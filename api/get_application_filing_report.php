<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/reports.php';

require_login();

header('Content-Type: application/json');

try {
    $pdo = get_pdo();

    // An invalid or missing year (a malformed/tampered query string — the
    // page's own <select> always sends a real value) falls back to the
    // most recent year with data rather than hard-erroring, per the design
    // spec: a report page should always render something.
    $year = (int) ($_GET['year'] ?? 0);
    if ($year <= 0) {
        $years = get_distinct_graduation_years($pdo);
        $year = $years[0] ?? (int) date('Y');
    }

    $report = get_application_filing_report($pdo, $year);
    $tierBreakdown = get_honor_tier_breakdown_for_year($pdo, $year);

    $report['schools'] = array_map(static function (array $school): array {
        $school['school_display'] = format_school_display_name($school['school_name'], $school['campus']);
        return $school;
    }, $report['schools']);

    echo json_encode([
        'status' => 'success',
        'year' => $year,
        'report' => $report,
        'tier_breakdown' => $tierBreakdown,
    ]);
} catch (\Throwable $e) {
    error_log('get_application_filing_report (api) failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'THE REPORT COULD NOT BE LOADED. PLEASE TRY AGAIN.']);
}
