<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/graduates.php';

require_role('administrator');

header('Content-Type: application/json');

if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'YOUR SESSION EXPIRED. PLEASE TRY AGAIN.']);
    exit;
}

$id = (int) ($_POST['id'] ?? 0);
$reason = trim((string) ($_POST['reason'] ?? ''));

$reasonValidation = validate_removal_reason($reason);
if (!$reasonValidation['ok']) {
    echo json_encode(['status' => 'error', 'message' => $reasonValidation['error']]);
    exit;
}

try {
    $pdo = get_pdo();
    $graduate = $id > 0 ? get_graduate_by_id($pdo, $id) : null;

    if ($graduate === null) {
        echo json_encode(['status' => 'error', 'message' => 'GRADUATE RECORD NOT FOUND.']);
        exit;
    }

    $currentUser = current_user();
    $result = delete_graduate($pdo, $id, $currentUser['id'] ?? null, $reason, 'manual');

    if (!$result['ok']) {
        echo json_encode(['status' => 'error', 'message' => $result['error']]);
        exit;
    }

    log_activity($pdo, $currentUser['id'] ?? null, 'record_removed', 'graduate_name=' . $graduate['graduate_name'] . ', reason=' . $reason);
} catch (\Throwable $e) {
    error_log('delete_saved_graduate failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'THE RECORD COULD NOT BE DELETED. PLEASE TRY AGAIN.']);
    exit;
}

echo json_encode(['status' => 'success', 'message' => 'RECORD REMOVED.']);
