<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/recycle_bin.php';
require_once __DIR__ . '/../includes/recycle_bin_items.php';

require_role('administrator');

header('Content-Type: application/json');

if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'YOUR SESSION EXPIRED. PLEASE TRY AGAIN.']);
    exit;
}

// The selection is a comma-separated list: "12" is a graduate record's bin id,
// "i12" an item (upload, school, user, keyword) in recycle_bin_items.
$tokens = array_values(array_filter(array_map('trim', explode(',', (string) ($_POST['ids'] ?? ''))), static fn (string $t): bool => preg_match('/^i?\d+$/', $t) === 1));
if ($tokens === []) {
    echo json_encode(['status' => 'error', 'message' => 'SELECT AT LEAST ONE ITEM TO RESTORE.']);
    exit;
}

try {
    $pdo = get_pdo();
    $currentUser = current_user();
    $userId = isset($currentUser['id']) ? (int) $currentUser['id'] : null;
    $restoredRecords = 0;
    $restoredItems = [];
    $errors = [];

    foreach ($tokens as $token) {
        if ($token[0] === 'i') {
            $result = restore_recycle_item($pdo, (int) substr($token, 1), $userId);
            if ($result['ok']) {
                $restoredItems[] = (RECYCLE_BIN_TYPE_LABELS[$result['type']] ?? $result['type']) . ' ' . $result['label'];
            }
        } else {
            $result = restore_graduate($pdo, (int) $token);
            if ($result['ok']) {
                $restoredRecords++;
            }
        }
        if (!$result['ok']) {
            $errors[] = $result['error'];
        }
    }

    $restored = $restoredRecords + count($restoredItems);
    if ($restored > 0) {
        $parts = [];
        if ($restoredRecords > 0) {
            $parts[] = $restoredRecords . ' record(s)';
        }
        if ($restoredItems !== []) {
            $parts[] = implode('; ', array_slice($restoredItems, 0, 10)) . (count($restoredItems) > 10 ? ' (+' . (count($restoredItems) - 10) . ' more)' : '');
        }
        log_activity($pdo, $userId, 'record_restored', 'restored from recycle bin: ' . implode(' | ', $parts));
    }
} catch (\Throwable $e) {
    error_log('restore_graduates failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'THE ITEMS COULD NOT BE RESTORED. PLEASE TRY AGAIN.']);
    exit;
}

if ($restored === 0) {
    echo json_encode(['status' => 'error', 'message' => $errors[0] ?? 'NOTHING WAS RESTORED.']);
    exit;
}

echo json_encode([
    'status' => 'success',
    'message' => $restored . ' ITEM(S) RESTORED.' . ($errors ? ' ' . count($errors) . ' COULD NOT BE RESTORED: ' . $errors[0] : ''),
    'restored' => $restored,
]);
