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

if (!defined('GEMINI_MODEL')) {
    define('GEMINI_MODEL', getenv('GEMINI_MODEL') ?: 'gemini-flash-latest');
}
