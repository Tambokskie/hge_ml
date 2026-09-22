<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/uploads.php';
require_once __DIR__ . '/../includes/activity.php';

require_login();

$pdo = get_pdo();
$stats = get_dashboard_stats($pdo);
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
        <h2 class="text-lg font-semibold text-gray-700 dark:text-gray-300 mb-1">WELCOME, <span class="text-accent-700 dark:text-accent-400"><?= e_upper($currentUser['name'] ?? '') ?></span>!</h2>
        <h1 class="text-xl font-semibold text-gray-900 dark:text-white mb-6">DASHBOARD</h1>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="dashboard-stat-card motion-safe:animate-fade-in-up" style="animation-delay: 0ms">
                <div class="flex items-center gap-3 min-w-0">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-accent-500/10 text-accent-600 dark:bg-accent-500/15 dark:text-accent-400">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M4.26 10.147a60.436 60.436 0 00-.491 6.347A48.627 48.627 0 0112 20.904a48.627 48.627 0 018.232-4.41 60.46 60.46 0 00-.491-6.347M4.26 10.147a50.548 50.548 0 00-2.658-.813A59.905 59.905 0 0112 3.493a59.902 59.902 0 0110.399 5.841c-.896.248-1.783.52-2.658.814M4.26 10.147a50.696 50.696 0 017.74-3.342M12 13.489a50.702 50.702 0 017.74-3.342" /></svg>
                    </span>
                    <p class="text-xs text-gray-600 dark:text-gray-500">TOTAL GRADUATES</p>
                </div>
                <p class="dashboard-stat-number" data-count-to="<?= (int) $stats['total_graduates'] ?>">0</p>
            </div>
            <div class="dashboard-stat-card motion-safe:animate-fade-in-up" style="animation-delay: 200ms">
                <div class="flex items-center gap-3 min-w-0">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-accent-500/10 text-accent-600 dark:bg-accent-500/15 dark:text-accent-400">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" /></svg>
                    </span>
                    <p class="text-xs text-gray-600 dark:text-gray-500">TOTAL SCHOOLS</p>
                </div>
                <p class="dashboard-stat-number" data-count-to="<?= (int) $stats['total_schools'] ?>">0</p>
            </div>
            <div class="dashboard-stat-card motion-safe:animate-fade-in-up" style="animation-delay: 400ms">
                <div class="flex items-center gap-3 min-w-0">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-accent-500/10 text-accent-600 dark:bg-accent-500/15 dark:text-accent-400">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" /></svg>
                    </span>
                    <p class="text-xs text-gray-600 dark:text-gray-500">TOTAL PDF LISTS</p>
                </div>
                <p class="dashboard-stat-number" data-count-to="<?= (int) $stats['total_uploads'] ?>">0</p>
            </div>
            <div class="dashboard-stat-card motion-safe:animate-fade-in-up" style="animation-delay: 600ms">
                <div class="flex items-center gap-3 min-w-0">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-accent-500/10 text-accent-600 dark:bg-accent-500/15 dark:text-accent-400">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" /></svg>
                    </span>
                    <div class="min-w-0">
                        <p class="text-xs text-gray-600 dark:text-gray-500">CURRENT YEAR RECORDS</p>
                        <p class="text-xs text-gray-400 dark:text-gray-600 mt-1"><?= $stats['current_year'] ? e((string) $stats['current_year']) : 'NO DATA YET' ?></p>
                    </div>
                </div>
                <p class="dashboard-stat-number" data-count-to="<?= (int) $stats['current_year_records'] ?>">0</p>
            </div>
        </div>

        <?php if ($isAdmin): ?>
        <div class="flex flex-wrap justify-end gap-3 mt-4">
            <a href="<?= e(APP_BASE_URL) ?>/admin/schools.php" class="inline-flex items-center gap-2 rounded-lg border border-gray-200 dark:border-white/10 bg-white dark:bg-surface-900 px-4 py-2 text-sm font-semibold text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-white/5 transition-colors motion-safe:animate-fade-in" style="animation-delay: 400ms">SCHOOL MANAGEMENT</a>
            <a href="<?= e(APP_BASE_URL) ?>/admin/upload.php" class="inline-flex items-center gap-2 rounded-lg bg-accent-600 px-4 py-2 text-sm font-semibold text-white hover:brightness-110 transition-all motion-safe:animate-fade-in" style="animation-delay: 600ms">UPLOAD PDF LIST</a>
        </div>
        <?php endif; ?>

        <div class="mt-8 grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div class="bg-white dark:bg-surface-900 border border-gray-100 dark:border-white/5 rounded-2xl p-5 hge-shadow">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white mb-4">LATIN HONORS BY TYPE</h2>
                <?php if (array_sum($honorBreakdown) === 0): ?>
                    <p class="text-sm text-gray-600 dark:text-gray-500 text-center py-8">NO RECORDS YET.</p>
                <?php else: ?>
                    <?php
                    $honorMax = max($honorBreakdown) ?: 1;
                    $honorBarColor = [
                        'CUM LAUDE' => 'bg-accent-300',
                        'MAGNA CUM LAUDE' => 'bg-accent-500',
                        'SUMMA CUM LAUDE' => 'bg-accent-700',
                    ];
                    ?>
                    <div class="flex items-end gap-4">
                        <?php foreach ($honorBreakdown as $honor => $count): ?>
                            <div class="flex-1 flex flex-col items-center gap-2">
                                <div class="w-full flex items-end h-24">
                                    <div class="w-full rounded-t-md <?= $honorBarColor[$honor] ?>" style="height: <?= (int) round(($count / $honorMax) * 100) ?>%"></div>
                                </div>
                                <p class="text-xs font-semibold text-gray-900 dark:text-white"><?= (int) $count ?></p>
                                <p class="text-[11px] text-gray-500 dark:text-gray-500 text-center leading-tight"><?= e($honor) ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="bg-white dark:bg-surface-900 border border-gray-100 dark:border-white/5 rounded-2xl p-5 hge-shadow">
                <?php
                $uploadCounts = array_column($uploadActivity, 'count');
                $uploadTotal = array_sum($uploadCounts);
                $uploadThisMonth = end($uploadCounts);
                ?>
                <div class="flex items-center justify-between mb-1">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-white">UPLOAD ACTIVITY</h2>
                    <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-400"><?= (int) $uploadThisMonth ?> THIS MONTH</span>
                </div>
                <p class="text-2xl font-bold text-accent-800 dark:text-white mb-2"><?= (int) $uploadTotal ?> PDFS <span class="text-xs font-normal text-gray-500 dark:text-gray-500">(LAST 6 MONTHS)</span></p>
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
                    <svg viewBox="0 0 <?= $chartW ?> <?= $chartH ?>" class="w-full h-20" preserveAspectRatio="none">
                        <g class="text-accent-600 dark:text-accent-400">
                            <polygon points="<?= e($areaAttr) ?>" fill="currentColor" opacity="0.12"></polygon>
                            <polyline points="<?= e($pointsAttr) ?>" fill="none" stroke="currentColor" stroke-width="2.5" vector-effect="non-scaling-stroke"></polyline>
                        </g>
                    </svg>
                    <div class="flex justify-between text-[11px] text-gray-400 dark:text-gray-600 mt-1">
                        <?php foreach ($uploadActivity as $month): ?>
                            <span><?= e($month['label']) ?></span>
                        <?php endforeach; ?>
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
                            <tr class="text-xs text-gray-600 dark:text-gray-500 border-b border-gray-200 dark:border-white/10">
                                <th class="px-4 py-3">SCHOOL</th>
                                <th class="px-4 py-3">YEAR</th>
                                <th class="px-4 py-3">RECORDS EXTRACTED</th>
                                <th class="px-4 py-3">DATE UPLOADED</th>
                                <th class="px-4 py-3">FILE NAME</th>
                                <th class="px-4 py-3">ACTION</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentUploads as $upload): ?>
                                <tr class="border-b border-gray-100 dark:border-white/5 hover:bg-gray-100 dark:hover:bg-white/5">
                                    <td class="px-4 py-3"><?= e_upper(format_school_display_name($upload['school_name'], $upload['campus'])) ?></td>
                                    <td class="px-4 py-3"><?= e($upload['school_year']) ?></td>
                                    <td class="px-4 py-3"><?= (int) $upload['records_extracted'] ?> RECORDS</td>
                                    <td class="px-4 py-3"><?= e_upper(date('F j, Y', strtotime($upload['uploaded_at']))) ?></td>
                                    <td class="px-4 py-3"><?= e($upload['original_filename']) ?></td>
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <a href="<?= e(APP_BASE_URL) ?>/admin/view_upload.php?id=<?= (int) $upload['id'] ?>" class="text-accent-700 dark:text-accent-400 hover:underline">VIEW</a>
                                        <?php if ($isAdmin && (int) $upload['records_extracted'] === 0): ?>
                                        <button type="button" class="delete-upload-btn text-red-600 dark:text-red-400 hover:underline ml-3"
                                            data-upload-id="<?= (int) $upload['id'] ?>"
                                            data-school="<?= e(format_school_display_name($upload['school_name'], $upload['campus'])) ?>"
                                            data-year="<?= e($upload['school_year']) ?>">DELETE</button>
                                        <?php endif; ?>
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
                    <a href="<?= e(APP_BASE_URL) ?>/admin/activity_log.php" class="text-xs font-semibold text-accent-700 dark:text-accent-400 hover:underline">VIEW ALL &rarr;</a>
                </div>
                <?php if (empty($recentActivity)): ?>
                    <p class="text-sm text-gray-600 dark:text-gray-500 text-center py-6">NO RECENT ACTIVITY.</p>
                <?php else: ?>
                    <ul class="divide-y divide-gray-100 dark:divide-white/5">
                        <?php foreach ($recentActivity as $entry): ?>
                            <li class="flex items-center gap-3 py-3">
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-accent-50 dark:bg-accent-500/15 text-accent-600 dark:text-accent-400">
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
<?php if ($isAdmin): ?>
<script>
window.HGE_DASHBOARD = {
    apiBase: <?= json_encode(APP_BASE_URL) ?>,
    csrfToken: <?= json_encode($csrfToken) ?>
};
</script>
<?php endif; ?>
<script src="<?= e(asset_url('assets/js/dashboard.js')) ?>"></script>
