<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/duplicates.php';
require_once __DIR__ . '/../includes/bulk_hge.php';
require_once __DIR__ . '/../includes/bulk_hge_review.php';

require_role('administrator');

header('Content-Type: application/json');

if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'YOUR SESSION EXPIRED. PLEASE TRY AGAIN.']);
    exit;
}

$itemId = (int) ($_POST['item_id'] ?? 0);
$action = (string) ($_POST['action'] ?? '');
$graduateId = (int) ($_POST['graduate_id'] ?? 0);

if ($itemId <= 0 || !in_array($action, ['file', 'dismiss'], true) || ($action === 'file' && $graduateId <= 0)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'INVALID REQUEST.']);
    exit;
}

$currentUser = current_user();

try {
    $pdo = get_pdo();

    if ($action === 'dismiss') {
        $result = bulk_hge_review_dismiss($pdo, $itemId, (int) $currentUser['id']);
        $message = 'ITEM DISMISSED. NOTHING WAS FILED.';
    } else {
        $result = bulk_hge_review_file($pdo, $itemId, $graduateId, (int) $currentUser['id']);
        $message = 'APPLICATION FILED.';
        if ($result['ok']) {
            log_activity(
                $pdo,
                $currentUser['id'] ?? null,
                'application_filed',
                'bulk review: ' . $result['person'] . ' filed against ' . $result['graduate_name'] . ' (' . $result['date'] . ')'
            );
        }
    }
} catch (\Throwable $e) {
    error_log('bulk_hge_review_resolve failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'THE ITEM COULD NOT BE UPDATED. PLEASE TRY AGAIN.']);
    exit;
}

if (!$result['ok']) {
    echo json_encode(['status' => 'error', 'message' => $result['error']]);
    exit;
}

echo json_encode(['status' => 'success', 'message' => $message, 'pending' => bulk_hge_review_pending_count($pdo)]);
