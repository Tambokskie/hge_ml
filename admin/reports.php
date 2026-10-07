<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/reports.php';

require_login();

$pdo = get_pdo();
$currentUser = current_user();
$currentPage = 'reports';
$csrfToken = csrf_token();

$graduationYears = get_distinct_graduation_years($pdo);

// From/To dropdown options: every year from the earliest to the latest year
// on file (a year with no records simply reports zeros). Default range is the
// latest 5-year period, the report's original behavior.
[$fromYear, $toYear] = normalize_report_year_range($graduationYears, null, null);
$yearOptions = $graduationYears === [] ? [$toYear] : range(min($graduationYears), max($graduationYears));
$periodLabel = $fromYear === $toYear ? (string) $fromYear : $fromYear . '–' . $toYear;
$periodSpan = $toYear - $fromYear + 1;

$filingReport = get_application_filing_report($pdo, $fromYear, $toYear);
$tierBreakdown = get_honor_tier_breakdown_for_year($pdo, $fromYear, $toYear);
$insights = get_application_filing_insights($filingReport['schools']);
$updatedAt = get_latest_update_timestamp_for_year($pdo);
$updatedAtDisplay = $updatedAt !== null ? strtoupper(date('F j, Y \a\t g:i A', strtotime($updatedAt))) : null;

$trendSeries = get_filing_trend($pdo, $fromYear, $toYear);

$previousPeriodReport = get_application_filing_report($pdo, $fromYear - $periodSpan, $fromYear - 1);
$onRecordChange = compute_percent_change($filingReport['total_on_record'], $previousPeriodReport['total_on_record']);
$appliedChange = compute_percent_change($filingReport['total_applied'], $previousPeriodReport['total_applied']);

// Rendered twice (ON RECORD card, APPLIED card) — a page-local helper rather
// than duplicating the same arrow/color/sign logic inline. Empty string when
// $change is null (no prior-year baseline), so the caller's wrapper element
// just renders empty rather than needing its own conditional.
function render_trend_html(?float $change, int $span = 1): string
{
    if ($change === null) {
        return '';
    }

    $isUp = $change >= 0;
    $colorClass = $isUp ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400';
    $arrowPath = $isUp
        ? 'M2.25 18L9 11.25l4.306 4.306a11.95 11.95 0 015.814-5.519l2.74-1.22m0 0l-5.94-2.28m5.94 2.28l-2.28 5.941'
        : 'M2.25 6L9 12.75l4.306-4.306a11.95 11.95 0 015.814 5.519l2.74 1.22m0 0l-5.94 2.28m5.94-2.28l-2.28-5.941';
    $sign = $isUp ? '+' : '';

    return '<p class="flex items-center gap-1 text-xs ' . $colorClass . ' mt-2">'
        . '<svg class="h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="' . $arrowPath . '" /></svg>'
        . '<span>' . $sign . number_format(abs($change), 1) . '% FROM PREVIOUS ' . ($span === 1 ? 'YEAR' : $span . ' YEARS') . '</span>'
        . '</p>';
}

// Hand-rolled SVG geometry (donut), the same no-charting-library
// convention already established by admin/dashboard.php's upload-activity
// sparkline — computed once here in PHP; assets/js/reports.js mirrors this
// exact arithmetic for the AJAX year-switch redraw.
$donutRadius = 50;
$donutCircumference = 2 * M_PI * $donutRadius;
$appliedFraction = $filingReport['total_on_record'] > 0
    ? $filingReport['total_applied'] / $filingReport['total_on_record']
    : 0;
$donutAppliedLength = $donutCircumference * $appliedFraction;
$notYetAppliedCount = $filingReport['total_on_record'] - $filingReport['total_applied'];
$notYetAppliedPercent = $filingReport['total_on_record'] > 0
    ? round($notYetAppliedCount / $filingReport['total_on_record'] * 100, 1)
    : 0.0;

