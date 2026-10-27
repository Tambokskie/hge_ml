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
$schoolId = (int) ($_POST['school_id'] ?? 0);

try {
    $pdo = get_pdo();
    $currentUser = current_user();
    $result = transfer_upload_to_school($pdo, $uploadId, $schoolId);

    if (!$result['ok']) {
        echo json_encode(['status' => 'error', 'message' => $result['error']]);
        exit;
    }

    log_activity(
        $pdo,
        $currentUser['id'] ?? null,
        'record_updated',
        'Transferred upload_id=' . $uploadId . ' from ' . $result['from'] . ' to ' . $result['to'] . ' (' . $result['records'] . ' saved record(s) moved' . ($result['file'] !== null ? ', file renamed to ' . $result['file'] : '') . ')'
    );
} catch (\Throwable $e) {
    error_log('transfer_upload_school endpoint failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'THE UPLOAD COULD NOT BE TRANSFERRED. PLEASE TRY AGAIN.']);
    exit;
}

echo json_encode(['status' => 'success', 'message' => 'TRANSFERRED TO ' . mb_strtoupper($result['to']) . '.']);
