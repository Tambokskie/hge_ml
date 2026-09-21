<?php

trait ParsesHonorRows
{
    /**
     * Matches the token that starts a degree/course column, however that
     * school's list phrases it — full names ("BACHELOR OF SCIENCE IN..."),
     * common Philippine abbreviations ("BS", "BSA", "BSIT", ...), and
     * period-punctuated forms ("B.S.", "M.A.", "PH.D."). The course text
     * itself always runs from wherever this matches to the Latin Honor
     * match, so extending this list only changes which token a line
     * anchors on, never how the anchor is used. Not exhaustive — add more
     * abbreviations here as new school formats turn up.
     *
     * Lookaround (not \b) is deliberate: \b fails to terminate a match
     * that ends on a period (e.g. "B.S. ACCOUNTANCY" — "." and the space
     * after it are both non-word characters, so no \b exists between
     * them), silently rejecting the exact abbreviated forms this list
     * exists to support.
     */
    private const COURSE_KEYWORD_PATTERN = '/(?<![A-Za-z0-9])('
        . 'BACHELOR|MASTER|DOCTOR(?:ATE)?|DIPLOMA|ASSOCIATE|CERTIFICATE|TECHNICAL|VOCATIONAL'
        . '|B\.\s*S\.?|B\.\s*A\.?|M\.\s*A\.?|M\.\s*S\.?|PH\.\s*D\.?'
        . '|BSA|BSIT|BSCS|BSBA|BSED|BEED|BSN|BSCE|BSME|BSEE|BSIE|BSHM|BSTM|BSE'
        . '|BFA|BTVTE|BTECH|BT|ABM|AB|BS|BA'
        . '|MBA|MPA|MAED|MED|MA|MS'
        . '|PHD'
        . ')(?![A-Za-z0-9])/i';

    protected function parseRows(string $text): array
    {
        $hasGwaColumn = (bool) preg_match('/\bGWA\b|\bGPA\b/i', $text);

        $rows = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            $row = $this->parseLine($line, $hasGwaColumn);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function parseLine(string $line, bool $hasGwaColumn): ?array
    {
        $trimmed = trim($line);

        $honorMatch = $this->matchHonor($trimmed);
        if ($honorMatch === null) {
            return null;
        }
        $honor = $honorMatch['honor'];
        $honorPosition = $honorMatch['position'];

        // A degree abbreviation is short enough to coincidentally collide
        // with something inside the name (e.g. "MA." as in "JUAN MA. DELA
        // CRUZ", a common Filipino name element, not "Master of Arts").
        // The real course token is always the one immediately preceding
        // the Latin Honor, so take the rightmost match before it rather
        // than the first one in the line.
        $bachelorPosition = null;
        if (preg_match_all(self::COURSE_KEYWORD_PATTERN, $trimmed, $courseMatches, PREG_OFFSET_CAPTURE)) {
            foreach ($courseMatches[0] as $match) {
                if ($match[1] < $honorPosition) {
                    $bachelorPosition = $match[1];
                }
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

        // Fallback boundary for when the degree keyword itself is too
        // OCR-garbled to recognize (e.g. "BACHELOR" misread as "PASTEL"):
        // a standalone "|" is almost always a mis-OCR'd table border, never
        // part of a real name, so it still marks the name/course split even
        // when the course text after it is unreadable garbage.
        $pipeStart = null;
        $pipeMatchLength = 0;
        if (preg_match('/\s\|\s/', $trimmed, $pipeMatch, PREG_OFFSET_CAPTURE)) {
            $candidate = $pipeMatch[0][1];
            if ($candidate < $honorPosition) {
                $pipeStart = $candidate;
                $pipeMatchLength = strlen($pipeMatch[0][0]);
            }
        }

        if ($bachelorPosition !== null) {
            $nameEnd = ($gwaStart !== null && $gwaStart < $bachelorPosition) ? $gwaStart : $bachelorPosition;
            $name = trim(substr($trimmed, 0, $nameEnd));
            $courseStart = $bachelorPosition;
        } elseif ($gwaStart !== null) {
            $name = trim(substr($trimmed, 0, $gwaStart));
            $courseStart = $gwaStart + strlen($gwaMatch[0][0]);
        } elseif ($pipeStart !== null) {
            $name = trim(substr($trimmed, 0, $pipeStart));
            $courseStart = $pipeStart + $pipeMatchLength;
        }

        if ($name === null || $name === '') {
            $columns = preg_split('/\s{2,}/', $trimmed);
            $name = trim($columns[0] ?? '');
            $courseStart = strlen($name);
        }

        $name = $this->cleanGraduateName($name);

        if ($name === '') {
            return null;
        }

        $course = '';
        if ($courseStart !== null && $courseStart < $honorPosition) {
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

        $name = $this->cleanGraduateName(substr($trimmed, $gapStart, $nameEnd - $gapStart));
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

    /**
     * Strips OCR/table artifacts that land in the name column but aren't
     * part of the name: a leading row/sequence number plus whatever
     * non-letter junk OCR glued to it (straight or curly quotes, brackets,
     * pipes, stray symbols — a name always starts with a letter, so
     * anything before the first letter is discarded), and a trailing
     * standalone number (e.g. a roll/ID number merged onto the end of the
     * column by a missing space). Never touches interior punctuation, so
     * "O'Brien-Smith" is untouched.
     */
    private function cleanGraduateName(string $name): string
    {
        $name = preg_replace('/^[^\p{L}]+/u', '', $name) ?? $name;
        $name = preg_replace('/\s+\d+\s*$/u', '', $name) ?? $name;

        return trim($name);
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
