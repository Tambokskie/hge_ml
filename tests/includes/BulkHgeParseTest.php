<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/bulk_hge.php';

class BulkHgeParseTest extends TestCase
{
    private const HEADER = [1 => 'CoEIssueDate', 2 => 'LName', 3 => 'FName', 4 => 'MI', 5 => 'MName', 6 => 'ExtName'];

    private function parse(array $dataRows, array $header = self::HEADER, bool $date1904 = false): array
    {
        $sheet = [1 => $header];
        foreach ($dataRows as $i => $row) {
            $sheet[$i + 2] = $row;
        }

        return parse_bulk_hge_rows($sheet, $date1904);
    }

    public function testParsesAFullRow(): void
    {
        $result = $this->parse([[1 => '45384', 2 => 'Sandagan', 3 => 'Nilven', 4 => 'A.', 5 => 'Arozado']]);

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['rows_read']);
        $this->assertSame([
            'row' => 2, 'date' => '2024-04-02', 'last' => 'Sandagan', 'first' => 'Nilven',
            'mi' => 'A', 'middle' => 'Arozado', 'ext' => '',
        ], $result['people'][0]);
    }

    public function testUnderscoreAndJunkMeanNoMiddleName(): void
    {
        $result = $this->parse([
            [1 => '45384', 2 => 'A', 3 => 'B', 4 => '_', 5 => '_'],
            [1 => '45385', 2 => 'C', 3 => 'D', 4 => '__.', 5 => '.'],
            [1 => '45386', 2 => 'E', 3 => 'F'],
        ]);

        foreach ($result['people'] as $person) {
            $this->assertSame('', $person['mi']);
            $this->assertSame('', $person['middle']);
        }
        $this->assertCount(3, $result['people']);
    }

    public function testMiAndMiddleNameAreOptionalIndependently(): void
    {
        $result = $this->parse([
            [1 => '45384', 2 => 'A', 3 => 'B', 4 => 'c.'],
            [1 => '45385', 2 => 'D', 3 => 'E', 5 => 'Fernandez'],
        ]);

        $this->assertSame('C', $result['people'][0]['mi']);
        $this->assertSame('', $result['people'][0]['middle']);
        $this->assertSame('', $result['people'][1]['mi']);
        $this->assertSame('Fernandez', $result['people'][1]['middle']);
    }

    public function testHeaderWithoutMiAndMNameColumnsStillParses(): void
    {
        $result = $this->parse([[1 => '45384', 2 => 'A', 3 => 'B']], [1 => 'CoEIssueDate', 2 => 'LName', 3 => 'FName']);

        $this->assertTrue($result['ok']);
        $this->assertCount(1, $result['people']);
    }

    public function testExtensionVariantsAreNormalizedAndJunkIgnored(): void
    {
        $result = $this->parse([
            [1 => '45384', 2 => 'A', 3 => 'B', 6 => 'JR.'],
            [1 => '45384', 2 => 'C', 3 => 'D', 6 => 'jr'],
            [1 => '45384', 2 => 'E', 3 => 'F', 6 => 'III'],
            [1 => '45384', 2 => 'G', 3 => 'H', 6 => '.'],
            [1 => '45384', 2 => 'I', 3 => 'J', 6 => '\\'],
        ]);

        $this->assertSame(['JR', 'JR', 'III', '', ''], array_column($result['people'], 'ext'));
    }

    public function testHeaderIsFoundBelowAnInstructionBlockAndAliasesAccepted(): void
    {
        $sheet = [
            1 => [1 => 'BULK UPDATE — INSTRUCTIONS'],
            2 => [1 => 'some more notes'],
            6 => [1 => 'Date Applied', 2 => 'Last Name', 3 => 'First Name', 4 => 'Middle Initial', 5 => 'Middle Name', 6 => 'Ext Name'],
            7 => [1 => '2024-04-02', 2 => 'Cruz', 3 => 'Juan', 4 => 'D', 5 => 'Dela', 6 => 'Sr.'],
        ];

        $result = parse_bulk_hge_rows($sheet);

        $this->assertTrue($result['ok']);
        $this->assertSame('2024-04-02', $result['people'][0]['date']);
        $this->assertSame('Cruz', $result['people'][0]['last']);
        $this->assertSame('SR', $result['people'][0]['ext']);
        $this->assertSame(7, $result['people'][0]['row']);
    }

    public function testMissingRequiredHeaderIsRejected(): void
    {
        $result = parse_bulk_hge_rows([1 => [1 => 'Name', 2 => 'Date'], 2 => [1 => 'x', 2 => 'y']]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('HEADER ROW', $result['error']);
    }

    public function testHeaderBeyondTheScanWindowIsNotFound(): void
    {
        $sheet = [20 => self::HEADER, 21 => [1 => '45384', 2 => 'A', 3 => 'B']];

        $this->assertFalse(parse_bulk_hge_rows($sheet)['ok']);
    }

    public function testDateFormats(): void
    {
        $this->assertSame('2024-04-02', bulk_hge_parse_date('45384'));
        $this->assertSame('2024-04-02', bulk_hge_parse_date('45384.5625'));
        $this->assertSame('2024-04-02', bulk_hge_parse_date('2024-04-02'));
        $this->assertSame('2024-04-02', bulk_hge_parse_date('4/2/2024'));
        $this->assertSame('2024-04-02', bulk_hge_parse_date('2024/4/2'));
        $this->assertSame('2024-04-02', bulk_hge_parse_date('April 2, 2024'));
        $this->assertSame('2024-04-02', bulk_hge_parse_date('45384 ', false));
    }

    public function testInvalidDatesAreRejected(): void
    {
        $this->assertNull(bulk_hge_parse_date(''));
        $this->assertNull(bulk_hge_parse_date('not a date'));
        $this->assertNull(bulk_hge_parse_date('13/45/2024'));
        $this->assertNull(bulk_hge_parse_date('2/30/2024'));
        $this->assertNull(bulk_hge_parse_date('12'));
        $this->assertNull(bulk_hge_parse_date('1850-01-01'));
        $this->assertNull(bulk_hge_parse_date((string) (((int) date('Y') + 5)) . '-01-01'));
    }

    public function testDate1904BaseIsHonoured(): void
    {
        // 1904-based serial 43922 = 1900-based 45384 (2024-04-02).
        $this->assertSame('2024-04-02', bulk_hge_parse_date('43922', true));
    }

    public function testRowsWithoutNamesOrWithBadDatesAreReportedNotDropped(): void
    {
        $result = $this->parse([
            [1 => '45384', 2 => 'OnlyLast'],
            [1 => '45384', 3 => 'OnlyFirst'],
            [1 => 'garbage', 2 => 'A', 3 => 'B'],
            [2 => 'A', 3 => 'B'],
            [1 => '45384', 2 => 'Good', 3 => 'Row'],
        ]);

        $this->assertCount(1, $result['people']);
        $this->assertSame(5, $result['rows_read']);
        $this->assertSame([2, 3, 4, 5], array_column($result['errors'], 'row'));
        $this->assertStringContainsString('NOT A VALID DATE', $result['errors'][2]['message']);
        $this->assertStringContainsString('MISSING', $result['errors'][3]['message']);
    }

    public function testFullyBlankRowsAreIgnoredSilently(): void
    {
        $result = $this->parse([[], [1 => ' ', 2 => ''], [1 => '45384', 2 => 'A', 3 => 'B']]);

        $this->assertSame(1, $result['rows_read']);
        $this->assertSame([], $result['errors']);
    }

    public function testSamePersonOnSeveralRowsKeepsTheEarliestDate(): void
    {
        $result = $this->parse([
            [1 => '45500', 2 => 'Cruz', 3 => 'Juan', 4 => 'D.', 5 => 'Dela'],
            [1 => '45384', 2 => 'CRUZ', 3 => 'JUAN', 4 => 'd', 5 => 'DELA'],
            [1 => '45600', 2 => 'Cruz', 3 => 'Juan', 4 => 'D.', 5 => 'Dela'],
        ]);

        $this->assertCount(1, $result['people']);
        $this->assertSame('2024-04-02', $result['people'][0]['date']);
        $this->assertSame(2, $result['duplicate_rows']);
        $this->assertSame(3, $result['rows_read']);
    }

    public function testDifferentMiddleNamesAreDifferentPeople(): void
    {
        $result = $this->parse([
            [1 => '45384', 2 => 'Cruz', 3 => 'Juan', 5 => 'Santos'],
            [1 => '45384', 2 => 'Cruz', 3 => 'Juan', 5 => 'Reyes'],
            [1 => '45384', 2 => 'Cruz', 3 => 'Juan', 5 => 'Santos', 6 => 'JR.'],
        ]);

        $this->assertCount(3, $result['people']);
    }

    public function testNameTokensFoldCaseEnyeAndStripNoise(): void
    {
        $this->assertSame(['MONTANEZ', 'CHARISSE', 'ELLA'], bulk_hge_name_tokens('Montañez, Charisse Ella')['tokens']);
        $this->assertSame(['CRUZ', 'JUAN'], bulk_hge_name_tokens('Ms. Cruz, Juan 1.56')['tokens']);

        $withExt = bulk_hge_name_tokens('CRUZ, JUAN JR.');
        $this->assertSame(['CRUZ', 'JUAN'], $withExt['tokens']);
        $this->assertSame('JR', $withExt['ext']);
    }

    public function testNoneMarkerDetection(): void
    {
        foreach (['', ' ', '_', '__.', '.', '-', '\\', ' _ '] as $value) {
            $this->assertTrue(bulk_hge_is_none($value), "[$value] should mean none");
        }
        foreach (['A', 'A.', 'Santos', '_A'] as $value) {
            $this->assertFalse(bulk_hge_is_none($value), "[$value] should be a value");
        }
    }

    public function testReadUploadRejectsWrongExtensionOversizeAndMismatchedContent(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bulk');
        file_put_contents($path, "not really excel\n");

        try {
            $this->assertFalse(bulk_hge_read_upload($path, 'list.pdf', 20)['ok']);
            $this->assertFalse(bulk_hge_read_upload($path, 'list.xls', 20)['ok']);
            $this->assertFalse(bulk_hge_read_upload($path, 'list.xlsx', 20)['ok']);
            $this->assertFalse(bulk_hge_read_upload($path, 'list.csv', BULK_HGE_MAX_BYTES + 1)['ok']);
            $this->assertFalse(bulk_hge_read_upload($path, 'list.csv', 0)['ok']);
        } finally {
            @unlink($path);
        }
    }

    public function testReadUploadParsesCsvEndToEnd(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bulk');
        $csv = "CoEIssueDate,LName,FName,MI,MName,ExtName\n4/2/2024,SANDAGAN,NILVEN,A.,AROZADO,\n";
        file_put_contents($path, $csv);

        try {
            $read = bulk_hge_read_upload($path, 'list.CSV', strlen($csv));
            $this->assertTrue($read['ok']);
            $parsed = parse_bulk_hge_rows($read['rows']);
            $this->assertSame('2024-04-02', $parsed['people'][0]['date']);
            $this->assertSame('AROZADO', $parsed['people'][0]['middle']);
        } finally {
            @unlink($path);
        }
    }

    public function testReadUploadParsesTheRealXlsFixtureThroughTheFullPipeline(): void
    {
        $path = __DIR__ . '/../fixtures/xls-reader-sample.xls';
        $read = bulk_hge_read_upload($path, 'sample.xls', filesize($path));

        $this->assertTrue($read['ok']);
        $parsed = parse_bulk_hge_rows($read['rows'], $read['date1904']);
        $this->assertTrue($parsed['ok']);
        $this->assertSame('2024-04-02', $parsed['people'][0]['date']);
        $this->assertSame('SANDAGAN', $parsed['people'][0]['last']);
        $this->assertSame('A', $parsed['people'][0]['mi']);
        // Row 3: MI and MName are "_" (no middle name), ExtName "JR."
        $this->assertSame('', $parsed['people'][1]['middle']);
        $this->assertSame('JR', $parsed['people'][1]['ext']);
        $this->assertSame('MONTAÑEZ', $parsed['people'][1]['last']);
    }

    public function testTemplateRoundTripsThroughTheXlsxReaderAndParser(): void
    {
        require_once __DIR__ . '/../../includes/xlsx_writer.php';
        require_once __DIR__ . '/../../includes/xlsx_reader.php';

        $path = tempnam(sys_get_temp_dir(), 'tpl');
        file_put_contents($path, build_bulk_hge_template_xlsx());

        try {
            $read = bulk_hge_read_upload($path, 'template.xlsx', filesize($path));
            $this->assertTrue($read['ok'], $read['error'] ?? '');
            $this->assertSame('CoEIssueDate', $read['rows'][6][1]);

            // An empty template has the header but no people: that parses (0 people), it is not an error.
            $parsed = parse_bulk_hge_rows($read['rows']);
            $this->assertTrue($parsed['ok']);
            $this->assertSame([], $parsed['people']);
        } finally {
            @unlink($path);
        }
    }
}
