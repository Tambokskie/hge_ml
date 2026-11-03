<?php

require_once __DIR__ . '/recycle_bin.php';
require_once __DIR__ . '/duplicates.php';

function derive_fallback_year(string $schoolYear): ?int
{
    if (preg_match_all('/\d{4}/', $schoolYear, $matches) && !empty($matches[0])) {
        return (int) end($matches[0]);
    }

    return null;
}

/**
 * Computes the next page range to extract for the chunked AI extraction
 * flow (api/extract_pdf.php), given how many pages have already been
 * successfully extracted, the document's total page count, and the
 * configured chunk size. Returns null once every page is covered — the
 * signal that nothing remains to extract.
 */
function next_chunk_range(int $extractedThroughPage, int $totalPages, int $chunkSize): ?array
{
    if ($extractedThroughPage >= $totalPages) {
        return null;
    }

    $first = $extractedThroughPage + 1;
    $last = min($extractedThroughPage + $chunkSize, $totalPages);

    return ['first' => $first, 'last' => $last];
}

function validate_staged_row(array $row): array
{
    $errors = [];

    $name = trim((string) ($row['graduate_name'] ?? ''));
    if ($name === '') {
        $errors[] = 'GRADUATE NAME IS REQUIRED.';
    } elseif (mb_strlen($name) > 255) {
        $errors[] = 'GRADUATE NAME IS TOO LONG.';
    }

    $honor = (string) ($row['latin_honor'] ?? '');
    if (!in_array($honor, ['CUM LAUDE', 'MAGNA CUM LAUDE', 'SUMMA CUM LAUDE'], true)) {
        $errors[] = 'LATIN HONOR MUST BE ONE OF THE THREE RECOGNIZED VALUES.';
    }

    $year = $row['graduation_year'] ?? null;
    if (!is_numeric($year) || (int) $year < 1901 || (int) $year > 2155) {
        $errors[] = 'YEAR GRADUATED MUST BE A VALID 4-DIGIT YEAR.';
    }

    $date = $row['graduation_date'] ?? null;
    if ($date !== null && $date !== '') {
        $dt = DateTime::createFromFormat('Y-m-d', $date);
        if (!$dt || $dt->format('Y-m-d') !== $date) {
            $errors[] = 'DATE OF GRADUATION MUST BE A VALID DATE.';
        }
    }

    return ['ok' => empty($errors), 'errors' => $errors];
}

function staged_row_status(array $row): string
{
    return validate_staged_row($row)['ok'] ? 'VALID' : 'INCOMPLETE';
}

function start_extraction_review(int $uploadId, array $rows): void
{
    ensure_session();
    $_SESSION['extraction_review'] = ['upload_id' => $uploadId, 'rows' => $rows];
}

function get_extraction_review(int $uploadId): ?array
{
    ensure_session();
    $review = $_SESSION['extraction_review'] ?? null;
    if ($review === null || (int) $review['upload_id'] !== $uploadId) {
        return null;
    }

    return $review;
}

function clear_extraction_review(): void
{
    ensure_session();
    unset($_SESSION['extraction_review']);
}

const STAGED_ROW_LOCKED_MESSAGE = 'THIS RECORD ALREADY HAS A FILED APPLICATION. IT IS KEPT EXACTLY AS IT IS AND CANNOT BE CHANGED OR REMOVED IN A RE-EXTRACTION.';

/**
 * A staged row that stands for a saved record with a filed application ("tagged"):
 * a re-extraction carries it along untouched. It cannot be edited, removed or have
 * its date changed in the review, and saving never updates or deletes it.
 */
function staged_row_is_locked(array $row): bool
{
    return !empty($row['locked']);
}

function count_saved_graduates(PDO $pdo, int $uploadId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM latin_honor_graduates WHERE upload_id = :upload_id');
    $stmt->execute(['upload_id' => $uploadId]);

    return (int) $stmt->fetchColumn();
}

