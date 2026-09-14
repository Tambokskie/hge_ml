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

$actionTypes = ['login', 'logout', 'pdf_upload', 'pdf_delete', 'record_created', 'record_updated', 'record_removed', 'search', 'user_created', 'user_updated'];

$currentUser = current_user();
$currentPage = 'activity_log';

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main class="lg:ml-64 min-h-screen bg-surface-950">
    <div class="p-6 lg:p-8">
        <h1 class="text-xl font-semibold text-white mb-2">ACTIVITY LOG</h1>
        <p class="text-sm text-gray-500 mb-6">ENTRIES ARE RETAINED FOR 6 MONTHS. "SEARCH" ENTRIES REFLECT SESSION ACTIVITY, NOT CONFIRMED USER INTENT.</p>

        <form id="activity-filter-form" method="get" class="grid grid-cols-1 sm:grid-cols-5 gap-3 mb-6">
            <div>
                <label class="block text-xs text-gray-400 mb-1">USER</label>
                <select name="user_id" id="filter-user" class="w-full rounded-lg bg-surface-800 border border-white/10 px-3 py-2 text-sm">
                    <option value="">ALL USERS</option>
                    <?php foreach ($allUsers as $u): ?>
                        <option value="<?= (int) $u['id'] ?>" <?= $userId === (int) $u['id'] ? 'selected' : '' ?>><?= e_upper($u['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-400 mb-1">ACTION</label>
                <select name="action" id="filter-action" class="w-full rounded-lg bg-surface-800 border border-white/10 px-3 py-2 text-sm">
                    <option value="">ALL ACTIONS</option>
                    <?php foreach ($actionTypes as $type): ?>
                        <option value="<?= e($type) ?>" <?= $action === $type ? 'selected' : '' ?>><?= e_upper($type) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-400 mb-1">FROM</label>
                <input type="date" name="date_from" id="filter-date-from" value="<?= e($dateFrom) ?>" class="w-full rounded-lg bg-surface-800 border border-white/10 px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-xs text-gray-400 mb-1">TO</label>
                <input type="date" name="date_to" id="filter-date-to" value="<?= e($dateTo) ?>" class="w-full rounded-lg bg-surface-800 border border-white/10 px-3 py-2 text-sm">
            </div>
            <div class="flex items-end">
                <button type="submit" class="w-full rounded-lg bg-accent-600 hover:bg-accent-500 text-white text-sm font-medium px-4 py-2">FILTER</button>
            </div>
        </form>

        <div id="activity-results">
            <?php if ($searchFailed): ?>
                <div class="rounded-xl border border-red-500/30 bg-red-500/10 p-10 text-center text-red-400">
                    <p class="text-sm font-medium">THE ACTIVITY LOG COULD NOT BE LOADED. PLEASE TRY AGAIN.</p>
                </div>
            <?php elseif (empty($searchResult['rows'])): ?>
                <div class="rounded-xl border border-white/10 bg-surface-900 p-10 text-center text-gray-400">
                    <p class="text-sm font-medium text-gray-300">NO ACTIVITY LOG ENTRIES FOUND.</p>
                </div>
            <?php else: ?>
                <div class="bg-surface-900 border border-white/10 rounded-xl overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead>
                            <tr class="text-xs text-gray-500 border-b border-white/10">
                                <th class="px-4 py-3">TIMESTAMP</th>
                                <th class="px-4 py-3">USER</th>
                                <th class="px-4 py-3">ACTION</th>
                                <th class="px-4 py-3">DETAILS</th>
                            </tr>
                        </thead>
                        <tbody id="activity-results-tbody">
                            <?php foreach ($searchResult['rows'] as $row): ?>
                                <tr class="border-b border-white/5 hover:bg-white/5">
                                    <td class="px-4 py-3"><?= e_upper(date('F j, Y g:i A', strtotime($row['created_at']))) ?></td>
                                    <td class="px-4 py-3"><?= $row['user_name'] !== null ? e_upper($row['user_name']) : 'SYSTEM' ?></td>
                                    <td class="px-4 py-3"><?= e_upper($row['action']) ?></td>
                                    <td class="px-4 py-3"><?= ($row['details'] ?? '') !== '' ? e($row['details']) : '—' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div id="activity-pagination" class="flex items-center justify-between mt-4 text-sm text-gray-400">
                    <span>PAGE <?= (int) $searchResult['pagination']['page'] ?> OF <?= (int) $searchResult['pagination']['total_pages'] ?> (<?= (int) $searchResult['pagination']['total_rows'] ?> ENTRIES)</span>
                    <div class="flex gap-2">
                        <?php if ($searchResult['pagination']['page'] > 1): ?>
                            <button type="button" class="activity-page-btn rounded-lg border border-white/10 px-3 py-1.5 hover:bg-white/5" data-page="<?= (int) $searchResult['pagination']['page'] - 1 ?>">PREV</button>
                        <?php endif; ?>
                        <?php if ($searchResult['pagination']['page'] < $searchResult['pagination']['total_pages']): ?>
                            <button type="button" class="activity-page-btn rounded-lg border border-white/10 px-3 py-1.5 hover:bg-white/5" data-page="<?= (int) $searchResult['pagination']['page'] + 1 ?>">NEXT</button>
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
<script src="<?= e(APP_BASE_URL) ?>/assets/js/activity_log.js"></script>
