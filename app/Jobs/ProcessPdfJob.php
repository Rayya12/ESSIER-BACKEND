<?php

namespace App\Jobs;

use App\Models\Mnemonic;
use App\Models\Question;
use App\Models\Study;
use App\Models\StudyDocument;
use App\Services\GeminiService;
use App\Services\PdfParserService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessPdfJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Allow 3 attempts — Gemini Vision calls can occasionally time out.
     */
    public int $tries = 3;

    /**
     * Exponential backoff between retries (seconds).
     */
    public array $backoff = [30, 90, 180];

    /**
     * Generous timeout — Vision processing of a 50-page PDF can take a while.
     */
    public int $timeout = 600;

    public function __construct(
        private readonly Study         $study,
        private readonly StudyDocument $document,
    ) {}

    public function handle(
        PdfParserService $parser,
        GeminiService    $gemini,
    ): void {
        Log::info('ProcessPdfJob: started', ['study_id' => $this->study->id]);

        try {
            $absolutePath = Storage::disk('private')->path($this->document->file_path);

            // ----------------------------------------------------------------
            // STEP 1: Parse PDF (text + Vision fallback per image/scan page)
            // ----------------------------------------------------------------
            [
                'text'         => $fullText,
                'chunks'       => $chunks,
                'page_count'   => $pageCount,
                'vision_pages' => $visionPages,
            ] = $parser->extractAndChunk($absolutePath);

            if (empty($fullText)) {
                throw new \RuntimeException(
                    'PDF yielded no text even after Vision processing. ' .
                    'File may be corrupted or an unsupported format.'
                );
            }

            Log::info('ProcessPdfJob: PDF parsed', [
                'study_id'     => $this->study->id,
                'pages'        => $pageCount,
                'vision_pages' => $visionPages, // pages that used Gemini Vision
                'chunks'       => count($chunks),
                'char_count'   => mb_strlen($fullText),
            ]);

            // ----------------------------------------------------------------
            // STEP 2: Store extracted text & embed chunks → study_documents
            // ----------------------------------------------------------------
            $chunkEmbeddings = $gemini->batchEmbedTexts($chunks);

            foreach ($chunks as $i => $chunk) {
                if ($i === 0) {
                    // Reuse the existing document record for the first chunk
                    $this->document->update(['extracted_text' => $chunk]);
                    $this->storeVector('study_documents', $this->document->id, $chunkEmbeddings[$i]);
                } else {
                    $doc = StudyDocument::create([
                        'study_id'       => $this->study->id,
                        'file_name'      => $this->document->file_name . " [chunk {$i}]",
                        'file_path'      => $this->document->file_path,
                        'extracted_text' => $chunk,
                        'created_at'     => now(),
                    ]);
                    $this->storeVector('study_documents', $doc->id, $chunkEmbeddings[$i]);
                }
            }

            // ----------------------------------------------------------------
            // STEP 3: Generate quiz questions + embed ideal answers
            // ----------------------------------------------------------------
            $questions = $gemini->generateQuestions($fullText, count: 5);

            if (!empty($questions)) {
                $idealAnswers     = array_column($questions, 'ideal_answer');
                $answerEmbeddings = $gemini->batchEmbedTexts($idealAnswers);

                foreach ($questions as $idx => $q) {
                    $question = Question::create([
                        'study_id'      => $this->study->id,
                        'question_text' => $q['question_text'],
                        'ideal_answer'  => $q['ideal_answer'],
                        'extracted_text' => $fullText,
                        'order_index'   => $idx + 1,
                    ]);

                    $this->storeVector('question', $question->id, $answerEmbeddings[$idx]);
                }

                Log::info('ProcessPdfJob: questions generated', [
                    'study_id' => $this->study->id,
                    'count'    => count($questions),
                ]);
            }

            // ----------------------------------------------------------------
            // STEP 4: Generate mnemonics
            // ----------------------------------------------------------------
            $mnemonicsText = $gemini->generateMnemonics($fullText);

            Mnemonic::create([
                'study_id'   => $this->study->id,
                'content'    => $mnemonicsText,
                'created_at' => now(),
            ]);

            // ----------------------------------------------------------------
            // STEP 5: Mark study as ready
            // ----------------------------------------------------------------
            $this->study->update(['status' => 'ready']);

            Log::info('ProcessPdfJob: completed', [
                'study_id'     => $this->study->id,
                'vision_pages' => $visionPages,
            ]);
        } catch (\Throwable $e) {
            Log::error('ProcessPdfJob: failed', [
                'study_id' => $this->study->id,
                'attempt'  => $this->attempts(),
                'error'    => $e->getMessage(),
            ]);

            // On final attempt, surface failure to the user
            if ($this->attempts() >= $this->tries) {
                $this->study->update(['status' => 'failed']);
            }

            throw $e;
        }
    }

    /**
     * Store a pgvector embedding via raw SQL (Eloquent doesn't support vector type natively).
     *
     * @param float[] $embedding
     */
    private function storeVector(string $table, string $id, array $embedding): void
    {
        $vector = '[' . implode(',', $embedding) . ']';

        DB::statement(
            "UPDATE \"{$table}\" SET ideal_embedding = ?::vector WHERE id = ?",
            [$vector, $id]
        );
    }
}
