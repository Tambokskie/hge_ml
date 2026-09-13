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
