<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/users.php';
require_once __DIR__ . '/../includes/activity.php';

require_role('administrator');

$pdo = get_pdo();
$allUsers = get_all_users($pdo);

$userId = (isset($_GET['user_id']) && (int) $_GET['user_id'] > 0) ? (int) $_GET['user_id'] : null;
$action = trim((string) ($_GET['action'] ?? ''));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));

$filters = [
    'user_id' => $userId,
    'action' => $action !== '' ? $action : null,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
];

$searchResult = null;
$searchFailed = false;
try {
    $searchResult = search_activity_log($pdo, $filters, $page);
} catch (\Throwable $e) {
    error_log('Activity log search failed: ' . $e->getMessage());
    $searchFailed = true;
}

$actionTypes = ['login', 'logout', 'pdf_upload', 'pdf_delete', 'template_upload', 'record_created', 'record_updated', 'record_removed', 'record_restored', 'record_purged', 'record_draft_saved', 'search', 'user_created', 'user_updated', 'school_created', 'school_updated', 'school_removed'];

$currentUser = current_user();
$currentPage = 'activity_log';

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main class="lg:ml-64 min-h-screen bg-gray-50 dark:bg-surface-950">
    <div class="p-6 lg:p-8">
        <div class="mb-6 flex items-start gap-4">
            <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-csc-navy-600/10 text-csc-navy-600 dark:text-csc-navy-300">
                <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
            </div>
            <div>
                <h1 class="text-2xl font-bold text-csc-navy-600 dark:text-white">ACTIVITY LOG</h1>
                <p class="text-sm text-gray-600 dark:text-gray-500 mt-1">ENTRIES ARE RETAINED FOR 6 MONTHS. "SEARCH" ENTRIES REFLECT SESSION ACTIVITY, NOT CONFIRMED USER INTENT.</p>
            </div>
        </div>

        <div class="bg-white dark:bg-surface-900 border border-gray-200 dark:border-white/10 rounded-xl p-4 sm:p-6 mb-6 hge-shadow motion-safe:animate-fade-in-up">
        <form id="activity-filter-form" method="get" class="grid grid-cols-1 sm:grid-cols-5 gap-3">
            <div>
                <label class="mb-1.5 block text-xs font-bold tracking-wide text-csc-navy-600 dark:text-csc-navy-300">USER</label>
                <div class="relative">
                    <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-gray-400 dark:text-gray-500">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" /></svg>
                    </span>
                    <select name="user_id" id="filter-user" class="w-full rounded-xl bg-gray-50 dark:bg-surface-800 border border-gray-200 dark:border-white/10 pl-10 pr-3 py-2.5 text-sm focus:border-csc-navy-500 focus:ring-2 focus:ring-csc-navy-500/20 focus:outline-none">
                        <option value="">ALL USERS</option>
                        <?php foreach ($allUsers as $u): ?>
                            <option value="<?= (int) $u['id'] ?>" <?= $userId === (int) $u['id'] ? 'selected' : '' ?>><?= e_upper($u['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div>
                <label class="mb-1.5 block text-xs font-bold tracking-wide text-csc-navy-600 dark:text-csc-navy-300">ACTION</label>
                <div class="relative">
                    <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-gray-400 dark:text-gray-500">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.83.699 2.528 0l4.318-4.318a1.788 1.788 0 000-2.528l-9.581-9.581A2.25 2.25 0 009.568 3z" /><path stroke-linecap="round" stroke-linejoin="round" d="M6 6h.008v.008H6V6z" /></svg>
                    </span>
                    <select name="action" id="filter-action" class="w-full rounded-xl bg-gray-50 dark:bg-surface-800 border border-gray-200 dark:border-white/10 pl-10 pr-3 py-2.5 text-sm focus:border-csc-navy-500 focus:ring-2 focus:ring-csc-navy-500/20 focus:outline-none">
                        <option value="">ALL ACTIONS</option>
                        <?php foreach ($actionTypes as $type): ?>
                            <option value="<?= e($type) ?>" <?= $action === $type ? 'selected' : '' ?>><?= e_upper($type) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div>
                <label class="mb-1.5 block text-xs font-bold tracking-wide text-csc-navy-600 dark:text-csc-navy-300">FROM</label>
                <div class="relative">
                    <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-gray-400 dark:text-gray-500">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" /></svg>
                    </span>
                    <input type="date" name="date_from" id="filter-date-from" value="<?= e($dateFrom) ?>" class="w-full rounded-xl bg-gray-50 dark:bg-surface-800 border border-gray-200 dark:border-white/10 pl-10 pr-3 py-2.5 text-sm focus:border-csc-navy-500 focus:ring-2 focus:ring-csc-navy-500/20 focus:outline-none">
                </div>
            </div>
            <div>
                <label class="mb-1.5 block text-xs font-bold tracking-wide text-csc-navy-600 dark:text-csc-navy-300">TO</label>
                <div class="relative">
                    <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-gray-400 dark:text-gray-500">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" /></svg>
                    </span>
                    <input type="date" name="date_to" id="filter-date-to" value="<?= e($dateTo) ?>" class="w-full rounded-xl bg-gray-50 dark:bg-surface-800 border border-gray-200 dark:border-white/10 pl-10 pr-3 py-2.5 text-sm focus:border-csc-navy-500 focus:ring-2 focus:ring-csc-navy-500/20 focus:outline-none">
                </div>
            </div>
            <div class="flex items-end">
                <button type="submit" class="w-full inline-flex items-center justify-center gap-1.5 rounded-xl bg-csc-navy-600 hover:bg-csc-navy-500 text-white text-sm font-semibold px-4 py-2.5 transition-colors">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607Z" /></svg>
                    FILTER
                </button>
            </div>
        </form>
        </div>

        <div id="activity-results">
            <?php if ($searchFailed): ?>
                <div class="rounded-xl border border-red-500/30 bg-red-500/10 p-10 text-center text-red-400 hge-shadow">
                    <p class="text-sm font-medium">THE ACTIVITY LOG COULD NOT BE LOADED. PLEASE TRY AGAIN.</p>
                </div>
            <?php elseif (empty($searchResult['rows'])): ?>
                <div class="rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-surface-900 p-10 text-center text-gray-500 dark:text-gray-400 hge-shadow">
                    <p class="text-sm font-medium text-gray-700 dark:text-gray-300">NO ACTIVITY LOG ENTRIES FOUND.</p>
                </div>
            <?php else: ?>
                <div class="bg-white dark:bg-surface-900 border border-gray-200 dark:border-white/10 rounded-xl overflow-x-auto overflow-y-hidden hge-shadow">
                    <table class="w-full text-sm text-left">
                        <thead>
                            <tr class="text-sm font-semibold text-gray-900 dark:text-white border-b border-gray-200 dark:border-white/10">
                                <th class="px-4 py-3">TIMESTAMP</th>
                                <th class="px-4 py-3">USER</th>
                                <th class="px-4 py-3">ACTION</th>
                                <th class="px-4 py-3">DETAILS</th>
                            </tr>
                        </thead>
                        <tbody id="activity-results-tbody">
                            <?php foreach ($searchResult['rows'] as $rowIndex => $row): ?>
                                <tr class="border-b border-gray-100 dark:border-white/5 hover:bg-gray-100 dark:hover:bg-white/5 motion-safe:animate-fade-in-up" style="animation-delay: <?= (int) ($rowIndex * 50) ?>ms">
                                    <td class="px-4 py-3"><?= e_upper(date('F j, Y g:i A', strtotime($row['created_at']))) ?></td>
                                    <td class="px-4 py-3"><?= $row['user_name'] !== null ? e_upper($row['user_name']) : 'SYSTEM' ?></td>
                                    <td class="px-4 py-3"><?= e_upper($row['action']) ?></td>
                                    <td class="px-4 py-3"><?= ($row['details'] ?? '') !== '' ? e($row['details']) : '—' ?></td>
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
                <div id="activity-pagination" class="flex flex-wrap items-center justify-between gap-3 mt-4 text-sm text-gray-500 dark:text-gray-400">
                    <span>SHOWING <?= (int) $rangeStart ?> TO <?= (int) $rangeEnd ?> OF <?= (int) $pageInfo['total_rows'] ?> ENTRIES</span>
                    <div class="flex items-center gap-1.5">
                        <?php if ($pageInfo['page'] > 1): ?>
                            <button type="button" class="activity-page-btn rounded-lg border border-gray-200 dark:border-white/10 p-1.5 hover:bg-gray-100 dark:hover:bg-white/5" data-page="<?= (int) $pageInfo['page'] - 1 ?>" aria-label="Previous page">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" /></svg>
                            </button>
                        <?php endif; ?>
                        <?php foreach (pagination_window($pageInfo['page'], $pageInfo['total_pages']) as $pageNum): ?>
                            <button type="button" class="activity-page-btn min-w-[2.25rem] rounded-lg px-2.5 py-1.5 text-sm font-medium <?= $pageNum === $pageInfo['page'] ? 'bg-csc-navy-600 text-white' : 'border border-gray-200 dark:border-white/10 hover:bg-gray-100 dark:hover:bg-white/5' ?>" data-page="<?= (int) $pageNum ?>"><?= (int) $pageNum ?></button>
                        <?php endforeach; ?>
                        <?php if ($pageInfo['page'] < $pageInfo['total_pages']): ?>
                            <button type="button" class="activity-page-btn rounded-lg border border-gray-200 dark:border-white/10 p-1.5 hover:bg-gray-100 dark:hover:bg-white/5" data-page="<?= (int) $pageInfo['page'] + 1 ?>" aria-label="Next page">
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
window.HGE_ACTIVITY_LOG = {
    apiBase: <?= json_encode(APP_BASE_URL) ?>
};
</script>
<script src="<?= e(asset_url('assets/js/activity_log.js')) ?>"></script>
