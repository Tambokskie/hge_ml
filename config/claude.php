<?php

// Claude (Anthropic) is an alternative provider for AI extraction, chosen per
// API-key slot in Settings -> AI Extraction Settings (admin/admin_tools.php).
// No hardcoded key default, for the same reason as config/gemini.php: an unset
// key simply leaves that slot unused.
//
// Slot 5 is the one slot that is not a Gemini key from the start; it can come
// from the environment (AI_EXTRACTION_KEY_5 / AI_EXTRACTION_PROVIDER_5, the
// provider being 'gemini' or 'claude') until it is saved from the settings
// modal, which then takes precedence (includes/app_settings.php).
if (!defined('AI_EXTRACTION_KEY_5')) {
    define('AI_EXTRACTION_KEY_5', getenv('AI_EXTRACTION_KEY_5') ?: '');
}

if (!defined('AI_EXTRACTION_PROVIDER_5')) {
    define('AI_EXTRACTION_PROVIDER_5', getenv('AI_EXTRACTION_PROVIDER_5') ?: 'claude');
}

if (!defined('CLAUDE_MODEL')) {
    define('CLAUDE_MODEL', getenv('CLAUDE_MODEL') ?: 'claude-sonnet-5-5');
}
