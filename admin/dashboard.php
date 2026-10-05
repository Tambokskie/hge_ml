<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/uploads.php';
require_once __DIR__ . '/../includes/activity.php';

require_login();

$pdo = get_pdo();
$stats = get_dashboard_stats($pdo);
$graduationYears = get_distinct_graduation_years($pdo);
$recentUploads = get_recent_uploads($pdo, 10);
$honorBreakdown = get_honor_type_breakdown($pdo);
$uploadActivity = get_upload_activity_by_month($pdo, 6);
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
        <div class="mb-6 flex items-start gap-4">
            <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-csc-navy-600/10 text-csc-navy-600 dark:text-csc-navy-300">
                <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z" /></svg>
            </div>
            <div>
                <h1 class="text-2xl font-bold text-csc-navy-600 dark:text-white">DASHBOARD</h1>
                <p class="text-sm text-gray-600 dark:text-gray-500 mt-1">WELCOME, <span class="font-semibold text-csc-navy-600 dark:text-csc-navy-300"><?= e_upper($currentUser['name'] ?? '') ?></span>!</p>
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="dashboard-stat-card motion-safe:animate-fade-in-up" style="animation-delay: 0ms">
                <div class="flex items-center gap-3 self-start">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-csc-navy-600/10 text-csc-navy-600 dark:bg-csc-navy-600/15 dark:text-csc-navy-300">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M4.26 10.147a60.436 60.436 0 00-.491 6.347A48.627 48.627 0 0112 20.904a48.627 48.627 0 018.232-4.41 60.46 60.46 0 00-.491-6.347M4.26 10.147a50.548 50.548 0 00-2.658-.813A59.905 59.905 0 0112 3.493a59.902 59.902 0 0110.399 5.841c-.896.248-1.783.52-2.658.814M4.26 10.147a50.696 50.696 0 017.74-3.342M12 13.489a50.702 50.702 0 017.74-3.342" /></svg>
                    </span>
                    <p class="text-xs text-gray-600 dark:text-gray-500">TOTAL GRADUATES</p>
                </div>
                <p class="dashboard-stat-number" data-count-to="<?= (int) $stats['total_graduates'] ?>">0</p>
            </div>
            <?php $schoolsMissing = max(0, $stats['total_active_schools'] - $stats['total_schools']); ?>
            <div class="dashboard-stat-card motion-safe:animate-fade-in-up" style="animation-delay: 200ms">
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
            <div class="dashboard-stat-card motion-safe:animate-fade-in-up" style="animation-delay: 400ms">
                <div class="flex items-center gap-3 self-start">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-csc-navy-600/10 text-csc-navy-600 dark:bg-csc-navy-600/15 dark:text-csc-navy-300">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" /></svg>
                    </span>
                    <p class="text-xs text-gray-600 dark:text-gray-500">TOTAL PDF LISTS</p>
                </div>
                <p class="dashboard-stat-number" data-count-to="<?= (int) $stats['total_uploads'] ?>">0</p>
            </div>
            <div class="dashboard-stat-card motion-safe:animate-fade-in-up" style="animation-delay: 600ms">
                <div class="flex items-center gap-3 self-start">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-csc-navy-600/10 text-csc-navy-600 dark:bg-csc-navy-600/15 dark:text-csc-navy-300">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" /></svg>
                    </span>
                    <p class="text-xs text-gray-600 dark:text-gray-500">CURRENT YEAR RECORDS</p>
                </div>
                <p class="dashboard-stat-number" id="dashboard-year-records-number" data-count-to="<?= (int) $stats['current_year_records'] ?>">0</p>
                <?php if (!empty($graduationYears)): ?>
                    <div class="relative inline-block">
                        <select id="dashboard-year-select" class="appearance-none bg-transparent text-xs text-gray-400 dark:text-gray-600 text-center pl-1 pr-4 cursor-pointer hover:text-gray-600 dark:hover:text-gray-400 focus:outline-none" aria-label="Select year">
                            <?php foreach ($graduationYears as $y): ?>
                                <option value="<?= (int) $y ?>" <?= $y === $stats['current_year'] ? 'selected' : '' ?>><?= (int) $y ?></option>
                            <?php endforeach; ?>
                        </select>
                        <svg class="pointer-events-none absolute right-0 top-1/2 -translate-y-1/2 h-3 w-3 text-gray-400 dark:text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                    </div>
                <?php else: ?>
                    <p class="text-xs text-gray-400 dark:text-gray-600">NO DATA YET</p>
                <?php endif; ?>
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
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white mb-4">LATIN HONORS BY TYPE</h2>
                <?php if (array_sum($honorBreakdown) === 0): ?>
                    <p class="text-sm text-gray-600 dark:text-gray-500 text-center py-8">NO RECORDS YET.</p>
                <?php else: ?>
                    <?php
                    $honorMax = max($honorBreakdown) ?: 1;
                    $honorBarColor = [
                        'CUM LAUDE' => 'bg-green-500',
                        'MAGNA CUM LAUDE' => 'bg-csc-navy-600',
                        'SUMMA CUM LAUDE' => 'bg-amber-500',
                    ];
                    ?>
                    <div class="flex items-end gap-4">
                        <?php $barIndex = 0; foreach ($honorBreakdown as $honor => $count): ?>
                            <div class="flex-1 flex flex-col items-center gap-2">
                                <div class="w-full flex items-end h-24">
                                    <div class="w-full rounded-t-md <?= $honorBarColor[$honor] ?> motion-safe:animate-grow-bar" style="--bar-height: <?= (int) round(($count / $honorMax) * 100) ?>%; animation-delay: <?= $barIndex++ * 150 ?>ms"></div>
                                </div>
                                <p class="text-xs font-semibold text-gray-900 dark:text-white"><?= (int) $count ?></p>
                                <p class="text-[11px] text-gray-500 dark:text-gray-500 text-center leading-tight"><?= e($honor) ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="bg-white dark:bg-surface-900 border border-gray-100 dark:border-white/5 rounded-2xl p-5 hge-shadow motion-safe:animate-fade-in-up" style="animation-delay: 400ms">
                <?php
                $uploadCounts = array_column($uploadActivity, 'count');
                $uploadTotal = array_sum($uploadCounts);
                $uploadThisMonth = end($uploadCounts);
                ?>
                <div class="flex items-center justify-between mb-1">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-white">UPLOAD ACTIVITY</h2>
                    <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-400"><?= (int) $uploadThisMonth ?> THIS MONTH</span>
                </div>
                <p class="text-2xl font-bold text-csc-navy-600 dark:text-white mb-2"><span id="upload-activity-count" data-count-to="<?= (int) $uploadTotal ?>">0</span> PDFS <span class="text-xs font-normal text-gray-500 dark:text-gray-500">(LAST 6 MONTHS)</span></p>
                <?php if ($uploadTotal === 0): ?>
                    <p class="text-sm text-gray-600 dark:text-gray-500 text-center py-6">NO UPLOADS IN THIS PERIOD.</p>
                <?php else: ?>
                    <?php
                    $chartW = 360;
                    $chartH = 70;
                    $pointCount = count($uploadCounts);
                    $chartMax = max($uploadCounts) ?: 1;
                    $stepX = $pointCount > 1 ? $chartW / ($pointCount - 1) : 0;
                    $chartPoints = [];
                    foreach ($uploadCounts as $i => $count) {
                        $x = round($i * $stepX, 1);
                        $y = round($chartH - ($count / $chartMax) * ($chartH - 10) - 5, 1);
                        $chartPoints[] = "$x,$y";
                    }
                    $pointsAttr = implode(' ', $chartPoints);
                    $areaAttr = $pointsAttr . " $chartW,$chartH 0,$chartH";
                    ?>
                    <div id="upload-activity-chart" class="opacity-0 transition-opacity duration-300 ease-out">
                        <svg viewBox="0 0 <?= $chartW ?> <?= $chartH ?>" class="w-full h-20" preserveAspectRatio="none">
                            <defs>
                                <!--
                                    A single growing rect shared by both the line and the area, so
                                    they reveal in lockstep. Chosen over animating the polyline's
                                    stroke-dasharray/dashoffset because that technique, combined with
                                    this chart's vector-effect="non-scaling-stroke" and non-uniform
                                    (preserveAspectRatio="none") X/Y scaling, rendered the line with a
                                    visible gap partway along the path in testing. Clipping never
                                    touches the polyline's own geometry, so the revealed line is always
                                    the exact same continuous path as the final static graph.
                                -->
                                <clipPath id="upload-activity-clip">
                                    <rect id="upload-activity-clip-rect" x="0" y="0" width="0" height="<?= $chartH ?>" data-target-width="<?= $chartW ?>"></rect>
                                </clipPath>
                            </defs>
                            <g class="text-csc-navy-600 dark:text-csc-navy-300">
                                <polygon id="upload-activity-area" points="<?= e($areaAttr) ?>" fill="currentColor" opacity="0.12" clip-path="url(#upload-activity-clip)"></polygon>
                                <polyline id="upload-activity-line" points="<?= e($pointsAttr) ?>" fill="none" stroke="currentColor" stroke-width="2.5" vector-effect="non-scaling-stroke" clip-path="url(#upload-activity-clip)"></polyline>
                            </g>
                        </svg>
                        <div class="flex justify-between text-[11px] text-gray-400 dark:text-gray-600 mt-1">
                            <?php foreach ($uploadActivity as $month): ?>
                                <span><?= e($month['label']) ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
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
    csrfToken: <?= json_encode($csrfToken) ?>
};
</script>
<script src="<?= e(asset_url('assets/js/dashboard.js')) ?>"></script>
