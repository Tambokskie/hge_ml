<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/recycle_bin.php';

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
    echo json_encode(['status' => 'error', 'message' => 'SELECT AT LEAST ONE ITEM TO DELETE.']);
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
    $purged = 0;
    $names = [];

    foreach ($ids as $binId) {
        $name = purge_recycle_bin_item($pdo, $binId);
        if ($name !== null) {
            $purged++;
            $names[] = $name;
        }
    }

    if ($purged > 0) {
        log_activity(
            $pdo,
            $currentUser['id'] ?? null,
            'record_purged',
            'permanently deleted from recycle bin: ' . implode('; ', array_slice($names, 0, 10)) . ($purged > 10 ? ' (+' . ($purged - 10) . ' more)' : '') . ', reason=' . $reason
        );
    }
} catch (\Throwable $e) {
    error_log('purge_recycle_bin failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'THE ITEMS COULD NOT BE DELETED. PLEASE TRY AGAIN.']);
    exit;
}

echo json_encode(['status' => 'success', 'message' => $purged . ' ITEM(S) PERMANENTLY DELETED.', 'purged' => $purged]);
