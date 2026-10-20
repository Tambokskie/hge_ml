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
 * file and returns its raw bytes. Written by hand (deflate + a minimal ZIP
 * container) rather than with ZipArchive, which is not installed here, or
 * PharData, whose archives declare "version needed" 0 and carry an extra
 * field per entry — Excel refuses to open files packaged that way.
 * Entries are written in the order given ([Content_Types].xml first).
 */
function xlsx_zip_parts(array $parts): string
{
    $dosTime = 0;
    $dosDate = ((2026 - 1980) << 9) | (1 << 5) | 1;
    $body = '';
    $central = '';
    $count = 0;

    foreach ($parts as $name => $data) {
        $name = (string) $name;
        $data = (string) $data;
        $deflated = gzdeflate($data, 6);
        if ($deflated === false) {
            throw new \RuntimeException('Could not compress xlsx part.');
        }
        $crc = crc32($data);
        $offset = strlen($body);

        // 20 = version needed (2.0), 0x0800 = UTF-8 names, 8 = deflate
        $body .= "PK\x03\x04" . pack('vvvvvVVVvv', 20, 0x0800, 8, $dosTime, $dosDate, $crc, strlen($deflated), strlen($data), strlen($name), 0)
            . $name . $deflated;
        $central .= "PK\x01\x02" . pack('vvvvvvVVVvvvvvVV', 20, 20, 0x0800, 8, $dosTime, $dosDate, $crc, strlen($deflated), strlen($data), strlen($name), 0, 0, 0, 0, 0, $offset)
            . $name;
        $count++;
    }

    return $body . $central . "PK\x05\x06" . pack('vvvvVVv', 0, 0, $count, $count, strlen($central), strlen($body), 0);
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
 * Template for the ADMIN page's bulk update of HGE applications. The column
 * names match the source system's own export (CoEIssueDate, LName, FName, MI,
 * MName, ExtName) so an export can be uploaded as-is; the instruction block
 * above the header row is skipped by the parser (it finds the header itself).
 */
function build_bulk_hge_template_xlsx(): string
{
    $rows = [
        1 => [1 => ['value' => 'BULK UPDATE OF HGE APPLICATIONS — ONE ROW PER APPLICANT', 'style' => 1]],
        2 => [1 => ['value' => 'CoEIssueDate = DATE APPLIED (e.g. 4/2/2024 or 2024-04-02). LName, FName AND CoEIssueDate ARE REQUIRED.', 'style' => 2]],
        3 => [1 => ['value' => 'MI AND MName ARE OPTIONAL. ENTER _ IN MI / MName WHEN THE APPLICANT HAS NO MIDDLE NAME. ExtName IS JR., SR., II, III OR IV (OPTIONAL).', 'style' => 2]],
        4 => [1 => ['value' => 'DO NOT CHANGE OR REMOVE THE HEADER ROW BELOW. KEEP ALL DATA ON THIS FIRST SHEET.', 'style' => 2]],
        6 => [
            1 => ['value' => 'CoEIssueDate', 'style' => 1],
            2 => ['value' => 'LName', 'style' => 1],
            3 => ['value' => 'FName', 'style' => 1],
            4 => ['value' => 'MI', 'style' => 1],
            5 => ['value' => 'MName', 'style' => 1],
            6 => ['value' => 'ExtName', 'style' => 1],
        ],
    ];

    for ($i = 0; $i < 50; $i++) {
        $rows[7 + $i][1] = ['value' => '', 'style' => 3];
    }

    return xlsx_zip_parts([
        '[Content_Types].xml' => xlsx_content_types_xml(),
        '_rels/.rels' => xlsx_package_rels_xml(),
        'xl/workbook.xml' => xlsx_workbook_xml('tbl_HGE'),
        'xl/_rels/workbook.xml.rels' => xlsx_workbook_rels_xml(),
        'xl/styles.xml' => xlsx_styles_xml(),
        'xl/worksheets/sheet1.xml' => xlsx_build_sheet_xml($rows, [1 => 16, 2 => 24, 3 => 26, 4 => 8, 5 => 24, 6 => 10]),
    ]);
}

