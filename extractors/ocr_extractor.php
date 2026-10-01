<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/ocr_tools.php';
require_once __DIR__ . '/ExtractorInterface.php';
require_once __DIR__ . '/ParsesHonorRows.php';

class OcrExtractor implements ExtractorInterface
{
    use ParsesHonorRows;

    /** @var ?callable(int, int): void */
    private $onPageProgress;

    /**
     * @param ?callable(int $currentPage, int $totalPages): void $onPageProgress
     *     Called once per page as Tesseract finishes it — the only genuinely
     *     steppable part of this extractor (Ghostscript rasterizes every
     *     page in one call, so there's no real per-page signal before this).
     */
    public function __construct(array $extraCourseKeywords = [], ?callable $onPageProgress = null)
    {
        $this->setExtraCourseKeywords($extraCourseKeywords);
        $this->onPageProgress = $onPageProgress;
    }

    public function extract(string $filePath): array
    {
        $ghostscriptPath = resolve_ghostscript_path();
        $tesseractPath = resolve_tesseract_path();

        $tmpDir = sys_get_temp_dir() . '/hge_ocr_' . uniqid();

        if (!mkdir($tmpDir, 0777, true) && !is_dir($tmpDir)) {
            error_log('OcrExtractor: could not create temp directory ' . $tmpDir);
            return ['status' => 'failed', 'rows' => []];
        }

        $cleanup = function () use ($tmpDir): void {
            foreach (glob($tmpDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($tmpDir);
        };
        register_shutdown_function($cleanup);

        try {
            $outputPattern = $tmpDir . '/page_%03d.png';
            $cmd = escapeshellarg($ghostscriptPath)
                . ' -dNOPAUSE -dBATCH -dSAFER -sDEVICE=png16m -r300'
                . ' -sOutputFile="' . $outputPattern . '"'
                . ' ' . escapeshellarg($filePath);

            exec($cmd . ' 2>&1', $output, $returnCode);

            $pageImages = glob($tmpDir . '/page_*.png') ?: [];
            sort($pageImages);

            if ($returnCode !== 0 || empty($pageImages)) {
                error_log('OcrExtractor: Ghostscript rasterization failed (exit ' . $returnCode . '): ' . implode(' ', $output));
                return ['status' => 'failed', 'rows' => []];
            }

            $textParts = [];
            $totalPages = count($pageImages);
            foreach ($pageImages as $pageIndex => $imagePath) {
                try {
                    $pageText = (new \thiagoalessio\TesseractOCR\TesseractOCR($imagePath))
                        ->executable($tesseractPath)
                        ->run(60);
                    $textParts[] = $pageText;
                } catch (\Throwable $e) {
                    error_log('OcrExtractor: Tesseract failed on ' . $imagePath . ': ' . $e->getMessage());
                }

                if ($this->onPageProgress !== null) {
                    ($this->onPageProgress)($pageIndex + 1, $totalPages);
                }
            }

            $text = implode("\n", $textParts);
            $rows = array_map(function (array $row): array {
                $row['source'] = 'ocr';
                return $row;
            }, $this->parseRows($text));

            return [
                'status' => empty($rows) ? 'failed' : 'ok',
                'rows' => $rows,
            ];
        } finally {
            $cleanup();
        }
    }
}
