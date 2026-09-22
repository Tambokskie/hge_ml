<?php

require_once __DIR__ . '/functions.php';

function search_graduates(PDO $pdo, array $filters, int $page, int $perPage = 25): array
{
    $conditions = [];
    $params = [];

    if (!empty($filters['school_id'])) {
        $conditions[] = 'g.school_id = :school_id';
        $params['school_id'] = (int) $filters['school_id'];
    }

    if (!empty($filters['graduation_year'])) {
        $conditions[] = 'g.graduation_year = :graduation_year';
        $params['graduation_year'] = (int) $filters['graduation_year'];
    }

    $name = trim((string) ($filters['name'] ?? ''));
    if ($name !== '') {
        $conditions[] = 'g.graduate_name LIKE :name';
        $params['name'] = '%' . addcslashes($name, '\\%_') . '%';
    }

    $whereSql = empty($conditions) ? '' : ('WHERE ' . implode(' AND ', $conditions));

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM latin_honor_graduates g {$whereSql}");
    $countStmt->execute($params);
    $totalRows = (int) $countStmt->fetchColumn();

    $pagination = paginate($totalRows, $page, $perPage);

    $sql = "SELECT g.id, g.graduate_name, g.course, g.latin_honor, g.graduation_date, g.graduation_year,
                   s.school_name, u.id AS upload_id, u.original_filename, u.uploaded_at,
                   a.application_date, (a.id IS NOT NULL) AS has_applied
            FROM latin_honor_graduates g
            JOIN schools s ON s.id = g.school_id
            JOIN latin_honor_uploads u ON u.id = g.upload_id
            LEFT JOIN graduate_applications a ON a.graduate_id = g.id
            {$whereSql}
            ORDER BY g.graduate_name ASC
            LIMIT :limit OFFSET :offset";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->bindValue('limit', $pagination['per_page'], PDO::PARAM_INT);
    $stmt->bindValue('offset', $pagination['offset'], PDO::PARAM_INT);
    $stmt->execute();

    return ['rows' => $stmt->fetchAll(), 'pagination' => $pagination];
}

function get_graduate_by_id(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, upload_id, school_id, graduate_name, course, latin_honor, graduation_date, graduation_year
         FROM latin_honor_graduates WHERE id = :id LIMIT 1'
    );
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

function update_graduate(PDO $pdo, int $id, array $data): void
{
    $stmt = $pdo->prepare(
        'UPDATE latin_honor_graduates
         SET graduate_name = :graduate_name, course = :course, latin_honor = :latin_honor,
             graduation_date = :graduation_date, graduation_year = :graduation_year
         WHERE id = :id'
    );
    $stmt->execute([
        'graduate_name' => $data['graduate_name'],
        'course' => $data['course'],
        'latin_honor' => $data['latin_honor'],
        'graduation_date' => $data['graduation_date'],
        'graduation_year' => $data['graduation_year'],
        'id' => $id,
    ]);
}

/**
 * @return array{ok: bool, error?: string}
 */
function file_application(PDO $pdo, int $graduateId, int $appliedBy, string $applicationDate): array
{
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO graduate_applications (graduate_id, applied_by, application_date)
             VALUES (:graduate_id, :applied_by, :application_date)'
        );
        $stmt->execute([
            'graduate_id' => $graduateId,
            'applied_by' => $appliedBy,
            'application_date' => $applicationDate,
        ]);
    } catch (\PDOException $e) {
        if ((int) $e->getCode() === 23000) {
            return ['ok' => false, 'error' => 'THIS GRADUATE HAS ALREADY FILED AN APPLICATION.'];
        }
        throw $e;
    }

    return ['ok' => true];
}

function build_search_log_details(array $filters): string
{
    $parts = [];

    if (!empty($filters['school_id'])) {
        $parts[] = 'school_id=' . (int) $filters['school_id'];
    }

    if (!empty($filters['graduation_year'])) {
        $parts[] = 'year=' . (int) $filters['graduation_year'];
    }

    $name = trim((string) ($filters['name'] ?? ''));
    if ($name !== '') {
        $parts[] = 'name=' . $name;
    }

    return implode(', ', $parts);
}
