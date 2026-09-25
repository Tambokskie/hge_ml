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
$rowIndexes = array_filter(
    array_map('trim', explode(',', (string) ($_POST['row_indexes'] ?? ''))),
    static fn (string $value): bool => $value !== ''
);
$rowIndexes = array_map('intval', $rowIndexes);

if (empty($rowIndexes)) {
    echo json_encode(['status' => 'error', 'message' => 'NO RECORDS SELECTED.']);
    exit;
}

$reason = trim((string) ($_POST['reason'] ?? ''));

$reasonValidation = validate_removal_reason($reason);
if (!$reasonValidation['ok']) {
    echo json_encode(['status' => 'error', 'message' => $reasonValidation['error']]);
    exit;
}

$result = remove_staged_rows($uploadId, $rowIndexes);

if (!$result['ok']) {
    echo json_encode(['status' => 'error', 'message' => $result['error']]);
    exit;
}

$currentUser = current_user();
log_activity(
    get_pdo(),
    $currentUser['id'] ?? null,
    'record_removed',
    $result['removed'] . ' RECORD(S) REMOVED FROM upload_id=' . $uploadId . ', reason=' . $reason . ' (STAGED, PENDING REVIEW)'
);

echo json_encode(['status' => 'success', 'message' => $result['removed'] . ' RECORD(S) REMOVED.']);
