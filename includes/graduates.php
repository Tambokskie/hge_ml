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

    $sql = "SELECT g.id, g.graduate_name, g.latin_honor, g.graduation_date, g.graduation_year,
                   s.school_name, u.id AS upload_id, u.original_filename, u.uploaded_at
            FROM latin_honor_graduates g
            JOIN schools s ON s.id = g.school_id
            JOIN latin_honor_uploads u ON u.id = g.upload_id
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
