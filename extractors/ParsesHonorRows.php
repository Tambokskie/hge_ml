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
     *
     * "ACHELOR" alongside "BACHELOR" covers a common OCR failure on
     * scanned lists: a decorative/serif capital "B" at the start of a
     * table cell is dropped entirely rather than misread as another
     * letter, leaving "achelor of Science..." with nothing to anchor on.
     */
    private const COURSE_KEYWORDS = [
        'BACHELOR', 'ACHELOR', 'MASTER', 'DOCTOR(?:ATE)?', 'DIPLOMA', 'ASSOCIATE', 'CERTIFICATE', 'TECHNICAL', 'VOCATIONAL',
        'B\.\s*S\.?', 'B\.\s*A\.?', 'M\.\s*A\.?', 'M\.\s*S\.?', 'PH\.\s*D\.?',
        'BSA', 'BSAB', 'BSIT', 'BSCS', 'BSBA', 'BSED', 'BEED', 'BSN', 'BSCE', 'BSME', 'BSEE', 'BSIE', 'BSHM', 'BSTM', 'BSE', 'BSREM', 'BPED',
        'BFA', 'BTVTE', 'BTECH', 'BT', 'ABM', 'AB', 'BS', 'BA',
        'MBA', 'MPA', 'MAED', 'MED', 'MA', 'MS',
        'PHD',
    ];

    /**
     * Admin-managed additions from the OCR Course Keywords settings page
     * (see includes/ocr_keywords.php), set via setExtraCourseKeywords()
     * before parseRows() runs. Each is preg_quote()'d before it ever
     * reaches courseKeywordPattern() — admin input is plain text, never a
     * regex fragment like the hardcoded tokens above.
     */
    private array $extraCourseKeywords = [];

    private ?string $compiledCourseKeywordPattern = null;

    public function setExtraCourseKeywords(array $keywords): void
    {
        $this->extraCourseKeywords = array_values(array_unique(array_filter(array_map(
            static fn ($keyword): string => trim(mb_strtoupper((string) $keyword)),
            $keywords
        ))));
        $this->compiledCourseKeywordPattern = null;
    }

    private function courseKeywordPattern(): string
    {
        if ($this->compiledCourseKeywordPattern !== null) {
            return $this->compiledCourseKeywordPattern;
        }

        $tokens = self::COURSE_KEYWORDS;
        foreach ($this->extraCourseKeywords as $keyword) {
            $tokens[] = preg_quote($keyword, '/');
        }

        $this->compiledCourseKeywordPattern = '/(?<![A-Za-z0-9])(' . implode('|', $tokens) . ')(?![A-Za-z0-9])/i';

        return $this->compiledCourseKeywordPattern;
    }

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
        $lines = $this->mergeOrphanedHonorFragmentLines(preg_split('/\r\n|\r|\n/', $text));
        $lines = $this->mergeSplitHonorLines($lines);

        $rows = [];
        foreach ($lines as $line) {
            $row = $this->parseLine($line, $hasGwaColumn);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $this->resolveDittoMarks($rows);
    }

    /**
     * When a table cell's course text wraps onto 2-3 lines, OCR sometimes
     * splits the *honor phrase itself* across the line boundary — e.g. a
     * row's line ends "... Magna Cum" and the honor concludes on the next
     * line as "Accountancy Laude July 04, 2025", or in the worst case the
     * whole honor phrase lands alone on its own orphaned line while the row
     * number/name line has no honor text at all. Neither line matches
     * matchHonor() on its own, so without this the row is silently dropped
     * (or, worse, misidentified as a ditto row and given a guessed honor).
     *
     * Joins such a line with the next one whenever doing so completes a
     * recognizable honor phrase AND the honor phrase starts within the
     * first ~20 characters of the next line — that second condition is
     * what keeps this from ever merging a genuine ditto/incomplete row into
     * an unrelated row that happens to follow it (a real row's name+course
     * always runs well past 20 characters before its own honor phrase).
     *
     * Also requires the current line to actually look like part of a row
     * (a leading row number, or a course keyword) before even attempting
     * this — otherwise an unrelated prose/header line sitting just above a
     * real row would get pulled into it too.
     */
    private function mergeSplitHonorLines(array $lines): array
    {
        $count = count($lines);
        $consumed = array_fill(0, $count, false);
        $merged = [];

        for ($i = 0; $i < $count; $i++) {
            if ($consumed[$i]) {
                continue;
            }

            $line = $lines[$i];
            $looksLikeRow = preg_match('/^\s*\d{1,3}[.)]\s/', $line) === 1
                || preg_match($this->courseKeywordPattern(), $line) === 1;

            if ($looksLikeRow && $i + 1 < $count && $this->matchHonor($line) === null) {
                $next = $lines[$i + 1];
                $candidate = rtrim($line) . ' ' . ltrim($next);
                $honorMatch = $this->matchHonor($candidate);

                if ($honorMatch !== null) {
                    $prefixFromNext = $honorMatch['position'] - (strlen(rtrim($line)) + 1);
                    if ($prefixFromNext <= 20) {
                        $line = $candidate;
                        $consumed[$i + 1] = true;
                    }
                }
            }

            $merged[] = $line;
        }

        return $merged;
    }

    /**
     * The other direction of the same OCR failure mergeSplitHonorLines()
     * fixes: instead of a row line ending mid-phrase and the next line
     * completing it, the honor phrase's opening words land completely
     * alone on their own orphaned line (typically because the table cell
     * rendered the tier name — "MAGNA CUM" — on its own visual row), with
     * one or more genuinely blank OCR lines before the row's actual
     * content ("1 LAUDE GERALDEZ, CRISTIAN JACA BSIT 1.15") resumes. Ties
     * these back together by scanning forward past any blank lines for a
     * line containing "LAUDE" that doesn't already have a complete honor
     * of its own. Up to ~20 trailing characters of noise on the fragment
     * line itself (e.g. "MAGNA CUM the fw") are tolerated and discarded —
     * only the recognized "(SUMMA/MAGNA) CUM" prefix is kept — but a
     * fragment that already contains "LAUDE" is left alone, since it isn't
     * actually orphaned.
     */
    private function mergeOrphanedHonorFragmentLines(array $lines): array
    {
        $count = count($lines);
        $consumed = array_fill(0, $count, false);
        $merged = [];

        for ($i = 0; $i < $count; $i++) {
            if ($consumed[$i]) {
                continue;
            }

            $trimmed = trim($lines[$i]);
            if (preg_match('/^((?:SUMMA|MAGNA)\s+CUM|CUM)\b.{0,20}$/i', $trimmed, $fragmentMatch) === 1
                && stripos($trimmed, 'LAUDE') === false
            ) {
                $j = $i + 1;
                while ($j < $count && trim($lines[$j]) === '') {
                    $j++;
                }

                if ($j < $count && preg_match('/\bLAUDE\b/i', $lines[$j]) === 1 && $this->matchHonor($lines[$j]) === null) {
                    $merged[] = $fragmentMatch[1] . ' ' . ltrim($lines[$j]);
                    for ($k = $i + 1; $k <= $j; $k++) {
                        $consumed[$k] = true;
                    }
                    continue;
                }
            }

            $merged[] = $lines[$i];
        }

        return array_values($merged);
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
        $honorEnd = $honorMatch['end'];

        // A degree abbreviation is short enough to coincidentally collide
        // with something inside the name (e.g. "MA." as in "JUAN MA. DELA
        // CRUZ", a common Filipino name element, not "Master of Arts").
        // The real course token is always the one immediately preceding
        // the Latin Honor, so take the rightmost match before it rather
        // than the first one in the line. Also tracks the first course
        // keyword AFTER the honor, for the "name-honor-course" layout below
        // (some lists put Latin Honor before the course column instead of
        // after it).
        $bachelorPosition = null;
        $afterHonorCoursePosition = null;
        if (preg_match_all($this->courseKeywordPattern(), $trimmed, $courseMatches, PREG_OFFSET_CAPTURE)) {
            foreach ($courseMatches[0] as $match) {
                if ($match[1] < $honorPosition) {
                    $bachelorPosition = $match[1];
                } elseif ($afterHonorCoursePosition === null && $match[1] >= $honorEnd) {
                    $afterHonorCoursePosition = $match[1];
                }
            }
        }

        if ($bachelorPosition !== null && trim(substr($trimmed, 0, $bachelorPosition)) === '') {
            return $this->parseCourseFirstLine($trimmed, $line, $bachelorPosition, $honorPosition, $honor);
        }

        // No course keyword precedes the honor at all, but one follows it:
        // this line uses "NAME [GENDER] HONOR COURSE DATE" column order
        // rather than "NAME COURSE HONOR DATE" — e.g. Visayas State
        // University's list ("ABAJON JEANY MAE LUCANAS F CUM LAUDE Bachelor
        // of Physical Education July 24, 2025"). Without this branch the
        // line falls through to the name-first logic below, which finds no
        // course boundary at all and swallows the entire row into
        // graduate_name with an empty course.
        if ($bachelorPosition === null && $afterHonorCoursePosition !== null) {
            return $this->parseHonorFirstLine($trimmed, $line, $honorPosition, $honorEnd, $honor);
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
     * Handles "NAME [GENDER] HONOR COURSE DATE" column order — the Latin
     * Honor sits between the name and the course, rather than after both
     * (the layout parseLine()'s default branch and parseCourseFirstLine()
     * both assume). Everything before the honor is the name; a lone
     * trailing "M"/"F" token right before it is the GENDER column, not part
     * of the name, so it's stripped. Everything after the honor is the
     * course, up to wherever the date starts (so the date text itself
     * doesn't get glued onto the end of the course string).
     *
     * The text before the honor is only trusted as a name when it has at
     * least two words — every real name in these lists is "SURNAME GIVEN
     * [MIDDLE]", never a single token. A single leftover token there is
     * actually a row number: either bare digits (cleanGraduateName() has
     * already stripped those to '') or, worse, digits OCR misread as
     * letters ("11" -> "il", "18" -> "1g", both of which survive the
     * digit-strip since they now look like letters). Those lists put the
     * honor before the name instead — "SEQNUM HONOR NAME COURSE GWA" — so
     * this delegates to parseHonorLeadingLine() rather than returning null.
     */
    private function parseHonorFirstLine(string $trimmed, string $rawLine, int $honorPosition, int $honorEnd, string $honor): ?array
    {
        $name = $this->cleanGraduateName(substr($trimmed, 0, $honorPosition));
        $name = trim(preg_replace('/\s+[MF]$/u', '', $name) ?? $name);
        if ($name === '' || strpos($name, ' ') === false) {
            return $this->parseHonorLeadingLine($trimmed, $rawLine, $honorEnd, $honor);
        }

        $afterHonor = substr($trimmed, $honorEnd);
        $courseEnd = strlen($afterHonor);
        if (preg_match('/\b(\d{1,2}\s+[A-Za-z]{3,9}\.?\s+\d{4}|[A-Za-z]{3,9}\.?\s+\d{1,2},?\s+\d{4}|\d{4}-\d{2}-\d{2}|\d{1,2}\/\d{1,2}\/\d{4})\b/', $afterHonor, $dateMatch, PREG_OFFSET_CAPTURE)) {
            $courseEnd = $dateMatch[0][1];
        }

        $course = $this->sanitizeExtractedText(substr($afterHonor, 0, $courseEnd));

        return [
            'graduate_name' => $name,
            'course' => $course,
            'latin_honor' => $honor,
            'graduation_date' => $this->extractDate($rawLine),
        ];
    }

    /**
     * "SEQNUM HONOR NAME COURSE GWA" — parseHonorFirstLine()'s sibling for
     * when nothing usable precedes the honor at all (just a row number, or
     * nothing once that number was cleaned away), so the name lives after
     * the honor too, immediately before the course keyword. Both name and
     * course are carved out of that same after-honor text using the course
     * keyword as the boundary between them — the same anchor
     * parseCourseFirstLine() uses, just on the other side of the honor.
     *
     * Takes the LAST course-keyword match, not the first: a short
     * abbreviation can coincidentally collide with something inside the
     * name itself (e.g. "Ma." in "VERONILLA, MA. CARLA OROGAN", matching
     * the "M.A." keyword), and the genuine course token is always the one
     * closest to the end of the row, right before the GWA figure.
     */
    private function parseHonorLeadingLine(string $trimmed, string $rawLine, int $honorEnd, string $honor): ?array
    {
        $afterHonor = substr($trimmed, $honorEnd);

        if (!preg_match_all($this->courseKeywordPattern(), $afterHonor, $courseMatches, PREG_OFFSET_CAPTURE) || empty($courseMatches[0])) {
            return null;
        }

        $courseStart = end($courseMatches[0])[1];
        $name = $this->cleanGraduateName(substr($afterHonor, 0, $courseStart));
        if ($name === '') {
            return null;
        }

        return [
            'graduate_name' => $name,
            'course' => $this->sanitizeExtractedText(substr($afterHonor, $courseStart)),
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
        if (!preg_match_all($this->courseKeywordPattern(), $trimmed, $courseMatches, PREG_OFFSET_CAPTURE)) {
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
     * '_', ':', curly/smart quotes) and any standalone number — a row/roll
     * number or a GWA figure like "1.75" — since none of that is ever part
     * of a graduate's name or course. A lone underscore (or a run of them)
     * is a common OCR misread of a checkbox/table-border cell that sits
     * right after the Latin Honor on a "name-honor-course" layout (e.g.
     * "MAGNA CUM LAUDE _ Bachelor of Science..."), same root cause as the
     * pipe case; a stray colon shows up the same way right before a course
     * abbreviation (e.g. "CAMAY, ELLA MAE M : BSHM"). Only whole,
     * whitespace-delimited numeric tokens are removed, so genuinely
     * alphanumeric OCR garbage (e.g. "1G5") is left alone rather than
     * guessed at. Straight quotes/apostrophes are deliberately left
     * untouched — those can be genuine name punctuation (e.g.
     * "O'Brien-Smith").
     */
    private function sanitizeExtractedText(string $text): string
    {
        $text = preg_replace('/[{}\[\]|~_:‘’“”]/u', '', $text) ?? $text;
        $text = preg_replace('/(?<!\S)\d+\.\d+(?!\S)/u', '', $text) ?? $text;
        $text = preg_replace('/(?<!\S)\d+(?!\S)/u', '', $text) ?? $text;
        $text = preg_replace('/\s{2,}/', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * The gap between CUM and LAUDE is deliberately bounded slop, not plain
     * \s*: once mergeSplitHonorLines() has spliced two OCR lines together,
     * whatever caused the split (a wrapped course word, a stray date/pipe)
     * usually still sits between them. Matching only the position where the
     * phrase starts (never the matched text itself, which is always one of
     * the three fixed constants below) means widening this can't corrupt
     * the honor value — it only lets previously-unrecognized phrases match.
     */
    private function matchHonor(string $text): ?array
    {
        $patterns = [
            'SUMMA CUM LAUDE' => '/SUMMA\s*CUM[^\n]{0,45}?LAUDE/i',
            'MAGNA CUM LAUDE' => '/MAGNA\s*CUM[^\n]{0,45}?LAUDE/i',
            'CUM LAUDE' => '/CUM[^\n]{0,45}?LAUDE/i',
        ];

        foreach ($patterns as $honor => $pattern) {
            if (preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE)) {
                return ['honor' => $honor, 'position' => $m[0][1], 'end' => $m[0][1] + strlen($m[0][0])];
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
