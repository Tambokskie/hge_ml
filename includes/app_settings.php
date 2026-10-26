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

/** The services an extraction API key can be connected to (value => label shown in the settings modal). */
const AI_PROVIDERS = ['gemini' => 'GEMINI', 'claude' => 'CLAUDE'];

/** How many API-key slots there are. Slots 1-4 started out as Gemini keys, slot 5 as a Claude key. */
const AI_KEY_SLOT_COUNT = 5;

/**
 * Where a slot's key, its provider and (for the first four) the environment fallback live.
 * The key settings of slots 1-4 keep their original `gemini_api_key*` names so keys saved
 * before the provider choice existed keep working untouched.
 *
 * @return array{key_setting: string, provider_setting: string, env_key: string, default_provider: string}
 */
function ai_key_slot(int $slot): array
{
    $keySettings = [1 => 'gemini_api_key', 2 => 'gemini_api_key_2', 3 => 'gemini_api_key_3', 4 => 'gemini_api_key_4', 5 => 'ai_api_key_5'];
    $envKeys = [1 => 'GEMINI_API_KEY', 2 => 'GEMINI_API_KEY_2', 3 => 'GEMINI_API_KEY_3', 4 => 'GEMINI_API_KEY_4', 5 => 'AI_EXTRACTION_KEY_5'];
    $defaultProvider = $slot === 5 && defined('AI_EXTRACTION_PROVIDER_5') && isset(AI_PROVIDERS[AI_EXTRACTION_PROVIDER_5])
        ? AI_EXTRACTION_PROVIDER_5
        : ($slot === 5 ? 'claude' : 'gemini');

    return [
        'key_setting' => $keySettings[$slot],
        'provider_setting' => 'ai_provider_' . $slot,
        'env_key' => $envKeys[$slot],
        'default_provider' => $defaultProvider,
    ];
}

/** The provider a slot is connected to: the saved choice, else the slot's default. Never anything but a known provider. */
function resolve_ai_slot_provider(PDO $pdo, int $slot): string
{
    $info = ai_key_slot($slot);
    $saved = get_app_setting($pdo, $info['provider_setting']);

    return ($saved !== null && isset(AI_PROVIDERS[$saved])) ? $saved : $info['default_provider'];
}

/** A slot's key: the one saved in Settings, else the environment variable's value; '' when neither is set. */
function resolve_ai_slot_key(PDO $pdo, int $slot): string
{
    $info = ai_key_slot($slot);

    return get_app_setting($pdo, $info['key_setting']) ?: (defined($info['env_key']) ? (string) constant($info['env_key']) : '');
}

/**
 * Every configured extraction key in slot order, each with the provider it is connected to.
 * Empty slots are left out.
 *
 * @return array<int, array{slot: int, provider: string, key: string}>
 */
function resolve_ai_key_slots(PDO $pdo): array
{
    $slots = [];
    for ($slot = 1; $slot <= AI_KEY_SLOT_COUNT; $slot++) {
        $key = resolve_ai_slot_key($pdo, $slot);
        if ($key !== '') {
            $slots[] = ['slot' => $slot, 'provider' => resolve_ai_slot_provider($pdo, $slot), 'key' => $key];
        }
    }

    return $slots;
}

/**
 * Pure. Groups consecutive slots of the same provider, so the extraction tries them in slot order
 * but hands each provider's neighbouring keys to its extractor as one list (which rotates through
 * them on a quota failure).
 *
 * @param array<int, array{slot: int, provider: string, key: string}> $slots
 * @return array<int, array{provider: string, keys: string[]}>
 */
function group_ai_slots_into_segments(array $slots): array
{
    $segments = [];
    foreach ($slots as $slot) {
        $last = count($segments) - 1;
        if ($last >= 0 && $segments[$last]['provider'] === $slot['provider']) {
            $segments[$last]['keys'][] = $slot['key'];
        } else {
            $segments[] = ['provider' => $slot['provider'], 'keys' => [$slot['key']]];
        }
    }

    return $segments;
}

/**
 * The Gemini API key(s) GeminiExtractor should try, in order: the slots connected to Gemini. A value
 * saved via Settings -> AI Extraction Settings (admin/admin_tools.php) takes precedence per-slot over
 * the GEMINI_API_KEY/GEMINI_API_KEY_2/_3/_4 environment variables (config/gemini.php), so changing a
 * key here is live on the very next request. Empty/unset slots are dropped rather than returned as ''.
 */
function resolve_gemini_api_keys(PDO $pdo): array
{
    $keys = [];
    foreach (resolve_ai_key_slots($pdo) as $slot) {
        if ($slot['provider'] === 'gemini') {
            $keys[] = $slot['key'];
        }
    }

    return $keys;
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

/**
 * Masked status of one API-key slot plus the provider it is connected to, for the settings modal.
 *
 * @return array{configured: bool, masked: ?string, provider: string}
 */
function ai_slot_display_status(PDO $pdo, int $slot): array
{
    $info = ai_key_slot($slot);
    $status = gemini_key_display_status($pdo, $info['key_setting'], defined($info['env_key']) ? (string) constant($info['env_key']) : '');
    $status['provider'] = resolve_ai_slot_provider($pdo, $slot);

    return $status;
}
