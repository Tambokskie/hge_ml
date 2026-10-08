<?php

/**
 * Reads a CSV file into the same shape as xlsx_read_first_sheet():
 *   ['ok' => true, 'rows' => [row][col] => string (both 1-indexed)]
 * or ['ok' => false, 'error' => 'ALL-CAPS MESSAGE'].
 *
 * Handles a UTF-8 BOM, Windows-1252 files saved by older Excel versions, and
 * comma / semicolon / tab delimiters (picked from the first non-empty line).
 */
function csv_read_rows(string $filePath, int $maxRows = 100000): array
{
    $content = @file_get_contents($filePath);
    if ($content === false) {
        return ['ok' => false, 'error' => 'THE FILE COULD NOT BE READ.'];
    }

    if (strpos(substr($content, 0, 4096), "\0") !== false) {
        return ['ok' => false, 'error' => 'THE FILE IS NOT A VALID CSV FILE.'];
    }

    if (strncmp($content, "\xEF\xBB\xBF", 3) === 0) {
        $content = substr($content, 3);
    }
    if (!mb_check_encoding($content, 'UTF-8')) {
        $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
    }

    $firstLine = '';
    foreach (preg_split('/\r\n|\r|\n/', $content) as $line) {
        if (trim($line) !== '') {
            $firstLine = $line;
            break;
        }
    }
    $delimiter = ',';
    $best = substr_count($firstLine, ',');
    foreach ([';' => ';', "\t" => "\t"] as $candidate) {
        $count = substr_count($firstLine, $candidate);
        if ($count > $best) {
            $best = $count;
            $delimiter = $candidate;
        }
    }

    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $content);
    rewind($stream);

    $rows = [];
    $rowNumber = 0;
    while (($fields = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
        $rowNumber++;
        if ($rowNumber > $maxRows) {
            break;
        }
        if ($fields === [null]) {
            continue;
        }
        $cells = [];
        foreach ($fields as $index => $value) {
            $value = (string) $value;
            if ($value !== '') {
                $cells[$index + 1] = $value;
            }
        }
        if ($cells !== []) {
            $rows[$rowNumber] = $cells;
        }
    }
    fclose($stream);

    return ['ok' => true, 'rows' => $rows];
}
