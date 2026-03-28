<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser;

class PdfParserService
{
    /**
     * Minimum character threshold for a page to be considered "has text".
     * Pages below this will be sent to Gemini Vision.
     */
    private const MIN_TEXT_LENGTH = 50;

    /**
     * Target chunk size in characters for embedding (~125 tokens).
     */
    private const CHUNK_SIZE = 500;

    /**
     * Overlap between consecutive chunks (for RAG context continuity).
     */
    private const CHUNK_OVERLAP = 100;

    /**
     * PNG resolution for rendering PDF pages (DPI).
     * 150 DPI is a good balance of quality vs file size for Vision API.
     */
    private const RENDER_DPI = 150;

    public function __construct(
        private readonly Parser        $parser,
        private readonly GeminiService $gemini,
    ) {}

    // -------------------------------------------------------------------------
    // PUBLIC API
    // -------------------------------------------------------------------------

    /**
     * Full pipeline: extract text from all pages (with Vision fallback for
     * image-heavy or scan pages), then chunk the result.
     *
     * @return array{text: string, chunks: string[], page_count: int, vision_pages: int[]}
     */
    public function extractAndChunk(string $absolutePath): array
    {
        $pageResults = $this->extractPerPage($absolutePath);

        $visionPages = [];
        $allText     = [];

        foreach ($pageResults as $pageNum => $result) {
            if ($result['used_vision']) {
                $visionPages[] = $pageNum + 1; // 1-indexed for logging
            }
            if (!empty($result['text'])) {
                $allText[] = "--- Halaman " . ($pageNum + 1) . " ---\n" . $result['text'];
            }
        }

        $fullText = implode("\n\n", $allText);
        $chunks   = $this->chunkText($fullText);

        return [
            'text'         => $fullText,
            'chunks'       => $chunks,
            'page_count'   => count($pageResults),
            'vision_pages' => $visionPages,
        ];
    }

    // -------------------------------------------------------------------------
    // PER-PAGE EXTRACTION
    // -------------------------------------------------------------------------

    /**
     * Extract text from each page individually.
     * Pages with insufficient text are rendered to PNG and sent to Gemini Vision.
     *
     * @return array<int, array{text: string, used_vision: bool}>
     */
    private function extractPerPage(string $absolutePath): array
    {
        $pdf   = $this->parser->parseFile($absolutePath);
        $pages = $pdf->getPages();

        if (empty($pages)) {
            throw new \RuntimeException('PDF has no pages or could not be parsed.');
        }

        $results      = [];
        $imagickAvail = extension_loaded('imagick');

        foreach ($pages as $pageIndex => $page) {
            $pageText = $this->normaliseText($page->getText());
            $hasText  = mb_strlen($pageText) >= self::MIN_TEXT_LENGTH;

            if ($hasText) {
                $results[$pageIndex] = [
                    'text'        => $pageText,
                    'used_vision' => false,
                ];
            } else {
                // Page is image-heavy, scan, or has very little text
                $visionText = $this->processPageWithVision(
                    absolutePath: $absolutePath,
                    pageIndex:    $pageIndex,
                    existingText: $pageText,
                    imagickAvail: $imagickAvail,
                );

                $results[$pageIndex] = [
                    'text'        => $visionText,
                    'used_vision' => true,
                ];
            }
        }

        return $results;
    }

    /**
     * Render a single PDF page to PNG and send to Gemini Vision.
     * Falls back to existing (possibly empty) text if Imagick is unavailable.
     */
    private function processPageWithVision(
        string $absolutePath,
        int    $pageIndex,
        string $existingText,
        bool   $imagickAvail,
    ): string {
        if (!$imagickAvail) {
            Log::warning('PdfParserService: Imagick not available, skipping Vision for page', [
                'page' => $pageIndex + 1,
            ]);
            return $existingText;
        }

        try {
            $imageBase64 = $this->renderPageToBase64($absolutePath, $pageIndex);
            $visionText  = $this->gemini->describePageImage($imageBase64, $existingText);

            Log::info('PdfParserService: Vision processed page', [
                'page'       => $pageIndex + 1,
                'text_chars' => mb_strlen($visionText),
            ]);

            return $visionText;
        } catch (\Throwable $e) {
            Log::error('PdfParserService: Vision failed for page', [
                'page'  => $pageIndex + 1,
                'error' => $e->getMessage(),
            ]);

            // Graceful fallback — return whatever text smalot managed to get
            return $existingText;
        }
    }

    // -------------------------------------------------------------------------
    // IMAGE RENDERING (Imagick)
    // -------------------------------------------------------------------------

    /**
     * Render a single PDF page to PNG using Imagick and return as base64.
     * Requires: PHP imagick extension + Ghostscript installed on server.
     */
    private function renderPageToBase64(string $absolutePath, int $pageIndex): string
    {
        $imagick = new \Imagick();

        // Resolution MUST be set before readImage for it to take effect
        $imagick->setResolution(self::RENDER_DPI, self::RENDER_DPI);

        // [pageIndex] suffix tells Imagick to load only that specific page
        $imagick->readImage("{$absolutePath}[{$pageIndex}]");

        // Flatten to white background (PDFs can have transparent bg)
        $imagick->setImageBackgroundColor('white');
        $imagick = $imagick->flattenImages();
        $imagick->setImageFormat('png');

        // Scale down if too large (Gemini Vision limit: ~20MB payload)
        $width  = $imagick->getImageWidth();
        $height = $imagick->getImageHeight();

        if ($width > 2000 || $height > 2800) {
            $imagick->scaleImage(2000, 2800, true); // maintain aspect ratio
        }

        $pngBlob = $imagick->getImageBlob();
        $imagick->clear();
        $imagick->destroy();

        return base64_encode($pngBlob);
    }

    // -------------------------------------------------------------------------
    // CHUNKING
    // -------------------------------------------------------------------------

    /**
     * Split full text into overlapping chunks for embedding.
     *
     * @return string[]
     */
    public function chunkText(string $text): array
    {
        if (empty(trim($text))) {
            return [];
        }

        $chunks = [];
        $length = mb_strlen($text);
        $offset = 0;

        while ($offset < $length) {
            $chunk = mb_substr($text, $offset, self::CHUNK_SIZE);

            // Try to break at sentence boundary for cleaner semantic chunks
            if ($offset + self::CHUNK_SIZE < $length) {
                $lastBreak = max(
                    mb_strrpos($chunk, '. ') ?: 0,
                    mb_strrpos($chunk, ".\n") ?: 0,
                    mb_strrpos($chunk, '! ') ?: 0,
                    mb_strrpos($chunk, '? ') ?: 0,
                );

                if ($lastBreak > self::CHUNK_SIZE / 2) {
                    $chunk = mb_substr($chunk, 0, $lastBreak + 1);
                }
            }

            $trimmed = trim($chunk);
            if (mb_strlen($trimmed) > 20) {
                $chunks[] = $trimmed;
            }

            $advance = mb_strlen($chunk) - self::CHUNK_OVERLAP;
            $offset += max($advance, 1); // prevent infinite loop
        }

        return $chunks;
    }

    // -------------------------------------------------------------------------
    // HELPERS
    // -------------------------------------------------------------------------

    private function normaliseText(string $text): string
    {
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        return trim($text);
    }
}
