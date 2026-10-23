<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/reports.php';
require_once __DIR__ . '/../includes/xlsx_writer.php';

require_login();

try {
    $pdo = get_pdo();

    // Same from/to normalization as the page and the JSON endpoint, so an
    // export always covers exactly the range the report on screen shows.
    [$fromYear, $toYear] = normalize_report_year_range(
        get_distinct_graduation_years($pdo),
        isset($_GET['from']) ? (int) $_GET['from'] : null,
        isset($_GET['to']) ? (int) $_GET['to'] : null
    );

    $report = get_application_filing_report($pdo, $fromYear, $toYear);
    $report['schools'] = array_map(static function (array $school): array {
        $school['school_display'] = strtoupper(format_school_display_name($school['school_name'], $school['campus']));
        return $school;
    }, $report['schools']);

    $bytes = build_application_filing_report_xlsx($report, $fromYear, $toYear);
    $filename = 'APPLICATION_FILING_REPORT_' . ($fromYear === $toYear ? $fromYear : $fromYear . '-' . $toYear) . '.xlsx';

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
