<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ocr_keywords.php';

require_role('administrator');
require_step_up('ocr_courses', 'ocr_courses');

$pdo = get_pdo();
$keywords = get_ocr_course_keywords($pdo);
$currentUser = current_user();
$currentPage = 'ocr_courses';
$csrfToken = csrf_token();

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main class="lg:ml-64 min-h-screen bg-gray-50 dark:bg-surface-950">
    <div class="p-6 lg:p-8">
        <h1 class="text-xl font-semibold text-gray-900 dark:text-white mb-2">OCR COURSE KEYWORDS</h1>
        <p class="text-sm text-gray-600 dark:text-gray-500 mb-6">ADD A DEGREE OR COURSE ABBREVIATION HERE SO THE OCR/TEXT EXTRACTION CAN RECOGNIZE IT ON FUTURE UPLOADS. KEYWORDS ARE ALWAYS STORED IN UPPERCASE.</p>

        <div class="bg-white dark:bg-surface-900 border border-gray-200 dark:border-white/10 rounded-xl p-4 sm:p-6 mb-6 hge-shadow">
            <form id="add-keyword-form" class="flex flex-col sm:flex-row items-start sm:items-end gap-3">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <div class="flex-1 w-full">
                    <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1" for="new-keyword">NEW DEGREE / COURSE KEYWORD</label>
                    <input id="new-keyword" name="keyword" type="text" maxlength="50" placeholder="e.g. BSCRIM" autocomplete="off"
                        class="w-full uppercase rounded-lg bg-gray-100 dark:bg-surface-800 border border-gray-200 dark:border-white/10 px-3 py-2 text-sm placeholder:text-gray-400 dark:placeholder:text-gray-600">
                </div>
                <button type="submit" id="add-keyword-btn"
                    class="w-full sm:w-auto rounded-lg bg-accent-600 hover:bg-accent-500 text-white text-sm font-medium px-4 py-2 transition-colors disabled:opacity-50 disabled:cursor-not-allowed">ADD KEYWORD</button>
            </form>
        </div>

        <div class="bg-white dark:bg-surface-900 border border-gray-200 dark:border-white/10 rounded-xl overflow-x-auto hge-shadow">
            <table class="w-full text-sm text-left">
                <thead>
                    <tr class="text-xs text-gray-600 dark:text-gray-500 border-b border-gray-200 dark:border-white/10">
                        <th class="px-4 py-3">KEYWORD</th>
                        <th class="px-4 py-3">DATE ADDED</th>
                        <th class="px-4 py-3">ACTION</th>
                    </tr>
                </thead>
                <tbody id="keywords-tbody">
                    <?php if (empty($keywords)): ?>
                        <tr id="keywords-empty-row"><td colspan="3" class="px-4 py-6 text-center text-gray-600 dark:text-gray-500">NO CUSTOM COURSE KEYWORDS YET.</td></tr>
                    <?php else: ?>
                        <?php foreach ($keywords as $keyword): ?>
                            <tr class="border-b border-gray-100 dark:border-white/5 hover:bg-gray-100 dark:hover:bg-white/5" data-keyword-id="<?= (int) $keyword['id'] ?>">
                                <td class="px-4 py-3 font-medium text-gray-900 dark:text-white"><?= e($keyword['keyword']) ?></td>
                                <td class="px-4 py-3"><?= e(date('F j, Y', strtotime($keyword['created_at']))) ?></td>
                                <td class="px-4 py-3">
                                    <button type="button" class="remove-keyword-btn text-red-400 hover:underline"
                                        data-keyword-id="<?= (int) $keyword['id'] ?>"
                                        data-keyword="<?= e($keyword['keyword']) ?>">REMOVE</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
<script>
window.HGE_OCR_COURSES = {
    csrfToken: <?= json_encode($csrfToken) ?>,
    apiBase: <?= json_encode(APP_BASE_URL) ?>
};
</script>
<script src="<?= e(asset_url('assets/js/ocr_courses.js')) ?>"></script>
