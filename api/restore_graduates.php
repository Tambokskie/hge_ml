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
if ($ids === []) {
    echo json_encode(['status' => 'error', 'message' => 'SELECT AT LEAST ONE ITEM TO RESTORE.']);
    exit;
}

try {
    $pdo = get_pdo();
    $currentUser = current_user();
    $restored = 0;
    $errors = [];

    foreach ($ids as $binId) {
        $result = restore_graduate($pdo, $binId);
        if ($result['ok']) {
            $restored++;
        } else {
            $errors[] = $result['error'];
        }
    }

    if ($restored > 0) {
        log_activity($pdo, $currentUser['id'] ?? null, 'record_restored', $restored . ' record(s) restored from recycle bin');
    }
} catch (\Throwable $e) {
    error_log('restore_graduates failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'THE RECORDS COULD NOT BE RESTORED. PLEASE TRY AGAIN.']);
    exit;
}

if ($restored === 0) {
    echo json_encode(['status' => 'error', 'message' => $errors[0] ?? 'NOTHING WAS RESTORED.']);
    exit;
}

echo json_encode([
    'status' => 'success',
    'message' => $restored . ' RECORD(S) RESTORED.' . ($errors ? ' ' . count($errors) . ' COULD NOT BE RESTORED.' : ''),
    'restored' => $restored,
]);
