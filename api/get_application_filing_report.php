<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/reports.php';

require_login();

header('Content-Type: application/json');

try {
    $pdo = get_pdo();

    // Missing/tampered from/to (the page's own selects always send real
    // values) fall back to the default range, and an inverted or
    // out-of-range pair is clamped — a report page should always render
    // something coherent. The normalized range is echoed back so the page
    // can resync its selects to what was actually used.
    [$fromYear, $toYear] = normalize_report_year_range(
        get_distinct_graduation_years($pdo),
        isset($_GET['from']) ? (int) $_GET['from'] : null,
        isset($_GET['to']) ? (int) $_GET['to'] : null
    );

    $report = get_application_filing_report($pdo, $fromYear, $toYear);
    $tierBreakdown = get_honor_tier_breakdown_for_year($pdo, $fromYear, $toYear);
    $insights = get_application_filing_insights($report['schools']);
    $updatedAt = get_latest_update_timestamp_for_year($pdo);

    // Trend lines compare against the period of equal length immediately
    // before this one (the previous year when a single year is selected).
    $span = $toYear - $fromYear + 1;
    $previousPeriodReport = get_application_filing_report($pdo, $fromYear - $span, $fromYear - 1);
    $trends = [
        'on_record_change' => compute_percent_change($report['total_on_record'], $previousPeriodReport['total_on_record']),
        'applied_change' => compute_percent_change($report['total_applied'], $previousPeriodReport['total_applied']),
    ];

    $formatSchoolDisplay = static function (array $school): array {
        $school['school_display'] = format_school_display_name($school['school_name'], $school['campus']);
        return $school;
    };

    $report['schools'] = array_map($formatSchoolDisplay, $report['schools']);

    foreach (['top_filing_rate', 'most_applicants'] as $key) {
        if ($insights[$key] !== null) {
            $insights[$key] = $formatSchoolDisplay($insights[$key]);
        }
    }

    echo json_encode([
        'status' => 'success',
        'from' => $fromYear,
        'to' => $toYear,
        'span' => $span,
        'report' => $report,
        'tier_breakdown' => $tierBreakdown,
        'insights' => $insights,
        'trends' => $trends,
        'trend_series' => get_filing_trend($pdo, $fromYear, $toYear),
        'updated_at' => $updatedAt,
    ]);
} catch (\Throwable $e) {
    error_log('get_application_filing_report (api) failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'THE REPORT COULD NOT BE LOADED. PLEASE TRY AGAIN.']);
}
