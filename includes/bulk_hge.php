<?php

// Callers must also load includes/duplicates.php (duplicate_exact_copy_key()) and
// includes/functions.php (format_school_display_name()).

require_once __DIR__ . '/graduates.php'; // application_date_year_error()

const BULK_HGE_MAX_BYTES = 10485760;
const BULK_HGE_MAX_ROWS = 50000;
const BULK_HGE_HEADER_SCAN_ROWS = 10;

// ---------------------------------------------------------------------------
// Text helpers
// ---------------------------------------------------------------------------

function bulk_hge_fold(string $value): string
{
    $value = strtr($value, [
        'Ñ' => 'N', 'ñ' => 'n', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'Ü' => 'U', 'ü' => 'u',
    ]);

    return strtoupper($value);
}

function bulk_hge_clean(string $value): string
{
    return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
}

/**
 * "No value" marker: blank, or only filler characters such as "_", "__.",
 * "." or "-". In the source system "_" in MI/MName means the person has no
 * middle name.
 */
function bulk_hge_is_none(string $value): bool
{
    return preg_match('/^[\s_.\-\\\\\/]*$/u', $value) === 1;
}

/** JR./JR → JR, III → III; anything unrecognised ("." "\") → ''. */
function bulk_hge_normalize_ext(string $value): string
{
    $value = preg_replace('/[^A-Z]/', '', bulk_hge_fold($value)) ?? '';

    return in_array($value, ['JR', 'SR', 'II', 'III', 'IV'], true) ? $value : '';
}

const BULK_HGE_EXT_TOKENS = ['JR', 'SR', 'II', 'III', 'IV'];
const BULK_HGE_HONORIFIC_TOKENS = ['MS', 'MR', 'MRS', 'MISS', 'DR', 'ENGR', 'ATTY'];

/**
 * Tokens of a name with punctuation, honorifics, pure numbers (stray GWA
 * text such as "1.56") and generational suffixes removed.
 *
 * @return array{tokens: array<int, string>, ext: string}
 */
function bulk_hge_name_tokens(string $name): array
{
    $folded = preg_replace('/[^A-Z0-9 ]+/', ' ', bulk_hge_fold($name)) ?? '';
    $tokens = [];
    $ext = '';

    foreach (preg_split('/\s+/', trim($folded)) as $token) {
        if ($token === '' || ctype_digit($token) || in_array($token, BULK_HGE_HONORIFIC_TOKENS, true)) {
            continue;
        }
        if (in_array($token, BULK_HGE_EXT_TOKENS, true)) {
            $ext = $token;
            continue;
        }
        $tokens[] = $token;
    }

    return ['tokens' => $tokens, 'ext' => $ext];
}

// ---------------------------------------------------------------------------
// Dates
// ---------------------------------------------------------------------------

/**
 * Accepts a real Excel date (raw serial number, as the readers return it),
 * ISO "2024-04-02", US "4/2/2024" (the order the source system displays),
 * "2024/04/02", or a date spelled with a month name. Anything else is invalid.
 */
function bulk_hge_parse_date(string $raw, bool $date1904 = false): ?string
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }

    $date = null;

    if (is_numeric($raw)) {
        $serial = (float) $raw;
        if ($date1904) {
            $serial += 1462;
        }
        if ($serial < 1 || $serial > 2958465) {
            return null;
        }
        $date = gmdate('Y-m-d', ((int) floor($serial) - 25569) * 86400);
    } elseif (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $raw, $m) || preg_match('/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/', $raw, $m)) {
        $date = checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]) : null;
    } elseif (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $raw, $m)) {
        $date = checkdate((int) $m[1], (int) $m[2], (int) $m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $m[1], $m[2]) : null;
    } elseif (preg_match('/[A-Za-z]{3}/', $raw)) {
        $timestamp = strtotime($raw);
        $date = $timestamp !== false ? date('Y-m-d', $timestamp) : null;
    }

    if ($date === null) {
        return null;
    }

    // Sanity window: an application date can't be ancient or in the far future.
    $year = (int) substr($date, 0, 4);

    return ($year >= 1990 && $year <= (int) date('Y') + 1) ? $date : null;
}

// ---------------------------------------------------------------------------
// Parsing the uploaded sheet
// ---------------------------------------------------------------------------

