<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_role('administrator');

header('Content-Type: application/json');

if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'YOUR SESSION EXPIRED. PLEASE TRY AGAIN.']);
    exit;
}

$nextKey = (string) ($_POST['next'] ?? '');
$destination = step_up_destinations()[$nextKey] ?? null;

if ($destination === null) {
    echo json_encode(['status' => 'error', 'message' => 'INVALID DESTINATION.']);
    exit;
}

$username = trim((string) ($_POST['username'] ?? ''));
$password = (string) ($_POST['password'] ?? '');

$pdo = get_pdo();

// Step-up re-authentication, same pattern as api/backup_database.php: a
// valid session + CSRF token is not enough to reach this settings page.
// Credentials are re-verified fresh here before granting the session flag
// that admin/ocr_courses.php (and its API endpoints) check server-side.
$verifiedUser = attempt_login($pdo, $username, $password);
if ($verifiedUser === null || $verifiedUser['role'] !== 'administrator') {
    echo json_encode(['status' => 'error', 'message' => 'INVALID ADMINISTRATOR USERNAME OR PASSWORD.']);
    exit;
}

grant_step_up($destination['purpose']);

echo json_encode(['status' => 'success', 'redirect_url' => APP_BASE_URL . $destination['path']]);
