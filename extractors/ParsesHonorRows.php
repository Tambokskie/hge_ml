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

        $name = null;
        $courseStart = null;

        if ($hasGwaColumn && preg_match('/\b\d{1,2}\.\d{2,4}\b/', $trimmed, $gwaMatch, PREG_OFFSET_CAPTURE)) {
            $gwaStart = $gwaMatch[0][1];
            $gwaEnd = $gwaStart + strlen($gwaMatch[0][0]);
            if ($gwaStart < $honorPosition) {
                $name = trim(substr($trimmed, 0, $gwaStart));
                $courseStart = $gwaEnd;
            }
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
