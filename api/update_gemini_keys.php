<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/app_settings.php';
require_once __DIR__ . '/../extractors/GeminiExtractor.php';

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

$keys = [
    'gemini_api_key' => trim((string) ($_POST['gemini_api_key'] ?? '')),
    'gemini_api_key_2' => trim((string) ($_POST['gemini_api_key_2'] ?? '')),
    'gemini_api_key_3' => trim((string) ($_POST['gemini_api_key_3'] ?? '')),
    'gemini_api_key_4' => trim((string) ($_POST['gemini_api_key_4'] ?? '')),
];

if (array_filter($keys) === []) {
    echo json_encode(['status' => 'error', 'message' => 'ENTER AT LEAST ONE API KEY TO SAVE. LEAVE A FIELD BLANK TO KEEP ITS CURRENT VALUE.']);
    exit;
}

try {
    $updated = [];

    foreach ($keys as $settingKey => $value) {
        if ($value !== '') {
            set_app_setting($pdo, $settingKey, $value, (int) $verifiedUser['id']);
            $updated[] = mb_strtoupper($settingKey);
        }
    }

    log_activity($pdo, (int) $verifiedUser['id'], 'ai_settings_updated', implode(', ', $updated) . ' UPDATED');

    // A quota-exhausted flag recorded against the previous key set says
    // nothing about this new one — don't let admin/upload.php keep showing
    // a stale red indicator for up to 24h after capacity just changed.
    GeminiExtractor::clearQuotaExhausted();
} catch (\Throwable $e) {
    error_log('update_gemini_keys failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'THE SETTINGS COULD NOT BE SAVED. PLEASE TRY AGAIN.']);
    exit;
}

echo json_encode(['status' => 'success', 'message' => 'AI EXTRACTION SETTINGS UPDATED.']);
