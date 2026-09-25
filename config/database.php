<?php

// Pinned explicitly so every date()/time() call in the app reflects local
// Philippine time regardless of the host's php.ini date.timezone setting or
// the underlying OS/MySQL server's own time zone configuration.
date_default_timezone_set('Asia/Manila');

if (!defined('APP_BASE_URL')) {
    define('APP_BASE_URL', getenv('APP_BASE_URL') ?: '/hge_masterlist');
}

if (!defined('SESSION_IDLE_TIMEOUT')) {
    define('SESSION_IDLE_TIMEOUT', (int) (getenv('SESSION_IDLE_TIMEOUT') ?: 14400));
}

function get_db_config(): array
{
    return [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'name' => getenv('DB_NAME') ?: 'hge_masterlist',
        'user' => getenv('DB_USER') ?: 'root',
        'pass' => getenv('DB_PASS') ?: '',
    ];
}

function connect_pdo(array $config): PDO
{
    $dsn = "mysql:host={$config['host']};dbname={$config['name']};charset=utf8mb4";

    // Pins the session's time zone to the Philippines' fixed UTC+8 offset (no
    // DST, so an offset needs no mysql.time_zone_name lookup tables) so every
    // CURRENT_TIMESTAMP/NOW()-driven column (created_at, updated_at, uploaded_at,
    // etc.) is correct regardless of what time zone the MySQL server itself is
    // configured with — the app can't assume that matches Manila.
    return new PDO($dsn, $config['user'], $config['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+08:00'",
    ]);
}

function get_pdo(): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    try {
        return $pdo = connect_pdo(get_db_config());
    } catch (PDOException $e) {
        error_log('Database connection failed: ' . $e->getMessage());
        http_response_code(500);
        die('THE APPLICATION COULD NOT CONNECT TO THE DATABASE. PLEASE TRY AGAIN LATER.');
    }
}
