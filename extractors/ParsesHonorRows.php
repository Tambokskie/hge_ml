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
        . '|BSA|BSIT|BSCS|BSBA|BSED|BEED|BSN|BSCE|BSME|BSEE|BSIE|BSHM|BSTM|BSE|BSREM'
        . '|BFA|BTVTE|BTECH|BT|ABM|AB|BS|BA'
        . '|MBA|MPA|MAED|MED|MA|MS'
        . '|PHD'
        . ')(?![A-Za-z0-9])/i';

    /**
     * Sentinel written into a ditto row's latin_honor/graduation_date while
     * parsing, then swapped for the nearest preceding row's real value by
     * resolveDittoMarks() once every line has been read. Never leaks past
     * parseRows() — no PDF text can ever contain a NUL byte, so it can't
     * collide with anything real.
     */
    private const DITTO_MARK = "\x00DITTO\x00";

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

        return $this->resolveDittoMarks($rows);
    }

    /**
     * Many Philippine school lists repeat "- do -" (a ditto mark, short for
     * "ditto") in the Honor Received / Date of Graduation columns instead of
     * retyping the same value on every row of a run. Backfills those columns
     * from the nearest earlier row that had a real value, so a run of ditto
     * rows still ends up with the correct honor/date instead of an empty one.
     *
     * Also covers the case where a row DOES spell out a real honor (a new
     * tier starting) but OCR mangled that one row's ditto date past
     * recognition, leaving extractDate() with nothing to find: once any row
     * in the document is confirmed to use the ditto convention, a plain
     * missing date is far more likely to be unreadable "-do-" than a
     * genuinely blank cell, so it inherits too.
     */
    private function resolveDittoMarks(array $rows): array
    {
        $usesDittoConvention = false;
        foreach ($rows as $row) {
            if ($row['latin_honor'] === self::DITTO_MARK) {
                $usesDittoConvention = true;
                break;
            }
        }

        $lastHonor = '';
        $lastDate = null;

        foreach ($rows as &$row) {
            if ($row['latin_honor'] === self::DITTO_MARK) {
                $row['latin_honor'] = $lastHonor;
            } else {
                $lastHonor = $row['latin_honor'];
            }

            if ($row['graduation_date'] === self::DITTO_MARK) {
                $row['graduation_date'] = $lastDate;
            } elseif ($row['graduation_date'] === null && $usesDittoConvention) {
                $row['graduation_date'] = $lastDate;
            } else {
                $lastDate = $row['graduation_date'];
            }
        }
        unset($row);

        return $rows;
    }

    private function parseLine(string $line, bool $hasGwaColumn): ?array
    {
        $trimmed = trim($line);

        $honorMatch = $this->matchHonor($trimmed);
        if ($honorMatch === null) {
            return $this->parseDittoLine($trimmed, $line);
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
            $course = $this->sanitizeExtractedText(substr($trimmed, $courseStart, $honorPosition - $courseStart));
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
        $course = $this->sanitizeExtractedText(substr($trimmed, $bachelorPosition, $gapStart - $bachelorPosition));

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
     * Handles a row whose Honor Received cell is a ditto mark rather than a
     * spelled-out honor — matchHonor() already failed, so there's no honor
     * phrase to anchor name/course extraction on.
     *
     * Real-world OCR of a ditto mark is wildly inconsistent ("-do-", "do",
     * "odo", or outright unrecognizable noise like "cae" or "205" — Tesseract
     * struggles badly with the small italicized "do" these lists use), so
     * matching the ditto text itself isn't reliable enough to build on.
     * Anchoring on the course keyword instead is: it's the last column that
     * OCRs reliably, and everything after it — however garbled — is either
     * a ditto mark or nothing at all, never more than a couple of short
     * tokens. That length check is what keeps this from misfiring on a
     * stray line that merely mentions a course abbreviation in passing.
     */
    private function parseDittoLine(string $trimmed, string $rawLine): ?array
    {
        if (!preg_match_all(self::COURSE_KEYWORD_PATTERN, $trimmed, $courseMatches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        [$courseText, $coursePosition] = end($courseMatches[0]);

        $name = $this->cleanGraduateName(substr($trimmed, 0, $coursePosition));
        if ($name === '') {
            return null;
        }

        // Extend past a hyphenated suffix the keyword list doesn't enumerate
        // (e.g. "BSBA-MM", "BSBA-HRM") so it isn't left dangling in the tail.
        $courseEnd = $coursePosition + strlen($courseText);
        if (preg_match('/^-[A-Za-z]{2,6}(?![A-Za-z0-9])/', substr($trimmed, $courseEnd), $suffixMatch)) {
            $courseEnd += strlen($suffixMatch[0]);
        }

        $tail = trim(substr($trimmed, $courseEnd));
        if ($tail !== '' && (mb_strlen($tail) > 40 || preg_match_all('/\S+/', $tail) > 6)) {
            return null;
        }

        $course = $this->sanitizeExtractedText(substr($trimmed, $coursePosition, $courseEnd - $coursePosition));

        return [
            'graduate_name' => $name,
            'course' => $course,
            'latin_honor' => self::DITTO_MARK,
            'graduation_date' => $this->extractDate($tail) ?? self::DITTO_MARK,
        ];
    }

    /**
     * Strips OCR/table artifacts that land in the name column but aren't
     * part of the name: a leading row/sequence number plus whatever
     * non-letter junk OCR glued to it (straight or curly quotes, brackets,
     * pipes, stray symbols — a name always starts with a letter, so
     * anything before the first letter is discarded), and any standalone
     * row/roll/GWA number elsewhere in the column (via sanitizeExtractedText).
     * Never touches interior punctuation, so "O'Brien-Smith" is untouched.
     */
    private function cleanGraduateName(string $name): string
    {
        $name = preg_replace('/^[^\p{L}]+/u', '', $name) ?? $name;

        return $this->sanitizeExtractedText($name);
    }

    /**
     * Shared cleanup for the name and course columns: drops table-formatting
     * symbols that occasionally survive OCR ('{', '}', '[', ']', '|', '~',
     * curly/smart quotes) and any standalone number — a row/roll number or
     * a GWA figure like "1.75" — since none of that is ever part of a
     * graduate's name or course. Only whole, whitespace-delimited numeric
     * tokens are removed, so genuinely alphanumeric OCR garbage (e.g.
     * "1G5") is left alone rather than guessed at. Straight quotes/
     * apostrophes are deliberately left untouched — those can be genuine
     * name punctuation (e.g. "O'Brien-Smith").
     */
    private function sanitizeExtractedText(string $text): string
    {
        $text = preg_replace('/[{}\[\]|~‘’“”]/u', '', $text) ?? $text;
        $text = preg_replace('/(?<!\S)\d+\.\d+(?!\S)/u', '', $text) ?? $text;
        $text = preg_replace('/(?<!\S)\d+(?!\S)/u', '', $text) ?? $text;
        $text = preg_replace('/\s{2,}/', ' ', $text) ?? $text;

        return trim($text);
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
            '/\b([A-Za-z]{3,9})\.?\s+(\d{1,2}),?\s*(\d{4})\b/',
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
