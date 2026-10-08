<?php

/**
 * Minimal reader for legacy Excel 97-2003 (.xls, BIFF8 inside an OLE2
 * compound file) workbooks — the format this app's .xlsx reader can't open.
 * Hand-rolled for the same reason as xlsx_reader.php: there is no Composer
 * spreadsheet library or ext-zip available. It reads only the FIRST worksheet
 * and only cell VALUES (text, numbers, formula results); styling is ignored,
 * so date cells come back as their raw Excel serial number, exactly like
 * xlsx_read_first_sheet() — callers convert them.
 *
 * Returns the same shape as xlsx_read_first_sheet():
 *   ['ok' => true, 'rows' => [row][col] => string (both 1-indexed), 'date1904' => bool]
 * or ['ok' => false, 'error' => 'ALL-CAPS MESSAGE'].
 */

const XLS_ERR_INVALID = 'THE FILE IS NOT A VALID EXCEL (.XLS) FILE.';
const XLS_ERR_UNSUPPORTED = 'THIS EXCEL FILE FORMAT IS NOT SUPPORTED. PLEASE SAVE IT AS .XLSX OR .CSV AND UPLOAD THAT INSTEAD.';

function xls_u16(string $data, int $offset): int
{
    return ord($data[$offset] ?? "\0") | (ord($data[$offset + 1] ?? "\0") << 8);
}

function xls_u32(string $data, int $offset): int
{
    return ord($data[$offset] ?? "\0") | (ord($data[$offset + 1] ?? "\0") << 8) | (ord($data[$offset + 2] ?? "\0") << 16) | (ord($data[$offset + 3] ?? "\0") << 24);
}

/**
 * Follows a sector chain through a FAT/mini-FAT and returns the sector ids.
 *
 * @param array<int, int> $fat
 * @return array<int, int>
 */
function xls_sector_chain(array $fat, int $start): array
{
    $chain = [];
    $seen = [];
    $sector = $start;
    while ($sector < 0xFFFFFFFA && isset($fat[$sector]) && !isset($seen[$sector])) {
        $seen[$sector] = true;
        $chain[] = $sector;
        $sector = $fat[$sector];
    }

    return $chain;
}

/**
 * Extracts the "Workbook" (or legacy "Book") stream from an OLE2 compound
 * file, or null when the container is malformed / the stream is missing.
 */
