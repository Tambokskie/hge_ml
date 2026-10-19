<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/reports.php';

require_login();

header('Content-Type: application/json');

$year = (int) ($_GET['year'] ?? 0);

if ($year <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'INVALID YEAR.']);
    exit;
}

try {
    $pdo = get_pdo();
    $isApplied = ($_GET['metric'] ?? '') === 'applied';
    $count = $isApplied
        ? get_applied_count_by_year($pdo, $year)
        : get_graduate_count_by_year($pdo, $year);

    $response = ['status' => 'success', 'year' => $year, 'count' => $count];
    // Only the plain (non-applied) call also refreshes the dashboard's
    // TREND card — it's the same data either way, so no need to compute it
    // twice per year-slicer click.
    if (!$isApplied) {
        $response['trend_monthly'] = get_filing_trend_monthly($pdo, $year, $year);
        $response['filing_report'] = get_application_filing_report($pdo, $year);
        $response['honor_breakdown'] = get_honor_tier_breakdown_for_year($pdo, $year);
    }

    echo json_encode($response);
} catch (\Throwable $e) {
    error_log('get_dashboard_year_records failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'THE RECORD COUNT COULD NOT BE LOADED. PLEASE TRY AGAIN.']);
}
