<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/csv_reader.php';

class CsvReaderTest extends TestCase
{
    /** @var array<int, string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
    }

    private function read(string $content, int $maxRows = 100000): array
    {
        $path = tempnam(sys_get_temp_dir(), 'csvtest');
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return csv_read_rows($path, $maxRows);
    }

    public function testReadsCommaSeparatedRowsOneIndexed(): void
    {
        $result = $this->read("CoEIssueDate,LName,FName\n2024-04-02,SANDAGAN,NILVEN\n");

        $this->assertTrue($result['ok']);
        $this->assertSame([1 => 'CoEIssueDate', 2 => 'LName', 3 => 'FName'], $result['rows'][1]);
        $this->assertSame([1 => '2024-04-02', 2 => 'SANDAGAN', 3 => 'NILVEN'], $result['rows'][2]);
    }

    public function testStripsUtf8Bom(): void
    {
        $result = $this->read("\xEF\xBB\xBFLName,FName\nA,B\n");

        $this->assertSame('LName', $result['rows'][1][1]);
    }

    public function testConvertsWindows1252ToUtf8(): void
    {
        // "MONTAÑEZ" saved by older Excel as ANSI: Ñ is the single byte 0xD1.
        $result = $this->read("LName,FName\nMONTA\xD1EZ,CHARISSE\n");

        $this->assertSame('MONTAÑEZ', $result['rows'][2][1]);
    }

    public function testKeepsValidUtf8Untouched(): void
    {
        $result = $this->read("LName,FName\nMONTAÑEZ,CHARISSE\n");

        $this->assertSame('MONTAÑEZ', $result['rows'][2][1]);
    }

    public function testDetectsSemicolonAndTabDelimiters(): void
    {
        $semicolon = $this->read("LName;FName;MI\nA;B;C\n");
        $tab = $this->read("LName\tFName\tMI\nA\tB\tC\n");

        $this->assertSame([1 => 'A', 2 => 'B', 3 => 'C'], $semicolon['rows'][2]);
        $this->assertSame([1 => 'A', 2 => 'B', 3 => 'C'], $tab['rows'][2]);
    }

    public function testQuotedFieldsMayContainDelimitersAndQuotes(): void
    {
        $result = $this->read("LName,FName\n\"DE LA CRUZ, JR\",\"JUAN \"\"JOHN\"\"\"\n");

        $this->assertSame('DE LA CRUZ, JR', $result['rows'][2][1]);
        $this->assertSame('JUAN "JOHN"', $result['rows'][2][2]);
    }

    public function testBlankLinesAreSkippedButRowNumbersStayAligned(): void
    {
        $result = $this->read("H1,H2\n\nA,B\n,,\nC,D\n");

        $this->assertSame([1, 3, 5], array_keys($result['rows']));
        $this->assertSame([1 => 'C', 2 => 'D'], $result['rows'][5]);
    }

    public function testEmptyCellsAreOmittedLikeTheXlsxReader(): void
    {
        $result = $this->read("A,B,C\nx,,z\n");

        $this->assertSame([1 => 'x', 3 => 'z'], $result['rows'][2]);
    }

    public function testHandlesWindowsLineEndings(): void
    {
        $result = $this->read("A,B\r\n1,2\r\n3,4\r\n");

        $this->assertSame([1 => '3', 2 => '4'], $result['rows'][3]);
    }

    public function testBinaryContentIsRejected(): void
    {
        $result = $this->read("PK\x03\x04\0\0\0binary");

        $this->assertFalse($result['ok']);
        $this->assertSame('THE FILE IS NOT A VALID CSV FILE.', $result['error']);
    }

    public function testMaxRowsCapsTheRead(): void
    {
        $result = $this->read("a\nb\nc\nd\ne\n", 3);

        $this->assertSame([1, 2, 3], array_keys($result['rows']));
    }

    public function testMissingFileFailsCleanly(): void
    {
        $result = csv_read_rows(sys_get_temp_dir() . '/missing-' . uniqid() . '.csv');

        $this->assertFalse($result['ok']);
    }
}