function xls_read_workbook_stream(string $file): ?string
{
    if (strlen($file) < 512 || substr($file, 0, 8) !== "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
        return null;
    }

    $sectorShift = xls_u16($file, 0x1E);
    $miniShift = xls_u16($file, 0x20);
    if ($sectorShift < 7 || $sectorShift > 16 || $miniShift > $sectorShift) {
        return null;
    }
    $sectorSize = 1 << $sectorShift;
    $miniSize = 1 << $miniShift;
    $fileLen = strlen($file);

    $readSector = static function (int $sid) use ($file, $sectorSize, $fileLen): string {
        $offset = ($sid + 1) * $sectorSize;
        if ($offset >= $fileLen) {
            return '';
        }

        return str_pad(substr($file, $offset, $sectorSize), $sectorSize, "\0");
    };

    $numFatSectors = xls_u32($file, 0x2C);
    $firstDirSector = xls_u32($file, 0x30);
    $miniCutoff = xls_u32($file, 0x38);
    $firstMiniFatSector = xls_u32($file, 0x3C);
    $firstDifatSector = xls_u32($file, 0x44);
    $numDifatSectors = xls_u32($file, 0x48);

    $fatSectorIds = [];
    for ($i = 0; $i < 109 && count($fatSectorIds) < $numFatSectors; $i++) {
        $sid = xls_u32($file, 0x4C + $i * 4);
        if ($sid < 0xFFFFFFFA) {
            $fatSectorIds[] = $sid;
        }
    }
    $difat = $firstDifatSector;
    for ($d = 0; $d < $numDifatSectors && $difat < 0xFFFFFFFA; $d++) {
        $data = $readSector($difat);
        $perSector = intdiv($sectorSize, 4) - 1;
        for ($i = 0; $i < $perSector && count($fatSectorIds) < $numFatSectors; $i++) {
            $sid = xls_u32($data, $i * 4);
            if ($sid < 0xFFFFFFFA) {
                $fatSectorIds[] = $sid;
            }
        }
        $difat = xls_u32($data, $perSector * 4);
    }

    $fat = [];
    foreach ($fatSectorIds as $sid) {
        $data = $readSector($sid);
        for ($i = 0; $i < $sectorSize; $i += 4) {
            $fat[] = xls_u32($data, $i);
        }
    }
    if ($fat === []) {
        return null;
    }

    $readChain = static function (array $table, int $start, callable $reader): string {
        $out = '';
        foreach (xls_sector_chain($table, $start) as $sid) {
            $out .= $reader($sid);
        }

        return $out;
    };

    $directory = $readChain($fat, $firstDirSector, $readSector);
    if ($directory === '') {
        return null;
    }

    $rootStart = null;
    $rootSize = 0;
    $stream = null;
    for ($offset = 0; $offset + 128 <= strlen($directory); $offset += 128) {
        $type = ord($directory[$offset + 66]);
        $nameLen = xls_u16($directory, $offset + 64);
        $name = $nameLen >= 2 ? mb_convert_encoding(substr($directory, $offset, $nameLen - 2), 'UTF-8', 'UTF-16LE') : '';
        $start = xls_u32($directory, $offset + 116);
        $size = xls_u32($directory, $offset + 120);

        if ($type === 5) {
            $rootStart = $start;
            $rootSize = $size;
        } elseif ($type === 2 && ($name === 'Workbook' || ($name === 'Book' && $stream === null))) {
            $stream = ['start' => $start, 'size' => $size, 'name' => $name];
        }
    }

    if ($stream === null) {
        return null;
    }

    if ($stream['size'] >= $miniCutoff) {
        return substr($readChain($fat, $stream['start'], $readSector), 0, $stream['size']);
    }

    // Small streams live in the mini stream (64-byte mini sectors).
    if ($rootStart === null) {
        return null;
    }
    $miniStream = substr($readChain($fat, $rootStart, $readSector), 0, $rootSize);
    $miniFatData = $readChain($fat, $firstMiniFatSector, $readSector);
    $miniFat = [];
    for ($i = 0; $i + 4 <= strlen($miniFatData); $i += 4) {
        $miniFat[] = xls_u32($miniFatData, $i);
    }
    $out = '';
    foreach (xls_sector_chain($miniFat, $stream['start']) as $msid) {
        $out .= substr($miniStream, $msid * $miniSize, $miniSize);
    }

    return substr($out, 0, $stream['size']);
}

/**
 * Reads the next BIFF record at $pos (advancing it). CONTINUE (0x003C)
 * records are returned as ordinary records — callers that need them (the SST)
 * stitch them on themselves so they can track where each one began.
 *
 * @return array{0: int, 1: string}|null  [type, data]
 */
function xls_next_record(string $stream, int &$pos): ?array
{
    $len = strlen($stream);
    if ($pos + 4 > $len) {
        return null;
    }
    $type = xls_u16($stream, $pos);
    $size = xls_u16($stream, $pos + 2);
    $data = substr($stream, $pos + 4, $size);
    $pos += 4 + $size;

    return [$type, $data];
}

/**
 * @return array<int, string>  shared strings, in SST order
 */
function xls_parse_sst(string $data, array $boundaries): array
{
    $total = strlen($data);
    if ($total < 8) {
        return [];
    }
    $count = xls_u32($data, 4);
    $pos = 8;
    $boundarySet = array_flip($boundaries);
    $strings = [];

    // Reads $chars characters of string data starting at $pos, honoring the
    // extra "wide flag" byte Excel inserts at the start of every CONTINUE
    // record that interrupts character data.
    $readChars = static function (int $chars, bool &$wide) use ($data, &$pos, $total, $boundarySet): string {
        $out = '';
        while ($chars > 0 && $pos < $total) {
            if (isset($boundarySet[$pos])) {
                $wide = (ord($data[$pos]) & 0x01) === 1;
                $pos++;
            }
            $next = $total;
            foreach ($boundarySet as $b => $_) {
                if ($b > $pos && $b < $next) {
                    $next = $b;
                }
            }
            $bytesPerChar = $wide ? 2 : 1;
            $take = min($chars, intdiv($next - $pos, $bytesPerChar));
            if ($take <= 0) {
                break;
            }
            $chunk = substr($data, $pos, $take * $bytesPerChar);
            $out .= $wide
                ? mb_convert_encoding($chunk, 'UTF-8', 'UTF-16LE')
                : mb_convert_encoding($chunk, 'UTF-8', 'Windows-1252');
            $pos += $take * $bytesPerChar;
            $chars -= $take;
        }

        return $out;
    };

    for ($i = 0; $i < $count && $pos + 3 <= $total; $i++) {
        $cch = xls_u16($data, $pos);
        $flags = ord($data[$pos + 2]);
        $pos += 3;
        $wide = ($flags & 0x01) === 1;
        $hasExt = ($flags & 0x04) !== 0;
        $hasRuns = ($flags & 0x08) !== 0;
        $runs = 0;
        $extLen = 0;
        if ($hasRuns) {
            $runs = xls_u16($data, $pos);
            $pos += 2;
        }
        if ($hasExt) {
            $extLen = xls_u32($data, $pos);
            $pos += 4;
        }

        $strings[] = $readChars($cch, $wide);
        $pos += $runs * 4 + $extLen;
    }

    return $strings;
}

