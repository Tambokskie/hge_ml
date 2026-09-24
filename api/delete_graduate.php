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
$rowIndex = (int) ($_POST['row_index'] ?? -1);
$reason = trim((string) ($_POST['reason'] ?? ''));

$reasonValidation = validate_removal_reason($reason);
if (!$reasonValidation['ok']) {
    echo json_encode(['status' => 'error', 'message' => $reasonValidation['error']]);
    exit;
}

$result = remove_staged_row($uploadId, $rowIndex);

if (!$result['ok']) {
    echo json_encode(['status' => 'error', 'message' => $result['error']]);
    exit;
}

echo json_encode(['status' => 'success', 'message' => 'RECORD REMOVED.']);
