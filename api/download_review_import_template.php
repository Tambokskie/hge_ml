<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/xlsx_writer.php';

require_role('administrator');

try {
    $bytes = build_review_import_template_xlsx();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="HONOR_GRADUATES_TEMPLATE.xlsx"');
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
} catch (\Throwable $e) {
    error_log('Review import template download failed: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain');
    die('THE TEMPLATE COULD NOT BE GENERATED. PLEASE TRY AGAIN.');
}
