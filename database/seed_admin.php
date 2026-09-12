<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';

$username = getenv('ADMIN_USERNAME') ?: 'admin';
$password = getenv('ADMIN_PASSWORD') ?: null;

if (!$password) {
    fwrite(STDERR, "Set the ADMIN_PASSWORD environment variable before running this script.\n");
    exit(1);
}

create_or_update_admin(get_pdo(), $username, $username . '@hge.local', $password);

echo "Administrator account '{$username}' created/updated.\n";
