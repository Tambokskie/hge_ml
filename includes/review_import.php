<?php

require_once __DIR__ . '/template_upload.php';

// Bulk add of graduates to a review in progress (admin/review.php, "UPLOAD
// EXCEL"): a quick way round an extraction that misread the list. The sheet is
// one row per graduate under the headers "Name of Honor Graduate", "Course",
// "Honors Received" and "Date of Graduation". Rows are only STAGED (marked
// source = manual); nothing reaches the database until SAVE RECORDS.

const REVIEW_IMPORT_MAX_ROWS = 2000;
const REVIEW_IMPORT_HEADER_SCAN_ROWS = 10;

/** @return array<string, array<int, string>>  field => accepted header spellings (A-Z only) */
function review_import_header_aliases(): array
{
    return [
        'name' => ['NAMEOFHONORGRADUATE', 'NAMEOFGRADUATE', 'GRADUATENAME', 'HONORGRADUATE', 'NAME'],
        'course' => ['COURSE', 'DEGREECOURSE', 'DEGREE', 'PROGRAM'],
        'honor' => ['HONORSRECEIVED', 'HONORRECEIVED', 'LATINHONOR', 'LATINHONORS', 'HONORS', 'HONOR'],
        'date' => ['DATEOFGRADUATION', 'GRADUATIONDATE', 'DATEGRADUATED', 'DATE'],
    ];
}

function review_import_clean(string $value): string
{
    return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
}

/**
 * Pure parse of an already-read worksheet ([rowNum => [colIndex => value]],
 * 1-indexed, as every reader here returns) into staged review rows.
 *
 * A row is skipped, and reported, when its name is missing or too long or its
 * honor is not one of the three Latin honors. A missing or unreadable date does
 * NOT skip the row: it is added without a date (the year falls back to the
 * upload's school year) and counted, so it can be fixed in the review.
 *
 * @return array{ok: false, error: string}|array{ok: true, rows: array, skipped: array<int, array{row: int, reason: string}>, undated: int}
 */
function parse_review_import_rows(array $sheetRows, ?int $fallbackYear = null): array
{
    $columns = null;
    $headerRow = 0;
    $aliases = review_import_header_aliases();

    foreach ($sheetRows as $rowNumber => $cells) {
        if ($rowNumber > REVIEW_IMPORT_HEADER_SCAN_ROWS) {
            break;
        }
        $found = [];
        foreach ($cells as $col => $value) {
            $key = preg_replace('/[^A-Z]/', '', mb_strtoupper((string) $value));
            foreach ($aliases as $field => $names) {
                if (!isset($found[$field]) && in_array($key, $names, true)) {
                    $found[$field] = (int) $col;
                }
            }
        }
        if (isset($found['name'], $found['honor'])) {
            $columns = $found;
            $headerRow = (int) $rowNumber;
            break;
        }
    }

    if ($columns === null) {
        return ['ok' => false, 'error' => 'THE HEADER ROW WAS NOT FOUND. THE FIRST ROW MUST HAVE: NAME OF HONOR GRADUATE, COURSE, HONORS RECEIVED, DATE OF GRADUATION.'];
    }

    $rows = [];
    $skipped = [];
    $undated = 0;

    foreach ($sheetRows as $rowNumber => $cells) {
        if ($rowNumber <= $headerRow) {
            continue;
        }

        $name = review_import_clean(xlsx_cell_value($sheetRows, $rowNumber, $columns['name']));
        $courseRaw = isset($columns['course']) ? review_import_clean(xlsx_cell_value($sheetRows, $rowNumber, $columns['course'])) : '';
        $honorRaw = review_import_clean(xlsx_cell_value($sheetRows, $rowNumber, $columns['honor']));
        $dateRaw = isset($columns['date']) ? xlsx_cell_value($sheetRows, $rowNumber, $columns['date']) : '';

        if ($name === '' && $courseRaw === '' && $honorRaw === '' && $dateRaw === '') {
            continue;
        }

        if ($name === '') {
            $skipped[] = ['row' => (int) $rowNumber, 'reason' => 'THE NAME IS MISSING.'];
            continue;
        }
        if (mb_strlen($name) > 255) {
            $skipped[] = ['row' => (int) $rowNumber, 'reason' => 'THE NAME IS TOO LONG.'];
            continue;
        }

        $honor = normalize_template_honor_value(preg_replace('/[^A-Za-z ]/', '', $honorRaw) ?? '');
        if ($honor === null) {
            $skipped[] = ['row' => (int) $rowNumber, 'reason' => $honorRaw === ''
                ? 'THE HONOR RECEIVED IS MISSING.'
                : 'THE HONOR RECEIVED IS NOT CUM LAUDE, MAGNA CUM LAUDE OR SUMMA CUM LAUDE.'];
            continue;
        }

        $date = $dateRaw !== '' ? parse_template_date_value($dateRaw) : null;
        if ($date === null) {
            $undated++;
        }

        $rows[] = [
            'graduate_name' => $name,
            'course' => mb_substr($courseRaw, 0, 255),
            'latin_honor' => $honor,
            'graduation_date' => $date,
            'graduation_year' => $date !== null ? (int) substr($date, 0, 4) : (int) $fallbackYear,
            'source' => 'manual',
        ];

        if (count($rows) > REVIEW_IMPORT_MAX_ROWS) {
            return ['ok' => false, 'error' => 'THE FILE HAS MORE THAN ' . REVIEW_IMPORT_MAX_ROWS . ' ROWS. SPLIT IT INTO SMALLER FILES.'];
        }
    }

    if ($rows === [] && $skipped === []) {
        return ['ok' => false, 'error' => 'THE FILE HAS NO ROWS UNDER THE HEADER.'];
    }

    return ['ok' => true, 'rows' => $rows, 'skipped' => $skipped, 'undated' => $undated];
}
