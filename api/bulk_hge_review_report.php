<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/xlsx_writer.php';

require_role('administrator');

$staged = $_SESSION['bulk_hge_preview'] ?? null;
$token = (string) ($_GET['token'] ?? '');

if (!is_array($staged) || $token === '' || !hash_equals((string) $staged['token'], $token)) {
    http_response_code(404);
    header('Content-Type: text/plain');
    die('THERE IS NO PREVIEW TO REPORT ON. PLEASE UPLOAD THE FILE AGAIN.');
}

try {
    $bytes = build_bulk_hge_review_xlsx($staged['review'], $staged['invalid']);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="HGE_BULK_UPDATE_REVIEW.xlsx"');
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
} catch (\Throwable $e) {
    error_log('bulk_hge_review_report failed: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain');
    die('THE REVIEW FILE COULD NOT BE GENERATED. PLEASE TRY AGAIN.');
}
