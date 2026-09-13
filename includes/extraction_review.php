<?php

function derive_fallback_year(string $schoolYear): ?int
{
    if (preg_match_all('/\d{4}/', $schoolYear, $matches) && !empty($matches[0])) {
        return (int) end($matches[0]);
    }

    return null;
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
    if (!is_numeric($year) || (int) $year < 1900 || (int) $year > 2100) {
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
        'latin_honor' => (string) ($data['latin_honor'] ?? ''),
        'graduation_date' => (($data['graduation_date'] ?? '') !== '') ? $data['graduation_date'] : null,
        'graduation_year' => (int) ($data['graduation_year'] ?? 0),
    ];

    $rows = $review['rows'];
    if ($rowIndex !== null && array_key_exists($rowIndex, $rows)) {
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

        $stmt = $pdo->prepare(
            'INSERT INTO latin_honor_graduates (upload_id, school_id, graduate_name, latin_honor, graduation_date, graduation_year)
             VALUES (:upload_id, :school_id, :graduate_name, :latin_honor, :graduation_date, :graduation_year)'
        );

        foreach ($rows as $row) {
            $stmt->execute([
                'upload_id' => $uploadId,
                'school_id' => $schoolId,
                'graduate_name' => $row['graduate_name'],
                'latin_honor' => $row['latin_honor'],
                'graduation_date' => $row['graduation_date'],
                'graduation_year' => $row['graduation_year'],
            ]);
        }

        update_upload_extraction_status($pdo, $uploadId, 'reviewed', count($rows));

        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        error_log('save_staged_records failed: ' . $e->getMessage());
        return ['status' => 'error', 'message' => 'THE RECORDS COULD NOT BE SAVED. PLEASE TRY AGAIN.'];
    }

    return ['status' => 'success', 'message' => count($rows) . ' RECORD(S) SAVED SUCCESSFULLY.'];
}