/**
 * Template for admin/review.php's UPLOAD EXCEL: just the header row
 * (see review_import_header_aliases()), one graduate per row beneath it.
 */
function build_review_import_template_xlsx(): string
{
    $rows = [
        1 => [
            1 => ['value' => 'Name of Honor Graduate', 'style' => 1],
            2 => ['value' => 'Course', 'style' => 1],
            3 => ['value' => 'Honors Received', 'style' => 1],
            4 => ['value' => 'Date of Graduation', 'style' => 1],
        ],
    ];

    return xlsx_zip_parts([
        '[Content_Types].xml' => xlsx_content_types_xml(),
        '_rels/.rels' => xlsx_package_rels_xml(),
        'xl/workbook.xml' => xlsx_workbook_xml('HONOR GRADUATES'),
        'xl/_rels/workbook.xml.rels' => xlsx_workbook_rels_xml(),
        'xl/styles.xml' => xlsx_styles_xml(),
        'xl/worksheets/sheet1.xml' => xlsx_build_sheet_xml($rows, [1 => 42, 2 => 42, 3 => 20, 4 => 22]),
    ]);
}
/**
 * Companion file of the bulk update preview: everything that was NOT filed
 * automatically and needs a human look (uncertain matches), plus rows that
 * could not be read at all.
 *
 * @param array<int, array{reason: string, person: array, saved_names: array<int, string>}> $review
 * @param array<int, array{row: int, message: string, name: string}> $invalid
 */
function build_bulk_hge_review_xlsx(array $review, array $invalid): string
{
    $rows = [
        1 => [1 => ['value' => 'BULK UPDATE — ITEMS NEEDING REVIEW (NOTHING IN THIS FILE WAS FILED)', 'style' => 1]],
        3 => [
            1 => ['value' => 'ROW IN FILE', 'style' => 1],
            2 => ['value' => 'DATE APPLIED', 'style' => 1],
            3 => ['value' => 'LAST NAME', 'style' => 1],
            4 => ['value' => 'FIRST NAME', 'style' => 1],
            5 => ['value' => 'MI', 'style' => 1],
            6 => ['value' => 'MIDDLE NAME', 'style' => 1],
            7 => ['value' => 'EXT', 'style' => 1],
            8 => ['value' => 'ISSUE', 'style' => 1],
            9 => ['value' => 'POSSIBLE SAVED RECORDS', 'style' => 1],
        ],
    ];

    $rowNum = 4;
    foreach ($review as $item) {
        $person = $item['person'];
        $rows[$rowNum++] = [
            1 => ['value' => (string) $person['row'], 'type' => 'n'],
            2 => ['value' => (string) $person['date']],
            3 => ['value' => (string) $person['last']],
            4 => ['value' => (string) $person['first']],
            5 => ['value' => (string) $person['mi']],
            6 => ['value' => (string) $person['middle']],
            7 => ['value' => (string) $person['ext']],
            8 => ['value' => (string) $item['reason']],
            9 => ['value' => implode(' || ', $item['saved_names'])],
        ];
    }
    foreach ($invalid as $item) {
        $rows[$rowNum++] = [
            1 => ['value' => (string) $item['row'], 'type' => 'n'],
            3 => ['value' => (string) $item['name']],
            8 => ['value' => 'ROW NOT READ — ' . $item['message']],
        ];
    }

    return xlsx_zip_parts([
        '[Content_Types].xml' => xlsx_content_types_xml(),
        '_rels/.rels' => xlsx_package_rels_xml(),
        'xl/workbook.xml' => xlsx_workbook_xml('REVIEW'),
        'xl/_rels/workbook.xml.rels' => xlsx_workbook_rels_xml(),
        'xl/styles.xml' => xlsx_styles_xml(),
        'xl/worksheets/sheet1.xml' => xlsx_build_sheet_xml($rows, [1 => 12, 2 => 14, 3 => 22, 4 => 22, 5 => 6, 6 => 20, 7 => 6, 8 => 60, 9 => 70]),
    ]);
}
