<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/schools.php';
require_once __DIR__ . '/../includes/uploads.php';
require_once __DIR__ . '/../includes/xlsx_writer.php';

require_login();

$schoolId = (isset($_GET['school_id']) && (int) $_GET['school_id'] > 0) ? (int) $_GET['school_id'] : null;
$schoolYear = trim((string) ($_GET['school_year'] ?? ''));

try {
    $pdo = get_pdo();
    $schools = get_school_records_report_rows($pdo, ['school_id' => $schoolId, 'school_year' => $schoolYear !== '' ? $schoolYear : null]);
    $bytes = build_school_records_report_xlsx($schools);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="SCHOOL_RECORDS_SUMMARY_' . date('Y-m-d') . '.xlsx"');
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
} catch (\Throwable $e) {
    error_log('School records report failed: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain');
    die('THE REPORT COULD NOT BE GENERATED. PLEASE TRY AGAIN.');
}
