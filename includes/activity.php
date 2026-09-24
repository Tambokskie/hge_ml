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

    return ['rows' => resolve_legacy_graduate_details($pdo, $stmt->fetchAll()), 'pagination' => $pagination];
}

function get_recent_activity(PDO $pdo, int $limit = 6): array
{
    $stmt = $pdo->prepare(
        "SELECT a.id, a.user_id, a.action, a.details, a.created_at, u.name AS user_name
         FROM activity_log a
         LEFT JOIN users u ON u.id = a.user_id
         WHERE a.action != 'search'
         ORDER BY a.created_at DESC
         LIMIT :limit"
    );
    $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return resolve_legacy_graduate_details($pdo, $stmt->fetchAll());
}

function resolve_legacy_graduate_details(PDO $pdo, array $rows): array
{
    $legacyIds = [];
    foreach ($rows as $row) {
        if (preg_match('/^graduate_id=(\d+)$/', (string) ($row['details'] ?? ''), $matches) === 1) {
            $legacyIds[(int) $matches[1]] = true;
        }
    }

    if (empty($legacyIds)) {
        return $rows;
    }

    $ids = array_keys($legacyIds);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id, graduate_name FROM latin_honor_graduates WHERE id IN ({$placeholders})");
    $stmt->execute($ids);
    $names = array_column($stmt->fetchAll(), 'graduate_name', 'id');

    foreach ($rows as &$row) {
        if (preg_match('/^graduate_id=(\d+)$/', (string) ($row['details'] ?? ''), $matches) === 1) {
            $id = (int) $matches[1];
            $row['details'] = 'graduate_name=' . ($names[$id] ?? 'UNKNOWN (RECORD DELETED)');
        }
    }
    unset($row);

    return $rows;
}

function activity_icon_path(string $action): string
{
    if (in_array($action, ['login', 'logout'], true)) {
        return 'M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l3 3m0 0-3 3m3-3H3';
    }

    if (in_array($action, ['pdf_upload', 'pdf_delete', 'template_upload'], true)) {
        return 'M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 8.25 12 3.75m0 0L7.5 8.25M12 3.75v12';
    }

    if (str_starts_with($action, 'record_')) {
        return 'm16.862 4.487 1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931Zm0 0L19.5 7.125';
    }

    if (str_starts_with($action, 'user_')) {
        return 'M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z';
    }

    if (str_starts_with($action, 'school_')) {
        return 'M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21';
    }

    if ($action === 'application_filed') {
        return 'M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z';
    }

    return 'M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z';
}
