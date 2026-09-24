<?php

require_once __DIR__ . '/../includes/xlsx_reader.php';
require_once __DIR__ . '/../includes/template_upload.php';

/**
 * Re-parses an already-stored "Upload From Template" .xlsx file — used only
 * by RE-EXTRACT RECORDS, since the initial upload (handle_template_upload_request()
 * in includes/template_upload.php) already validates and stages the rows in
 * one step. Reuses the exact same parser so re-extracting reproduces the same
 * rows the original upload accepted.
 */
class TemplateExtractor implements ExtractorInterface
{
    public function extract(string $filePath): array
    {
        $sheet = xlsx_read_first_sheet($filePath);
        if (!$sheet['ok']) {
            return ['status' => 'failed', 'rows' => []];
        }

        $parsed = parse_latin_honor_template($sheet['rows']);
        if ($parsed['status'] !== 'success') {
            return ['status' => 'failed', 'rows' => []];
        }

        return ['status' => 'ok', 'rows' => $parsed['rows']];
    }
}
