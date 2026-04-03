<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService
{
    private string $apiKey;
    private string $baseUrl;
    private string $embeddingModel;
    private string $llmModel;

    /**
     * Max retry attempts saat kena 429 atau 5xx.
     */
    private int $maxRetries = 4;

    /**
     * Base delay (ms) untuk exponential backoff.
     * Delay = baseDelayMs * 2^attempt  → 1s, 2s, 4s, 8s
     */
    private int $baseDelayMs = 1000;

    public function __construct()
    {
        $this->apiKey         = config('services.gemini.api_key');
        $this->baseUrl        = config('services.gemini.base_url');
        $this->embeddingModel = config('services.gemini.embedding_model');
        $this->llmModel       = config('services.gemini.llm_model');
    }

    // -------------------------------------------------------------------------
    // VISION — describe / OCR a page image
    // -------------------------------------------------------------------------

    /**
     * Send a rendered PDF page (as base64 PNG) to Gemini Vision.
     * Returns extracted text + description of any images/diagrams found.
     */
    public function describePageImage(string $imageBase64, string $existingText = ''): string
    {
        $contextNote = !empty($existingText)
            ? "Teks parsial yang sudah berhasil diekstrak dari halaman ini: \"{$existingText}\"\n\n"
            : '';

        $prompt = <<<PROMPT
{$contextNote}Kamu adalah AI yang membantu mengekstrak konten dari halaman dokumen PDF untuk keperluan belajar.

Lakukan dua hal sekaligus pada gambar halaman ini:

1. OCR — Salin SEMUA teks yang terlihat di halaman ini secara akurat, termasuk judul, paragraf, bullet points, tabel, caption, header, footer, dan nomor halaman.

2. Deskripsi Visual — Untuk setiap gambar, diagram, grafik, tabel, atau ilustrasi yang ada:
   - Jelaskan apa yang ditampilkan
   - Sebutkan label, angka, atau data penting yang terlihat
   - Jelaskan hubungan atau pola yang terlihat (jika ada grafik/diagram)

FORMAT OUTPUT:
[TEKS]
(semua teks OCR di sini)

[VISUAL]
(deskripsi setiap elemen visual, jika ada. Tulis "Tidak ada elemen visual" jika hanya teks.)

Gunakan Bahasa Indonesia untuk deskripsi visual. Untuk teks OCR, ikuti bahasa asli dokumen.
PROMPT;

        return $this->retryRequest(function () use ($prompt, $imageBase64) {
            $response = Http::withQueryParameters(['key' => $this->apiKey])
                ->timeout(90)
                ->post("{$this->baseUrl}/{$this->llmModel}:generateContent", [
                    'contents' => [
                        [
                            'parts' => [
                                [
                                    'inline_data' => [
                                        'mime_type' => 'image/png',
                                        'data'      => $imageBase64,
                                    ],
                                ],
                                ['text' => $prompt],
                            ],
                        ],
                    ],
                    'generationConfig' => [
                        'temperature'     => 0.1,
                        'maxOutputTokens' => 4096,
                    ],
                ]);

            $this->assertSuccess($response, 'describePageImage');

            return trim($response->json('candidates.0.content.parts.0.text') ?? '');
        }, 'describePageImage');
    }

    // -------------------------------------------------------------------------
    // EMBEDDING
    // -------------------------------------------------------------------------

    /**
     * Generate an embedding vector for a single text chunk.
     *
     * @return float[]
     */
    public function embedText(string $text): array
    {
        return $this->retryRequest(function () use ($text) {
            $response = Http::withQueryParameters(['key' => $this->apiKey])
                ->timeout(30)
                ->post("{$this->baseUrl}/{$this->embeddingModel}:embedContent", [
                    'model'   => $this->embeddingModel,
                    'content' => [
                        'parts' => [['text' => $text]],
                    ],
                ]);

            $this->assertSuccess($response, 'embedText');

            return $response->json('embedding.values');
        }, 'embedText');
    }

    /**
     * Batch embed multiple text chunks.
     * Gemini supports up to 100 items per batchEmbedContents request.
     * Dibatasi 5 batch per menit untuk hindari rate limit 429.
     *
     * @param  string[] $chunks
     * @return float[][]
     */
    public function batchEmbedTexts(array $chunks): array
    {
        if (empty($chunks)) {
            return [];
        }

        $rawModel   = str_replace('models/', '', $this->embeddingModel);
        $modelPath  = "models/{$rawModel}";
        $batches    = array_chunk($chunks, 100);
        $allVectors = [];

        foreach ($batches as $batchIndex => $batch) {
            // Delay antar batch untuk hindari 429 (kecuali batch pertama)
            if ($batchIndex > 0) {
                $this->sleep(2000); // 2 detik antar batch
            }

            $requests = array_map(fn($chunk) => [
                'model'   => $modelPath,
                'content' => ['parts' => [['text' => $chunk]]],
            ], $batch);

            $vectors = $this->retryRequest(function () use ($modelPath, $requests) {
                $response = Http::withQueryParameters(['key' => $this->apiKey])
                    ->timeout(60)
                    ->post("{$this->baseUrl}/{$modelPath}:batchEmbedContents", [
                        'requests' => $requests,
                    ]);

                $this->assertSuccess($response, 'batchEmbedTexts');

                $embeddings = $response->json('embeddings') ?? [];

                return array_column($embeddings, 'values');
            }, 'batchEmbedTexts');

            $allVectors = array_merge($allVectors, $vectors);
        }

        return $allVectors;
    }

    // -------------------------------------------------------------------------
    // QUIZ GENERATION
    // -------------------------------------------------------------------------

    /**
     * Generate essay questions + ideal answers from extracted text.
     *
     * @return array<int, array{question_text: string, ideal_answer: string}>
     */
    public function generateQuestions(string $extractedText, int $count = 5): array
    {
        $prompt = <<<PROMPT
Kamu adalah AI pendidikan. Berdasarkan materi di bawah ini, buat {$count} pertanyaan essay yang menguji pemahaman mendalam beserta jawaban idealnya.

Prioritaskan konten dari bagian [TEKS]. Jika ada bagian [VISUAL], buat minimal 1 pertanyaan yang berkaitan dengan diagram atau gambar yang dideskripsikan.

FORMAT RESPONS (JSON saja, tanpa penjelasan, tanpa markdown fence):
{"questions":[{"question_text":"...","ideal_answer":"..."}]}

MATERI:
{$extractedText}
PROMPT;

        $result = $this->generateContent($prompt);
        $parsed = $this->parseJson($result);

        Log::info('Parsed Result:', ['parsed' => $parsed]);

        return $parsed['questions'] ?? [];
    }

    // -------------------------------------------------------------------------
    // MNEMONICS GENERATION
    // -------------------------------------------------------------------------

    /**
     * Generate a mnemonic to help remember the study material.
     */
    public function generateMnemonics(string $extractedText): string
    {
        $prompt = <<<PROMPT
Kamu adalah AI pendidikan yang ahli dalam teknik memori.
Buat SATU mnemonics yang efektif dan mudah diingat dari poin-poin utama materi berikut.
Mnemonics bisa berupa akronim, cerita pendek, atau asosiasi kata yang kreatif.
Gunakan Bahasa Indonesia. Berikan hanya teks mnemonicsnya saja, tanpa penjelasan panjang.

MATERI:
{$extractedText}
PROMPT;

        return trim($this->generateContent($prompt));
    }

    // -------------------------------------------------------------------------
    // INTERNAL — HTTP helpers
    // -------------------------------------------------------------------------

    private function generateContent(string $prompt): string
    {
        return $this->retryRequest(function () use ($prompt) {
            $response = Http::withQueryParameters(['key' => $this->apiKey])
                ->timeout(120)
                ->post("{$this->baseUrl}/{$this->llmModel}:generateContent", [
                    'contents' => [
                        ['parts' => [['text' => $prompt]]],
                    ],
                    'generationConfig' => [
                        'temperature'     => 0.4,
                        'maxOutputTokens' => 8192,
                    ],
                ]);

            $this->assertSuccess($response, 'generateContent');

            return $response->json('candidates.0.content.parts.0.text') ?? '';
        }, 'generateContent');
    }

    /**
     * Retry wrapper dengan exponential backoff.
     * Retry hanya untuk 429 (rate limit) dan 5xx (server error).
     */
    private function retryRequest(callable $callback, string $context): mixed
    {
        $attempt = 0;

        while (true) {
            try {
                return $callback();
            } catch (\RuntimeException $e) {
                $isRateLimit  = str_contains($e->getMessage(), '429');
                $isServerErr  = preg_match('/HTTP 5\d\d/', $e->getMessage());

                if ((!$isRateLimit && !$isServerErr) || $attempt >= $this->maxRetries) {
                    throw $e;
                }

                $delayMs = $this->baseDelayMs * (2 ** $attempt);

                // Tambah jitter ±20% agar tidak semua retry barengan
                $jitter  = (int) ($delayMs * 0.2);
                $delayMs = $delayMs + rand(-$jitter, $jitter);

                Log::warning("GeminiService::{$context} retry #{$attempt}", [
                    'reason'   => $isRateLimit ? '429 rate limit' : '5xx server error',
                    'delay_ms' => $delayMs,
                ]);

                $this->sleep($delayMs);
                $attempt++;
            }
        }
    }

    private function parseJson(string $result): array
    {
        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/', $result, $matches)) {
            $cleaned = $matches[1];
        } else {
            $cleaned = trim($result);
        }

        $decoded = json_decode($cleaned, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('parseJson failed', [
                'error' => json_last_error_msg(),
                'raw'   => $result,
            ]);
            return [];
        }

        return $decoded ?? [];
    }

    private function assertSuccess(Response $response, string $context): void
    {
        if ($response->failed()) {
            Log::error("GeminiService::{$context} failed", [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new \RuntimeException(
                "Gemini API error in {$context}: HTTP {$response->status()}"
            );
        }
    }

    /**
     * Sleep dalam milidetik.
     */
    private function sleep(int $ms): void
    {
        usleep($ms * 1000);
    }
}