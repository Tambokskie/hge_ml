<?php

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

redirect(current_user() !== null ? APP_BASE_URL . '/admin/dashboard.php' : APP_BASE_URL . '/login.php');
