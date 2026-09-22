<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/schools.php';
require_once __DIR__ . '/../includes/graduates.php';

require_login();

$pdo = get_pdo();
$schools = get_active_schools_list($pdo);
$years = get_distinct_graduation_years($pdo);

$isFreshLoad = empty($_GET);

$schoolId = (isset($_GET['school_id']) && (int) $_GET['school_id'] > 0) ? (int) $_GET['school_id'] : null;
$year = (isset($_GET['year']) && (int) $_GET['year'] > 0) ? (int) $_GET['year'] : null;
$name = trim((string) ($_GET['name'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));

if ($isFreshLoad) {
    $year = (int) date('Y');
}

$filters = ['school_id' => $schoolId, 'graduation_year' => $year, 'name' => $name];
$hasFilters = $isFreshLoad || $schoolId !== null || $year !== null || $name !== '';

$currentUser = current_user();
$currentPage = 'lookup';
$csrfToken = csrf_token();
$isAdmin = user_has_role($currentUser, 'administrator');

$searchResult = null;
$searchFailed = false;
if ($hasFilters) {
    try {
        $searchResult = search_graduates($pdo, $filters, $page);

        try {
            log_activity($pdo, $currentUser['id'] ?? null, 'search', build_search_log_details($filters));
        } catch (\Throwable $logError) {
            error_log('Activity log failed: ' . $logError->getMessage());
        }
    } catch (\Throwable $e) {
        error_log('Graduate search failed: ' . $e->getMessage());
        $searchFailed = true;
    }
}

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main class="lg:ml-64 min-h-screen bg-gray-50 dark:bg-surface-950">
    <div class="p-6 lg:p-8">
        <h1 class="text-xl font-semibold text-gray-900 dark:text-white mb-2">HONOR GRADUATE LOOK-UP</h1>
        <p class="text-sm text-gray-600 dark:text-gray-500 mb-6">SEARCH BY SCHOOL, GRADUATION YEAR, OR GRADUATE NAME TO VERIFY A HONOR GRADUATE RECORD.</p>

        <div class="bg-white dark:bg-surface-900 border border-gray-200 dark:border-white/10 rounded-xl p-4 sm:p-6 mb-6 hge-shadow">
            <form id="lookup-filter-form" method="get" class="grid grid-cols-1 sm:grid-cols-4 gap-3">
                <div>
                    <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">SCHOOL</label>
                    <select name="school_id" id="filter-school" class="w-full rounded-lg bg-gray-100 dark:bg-surface-800 border border-gray-200 dark:border-white/10 px-3 py-2 text-sm">
                        <option value="">ALL SCHOOLS</option>
                        <?php foreach ($schools as $school): ?>
                            <option value="<?= (int) $school['id'] ?>" <?= $schoolId === (int) $school['id'] ? 'selected' : '' ?>><?= e_upper(format_school_display_name($school['school_name'], $school['campus'])) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">GRADUATION YEAR</label>
                    <select name="year" id="filter-year" class="w-full rounded-lg bg-gray-100 dark:bg-surface-800 border border-gray-200 dark:border-white/10 px-3 py-2 text-sm">
                        <option value="">ALL YEARS</option>
                        <?php foreach ($years as $y): ?>
                            <option value="<?= (int) $y ?>" <?= $year === (int) $y ? 'selected' : '' ?>><?= (int) $y ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">GRADUATE NAME</label>
                    <input type="text" name="name" id="filter-name" value="<?= e($name) ?>" placeholder="SEARCH BY NAME..." class="w-full rounded-lg bg-gray-100 dark:bg-surface-800 border border-gray-200 dark:border-white/10 px-3 py-2 text-sm uppercase placeholder:text-gray-400 dark:placeholder:text-gray-600">
                </div>
                <div class="sm:col-span-4 flex flex-wrap items-center justify-between gap-3">
                    <button type="submit" class="rounded-lg bg-accent-600 hover:bg-accent-500 text-white text-sm font-medium px-4 py-2">SEARCH</button>
                    <?php if ($isAdmin): ?>
                    <button type="button" id="bulk-update-date-btn" class="rounded-lg border border-accent-500/40 text-accent-600 dark:text-accent-300 hover:bg-accent-500/10 text-sm font-medium px-4 py-2">UPDATE GRADUATION DATE</button>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div id="lookup-results">
            <?php if (!$hasFilters): ?>
                <div class="rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-surface-900 p-10 text-center text-gray-500 dark:text-gray-400 hge-shadow">
                    <p class="text-sm">SEARCH BY NAME, SCHOOL, OR YEAR TO FIND HONOR GRADUATE RECORDS.</p>
                </div>
            <?php elseif ($searchFailed): ?>
                <div class="rounded-xl border border-red-500/30 bg-red-500/10 p-10 text-center text-red-400 hge-shadow">
                    <p class="text-sm font-medium">THE SEARCH COULD NOT BE COMPLETED. PLEASE TRY AGAIN.</p>
                </div>
            <?php elseif (empty($searchResult['rows'])): ?>
                <div class="rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-surface-900 p-10 text-center text-gray-500 dark:text-gray-400 hge-shadow">
                    <p class="text-sm font-medium text-gray-700 dark:text-gray-300">NO HONOR GRADUATE RECORD FOUND.</p>
                </div>
            <?php else: ?>
                <div class="bg-white dark:bg-surface-900 border border-gray-200 dark:border-white/10 rounded-xl overflow-x-auto hge-shadow">
                    <table class="w-full text-sm text-left">
                        <thead>
                            <tr class="text-sm font-semibold text-gray-900 dark:text-white border-b border-gray-200 dark:border-white/10">
                                <th class="px-4 py-3">GRADUATE NAME</th>
                                <th class="px-4 py-3">DEGREE/COURSE</th>
                                <th class="px-4 py-3">SCHOOL</th>
                                <th class="px-4 py-3">LATIN HONOR</th>
                                <th class="px-4 py-3">GRADUATION YEAR</th>
                                <th class="px-4 py-3">ACTION</th>
                                <th class="px-4 py-3">APPLIED</th>
                            </tr>
                        </thead>
                        <tbody id="lookup-results-tbody">
                            <?php foreach ($searchResult['rows'] as $row): ?>
                                <tr class="border-b border-gray-100 dark:border-white/5 hover:bg-gray-100 dark:hover:bg-white/5">
                                    <td class="px-4 py-3"><?= e_upper($row['graduate_name']) ?></td>
                                    <td class="px-4 py-3"><?= ($row['course'] ?? '') !== '' ? e_upper($row['course']) : '—' ?></td>
                                    <td class="px-4 py-3"><?= e_upper(format_school_display_name($row['school_name'], $row['campus'])) ?></td>
                                    <td class="px-4 py-3"><?= e($row['latin_honor']) ?></td>
                                    <td class="px-4 py-3"><?= e((string) $row['graduation_year']) ?></td>
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <button type="button" class="view-graduate-btn text-green-600 dark:text-green-400 hover:underline"
                                            data-name="<?= e($row['graduate_name']) ?>"
                                            data-course="<?= e($row['course'] ?? '') ?>"
                                            data-school="<?= e(format_school_display_name($row['school_name'], $row['campus'])) ?>"
                                            data-honor="<?= e($row['latin_honor']) ?>"
                                            data-date="<?= e(format_date_display($row['graduation_date'])) ?>"
                                            data-year="<?= e((string) $row['graduation_year']) ?>"
                                            data-upload-id="<?= (int) $row['upload_id'] ?>"
                                            data-filename="<?= e($row['original_filename']) ?>"
                                            data-uploaded-at="<?= e_upper(date('F j, Y', strtotime($row['uploaded_at']))) ?>">VIEW</button>
                                        <?php if ((int) $row['has_applied'] === 1): ?>
                                        <span class="inline-block rounded px-1.5 py-0.5 text-[10px] font-medium bg-blue-500/20 text-blue-600 dark:text-blue-400 ml-3">APPLIED</span>
                                        <?php else: ?>
                                        <button type="button" class="apply-graduate-btn text-blue-600 dark:text-blue-400 hover:underline ml-3"
                                            data-id="<?= (int) $row['id'] ?>"
                                            data-name="<?= e($row['graduate_name']) ?>">APPLIED</button>
                                        <?php endif; ?>
                                        <?php if ($isAdmin): ?>
                                        <button type="button" class="edit-graduate-btn text-yellow-600 dark:text-yellow-400 hover:underline ml-3"
                                            data-id="<?= (int) $row['id'] ?>"
                                            data-name="<?= e($row['graduate_name']) ?>"
                                            data-course="<?= e($row['course'] ?? '') ?>"
                                            data-honor="<?= e($row['latin_honor']) ?>"
                                            data-date="<?= e((string) $row['graduation_date']) ?>"
                                            data-year="<?= e((string) $row['graduation_year']) ?>">EDIT</button>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3"><?= e(format_date_display($row['application_date'] ?? null)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div id="lookup-pagination" class="flex items-center justify-between mt-4 text-sm text-gray-500 dark:text-gray-400">
                    <span>PAGE <?= (int) $searchResult['pagination']['page'] ?> OF <?= (int) $searchResult['pagination']['total_pages'] ?> (<?= (int) $searchResult['pagination']['total_rows'] ?> RECORDS)</span>
                    <div class="flex gap-2">
                        <?php if ($searchResult['pagination']['page'] > 1): ?>
                            <button type="button" class="lookup-page-btn rounded-lg border border-gray-200 dark:border-white/10 px-3 py-1.5 hover:bg-gray-100 dark:hover:bg-white/5" data-page="<?= (int) $searchResult['pagination']['page'] - 1 ?>">PREV</button>
                        <?php endif; ?>
                        <?php if ($searchResult['pagination']['page'] < $searchResult['pagination']['total_pages']): ?>
                            <button type="button" class="lookup-page-btn rounded-lg border border-gray-200 dark:border-white/10 px-3 py-1.5 hover:bg-gray-100 dark:hover:bg-white/5" data-page="<?= (int) $searchResult['pagination']['page'] + 1 ?>">NEXT</button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php include __DIR__ . '/../includes/pdf_drawer.php'; ?>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
<script>
window.HGE_LOOKUP = {
    apiBase: <?= json_encode(APP_BASE_URL) ?>,
    csrfToken: <?= json_encode($csrfToken) ?>,
    isAdmin: <?= json_encode($isAdmin) ?>
};
</script>
<script src="<?= e(asset_url('assets/js/lookup.js')) ?>"></script>
