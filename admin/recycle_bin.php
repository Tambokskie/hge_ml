<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/recycle_bin.php';
require_once __DIR__ . '/../includes/recycle_bin_items.php';

require_role('administrator');

$pdo = get_pdo();

$name = trim((string) ($_GET['name'] ?? ''));
$via = (string) ($_GET['via'] ?? '');
$type = (string) ($_GET['type'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));

$result = get_recycle_bin_combined($pdo, ['name' => $name, 'via' => $via, 'type' => $type], $page);
$rows = $result['rows'];
$pagination = $result['pagination'];
$notice = $result['notice'];
$typeBadgeClasses = [
    'record' => 'bg-csc-navy-600/10 text-csc-navy-600 dark:text-csc-navy-300',
    'upload' => 'bg-blue-500/10 text-blue-700 dark:text-blue-400',
    'school' => 'bg-purple-500/10 text-purple-700 dark:text-purple-400',
    'user' => 'bg-orange-500/10 text-orange-700 dark:text-orange-400',
    'ocr_keyword' => 'bg-gray-500/10 text-gray-700 dark:text-gray-300',
];

$viaLabels = ['manual' => 'DELETED', 'duplicate_cleanup' => 'DUPLICATE CLEANUP', 'review_edit' => 'RE-REVIEW'];

function recycle_bin_page_url(int $targetPage, string $name, string $via, string $type): string
{
    return APP_BASE_URL . '/admin/recycle_bin.php?' . http_build_query(array_filter(['name' => $name, 'via' => $via, 'type' => $type, 'page' => $targetPage > 1 ? $targetPage : null]));
}

