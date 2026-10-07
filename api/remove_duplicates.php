<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/duplicates.php';

require_role('administrator');

header('Content-Type: application/json');

if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'YOUR SESSION EXPIRED. PLEASE TRY AGAIN.']);
    exit;
}

$ids = array_filter(array_map('intval', explode(',', (string) ($_POST['ids'] ?? ''))));
$reason = trim((string) ($_POST['reason'] ?? ''));

if ($ids === []) {
    echo json_encode(['status' => 'error', 'message' => 'SELECT AT LEAST ONE DUPLICATE RECORD TO REMOVE.']);
    exit;
}

$reasonValidation = validate_removal_reason($reason);
if (!$reasonValidation['ok']) {
    echo json_encode(['status' => 'error', 'message' => $reasonValidation['error']]);
    exit;
}

try {
    $pdo = get_pdo();
    $currentUser = current_user();
    $result = remove_duplicate_graduates($pdo, $ids, $currentUser['id'] ?? null, $reason);

    if ($result['removed'] > 0) {
        log_activity($pdo, $currentUser['id'] ?? null, 'record_removed', 'duplicate_cleanup=' . $result['removed'] . ' record(s) moved to recycle bin, reason=' . $reason);
    }
} catch (\Throwable $e) {
    error_log('remove_duplicates failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'THE DUPLICATES COULD NOT BE REMOVED. PLEASE TRY AGAIN.']);
    exit;
}

if ($result['removed'] === 0) {
    echo json_encode(['status' => 'error', 'message' => $result['skipped'][0] ?? 'NOTHING WAS REMOVED.', 'skipped' => $result['skipped']]);
    exit;
}

echo json_encode([
    'status' => 'success',
    'message' => $result['removed'] . ' DUPLICATE RECORD(S) MOVED TO THE RECYCLE BIN.',
    'removed' => $result['removed'],
    'skipped' => $result['skipped'],
]);
