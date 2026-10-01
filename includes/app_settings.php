<?php

/**
 * Small generic key-value store for admin-configurable settings that need to
 * take effect immediately, with no server restart — env vars (GEMINI_API_KEY
 * etc.) can't be changed at runtime from a web request, so anything an admin
 * should be able to update via a modal lives here instead.
 */
function get_app_setting(PDO $pdo, string $key): ?string
{
    $stmt = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key = :key');
    $stmt->execute(['key' => $key]);
    $value = $stmt->fetchColumn();

    return $value === false ? null : $value;
}

function set_app_setting(PDO $pdo, string $key, string $value, int $updatedBy): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO app_settings (setting_key, setting_value, updated_by)
         VALUES (:key, :value, :updated_by)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)'
    );
    $stmt->execute(['key' => $key, 'value' => $value, 'updated_by' => $updatedBy]);
}

/**
 * The API key(s) GeminiExtractor should try, in order. A value saved via
 * Settings -> AI Extraction (admin/users.php) takes precedence per-slot over
 * the GEMINI_API_KEY/GEMINI_API_KEY_2/_3/_4 environment variables
 * (config/gemini.php), so changing a key here is live on the very next
 * request. Empty/unset slots are dropped rather than returned as ''.
 */
function resolve_gemini_api_keys(PDO $pdo): array
{
    $keys = [
        get_app_setting($pdo, 'gemini_api_key') ?: (defined('GEMINI_API_KEY') ? GEMINI_API_KEY : ''),
        get_app_setting($pdo, 'gemini_api_key_2') ?: (defined('GEMINI_API_KEY_2') ? GEMINI_API_KEY_2 : ''),
        get_app_setting($pdo, 'gemini_api_key_3') ?: (defined('GEMINI_API_KEY_3') ? GEMINI_API_KEY_3 : ''),
        get_app_setting($pdo, 'gemini_api_key_4') ?: (defined('GEMINI_API_KEY_4') ? GEMINI_API_KEY_4 : ''),
    ];

    return array_values(array_filter($keys, static fn (string $k): bool => $k !== ''));
}

/**
 * Masked status for the Settings modal — once saved, the actual key value is
 * never sent back to the browser, only whether a slot is configured and its
 * last 4 characters, enough for an admin to recognize which key is active
 * without re-exposing the secret.
 */
function gemini_key_display_status(PDO $pdo, string $settingKey, string $envValue): array
{
    $value = get_app_setting($pdo, $settingKey) ?: $envValue;

    if ($value === '') {
        return ['configured' => false, 'masked' => null];
    }

    $masked = strlen($value) > 4 ? str_repeat('•', 4) . substr($value, -4) : str_repeat('•', strlen($value));

    return ['configured' => true, 'masked' => $masked];
}
