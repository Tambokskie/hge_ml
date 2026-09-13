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
        'SELECT u.id, u.school_year, u.original_filename, u.stored_filename, u.uploaded_at, u.records_extracted, s.school_name
         FROM latin_honor_uploads u
         JOIN schools s ON s.id = u.school_id
         ORDER BY u.uploaded_at DESC, u.id DESC
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
                u.file_size, u.uploaded_at, u.extraction_status, u.records_extracted, s.school_name
         FROM latin_honor_uploads u
         JOIN schools s ON s.id = u.school_id
         WHERE u.id = :id
         LIMIT 1'
    );
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}
