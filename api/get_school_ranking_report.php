<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/reports.php';

require_login();

header('Content-Type: application/json');

try {
    $pdo = get_pdo();

    // Same invalid/missing-year fallback as get_application_filing_report.php
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

    echo json_encode(['status' => 'success', 'year' => $year, 'rows' => $rows]);
} catch (\Throwable $e) {
    error_log('get_school_ranking_report (api) failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'THE REPORT COULD NOT BE LOADED. PLEASE TRY AGAIN.']);
}
