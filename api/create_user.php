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

try {
    $pdo = get_pdo();
    $username = trim((string) ($_POST['username'] ?? ''));

    $result = create_user($pdo, [
        'name' => $_POST['name'] ?? '',
        'username' => $username,
        'email' => $_POST['email'] ?? '',
        'password' => $_POST['password'] ?? '',
        'role' => $_POST['role'] ?? 'user',
    ]);

    if ($result['status'] === 'success') {
        try {
            $currentUser = current_user();
            log_activity($pdo, $currentUser['id'] ?? null, 'user_created', 'username=' . $username);
        } catch (\Throwable $logError) {
            error_log('Activity log failed: ' . $logError->getMessage());
        }
    }

    echo json_encode($result);
} catch (\Throwable $e) {
    error_log('Create user failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'THE USER COULD NOT BE CREATED. PLEASE TRY AGAIN.']);
}
