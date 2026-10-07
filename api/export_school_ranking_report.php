<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/reports.php';
require_once __DIR__ . '/../includes/xlsx_writer.php';

require_login();

try {
    $pdo = get_pdo();

    // Same invalid/missing-year fallback as api/get_school_ranking_report.php
    // — defaults to the most recent year with data rather than hard-erroring.
    $year = (int) ($_GET['year'] ?? 0);
    if ($year <= 0) {
        $years = get_distinct_graduation_years($pdo);
        $year = $years[0] ?? (int) date('Y');
    }

    $rows = get_school_ranking_by_honor_count($pdo, $year);
    $rows = array_map(static function (array $row): array {
        $row['school_display'] = format_school_display_name($row['school_name'], $row['campus']);
        return $row;
    }, $rows);

    $bytes = build_school_ranking_report_xlsx($rows, $year);
    $filename = 'SCHOOL_RANKING_REPORT_' . $year . '.xlsx';

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
} catch (\Throwable $e) {
    error_log('School ranking report export failed: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain');
    die('THE REPORT COULD NOT BE GENERATED. PLEASE TRY AGAIN.');
}