/** Decodes an RK-encoded number. */
function xls_decode_rk(int $rk): float
{
    $isInt = ($rk & 0x02) !== 0;
    $div100 = ($rk & 0x01) !== 0;
    if ($isInt) {
        $value = $rk >> 2;
        if ($value & 0x20000000) {
            $value -= 0x40000000;
        }
        $number = (float) $value;
    } else {
        $bytes = pack('V', 0) . pack('V', $rk & 0xFFFFFFFC);
        $number = unpack('e', $bytes)[1];
    }

    return $div100 ? $number / 100 : $number;
}

function xls_number_to_string(float $number): string
{
    if (is_nan($number) || is_infinite($number)) {
        return '';
    }
    if ($number == floor($number) && abs($number) < 1e15) {
        return (string) (int) $number;
    }

    return rtrim(rtrim(sprintf('%.15G', $number), '0'), '.');
}

/** Reads a BIFF8 unicode string with a 2-byte length (LABEL/STRING records). */
function xls_read_biff_string(string $data, int $offset): string
{
    if (strlen($data) < $offset + 3) {
        return '';
    }
    $cch = xls_u16($data, $offset);
    $flags = ord($data[$offset + 2]);
    $offset += 3;
    if ($flags & 0x08) {
        $offset += 2;
    }
    if ($flags & 0x04) {
        $offset += 4;
    }
    if ($flags & 0x01) {
        return mb_convert_encoding(substr($data, $offset, $cch * 2), 'UTF-8', 'UTF-16LE');
    }

    return mb_convert_encoding(substr($data, $offset, $cch), 'UTF-8', 'Windows-1252');
}

