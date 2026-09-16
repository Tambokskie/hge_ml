<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/ExtractorInterface.php';
require_once __DIR__ . '/ParsesHonorRows.php';

class GenericExtractor implements ExtractorInterface
{
    use ParsesHonorRows;

    public function extract(string $filePath): array
    {
        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($filePath);
            $text = $pdf->getText();
        } catch (\Throwable $e) {
            error_log('GenericExtractor: PDF parse failed: ' . $e->getMessage());
            return ['status' => 'failed', 'rows' => []];
        }

        $rows = array_map(function (array $row): array {
            $row['source'] = 'text';
            return $row;
        }, $this->parseRows($text));

        return [
            'status' => empty($rows) ? 'failed' : 'ok',
            'rows' => $rows,
        ];
    }
}
