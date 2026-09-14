<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/activity.php';

require_role('administrator');

header('Content-Type: application/json');

$userId = (isset($_GET['user_id']) && (int) $_GET['user_id'] > 0) ? (int) $_GET['user_id'] : null;
$action = trim((string) ($_GET['action'] ?? ''));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));

$filters = [
    'user_id' => $userId,
    'action' => $action !== '' ? $action : null,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
];

try {
    $pdo = get_pdo();
    $result = search_activity_log($pdo, $filters, $page);

    echo json_encode([
        'status' => 'success',
        'rows' => $result['rows'],
        'pagination' => $result['pagination'],
    ]);
} catch (\Throwable $e) {
    error_log('Activity log search failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'THE ACTIVITY LOG COULD NOT BE LOADED. PLEASE TRY AGAIN.']);
}
