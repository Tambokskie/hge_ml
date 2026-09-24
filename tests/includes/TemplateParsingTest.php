<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/template_upload.php';

class TemplateParsingTest extends TestCase
{
    /**
     * Builds a [rowNum => [colIndex => value]] sheet exactly like
     * xlsx_read_first_sheet() would return it, for a template whose header
     * block and data rows can be overridden per test.
     */
    private function sheet(array $header = [], array $dataRows = [], int $headerRowNum = 6): array
    {
        $header = array_merge([
            'school_name_label' => '[SCHOOL NAME]',
            'school_name' => 'VISAYAS STATE UNIVERSITY',
            'campus_label' => '[CAMPUS]',
            'campus' => 'MAIN CAMPUS',
            'date_label' => '[DATE OF GRADUATION]',
            'date' => '2025-07-24',
            'year_label' => '[YEAR]',
            'year' => '2025',
        ], $header);

        $rows = [
            1 => [1 => $header['school_name_label'], 2 => $header['school_name']],
            2 => [1 => $header['campus_label'], 2 => $header['campus']],
            3 => [1 => $header['date_label'], 2 => $header['date']],
            4 => [1 => $header['year_label'], 2 => $header['year']],
            $headerRowNum => [1 => 'SEQUENCE', 2 => 'LAST NAME', 3 => 'GIVEN NAME', 4 => 'MIDDLE NAME', 5 => 'LATIN HONOR', 6 => 'COURSE'],
        ];

        $rowNum = $headerRowNum + 1;
        foreach ($dataRows as $dataRow) {
            $rows[$rowNum] = [
                1 => $dataRow['sequence'] ?? '',
                2 => $dataRow['last_name'] ?? '',
                3 => $dataRow['given_name'] ?? '',
                4 => $dataRow['middle_name'] ?? '',
                5 => $dataRow['latin_honor'] ?? '',
                6 => $dataRow['course'] ?? '',
            ];
            $rowNum++;
        }

        return $rows;
    }

    public function testValidTemplateParsesExpectedRows(): void
    {
        $sheet = $this->sheet([], [
            ['sequence' => '1', 'last_name' => 'Dela Cruz', 'given_name' => 'Juan', 'middle_name' => 'Santos', 'latin_honor' => 'cum laude', 'course' => 'BS Computer Science'],
            ['sequence' => '2', 'last_name' => 'Reyes', 'given_name' => 'Maria', 'middle_name' => '', 'latin_honor' => 'MAGNA CUM LAUDE', 'course' => 'BS Accountancy'],
        ]);

        $result = parse_latin_honor_template($sheet);

        $this->assertSame('success', $result['status']);
        $this->assertSame('VISAYAS STATE UNIVERSITY', $result['school_name']);
        $this->assertSame('MAIN CAMPUS', $result['campus']);
        $this->assertSame('2025', $result['school_year']);
        $this->assertSame('2025-07-24', $result['graduation_date']);
        $this->assertCount(2, $result['rows']);

        $this->assertSame('DELA CRUZ JUAN SANTOS', $result['rows'][0]['graduate_name']);
        $this->assertSame('BS COMPUTER SCIENCE', $result['rows'][0]['course']);
        $this->assertSame('CUM LAUDE', $result['rows'][0]['latin_honor']);
        $this->assertSame('2025-07-24', $result['rows'][0]['graduation_date']);
        $this->assertSame(2025, $result['rows'][0]['graduation_year']);

        // No middle name: no stray double space.
        $this->assertSame('REYES MARIA', $result['rows'][1]['graduate_name']);
        $this->assertSame('MAGNA CUM LAUDE', $result['rows'][1]['latin_honor']);
    }

    public function testWrongHeaderLabelIsRejectedAsInvalidTemplate(): void
    {
        $sheet = $this->sheet(['school_name_label' => '[INSTITUTION NAME]']);

        $result = parse_latin_honor_template($sheet);

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('DOES NOT LOOK LIKE THE LATIN HONOR TEMPLATE', $result['message']);
    }