$filingSchoolsForJs = array_map(static function (array $school): array {
    $school['school_display'] = format_school_display_name($school['school_name'], $school['campus']);
    return $school;
}, $filingReport['schools']);

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main class="lg:ml-64 min-h-screen bg-gray-50 dark:bg-surface-950">
    <div class="p-6 lg:p-8">
        <div class="max-w-6xl mx-auto">
            <div class="mb-6 flex items-start gap-4 print:hidden">
                <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-csc-navy-600/10 text-csc-navy-600 dark:text-csc-navy-300">
                    <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" /></svg>
                </div>
                <div>
                    <h1 class="text-2xl font-bold text-csc-navy-600 dark:text-white">REPORTS</h1>
                    <p class="text-sm text-gray-600 dark:text-gray-500 mt-1">YEAR-SCOPED SUMMARIES OF LATIN HONOR GRADUATES AND HONOR GRADUATE ELIGIBILITY FILINGS.</p>
                </div>
            </div>

            <!-- APPLICATION FILING REPORT -->
            <div id="filing-report-print-area" class="bg-white dark:bg-surface-900 border border-gray-200 dark:border-white/10 rounded-xl p-6 mb-6 hge-shadow">
                <div class="flex flex-wrap items-center justify-between gap-3 print:hidden">
                    <h2 class="text-lg font-bold text-csc-navy-600 dark:text-white">APPLICATION FILING REPORT</h2>
                    <div class="flex items-center gap-2">
                        <div class="relative">
                            <button type="button" id="filing-export-toggle" aria-expanded="false" aria-controls="filing-export-menu" class="inline-flex items-center gap-1.5 rounded-xl bg-csc-navy-600 hover:bg-csc-navy-700 text-white text-sm font-medium px-4 py-2.5 transition-colors">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                                EXPORT REPORT
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" /></svg>
                            </button>
                            <div id="filing-export-menu" class="hidden absolute right-0 mt-2 w-44 rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-surface-800 shadow-lg z-10 py-1">
                                <a id="filing-report-export" href="<?= e(APP_BASE_URL) ?>/api/export_application_filing_report.php?from=<?= (int) $fromYear ?>&amp;to=<?= (int) $toYear ?>" class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5">
                                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                                    EXPORT XLSX
                                </a>
                                <button type="button" id="filing-print-btn" class="w-full flex items-center gap-2 px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5 text-left">
                                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0110.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0l.229 2.523a1.125 1.125 0 01-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0021 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 00-1.913-.247M6.34 18H5.25A2.25 2.25 0 013 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 011.913-.247m10.5 0a48.536 48.536 0 00-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659M18 10.5h.008v.008H18V10.5zm-3 0h.008v.008H15V10.5z" /></svg>
                                    PRINT REPORT
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-3 mt-3">
                    <div class="flex items-center gap-2 print:hidden">
                        <label for="filing-report-from" class="text-xs font-semibold text-gray-500 dark:text-gray-400">FROM</label>
                        <select id="filing-report-from" class="rounded-xl border border-gray-300 dark:border-white/15 bg-white dark:bg-surface-800 text-sm px-3 py-2">
                            <?php foreach ($yearOptions as $y): ?>
                                <option value="<?= (int) $y ?>" <?= $y === $fromYear ? 'selected' : '' ?> <?= $y > $toYear ? 'disabled' : '' ?>><?= (int) $y ?></option>
                            <?php endforeach; ?>
                        </select>
                        <label for="filing-report-to" class="text-xs font-semibold text-gray-500 dark:text-gray-400">TO</label>
                        <select id="filing-report-to" class="rounded-xl border border-gray-300 dark:border-white/15 bg-white dark:bg-surface-800 text-sm px-3 py-2">
                            <?php foreach ($yearOptions as $y): ?>
                                <option value="<?= (int) $y ?>" <?= $y === $toYear ? 'selected' : '' ?> <?= $y < $fromYear ? 'disabled' : '' ?>><?= (int) $y ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <p class="hidden print:block text-xs font-semibold text-gray-700">REPORTING PERIOD: <span id="filing-print-period"><?= e($periodLabel) ?></span></p>
                    <p id="filing-updated-at-row" class="flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400 <?= $updatedAtDisplay === null ? 'hidden' : '' ?>">
                        <span class="h-1.5 w-1.5 rounded-full bg-green-500"></span>
                        DATA UPDATED: <span id="filing-updated-at"><?= e($updatedAtDisplay ?? '') ?></span>
                    </p>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-4 mb-4">
                    <div class="dashboard-stat-card items-start text-left motion-safe:animate-fade-in-up" style="animation-delay: 0ms">
                        <div class="flex items-center gap-3 min-w-0">
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-csc-navy-600/10 text-csc-navy-600 dark:bg-csc-navy-600/15 dark:text-csc-navy-300">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M4.26 10.147a60.436 60.436 0 00-.491 6.347A48.627 48.627 0 0112 20.904a48.627 48.627 0 018.232-4.41 60.46 60.46 0 00-.491-6.347M4.26 10.147a50.548 50.548 0 00-2.658-.813A59.905 59.905 0 0112 3.493a59.902 59.902 0 0110.399 5.841c-.896.248-1.783.52-2.658.814M4.26 10.147a50.696 50.696 0 017.74-3.342M12 13.489a50.702 50.702 0 017.74-3.342" /></svg>
                            </span>
                            <p class="text-xs text-gray-600 dark:text-gray-500">ON RECORD</p>
                        </div>
                        <p id="filing-total-on-record" class="dashboard-stat-number" data-count-to="<?= (int) $filingReport['total_on_record'] ?>">0</p>
                        <p class="text-xs text-gray-500 dark:text-gray-500">LATIN HONOR GRADUATES</p>
                        <div id="filing-on-record-trend"><?= render_trend_html($onRecordChange, $periodSpan) ?></div>
                    </div>
                    <div class="dashboard-stat-card items-start text-left motion-safe:animate-fade-in-up" style="animation-delay: 100ms">
                        <div class="flex items-center gap-3 min-w-0">
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-green-500/10 text-green-600 dark:text-green-400">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                            </span>
                            <p class="text-xs text-gray-600 dark:text-gray-500">APPLIED</p>
                        </div>
                        <p id="filing-total-applied" class="dashboard-stat-number" data-count-to="<?= (int) $filingReport['total_applied'] ?>">0</p>
                        <p class="text-xs text-gray-500 dark:text-gray-500">HGE APPLICATIONS</p>
                        <div id="filing-applied-trend"><?= render_trend_html($appliedChange, $periodSpan) ?></div>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-5">
                    <div class="bg-gray-50 dark:bg-white/5 border border-gray-100 dark:border-white/5 rounded-xl p-4 flex flex-col motion-safe:animate-fade-in-up" style="animation-delay: 600ms">
                        <h3 class="flex items-center gap-2 text-xs font-bold tracking-wide text-csc-navy-600 dark:text-csc-navy-300 mb-3">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 00-.182.557l1.285 5.385a.562.562 0 01-.84.61l-4.725-2.885a.562.562 0 00-.586 0L6.982 20.54a.562.562 0 01-.84-.61l1.285-5.386a.562.562 0 00-.182-.557l-4.204-3.602a.562.562 0 01.321-.988l5.518-.442a.563.563 0 00.475-.345L11.48 3.5z" /></svg>
                            LATIN HONOR DISTRIBUTION
                        </h3>
                        <div id="filing-tier-distribution" class="flex-1 flex flex-col justify-center space-y-3">
                            <?php
                            $tierOnRecord = $filingReport['total_on_record'];
                            $tierIds = ['CUM LAUDE' => 'cum-laude', 'MAGNA CUM LAUDE' => 'magna-cum-laude', 'SUMMA CUM LAUDE' => 'summa-cum-laude'];
                            foreach ($tierIds as $tierName => $tierId):
                                $tierCount = $tierBreakdown[$tierName];
                                $tierPercent = $tierOnRecord > 0 ? round($tierCount / $tierOnRecord * 100, 1) : 0.0;
                            ?>
                                <div>
                                    <div class="flex items-center justify-between text-xs mb-1">
                                        <span class="text-gray-700 dark:text-gray-300"><?= e($tierName) ?></span>
                                        <span class="text-gray-500 dark:text-gray-400"><span id="tier-<?= $tierId ?>" class="dashboard-stat-reveal" data-count-to="<?= $tierCount ?>">0</span> <span id="tier-<?= $tierId ?>-percent">(<?= e((string) $tierPercent) ?>%)</span></span>
                                    </div>
                                    <div class="h-1.5 w-full rounded-full bg-gray-200 dark:bg-white/10 overflow-hidden">
                                        <div id="tier-<?= $tierId ?>-bar" class="tier-bar-animated h-full rounded-full bg-csc-navy-600 dark:bg-csc-navy-300 motion-safe:transition-all motion-safe:duration-1000 motion-safe:ease-out" style="width: 0%" data-target-width="<?= $tierPercent ?>"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="bg-gray-50 dark:bg-white/5 border border-gray-100 dark:border-white/5 rounded-xl p-4 motion-safe:animate-fade-in-up" style="animation-delay: 700ms">
                        <h3 class="flex items-center gap-2 text-xs font-bold tracking-wide text-csc-navy-600 dark:text-csc-navy-300 mb-3">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" /></svg>
                            APPLICATION FILING OVERVIEW
                        </h3>
                        <div class="relative h-32 w-32 mx-auto">
                            <svg viewBox="0 0 120 120" class="h-32 w-32 -rotate-90">
                                <circle cx="60" cy="60" r="<?= $donutRadius ?>" fill="none" stroke="currentColor" stroke-width="16" class="text-sky-100 dark:text-sky-500/15" />
                                <circle id="filing-donut-applied-arc" cx="60" cy="60" r="<?= $donutRadius ?>" fill="none" stroke="currentColor" stroke-width="16" class="text-csc-navy-600 dark:text-csc-navy-300 motion-safe:transition-all motion-safe:duration-1000 motion-safe:ease-out" stroke-dasharray="<?= round($donutCircumference, 2) ?>" stroke-dashoffset="<?= round($donutCircumference, 2) ?>" data-target-offset="<?= round($donutCircumference - $donutAppliedLength, 2) ?>" />
                            </svg>
                            <div class="absolute inset-0 flex flex-col items-center justify-center">
                                <span id="filing-rate" class="text-xl font-bold text-csc-navy-600 dark:text-white"><?= e((string) $filingReport['filing_rate']) ?>%</span>
                                <span class="text-[9px] text-gray-500 dark:text-gray-400 tracking-wide">FILING RATE</span>
                            </div>
                        </div>
                        <div id="filing-donut-legend" class="dashboard-stat-reveal space-y-1.5 mt-3 text-xs">
                            <div class="flex items-center justify-between">
                                <span class="flex items-center gap-1.5 text-gray-600 dark:text-gray-400"><span class="h-2 w-2 rounded-full bg-gray-400"></span>ON RECORD</span>
                                <span class="text-gray-700 dark:text-gray-300"><?= number_format($filingReport['total_on_record']) ?> (100%)</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="flex items-center gap-1.5 text-gray-600 dark:text-gray-400"><span class="h-2 w-2 rounded-full bg-csc-navy-600 dark:bg-csc-navy-300"></span>APPLIED</span>
                                <span class="text-gray-700 dark:text-gray-300"><?= number_format($filingReport['total_applied']) ?> (<?= e((string) $filingReport['filing_rate']) ?>%)</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="flex items-center gap-1.5 text-gray-600 dark:text-gray-400"><span class="h-2 w-2 rounded-full bg-sky-200 dark:bg-sky-500/30"></span>NOT YET APPLIED</span>
                                <span class="text-gray-700 dark:text-gray-300"><?= number_format($notYetAppliedCount) ?> (<?= e((string) $notYetAppliedPercent) ?>%)</span>
                            </div>
                        </div>
                    </div>

                    <div class="bg-gray-50 dark:bg-white/5 border border-gray-100 dark:border-white/5 rounded-xl p-4 motion-safe:animate-fade-in-up" style="animation-delay: 800ms">
                        <h3 class="flex items-center gap-2 text-xs font-bold tracking-wide text-csc-navy-600 dark:text-csc-navy-300 mb-3">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M12 18v-5.25m0 0a6.01 6.01 0 001.5-.189m-1.5.189a6.01 6.01 0 01-1.5-.189m3.75 7.478a12.06 12.06 0 01-4.5 0m3.75 2.383a14.406 14.406 0 01-3 0M14.25 18v-.192c0-.983.658-1.823 1.508-2.316a7.5 7.5 0 10-7.517 0c.85.493 1.509 1.333 1.509 2.316V18" /></svg>
                            KEY INSIGHTS
                        </h3>
                        <div id="filing-insights" class="dashboard-stat-reveal space-y-4">
                            <?php if ($insights['top_filing_rate'] === null): ?>
                                <p id="filing-insights-empty" class="text-xs text-gray-500 dark:text-gray-400">NO HONOR GRADUATE RECORDS FOUND FOR THIS YEAR.</p>
                            <?php else: ?>
                                <?php
                                $topRate = $insights['top_filing_rate'];
                                $mostApplicants = $insights['most_applicants'];
                                ?>
                                <div id="filing-insight-top-rate">
                                    <p class="flex items-center gap-1.5 text-[11px] font-bold tracking-wide text-yellow-600 dark:text-yellow-400">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 18.75h-9m9 0a3 3 0 013 3h-15a3 3 0 013-3m9 0v-3.675A55.378 55.378 0 0012 15c-1.51 0-3.015.05-4.5.15V18.75m9 0a3 3 0 00-3-3h-3a3 3 0 00-3 3m6-9v-1.5m0 1.5a9 9 0 01-9-9V3a.75.75 0 01.75-.75h16.5a.75.75 0 01.75.75v.75a9 9 0 01-9 9z" /></svg>
                                        TOP FILING RATE
                                    </p>
                                    <p id="filing-insight-top-rate-school" class="text-sm font-semibold text-gray-800 dark:text-white mt-1"><?= e_upper(format_school_display_name($topRate['school_name'], $topRate['campus'])) ?></p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                        <span id="filing-insight-top-rate-value" class="text-green-600 dark:text-green-400 font-semibold"><?= e((string) $topRate['filing_rate']) ?>% FILING RATE</span>
                                        · <span id="filing-insight-top-rate-detail"><?= (int) $topRate['applied'] ?> OF <?= (int) $topRate['on_record'] ?> GRADUATES HAVE FILED</span>
                                    </p>
                                </div>
                                <div id="filing-insight-most-applicants">
                                    <p class="flex items-center gap-1.5 text-[11px] font-bold tracking-wide text-csc-navy-600 dark:text-csc-navy-300">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" /></svg>
                                        MOST APPLICANTS
                                    </p>
                                    <p id="filing-insight-most-applicants-school" class="text-sm font-semibold text-gray-800 dark:text-white mt-1"><?= e_upper(format_school_display_name($mostApplicants['school_name'], $mostApplicants['campus'])) ?></p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                        <span id="filing-insight-most-applicants-value"><?= (int) $mostApplicants['applied'] ?> APPLICATIONS</span>
                                    </p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="bg-gray-50 dark:bg-white/5 border border-gray-100 dark:border-white/5 rounded-xl p-4 mb-5 motion-safe:animate-fade-in-up" style="animation-delay: 900ms">
                    <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
                        <div class="flex items-center gap-3">
                            <h3 class="flex items-center gap-2 text-xs font-bold tracking-wide text-csc-navy-600 dark:text-csc-navy-300">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18L9 11.25l4.306 4.306a11.95 11.95 0 015.814-5.519l2.74-1.22m0 0l-5.94-2.28m5.94 2.28l-2.28 5.941" /></svg>
                                TREND
                            </h3>
                        </div>
                        <div class="flex flex-wrap items-center gap-4 text-xs text-gray-600 dark:text-gray-400">
                            <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-csc-navy-600 dark:bg-csc-navy-300"></span>LATIN HONORS GRADUATED (RIGHT AXIS)</span>
                            <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-emerald-500"></span>LATIN HONORS APPLIED FOR HGE (LEFT AXIS)</span>
                        </div>
                    </div>
                    <div id="filing-trend-chart" class="relative w-full select-none" style="height: 260px"></div>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3 mb-3 print:hidden">
                    <h3 class="flex items-center gap-2 text-sm font-bold text-csc-navy-600 dark:text-white">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" /></svg>
                        SCHOOL FILING SUMMARY
                        <span id="filing-school-count" class="text-xs font-normal text-gray-500 dark:text-gray-400"><?= count($filingReport['schools']) ?> SCHOOL<?= count($filingReport['schools']) === 1 ? '' : 'S' ?></span>
                    </h3>
                    <div class="flex flex-wrap items-center gap-2">
                        <div class="relative">
                            <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-gray-400 dark:text-gray-500">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607Z" /></svg>
                            </span>
                            <input type="text" id="filing-school-search" placeholder="SEARCH SCHOOL..." autocomplete="off" class="rounded-xl border border-gray-300 dark:border-white/15 bg-white dark:bg-surface-800 text-sm pl-9 pr-3 py-2 w-44">
                        </div>
                        <select id="filing-school-sort" class="rounded-xl border border-gray-300 dark:border-white/15 bg-white dark:bg-surface-800 text-sm px-3 py-2">
                            <option value="filing_rate_desc">FILING RATE</option>
                            <option value="on_record_desc">ON RECORD</option>
                            <option value="applied_desc">APPLIED</option>
                            <option value="school_name_asc">SCHOOL NAME</option>
                        </select>
                        <select id="filing-school-status-filter" class="rounded-xl border border-gray-300 dark:border-white/15 bg-white dark:bg-surface-800 text-sm px-3 py-2">
                            <option value="all">ALL SCHOOLS</option>
                            <option value="filed">HAS FILED</option>
                            <option value="not_filed">NO FILINGS YET</option>
                        </select>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="text-xs text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-white/10">
                            <tr>
                                <th class="px-4 py-3">#</th>
                                <th class="px-4 py-3">SCHOOL</th>
                                <th class="px-4 py-3">ON RECORD</th>
                                <th class="px-4 py-3">APPLIED</th>
                                <th class="px-4 py-3">FILING RATE</th>
                            </tr>
                        </thead>
                        <tbody id="filing-report-tbody">
                            <?php if (empty($filingReport['schools'])): ?>
                                <tr><td colspan="5" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400">NO HONOR GRADUATE RECORDS FOUND FOR THIS YEAR.</td></tr>
                            <?php else: ?>
                                <?php foreach ($filingReport['schools'] as $index => $school): ?>
                                    <tr class="border-b border-gray-100 dark:border-white/5">
                                        <td class="px-4 py-3 text-gray-400 dark:text-gray-500"><?= (int) ($index + 1) ?></td>
                                        <td class="px-4 py-3"><?= e_upper(format_school_display_name($school['school_name'], $school['campus'])) ?></td>
                                        <td class="px-4 py-3"><?= (int) $school['on_record'] ?></td>
                                        <td class="px-4 py-3">
                                            <span class="inline-flex items-center justify-center rounded-full px-2.5 py-0.5 text-xs font-semibold <?= $school['applied'] > 0 ? 'bg-csc-navy-600/10 text-csc-navy-600 dark:bg-csc-navy-600/20 dark:text-csc-navy-300' : 'bg-gray-100 text-gray-500 dark:bg-white/5 dark:text-gray-400' ?>"><?= (int) $school['applied'] ?></span>
                                        </td>
                                        <td class="px-4 py-3">
                                            <span class="inline-flex items-center justify-center rounded-full px-2.5 py-0.5 text-xs font-semibold <?= $school['filing_rate'] > 0 ? 'bg-green-500/15 text-green-700 dark:bg-green-500/10 dark:text-green-400' : 'bg-gray-100 text-gray-500 dark:bg-white/5 dark:text-gray-400' ?>"><?= e((string) $school['filing_rate']) ?>%</span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
<script>
window.HGE_REPORTS = {
    apiBase: <?= json_encode(APP_BASE_URL) ?>,
    csrfToken: <?= json_encode($csrfToken) ?>,
    initialSchools: <?= json_encode($filingSchoolsForJs) ?>,
    initialTrend: <?= json_encode($trendSeries) ?>,
    initialFrom: <?= (int) $fromYear ?>,
    initialTo: <?= (int) $toYear ?>
};
</script>
<script src="<?= e(asset_url('assets/js/reports.js')) ?>"></script>