$currentUser = current_user();
$currentPage = 'recycle_bin';
$csrfToken = csrf_token();

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main class="lg:ml-64 min-h-screen bg-gray-50 dark:bg-surface-950">
    <div class="p-6 lg:p-8">
        <div class="mb-6 flex items-start gap-4">
            <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-csc-red-600/10 text-csc-red-600 dark:text-csc-red-400">
                <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" /></svg>
            </div>
            <div>
                <h1 class="text-2xl font-bold text-csc-navy-600 dark:text-white">RECYCLE BIN</h1>
                <p class="text-sm text-gray-600 dark:text-gray-500 mt-1">EVERYTHING REMOVED FROM THE SYSTEM (RECORDS, UPLOADS, SCHOOLS, USERS AND KEYWORDS) IS KEPT HERE FIRST. RESTORE AN ITEM TO PUT IT BACK EXACTLY AS IT WAS, OR DELETE IT PERMANENTLY. EVERY REMOVAL IS ALSO IN THE ACTIVITY LOG.</p>
            </div>
        </div>

        <div class="bg-white dark:bg-surface-900 border border-gray-200 dark:border-white/10 rounded-xl p-4 sm:p-6 mb-6 hge-shadow motion-safe:animate-fade-in-up">
            <form method="get" class="grid grid-cols-1 sm:grid-cols-5 gap-3 items-end">
                <div class="sm:col-span-2">
                    <label class="mb-1.5 block text-xs font-bold tracking-wide text-csc-navy-600 dark:text-csc-navy-300" for="bin-name">NAME</label>
                    <input type="text" name="name" id="bin-name" value="<?= e($name) ?>" placeholder="SEARCH BY NAME..." class="w-full rounded-xl bg-gray-50 dark:bg-surface-800 border border-gray-200 dark:border-white/10 px-3 py-2.5 text-sm uppercase placeholder:text-gray-400 placeholder:normal-case focus:border-csc-navy-500 focus:ring-2 focus:ring-csc-navy-500/20 focus:outline-none">
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-bold tracking-wide text-csc-navy-600 dark:text-csc-navy-300" for="bin-type">TYPE</label>
                    <select name="type" id="bin-type" class="w-full rounded-xl bg-gray-50 dark:bg-surface-800 border border-gray-200 dark:border-white/10 px-3 py-2.5 text-sm focus:border-csc-navy-500 focus:ring-2 focus:ring-csc-navy-500/20 focus:outline-none">
                        <option value="">ALL</option>
                        <?php foreach (RECYCLE_BIN_TYPE_LABELS as $key => $label): ?>
                            <option value="<?= e($key) ?>" <?= $type === $key ? 'selected' : '' ?>><?= e($label) ?>S</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-bold tracking-wide text-csc-navy-600 dark:text-csc-navy-300" for="bin-via">REMOVED BY</label>
                    <select name="via" id="bin-via" class="w-full rounded-xl bg-gray-50 dark:bg-surface-800 border border-gray-200 dark:border-white/10 px-3 py-2.5 text-sm focus:border-csc-navy-500 focus:ring-2 focus:ring-csc-navy-500/20 focus:outline-none">
                        <option value="">ALL</option>
                        <?php foreach ($viaLabels as $key => $label): ?>
                            <option value="<?= e($key) ?>" <?= $via === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex items-center gap-2">
                    <button type="submit" class="inline-flex items-center gap-1.5 rounded-xl bg-csc-navy-600 hover:bg-csc-navy-500 text-white text-sm font-semibold px-4 py-2.5 transition-colors">SEARCH</button>
                    <a href="<?= e(APP_BASE_URL) ?>/admin/recycle_bin.php" class="inline-flex items-center rounded-xl border border-gray-300 dark:border-white/15 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5 text-sm font-medium px-4 py-2.5 transition-colors">CLEAR</a>
                </div>
            </form>
        </div>

        <?php if ($notice !== null): ?>
            <div class="mb-4 rounded-lg border border-yellow-500/30 bg-yellow-500/10 text-yellow-700 dark:text-yellow-400 text-sm px-4 py-3"><?= e($notice) ?></div>
        <?php endif; ?>

        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
            <span class="inline-flex items-center rounded-full bg-gray-100 dark:bg-white/5 px-3 py-1.5 text-xs font-semibold text-gray-600 dark:text-gray-400">
                <?= (int) $pagination['total_rows'] ?> ITEM<?= (int) $pagination['total_rows'] === 1 ? '' : 'S' ?> IN THE BIN
            </span>
            <div class="flex items-center gap-2">
                <button type="button" id="restore-selected-btn" disabled class="inline-flex items-center gap-1.5 rounded-xl bg-green-600 hover:bg-green-500 text-white text-sm font-semibold px-4 py-2.5 transition-colors disabled:opacity-50 disabled:cursor-not-allowed">RESTORE SELECTED</button>
                <button type="button" id="purge-selected-btn" disabled class="inline-flex items-center gap-1.5 rounded-xl bg-csc-red-600 hover:bg-csc-red-500 text-white text-sm font-semibold px-4 py-2.5 transition-colors disabled:opacity-50 disabled:cursor-not-allowed">DELETE PERMANENTLY</button>
            </div>
        </div>

        <div class="bg-white dark:bg-surface-900 border border-gray-200 dark:border-white/10 rounded-xl overflow-x-auto hge-shadow">
            <table class="w-full text-sm text-left">
                <thead class="text-xs text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-white/10">
                    <tr>
                        <th class="px-4 py-3 w-10"><input type="checkbox" id="bin-select-all" class="h-4 w-4 rounded border-gray-300 dark:border-white/20" aria-label="Select all"></th>
                        <th class="px-4 py-3">TYPE</th>
                        <th class="px-4 py-3">NAME</th>
                        <th class="px-4 py-3">DETAILS</th>
                        <th class="px-4 py-3">REMOVED</th>
                        <th class="px-4 py-3">REASON</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="6" class="px-4 py-10 text-center text-gray-500 dark:text-gray-400">THE RECYCLE BIN IS EMPTY.</td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <?php
                            $isRecord = $row['kind'] === 'record';
                            // Graduate records and other items live in different tables: "i" marks the latter in the selection.
                            $selectValue = ($isRecord ? '' : 'i') . (int) $row['bin_id'];
                            $details = $isRecord
                                ? implode(' · ', array_filter([
                                    $row['course'] !== '' ? $row['course'] : null,
                                    $row['latin_honor'],
                                    $row['graduation_year'] !== null ? (string) (int) $row['graduation_year'] : null,
                                    $row['school_name'] !== null ? format_school_display_name($row['school_name'], $row['campus']) : null,
                                ]))
                                : (string) $row['details'];
                            ?>
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="px-4 py-3"><input type="checkbox" class="bin-checkbox h-4 w-4 rounded border-gray-300 dark:border-white/20" value="<?= e($selectValue) ?>"></td>
                                <td class="px-4 py-3"><span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold <?= e($typeBadgeClasses[$row['kind']] ?? $typeBadgeClasses['record']) ?>"><?= e(RECYCLE_BIN_TYPE_LABELS[$row['kind']] ?? strtoupper($row['kind'])) ?></span></td>
                                <td class="px-4 py-3"><?= e_upper($row['title']) ?></td>
                                <td class="px-4 py-3 text-xs text-gray-600 dark:text-gray-400"><?= $details !== '' ? e_upper($details) : '—' ?></td>
                                <td class="px-4 py-3 text-xs text-gray-600 dark:text-gray-400">
                                    <?= e_upper(date('M j, Y g:i A', strtotime($row['deleted_at']))) ?><br>
                                    <?= e_upper($row['deleted_by_name'] ?? '—') ?><?= $isRecord ? ' · ' . e($viaLabels[$row['via']] ?? $row['via']) : '' ?>
                                </td>
                                <td class="px-4 py-3 text-xs text-gray-600 dark:text-gray-400"><?= e_upper($row['deleted_reason'] !== '' ? $row['deleted_reason'] : '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($pagination['total_pages'] > 1): ?>
            <div class="mt-4 flex flex-wrap items-center justify-center gap-1.5">
                <?php foreach (pagination_window($pagination['page'], $pagination['total_pages']) as $p): ?>
                    <a href="<?= e(recycle_bin_page_url($p, $name, $via, $type)) ?>" class="rounded-lg px-3 py-1.5 text-sm <?= $p === $pagination['page'] ? 'bg-csc-navy-600 text-white' : 'text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5' ?>"><?= (int) $p ?></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
<script>
window.HGE_RECYCLE_BIN = {
    apiBase: <?= json_encode(APP_BASE_URL) ?>,
    csrfToken: <?= json_encode($csrfToken) ?>
};
</script>
<script src="<?= e(asset_url('assets/js/recycle_bin.js')) ?>"></script>
