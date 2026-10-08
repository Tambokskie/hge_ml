<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/xls_reader.php';

class XlsReaderTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../fixtures/xls-reader-sample.xls';

    /** @var array<int, string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
    }

    private function writeTemp(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlstest');
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return $path;
    }

    // ---- real Excel-written fixture (large stream path, SST, RK, MULRK, formulas)

    public function testFixtureHeaderAndTextCells(): void
    {
        $result = xls_read_first_sheet(self::FIXTURE);

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['date1904']);
        $this->assertSame(
            [1 => 'CoEIssueDate', 2 => 'LName', 3 => 'FName', 4 => 'MI', 5 => 'MName', 6 => 'ExtName'],
            $result['rows'][1]
        );
        $this->assertSame(
            [1 => '45384', 2 => 'SANDAGAN', 3 => 'NILVEN', 4 => 'A.', 5 => 'AROZADO'],
            $result['rows'][2]
        );
    }

    public function testFixtureKeepsUnderscoreEnyeAndNonLatinText(): void
    {
        $rows = xls_read_first_sheet(self::FIXTURE)['rows'];

        $this->assertSame('46022', $rows[3][1]);
        $this->assertSame('MONTAÑEZ', $rows[3][2]);
        $this->assertSame('_', $rows[3][4]);
        $this->assertSame('_', $rows[3][5]);
        $this->assertSame('JR.', $rows[3][6]);
        $this->assertSame('46302.5625', $rows[4][1]);
        $this->assertSame('李DAYO', $rows[4][2]);
    }

    public function testFixtureNumbersFormulasAndGaps(): void
    {
        $rows = xls_read_first_sheet(self::FIXTURE)['rows'];

        $this->assertSame([1 => '12.5', 2 => '-3', 3 => '123456789', 4 => '0.1', 5 => '1234567.891'], $rows[5]);
        $this->assertSame([1 => '3', 2 => 'ABCD', 3 => 'TRUE'], $rows[6]);
        $this->assertSame([1 => 'after-blank', 5 => 'gap-then-value'], $rows[7]);
    }

    public function testFixtureLargeSharedStringTableAndOnlyFirstSheet(): void
    {
        $rows = xls_read_first_sheet(self::FIXTURE)['rows'];

        $this->assertSame('FILLER SURNAME 0', $rows[10][2]);
        $this->assertSame(409, array_key_last($rows));
        $this->assertSame('Filler Given Name Number 399 Ñ', $rows[409][3]);
        $this->assertStringNotContainsString('NOT THE FIRST SHEET', json_encode($rows, JSON_UNESCAPED_UNICODE));
    }

    // ---- failure paths

    public function testNonExcelFileIsRejectedCleanly(): void
    {
        $result = xls_read_first_sheet($this->writeTemp(str_repeat('this is plain text, not a workbook. ', 40)));

        $this->assertFalse($result['ok']);
        $this->assertSame(XLS_ERR_INVALID, $result['error']);
    }

    public function testEmptyAndMissingFilesAreRejectedCleanly(): void
    {
        $this->assertFalse(xls_read_first_sheet($this->writeTemp(''))['ok']);
        $this->assertFalse(xls_read_first_sheet(sys_get_temp_dir() . '/definitely-missing-' . uniqid() . '.xls')['ok']);
    }

    public function testTruncatedWorkbookIsRejectedCleanly(): void
    {
        $bytes = file_get_contents(self::FIXTURE);
        $result = xls_read_first_sheet($this->writeTemp(substr($bytes, 0, 700)));

        $this->assertFalse($result['ok']);
        $this->assertNotSame('', $result['error']);
    }

    public function testOldBiff5WorkbookIsReportedAsUnsupported(): void
    {
        $result = xls_read_first_sheet($this->writeTemp($this->buildMiniWorkbook(0x0500)));

        $this->assertFalse($result['ok']);
        $this->assertSame(XLS_ERR_UNSUPPORTED, $result['error']);
    }

    public function testPasswordProtectedWorkbookIsReported(): void
    {
        $result = xls_read_first_sheet($this->writeTemp($this->buildMiniWorkbook(0x0600, true)));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('PASSWORD', $result['error']);
    }

    // ---- hand-built OLE2 container: small streams live in the mini stream

    public function testSmallWorkbookStoredInTheMiniStreamIsRead(): void
    {
        $result = xls_read_first_sheet($this->writeTemp($this->buildMiniWorkbook()));

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertSame([1 => 'HELLO', 2 => '45384'], $result['rows'][1]);
        $this->assertSame([1 => 'WORLD'], $result['rows'][2]);
    }

    // ---- builders

    private function record(int $type, string $data): string
    {
        return pack('vv', $type, strlen($data)) . $data;
    }

    private function biffString(string $text): string
    {
        return pack('v', strlen($text)) . "\x00" . $text;
    }

    private function directoryEntry(string $name, int $type, int $start, int $length): string
    {
        $utf16 = mb_convert_encoding($name, 'UTF-16LE', 'UTF-8');

        return str_pad($utf16, 64, "\0") . pack('v', strlen($utf16) + 2) . chr($type) . "\x01"
            . pack('VVV', 0xFFFFFFFF, 0xFFFFFFFF, 0xFFFFFFFF)
            . str_repeat("\0", 16) . pack('V', 0) . str_repeat("\0", 16)
            . pack('VVV', $start, $length, 0);
    }

    /**
     * A tiny but valid .xls: workbook globals + one sheet, wrapped in an OLE2
     * container whose Workbook stream is below the 4096-byte cutoff so it is
     * stored in the mini stream (Excel never writes real files that small, but
     * the container format requires readers to support it).
     *
     * Sheet content: A1 = "HELLO", B1 = 45384, A2 = "WORLD".
     */
    private function buildMiniWorkbook(int $biffVersion = 0x0600, bool $encrypted = false): string
    {
        $bof = static fn (int $kind): string => pack('vv', 0x0809, 16)
            . pack('vvvv', $biffVersion, $kind, 0x0DBB, 0x07CC) . pack('VV', 0, 6);

        $sst = pack('VV', 3, 2) . $this->biffString('HELLO') . $this->biffString('WORLD');

        $globals = function (int $sheetOffset) use ($bof, $sst, $encrypted): string {
            $out = $bof(0x0005);
            if ($encrypted) {
                $out .= $this->record(0x002F, "\0\0\0\0");
            }
            $out .= $this->record(0x0022, pack('v', 0));
            // BOUNDSHEET: u32 sheet offset, visibility, type (0 = worksheet), 1-byte-length name "S"
            $out .= $this->record(0x0085, pack('V', $sheetOffset) . "\x00\x00" . "\x01\x00S");
            $out .= $this->record(0x00FC, $sst);
            $out .= $this->record(0x000A, '');

            return $out;
        };

        $sheetOffset = strlen($globals(0));
        $workbook = $globals($sheetOffset)
            . $bof(0x0010)
            . $this->record(0x00FD, pack('vvvV', 0, 0, 0, 0))
            . $this->record(0x0203, pack('vvv', 0, 1, 0) . pack('e', 45384.0))
            . $this->record(0x00FD, pack('vvvV', 1, 0, 0, 1))
            . $this->record(0x000A, '');

        $size = strlen($workbook);
        $miniSectors = (int) ceil($size / 64);
        $miniStreamSectors = (int) ceil($miniSectors * 64 / 512);
        $miniStream = str_pad($workbook, $miniStreamSectors * 512, "\0");

        $free = 0xFFFFFFFF;
        $end = 0xFFFFFFFE;

        // sector 0 = FAT, 1 = directory, 2 = mini-FAT, 3.. = the mini stream container
        $fat = [0xFFFFFFFD, $end, $end];
        for ($i = 0; $i < $miniStreamSectors; $i++) {
            $fat[] = $i === $miniStreamSectors - 1 ? $end : 3 + $i + 1;
        }
        $fatBytes = '';
        $miniFatBytes = '';
        for ($i = 0; $i < 128; $i++) {
            $fatBytes .= pack('V', $fat[$i] ?? $free);
            $miniFatBytes .= pack('V', $i < $miniSectors ? ($i === $miniSectors - 1 ? $end : $i + 1) : $free);
        }

        $directory = $this->directoryEntry('Root Entry', 5, 3, strlen($miniStream))
            . $this->directoryEntry('Workbook', 2, 0, $size)
            . str_repeat("\0", 256);

        $header = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\0", 16)
            . pack('vvvvv', 0x003E, 3, 0xFFFE, 9, 6) . str_repeat("\0", 6)
            // dir sectors, FAT sectors, first dir, txn, mini cutoff, first mini-FAT, mini-FAT sectors, first DIFAT, DIFAT sectors, DIFAT[0]
            . pack('VVVVVVVVVV', 0, 1, 1, 0, 4096, 2, 1, 0xFFFFFFFE, 0, 0)
            . str_repeat(pack('V', 0xFFFFFFFF), 108);

        return $header . $fatBytes . $directory . $miniFatBytes . $miniStream;
    }
}