/** @return array<string, array<int, string>>  field => accepted header spellings (A-Z0-9 only) */
function bulk_hge_header_aliases(): array
{
    return [
        'date' => ['COEISSUEDATE', 'DATEAPPLIED', 'APPLICATIONDATE', 'DATEOFAPPLICATION', 'COEDATE', 'DATE'],
        'last' => ['LNAME', 'LASTNAME', 'SURNAME'],
        'first' => ['FNAME', 'FIRSTNAME', 'GIVENNAME'],
        'mi' => ['MI', 'MIDDLEINITIAL'],
        'middle' => ['MNAME', 'MIDDLENAME'],
        'ext' => ['EXTNAME', 'EXTENSIONNAME', 'EXTENSION', 'SUFFIX', 'EXT'],
    ];
}

/**
 * Pure (no DB): turns the rows of an uploaded sheet into de-duplicated
 * people. The header row is located within the first rows (so both the
 * template, which has an instruction block above it, and a raw export whose
 * header is on row 1 work). MI and MName are optional; "_" means no middle
 * name. Rows with a missing name or an unreadable date are reported, not
 * dropped silently. The same person on several rows keeps the earliest date.
 *
 * @param array<int, array<int, string>> $sheetRows
 * @return array{ok: bool, error?: string, people?: array, rows_read?: int, duplicate_rows?: int, errors?: array}
 */
function parse_bulk_hge_rows(array $sheetRows, bool $date1904 = false): array
{
    $aliases = bulk_hge_header_aliases();
    $headerRow = null;
    $columns = [];

    $rowNumbers = array_keys($sheetRows);
    sort($rowNumbers);
    foreach ($rowNumbers as $rowNumber) {
        if ($rowNumber > BULK_HGE_HEADER_SCAN_ROWS) {
            break;
        }
        $found = [];
        foreach ($sheetRows[$rowNumber] as $col => $value) {
            $normalized = preg_replace('/[^A-Z0-9]/', '', bulk_hge_fold((string) $value)) ?? '';
            foreach ($aliases as $field => $names) {
                if (!isset($found[$field]) && in_array($normalized, $names, true)) {
                    $found[$field] = (int) $col;
                }
            }
        }
        if (isset($found['date'], $found['last'], $found['first'])) {
            $headerRow = (int) $rowNumber;
            $columns = $found;
            break;
        }
    }

    if ($headerRow === null) {
        return [
            'ok' => false,
            'error' => 'THE FILE MUST HAVE A HEADER ROW WITH THE COLUMNS: CoEIssueDate (DATE APPLIED), LName (LAST NAME), FName (FIRST NAME), MI, MName (MIDDLE NAME) AND ExtName. USE THE DOWNLOADABLE TEMPLATE.',
        ];
    }

    $cell = static function (array $row, string $field) use ($columns): string {
        return isset($columns[$field]) ? bulk_hge_clean((string) ($row[$columns[$field]] ?? '')) : '';
    };

    $people = [];
    $errors = [];
    $rowsRead = 0;
    $duplicateRows = 0;

    foreach ($rowNumbers as $rowNumber) {
        if ($rowNumber <= $headerRow) {
            continue;
        }
        $row = $sheetRows[$rowNumber];
        $last = $cell($row, 'last');
        $first = $cell($row, 'first');
        $rawDate = $cell($row, 'date');
        $miRaw = $cell($row, 'mi');
        $middleRaw = $cell($row, 'middle');
        $extRaw = $cell($row, 'ext');

        if ($last === '' && $first === '' && $rawDate === '' && $miRaw === '' && $middleRaw === '' && $extRaw === '') {
            continue;
        }

        $rowsRead++;
        $label = trim($last . ', ' . $first, ' ,');

        if ($last === '' || $first === '') {
            $errors[] = ['row' => $rowNumber, 'message' => 'LAST NAME AND FIRST NAME ARE REQUIRED.', 'name' => $label];
            continue;
        }

        $date = bulk_hge_parse_date($rawDate, $date1904);
        if ($date === null) {
            $errors[] = [
                'row' => $rowNumber,
                'message' => $rawDate === '' ? 'THE DATE APPLIED IS MISSING.' : 'THE DATE APPLIED IS NOT A VALID DATE.',
                'name' => $label,
            ];
            continue;
        }

        $middle = bulk_hge_is_none($middleRaw) ? '' : $middleRaw;
        $miLetter = '';
        if (!bulk_hge_is_none($miRaw) && preg_match('/[A-Z]/', bulk_hge_fold($miRaw), $m)) {
            $miLetter = $m[0];
        }
        $ext = bulk_hge_normalize_ext($extRaw);

        $key = bulk_hge_fold($last) . '|' . bulk_hge_fold($first) . '|' . $miLetter . '|' . bulk_hge_fold($middle) . '|' . $ext;

        if (isset($people[$key])) {
            $duplicateRows++;
            if ($date < $people[$key]['date']) {
                $people[$key]['date'] = $date;
            }
            continue;
        }

        $people[$key] = [
            'row' => $rowNumber,
            'date' => $date,
            'last' => $last,
            'first' => $first,
            'mi' => $miLetter,
            'middle' => $middle,
            'ext' => $ext,
        ];

        if (count($people) > BULK_HGE_MAX_ROWS) {
            return ['ok' => false, 'error' => 'THE FILE HAS TOO MANY ROWS (MAXIMUM ' . number_format(BULK_HGE_MAX_ROWS) . ').'];
        }
    }

    return [
        'ok' => true,
        'people' => array_values($people),
        'rows_read' => $rowsRead,
        'duplicate_rows' => $duplicateRows,
        'errors' => $errors,
    ];
}

