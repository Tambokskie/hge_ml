<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_role('administrator');

if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'YOUR SESSION EXPIRED. PLEASE TRY AGAIN.']);
    exit;
}

$username = trim((string) ($_POST['username'] ?? ''));
$password = (string) ($_POST['password'] ?? '');

$pdo = get_pdo();

// Step-up re-authentication: a valid session + CSRF token is not enough
// for a full database export. The submitted credentials must belong to
// an active administrator account, verified fresh here (not just trusted
// from the session), before any dump is produced.
$verifiedUser = attempt_login($pdo, $username, $password);
if ($verifiedUser === null || $verifiedUser['role'] !== 'administrator') {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'INVALID ADMINISTRATOR USERNAME OR PASSWORD.']);
    exit;
}

$mysqldumpPath = getenv('MYSQLDUMP_PATH') ?: 'C:\xampp\mysql\bin\mysqldump.exe';

if (!is_file($mysqldumpPath)) {
    error_log('backup_database: mysqldump not found at ' . $mysqldumpPath);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'THE BACKUP TOOL IS NOT AVAILABLE ON THIS SERVER.']);
    exit;
}

$dbConfig = get_db_config();
$tmpFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hge_backup_' . uniqid() . '.sql';

if ($dbConfig['pass'] !== '') {
    putenv('MYSQL_PWD=' . $dbConfig['pass']);
}

$cmd = escapeshellarg($mysqldumpPath)
    . ' --host=' . escapeshellarg($dbConfig['host'])
    . ' --user=' . escapeshellarg($dbConfig['user'])
    . ' --single-transaction --routines --triggers --add-drop-table --databases'
    . ' --result-file=' . escapeshellarg($tmpFile)
    . ' ' . escapeshellarg($dbConfig['name']);

exec($cmd . ' 2>&1', $output, $returnCode);
putenv('MYSQL_PWD');

if ($returnCode !== 0 || !is_file($tmpFile) || filesize($tmpFile) === 0) {
    @unlink($tmpFile);
    error_log('backup_database: mysqldump failed (exit ' . $returnCode . '): ' . implode(' ', $output));
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'THE DATABASE BACKUP COULD NOT BE CREATED. PLEASE TRY AGAIN.']);
    exit;
}

try {
    log_activity($pdo, (int) $verifiedUser['id'], 'db_backup', 'database=' . $dbConfig['name']);
} catch (\Throwable $e) {
    error_log('backup_database: activity log failed: ' . $e->getMessage());
}

$filename = 'hge_masterlist_backup_' . date('Y-m-d_His') . '.sql';

header('Content-Type: application/sql');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($tmpFile));
header('X-Content-Type-Options: nosniff');
readfile($tmpFile);
@unlink($tmpFile);
exit;
