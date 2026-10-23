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
    echo json_encode(['status' => 'error', 'message' => 'PLEASE SELECT A VALID PDF, JPG/JPEG OR PNG FILE TO UPLOAD.']);
    exit;
}

$currentUser = current_user();
$pdo = get_pdo();

try {
    $result = handle_upload_request($pdo, $_POST, $_FILES, (int) $currentUser['id'], __DIR__ . '/../storage/pdfs');
} catch (\Throwable $e) {
    error_log('PDF upload failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'THE UPLOAD COULD NOT BE COMPLETED. PLEASE TRY AGAIN.']);
    exit;
}

if ($result['status'] === 'success') {
    try {
        $uploaded = get_upload_by_id($pdo, (int) $result['upload']['id']);
        if ($uploaded !== null) {
            $details = format_school_display_name($uploaded['school_name'], $uploaded['campus']) . ' ' . $uploaded['school_year'];
            log_activity($pdo, (int) $currentUser['id'], 'pdf_upload', $details);
        }
    } catch (\Throwable $logError) {
        error_log('Activity log failed: ' . $logError->getMessage());
    }
}

echo json_encode($result);
