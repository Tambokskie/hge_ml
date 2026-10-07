<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/reports.php';
require_once __DIR__ . '/../includes/xlsx_writer.php';

require_login();

try {
    $pdo = get_pdo();

    // Same invalid/missing-year fallback as api/get_application_filing_report.php
    // — defaults to the most recent year with data rather than hard-erroring.
    $year = (int) ($_GET['year'] ?? 0);
    if ($year <= 0) {
        $years = get_distinct_graduation_years($pdo);
        $year = $years[0] ?? (int) date('Y');
    }

    $report = get_application_filing_report($pdo, $year);
    $report['schools'] = array_map(static function (array $school): array {
        $school['school_display'] = strtoupper(format_school_display_name($school['school_name'], $school['campus']));
        return $school;
    }, $report['schools']);

    $bytes = build_application_filing_report_xlsx($report, $year);
    $filename = 'APPLICATION_FILING_REPORT_' . $year . '.xlsx';

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
} catch (\Throwable $e) {
    error_log('Application filing report export failed: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain');
    die('THE REPORT COULD NOT BE GENERATED. PLEASE TRY AGAIN.');
}
