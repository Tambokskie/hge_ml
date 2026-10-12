<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/extraction_review.php';
require_once __DIR__ . '/../../includes/duplicates.php';
require_once __DIR__ . '/../../includes/bulk_hge.php';
require_once __DIR__ . '/../../includes/review_import.php';
require_once __DIR__ . '/../../includes/xlsx_writer.php';

class ReviewImportTest extends TestCase
{
    private const HEADERS = ['Name of Honor Graduate', 'Course', 'Honors Received', 'Date of Graduation'];

    /** A worksheet the way every reader returns it: [rowNum => [colNum => string]]. */
    private function sheet(array $rows, array $headers = self::HEADERS): array
    {
        $sheet = [1 => array_combine(range(1, count($headers)), $headers)];
        foreach ($rows as $i => $row) {
            $sheet[$i + 2] = array_combine(range(1, count($row)), $row);
        }

        return $sheet;
    }

    public function testTheTemplateLayoutBecomesManualRows(): void
    {
        $result = parse_review_import_rows($this->sheet([
            ['Cabradilla, Ana Grace Repunte', 'Bachelor of Arts in Communication', 'Cum Laude', 'April 16, 2025'],
            ['Verutiao, Nichole Lariba', 'Bachelor of Arts in Communication', 'Magna Cum Laude', 'April 16, 2025'],
            ['Cañete, Jamaica Risos', 'Bachelor of Arts in Economics', 'Cum Laude', 'April 16, 2025'],
        ]), 2024);

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['skipped']);
        $this->assertSame(0, $result['undated']);
        $this->assertCount(3, $result['rows']);
        $this->assertSame([
            'graduate_name' => 'Cabradilla, Ana Grace Repunte',
            'course' => 'Bachelor of Arts in Communication',
            'latin_honor' => 'CUM LAUDE',
            'graduation_date' => '2025-04-16',
            'graduation_year' => 2025,
            'source' => 'manual',
        ], $result['rows'][0]);
        $this->assertSame('MAGNA CUM LAUDE', $result['rows'][1]['latin_honor']);
        $this->assertSame('Cañete, Jamaica Risos', $result['rows'][2]['graduate_name']);
    }

    public function testHeadersMayBeInAnyOrderWithExtraColumnsAndBelowATitleRow(): void
    {
        $sheet = [
            1 => [1 => 'LIST OF HONOR GRADUATES'],
            3 => [1 => 'No.', 2 => 'Honors Received', 3 => 'NAME OF HONOR GRADUATE', 4 => 'Date of Graduation', 5 => 'Course'],
            4 => [1 => '1', 2 => 'summa cum laude', 3 => 'Reyes, Ana', 4 => '2025-04-16', 5 => 'BSA'],
        ];

        $result = parse_review_import_rows($sheet);

        $this->assertTrue($result['ok']);
        $this->assertSame(['Reyes, Ana', 'BSA', 'SUMMA CUM LAUDE', '2025-04-16'], [
            $result['rows'][0]['graduate_name'], $result['rows'][0]['course'], $result['rows'][0]['latin_honor'], $result['rows'][0]['graduation_date'],
        ]);
    }

    public function testRowsWithAMissingNameOrAnUnknownHonorAreSkippedWithTheirRowNumber(): void
    {
        $result = parse_review_import_rows($this->sheet([
            ['Reyes, Ana', 'BSA', 'Cum Laude', 'April 16, 2025'],
            ['', 'BSA', 'Cum Laude', 'April 16, 2025'],
            ['Santos, Maria', 'BSA', 'Honorable Mention', 'April 16, 2025'],
            ['Cruz, Juan', 'BSA', '', 'April 16, 2025'],
            ['', '', '', ''],
        ]));

        $this->assertCount(1, $result['rows']);
        $this->assertSame([3, 4, 5], array_column($result['skipped'], 'row'));
        $this->assertStringContainsString('NAME', $result['skipped'][0]['reason']);
        $this->assertStringContainsString('NOT CUM LAUDE', $result['skipped'][1]['reason']);
        $this->assertStringContainsString('MISSING', $result['skipped'][2]['reason']);
    }

    public function testAMissingOrUnreadableDateKeepsTheRowAndFallsBackToTheSchoolYear(): void
    {
        $result = parse_review_import_rows($this->sheet([
            ['Reyes, Ana', 'BSA', 'Cum Laude', ''],
            ['Santos, Maria', 'BSA', 'Cum Laude', 'sometime in spring'],
            ['Cruz, Juan', 'BSA', 'Cum Laude', '45763'],
        ]), 2025);

        $this->assertCount(3, $result['rows']);
        $this->assertSame(2, $result['undated']);
        $this->assertNull($result['rows'][0]['graduation_date']);
        $this->assertSame(2025, $result['rows'][0]['graduation_year']);
        $this->assertSame(['2025-04-16', 2025], [$result['rows'][2]['graduation_date'], $result['rows'][2]['graduation_year']], 'an Excel date serial is read as a date');
    }

    public function testWithoutAHeaderRowItIsAnError(): void
    {
        $result = parse_review_import_rows([1 => [1 => 'Reyes, Ana', 2 => 'BSA', 3 => 'Cum Laude']]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('HEADER', $result['error']);
    }

    public function testAHeaderWithNoRowsUnderItIsAnError(): void
    {
        $result = parse_review_import_rows($this->sheet([]));

        $this->assertFalse($result['ok']);
    }

    public function testTooManyRowsAreRefused(): void
    {
        $rows = array_fill(0, REVIEW_IMPORT_MAX_ROWS + 1, ['Reyes, Ana', 'BSA', 'Cum Laude', 'April 16, 2025']);

        $this->assertFalse(parse_review_import_rows($this->sheet($rows))['ok']);
    }

    public function testARealXlsxFileGoesThroughTheReaderAndParser(): void
    {
        $cell = static fn (string $v, bool $number = false): array => $number ? ['value' => $v, 'type' => 'n'] : ['value' => $v];
        $rows = [
            1 => [1 => $cell('Name of Honor Graduate'), 2 => $cell('Course'), 3 => $cell('Honors Received'), 4 => $cell('Date of Graduation')],
            2 => [1 => $cell('Ibañez, John Lloyd Acsaba'), 2 => $cell('Bachelor of Arts in Economics'), 3 => $cell('Cum Laude'), 4 => $cell('April 16, 2025')],
            3 => [1 => $cell('Luay, Melinda Arpon'), 2 => $cell('Bachelor of Arts in Economics'), 3 => $cell('Magna Cum Laude'), 4 => $cell('45763', true)],
        ];
        $xlsx = xlsx_zip_parts([
            '[Content_Types].xml' => xlsx_content_types_xml(),
            '_rels/.rels' => xlsx_package_rels_xml(),
            'xl/workbook.xml' => xlsx_workbook_xml('SHEET1'),
            'xl/_rels/workbook.xml.rels' => xlsx_workbook_rels_xml(),
            'xl/styles.xml' => xlsx_styles_xml(),
            'xl/worksheets/sheet1.xml' => xlsx_build_sheet_xml($rows),
        ]);
        $path = tempnam(sys_get_temp_dir(), 'hge_import_test_') . '.xlsx';
        file_put_contents($path, $xlsx);

        try {
            $sheet = bulk_hge_read_upload($path, 'honors.xlsx', (int) filesize($path));
            $this->assertTrue($sheet['ok'], $sheet['error'] ?? '');
            $result = parse_review_import_rows($sheet['rows'], 2025);
        } finally {
            @unlink($path);
        }

        $this->assertTrue($result['ok']);
        $this->assertSame(
            [['Ibañez, John Lloyd Acsaba', 'CUM LAUDE', '2025-04-16'], ['Luay, Melinda Arpon', 'MAGNA CUM LAUDE', '2025-04-16']],
            array_map(static fn (array $r): array => [$r['graduate_name'], $r['latin_honor'], $r['graduation_date']], $result['rows'])
        );
    }

    public function testTheDownloadableTemplateHasTheHeadersAndImportsOnceFilledIn(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'hge_tpl_') . '.xlsx';
        file_put_contents($path, build_review_import_template_xlsx());

        try {
            $sheet = bulk_hge_read_upload($path, 'template.xlsx', (int) filesize($path));
        } finally {
            @unlink($path);
        }

        $this->assertTrue($sheet['ok'], $sheet['error'] ?? '');
        $this->assertSame(self::HEADERS, array_values($sheet['rows'][1]));

        $empty = parse_review_import_rows($sheet['rows']);
        $this->assertFalse($empty['ok'], 'an empty template has nothing to import');

        $sheet['rows'][2] = [1 => 'Reyes, Ana', 2 => 'BSA', 3 => 'Cum Laude', 4 => 'April 16, 2025'];
        $filled = parse_review_import_rows($sheet['rows']);
        $this->assertTrue($filled['ok']);
        $this->assertSame('2025-04-16', $filled['rows'][0]['graduation_date']);
    }
    public function testAppendingKeepsTheExistingStagedRowsAndAddsTheNewOnesAtTheEnd(): void
    {
        $_SESSION = [];
        start_extraction_review(7, [['graduate_name' => 'Existing, Row', 'latin_honor' => 'CUM LAUDE', 'graduation_date' => null, 'graduation_year' => 2025]]);
        $new = parse_review_import_rows($this->sheet([['Reyes, Ana', 'BSA', 'Cum Laude', 'April 16, 2025']]))['rows'];

        $result = append_staged_rows(7, $new);

        $this->assertSame(['ok' => true, 'added' => 1], $result);
        $rows = get_extraction_review(7)['rows'];
        $this->assertSame(['Existing, Row', 'Reyes, Ana'], array_column($rows, 'graduate_name'));
        $this->assertSame('manual', $rows[1]['source']);
        $this->assertTrue(validate_staged_row($rows[1])['ok']);
    }

    public function testAppendingWithoutAnActiveReviewFails(): void
    {
        $_SESSION = [];

        $this->assertFalse(append_staged_rows(7, [['graduate_name' => 'X']])['ok']);
    }
}
