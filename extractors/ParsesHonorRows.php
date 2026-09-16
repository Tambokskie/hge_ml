<?php

trait ParsesHonorRows
{
    protected function parseRows(string $text): array
    {
        $rows = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            $row = $this->parseLine($line);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function parseLine(string $line): ?array
    {
        $honor = $this->normalizeLatinHonor($line);
        if ($honor === null) {
            return null;
        }

        $columns = preg_split('/\s{2,}/', trim($line));
        $name = trim($columns[0] ?? '');
        if ($name === '') {
            return null;
        }

        return [
            'graduate_name' => $name,
            'latin_honor' => $honor,
            'graduation_date' => $this->extractDate($line),
        ];
    }

    private function normalizeLatinHonor(string $text): ?string
    {
        if (preg_match('/SUMMA\s*CUM\s*LAUDE/i', $text)) {
            return 'SUMMA CUM LAUDE';
        }
        if (preg_match('/MAGNA\s*CUM\s*LAUDE/i', $text)) {
            return 'MAGNA CUM LAUDE';
        }
        if (preg_match('/CUM\s*LAUDE/i', $text)) {
            return 'CUM LAUDE';
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