function xls_read_first_sheet(string $filePath, int $maxRows = 65536): array
{
    $file = @file_get_contents($filePath);
    if ($file === false || $file === '') {
        return ['ok' => false, 'error' => 'THE FILE COULD NOT BE READ.'];
    }

    $stream = xls_read_workbook_stream($file);
    if ($stream === null || strlen($stream) < 8) {
        return ['ok' => false, 'error' => XLS_ERR_INVALID];
    }

    $pos = 0;
    $record = xls_next_record($stream, $pos);
    if ($record === null || $record[0] !== 0x0809) {
        return ['ok' => false, 'error' => XLS_ERR_INVALID];
    }
    if (strlen($record[1]) < 4 || xls_u16($record[1], 0) !== 0x0600) {
        return ['ok' => false, 'error' => XLS_ERR_UNSUPPORTED];
    }

    // ---- workbook globals: first worksheet's offset, shared strings, date base
    $sheetOffset = null;
    $sstData = null;
    $sstBoundaries = [];
    $date1904 = false;
    $depth = 1;
    $lastWasSst = false;

    while (($record = xls_next_record($stream, $pos)) !== null) {
        [$type, $data] = $record;

        if ($type === 0x0809) {
            $depth++;
        } elseif ($type === 0x000A) {
            $depth--;
            if ($depth <= 0) {
                break;
            }
        } elseif ($type === 0x002F) {
            return ['ok' => false, 'error' => 'THE EXCEL FILE IS PASSWORD-PROTECTED. REMOVE THE PASSWORD AND TRY AGAIN.'];
        } elseif ($type === 0x0022 && strlen($data) >= 2) {
            $date1904 = xls_u16($data, 0) === 1;
        } elseif ($type === 0x0085 && $sheetOffset === null && strlen($data) >= 8) {
            // BOUNDSHEET: u32 stream offset, u8 visibility, u8 sheet type (0 = worksheet)
            if (ord($data[5]) === 0) {
                $sheetOffset = xls_u32($data, 0);
            }
        } elseif ($type === 0x00FC) {
            $sstData = $data;
            $sstBoundaries = [];
            $lastWasSst = true;
            continue;
        } elseif ($type === 0x003C && $lastWasSst && $sstData !== null) {
            $sstBoundaries[] = strlen($sstData);
            $sstData .= $data;
            continue;
        }
        $lastWasSst = false;
    }

    if ($sheetOffset === null || $sheetOffset >= strlen($stream)) {
        return ['ok' => false, 'error' => XLS_ERR_INVALID];
    }

    $sst = $sstData !== null ? xls_parse_sst($sstData, $sstBoundaries) : [];

    // ---- first worksheet substream
    $rows = [];
    $pos = $sheetOffset;
    $depth = 0;
    $pendingFormulaString = null;

    $setCell = static function (int $row, int $col, string $value) use (&$rows, $maxRows): void {
        if ($row >= $maxRows) {
            return;
        }
        $rows[$row + 1][$col + 1] = $value;
    };

    while (($record = xls_next_record($stream, $pos)) !== null) {
        [$type, $data] = $record;
        $len = strlen($data);

        if ($type === 0x0809) {
            $depth++;
            continue;
        }
        if ($type === 0x000A) {
            $depth--;
            if ($depth <= 0) {
                break;
            }
            continue;
        }
        if ($depth !== 1) {
            continue;
        }

        switch ($type) {
            case 0x00FD: // LABELSST
                if ($len >= 10) {
                    $setCell(xls_u16($data, 0), xls_u16($data, 2), $sst[xls_u32($data, 6)] ?? '');
                }
                break;
            case 0x0204: // LABEL
                if ($len >= 8) {
                    $setCell(xls_u16($data, 0), xls_u16($data, 2), xls_read_biff_string($data, 6));
                }
                break;
            case 0x0203: // NUMBER
                if ($len >= 14) {
                    $setCell(xls_u16($data, 0), xls_u16($data, 2), xls_number_to_string(unpack('e', substr($data, 6, 8))[1]));
                }
                break;
            case 0x027E: // RK
                if ($len >= 10) {
                    $setCell(xls_u16($data, 0), xls_u16($data, 2), xls_number_to_string(xls_decode_rk(xls_u32($data, 6))));
                }
                break;
            case 0x00BD: // MULRK
                if ($len >= 6) {
                    $row = xls_u16($data, 0);
                    $col = xls_u16($data, 2);
                    for ($offset = 4; $offset + 6 <= $len - 2; $offset += 6, $col++) {
                        $setCell($row, $col, xls_number_to_string(xls_decode_rk(xls_u32($data, $offset + 2))));
                    }
                }
                break;
            case 0x0006: // FORMULA
                if ($len >= 14) {
                    $row = xls_u16($data, 0);
                    $col = xls_u16($data, 2);
                    if (xls_u16($data, 12) === 0xFFFF) {
                        $kind = ord($data[6]);
                        if ($kind === 0) {
                            $pendingFormulaString = [$row, $col]; // text arrives in the next STRING record
                        } elseif ($kind === 1) {
                            $setCell($row, $col, ord($data[8]) ? 'TRUE' : 'FALSE');
                        }
                    } else {
                        $setCell($row, $col, xls_number_to_string(unpack('e', substr($data, 6, 8))[1]));
                    }
                }
                break;
            case 0x0207: // STRING (result of the preceding FORMULA)
                if ($pendingFormulaString !== null) {
                    $setCell($pendingFormulaString[0], $pendingFormulaString[1], xls_read_biff_string($data, 0));
                    $pendingFormulaString = null;
                }
                break;
            case 0x0205: // BOOLERR
                if ($len >= 8 && ord($data[7]) === 0) {
                    $setCell(xls_u16($data, 0), xls_u16($data, 2), ord($data[6]) ? 'TRUE' : 'FALSE');
                }
                break;
        }
    }

    ksort($rows);
    foreach ($rows as &$cols) {
        ksort($cols);
    }
    unset($cols);

    return ['ok' => true, 'rows' => $rows, 'date1904' => $date1904];
}
