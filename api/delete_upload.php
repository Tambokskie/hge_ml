<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/uploads.php';

require_role('administrator');

header('Content-Type: application/json');

if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'YOUR SESSION EXPIRED. PLEASE TRY AGAIN.']);
    exit;
}

$uploadId = (int) ($_POST['upload_id'] ?? 0);

try {
    $pdo = get_pdo();
    $result = delete_upload($pdo, $uploadId);

    if (!$result['ok']) {
        echo json_encode(['status' => 'error', 'message' => $result['error']]);
        exit;
    }

    $currentUser = current_user();
    log_activity($pdo, $currentUser['id'] ?? null, 'pdf_delete', 'upload_id=' . $uploadId);
} catch (\Throwable $e) {
    error_log('delete_upload endpoint failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'THE UPLOAD COULD NOT BE DELETED. PLEASE TRY AGAIN.']);
    exit;
}

echo json_encode(['status' => 'success', 'message' => 'UPLOAD DELETED SUCCESSFULLY.']);
