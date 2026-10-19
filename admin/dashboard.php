<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/uploads.php';
require_once __DIR__ . '/../includes/activity.php';
require_once __DIR__ . '/../includes/reports.php';

require_login();

$pdo = get_pdo();
$stats = get_dashboard_stats($pdo);
$graduationYears = get_distinct_graduation_years($pdo);
$applicationYears = get_application_years($pdo);

// One shared year list/selection drives the APPLIED, CURRENT YEAR RECORDS
// AND TREND cards (a single slicer instead of two independent dropdowns) —
// the two source year lists can genuinely differ (an application can be
// filed in a different year than the graduate's own graduation_year), so
// the slicer offers their union, and all three are recomputed fresh for
// whichever single year ends up selected by default, rather than reusing
// get_dashboard_stats()'s two independently-defaulted values.
$dashboardYears = array_values(array_unique(array_merge($graduationYears, $applicationYears)));
rsort($dashboardYears);
$dashboardDefaultYear = $stats['current_year'] ?? (int) date('Y');
$dashboardDefaultYearRecords = get_graduate_count_by_year($pdo, $dashboardDefaultYear);
$dashboardDefaultApplied = get_applied_count_by_year($pdo, $dashboardDefaultYear);

// TREND card: the selected year's own graduates-on-record vs. HGE
// applications, broken down by month — tied to the same slicer rather than
// admin/reports.php's multi-year range, since the slicer already picks one
// year at a time.
$trendMonthly = get_filing_trend_monthly($pdo, $dashboardDefaultYear, $dashboardDefaultYear);

// APPLICATION FILING OVERVIEW card: same donut as admin/reports.php, but for
// the single selected year rather than a from/to range ($toYear defaults to
// $fromYear) — tied to the same slicer as the two cards above.
$filingReport = get_application_filing_report($pdo, $dashboardDefaultYear);
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

$recentUploads = get_recent_uploads($pdo, 10);

