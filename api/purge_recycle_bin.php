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

// "12" is a graduate record's bin id, "i12" an item in recycle_bin_items.
$tokens = array_values(array_filter(array_map('trim', explode(',', (string) ($_POST['ids'] ?? ''))), static fn (string $t): bool => preg_match('/^i?\d+$/', $t) === 1));
$reason = trim((string) ($_POST['reason'] ?? ''));

if ($tokens === []) {
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

    foreach ($tokens as $token) {
        if ($token[0] === 'i') {
            $item = purge_recycle_item($pdo, (int) substr($token, 1));
            $name = $item === null ? null : (RECYCLE_BIN_TYPE_LABELS[$item['type']] ?? $item['type']) . ' ' . $item['label'];
        } else {
            $name = purge_recycle_bin_item($pdo, (int) $token);
            $name = $name === null ? null : 'RECORD ' . $name;
        }
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
