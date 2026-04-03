<?php

namespace App\Jobs;

use App\Models\Mnemonic;
use App\Models\Question;
use App\Models\Study;
use App\Models\StudyChunk;
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

    /** Retry 3x — GeminiService sudah handle 429 sendiri via backoff */
    public int $tries = 3;

    /** Backoff antar job retry (bukan antar API call) */
    public array $backoff = [60, 180, 300];

    /** Generous timeout untuk PDF besar */
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
            // STEP 1: Parse PDF
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
                'vision_pages' => $visionPages,
                'chunks'       => count($chunks),
                'char_count'   => mb_strlen($fullText),
            ]);

            // ----------------------------------------------------------------
            // STEP 2: Embed chunks → simpan ke study_chunks
            // ----------------------------------------------------------------
            $chunkEmbeddings = $gemini->batchEmbedTexts($chunks);

            foreach ($chunks as $i => $chunkText) {
                $chunk = StudyChunk::create([
                    'study_document_id' => $this->document->id,
                    'study_id'          => $this->study->id,
                    'chunk_index'       => $i,
                    'content'           => $chunkText,
                ]);

                if (!empty($chunkEmbeddings[$i])) {
                    $this->storeChunkEmbedding($chunk->id, $chunkEmbeddings[$i]);
                }
            }

            Log::info('ProcessPdfJob: chunks stored', [
                'study_id' => $this->study->id,
                'count'    => count($chunks),
            ]);

            // ----------------------------------------------------------------
            // STEP 3: Generate quiz questions + embed ideal answers
            // ----------------------------------------------------------------
            $questions = $gemini->generateQuestions($fullText, count: 5);

            if (!empty($questions)) {
                $idealAnswers     = array_column($questions, 'ideal_answer');
                $answerEmbeddings = $gemini->batchEmbedTexts($idealAnswers);

                foreach ($questions as $idx => $q) {
                    $question = Question::create([
                        'study_id'       => $this->study->id,
                        'question_text'  => $q['question_text'],
                        'ideal_answer'   => $q['ideal_answer'],
                        'extracted_text' => $fullText,
                        'order_index'    => $idx + 1,
                    ]);

                    if (!empty($answerEmbeddings[$idx])) {
                        $this->storeQuestionEmbedding($question->id, $answerEmbeddings[$idx]);
                    }
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
                'study_id' => $this->study->id,
                'content'  => $mnemonicsText,
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

            if ($this->attempts() >= $this->tries) {
                $this->study->update(['status' => 'failed']);
            }

            throw $e;
        }
    }

    /**
     * Simpan embedding chunk ke study_chunks.
     *
     * @param float[] $embedding
     */
    private function storeChunkEmbedding(string $chunkId, array $embedding): void
    {
        $vector = '[' . implode(',', $embedding) . ']';

        DB::statement(
            'UPDATE "study_chunks" SET content_embedding = ?::vector WHERE id = ?',
            [$vector, $chunkId]
        );
    }

    /**
     * Simpan embedding jawaban ideal ke questions.
     *
     * @param float[] $embedding
     */
    private function storeQuestionEmbedding(string $questionId, array $embedding): void
    {
        $vector = '[' . implode(',', $embedding) . ']';

        DB::statement(
            'UPDATE "questions" SET ideal_embedding = ?::vector WHERE id = ?',
            [$vector, $questionId]
        );
    }
}