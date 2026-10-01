<?php

// No hardcoded default for the key (unlike DB_HOST/DB_USER/etc. in
// config/database.php) — there is no sensible public default for a secret.
// An empty key makes GeminiExtractor::extract() fail immediately without
// attempting a network call, so a clone of this repo with GEMINI_API_KEY
// unset silently and correctly falls back to the existing
// GenericExtractor -> OcrExtractor pipeline.
if (!defined('GEMINI_API_KEY')) {
    define('GEMINI_API_KEY', getenv('GEMINI_API_KEY') ?: '');
}

// Up to three additional optional keys, each from a separate Google
// account/project — tried in order only after the previous one comes back
// quota-exhausted (HTTP 429), roughly multiplying the effective free-tier
// daily request quota by however many are actually configured. Safe to
// leave any of these unset; GeminiExtractor simply won't have that slot to
// fall back to.
if (!defined('GEMINI_API_KEY_2')) {
    define('GEMINI_API_KEY_2', getenv('GEMINI_API_KEY_2') ?: '');
}

if (!defined('GEMINI_API_KEY_3')) {
    define('GEMINI_API_KEY_3', getenv('GEMINI_API_KEY_3') ?: '');
}

if (!defined('GEMINI_API_KEY_4')) {
    define('GEMINI_API_KEY_4', getenv('GEMINI_API_KEY_4') ?: '');
}

if (!defined('GEMINI_MODEL')) {
    define('GEMINI_MODEL', getenv('GEMINI_MODEL') ?: 'gemini-flash-latest');
}