// ---------------------------------------------------------------------------
// Matching against saved graduates
// ---------------------------------------------------------------------------

/**
 * Loads every saved graduate once, tokenized, with an inverted token index so
 * thousands of rows can be matched without scanning the whole table each time.
 *
 * @return array{rows: array<int, array>, index: array<string, array<int, int>>}
 */
function bulk_hge_load_graduate_index(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT g.id, g.graduate_name, g.school_id, g.course, g.latin_honor, g.graduation_year, g.graduation_date,
                (a.id IS NOT NULL) AS applied, (a.approved_at IS NOT NULL) AS approved,
                s.school_name, s.campus
         FROM latin_honor_graduates g
         LEFT JOIN graduate_applications a ON a.graduate_id = g.id
         LEFT JOIN schools s ON s.id = g.school_id'
    );

    $rows = [];
    $index = [];
    foreach ($stmt->fetchAll() as $row) {
        $id = (int) $row['id'];
        $parsed = bulk_hge_name_tokens((string) $row['graduate_name']);
        $rows[$id] = [
            'name' => $row['graduate_name'],
            'school' => format_school_display_name((string) ($row['school_name'] ?? ''), $row['campus'] ?? null),
            'year' => (string) $row['graduation_year'],
            'grad_year' => graduate_effective_graduation_year($row['graduation_date'], (int) $row['graduation_year']),
            'tokens' => $parsed['tokens'],
            'ext' => $parsed['ext'],
            'applied' => (int) $row['applied'] === 1,
            'approved' => (int) $row['approved'] === 1,
            'group_key' => duplicate_exact_copy_key($row),
        ];
        foreach (array_unique($parsed['tokens']) as $token) {
            $index[$token][] = $id;
        }
    }

    return ['rows' => $rows, 'index' => $index];
}

/**
 * Does a saved record plausibly belong to this person? All of the person's
 * last- and first-name tokens must appear in the saved name; whatever is left
 * over must be explained by the person's middle name (a whole word, or an
 * initial matching MI / the middle name's first letter).
 *
 * @return string|null  'high' (leftovers fully explained / nobody has a middle
 *                      name), 'medium' (the saved record simply has no middle
 *                      name while the file does), or null (no match)
 */
function bulk_hge_candidate_level(array $person, array $savedTokens): ?string
{
    $last = bulk_hge_name_tokens($person['last'])['tokens'];
    $first = bulk_hge_name_tokens($person['first'])['tokens'];
    $middle = $person['middle'] === '' ? [] : bulk_hge_name_tokens($person['middle'])['tokens'];
    $must = array_merge($last, $first);
    if ($must === []) {
        return null;
    }
    $hasMiddle = $middle !== [] || $person['mi'] !== '';

    $remaining = $savedTokens;
    foreach ($must as $token) {
        $at = array_search($token, $remaining, true);
        if ($at === false) {
            return null;
        }
        unset($remaining[$at]);
    }
    $remaining = array_values($remaining);

    if ($remaining === []) {
        return $hasMiddle ? 'medium' : 'high';
    }
    if (!$hasMiddle) {
        return null;
    }

    $initials = array_map(static fn (string $t): string => $t[0], $middle);
    foreach ($remaining as $token) {
        $isFullWord = in_array($token, $middle, true);
        $isInitial = strlen($token) === 1 && ($token === $person['mi'] || in_array($token, $initials, true));
        if (!$isFullWord && !$isInitial) {
            return null;
        }
    }

    return 'high';
}

