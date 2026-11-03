<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/duplicates.php';

require_login();

header('Content-Type: application/json');

$graduateId = (int) ($_GET['graduate_id'] ?? 0);

try {
    $details = $graduateId > 0 ? get_duplicate_finding_details(get_pdo(), $graduateId) : null;
} catch (\Throwable $e) {
    error_log('get_duplicate_findings failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'THE DUPLICATE DETAILS COULD NOT BE LOADED. PLEASE TRY AGAIN.']);
    exit;
}

if ($details === null) {
    echo json_encode(['status' => 'error', 'message' => 'NO DUPLICATE WAS FOUND FOR THIS RECORD.']);
    exit;
}

echo json_encode(['status' => 'success'] + $details, JSON_INVALID_UTF8_SUBSTITUTE);
