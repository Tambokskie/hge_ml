<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/extraction_review.php';

require_role('administrator');

header('Content-Type: application/json');

if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'YOUR SESSION EXPIRED. PLEASE TRY AGAIN.']);
    exit;
}

$uploadId = (int) ($_POST['upload_id'] ?? 0);
$date = trim((string) ($_POST['graduation_date'] ?? ''));

if ($date === '') {
    echo json_encode(['status' => 'error', 'message' => 'PLEASE SELECT A DATE OF GRADUATION.']);
    exit;
}

$result = mass_update_staged_graduation_date($uploadId, $date);

if (!$result['ok']) {
    echo json_encode(['status' => 'error', 'message' => $result['error']]);
    exit;
}

$currentUser = current_user();
log_activity(
    get_pdo(),
    $currentUser['id'] ?? null,
    'record_updated',
    $result['updated'] . ' RECORD(S) DATE OF GRADUATION SET TO ' . $date . ' FOR upload_id=' . $uploadId . ' (STAGED, PENDING REVIEW)'
);

echo json_encode(['status' => 'success', 'message' => $result['updated'] . ' RECORD(S) UPDATED.']);
