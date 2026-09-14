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
$currentUser = current_user();

if ($userId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'USER NOT FOUND.']);
    exit;
}

$role = isset($_POST['role']) && $_POST['role'] !== '' ? (string) $_POST['role'] : null;
$status = isset($_POST['status']) && $_POST['status'] !== '' ? (string) $_POST['status'] : null;
$newPassword = isset($_POST['new_password']) && $_POST['new_password'] !== '' ? (string) $_POST['new_password'] : null;

$isSelf = $currentUser !== null && (int) $currentUser['id'] === $userId;

if ($isSelf && $role !== null && $role !== 'administrator') {
    echo json_encode(['status' => 'error', 'message' => 'YOU CANNOT CHANGE YOUR OWN ROLE.']);
    exit;
}

if ($isSelf && $status === 'disabled') {
    echo json_encode(['status' => 'error', 'message' => 'YOU CANNOT DISABLE YOUR OWN ACCOUNT.']);
    exit;
}

try {
    $pdo = get_pdo();

    $existsStmt = $pdo->prepare('SELECT 1 FROM users WHERE id = :id');
    $existsStmt->execute(['id' => $userId]);
    if ($existsStmt->fetchColumn() === false) {
        echo json_encode(['status' => 'error', 'message' => 'USER NOT FOUND.']);
        exit;
    }

    $pdo->beginTransaction();
    $details = [];

    if ($role !== null) {
        $result = update_user_role($pdo, $userId, $role);
        if ($result['status'] !== 'success') {
            $pdo->rollBack();
            echo json_encode($result);
            exit;
        }
        $details[] = 'role=' . $role;
    }

    if ($status !== null) {
        $result = set_user_status($pdo, $userId, $status);
        if ($result['status'] !== 'success') {
            $pdo->rollBack();
            echo json_encode($result);
            exit;
        }
        $details[] = 'status=' . $status;
    }

    if ($newPassword !== null) {
        $result = reset_user_password($pdo, $userId, $newPassword);
        if ($result['status'] !== 'success') {
            $pdo->rollBack();
            echo json_encode($result);
            exit;
        }
        $details[] = 'password_reset';
    }

    if (empty($details)) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'NO CHANGES WERE SUBMITTED.']);
        exit;
    }

    $pdo->commit();

    try {
        log_activity($pdo, $currentUser['id'] ?? null, 'user_updated', 'user_id=' . $userId . ', ' . implode(', ', $details));
    } catch (\Throwable $logError) {
        error_log('Activity log failed: ' . $logError->getMessage());
    }

    echo json_encode(['status' => 'success', 'message' => 'USER UPDATED SUCCESSFULLY.']);
} catch (\Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Update user failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'THE USER COULD NOT BE UPDATED. PLEASE TRY AGAIN.']);
}
