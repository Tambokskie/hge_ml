<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/uploads.php';

require_login();

$id = (int) ($_GET['id'] ?? 0);

try {
    $upload = $id > 0 ? get_upload_by_id(get_pdo(), $id) : null;

    if ($upload === null || !is_file($upload['file_path'])) {
        http_response_code(404);
        die('THE REQUESTED FILE COULD NOT BE FOUND.');
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . basename($upload['stored_filename']) . '"');
    header('Content-Length: ' . filesize($upload['file_path']));
    readfile($upload['file_path']);
} catch (\Throwable $e) {
    error_log('PDF serve failed: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain');
    die('THE FILE COULD NOT BE RETRIEVED. PLEASE TRY AGAIN.');
}
