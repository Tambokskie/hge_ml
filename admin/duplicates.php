<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/schools.php';
require_once __DIR__ . '/../includes/duplicates.php';

require_role('administrator');

$pdo = get_pdo();
$schools = get_active_schools_list($pdo);
$years = get_distinct_graduation_years($pdo);

$schoolId = (isset($_GET['school_id']) && (int) $_GET['school_id'] > 0) ? (int) $_GET['school_id'] : null;
$year = (isset($_GET['year']) && (int) $_GET['year'] > 0) ? (int) $_GET['year'] : null;

$groupLimit = 100;
$allGroups = find_duplicate_groups($pdo, $schoolId, $year);
$groups = array_slice($allGroups, 0, $groupLimit);
$extraCopyCount = 0;
foreach ($allGroups as $group) {
    $extraCopyCount += count($group['members']) - 1;
}

$currentUser = current_user();
$currentPage = 'duplicates';
$csrfToken = csrf_token();

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main class="lg:ml-64 min-h-screen bg-gray-50 dark:bg-surface-950">
    <div class="p-6 lg:p-8">
        <div class="mb-6 flex items-start gap-4">
            <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-yellow-500/10 text-yellow-600 dark:text-yellow-400">
                <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 01-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 011.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 00-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 01-1.125-1.125v-9.25m12 6.625v-1.875a3.375 3.375 0 00-3.375-3.375h-1.5a1.125 1.125 0 01-1.125-1.125v-1.5a3.375 3.375 0 00-3.375-3.375H9.75" /></svg>
            </div>
            <div>
                <h1 class="text-2xl font-bold text-csc-navy-600 dark:text-white">DUPLICATE RECORDS</h1>
                <p class="text-sm text-gray-600 dark:text-gray-500 mt-1">RECORDS WITH THE SAME SCHOOL, GRADUATION YEAR, LATIN HONOR, NAME, AND COURSE. CHOOSE WHICH EXTRA COPIES TO REMOVE — THEY GO TO THE RECYCLE BIN AND CAN BE RESTORED.</p>
            </div>
        </div>

        <div class="bg-white dark:bg-surface-900 border border-gray-200 dark:border-white/10 rounded-xl p-4 sm:p-6 mb-6 hge-shadow motion-safe:animate-fade-in-up">
            <form method="get" class="grid grid-cols-1 sm:grid-cols-4 gap-3 items-end">
                <div class="sm:col-span-2">
                    <label class="mb-1.5 block text-xs font-bold tracking-wide text-csc-navy-600 dark:text-csc-navy-300" for="dup-school">SCHOOL</label>
                    <select name="school_id" id="dup-school" class="w-full rounded-xl bg-gray-50 dark:bg-surface-800 border border-gray-200 dark:border-white/10 px-3 py-2.5 text-sm focus:border-csc-navy-500 focus:ring-2 focus:ring-csc-navy-500/20 focus:outline-none">
                        <option value="">ALL SCHOOLS</option>
                        <?php foreach ($schools as $school): ?>
                            <option value="<?= (int) $school['id'] ?>" <?= $schoolId === (int) $school['id'] ? 'selected' : '' ?>><?= e_upper(format_school_display_name($school['school_name'], $school['campus'])) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-bold tracking-wide text-csc-navy-600 dark:text-csc-navy-300" for="dup-year">GRADUATION YEAR</label>
                    <select name="year" id="dup-year" class="w-full rounded-xl bg-gray-50 dark:bg-surface-800 border border-gray-200 dark:border-white/10 px-3 py-2.5 text-sm focus:border-csc-navy-500 focus:ring-2 focus:ring-csc-navy-500/20 focus:outline-none">
                        <option value="">ALL YEARS</option>
                        <?php foreach ($years as $y): ?>
                            <option value="<?= (int) $y ?>" <?= $year === (int) $y ? 'selected' : '' ?>><?= (int) $y ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex items-center gap-2">
                    <button type="submit" class="inline-flex items-center gap-1.5 rounded-xl bg-csc-navy-600 hover:bg-csc-navy-500 text-white text-sm font-semibold px-4 py-2.5 transition-colors">SCAN</button>
                    <a href="<?= e(APP_BASE_URL) ?>/admin/duplicates.php" class="inline-flex items-center rounded-xl border border-gray-300 dark:border-white/15 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5 text-sm font-medium px-4 py-2.5 transition-colors">CLEAR</a>
                </div>
            </form>
        </div>

        <?php if (empty($allGroups)): ?>
            <div class="rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-surface-900 p-10 text-center hge-shadow">
                <p class="text-sm font-medium text-gray-700 dark:text-gray-300">NO DUPLICATE RECORDS FOUND.</p>
            </div>
        <?php else: ?>
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <span class="inline-flex items-center rounded-full bg-yellow-500/15 text-yellow-700 dark:bg-yellow-500/10 dark:text-yellow-400 px-3 py-1.5 text-xs font-semibold">
                    <?= count($allGroups) ?> DUPLICATE GROUP<?= count($allGroups) === 1 ? '' : 'S' ?> · <?= (int) $extraCopyCount ?> EXTRA COPIES
                </span>
                <button type="button" id="remove-selected-btn" disabled class="inline-flex items-center gap-1.5 rounded-xl bg-csc-red-600 hover:bg-csc-red-500 text-white text-sm font-semibold px-4 py-2.5 transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
                    MOVE SELECTED TO RECYCLE BIN (<span id="selected-count">0</span>)
                </button>
            </div>
            <?php if (count($allGroups) > $groupLimit): ?>
                <p class="mb-4 text-xs text-gray-500 dark:text-gray-400">SHOWING THE FIRST <?= (int) $groupLimit ?> GROUPS. FILTER BY SCHOOL OR YEAR TO SEE THE REST.</p>
            <?php endif; ?>

            <div class="space-y-4">
                <?php foreach ($groups as $groupIndex => $group): ?>
                    <?php $first = $group['members'][0]; ?>
                    <div class="bg-white dark:bg-surface-900 border border-gray-200 dark:border-white/10 rounded-xl overflow-hidden hge-shadow motion-safe:animate-fade-in-up" style="animation-delay: <?= (int) min($groupIndex, 10) * 40 ?>ms">
                        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 dark:border-white/5 px-4 py-3">
                            <div>
                                <p class="text-sm font-semibold text-gray-900 dark:text-white"><?= e_upper($first['graduate_name']) ?></p>
                                <p class="text-xs text-gray-500 dark:text-gray-400"><?= e_upper($first['course'] !== '' ? $first['course'] : '—') ?> · <?= e_upper(format_school_display_name($first['school_name'], $first['campus'])) ?> · <?= e($first['latin_honor']) ?> · <?= (int) $first['graduation_year'] ?></p>
                            </div>
                            <span class="text-xs font-semibold text-gray-500 dark:text-gray-400"><?= count($group['members']) ?> COPIES</span>
                        </div>
                        <table class="w-full text-sm text-left">
                            <tbody>
                                <?php foreach ($group['members'] as $memberIndex => $member): ?>
                                    <?php
                                    $isKeeper = $memberIndex === 0;
                                    $isLocked = (int) $member['has_applied'] === 1;
                                    ?>
                                    <tr class="border-b border-gray-100 dark:border-white/5 last:border-0">
                                        <td class="px-4 py-2.5 w-10">
                                            <input type="checkbox" class="dup-checkbox h-4 w-4 rounded border-gray-300 dark:border-white/20" value="<?= (int) $member['id'] ?>"
                                                <?= $isKeeper || $isLocked ? 'disabled' : 'checked' ?>
                                                title="<?= $isLocked ? 'HAS A FILED APPLICATION — CANNOT BE REMOVED' : ($isKeeper ? 'SUGGESTED COPY TO KEEP' : 'MOVE TO RECYCLE BIN') ?>">
                                        </td>
                                        <td class="px-2 py-2.5 text-xs text-gray-500 dark:text-gray-400">#<?= (int) $member['id'] ?></td>
                                        <td class="px-2 py-2.5 text-xs text-gray-600 dark:text-gray-300"><?= e($member['original_filename']) ?> (UPLOAD <?= (int) $member['upload_number'] ?>)</td>
                                        <td class="px-2 py-2.5 text-xs text-gray-600 dark:text-gray-300"><?= $member['graduation_date'] ? e_upper(date('M j, Y', strtotime($member['graduation_date']))) : '—' ?></td>
                                        <td class="px-2 py-2.5 text-right">
                                            <?php if ($isKeeper): ?>
                                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-[10px] font-semibold bg-green-500/15 text-green-700 dark:bg-green-500/10 dark:text-green-400">KEEP</span>
                                            <?php endif; ?>
                                            <?php if ($isLocked): ?>
                                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-[10px] font-semibold bg-csc-navy-600/10 text-csc-navy-600 dark:bg-csc-navy-600/20 dark:text-csc-navy-300">FILED <?= e_upper(date('M j, Y', strtotime($member['application_date']))) ?></span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
<script>
window.HGE_DUPLICATES = {
    apiBase: <?= json_encode(APP_BASE_URL) ?>,
    csrfToken: <?= json_encode($csrfToken) ?>
};
</script>
<script src="<?= e(asset_url('assets/js/duplicates.js')) ?>"></script>
