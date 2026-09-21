<?php

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

    return new PDO($dsn, $config['user'], $config['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
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