/**
 * Collapses candidate ids that are the same person duplicated in the system
 * (same school/year/honor/name/course) down to the keeper: the copy with a
 * filed application, else the oldest.
 */
function bulk_hge_collapse_duplicates(array $ids, array $db): ?int
{
    $groupKeys = array_unique(array_map(static fn (int $id): string => $db['rows'][$id]['group_key'], $ids));
    if (count($groupKeys) !== 1) {
        return null;
    }

    usort($ids, static function (int $a, int $b) use ($db): int {
        return ((int) $db['rows'][$b]['applied'] <=> (int) $db['rows'][$a]['applied']) ?: ($a <=> $b);
    });

    return $ids[0];
}

/**
 * @return array{status: string, graduate_id: int|null, candidates: array<int, int>}
 *         status: confident | loose | ambiguous | not_found
 */
function bulk_hge_match_person(array $person, array $db): array
{
    $required = array_unique(array_merge(
        bulk_hge_name_tokens($person['last'])['tokens'],
        bulk_hge_name_tokens($person['first'])['tokens']
    ));

    $candidateIds = null;
    foreach ($required as $token) {
        $ids = $db['index'][$token] ?? [];
        $candidateIds = $candidateIds === null ? $ids : array_values(array_intersect($candidateIds, $ids));
        if ($candidateIds === []) {
            break;
        }
    }

    $high = [];
    $medium = [];
    foreach ($candidateIds ?? [] as $id) {
        $level = bulk_hge_candidate_level($person, $db['rows'][$id]['tokens']);
        if ($level === 'high') {
            $high[] = $id;
        } elseif ($level === 'medium') {
            $medium[] = $id;
        }
    }

    $result = static fn (string $status, ?int $id, array $candidates): array => [
        'status' => $status,
        'graduate_id' => $id,
        'candidates' => $candidates,
    ];

    if ($high !== []) {
        if (count($high) === 1) {
            return $result('confident', $high[0], $high);
        }
        $keeper = bulk_hge_collapse_duplicates($high, $db);
        if ($keeper !== null) {
            return $result('confident', $keeper, $high);
        }
        if ($person['ext'] !== '') {
            $sameExt = array_values(array_filter($high, static fn (int $id): bool => $db['rows'][$id]['ext'] === $person['ext']));
            if (count($sameExt) === 1) {
                return $result('confident', $sameExt[0], $high);
            }
        }

        return $result('ambiguous', null, $high);
    }

    if ($medium !== []) {
        if (count($medium) === 1) {
            return $result('loose', $medium[0], $medium);
        }
        $keeper = bulk_hge_collapse_duplicates($medium, $db);

        return $keeper !== null ? $result('loose', $keeper, $medium) : $result('ambiguous', null, $medium);
    }

    return $result('not_found', null, []);
}

/**
 * Matches every person and sorts them into what will be filed or approved,
 * what is skipped because it is already approved, what needs a human look,
 * and what simply isn't a Latin Honor graduate in this system (the source
 * list is the whole HGE applicant table, so most rows legitimately aren't).
 * A confident match that is already filed but not yet approved still goes
 * into $toFile — see bulk_hge_insert_pairs()'s upsert, which approves an
 * existing application rather than skipping it; only an already-APPROVED
 * match is excluded (there's nothing left for this file's date to change).
 *
 * @param array<int, array> $people  from parse_bulk_hge_rows()
 * @return array{to_file: array, review: array, counts: array, date_errors: array} date_errors are
 *     matched rows whose date breaks the year rule; they are listed with the unreadable rows
 */
