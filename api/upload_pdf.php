<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pdf_files.php';
require_once __DIR__ . '/../includes/schools.php';
require_once __DIR__ . '/../includes/uploads.php';

require_role('administrator');

header('Content-Type: application/json');

if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'YOUR SESSION EXPIRED. PLEASE TRY AGAIN.']);
    exit;
}

$file = $_FILES['pdf_file'] ?? null;
if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
    echo json_encode(['status' => 'error', 'message' => 'PLEASE SELECT A VALID PDF FILE TO UPLOAD.']);
    exit;
}

$currentUser = current_user();

try {
    $result = handle_upload_request(get_pdo(), $_POST, $_FILES, (int) $currentUser['id'], __DIR__ . '/../storage/pdfs');
} catch (\Throwable $e) {
    error_log('PDF upload failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'THE UPLOAD COULD NOT BE COMPLETED. PLEASE TRY AGAIN.']);
    exit;
}

echo json_encode($result);