/**
 * What to stage after a (re-)extraction of an upload that ALREADY has saved
 * records, so a re-extraction never harms a record that has a filed application:
 *  - every saved record with a filed application ("tagged") is carried into the
 *    review as a LOCKED row (same id, same data) - only when $addLocked, i.e. for
 *    the first batch of an extraction, so it is not added once per batch;
 *  - a newly extracted row with the same name as a tagged record is dropped - the
 *    tagged record prevails, so the new copy would only be a duplicate;
 *  - a newly extracted row with the same name as an untagged saved record takes
 *    over that record's id, so saving UPDATES it in place instead of deleting and
 *    re-inserting it. Saved records the new extraction does not reproduce are
 *    removed on save (they go to the Recycle Bin and can be restored).
 * Nothing is written here and nothing changes in the database until the admin
 * clicks SAVE RECORDS. With no saved records this returns the new rows unchanged.
 *
 * @param array<int, array> $newRows        freshly extracted rows (no ids)
 * @param array<int, array> $alreadyStaged  rows staged by earlier batches (their ids are taken)
 * @return array{rows: array<int, array>, kept: int, skipped: int}
 */
function merge_reextracted_rows(PDO $pdo, int $uploadId, array $newRows, array $alreadyStaged = [], bool $addLocked = true): array
{
    $stmt = $pdo->prepare(
        'SELECT g.id, g.graduate_name, g.course, g.latin_honor, g.graduation_date, g.graduation_year, g.source, a.application_date
         FROM latin_honor_graduates g
         LEFT JOIN graduate_applications a ON a.graduate_id = g.id
         WHERE g.upload_id = :upload_id
         ORDER BY g.id ASC'
    );
    $stmt->execute(['upload_id' => $uploadId]);
    $saved = $stmt->fetchAll();

    if ($saved === []) {
        return ['rows' => $newRows, 'kept' => 0, 'skipped' => 0];
    }

    $taken = [];
    foreach ($alreadyStaged as $staged) {
        if (isset($staged['id'])) {
            $taken[(int) $staged['id']] = true;
        }
    }

    $locked = [];
    $taggedKeys = [];
    $untaggedIdsByKey = [];
    foreach ($saved as $record) {
        $key = duplicate_group_key($record);
        if ($record['application_date'] !== null) {
            $taggedKeys[$key] = true;
            $locked[] = [
                'id' => (int) $record['id'],
                'graduate_name' => (string) $record['graduate_name'],
                'course' => (string) $record['course'],
                'latin_honor' => (string) $record['latin_honor'],
                'graduation_date' => $record['graduation_date'] !== null ? (string) $record['graduation_date'] : null,
                'graduation_year' => (int) $record['graduation_year'],
                'source' => $record['source'],
                'locked' => true,
                'applied_on' => (string) $record['application_date'],
            ];
        } elseif (!isset($taken[(int) $record['id']])) {
            $untaggedIdsByKey[$key][] = (int) $record['id'];
        }
    }

    $kept = [];
    $skipped = 0;
    foreach ($newRows as $row) {
        $key = duplicate_group_key($row);
        if (isset($taggedKeys[$key])) {
            $skipped++;
            continue;
        }
        if (!empty($untaggedIdsByKey[$key])) {
            $row['id'] = array_shift($untaggedIdsByKey[$key]);
        }
        $kept[] = $row;
    }

    return ['rows' => $addLocked ? array_merge($locked, $kept) : $kept, 'kept' => count($locked), 'skipped' => $skipped];
}
function set_staged_row(int $uploadId, ?int $rowIndex, array $data): array
{
    $review = get_extraction_review($uploadId);
    if ($review === null) {
        return ['ok' => false, 'error' => 'NO ACTIVE REVIEW SESSION FOR THIS UPLOAD.'];
    }

    $row = [
        'graduate_name' => trim((string) ($data['graduate_name'] ?? '')),
        'course' => trim((string) ($data['course'] ?? '')),
        'latin_honor' => (string) ($data['latin_honor'] ?? ''),
        'graduation_date' => (($data['graduation_date'] ?? '') !== '') ? $data['graduation_date'] : null,
        'graduation_year' => resolve_graduation_year(($data['graduation_date'] ?? '') !== '' ? $data['graduation_date'] : null, $data['graduation_year'] ?? 0),
        'source' => 'manual',
    ];

    $rows = $review['rows'];
    if ($rowIndex !== null && array_key_exists($rowIndex, $rows)) {
        if (staged_row_is_locked($rows[$rowIndex])) {
            return ['ok' => false, 'error' => STAGED_ROW_LOCKED_MESSAGE];
        }
        // Carries the DB id forward when editing a row that was seeded from
        // an already-saved upload (see get_graduates_for_upload()), so
        // save_staged_records() can UPDATE it in place instead of inserting
        // a duplicate. Newly added rows never get an id.
        if (isset($rows[$rowIndex]['id'])) {
            $row['id'] = $rows[$rowIndex]['id'];
        }
        $rows[$rowIndex] = $row;
    } else {
        $rows[] = $row;
    }

    $_SESSION['extraction_review']['rows'] = $rows;

    return ['ok' => true];
}

