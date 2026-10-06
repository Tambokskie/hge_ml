<?php

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
        'graduation_year' => (int) ($data['graduation_year'] ?? 0),
        'source' => 'manual',
    ];

    $rows = $review['rows'];
    if ($rowIndex !== null && array_key_exists($rowIndex, $rows)) {
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
    foreach ($rows as &$row) {
        $row['graduation_date'] = $date;
        $row['graduation_year'] = $year;
    }
    unset($row);

    $_SESSION['extraction_review']['rows'] = $rows;

    return ['ok' => true, 'updated' => count($rows)];
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
function save_staged_records(PDO $pdo, int $uploadId, int $schoolId, array $rows): array
{
    if (empty($rows)) {
        return ['status' => 'error', 'message' => 'THERE ARE NO RECORDS TO SAVE.'];
    }

    $incomplete = [];
    foreach ($rows as $index => $row) {
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
            $fields = [
                'graduate_name' => $row['graduate_name'],
                'course' => $row['course'] ?? '',
                'latin_honor' => $row['latin_honor'],
                'graduation_date' => $row['graduation_date'],
                'graduation_year' => $row['graduation_year'],
                'source' => $row['source'] ?? null,
            ];

            $rowId = isset($row['id']) ? (int) $row['id'] : 0;
            if ($rowId > 0 && in_array($rowId, $existingIds, true)) {
                $updateStmt->execute($fields + ['id' => $rowId, 'upload_id' => $uploadId]);
                $keptIds[] = $rowId;
            } else {
                $insertStmt->execute($fields + ['upload_id' => $uploadId, 'school_id' => $schoolId]);
                $keptIds[] = (int) $pdo->lastInsertId();
            }
        }

        $removedIds = array_diff($existingIds, $keptIds);
        if (!empty($removedIds)) {
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

    return ['status' => 'success', 'message' => count($rows) . ' RECORD(S) SAVED SUCCESSFULLY.'];
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

        update_upload_extraction_status($pdo, $uploadId, 'draft', 0);
    } catch (\Throwable $e) {
        error_log('save_extraction_draft failed: ' . $e->getMessage());
        return ['status' => 'error', 'message' => 'THE DRAFT COULD NOT BE SAVED. PLEASE TRY AGAIN.'];
    }

    return ['status' => 'success', 'message' => count($rows) . ' RECORD(S) SAVED AS DRAFT. YOU CAN RESUME THIS REVIEW LATER.'];
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
