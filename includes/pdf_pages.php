<?php

require_once __DIR__ . '/ocr_tools.php';

/**
 * Total page count of a PDF via Ghostscript's own pdfpagecount PostScript
 * operator — avoids adding a second PDF-parsing dependency just for a page
 * count when Ghostscript is already required for OCR rasterization
 * (includes/ocr_tools.php). Ghostscript's default -dSAFER sandboxing blocks
 * the raw PostScript `file` operator used here unless the exact path is
 * explicitly permitted via --permit-file-read.
 */
function count_pdf_pages(string $filePath): ?int
{
    if (!is_file($filePath)) {
        return null;
    }

    $ghostscriptPath = resolve_ghostscript_path();
    $normalizedPath = str_replace('\\', '/', $filePath);
    $psCommand = '(' . $normalizedPath . ') (r) file runpdfbegin pdfpagecount = quit';

    $cmd = escapeshellarg($ghostscriptPath)
        . ' -q -dNODISPLAY'
        . ' ' . escapeshellarg('--permit-file-read=' . $normalizedPath)
        . ' -c ' . escapeshellarg($psCommand);

    exec($cmd . ' 2>&1', $output, $returnCode);

    if ($returnCode !== 0 || empty($output)) {
        error_log('count_pdf_pages: Ghostscript failed (exit ' . $returnCode . '): ' . implode(' ', $output));
        return null;
    }

    $count = (int) trim((string) end($output));
    return $count > 0 ? $count : null;
}

/**
 * Slices pages $first..$last (1-indexed, inclusive) of $filePath into a new,
 * standalone temp PDF and returns its path. The caller is responsible for
 * deleting it once done. Returns null on any Ghostscript failure.
 */
function extract_pdf_page_range(string $filePath, int $first, int $last): ?string
{
    if (!is_file($filePath)) {
        return null;
    }

    $ghostscriptPath = resolve_ghostscript_path();
    $outputPath = sys_get_temp_dir() . '/hge_chunk_' . uniqid() . '.pdf';

    $cmd = escapeshellarg($ghostscriptPath)
        . ' -q -dNOPAUSE -dBATCH -dSAFER -sDEVICE=pdfwrite'
        . ' -dFirstPage=' . $first . ' -dLastPage=' . $last
        . ' -sOutputFile=' . escapeshellarg($outputPath)
        . ' ' . escapeshellarg($filePath);

    exec($cmd . ' 2>&1', $output, $returnCode);

    if ($returnCode !== 0 || !is_file($outputPath)) {
        error_log('extract_pdf_page_range: Ghostscript failed (exit ' . $returnCode . '): ' . implode(' ', $output));
        return null;
    }

    return $outputPath;
}