function remove_staged_row(int $uploadId, int $rowIndex): array
{
    $review = get_extraction_review($uploadId);
    if ($review === null) {
        return ['ok' => false, 'error' => 'NO ACTIVE REVIEW SESSION FOR THIS UPLOAD.'];
    }

    $rows = $review['rows'];
    if (!array_key_exists($rowIndex, $rows)) {
        return ['ok' => false, 'error' => 'RECORD NOT FOUND.'];
    }

    if (staged_row_is_locked($rows[$rowIndex])) {
        return ['ok' => false, 'error' => STAGED_ROW_LOCKED_MESSAGE];
    }

    unset($rows[$rowIndex]);
    $_SESSION['extraction_review']['rows'] = array_values($rows);

    return ['ok' => true];
}

/**
 * @param int[] $rowIndexes
 */
function remove_staged_rows(int $uploadId, array $rowIndexes): array
{
    $review = get_extraction_review($uploadId);
    if ($review === null) {
        return ['ok' => false, 'error' => 'NO ACTIVE REVIEW SESSION FOR THIS UPLOAD.'];
    }

    $rows = $review['rows'];
    $validIndexes = array_filter(
        array_unique(array_map('intval', $rowIndexes)),
        static fn (int $index): bool => array_key_exists($index, $rows)
    );

    if (empty($validIndexes)) {
        return ['ok' => false, 'error' => 'NO VALID RECORDS WERE SELECTED.'];
    }

    foreach ($validIndexes as $index) {
        if (staged_row_is_locked($rows[$index])) {
            return ['ok' => false, 'error' => STAGED_ROW_LOCKED_MESSAGE];
        }
    }

    foreach ($validIndexes as $index) {
        unset($rows[$index]);
    }
    $_SESSION['extraction_review']['rows'] = array_values($rows);

    return ['ok' => true, 'removed' => count($validIndexes)];
}

/**
 * Sets the date of graduation (and the year derived from it) on every
 * staged row at once — for when a PDF's list never had a date column at
 * all, so every row would otherwise need the same date typed in by hand.
 */
function mass_update_staged_graduation_date(int $uploadId, string $date): array
{
    $review = get_extraction_review($uploadId);
    if ($review === null) {
        return ['ok' => false, 'error' => 'NO ACTIVE REVIEW SESSION FOR THIS UPLOAD.'];
    }

    $dt = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dt || $dt->format('Y-m-d') !== $date) {
        return ['ok' => false, 'error' => 'DATE OF GRADUATION MUST BE A VALID DATE.'];
    }

    $rows = $review['rows'];
    if (empty($rows)) {
        return ['ok' => false, 'error' => 'THERE ARE NO RECORDS TO UPDATE.'];
    }

    $year = (int) $dt->format('Y');
    $updated = 0;
    foreach ($rows as &$row) {
        // A record with a filed application keeps its own date.
        if (staged_row_is_locked($row)) {
            continue;
        }
        $row['graduation_date'] = $date;
        $row['graduation_year'] = $year;
        $updated++;
    }
    unset($row);

    if ($updated === 0) {
        return ['ok' => false, 'error' => 'THERE ARE NO RECORDS TO UPDATE.'];
    }

    $_SESSION['extraction_review']['rows'] = $rows;

    return ['ok' => true, 'updated' => $updated];
}

/**
 * Saves the reviewed rows for an upload. Rows carrying an 'id' (seeded from
 * get_graduates_for_upload() when re-opening an already-reviewed upload for
 * editing) are UPDATEd in place; rows without one are INSERTed as new. Any
 * existing DB row for this upload whose id no longer appears in $rows is
 * DELETEd, so removing a row during a re-review actually removes it. This
 * makes the function safe for both a first-time save (no existing rows, so
 * every row inserts exactly as before) and a later edit of saved records.
 */
