<?php

function find_existing_upload(PDO $pdo, int $schoolId, string $schoolYear): ?array
{
    $stmt = $pdo->prepare(
        'SELECT u.id, u.school_id, u.school_year, u.original_filename, u.stored_filename,
                u.uploaded_at, u.records_extracted, s.school_name
         FROM latin_honor_uploads u
         JOIN schools s ON s.id = u.school_id
         WHERE u.school_id = :school_id AND u.school_year = :school_year
         ORDER BY u.upload_number DESC
         LIMIT 1'
    );
    $stmt->execute(['school_id' => $schoolId, 'school_year' => $schoolYear]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

function next_upload_number(PDO $pdo, int $schoolId, string $schoolYear): int
{
    $stmt = $pdo->prepare(
        'SELECT COALESCE(MAX(upload_number), 0) FROM latin_honor_uploads WHERE school_id = :school_id AND school_year = :school_year'
    );
    $stmt->execute(['school_id' => $schoolId, 'school_year' => $schoolYear]);

    return ((int) $stmt->fetchColumn()) + 1;
}

function create_upload_record(PDO $pdo, array $data): array
{
    $stmt = $pdo->prepare(
        'INSERT INTO latin_honor_uploads
            (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number)
         VALUES
            (:school_id, :school_year, :original_filename, :stored_filename, :file_path, :file_size, :file_hash, :uploaded_by, :upload_number)'
    );
    $stmt->execute([
        'school_id' => $data['school_id'],
        'school_year' => $data['school_year'],
        'original_filename' => $data['original_filename'],
        'stored_filename' => $data['stored_filename'],
        'file_path' => $data['file_path'],
        'file_size' => $data['file_size'],
        'file_hash' => $data['file_hash'],
        'uploaded_by' => $data['uploaded_by'],
        'upload_number' => $data['upload_number'],
    ]);

    return [
        'id' => (int) $pdo->lastInsertId(),
        'school_id' => $data['school_id'],
        'school_year' => $data['school_year'],
        'original_filename' => $data['original_filename'],
        'stored_filename' => $data['stored_filename'],
        'file_path' => $data['file_path'],
        'file_size' => $data['file_size'],
        'file_hash' => $data['file_hash'],
        'uploaded_by' => $data['uploaded_by'],
        'upload_number' => $data['upload_number'],
        'extraction_status' => 'pending',
        'records_extracted' => 0,
    ];
}

function get_recent_uploads(PDO $pdo, int $limit = 5): array
{
    $stmt = $pdo->prepare(
        'SELECT u.id, u.school_year, u.original_filename, u.stored_filename, u.uploaded_at, u.records_extracted, s.school_name, s.campus
         FROM latin_honor_uploads u
         JOIN schools s ON s.id = u.school_id
         ORDER BY (u.records_extracted = 0) DESC,
                  CASE WHEN u.records_extracted = 0 THEN u.uploaded_at END ASC,
                  u.uploaded_at DESC, u.id DESC
         LIMIT :limit'
    );
    $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

function get_upload_by_id(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT u.id, u.school_id, u.school_year, u.original_filename, u.stored_filename, u.file_path,
                u.file_size, u.uploaded_at, u.extraction_status, u.records_extracted, s.school_name, s.campus
         FROM latin_honor_uploads u
         JOIN schools s ON s.id = u.school_id
         WHERE u.id = :id
         LIMIT 1'
    );
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

function handle_upload_request(PDO $pdo, array $post, array $files, int $uploadedBy, string $storageDir, int $maxBytes = 20971520): array
{
    $schoolId = (int) ($post['school_id'] ?? 0);
    $schoolName = trim((string) ($post['school_name'] ?? ''));
    $schoolYear = trim((string) ($post['school_year'] ?? ''));
    $confirmDuplicate = !empty($post['confirm_duplicate']);

    if (($schoolId <= 0 && $schoolName === '') || $schoolYear === '') {
        return ['status' => 'error', 'message' => 'SCHOOL NAME AND SCHOOL YEAR ARE REQUIRED.'];
    }

    if ($schoolId <= 0 && mb_strlen($schoolName) > 255) {
        return ['status' => 'error', 'message' => 'SCHOOL NAME IS TOO LONG.'];
    }

    if (mb_strlen($schoolYear) > 9) {
        return ['status' => 'error', 'message' => 'SCHOOL YEAR IS TOO LONG.'];
    }

    if (!ctype_digit($schoolYear)) {
        return ['status' => 'error', 'message' => 'SCHOOL YEAR MUST CONTAIN NUMBERS ONLY.'];
    }

    $file = $files['pdf_file'] ?? null;
    if (!is_array($file) || ($file['tmp_name'] ?? '') === '') {
        return ['status' => 'error', 'message' => 'PLEASE SELECT A PDF FILE TO UPLOAD.'];
    }

    $size = filesize($file['tmp_name']);
    $validation = validate_pdf_file_content($file['tmp_name'], $size === false ? PHP_INT_MAX : $size, $maxBytes);
    if (!$validation['ok']) {
        return ['status' => 'error', 'message' => $validation['error']];
    }

    if ($schoolId > 0) {
        $school = get_school_by_id($pdo, $schoolId);
        if ($school === null) {
            return ['status' => 'error', 'message' => 'SELECTED SCHOOL COULD NOT BE FOUND. PLEASE REFRESH THE PAGE AND TRY AGAIN.'];
        }
    } else {
        $school = find_or_create_school($pdo, $schoolName);
        $school['campus'] = $school['campus'] ?? '';
    }

    $existing = find_existing_upload($pdo, $school['id'], $schoolYear);
    if ($existing !== null && !$confirmDuplicate) {
        return [
            'status' => 'duplicate',
            'message' => 'A LATIN HONOR LIST FOR THIS SCHOOL AND SCHOOL YEAR ALREADY EXISTS.',
            'existing_upload' => $existing,
        ];
    }

    $uploadNumber = next_upload_number($pdo, $school['id'], $schoolYear);
    $storedFilename = build_stored_filename($school['school_name'], $schoolYear, $uploadNumber, (string) ($school['campus'] ?? ''));

    $resolvedStorageDir = realpath($storageDir);
    if ($resolvedStorageDir === false) {
        return ['status' => 'error', 'message' => 'THE FILE COULD NOT BE SAVED. PLEASE TRY AGAIN.'];
    }
    $destination = $resolvedStorageDir . DIRECTORY_SEPARATOR . $storedFilename;

    if (file_exists($destination)) {
        return [
            'status' => 'error',
            'message' => 'A FILE WITH THIS NAME ALREADY EXISTS FOR A DIFFERENT SCHOOL. PLEASE CONTACT AN ADMINISTRATOR.',
        ];
    }

    if (!copy($file['tmp_name'], $destination)) {
        return ['status' => 'error', 'message' => 'THE FILE COULD NOT BE SAVED. PLEASE TRY AGAIN.'];
    }

    try {
        $upload = create_upload_record($pdo, [
            'school_id' => $school['id'],
            'school_year' => $schoolYear,
            'original_filename' => (string) ($file['name'] ?? $storedFilename),
            'stored_filename' => $storedFilename,
            'file_path' => $destination,
            'file_size' => filesize($destination),
            'file_hash' => hash_file('sha256', $destination),
            'uploaded_by' => $uploadedBy,
            'upload_number' => $uploadNumber,
        ]);
    } catch (\Throwable $e) {
        error_log('create_upload_record failed after file copy: ' . $e->getMessage());
        @unlink($destination);
        return ['status' => 'error', 'message' => 'THE RECORD COULD NOT BE SAVED. PLEASE TRY AGAIN.'];
    }

    return [
        'status' => 'success',
        'message' => 'LATIN HONOR LIST UPLOADED SUCCESSFULLY.',
        'upload' => $upload,
    ];
}

/**
 * @return array{ok: bool, error?: string}
 */
function delete_upload(PDO $pdo, int $uploadId): array
{
    $upload = get_upload_by_id($pdo, $uploadId);
    if ($upload === null) {
        return ['ok' => false, 'error' => 'UPLOAD RECORD NOT FOUND.'];
    }

    if ((int) $upload['records_extracted'] > 0) {
        return ['ok' => false, 'error' => 'THIS UPLOAD HAS EXTRACTED RECORDS AND CANNOT BE DELETED.'];
    }

    try {
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM latin_honor_graduates WHERE upload_id = :id')->execute(['id' => $uploadId]);
        $pdo->prepare('DELETE FROM extraction_review_drafts WHERE upload_id = :id')->execute(['id' => $uploadId]);
        $pdo->prepare('DELETE FROM latin_honor_uploads WHERE id = :id')->execute(['id' => $uploadId]);
        $pdo->commit();
    } catch (\Throwable $e) {
        try {
            $pdo->rollBack();
        } catch (\Throwable $rollbackError) {
            error_log('delete_upload rollback failed: ' . $rollbackError->getMessage());
        }
        error_log('delete_upload failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'THE UPLOAD COULD NOT BE DELETED. PLEASE TRY AGAIN.'];
    }

    if (!empty($upload['file_path']) && is_file($upload['file_path'])) {
        @unlink($upload['file_path']);
    }

    return ['ok' => true];
}

function update_upload_extraction_status(PDO $pdo, int $uploadId, string $status, int $recordsExtracted): void
{
    $stmt = $pdo->prepare(
        'UPDATE latin_honor_uploads SET extraction_status = :status, records_extracted = :records WHERE id = :id'
    );
    $stmt->execute([
        'status' => $status,
        'records' => $recordsExtracted,
        'id' => $uploadId,
    ]);
}
