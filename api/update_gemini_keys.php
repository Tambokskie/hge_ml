<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/app_settings.php';

require_role('administrator');

header('Content-Type: application/json');

if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'YOUR SESSION EXPIRED. PLEASE TRY AGAIN.']);
    exit;
}

$username = trim((string) ($_POST['username'] ?? ''));
$password = (string) ($_POST['password'] ?? '');

$pdo = get_pdo();

// Step-up re-authentication, the same pattern api/backup_database.php uses:
// a valid session + CSRF token isn't enough to change a live API credential.
$verifiedUser = attempt_login($pdo, $username, $password);
if ($verifiedUser === null || $verifiedUser['role'] !== 'administrator') {
    echo json_encode(['status' => 'error', 'message' => 'INVALID ADMINISTRATOR USERNAME OR PASSWORD.']);
    exit;
}

$key1 = trim((string) ($_POST['gemini_api_key'] ?? ''));
$key2 = trim((string) ($_POST['gemini_api_key_2'] ?? ''));

if ($key1 === '' && $key2 === '') {
    echo json_encode(['status' => 'error', 'message' => 'ENTER AT LEAST ONE API KEY TO SAVE. LEAVE A FIELD BLANK TO KEEP ITS CURRENT VALUE.']);
    exit;
}

try {
    $updated = [];

    if ($key1 !== '') {
        set_app_setting($pdo, 'gemini_api_key', $key1, (int) $verifiedUser['id']);
        $updated[] = 'GEMINI_API_KEY';
    }

    if ($key2 !== '') {
        set_app_setting($pdo, 'gemini_api_key_2', $key2, (int) $verifiedUser['id']);
        $updated[] = 'GEMINI_API_KEY_2';
    }

    log_activity($pdo, (int) $verifiedUser['id'], 'ai_settings_updated', implode(', ', $updated) . ' UPDATED');
} catch (\Throwable $e) {
    error_log('update_gemini_keys failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'THE SETTINGS COULD NOT BE SAVED. PLEASE TRY AGAIN.']);
    exit;
}

echo json_encode(['status' => 'success', 'message' => 'AI EXTRACTION SETTINGS UPDATED.']);
