<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/graduates.php';

require_login();

header('Content-Type: application/json');

if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'YOUR SESSION EXPIRED. PLEASE TRY AGAIN.']);
    exit;
}

$graduateId = (int) ($_POST['graduate_id'] ?? 0);
$currentUser = current_user();

try {
    $pdo = get_pdo();
    $graduate = $graduateId > 0 ? get_graduate_by_id($pdo, $graduateId) : null;

    if ($graduate === null) {
        echo json_encode(['status' => 'error', 'message' => 'GRADUATE RECORD NOT FOUND.']);
        exit;
    }

    $isAdmin = user_has_role($currentUser, 'administrator');
    $result = revert_application($pdo, $graduateId, $isAdmin);

    if (!$result['ok']) {
        echo json_encode(['status' => 'error', 'message' => $result['error']]);
        exit;
    }

    log_activity(
        $pdo,
        $currentUser['id'] ?? null,
        'application_reverted',
        'reverted ' . $result['reverted'] . ' for graduate_name=' . $graduate['graduate_name']
    );
} catch (\Throwable $e) {
    error_log('revert_application failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'THE REVERT COULD NOT BE COMPLETED. PLEASE TRY AGAIN.']);
    exit;
}

echo json_encode([
    'status' => 'success',
    'reverted' => $result['reverted'],
    'message' => $result['reverted'] === 'approval' ? 'APPROVAL REVERTED.' : 'APPLICATION REVERTED.',
]);