function bulk_hge_build_preview(PDO $pdo, array $people, array $parseErrors = []): array
{
    $db = bulk_hge_load_graduate_index($pdo);

    $matches = [];
    $claims = [];
    foreach ($people as $i => $person) {
        $match = bulk_hge_match_person($person, $db);
        $matches[$i] = $match;
        if ($match['status'] === 'confident') {
            $claims[$match['graduate_id']][] = $i;
        }
    }

    // One saved record claimed by two different people in the file is not safe
    // to file automatically.
    foreach ($claims as $graduateId => $personIndexes) {
        if (count($personIndexes) > 1) {
            foreach ($personIndexes as $i) {
                $matches[$i] = ['status' => 'claimed', 'graduate_id' => $graduateId, 'candidates' => [$graduateId]];
            }
        }
    }

    $toFile = [];
    $review = [];
    $dateErrors = [];
    $counts = [
        'people' => count($people),
        'to_file' => 0,
        'already_approved' => 0,
        'review' => 0,
        'not_found' => 0,
        'invalid' => count($parseErrors),
        'invalid_date' => 0,
    ];

    $reasons = [
        'loose' => 'LOOSE MATCH — THE SAVED RECORD HAS NO MIDDLE NAME TO CONFIRM IT.',
        'ambiguous' => 'AMBIGUOUS — MORE THAN ONE SAVED RECORD FITS THIS PERSON.',
        'claimed' => 'CONFLICT — THE SAME SAVED RECORD FITS MORE THAN ONE PERSON IN THE FILE.',
    ];

    foreach ($people as $i => $person) {
        $match = $matches[$i];
        switch ($match['status']) {
            case 'confident':
                if ($db['rows'][$match['graduate_id']]['approved']) {
                    $counts['already_approved']++;
                } else {
                    // The file's date is the filing and approval date: a year before the graduate's
                    // graduation year is invalid, so the row is reported instead of filed.
                    $yearError = application_date_year_error($db['rows'][$match['graduate_id']]['grad_year'], $person['date'], 'DATE IN THE FILE');
                    if ($yearError !== null) {
                        $dateErrors[] = [
                            'row' => $person['row'],
                            'name' => trim($person['last'] . ', ' . $person['first'], ' ,'),
                            'message' => $yearError,
                        ];
                        $counts['invalid_date']++;
                        $counts['invalid']++;
                    } else {
                        $toFile[] = ['graduate_id' => $match['graduate_id'], 'date' => $person['date']];
                        $counts['to_file']++;
                    }
                }
                break;
            case 'not_found':
                $counts['not_found']++;
                break;
            default:
                $counts['review']++;
                $review[] = [
                    'status' => $match['status'],
                    'reason' => $reasons[$match['status']],
                    'person' => $person,
                    'candidate_ids' => array_map('intval', $match['candidates']),
                    'saved_names' => array_map(static fn (int $id): string => $db['rows'][$id]['name'], $match['candidates']),
                    'saved' => array_map(static fn (int $id): array => [
                        'name' => $db['rows'][$id]['name'],
                        'school' => $db['rows'][$id]['school'],
                        'year' => $db['rows'][$id]['year'],
                    ], $match['candidates']),
                ];
        }
    }

    return ['to_file' => $toFile, 'review' => $review, 'counts' => $counts, 'date_errors' => $dateErrors];
}

// ---------------------------------------------------------------------------
// Applying
// ---------------------------------------------------------------------------

/**
 * Inserts (or approves) the applications; the caller owns the transaction.
 * $pair['date'] is the source file's CoE Issue Date, which is treated as the
 * approval date, not the filing date (per project decision): a graduate with
 * no existing application yet is filed AND approved on that same date (there
 * is no earlier filing event to preserve); a graduate who already has one is
 * left with its original application_date untouched and only gets
 * approved_at/approved_by set to this date — this is an upsert, never a
 * skip, which is why graduate_applications.uq_graduate_id's ON DUPLICATE KEY
 * branch below only ever touches the approval columns. A vanished graduate
 * (deleted since the preview was built) still counts as skipped — that's a
 * real FK failure, not a duplicate.
 *
 * @param array<int, array{graduate_id: int, date: string}> $pairs
 * @return array{filed: int, approved: int, skipped: int, invalid_date: int} invalid_date counts the
 *     rows left out because their date's year is before the graduate's graduation year
 */
