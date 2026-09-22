<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/users.php';

require_role('administrator');

header('Content-Type: application/json');

if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'YOUR SESSION EXPIRED. PLEASE TRY AGAIN.']);
    exit;
}

$userId = (int) ($_POST['user_id'] ?? 0);
$password = (string) ($_POST['password'] ?? '');
$currentUser = current_user();

if ($userId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'USER NOT FOUND.']);
    exit;
}

try {
    $pdo = get_pdo();
    $result = remove_user($pdo, $userId, (int) ($currentUser['id'] ?? 0), $password);

    if ($result['status'] === 'success') {
        try {
            log_activity($pdo, $currentUser['id'] ?? null, 'user_removed', 'user_id=' . $userId . ', name=' . $result['name']);
        } catch (\Throwable $logError) {
            error_log('Activity log failed: ' . $logError->getMessage());
        }
    }

    echo json_encode($result);
} catch (\Throwable $e) {
    error_log('Remove user failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'THE USER COULD NOT BE REMOVED. PLEASE TRY AGAIN.']);
}
