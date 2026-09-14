<?php

require_once __DIR__ . '/functions.php';

function search_activity_log(PDO $pdo, array $filters, int $page, int $perPage = 25): array
{
    $conditions = [];
    $params = [];

    if (!empty($filters['user_id'])) {
        $conditions[] = 'a.user_id = :user_id';
        $params['user_id'] = (int) $filters['user_id'];
    }

    if (!empty($filters['action'])) {
        $conditions[] = 'a.action = :action';
        $params['action'] = (string) $filters['action'];
    }

    $dateFrom = trim((string) ($filters['date_from'] ?? ''));
    if ($dateFrom !== '') {
        $conditions[] = 'a.created_at >= :date_from';
        $params['date_from'] = $dateFrom . ' 00:00:00';
    }

    $dateTo = trim((string) ($filters['date_to'] ?? ''));
    if ($dateTo !== '') {
        $conditions[] = 'a.created_at <= :date_to';
        $params['date_to'] = $dateTo . ' 23:59:59';
    }

    $whereSql = empty($conditions) ? '' : ('WHERE ' . implode(' AND ', $conditions));

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM activity_log a {$whereSql}");
    $countStmt->execute($params);
    $totalRows = (int) $countStmt->fetchColumn();

    $pagination = paginate($totalRows, $page, $perPage);

    $sql = "SELECT a.id, a.user_id, a.action, a.details, a.created_at, u.name AS user_name, u.username
            FROM activity_log a
            LEFT JOIN users u ON u.id = a.user_id
            {$whereSql}
            ORDER BY a.created_at DESC
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