function bulk_hge_insert_pairs(PDO $pdo, array $pairs, int $appliedBy): array
{
    $upsert = $pdo->prepare(
        'INSERT INTO graduate_applications (graduate_id, applied_by, application_date, approved_at, approved_by)
         VALUES (:graduate_id, :applied_by, :application_date, :approved_at, :approved_by)
         ON DUPLICATE KEY UPDATE approved_at = VALUES(approved_at), approved_by = VALUES(approved_by)'
    );

    $graduateInfo = $pdo->prepare('SELECT graduation_date, graduation_year FROM latin_honor_graduates WHERE id = :id');

    $filed = 0;
    $approved = 0;
    $skipped = 0;
    $invalidDate = 0;
    foreach ($pairs as $pair) {
        // The file's date is both the filing and the approval date, so it may not be in a year before
        // the graduate graduated (application_date_year_error()); such a row is left out, not filed.
        $graduateInfo->execute(['id' => (int) $pair['graduate_id']]);
        $graduate = $graduateInfo->fetch();
        if (is_array($graduate)
            && application_date_year_error(graduate_effective_graduation_year($graduate['graduation_date'], (int) $graduate['graduation_year']), (string) $pair['date'], 'DATE') !== null) {
            $invalidDate++;
            continue;
        }

        try {
            $upsert->execute([
                'graduate_id' => (int) $pair['graduate_id'],
                'applied_by' => $appliedBy,
                'application_date' => $pair['date'],
                'approved_at' => $pair['date'],
                'approved_by' => $appliedBy,
            ]);
            // MySQL's ON DUPLICATE KEY UPDATE reports 1 affected row for a
            // fresh INSERT and 2 for a row that an UPDATE branch actually
            // changed (0 if the duplicate's approved_at/approved_by were
            // already identical — still an "approved" outcome, just a no-op
            // one, so it's counted the same as a changed update).
            if ($upsert->rowCount() === 1) {
                $filed++;
            } else {
                $approved++;
            }
        } catch (\PDOException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
            $skipped++;
        }
    }

    return ['filed' => $filed, 'approved' => $approved, 'skipped' => $skipped, 'invalid_date' => $invalidDate];
}

/**
 * Files/approves the previewed applications in a single transaction. A
 * vanished graduate (deleted since the preview was built) is skipped.
 *
 * @param array<int, array{graduate_id: int, date: string}> $pairs
 * @return array{ok: bool, filed?: int, approved?: int, skipped?: int, error?: string}
 */
function bulk_hge_apply(PDO $pdo, array $pairs, int $appliedBy): array
{
    try {
        $pdo->beginTransaction();
        $counts = bulk_hge_insert_pairs($pdo, $pairs, $appliedBy);
        $pdo->commit();
    } catch (\Throwable $e) {
        try {
            $pdo->rollBack();
        } catch (\Throwable $rollbackError) {
            error_log('bulk_hge_apply rollback failed: ' . $rollbackError->getMessage());
        }
        error_log('bulk_hge_apply failed: ' . $e->getMessage());

        return ['ok' => false, 'error' => 'THE APPLICATIONS COULD NOT BE FILED. NOTHING WAS CHANGED. PLEASE TRY AGAIN.'];
    }

    return ['ok' => true, 'filed' => $counts['filed'], 'approved' => $counts['approved'], 'skipped' => $counts['skipped'], 'invalid_date' => $counts['invalid_date']];
}

// ---------------------------------------------------------------------------
// Reading an uploaded file (any supported format)
// ---------------------------------------------------------------------------

/**
 * Validates and reads an uploaded .xls / .xlsx / .csv. Extension AND content
 * signature must agree, so a renamed file is rejected with a clear message.
 *
 * @return array{ok: bool, error?: string, rows?: array, date1904?: bool}
 */
function bulk_hge_read_upload(string $path, string $originalName, int $size): array
{
    if ($size <= 0 || $size > BULK_HGE_MAX_BYTES) {
        return ['ok' => false, 'error' => 'THE FILE IS EMPTY OR EXCEEDS THE ' . (int) (BULK_HGE_MAX_BYTES / 1048576) . ' MB LIMIT.'];
    }

    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $signature = (string) @file_get_contents($path, false, null, 0, 8);

    if ($extension === 'xls') {
        if (strncmp($signature, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", 8) !== 0) {
            return ['ok' => false, 'error' => 'THE FILE IS NOT A VALID EXCEL (.XLS) FILE.'];
        }
        require_once __DIR__ . '/xls_reader.php';

        return xls_read_first_sheet($path);
    }

    if ($extension === 'xlsx') {
        if (strncmp($signature, 'PK', 2) !== 0) {
            return ['ok' => false, 'error' => 'THE FILE IS NOT A VALID EXCEL (.XLSX) FILE.'];
        }
        require_once __DIR__ . '/xlsx_reader.php';

        return xlsx_read_first_sheet($path);
    }

    if ($extension === 'csv') {
        require_once __DIR__ . '/csv_reader.php';

        return csv_read_rows($path);
    }

    return ['ok' => false, 'error' => 'ONLY .XLS, .XLSX OR .CSV FILES ARE ACCEPTED.'];
}
