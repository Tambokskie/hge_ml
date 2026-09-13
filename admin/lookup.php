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

$schoolId = (isset($_GET['school_id']) && (int) $_GET['school_id'] > 0) ? (int) $_GET['school_id'] : null;
$year = (isset($_GET['year']) && (int) $_GET['year'] > 0) ? (int) $_GET['year'] : null;
$name = trim((string) ($_GET['name'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));

$filters = ['school_id' => $schoolId, 'graduation_year' => $year, 'name' => $name];
$hasFilters = $schoolId !== null || $year !== null || $name !== '';

$currentUser = current_user();
$currentPage = 'lookup';

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
<main class="lg:ml-64 min-h-screen bg-surface-950">
    <div class="p-6 lg:p-8">
        <h1 class="text-xl font-semibold text-white mb-2">HONOR GRADUATE LOOK-UP</h1>
        <p class="text-sm text-gray-500 mb-6">SEARCH BY SCHOOL, GRADUATION YEAR, OR GRADUATE NAME TO VERIFY A HONOR GRADUATE RECORD.</p>

        <form id="lookup-filter-form" method="get" class="grid grid-cols-1 sm:grid-cols-4 gap-3 mb-6">
            <div>
                <label class="block text-xs text-gray-400 mb-1">SCHOOL</label>
                <select name="school_id" id="filter-school" class="w-full rounded-lg bg-surface-800 border border-white/10 px-3 py-2 text-sm">
                    <option value="">ALL SCHOOLS</option>
                    <?php foreach ($schools as $school): ?>
                        <option value="<?= (int) $school['id'] ?>" <?= $schoolId === (int) $school['id'] ? 'selected' : '' ?>><?= e_upper($school['school_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-400 mb-1">GRADUATION YEAR</label>
                <select name="year" id="filter-year" class="w-full rounded-lg bg-surface-800 border border-white/10 px-3 py-2 text-sm">
                    <option value="">ALL YEARS</option>
                    <?php foreach ($years as $y): ?>
                        <option value="<?= (int) $y ?>" <?= $year === (int) $y ? 'selected' : '' ?>><?= (int) $y ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="sm:col-span-2">
                <label class="block text-xs text-gray-400 mb-1">GRADUATE NAME</label>
                <input type="text" name="name" id="filter-name" value="<?= e($name) ?>" placeholder="SEARCH BY NAME..." class="w-full rounded-lg bg-surface-800 border border-white/10 px-3 py-2 text-sm placeholder:text-gray-600">
            </div>
            <div class="sm:col-span-4">
                <button type="submit" class="rounded-lg bg-accent-600 hover:bg-accent-500 text-white text-sm font-medium px-4 py-2">SEARCH</button>
            </div>
        </form>

        <div id="lookup-results">
            <?php if (!$hasFilters): ?>
                <div class="rounded-xl border border-white/10 bg-surface-900 p-10 text-center text-gray-400">
                    <p class="text-sm">SEARCH BY NAME, SCHOOL, OR YEAR TO FIND HONOR GRADUATE RECORDS.</p>
                </div>
            <?php elseif ($searchFailed): ?>
                <div class="rounded-xl border border-red-500/30 bg-red-500/10 p-10 text-center text-red-400">
                    <p class="text-sm font-medium">THE SEARCH COULD NOT BE COMPLETED. PLEASE TRY AGAIN.</p>
                </div>
            <?php elseif (empty($searchResult['rows'])): ?>
                <div class="rounded-xl border border-white/10 bg-surface-900 p-10 text-center text-gray-400">
                    <p class="text-sm font-medium text-gray-300">NO HONOR GRADUATE RECORD FOUND.</p>
                </div>
            <?php else: ?>
                <div class="bg-surface-900 border border-white/10 rounded-xl overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead>
                            <tr class="text-xs text-gray-500 border-b border-white/10">
                                <th class="px-4 py-3">GRADUATE NAME</th>
                                <th class="px-4 py-3">SCHOOL</th>
                                <th class="px-4 py-3">LATIN HONOR</th>
                                <th class="px-4 py-3">GRADUATION YEAR</th>
                                <th class="px-4 py-3">ACTION</th>
                            </tr>
                        </thead>
                        <tbody id="lookup-results-tbody">
                            <?php foreach ($searchResult['rows'] as $row): ?>
                                <tr class="border-b border-white/5 hover:bg-white/5">
                                    <td class="px-4 py-3"><?= e_upper($row['graduate_name']) ?></td>
                                    <td class="px-4 py-3"><?= e_upper($row['school_name']) ?></td>
                                    <td class="px-4 py-3"><?= e($row['latin_honor']) ?></td>
                                    <td class="px-4 py-3"><?= e((string) $row['graduation_year']) ?></td>
                                    <td class="px-4 py-3">
                                        <button type="button" class="view-graduate-btn text-accent-400 hover:underline"
                                            data-name="<?= e($row['graduate_name']) ?>"
                                            data-school="<?= e($row['school_name']) ?>"
                                            data-honor="<?= e($row['latin_honor']) ?>"
                                            data-date="<?= e(format_date_display($row['graduation_date'])) ?>"
                                            data-year="<?= e((string) $row['graduation_year']) ?>"
                                            data-upload-id="<?= (int) $row['upload_id'] ?>"
                                            data-filename="<?= e($row['original_filename']) ?>"
                                            data-uploaded-at="<?= e_upper(date('F j, Y', strtotime($row['uploaded_at']))) ?>">VIEW</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div id="lookup-pagination" class="flex items-center justify-between mt-4 text-sm text-gray-400">
                    <span>PAGE <?= (int) $searchResult['pagination']['page'] ?> OF <?= (int) $searchResult['pagination']['total_pages'] ?> (<?= (int) $searchResult['pagination']['total_rows'] ?> RECORDS)</span>
                    <div class="flex gap-2">
                        <?php if ($searchResult['pagination']['page'] > 1): ?>
                            <button type="button" class="lookup-page-btn rounded-lg border border-white/10 px-3 py-1.5 hover:bg-white/5" data-page="<?= (int) $searchResult['pagination']['page'] - 1 ?>">PREV</button>
                        <?php endif; ?>
                        <?php if ($searchResult['pagination']['page'] < $searchResult['pagination']['total_pages']): ?>
                            <button type="button" class="lookup-page-btn rounded-lg border border-white/10 px-3 py-1.5 hover:bg-white/5" data-page="<?= (int) $searchResult['pagination']['page'] + 1 ?>">NEXT</button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
<script>
window.HGE_LOOKUP = {
    apiBase: <?= json_encode(APP_BASE_URL) ?>
};
</script>
<script src="<?= e(APP_BASE_URL) ?>/assets/js/lookup.js"></script>
