<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/schools.php';
require_once __DIR__ . '/../includes/pdf_files.php';
require_once __DIR__ . '/../includes/xlsx_writer.php';

require_role('administrator');

$schoolId = (int) ($_GET['school_id'] ?? 0);

try {
    $school = $schoolId > 0 ? get_school_by_id(get_pdo(), $schoolId) : null;

    if ($school === null) {
        http_response_code(404);
        header('Content-Type: text/plain');
        die('SELECTED SCHOOL COULD NOT BE FOUND.');
    }

    $bytes = build_latin_honor_template_xlsx($school['school_name'], (string) ($school['campus'] ?? ''));
    $filename = build_stored_filename($school['school_name'], 'TEMPLATE', 1, (string) ($school['campus'] ?? ''), 'xlsx');

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
} catch (\Throwable $e) {
    error_log('Template download failed: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain');
    die('THE TEMPLATE COULD NOT BE GENERATED. PLEASE TRY AGAIN.');
}
