<?php

/**
 * Minimal, dependency-free .xlsx (OOXML spreadsheet) writer. There is no
 * Composer package for this (no PhpSpreadsheet in composer.json, and this
 * environment has no working `composer`/ext-zip to add one), so this builds
 * the handful of XML parts an .xlsx actually needs by hand and zips them with
 * PharData — PharData's ZIP support works even with `phar.readonly = On`
 * (that ini setting only restricts executable .phar archives, not data-only
 * ones) and does not require ext-zip.
 */

function xlsx_column_letter(int $index): string
{
    $letter = '';
    while ($index > 0) {
        $index--;
        $letter = chr(65 + ($index % 26)) . $letter;
        $index = intdiv($index, 26);
    }

    return $letter;
}

function xlsx_xml_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/**
 * @param array<int, array<int, array{value: string, style?: int, type?: 'inlineStr'|'n'}>> $rows
 *        Keyed by 1-based row number, then by 1-based column number.
 */
function xlsx_build_sheet_xml(array $rows, array $colWidths = []): string
{
    $maxCol = 0;
    $maxRow = 0;
    foreach ($rows as $rowNum => $cols) {
        $maxRow = max($maxRow, $rowNum);
        foreach ($cols as $colNum => $cell) {
            $maxCol = max($maxCol, $colNum);
        }
    }
    $dimension = $maxRow > 0 ? ('A1:' . xlsx_column_letter(max($maxCol, 1)) . $maxRow) : 'A1';

    $colsXml = '';
    if (!empty($colWidths)) {
        $colsXml .= '<cols>';
        foreach ($colWidths as $colNum => $width) {
            $colsXml .= '<col min="' . $colNum . '" max="' . $colNum . '" width="' . $width . '" customWidth="1"/>';
        }
        $colsXml .= '</cols>';
    }

    ksort($rows);
    $sheetDataXml = '';
    foreach ($rows as $rowNum => $cols) {
        ksort($cols);
        $sheetDataXml .= '<row r="' . $rowNum . '">';
        foreach ($cols as $colNum => $cell) {
            $ref = xlsx_column_letter($colNum) . $rowNum;
            $style = isset($cell['style']) ? ' s="' . (int) $cell['style'] . '"' : '';
            $type = $cell['type'] ?? 'inlineStr';
            $value = (string) ($cell['value'] ?? '');

            if ($value === '') {
                $sheetDataXml .= '<c r="' . $ref . '"' . $style . '/>';
            } elseif ($type === 'n') {
                $sheetDataXml .= '<c r="' . $ref . '"' . $style . '><v>' . xlsx_xml_escape($value) . '</v></c>';
            } else {
                $sheetDataXml .= '<c r="' . $ref . '"' . $style . ' t="inlineStr"><is><t xml:space="preserve">' . xlsx_xml_escape($value) . '</t></is></c>';
            }
        }
        $sheetDataXml .= '</row>';
    }

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<dimension ref="' . $dimension . '"/>'
        . '<sheetViews><sheetView workbookViewId="0"/></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="15"/>'
        . $colsXml
        . '<sheetData>' . $sheetDataXml . '</sheetData>'
        . '</worksheet>';
}

function xlsx_content_types_xml(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>';
}

function xlsx_package_rels_xml(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';
}

function xlsx_workbook_xml(string $sheetName): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="' . xlsx_xml_escape($sheetName) . '" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';
}

function xlsx_workbook_rels_xml(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';
}

/**
 * Style index 0 = normal, 1 = bold, 2 = small italic gray (used for the
 * non-validated hint text next to the template's header fields), 3 = size
 * 11 / explicit black (used for the fill-in cells under GIVEN NAME).
 */
function xlsx_styles_xml(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="4">'
        . '<font><sz val="11"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
        . '<font><i/><sz val="9"/><color rgb="FF808080"/><name val="Calibri"/></font>'
        . '<font><sz val="11"/><color rgb="FF000000"/><name val="Calibri"/></font>'
        . '</fonts>'
        . '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="4">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';
}

