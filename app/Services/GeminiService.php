<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService
{
    private string $apiKey;
    private string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta';

    private string $embeddingModel = 'models/text-embedding-004';
    private string $llmModel       = 'models/gemini-1.5-flash';

    public function __construct()
    {
        $this->apiKey = config('services.gemini.api_key');
    }

    // -------------------------------------------------------------------------
    // VISION — describe / OCR a page image
    // -------------------------------------------------------------------------

    /**
     * Send a rendered PDF page (as base64 PNG) to Gemini Vision.
     * Returns extracted text + description of any images/diagrams found.
     *
     * @param  string $imageBase64   base64-encoded PNG of the page
     * @param  string $existingText  any text smalot already extracted (can be empty)
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

        $response = Http::withQueryParameters(['key' => $this->apiKey])
            ->timeout(60)
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
                    'temperature'     => 0.1, // low temp for accurate OCR
                    'maxOutputTokens' => 4096,
                ],
            ]);

        $this->assertSuccess($response, 'describePageImage');

        return trim($response->json('candidates.0.content.parts.0.text') ?? '');
    }

    // -------------------------------------------------------------------------
    // EMBEDDING
    // -------------------------------------------------------------------------

    /**
     * Generate an embedding vector for a single text chunk.
     * Returns float[] with 768 dimensions (text-embedding-004).
     *
     * @return float[]
     */
    public function embedText(string $text): array
    {
        $response = Http::withQueryParameters(['key' => $this->apiKey])
            ->post("{$this->baseUrl}/{$this->embeddingModel}:embedContent", [
                'model'   => $this->embeddingModel,
                'content' => [
                    'parts' => [['text' => $text]],
                ],
            ]);

        $this->assertSuccess($response, 'embedText');

        return $response->json('embedding.values');
    }

    /**
     * Batch embed multiple text chunks.
     * Gemini supports up to 100 items per batchEmbedContents request.
     *
     * @param  string[] $chunks
     * @return float[][] — same order as input
     */
    public function batchEmbedTexts(array $chunks): array
    {
        if (empty($chunks)) {
            return [];
        }

        // Split into batches of 100 (Gemini limit)
        $batches    = array_chunk($chunks, 100);
        $allVectors = [];

        foreach ($batches as $batch) {
            $requests = array_map(fn($chunk) => [
                'model'   => $this->embeddingModel,
                'content' => ['parts' => [['text' => $chunk]]],
            ], $batch);

            $response = Http::withQueryParameters(['key' => $this->apiKey])
                ->post("{$this->baseUrl}/{$this->embeddingModel}:batchEmbedContents", [
                    'requests' => $requests,
                ]);

            $this->assertSuccess($response, 'batchEmbedTexts');

            $vectors    = array_column($response->json('embeddings'), 'values');
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
    // INTERNAL HELPERS
    // -------------------------------------------------------------------------

    private function generateContent(string $prompt): string
    {
        $response = Http::withQueryParameters(['key' => $this->apiKey])
            ->timeout(60)
            ->post("{$this->baseUrl}/{$this->llmModel}:generateContent", [
                'contents' => [
                    ['parts' => [['text' => $prompt]]],
                ],
                'generationConfig' => [
                    'temperature'     => 0.4,
                    'maxOutputTokens' => 2048,
                ],
            ]);

        $this->assertSuccess($response, 'generateContent');

        return $response->json('candidates.0.content.parts.0.text') ?? '';
    }

    private function parseJson(string $raw): array
    {
        // Strip markdown code fences if Gemini wraps the response
        $clean = preg_replace('/^```(?:json)?\s*/i', '', trim($raw));
        $clean = preg_replace('/\s*```$/', '', $clean);

        $decoded = json_decode($clean, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('GeminiService: failed to parse JSON response', ['raw' => $raw]);
            return [];
        }

        return $decoded;
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
}
