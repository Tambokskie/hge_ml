<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/schools.php';
require_once __DIR__ . '/../includes/uploads.php';

require_login();

$pdo = get_pdo();
$schools = get_active_schools_list($pdo);
$schoolYears = get_distinct_upload_school_years($pdo);

$schoolId = (isset($_GET['school_id']) && (int) $_GET['school_id'] > 0) ? (int) $_GET['school_id'] : null;
$schoolYear = trim((string) ($_GET['school_year'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));

$filters = ['school_id' => $schoolId, 'school_year' => $schoolYear !== '' ? $schoolYear : null];

$searchResult = null;
$searchFailed = false;
try {
    $searchResult = search_uploads($pdo, $filters, $page);
} catch (\Throwable $e) {
    error_log('School records search failed: ' . $e->getMessage());
    $searchFailed = true;
}

$actionSummary = get_upload_action_summary($pdo);

$currentUser = current_user();
$currentPage = 'school_records';
$csrfToken = csrf_token();
$isAdmin = user_has_role($currentUser, 'administrator');

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main class="lg:ml-64 min-h-screen bg-gray-50 dark:bg-surface-950">
    <div class="p-6 lg:p-8">
        <div class="mb-6 flex items-start gap-4">
            <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-csc-navy-600/10 text-csc-navy-600 dark:text-csc-navy-300">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" /></svg>
            </div>
            <div>
                <h1 class="text-2xl font-bold text-csc-navy-600 dark:text-white">SCHOOL RECORDS</h1>
                <p class="text-sm text-gray-600 dark:text-gray-500 mt-1">BROWSE UPLOADED LATIN HONOR LISTS<?= $isAdmin ? ' AND EDIT THEIR SAVED RECORDS.' : '.' ?></p>
            </div>
        </div>

        <h2 class="text-xs font-bold tracking-wide text-gray-500 dark:text-gray-500 mb-3">ACTIONS SUMMARY</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
            <div class="dashboard-stat-card items-start text-left motion-safe:animate-fade-in-up" style="animation-delay: 0ms">
                <div class="flex items-center justify-between w-full gap-3">
                    <div class="flex items-center gap-3 min-w-0">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-green-500/10 text-green-600 dark:text-green-400">
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        </span>
                        <p class="text-xs text-gray-600 dark:text-gray-500">COMPLETED</p>
                    </div>
                    <p class="dashboard-stat-number mr-6" data-count-to="<?= (int) $actionSummary['completed'] ?>">0</p>
                </div>
            </div>
            <div class="dashboard-stat-card items-start text-left motion-safe:animate-fade-in-up" style="animation-delay: 150ms">
                <div class="flex items-center justify-between w-full gap-3">
                    <div class="flex items-center gap-3 min-w-0">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-yellow-500/10 text-yellow-600 dark:text-yellow-400">
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        </span>
                        <p class="text-xs text-gray-600 dark:text-gray-500">PENDING ACTION</p>
                    </div>
                    <p class="dashboard-stat-number mr-6" data-count-to="<?= (int) $actionSummary['pending'] ?>">0</p>
                </div>
            </div>
        </div>

        <div class="bg-white dark:bg-surface-900 border border-gray-200 dark:border-white/10 rounded-xl p-4 sm:p-6 mb-6 hge-shadow motion-safe:animate-fade-in-up" style="animation-delay: 200ms">
            <form id="school-records-filter-form" method="get" class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="mb-1.5 block text-xs font-bold tracking-wide text-csc-navy-600 dark:text-csc-navy-300">SCHOOL</label>
                    <div class="relative">
                        <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-gray-400 dark:text-gray-500">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" /></svg>
                        </span>
                        <select name="school_id" id="filter-school" class="w-full rounded-xl bg-gray-50 dark:bg-surface-800 border border-gray-200 dark:border-white/10 pl-10 pr-3 py-2.5 text-sm focus:border-csc-navy-500 focus:ring-2 focus:ring-csc-navy-500/20 focus:outline-none">
                            <option value="">ALL SCHOOLS</option>
                            <?php foreach ($schools as $school): ?>
                                <option value="<?= (int) $school['id'] ?>" <?= $schoolId === (int) $school['id'] ? 'selected' : '' ?>><?= e_upper(format_school_display_name($school['school_name'], $school['campus'])) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-bold tracking-wide text-csc-navy-600 dark:text-csc-navy-300">SCHOOL YEAR</label>
                    <div class="relative">
                        <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-gray-400 dark:text-gray-500">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" /></svg>
                        </span>
                        <select name="school_year" id="filter-year" class="w-full rounded-xl bg-gray-50 dark:bg-surface-800 border border-gray-200 dark:border-white/10 pl-10 pr-3 py-2.5 text-sm focus:border-csc-navy-500 focus:ring-2 focus:ring-csc-navy-500/20 focus:outline-none">
                            <option value="">ALL YEARS</option>
                            <?php foreach ($schoolYears as $y): ?>
                                <option value="<?= e($y) ?>" <?= $schoolYear === (string) $y ? 'selected' : '' ?>><?= e($y) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="sm:col-span-2 flex flex-wrap items-center justify-between gap-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="submit" class="inline-flex items-center gap-1.5 rounded-xl bg-csc-navy-600 hover:bg-csc-navy-500 text-white text-sm font-semibold px-4 py-2.5 transition-colors">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607Z" /></svg>
                            SEARCH
                        </button>
                        <button type="button" id="school-records-clear-filters-btn" class="inline-flex items-center gap-1.5 rounded-xl border border-gray-300 dark:border-white/15 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5 text-sm font-medium px-4 py-2.5 transition-colors">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>
                            CLEAR FILTERS
                        </button>
                    </div>
                    <span id="school-records-found" class="inline-flex items-center rounded-full bg-gray-100 dark:bg-white/5 px-3 py-1.5 text-xs font-semibold text-gray-600 dark:text-gray-400 <?= $searchFailed ? 'hidden' : '' ?>">
                        <?= (int) ($searchResult['pagination']['total_rows'] ?? 0) ?> UPLOAD<?= (int) ($searchResult['pagination']['total_rows'] ?? 0) === 1 ? '' : 'S' ?> FOUND
                    </span>
                </div>
            </form>
        </div>

        <div id="school-records-results">
            <?php if ($searchFailed): ?>
                <div class="rounded-xl border border-red-500/30 bg-red-500/10 p-10 text-center text-red-400 hge-shadow">
                    <p class="text-sm font-medium">THE SEARCH COULD NOT BE COMPLETED. PLEASE TRY AGAIN.</p>
                </div>
            <?php elseif (empty($searchResult['rows'])): ?>
                <div class="rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-surface-900 p-10 text-center text-gray-500 dark:text-gray-400 hge-shadow">
                    <p class="text-sm font-medium text-gray-700 dark:text-gray-300">NO UPLOADS FOUND.</p>
                </div>
            <?php else: ?>
                <div class="bg-white dark:bg-surface-900 border border-gray-200 dark:border-white/10 rounded-xl overflow-x-auto overflow-y-hidden hge-shadow">
                    <table class="w-full text-sm text-left">
                        <thead>
                            <tr class="text-sm font-semibold text-gray-900 dark:text-white border-b border-gray-200 dark:border-white/10">
                                <th class="px-4 py-3">SCHOOL</th>
                                <th class="px-4 py-3">YEAR</th>
                                <th class="px-4 py-3">RECORDS EXTRACTED</th>
                                <th class="px-4 py-3">DATE UPLOADED</th>
                                <th class="px-4 py-3">STATUS</th>
                                <th class="px-4 py-3">FILE NAME</th>
                                <th class="px-4 py-3 text-right">ACTION</th>
                            </tr>
                        </thead>
                        <tbody id="school-records-tbody">
                            <?php foreach ($searchResult['rows'] as $rowIndex => $upload):
                                $agingClass = pending_upload_age_class($upload);
                                $statusPill = upload_status_pill($upload['extraction_status']);
                                // The critical-aging tier already pulses continuously via its own
                                // animation; stacking the fade-in-up entrance on top of it would
                                // fight over the single CSS `animation` property, so skip it there.
                                $hasPulseAnimation = strpos($agingClass, 'animate-pending-critical-pulse') !== false;
                            ?>
                                <tr class="border-b border-gray-100 dark:border-white/5 hover:bg-gray-100 dark:hover:bg-white/5<?= $agingClass !== '' ? ' ' . $agingClass : '' ?><?= $hasPulseAnimation ? '' : ' motion-safe:animate-fade-in-up' ?>"<?= $hasPulseAnimation ? '' : ' style="animation-delay: ' . ($rowIndex * 50) . 'ms"' ?>>
                                    <td class="px-4 py-3"><?= e_upper(format_school_display_name($upload['school_name'], $upload['campus'])) ?></td>
                                    <td class="px-4 py-3"><?= e($upload['school_year']) ?></td>
                                    <td class="px-4 py-3"><?= (int) $upload['records_extracted'] ?> RECORDS</td>
                                    <td class="px-4 py-3"><?= e_upper(date('F j, Y', strtotime($upload['uploaded_at']))) ?></td>
                                    <td class="px-4 py-3">
                                        <div class="flex items-center gap-3">
                                            <span class="inline-flex min-w-[140px] items-center justify-center rounded-full px-2.5 py-1 text-xs font-semibold <?= $statusPill['class'] ?>"><?= e($statusPill['label']) ?></span>
                                            <?php if ($upload['extraction_status'] !== 'reviewed'): ?>
                                                <div class="border-l border-gray-200 dark:border-white/10 pl-3 leading-tight">
                                                    <p class="text-[11px] text-gray-500 dark:text-gray-400">AGING: <?= days_since($upload['uploaded_at']) ?> DAY<?= days_since($upload['uploaded_at']) === 1 ? '' : 'S' ?></p>
                                                    <p class="text-[11px] text-gray-400 dark:text-gray-600">LAST CHECKED: <?= e_upper(date('d M Y', strtotime($upload['updated_at']))) ?></p>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3"><?= e($upload['original_filename']) ?></td>
                                    <td class="px-4 py-3 text-right">
                                        <?php $actionBtnIndex = 0; ?>
                                        <div class="flex items-center justify-end gap-1.5">
                                            <a href="<?= e(APP_BASE_URL) ?>/admin/view_upload.php?id=<?= (int) $upload['id'] ?>" class="rounded-lg bg-csc-navy-600/10 hover:bg-csc-navy-600/20 text-csc-navy-600 dark:text-csc-navy-300 p-1.5 motion-safe:animate-drop-in" style="animation-delay: <?= (int) ($rowIndex * 50 + ($actionBtnIndex++) * 70) ?>ms" title="View upload">
                                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                                            </a>
                                            <?php if ($isAdmin && $upload['extraction_status'] === 'reviewed'): ?>
                                            <a href="<?= e(APP_BASE_URL) ?>/admin/review.php?upload_id=<?= (int) $upload['id'] ?>" class="rounded-lg bg-green-500/10 hover:bg-green-500/20 text-green-600 dark:text-green-400 p-1.5 motion-safe:animate-drop-in" style="animation-delay: <?= (int) ($rowIndex * 50 + ($actionBtnIndex++) * 70) ?>ms" title="Edit saved records">
                                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125" /></svg>
                                            </a>
                                            <?php endif; ?>
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
                </div>
                <?php
                    $pageInfo = $searchResult['pagination'];
                    $rangeStart = $pageInfo['total_rows'] === 0 ? 0 : $pageInfo['offset'] + 1;
                    $rangeEnd = min($pageInfo['offset'] + $pageInfo['per_page'], $pageInfo['total_rows']);
                ?>
                <div id="school-records-pagination" class="flex flex-wrap items-center justify-between gap-3 mt-4 text-sm text-gray-500 dark:text-gray-400">
                    <span>SHOWING <?= (int) $rangeStart ?> TO <?= (int) $rangeEnd ?> OF <?= (int) $pageInfo['total_rows'] ?> ENTRIES</span>
                    <div class="flex items-center gap-1.5">
                        <?php if ($pageInfo['total_pages'] > 1): ?>
                            <select class="school-records-page-select rounded-lg border border-gray-200 dark:border-white/10 bg-white dark:bg-surface-800 px-2 py-1.5 text-sm focus:border-csc-navy-500 focus:ring-2 focus:ring-csc-navy-500/20 focus:outline-none" aria-label="Jump to page">
                                <?php for ($p = 1; $p <= $pageInfo['total_pages']; $p++): ?>
                                    <option value="<?= $p ?>" <?= $p === $pageInfo['page'] ? 'selected' : '' ?>>PAGE <?= $p ?> OF <?= (int) $pageInfo['total_pages'] ?></option>
                                <?php endfor; ?>
                            </select>
                        <?php endif; ?>
                        <?php if ($pageInfo['page'] > 1): ?>
                            <button type="button" class="school-records-page-btn rounded-lg border border-gray-200 dark:border-white/10 p-1.5 hover:bg-gray-100 dark:hover:bg-white/5" data-page="<?= (int) $pageInfo['page'] - 1 ?>" aria-label="Previous page">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" /></svg>
                            </button>
                        <?php endif; ?>
                        <?php foreach (pagination_window($pageInfo['page'], $pageInfo['total_pages']) as $pageNum): ?>
                            <button type="button" class="school-records-page-btn min-w-[2.25rem] rounded-lg px-2.5 py-1.5 text-sm font-medium <?= $pageNum === $pageInfo['page'] ? 'bg-csc-navy-600 text-white' : 'border border-gray-200 dark:border-white/10 hover:bg-gray-100 dark:hover:bg-white/5' ?>" data-page="<?= (int) $pageNum ?>"><?= (int) $pageNum ?></button>
                        <?php endforeach; ?>
                        <?php if ($pageInfo['page'] < $pageInfo['total_pages']): ?>
                            <button type="button" class="school-records-page-btn rounded-lg border border-gray-200 dark:border-white/10 p-1.5 hover:bg-gray-100 dark:hover:bg-white/5" data-page="<?= (int) $pageInfo['page'] + 1 ?>" aria-label="Next page">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
<script>
window.HGE_SCHOOL_RECORDS = {
    apiBase: <?= json_encode(APP_BASE_URL) ?>,
    csrfToken: <?= json_encode($csrfToken) ?>,
    isAdmin: <?= json_encode($isAdmin) ?>,
    reviewUrl: <?= json_encode(APP_BASE_URL . '/admin/review.php') ?>,
    viewUploadUrl: <?= json_encode(APP_BASE_URL . '/admin/view_upload.php') ?>
};
</script>
<script src="<?= e(asset_url('assets/js/school_records.js')) ?>"></script>
