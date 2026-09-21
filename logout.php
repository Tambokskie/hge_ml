<?php

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

logout_user();

$reason = $_GET['reason'] ?? '';
redirect(APP_BASE_URL . '/login.php' . ($reason === 'idle' ? '?timeout=1' : ''));
