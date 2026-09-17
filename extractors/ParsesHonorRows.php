<?php

trait ParsesHonorRows
{
    protected function parseRows(string $text): array
    {
        $hasGwaColumn = (bool) preg_match('/\bGWA\b|\bGPA\b/i', $text);
        $hasCourseColumn = (bool) preg_match('/\bDEGREE\b|\bCOURSE\b|\bPROGRAM\b/i', $text);

        $rows = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            $row = $this->parseLine($line, $hasGwaColumn, $hasCourseColumn);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function parseLine(string $line, bool $hasGwaColumn, bool $hasCourseColumn): ?array
    {
        $trimmed = trim($line);

        $honorMatch = $this->matchHonor($trimmed);
        if ($honorMatch === null) {
            return null;
        }
        $honor = $honorMatch['honor'];
        $honorPosition = $honorMatch['position'];

        $bachelorPosition = null;
        if ($hasCourseColumn && preg_match('/BACHELOR\b/i', $trimmed, $bachelorMatch, PREG_OFFSET_CAPTURE)) {
            $pos = $bachelorMatch[0][1];
            if ($pos < $honorPosition) {
                $bachelorPosition = $pos;
            }
        }

        if ($bachelorPosition !== null && trim(substr($trimmed, 0, $bachelorPosition)) === '') {
            return $this->parseCourseFirstLine($trimmed, $line, $bachelorPosition, $honorPosition, $honor);
        }

        $name = null;
        $courseStart = null;

        $gwaStart = null;
        if ($hasGwaColumn && preg_match('/\b\d{1,2}\.\d{2,4}/', $trimmed, $gwaMatch, PREG_OFFSET_CAPTURE)) {
            $candidate = $gwaMatch[0][1];
            if ($candidate < $honorPosition) {
                $gwaStart = $candidate;
            }
        }

        if ($bachelorPosition !== null) {
            $nameEnd = ($gwaStart !== null && $gwaStart < $bachelorPosition) ? $gwaStart : $bachelorPosition;
            $name = trim(substr($trimmed, 0, $nameEnd));
            $courseStart = $bachelorPosition;
        } elseif ($gwaStart !== null) {
            $name = trim(substr($trimmed, 0, $gwaStart));
            $courseStart = $gwaStart + strlen($gwaMatch[0][0]);
        }

        if ($name === null || $name === '') {
            $columns = preg_split('/\s{2,}/', $trimmed);
            $name = trim($columns[0] ?? '');
            $courseStart = strlen($name);
        }

        if ($name === '') {
            return null;
        }

        $course = '';
        if ($hasCourseColumn && $courseStart !== null && $courseStart < $honorPosition) {
            $course = trim(substr($trimmed, $courseStart, $honorPosition - $courseStart));
        }

        return [
            'graduate_name' => $name,
            'course' => $course,
            'latin_honor' => $honor,
            'graduation_date' => $this->extractDate($line),
        ];
    }

    private function parseCourseFirstLine(string $trimmed, string $rawLine, int $bachelorPosition, int $honorPosition, string $honor): ?array
    {
        $afterBachelor = substr($trimmed, $bachelorPosition, $honorPosition - $bachelorPosition);

        if (!preg_match('/\s{2,}/', $afterBachelor, $gapMatch, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $gapStart = $bachelorPosition + $gapMatch[0][1];
        $course = trim(substr($trimmed, $bachelorPosition, $gapStart - $bachelorPosition));

        $nameEnd = $honorPosition;
        if (preg_match('/\b(\d{1,2}\s+[A-Za-z]{3,9}\.?\s+\d{4}|[A-Za-z]{3,9}\.?\s+\d{1,2},?\s+\d{4}|\d{4}-\d{2}-\d{2}|\d{1,2}\/\d{1,2}\/\d{4})\b/', substr($trimmed, $gapStart, $honorPosition - $gapStart), $dateMatch, PREG_OFFSET_CAPTURE)) {
            $nameEnd = $gapStart + $dateMatch[0][1];
        }

        $name = trim(substr($trimmed, $gapStart, $nameEnd - $gapStart));
        if ($name === '') {
            return null;
        }

        return [
            'graduate_name' => $name,
            'course' => $course,
            'latin_honor' => $honor,
            'graduation_date' => $this->extractDate($rawLine),
        ];
    }

    private function matchHonor(string $text): ?array
    {
        $patterns = [
            'SUMMA CUM LAUDE' => '/SUMMA\s*CUM\s*LAUDE/i',
            'MAGNA CUM LAUDE' => '/MAGNA\s*CUM\s*LAUDE/i',
            'CUM LAUDE' => '/CUM\s*LAUDE/i',
        ];

        foreach ($patterns as $honor => $pattern) {
            if (preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE)) {
                return ['honor' => $honor, 'position' => $m[0][1]];
            }
        }

        return null;
    }

    private function extractDate(string $text): ?string
    {
        $patterns = [
            '/\b(\d{1,2})\s+([A-Za-z]{3,9})\.?\s+(\d{4})\b/',
            '/\b([A-Za-z]{3,9})\.?\s+(\d{1,2}),?\s+(\d{4})\b/',
            '/\b(\d{4})-(\d{2})-(\d{2})\b/',
            '/\b(\d{1,2})\/(\d{1,2})\/(\d{4})\b/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                $ts = strtotime($m[0]);
                if ($ts !== false) {
                    return date('Y-m-d', $ts);
                }
            }
        }

        return null;
    }
}