function save_staged_records(PDO $pdo, int $uploadId, int $schoolId, array $rows, ?int $savedBy = null): array
{
    if (empty($rows)) {
        return ['status' => 'error', 'message' => 'THERE ARE NO RECORDS TO SAVE.'];
    }

    $incomplete = [];
    foreach ($rows as $index => $row) {
        if (staged_row_is_locked($row)) {
            continue;
        }
        if (staged_row_status($row) !== 'VALID') {
            $incomplete[] = $index + 1;
        }
    }

    if (!empty($incomplete)) {
        return [
            'status' => 'error',
            'message' => 'RECORD(S) ' . implode(', ', $incomplete) . ' ARE INCOMPLETE. PLEASE FIX THEM BEFORE SAVING.',
        ];
    }

    try {
        $pdo->beginTransaction();

        $existingIdsStmt = $pdo->prepare('SELECT id FROM latin_honor_graduates WHERE upload_id = :upload_id');
        $existingIdsStmt->execute(['upload_id' => $uploadId]);
        $existingIds = array_map('intval', $existingIdsStmt->fetchAll(PDO::FETCH_COLUMN));

        $insertStmt = $pdo->prepare(
            'INSERT INTO latin_honor_graduates (upload_id, school_id, graduate_name, course, latin_honor, graduation_date, graduation_year, source)
             VALUES (:upload_id, :school_id, :graduate_name, :course, :latin_honor, :graduation_date, :graduation_year, :source)'
        );
        $updateStmt = $pdo->prepare(
            'UPDATE latin_honor_graduates
             SET graduate_name = :graduate_name, course = :course, latin_honor = :latin_honor,
                 graduation_date = :graduation_date, graduation_year = :graduation_year, source = :source
             WHERE id = :id AND upload_id = :upload_id'
        );

        $keptIds = [];
        foreach ($rows as $row) {
            if (staged_row_is_locked($row)) {
                // A record with a filed application: kept exactly as saved - never updated or removed here.
                $lockedId = isset($row['id']) ? (int) $row['id'] : 0;
                if ($lockedId > 0 && in_array($lockedId, $existingIds, true)) {
                    $keptIds[] = $lockedId;
                }
                continue;
            }

            $fields = [
                'graduate_name' => $row['graduate_name'],
                'course' => $row['course'] ?? '',
                'latin_honor' => $row['latin_honor'],
                'graduation_date' => $row['graduation_date'],
                // whatever put the row here (AI, text, OCR, Excel, an edit), the year follows the date
                'graduation_year' => resolve_graduation_year($row['graduation_date'] ?? null, $row['graduation_year']),
                'source' => $row['source'] ?? null,
            ];

            $rowId = isset($row['id']) ? (int) $row['id'] : 0;
            if ($rowId > 0 && in_array($rowId, $existingIds, true)) {
                $updateStmt->execute($fields + ['id' => $rowId, 'upload_id' => $uploadId]);
                $keptIds[] = $rowId;
            } else {
                $insertStmt->execute($fields + ['upload_id' => $uploadId, 'school_id' => $schoolId]);
                $keptIds[] = (int) $pdo->lastInsertId();            }
        }

        $removedIds = array_diff($existingIds, $keptIds);
        if (!empty($removedIds)) {
            foreach ($removedIds as $removedId) {
                archive_graduate($pdo, (int) $removedId, $savedBy, 'REMOVED DURING RE-REVIEW OF SAVED RECORDS', 'review_edit');
            }
            $placeholders = implode(',', array_fill(0, count($removedIds), '?'));
            $pdo->prepare("DELETE FROM latin_honor_graduates WHERE id IN ({$placeholders})")->execute(array_values($removedIds));
        }

        update_upload_extraction_status($pdo, $uploadId, 'reviewed', count($rows));
        $pdo->prepare('DELETE FROM extraction_review_drafts WHERE upload_id = :upload_id')->execute(['upload_id' => $uploadId]);

        $pdo->commit();
    } catch (\Throwable $e) {
        try {
            $pdo->rollBack();
        } catch (\Throwable $rollbackError) {
            error_log('save_staged_records rollback failed: ' . $rollbackError->getMessage());
        }
        error_log('save_staged_records failed: ' . $e->getMessage());

        if ($e instanceof \PDOException && (int) $e->getCode() === 23000) {
            return [
                'status' => 'error',
                'message' => 'ONE OR MORE REMOVED RECORDS ALREADY HAVE A FILED APPLICATION AND CANNOT BE DELETED THIS WAY.',
            ];
        }

        return ['status' => 'error', 'message' => 'THE RECORDS COULD NOT BE SAVED. PLEASE TRY AGAIN.'];
    }

    return ['status' => 'success', 'message' => count($rows) . ' RECORD(S) SAVED SUCCESSFULLY.', 'removed_count' => count($removedIds ?? [])];
}

