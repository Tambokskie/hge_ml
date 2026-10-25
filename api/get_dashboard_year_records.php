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
        $filingReport = get_application_filing_report($pdo, $year);
        $filedYearBreakdown = get_applied_breakdown_by_filed_year($pdo, $year);

        // Mirrors admin/dashboard.php's initial render: ON RECORD, APPLIED,
        // APPROVED and the filing rate derived from them all stay
        // graduation-year scoped (the ring's own population), since dividing
        // on_record by a figure scoped to a *different* population (e.g.
        // applications filed in $year regardless of the applicant's own
        // graduation_year) produces a filing_rate/NOT-YET-APPLIED that don't
        // reconcile with on_record. The filed-year-scoped totals ride along
        // separately as filed_year_* purely so the donut legend's
        // "(N filed in <year>)" annotation can also refresh on a year
        // switch, see includes/reports.php:get_applied_breakdown_by_filed_year().
        $totalOnRecord = $filingReport['total_on_record'];
        $response['filing_report'] = [
            'total_on_record' => $totalOnRecord,
            'total_applied' => $filingReport['total_applied'],
            'total_approved' => $filingReport['total_approved'],
            'filing_rate' => $filingReport['filing_rate'],
            'filed_year_applied' => $filedYearBreakdown['total_applied'],
            'filed_year_approved' => $filedYearBreakdown['total_approved'],
            'year' => $year,
        ];
        $response['honor_breakdown'] = get_honor_tier_breakdown_for_year($pdo, $year);

        // SCHOOL FILING SUMMARY table (admin/dashboard.php): same
        // filing-rate-sorted shape the initial PHP render uses, so the
        // year-slicer's AJAX refresh can feed it straight to the same
        // row-building logic in dashboard.js without a second sort pass there.
        $schools = array_map(static function (array $school): array {
            $school['school_display'] = format_school_display_name($school['school_name'], $school['campus']);
            return $school;
        }, $filingReport['schools']);
        usort($schools, static function (array $a, array $b): int {
            return $b['filing_rate'] <=> $a['filing_rate'] ?: strcasecmp($a['school_display'], $b['school_display']);
        });
        $response['schools'] = $schools;
    }

    echo json_encode($response);
} catch (\Throwable $e) {
    error_log('get_dashboard_year_records failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'THE RECORD COUNT COULD NOT BE LOADED. PLEASE TRY AGAIN.']);
}