/**
 * Zips the given "package path" => "xml content" map into a valid .xlsx
 * file and returns its raw bytes. Uses PharData rather than ZipArchive
 * because ext-zip is not installed in this environment.
 */
function xlsx_zip_parts(array $parts): string
{
    $tmpPath = tempnam(sys_get_temp_dir(), 'hge_xlsx_') . '.zip';
    if (file_exists($tmpPath)) {
        unlink($tmpPath);
    }

    try {
        $archive = new PharData($tmpPath, 0, null, Phar::ZIP);
        foreach ($parts as $path => $content) {
            $archive->addFromString($path, $content);
        }
        unset($archive);

        $bytes = file_get_contents($tmpPath);
        if ($bytes === false) {
            throw new \RuntimeException('Could not read generated xlsx archive.');
        }

        return $bytes;
    } finally {
        if (is_file($tmpPath)) {
            @unlink($tmpPath);
        }
    }
}

/**
 * Builds the downloadable Latin Honor template, pre-filled with the exact
 * school name and campus on file so a re-upload always matches the record
 * an administrator picked in the "DOWNLOAD TEMPLATE" modal.
 */
function build_latin_honor_template_xlsx(string $schoolName, string $campus): string
{
    $rows = [
        1 => [
            1 => ['value' => $schoolName, 'style' => 1],
            3 => ['value' => '(EXACT NAME ON FILE — DO NOT EDIT)', 'style' => 2],
        ],
        2 => [
            1 => ['value' => $campus, 'style' => 1],
            3 => ['value' => '(EXACT CAMPUS ON FILE — DO NOT EDIT)', 'style' => 2],
        ],
        3 => [
            1 => ['value' => '[DAY/MONTH/YEAR]', 'style' => 1],
            3 => ['value' => '(DATE OF GRADUATION, e.g. 24/07/2025)', 'style' => 2],
        ],
        4 => [
            1 => ['value' => '[YEAR]', 'style' => 1],
            3 => ['value' => '(4-DIGIT SCHOOL YEAR, e.g. 2025)', 'style' => 2],
        ],
        6 => [
            1 => ['value' => 'SEQUENCE', 'style' => 1],
            2 => ['value' => 'LAST NAME', 'style' => 1],
            3 => ['value' => 'GIVEN NAME', 'style' => 1],
            4 => ['value' => 'MIDDLE NAME', 'style' => 1],
            5 => ['value' => 'LATIN HONOR', 'style' => 1],
            6 => ['value' => 'COURSE', 'style' => 1],
        ],
    ];

    for ($i = 0; $i < 50; $i++) {
        $rows[7 + $i][1] = ['value' => (string) ($i + 1), 'type' => 'n'];
        // Explicit size 11 / black formatting for the fill-in cells under
        // GIVEN NAME, so typed-in names always render consistently
        // regardless of Excel's own default cell style.
        $rows[7 + $i][3] = ['value' => '', 'style' => 3];
    }

    $sheetXml = xlsx_build_sheet_xml($rows, [1 => 32, 2 => 20, 3 => 34, 4 => 20, 5 => 18, 6 => 30]);

    return xlsx_zip_parts([
        '[Content_Types].xml' => xlsx_content_types_xml(),
        '_rels/.rels' => xlsx_package_rels_xml(),
        'xl/workbook.xml' => xlsx_workbook_xml('TEMPLATE'),
        'xl/_rels/workbook.xml.rels' => xlsx_workbook_rels_xml(),
        'xl/styles.xml' => xlsx_styles_xml(),
        'xl/worksheets/sheet1.xml' => $sheetXml,
    ]);
}

/**
 * @param array{total_on_record: int, total_applied: int, filing_rate: float,
 *     schools: array<int, array{school_display: string, on_record: int, applied: int, filing_rate: float}>} $report
 *     Same shape get_application_filing_report() (includes/reports.php) returns,
 *     with each school row additionally carrying a pre-formatted
 *     'school_display' string — this file does not call
 *     format_school_display_name() (includes/functions.php) itself, since
 *     includes/*.php files in this project don't require each other; the
 *     caller (the export endpoint) does that formatting first.
 */
