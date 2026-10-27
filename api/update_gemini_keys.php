<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/gemini.php';
require_once __DIR__ . '/../config/claude.php';
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

// Each of the five slots has a key (blank = keep the current one) and the provider
// it is connected to (Gemini or Claude).
$keys = [];
$providers = [];
for ($slot = 1; $slot <= AI_KEY_SLOT_COUNT; $slot++) {
    $info = ai_key_slot($slot);
    $keys[$info['key_setting']] = trim((string) ($_POST[$info['key_setting']] ?? ''));

    $provider = trim((string) ($_POST[$info['provider_setting']] ?? ''));
    if ($provider === '') {
        continue; // not sent: leave the slot's provider as it is
    }
    if (!isset(AI_PROVIDERS[$provider])) {
        echo json_encode(['status' => 'error', 'message' => 'CHOOSE GEMINI OR CLAUDE FOR EACH API KEY.']);
        exit;
    }
    $providers[$slot] = $provider;
}

// Each slot's expiry date: sent as YYYY-MM-DD, or blank to clear it; not sent = leave it as it is.
$expiries = [];
for ($slot = 1; $slot <= AI_KEY_SLOT_COUNT; $slot++) {
    $field = ai_key_slot($slot)['expiry_setting'];
    if (!isset($_POST[$field])) {
        continue;
    }
    $value = trim((string) $_POST[$field]);
    if ($value !== '' && parse_ai_key_expiry($value) === null) {
        echo json_encode(['status' => 'error', 'message' => 'KEY ' . $slot . ': ENTER A REAL EXPIRATION DATE, OR LEAVE IT BLANK.']);
        exit;
    }
    $expiries[$slot] = $value;
}

$changedExpiries = [];
foreach ($expiries as $slot => $value) {
    if ($value !== (resolve_ai_slot_expiry($pdo, $slot) ?? '')) {
        $changedExpiries[$slot] = $value;
    }
}

$changedProviders = [];
foreach ($providers as $slot => $provider) {
    if ($provider !== resolve_ai_slot_provider($pdo, $slot)) {
        $changedProviders[$slot] = $provider;
    }
}

if (array_filter($keys) === [] && $changedProviders === [] && $changedExpiries === []) {
    echo json_encode(['status' => 'error', 'message' => 'ENTER AT LEAST ONE API KEY OR CHANGE A PROVIDER OR EXPIRATION DATE TO SAVE. LEAVE A KEY FIELD BLANK TO KEEP ITS CURRENT VALUE.']);
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

    foreach ($changedProviders as $slot => $provider) {
        set_app_setting($pdo, ai_key_slot($slot)['provider_setting'], $provider, (int) $verifiedUser['id']);
        $updated[] = 'SLOT ' . $slot . ' PROVIDER: ' . mb_strtoupper($provider);
    }

    foreach ($changedExpiries as $slot => $value) {
        $setting = ai_key_slot($slot)['expiry_setting'];
        if ($value === '') {
            delete_app_setting($pdo, $setting);
            $updated[] = 'SLOT ' . $slot . ' EXPIRATION DATE: CLEARED';
        } else {
            set_app_setting($pdo, $setting, $value, (int) $verifiedUser['id']);
            $updated[] = 'SLOT ' . $slot . ' EXPIRATION DATE: ' . $value;
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
