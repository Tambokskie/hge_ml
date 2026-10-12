<?php

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/recycle_bin_items.php';

function get_ocr_course_keywords(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT id, keyword, created_at FROM ocr_course_keywords ORDER BY keyword ASC');
    return $stmt->fetchAll();
}

function add_ocr_course_keyword(PDO $pdo, string $keyword, int $userId): array
{
    $keyword = mb_strtoupper(trim($keyword));

    if ($keyword === '') {
        return ['status' => 'error', 'message' => 'COURSE/DEGREE KEYWORD IS REQUIRED.'];
    }

    if (mb_strlen($keyword) > 50) {
        return ['status' => 'error', 'message' => 'COURSE/DEGREE KEYWORD IS TOO LONG.'];
    }

    // Kept deliberately narrow: this string is compiled straight into the
    // OCR course-detection regex (preg_quote()'d, so it can never break the
    // pattern) — restricting the character set here is about keeping every
    // stored keyword a plausible degree/course abbreviation, not escaping.
    if (preg_match('/^[A-Z0-9.\- ]+$/', $keyword) !== 1) {
        return ['status' => 'error', 'message' => 'COURSE/DEGREE KEYWORD MAY ONLY CONTAIN LETTERS, NUMBERS, PERIODS, HYPHENS, AND SPACES.'];
    }

    try {
        $stmt = $pdo->prepare('INSERT INTO ocr_course_keywords (keyword, created_by) VALUES (:keyword, :created_by)');
        $stmt->execute(['keyword' => $keyword, 'created_by' => $userId]);
    } catch (\PDOException $e) {
        if ($e->getCode() === '23000') {
            return ['status' => 'error', 'message' => 'THIS COURSE/DEGREE KEYWORD ALREADY EXISTS.'];
        }
        throw $e;
    }

    return ['status' => 'success', 'message' => 'COURSE/DEGREE KEYWORD ADDED.', 'id' => (int) $pdo->lastInsertId(), 'keyword' => $keyword];
}

function remove_ocr_course_keyword(PDO $pdo, int $id, ?int $userId = null): array
{
    $stmt = $pdo->prepare('SELECT * FROM ocr_course_keywords WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    if ($row === false) {
        return ['status' => 'error', 'message' => 'COURSE/DEGREE KEYWORD NOT FOUND.'];
    }
    $keyword = (string) $row['keyword'];

    // Moved to the recycle bin, not destroyed.
    try {
        $pdo->beginTransaction();
        archive_item($pdo, 'ocr_keyword', $id, $keyword, 'OCR COURSE/DEGREE KEYWORD', ['keyword' => $row], $userId, 'KEYWORD REMOVED');
        $pdo->prepare('DELETE FROM ocr_course_keywords WHERE id = :id')->execute(['id' => $id]);
        $pdo->commit();
    } catch (\Throwable $e) {
        try {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (\Throwable $rollbackError) {
            error_log('remove_ocr_course_keyword rollback failed: ' . $rollbackError->getMessage());
        }
        error_log('remove_ocr_course_keyword failed: ' . $e->getMessage());

        return ['status' => 'error', 'message' => recycle_bin_missing_table_message($e) ?? 'THE KEYWORD COULD NOT BE REMOVED. PLEASE TRY AGAIN.'];
    }

    return ['status' => 'success', 'message' => 'COURSE/DEGREE KEYWORD REMOVED.', 'keyword' => $keyword];
}
