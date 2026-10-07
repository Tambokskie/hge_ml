<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/xlsx_writer.php';
require_once __DIR__ . '/../../includes/xlsx_reader.php';

class XlsxReadWriteTest extends TestCase
{
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->tempFiles = [];
    }

    private function writeTempFile(string $bytes): string
    {
        $path = sys_get_temp_dir() . '/hge_xlsx_test_' . uniqid() . '.xlsx';
        file_put_contents($path, $bytes);
        $this->tempFiles[] = $path;

        return $path;
    }

    public function testGeneratedTemplateRoundTripsThroughReader(): void
    {
        $bytes = build_latin_honor_template_xlsx('VISAYAS STATE UNIVERSITY', 'MAIN CAMPUS');
        $path = $this->writeTempFile($bytes);

        $result = xlsx_read_first_sheet($path);

        $this->assertTrue($result['ok']);
        $rows = $result['rows'];
        $this->assertSame('VISAYAS STATE UNIVERSITY', $rows[1][1]);
        $this->assertSame('MAIN CAMPUS', $rows[2][1]);
        $this->assertSame('[DAY/MONTH/YEAR]', $rows[3][1]);
        $this->assertSame('[YEAR]', $rows[4][1]);
        $this->assertSame(
            ['SEQUENCE', 'LAST NAME', 'GIVEN NAME', 'MIDDLE NAME', 'LATIN HONOR', 'COURSE'],
            [$rows[6][1], $rows[6][2], $rows[6][3], $rows[6][4], $rows[6][5], $rows[6][6]]
        );
    }

    public function testReaderFollowsWorkbookRelationshipsRatherThanAssumingSheet1(): void
    {
        // Simulates a file whose internal worksheet part isn't named
        // "sheet1.xml" and whose text is shared-string-encoded (t="s")
        // instead of inline (t="inlineStr") — exactly what Excel/LibreOffice
        // produce whenever a person edits and re-saves our generated file.
        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetData>'
            . '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1"><v>42</v></c></row>'
            . '<row r="2"><c r="A2" t="s"><v>1</v></c></row>'
            . '</sheetData></worksheet>';

        $sharedStringsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="2" uniqueCount="2">'
            . '<si><t>HELLO WORLD</t></si><si><t>SECOND STRING</t></si></sst>';

        $bytes = xlsx_zip_parts([
            '[Content_Types].xml' => xlsx_content_types_xml(),
            '_rels/.rels' => xlsx_package_rels_xml(),
            'xl/workbook.xml' => xlsx_workbook_xml('SHEETX'),
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/customname.xml"/>'
                . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
                . '</Relationships>',
            'xl/worksheets/customname.xml' => $sheetXml,
            'xl/sharedStrings.xml' => $sharedStringsXml,
        ]);

        $path = $this->writeTempFile($bytes);
        $result = xlsx_read_first_sheet($path);

        $this->assertTrue($result['ok']);
        $this->assertSame('HELLO WORLD', $result['rows'][1][1]);
        $this->assertSame('42', $result['rows'][1][2]);
        $this->assertSame('SECOND STRING', $result['rows'][2][1]);
    }

    public function testReadingANonXlsxFileFailsGracefully(): void
    {
        $path = sys_get_temp_dir() . '/hge_not_xlsx_' . uniqid() . '.xlsx';
        file_put_contents($path, 'not a zip file at all');
        $this->tempFiles[] = $path;

        $result = xlsx_read_first_sheet($path);

        $this->assertFalse($result['ok']);
        $this->assertNotEmpty($result['error']);
    }

    public function testColumnLetterHelperRoundTripsForMultiLetterColumns(): void
    {
        $this->assertSame('A', xlsx_column_letter(1));
        $this->assertSame('Z', xlsx_column_letter(26));
        $this->assertSame('AA', xlsx_column_letter(27));
        $this->assertSame('AB', xlsx_column_letter(28));

        $this->assertSame(1, xlsx_column_index('A1'));
        $this->assertSame(26, xlsx_column_index('Z9'));
        $this->assertSame(27, xlsx_column_index('AA100'));
        $this->assertSame(28, xlsx_column_index('AB1'));
    }

    public function testApplicationFilingReportXlsxRoundTripsThroughReader(): void
    {
        $report = [
            'total_on_record' => 3,
            'total_applied' => 1,
            'filing_rate' => 33.3,
            'schools' => [
                ['school_display' => 'ABC UNIVERSITY-MAIN', 'on_record' => 2, 'applied' => 1, 'filing_rate' => 50.0],
                ['school_display' => 'XYZ COLLEGE', 'on_record' => 1, 'applied' => 0, 'filing_rate' => 0.0],
            ],
        ];

        $bytes = build_application_filing_report_xlsx($report, 2025);
        $path = $this->writeTempFile($bytes);

        $result = xlsx_read_first_sheet($path);

        $this->assertTrue($result['ok']);
        $rows = $result['rows'];
        $this->assertSame('3', $rows[3][2]);
        $this->assertSame('1', $rows[4][2]);
        $this->assertSame('ABC UNIVERSITY-MAIN', $rows[8][1]);
        $this->assertSame('2', $rows[8][2]);
        $this->assertSame('XYZ COLLEGE', $rows[9][1]);
        $this->assertSame('0', $rows[9][3]);
    }

    public function testApplicationFilingReportXlsxTitleShowsTheSelectedPeriod(): void
    {
        $report = ['total_on_record' => 0, 'total_applied' => 0, 'filing_rate' => 0.0, 'schools' => []];

        $range = xlsx_read_first_sheet($this->writeTempFile(build_application_filing_report_xlsx($report, 2022, 2026)));
        $single = xlsx_read_first_sheet($this->writeTempFile(build_application_filing_report_xlsx($report, 2025, 2025)));

        $this->assertSame('APPLICATION FILING REPORT — 2022–2026', $range['rows'][1][1]);
        $this->assertSame('APPLICATION FILING REPORT — 2025', $single['rows'][1][1]);
    }

}