// LATIN HONOR DISTRIBUTION now lives inside the APPLICATION FILING OVERVIEW
// card (merged) and is tied to the same single-year slicer as the donut
// above, rather than includes/functions.php's all-time get_honor_type_breakdown().
$honorBreakdown = get_honor_tier_breakdown_for_year($pdo, $dashboardDefaultYear);
$currentUser = current_user();
$currentPage = 'dashboard';
$csrfToken = csrf_token();
$isAdmin = user_has_role($currentUser, 'administrator');
$recentActivity = $isAdmin ? get_recent_activity($pdo, 6) : [];

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main class="lg:ml-64 min-h-screen bg-gray-50 dark:bg-surface-950">
    <div class="p-6 lg:p-8">
        <div class="mb-6">
            <div class="flex items-start gap-4">
                <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-csc-navy-600/10 text-csc-navy-600 dark:text-csc-navy-300">
                    <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z" /></svg>
                </div>
                <div>
                    <h1 class="text-2xl font-bold text-csc-navy-600 dark:text-white">DASHBOARD</h1>
                    <p class="text-sm text-gray-600 dark:text-gray-500 mt-1">WELCOME, <span class="font-semibold text-csc-navy-600 dark:text-csc-navy-300"><?= e_upper($currentUser['name'] ?? '') ?></span>!</p>
                </div>
            </div>
            <?php if (!empty($dashboardYears)): ?>
                <?php $dashboardYearsNeedPaging = count($dashboardYears) > 5; ?>
                <div class="mt-4 flex items-center justify-center gap-2">
                    <?php if ($dashboardYearsNeedPaging): ?>
                        <button type="button" id="dashboard-year-prev" class="shrink-0 rounded-lg p-1.5 text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-white/5 disabled:opacity-30 disabled:pointer-events-none motion-safe:animate-fade-in" style="animation-delay: 100ms" aria-label="Show more recent years">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" /></svg>
                        </button>
                    <?php endif; ?>
                    <div id="dashboard-year-viewport" class="<?= $dashboardYearsNeedPaging ? 'overflow-hidden w-[296px]' : '' ?> rounded-xl bg-gray-100 dark:bg-white/5 p-1 motion-safe:animate-fade-in" style="animation-delay: 100ms">
                        <div id="dashboard-year-track" class="inline-flex gap-1 transition-transform duration-300 ease-out" role="group" aria-label="Filter dashboard by year">
                            <?php foreach ($dashboardYears as $y): ?>
                                <button type="button"
                                    class="dashboard-year-pill w-14 shrink-0 rounded-lg px-3 py-1.5 text-xs font-semibold text-center transition-colors<?= $y === $dashboardDefaultYear ? ' bg-csc-navy-600 text-white' : ' text-gray-600 dark:text-gray-400 hover:bg-white dark:hover:bg-white/10' ?>"
                                    data-year="<?= (int) $y ?>"
                                    aria-pressed="<?= $y === $dashboardDefaultYear ? 'true' : 'false' ?>"><?= (int) $y ?></button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php if ($dashboardYearsNeedPaging): ?>
                        <button type="button" id="dashboard-year-next" class="shrink-0 rounded-lg p-1.5 text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-white/5 disabled:opacity-30 disabled:pointer-events-none motion-safe:animate-fade-in" style="animation-delay: 100ms" aria-label="Show older years">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                        </button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="dashboard-stat-card motion-safe:animate-fade-in-up" style="animation-delay: 0ms">
                <div class="flex items-center gap-3 self-start">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-csc-navy-600/10 text-csc-navy-600 dark:bg-csc-navy-600/15 dark:text-csc-navy-300">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" /></svg>
                    </span>
                    <p class="text-xs text-gray-600 dark:text-gray-500">RECORD FOR THE YEAR SELECTED</p>
                </div>
                <p class="dashboard-stat-number" id="dashboard-year-records-number" data-count-to="<?= (int) $dashboardDefaultYearRecords ?>">0</p>
                <?php if (!empty($graduationYears)): ?>
                    <p id="dashboard-year-records-label" class="dashboard-stat-reveal text-xs text-gray-400 dark:text-gray-600"><?= (int) $dashboardDefaultYear ?></p>
                <?php else: ?>
                    <p class="text-xs text-gray-400 dark:text-gray-600">NO DATA YET</p>
                <?php endif; ?>
            </div>
            <?php $schoolsMissing = max(0, $stats['total_active_schools'] - $stats['total_schools']); ?>
            <div class="dashboard-stat-card motion-safe:animate-fade-in-up" style="animation-delay: 200ms">
                <div class="flex items-center gap-3 self-start">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-green-500/10 text-green-600 dark:text-green-400">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    </span>
                    <p class="text-xs text-gray-600 dark:text-gray-500">HGE APPLICATIONS RECEIVED</p>
                </div>
                <div>
                    <p class="dashboard-stat-number" id="dashboard-applied-number" data-count-to="<?= (int) $dashboardDefaultApplied ?>">0</p>
                    <p id="dashboard-applied-year-label" class="dashboard-stat-reveal mt-0.5 text-xs text-gray-500 dark:text-gray-500">HGE APPLICATION AS OF <?= (int) $dashboardDefaultYear ?></p>
                </div>
            </div>
            <div class="dashboard-stat-card motion-safe:animate-fade-in-up" style="animation-delay: 400ms">
                <div class="flex items-center gap-3 self-start">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-csc-navy-600/10 text-csc-navy-600 dark:bg-csc-navy-600/15 dark:text-csc-navy-300">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" /></svg>
                    </span>
                    <p class="text-xs text-gray-600 dark:text-gray-500">SCHOOLS WITH RECORDS</p>
                </div>
                <div>
                    <p class="flex items-baseline justify-center gap-1">
                        <span class="dashboard-stat-number" data-count-to="<?= (int) $stats['total_schools'] ?>">0</span>
                        <span class="text-lg font-semibold text-gray-400 dark:text-gray-500">/</span>
                        <span class="dashboard-stat-reveal text-lg font-semibold text-gray-400 dark:text-gray-500" data-count-to="<?= (int) $stats['total_active_schools'] ?>">0</span>
                    </p>
                    <p class="dashboard-stat-reveal text-[11px] text-gray-500 dark:text-gray-500 mt-0.5">
                        <?= $schoolsMissing > 0 ? $schoolsMissing . ' SCHOOL' . ($schoolsMissing === 1 ? '' : 'S') . ' WITH NO SUBMISSION YET' : 'ALL SCHOOLS HAVE SUBMITTED' ?>
                    </p>
                </div>
            </div>
            <div class="dashboard-stat-card motion-safe:animate-fade-in-up" style="animation-delay: 600ms">
                <div class="flex items-center gap-3 self-start">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-csc-navy-600/10 text-csc-navy-600 dark:bg-csc-navy-600/15 dark:text-csc-navy-300">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M4.26 10.147a60.436 60.436 0 00-.491 6.347A48.627 48.627 0 0112 20.904a48.627 48.627 0 018.232-4.41 60.46 60.46 0 00-.491-6.347M4.26 10.147a50.548 50.548 0 00-2.658-.813A59.905 59.905 0 0112 3.493a59.902 59.902 0 0110.399 5.841c-.896.248-1.783.52-2.658.814M4.26 10.147a50.696 50.696 0 017.74-3.342M12 13.489a50.702 50.702 0 017.74-3.342" /></svg>
                    </span>
                    <p class="text-xs text-gray-600 dark:text-gray-500">TOTAL GRADUATES ON RECORD</p>
                </div>
                <p class="dashboard-stat-number" data-count-to="<?= (int) $stats['total_graduates'] ?>">0</p>
            </div>
        </div>

        <?php if ($isAdmin): ?>
        <div class="flex flex-wrap justify-end gap-3 mt-4">
            <a href="<?= e(APP_BASE_URL) ?>/admin/schools.php" class="inline-flex items-center gap-2 rounded-lg border border-gray-200 dark:border-white/10 bg-white dark:bg-surface-900 px-4 py-2 text-sm font-semibold text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-white/5 transition-colors motion-safe:animate-fade-in" style="animation-delay: 400ms">SCHOOL MANAGEMENT</a>
            <a href="<?= e(APP_BASE_URL) ?>/admin/upload.php" class="inline-flex items-center gap-2 rounded-lg bg-csc-navy-600 px-4 py-2 text-sm font-semibold text-white hover:brightness-110 transition-all motion-safe:animate-fade-in" style="animation-delay: 600ms">UPLOAD PDF LIST</a>
        </div>
        <?php endif; ?>

        <div class="mt-8 grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div class="bg-white dark:bg-surface-900 border border-gray-100 dark:border-white/5 rounded-2xl p-5 hge-shadow motion-safe:animate-fade-in-up" style="animation-delay: 200ms">
                <h2 class="flex items-center gap-2 text-sm font-semibold text-gray-900 dark:text-white mb-5">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" /></svg>
                    APPLICATION FILING OVERVIEW
                </h2>
                <div class="flex items-center gap-6">
                    <div id="filing-donut-legend" class="dashboard-stat-reveal space-y-3 text-sm">
                        <div class="flex items-center gap-3">
                            <span class="flex items-center gap-2 text-gray-600 dark:text-gray-400 w-[150px] shrink-0"><span class="h-2.5 w-2.5 rounded-full bg-gray-400 shrink-0"></span>ON RECORD</span>
                            <span class="text-gray-700 dark:text-gray-300"><span id="filing-onrecord-count"><?= number_format($filingReport['total_on_record']) ?></span> (<span id="filing-onrecord-percent">100%</span>)</span>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="flex items-center gap-2 text-gray-600 dark:text-gray-400 w-[150px] shrink-0"><span class="h-2.5 w-2.5 rounded-full bg-csc-navy-600 dark:bg-csc-navy-300 shrink-0"></span>APPLIED</span>
                            <span class="text-gray-700 dark:text-gray-300"><span id="filing-applied-count"><?= number_format($filingReport['total_applied']) ?></span> (<span id="filing-applied-percent"><?= e((string) $filingReport['filing_rate']) ?>%</span>)</span>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="flex items-center gap-2 text-gray-600 dark:text-gray-400 w-[150px] shrink-0"><span class="h-2.5 w-2.5 rounded-full bg-sky-200 dark:bg-sky-500/30 shrink-0"></span>NOT YET APPLIED</span>
                            <span class="text-gray-700 dark:text-gray-300"><span id="filing-notyetapplied-count"><?= number_format($notYetAppliedCount) ?></span> (<span id="filing-notyetapplied-percent"><?= e((string) $notYetAppliedPercent) ?>%</span>)</span>
                        </div>
                    </div>
                    <div class="flex-1 flex justify-center">
                        <div class="relative h-40 w-40 shrink-0">
                            <svg viewBox="0 0 120 120" class="h-40 w-40 -rotate-90">
                                <circle cx="60" cy="60" r="<?= $donutRadius ?>" fill="none" stroke="currentColor" stroke-width="16" class="text-sky-100 dark:text-sky-500/15" />
                                <circle id="filing-donut-applied-arc" cx="60" cy="60" r="<?= $donutRadius ?>" fill="none" stroke="currentColor" stroke-width="16" class="text-csc-navy-600 dark:text-csc-navy-300 motion-safe:transition-all motion-safe:duration-1000 motion-safe:ease-out" stroke-dasharray="<?= round($donutCircumference, 2) ?>" stroke-dashoffset="<?= round($donutCircumference, 2) ?>" data-target-offset="<?= round($donutCircumference - $donutAppliedLength, 2) ?>" />
                            </svg>
                            <div class="absolute inset-0 flex flex-col items-center justify-center">
                                <span id="filing-rate" class="text-2xl font-bold text-csc-navy-600 dark:text-white"><?= e((string) $filingReport['filing_rate']) ?>%</span>
                                <span class="text-[10px] text-gray-500 dark:text-gray-400 tracking-wide">FILING RATE</span>
                            </div>
                        </div>
                    </div>
                </div>
                <?php if (array_sum($honorBreakdown) === 0): ?>
                    <p class="text-sm text-gray-600 dark:text-gray-500 text-center py-8">NO RECORDS YET.</p>
                <?php else: ?>
                    <?php
                    $honorTotal = array_sum($honorBreakdown);
                    $honorTierIds = ['CUM LAUDE' => 'cum-laude', 'MAGNA CUM LAUDE' => 'magna-cum-laude', 'SUMMA CUM LAUDE' => 'summa-cum-laude'];
                    ?>
                    <div class="space-y-4 mt-6 pt-5 border-t border-gray-100 dark:border-white/5">
                        <?php foreach ($honorTierIds as $honor => $honorTierId): ?>
                            <?php
                            $honorCount = $honorBreakdown[$honor];
                            $honorPercent = $honorTotal > 0 ? round($honorCount / $honorTotal * 100, 1) : 0.0;
                            ?>
                            <div>
                                <div class="flex items-center justify-between text-sm mb-1.5">
                                    <span class="text-gray-700 dark:text-gray-300"><?= e($honor) ?></span>
                                    <span class="text-gray-500 dark:text-gray-400"><span id="dashboard-tier-<?= $honorTierId ?>" class="dashboard-stat-reveal" data-count-to="<?= $honorCount ?>">0</span> <span id="dashboard-tier-<?= $honorTierId ?>-percent">(<?= e((string) $honorPercent) ?>%)</span></span>
                                </div>
                                <div class="h-2 w-full rounded-full bg-gray-200 dark:bg-white/10 overflow-hidden">
                                    <div id="dashboard-tier-<?= $honorTierId ?>-bar" class="tier-bar-animated h-full rounded-full bg-csc-navy-600 dark:bg-csc-navy-300 motion-safe:transition-all motion-safe:duration-1000 motion-safe:ease-out" style="width: 0%" data-target-width="<?= $honorPercent ?>"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="bg-white dark:bg-surface-900 border border-gray-100 dark:border-white/5 rounded-2xl p-5 hge-shadow motion-safe:animate-fade-in-up" style="animation-delay: 400ms">
                <div class="mb-3">
                    <h2 class="flex items-center gap-2 text-sm font-semibold text-gray-900 dark:text-white">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18L9 11.25l4.306 4.306a11.95 11.95 0 015.814-5.519l2.74-1.22m0 0l-5.94-2.28m5.94 2.28l-2.28 5.941" /></svg>
                        TREND
                    </h2>
                    <div class="flex flex-wrap items-center gap-4 mt-6 text-xs text-gray-600 dark:text-gray-400">
                        <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-csc-navy-600 dark:bg-csc-navy-300"></span>GRADUATED WITH LATIN HONORS (On Record)</span>
                        <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-emerald-500"></span><span id="filing-trend-applied-label">LATIN HONORS APPLIED FOR HGE</span></span>
                    </div>
                </div>
                <div id="filing-trend-chart" class="relative w-full select-none mt-8" style="height: 220px"></div>
                <p id="filing-trend-note" class="hidden mt-2 text-[11px] text-gray-500 dark:text-gray-400"></p>
            </div>
        </div>

        <div class="mt-8">
            <h2 class="text-sm font-semibold text-gray-500 dark:text-gray-400 mb-3 tracking-wide">LATEST UPLOAD</h2>
            <div class="bg-white dark:bg-surface-900 border border-gray-100 dark:border-white/5 rounded-2xl overflow-x-auto overflow-y-hidden hge-shadow">
                <?php if (empty($recentUploads)): ?>
                    <p class="text-sm text-gray-600 dark:text-gray-500 p-6 text-center">NO UPLOADS YET.</p>
                <?php else: ?>
                    <table class="w-full text-sm text-left">
                        <thead>
                            <tr class="text-sm font-semibold text-gray-900 dark:text-white border-b border-gray-200 dark:border-white/10">
                                <th class="px-4 py-3">SCHOOL</th>
                                <th class="px-4 py-3">YEAR</th>
                                <th class="px-4 py-3">RECORDS EXTRACTED</th>
                                <th class="px-4 py-3">DATE UPLOADED</th>
                                <th class="px-4 py-3">FILE NAME</th>
                                <th class="px-4 py-3 text-right">ACTION</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentUploads as $rowIndex => $upload):
                                $agingClass = pending_upload_age_class($upload);
                                // The critical-aging tier already pulses continuously via its own
                                // animation; stacking the fade-in-up entrance on top of it would
                                // fight over the single CSS `animation` property, so skip it there.
                                $hasPulseAnimation = strpos($agingClass, 'animate-pending-critical-pulse') !== false;
                            ?>
                                <tr class="border-b border-gray-100 dark:border-white/5 hover:bg-gray-100 dark:hover:bg-white/5<?= $agingClass !== '' ? ' ' . $agingClass : '' ?><?= (int) $upload['records_extracted'] === 0 ? ' text-green-700 dark:text-green-400' : '' ?><?= $hasPulseAnimation ? '' : ' motion-safe:animate-fade-in-up' ?>"<?= $hasPulseAnimation ? '' : ' style="animation-delay: ' . ($rowIndex * 50) . 'ms"' ?>>
                                    <td class="px-4 py-3"><?= e_upper(format_school_display_name($upload['school_name'], $upload['campus'])) ?></td>
                                    <td class="px-4 py-3"><?= e($upload['school_year']) ?></td>
                                    <td class="px-4 py-3"><?= (int) $upload['records_extracted'] ?> RECORDS</td>
                                    <td class="px-4 py-3"><?= e_upper(date('F j, Y', strtotime($upload['uploaded_at']))) ?></td>
                                    <td class="px-4 py-3"><?= e($upload['original_filename']) ?></td>
                                    <td class="px-4 py-3 text-right">
                                        <?php $actionBtnIndex = 0; ?>
                                        <div class="flex items-center justify-end gap-1.5">
                                            <a href="<?= e(APP_BASE_URL) ?>/admin/view_upload.php?id=<?= (int) $upload['id'] ?>" class="rounded-lg bg-csc-navy-600/10 hover:bg-csc-navy-600/20 text-csc-navy-600 dark:text-csc-navy-300 p-1.5 motion-safe:animate-drop-in" style="animation-delay: <?= (int) ($rowIndex * 50 + ($actionBtnIndex++) * 70) ?>ms" title="View upload">
                                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                                            </a>
                                            <?php if ($isAdmin && (int) $upload['records_extracted'] === 0): ?>
                                            <button type="button" class="delete-upload-btn rounded-lg bg-csc-red-600/10 hover:bg-csc-red-600/20 text-csc-red-600 dark:text-csc-red-400 p-1.5 motion-safe:animate-drop-in" style="animation-delay: <?= (int) ($rowIndex * 50 + ($actionBtnIndex++) * 70) ?>ms" title="Delete upload"
                                                data-upload-id="<?= (int) $upload['id'] ?>"
                                                data-school="<?= e(format_school_display_name($upload['school_name'], $upload['campus'])) ?>"
                                                data-year="<?= e($upload['school_year']) ?>">
                                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" /></svg>
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($isAdmin): ?>
        <div class="mt-8">
            <div class="bg-white dark:bg-surface-900 border border-gray-100 dark:border-white/5 rounded-2xl p-5 hge-shadow">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-white">RECENT ACTIVITY</h2>
                    <a href="<?= e(APP_BASE_URL) ?>/admin/activity_log.php" class="text-xs font-semibold text-csc-navy-600 dark:text-csc-navy-300 hover:underline">VIEW ALL &rarr;</a>
                </div>
                <?php if (empty($recentActivity)): ?>
                    <p class="text-sm text-gray-600 dark:text-gray-500 text-center py-6">NO RECENT ACTIVITY.</p>
                <?php else: ?>
                    <ul class="divide-y divide-gray-100 dark:divide-white/5">
                        <?php foreach ($recentActivity as $activityIndex => $entry): ?>
                            <li class="flex items-center gap-3 py-3 motion-safe:animate-fade-in-up" style="animation-delay: <?= $activityIndex * 50 ?>ms">
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-csc-navy-600/10 dark:bg-csc-navy-600/15 text-csc-navy-600 dark:text-csc-navy-300">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="<?= e(activity_icon_path($entry['action'])) ?>" /></svg>
                                </span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm text-gray-900 dark:text-white truncate"><?= e_upper(str_replace('_', ' ', $entry['action'])) ?></p>
                                    <p class="text-xs text-gray-500 dark:text-gray-500 truncate"><?= $entry['user_name'] !== null ? e_upper($entry['user_name']) : 'SYSTEM' ?><?= ($entry['details'] ?? '') !== '' ? ' — ' . e($entry['details']) : '' ?></p>
                                </div>
                                <span class="shrink-0 text-xs text-gray-400 dark:text-gray-600 whitespace-nowrap"><?= e_upper(date('M j, g:i A', strtotime($entry['created_at']))) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
<script>
window.HGE_DASHBOARD = {
    apiBase: <?= json_encode(APP_BASE_URL) ?>,
    csrfToken: <?= json_encode($csrfToken) ?>,
    initialTrendMonthly: <?= json_encode($trendMonthly) ?>,
    initialTo: <?= (int) $dashboardDefaultYear ?>,
    initialMode: 'month'
};
</script>
<script src="<?= e(asset_url('assets/js/dashboard.js')) ?>"></script>