function build_application_filing_report_xlsx(array $report, int $year): string
{
    $rows = [
        1 => [
            1 => ['value' => 'APPLICATION FILING REPORT — ' . $year, 'style' => 1],
        ],
        3 => [
            1 => ['value' => 'TOTAL ON RECORD', 'style' => 1],
            2 => ['value' => (string) $report['total_on_record'], 'type' => 'n'],
        ],
        4 => [
            1 => ['value' => 'TOTAL APPLIED', 'style' => 1],
            2 => ['value' => (string) $report['total_applied'], 'type' => 'n'],
        ],
        5 => [
            1 => ['value' => 'FILING RATE (%)', 'style' => 1],
            2 => ['value' => (string) $report['filing_rate'], 'type' => 'n'],
        ],
        7 => [
            1 => ['value' => 'SCHOOL', 'style' => 1],
            2 => ['value' => 'ON RECORD', 'style' => 1],
            3 => ['value' => 'APPLIED', 'style' => 1],
            4 => ['value' => 'FILING RATE (%)', 'style' => 1],
        ],
    ];

    $rowNum = 8;
    foreach ($report['schools'] as $school) {
        $rows[$rowNum] = [
            1 => ['value' => $school['school_display']],
            2 => ['value' => (string) $school['on_record'], 'type' => 'n'],
            3 => ['value' => (string) $school['applied'], 'type' => 'n'],
            4 => ['value' => (string) $school['filing_rate'], 'type' => 'n'],
        ];
        $rowNum++;
    }

    $sheetXml = xlsx_build_sheet_xml($rows, [1 => 40, 2 => 14, 3 => 12, 4 => 16]);

    return xlsx_zip_parts([
        '[Content_Types].xml' => xlsx_content_types_xml(),
        '_rels/.rels' => xlsx_package_rels_xml(),
        'xl/workbook.xml' => xlsx_workbook_xml('APPLICATION FILING REPORT'),
        'xl/_rels/workbook.xml.rels' => xlsx_workbook_rels_xml(),
        'xl/styles.xml' => xlsx_styles_xml(),
        'xl/worksheets/sheet1.xml' => $sheetXml,
    ]);
}

/**
 * @param array<int, array{school_display: string, honor_count: int}> $rows
 *     Same shape get_school_ranking_by_honor_count() (includes/reports.php)
 *     returns, with each row additionally carrying a pre-formatted
 *     'school_display' string (see build_application_filing_report_xlsx()'s
 *     docblock for why this file doesn't format it itself).
 */
function build_school_ranking_report_xlsx(array $rows, int $year): string
{
    $sheetRows = [
        1 => [
            1 => ['value' => 'SCHOOL RANKING REPORT — ' . $year, 'style' => 1],
        ],
        2 => [
            1 => ['value' => 'RANK', 'style' => 1],
            2 => ['value' => 'SCHOOL', 'style' => 1],
            3 => ['value' => 'LATIN HONOR GRADUATES', 'style' => 1],
        ],
    ];

    $rowNum = 3;
    $rank = 1;
    foreach ($rows as $row) {
        $sheetRows[$rowNum] = [
            1 => ['value' => (string) $rank, 'type' => 'n'],
            2 => ['value' => $row['school_display']],
            3 => ['value' => (string) $row['honor_count'], 'type' => 'n'],
        ];
        $rowNum++;
        $rank++;
    }

    $sheetXml = xlsx_build_sheet_xml($sheetRows, [1 => 10, 2 => 40, 3 => 22]);

    return xlsx_zip_parts([
        '[Content_Types].xml' => xlsx_content_types_xml(),
        '_rels/.rels' => xlsx_package_rels_xml(),
        'xl/workbook.xml' => xlsx_workbook_xml('SCHOOL RANKING REPORT'),
        'xl/_rels/workbook.xml.rels' => xlsx_workbook_rels_xml(),
        'xl/styles.xml' => xlsx_styles_xml(),
        'xl/worksheets/sheet1.xml' => $sheetXml,
    ]);
}