/**
 * Persists the in-progress review as a draft, kept entirely out of latin_honor_graduates
 * (so it is never mixed with official records) until a future SAVE RECORDS finalizes it.
 */
function save_extraction_draft(PDO $pdo, int $uploadId, int $savedBy, array $rows): array
{
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO extraction_review_drafts (upload_id, rows_json, saved_by)
             VALUES (:upload_id, :rows_json, :saved_by)
             ON DUPLICATE KEY UPDATE rows_json = VALUES(rows_json), saved_by = VALUES(saved_by)'
        );
        $stmt->execute([
            'upload_id' => $uploadId,
            'rows_json' => json_encode(array_values($rows)),
            'saved_by' => $savedBy,
        ]);

        update_upload_extraction_status($pdo, $uploadId, 'draft', count_saved_graduates($pdo, $uploadId));
    } catch (\Throwable $e) {
        error_log('save_extraction_draft failed: ' . $e->getMessage());
        return ['status' => 'error', 'message' => 'THE DRAFT COULD NOT BE SAVED. PLEASE TRY AGAIN.'];
    }

    return ['status' => 'success', 'message' => count($rows) . ' RECORD(S) SAVED AS DRAFT. YOU CAN RESUME THIS REVIEW LATER.'];
}

/**
 * Appends newly-extracted rows onto an upload's existing draft (if any),
 * reusing save_extraction_draft() for the actual persistence. Used by the
 * chunked AI extraction flow (api/extract_pdf.php), where each
 * successfully-extracted page range adds its rows to what prior chunks
 * already staged, rather than replacing them.
 */
function append_extraction_draft_rows(PDO $pdo, int $uploadId, int $savedBy, array $newRows): array
{
    $existing = get_extraction_draft($pdo, $uploadId);
    $rows = $existing !== null ? $existing['rows'] : [];

    return save_extraction_draft($pdo, $uploadId, $savedBy, array_merge($rows, $newRows));
}

function get_extraction_draft(PDO $pdo, int $uploadId): ?array
{
    $stmt = $pdo->prepare('SELECT rows_json, updated_at FROM extraction_review_drafts WHERE upload_id = :upload_id');
    $stmt->execute(['upload_id' => $uploadId]);
    $row = $stmt->fetch();

    if ($row === false) {
        return null;
    }

    return [
        'rows' => json_decode($row['rows_json'], true) ?? [],
        'updated_at' => $row['updated_at'],
    ];
}

function delete_extraction_draft(PDO $pdo, int $uploadId): void
{
    $pdo->prepare('DELETE FROM extraction_review_drafts WHERE upload_id = :upload_id')->execute(['upload_id' => $uploadId]);
}

/**
 * Adds rows to the end of an upload's staged review (the bulk upload from
 * Excel on admin/review.php). Existing staged rows are left untouched.
 *
 * @param array<int, array> $newRows  staged rows (see set_staged_row())
 */
function append_staged_rows(int $uploadId, array $newRows): array
{
    $review = get_extraction_review($uploadId);
    if ($review === null) {
        return ['ok' => false, 'error' => 'NO ACTIVE REVIEW SESSION FOR THIS UPLOAD.'];
    }

    $_SESSION['extraction_review']['rows'] = array_merge($review['rows'], array_values($newRows));

    return ['ok' => true, 'added' => count($newRows)];
}
