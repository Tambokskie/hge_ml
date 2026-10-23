<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/reports.php';
require_once __DIR__ . '/../includes/xlsx_writer.php';

require_login();

/**
 * admin/dashboard.php's DOWNLOAD REPORT panel: turns the panel's two radio
 * modes into a graduation-year [from, to] pair. Deliberately self-contained
 * rather than reusing admin/reports.php's normalize_report_year_range() —
 * that function doesn't exist anywhere in the codebase yet (the Reports
 * Group feature it belongs to is still unfinished), so this dashboard
 * feature doesn't depend on it. "Range of years" counts backward from the
 * most recent graduation year on record, clamped to the earliest year on
 * record rather than going negative.
 *
 * @param int[] $graduationYears
 * @return array{0: int, 1: int}|null null on an invalid/unrecognized selection.
 */
function resolve_dashboard_report_year_range(array $graduationYears, string $mode, ?int $year, ?string $range): ?array
{
    if (empty($graduationYears)) {
        return null;
    }
    $maxYear = max($graduationYears);
    $minYear = min($graduationYears);

    if ($mode === 'year') {
        if ($year === null || !in_array($year, $graduationYears, true)) {
            return null;
        }
        return [$year, $year];
    }

    if ($range === 'all') {
        return [$minYear, $maxYear];
    }

    $spans = ['past5' => 5, 'past4' => 4, 'past3' => 3, 'past2' => 2];
    if (isset($spans[$range ?? ''])) {
        $from = max($minYear, $maxYear - $spans[$range] + 1);
        return [$from, $maxYear];
    }

    return null;
}

try {
    $pdo = get_pdo();
    $graduationYears = get_distinct_graduation_years($pdo);

    $mode = ($_GET['mode'] ?? '') === 'range' ? 'range' : 'year';
    $year = isset($_GET['year']) && $_GET['year'] !== '' ? (int) $_GET['year'] : null;
    $range = $_GET['range'] ?? null;

    $yearRange = resolve_dashboard_report_year_range($graduationYears, $mode, $year, $range);
    if ($yearRange === null) {
        http_response_code(400);
        header('Content-Type: text/plain');
        die('INVALID YEAR SELECTION.');
    }
    [$fromYear, $toYear] = $yearRange;

    $report = get_application_filing_report($pdo, $fromYear, $toYear);
    $report['schools'] = array_map(static function (array $school): array {
        $school['school_display'] = format_school_display_name($school['school_name'], $school['campus']);
        return $school;
    }, $report['schools']);
    usort($report['schools'], static function (array $a, array $b): int {
        return $b['filing_rate'] <=> $a['filing_rate'] ?: strcasecmp($a['school_display'], $b['school_display']);
    });

    $bytes = build_dashboard_filing_report_xlsx($report, $fromYear, $toYear);
    $filename = 'APPLICATION_FILING_REPORT_' . ($fromYear === $toYear ? (string) $fromYear : $fromYear . '-' . $toYear) . '.xlsx';

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
} catch (\Throwable $e) {
    error_log('Dashboard filing report export failed: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain');
    die('THE REPORT COULD NOT BE GENERATED. PLEASE TRY AGAIN.');
}
