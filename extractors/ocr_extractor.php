<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/ExtractorInterface.php';
require_once __DIR__ . '/ParsesHonorRows.php';

class OcrExtractor implements ExtractorInterface
{
    use ParsesHonorRows;

    public function extract(string $filePath): array
    {
        $ghostscriptPath = getenv('GHOSTSCRIPT_PATH') ?: 'C:\Program Files\gs\gs10.08.0\bin\gswin64c.exe';
        $tesseractPath = getenv('TESSERACT_PATH') ?: 'C:\Program Files\Tesseract-OCR\tesseract.exe';

        $tmpDir = sys_get_temp_dir() . '/hge_ocr_' . uniqid();

        if (!mkdir($tmpDir, 0777, true) && !is_dir($tmpDir)) {
            error_log('OcrExtractor: could not create temp directory ' . $tmpDir);
            return ['status' => 'failed', 'rows' => []];
        }

        try {
            $outputPattern = $tmpDir . '/page_%03d.png';
            $cmd = escapeshellarg($ghostscriptPath)
                . ' -dNOPAUSE -dBATCH -dSAFER -sDEVICE=png16m -r300'
                . ' -sOutputFile=' . escapeshellarg($outputPattern)
                . ' ' . escapeshellarg($filePath);

            exec($cmd . ' 2>&1', $output, $returnCode);

            $pageImages = glob($tmpDir . '/page_*.png') ?: [];
            sort($pageImages);

            if ($returnCode !== 0 || empty($pageImages)) {
                error_log('OcrExtractor: Ghostscript rasterization failed (exit ' . $returnCode . '): ' . implode(' ', $output));
                return ['status' => 'failed', 'rows' => []];
            }

            $textParts = [];
            foreach ($pageImages as $imagePath) {
                try {
                    $pageText = (new \thiagoalessio\TesseractOCR\TesseractOCR($imagePath))
                        ->executable($tesseractPath)
                        ->run();
                    $textParts[] = $pageText;
                } catch (\Throwable $e) {
                    error_log('OcrExtractor: Tesseract failed on ' . $imagePath . ': ' . $e->getMessage());
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
            foreach (glob($tmpDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($tmpDir);
        }
    }
}
