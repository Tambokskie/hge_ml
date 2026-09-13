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