    public function testMissingColumnHeaderRowIsRejected(): void
    {
        $sheet = $this->sheet();
        unset($sheet[6]);

        $result = parse_latin_honor_template($sheet);

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('HEADER ROW COULD NOT BE FOUND', $result['message']);
    }

    public function testMissingSchoolNameIsRejected(): void
    {
        $sheet = $this->sheet(['school_name' => '']);

        $result = parse_latin_honor_template($sheet);

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('MISSING THE SCHOOL NAME', $result['message']);
    }

    public function testInvalidDateIsRejected(): void
    {
        $sheet = $this->sheet(['date' => 'not a date']);

        $result = parse_latin_honor_template($sheet);

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('NOT A VALID DATE', $result['message']);
    }

    public function testExcelSerialDateIsConverted(): void
    {
        // 45862 is the Excel serial number for 2025-07-24 (days since
        // 1899-12-30, the base PHP's UTC-anchored formula assumes).
        $sheet = $this->sheet(['date' => '45862'], [
            ['sequence' => '1', 'last_name' => 'Dela Cruz', 'given_name' => 'Juan', 'latin_honor' => 'CUM LAUDE', 'course' => 'BS Computer Science'],
        ]);

        $result = parse_latin_honor_template($sheet);

        $this->assertSame('success', $result['status']);
        $this->assertSame('2025-07-24', $result['graduation_date']);
    }

    public function testYearIsDerivedFromDateWhenYearCellIsBlank(): void
    {
        $sheet = $this->sheet(['year' => ''], [
            ['sequence' => '1', 'last_name' => 'Dela Cruz', 'given_name' => 'Juan', 'latin_honor' => 'CUM LAUDE', 'course' => 'BS Computer Science'],
        ]);

        $result = parse_latin_honor_template($sheet);

        $this->assertSame('success', $result['status']);
        $this->assertSame('2025', $result['school_year']);
    }

    public function testMissingYearAndDateIsRejected(): void
    {
        $sheet = $this->sheet(['year' => '', 'date' => '']);

        $result = parse_latin_honor_template($sheet);

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('YEAR IN THE TEMPLATE IS MISSING', $result['message']);
    }

    public function testRowMissingRequiredFieldIsReportedByExcelRowNumber(): void
    {
        $sheet = $this->sheet([], [
            ['sequence' => '1', 'last_name' => 'Dela Cruz', 'given_name' => 'Juan', 'latin_honor' => 'CUM LAUDE', 'course' => 'BS Computer Science'],
            ['sequence' => '2', 'last_name' => '', 'given_name' => 'Maria', 'latin_honor' => 'CUM LAUDE', 'course' => 'BS Accountancy'],
        ]);

        $result = parse_latin_honor_template($sheet);

        $this->assertSame('error', $result['status']);
        // Header block rows 1-4, blank row 5, column-header row 6, so the
        // second data row (missing LAST NAME) is Excel row 8.
        $this->assertStringContainsString('EXCEL ROW(S) 8', $result['message']);
    }

    public function testInvalidLatinHonorValueIsRejected(): void
    {
        $sheet = $this->sheet([], [
            ['sequence' => '1', 'last_name' => 'Dela Cruz', 'given_name' => 'Juan', 'latin_honor' => 'HONORABLE MENTION', 'course' => 'BS Computer Science'],
        ]);

        $result = parse_latin_honor_template($sheet);

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('EXCEL ROW(S) 7', $result['message']);
    }

    public function testBlankTrailingRowsAreIgnored(): void
    {
        $sheet = $this->sheet([], [
            ['sequence' => '1', 'last_name' => 'Dela Cruz', 'given_name' => 'Juan', 'latin_honor' => 'CUM LAUDE', 'course' => 'BS Computer Science'],
            [], // fully blank row — a leftover unfilled template row.
        ]);

        $result = parse_latin_honor_template($sheet);

        $this->assertSame('success', $result['status']);
        $this->assertCount(1, $result['rows']);
    }

    public function testNoDataRowsAtAllIsRejected(): void
    {
        $sheet = $this->sheet();

        $result = parse_latin_honor_template($sheet);

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('NO GRADUATE RECORDS TO IMPORT', $result['message']);
    }
}
