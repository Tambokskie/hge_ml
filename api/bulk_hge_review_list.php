<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/duplicates.php';
require_once __DIR__ . '/../includes/bulk_hge.php';
require_once __DIR__ . '/../includes/bulk_hge_review.php';

require_role('administrator');

header('Content-Type: application/json');

$limit = 100;
$offset = max(0, (int) ($_GET['offset'] ?? 0));

try {
    $list = bulk_hge_review_list(get_pdo(), $limit, $offset);
} catch (\Throwable $e) {
    error_log('bulk_hge_review_list failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => bulk_hge_review_missing_table_message($e) ?? 'THE REVIEW LIST COULD NOT BE LOADED. PLEASE TRY AGAIN.',
    ]);
    exit;
}

echo json_encode(['status' => 'success', 'total' => $list['total'], 'items' => $list['items']], JSON_INVALID_UTF8_SUBSTITUTE);
