<?php

/**
 * Minimal, dependency-free .xlsx reader — the read side of xlsx_writer.php.
 * Supports both the inline-string cells our own generated template uses and
 * the shared-string table real Excel/LibreOffice normally rewrites a file
 * into the moment a person edits and saves it, since an uploaded template
 * will almost always have gone through one of those before it comes back to
 * us.
 */

function xlsx_strip_namespaces(string $xml): string
{
    return preg_replace('/xmlns(:\w+)?="[^"]*"/', '', $xml) ?? $xml;
}

function xlsx_column_index(string $cellRef): int
{
    if (!preg_match('/^([A-Z]+)\d+$/', $cellRef, $m)) {
        return 0;
    }

    $letters = $m[1];
    $index = 0;
    for ($i = 0; $i < strlen($letters); $i++) {
        $index = $index * 26 + (ord($letters[$i]) - 64);
    }

    return $index;
}

function xlsx_row_number(string $cellRef): int
{
    return preg_match('/(\d+)$/', $cellRef, $m) ? (int) $m[1] : 0;
}

/**
 * @return array<int, string> shared string index => text
 */
function xlsx_read_shared_strings(?string $xml): array
{
    if ($xml === null) {
        return [];
    }

    $doc = @simplexml_load_string(xlsx_strip_namespaces($xml));
    if ($doc === false) {
        return [];
    }

    // SimpleXML's foreach gives the element name ("si") as the key, not a
    // positional index, so the shared-string index has to be tracked by hand.
    $strings = [];
    $index = 0;
    foreach ($doc->si as $si) {
        if (isset($si->t)) {
            $strings[$index] = (string) $si->t;
        } else {
            // Rich text: concatenate every run's text node.
            $text = '';
            foreach ($si->r as $run) {
                $text .= (string) $run->t;
            }
            $strings[$index] = $text;
        }
        $index++;
    }

    return $strings;
}

/**
 * @return array<int, array<int, string>> rowNum => [colIndex => rawValue]
 *         Raw values are exactly as stored: numeric cells (including Excel
 *         serial dates) come back as their numeric string, everything else
 *         as decoded text.
 */
function xlsx_read_sheet_rows(string $sheetXml, array $sharedStrings): array
{
    $doc = @simplexml_load_string(xlsx_strip_namespaces($sheetXml));
    if ($doc === false || !isset($doc->sheetData)) {
        return [];
    }

    $rows = [];
    foreach ($doc->sheetData->row as $rowEl) {
        $rowNum = (int) $rowEl['r'];
        if ($rowNum <= 0) {
            continue;
        }

        $cols = [];
        foreach ($rowEl->c as $cellEl) {
            $ref = (string) $cellEl['r'];
            $colIndex = $ref !== '' ? xlsx_column_index($ref) : (count($cols) + 1);
            if ($colIndex <= 0) {
                continue;
            }

            $type = (string) $cellEl['t'];

            if ($type === 'inlineStr') {
                $value = isset($cellEl->is->t) ? (string) $cellEl->is->t : '';
                if ($value === '' && isset($cellEl->is->r)) {
                    foreach ($cellEl->is->r as $run) {
                        $value .= (string) $run->t;
                    }
                }
            } elseif ($type === 's') {
                $index = (int) $cellEl->v;
                $value = $sharedStrings[$index] ?? '';
            } else {
                // 'str' (formula result), 'b' (boolean), or numeric/default.
                $value = isset($cellEl->v) ? (string) $cellEl->v : '';
            }

            $cols[$colIndex] = $value;
        }

        $rows[$rowNum] = $cols;
    }

    return $rows;
}

/**
 * Opens an .xlsx file and returns the first worksheet's cells as
 * [rowNum => [colIndex => value]]. Resolves the actual first-sheet part via
 * workbook.xml + its relationships rather than assuming "sheet1.xml", since
 * Excel/LibreOffice don't guarantee that name when a file is re-saved.
 *
 * @return array{ok: bool, rows?: array<int, array<int, string>>, error?: string}
 */
function xlsx_read_first_sheet(string $filePath): array
{
    $tmpPath = null;

    try {
        // PharData needs a real filesystem path with a recognizable archive
        // extension to open reliably; $_FILES tmp names have neither.
        $tmpPath = tempnam(sys_get_temp_dir(), 'hge_xlsx_read_') . '.zip';
        if (!copy($filePath, $tmpPath)) {
            return ['ok' => false, 'error' => 'THE FILE COULD NOT BE READ.'];
        }

        try {
            $archive = new PharData($tmpPath, 0, null, Phar::ZIP);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'THE FILE IS NOT A VALID EXCEL (.XLSX) FILE.'];
        }

        if (!isset($archive['xl/workbook.xml'])) {
            return ['ok' => false, 'error' => 'THE FILE IS NOT A VALID EXCEL (.XLSX) FILE.'];
        }

        // Deliberately NOT namespace-stripped here: the sheet->workbook-part
        // link lives in the namespaced "r:id" attribute. Stripping the
        // xmlns:r declaration while leaving that prefixed attribute in place
        // would make the document namespace-invalid, so this reads the
        // original XML and resolves "r:id" properly via SimpleXML's
        // namespace-aware attributes() (plain ['r:id'] bracket access does
        // NOT match a real namespaced attribute — it only looks at
        // no-namespace attributes by literal name).
        $workbookXml = $archive['xl/workbook.xml']->getContent();
        $workbookDoc = @simplexml_load_string($workbookXml);
        if ($workbookDoc === false || !isset($workbookDoc->sheets->sheet[0])) {
            return ['ok' => false, 'error' => 'THE FILE IS NOT A VALID EXCEL (.XLSX) FILE.'];
        }

        $firstSheet = $workbookDoc->sheets->sheet[0];
        $relId = (string) $firstSheet->attributes('r', true)->id;

        $sheetTarget = 'worksheets/sheet1.xml';
        if ($relId !== '' && isset($archive['xl/_rels/workbook.xml.rels'])) {
            $relsXml = $archive['xl/_rels/workbook.xml.rels']->getContent();
            $relsDoc = @simplexml_load_string(xlsx_strip_namespaces($relsXml));
            if ($relsDoc !== false) {
                foreach ($relsDoc->Relationship as $rel) {
                    if ((string) $rel['Id'] === $relId) {
                        $sheetTarget = (string) $rel['Target'];
                        break;
                    }
                }
            }
        }

        $sheetPath = 'xl/' . ltrim($sheetTarget, '/');
        if (!isset($archive[$sheetPath])) {
            return ['ok' => false, 'error' => 'THE FILE IS NOT A VALID EXCEL (.XLSX) FILE.'];
        }

        $sharedStrings = isset($archive['xl/sharedStrings.xml'])
            ? xlsx_read_shared_strings($archive['xl/sharedStrings.xml']->getContent())
            : [];

        $rows = xlsx_read_sheet_rows($archive[$sheetPath]->getContent(), $sharedStrings);

        return ['ok' => true, 'rows' => $rows];
    } catch (\Throwable $e) {
        error_log('xlsx_read_first_sheet failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'THE FILE COULD NOT BE READ. IT MAY BE CORRUPTED.'];
    } finally {
        if ($tmpPath !== null && is_file($tmpPath)) {
            @unlink($tmpPath);
        }
    }
}
