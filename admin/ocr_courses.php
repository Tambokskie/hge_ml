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
        <div class="mb-6 flex items-start gap-4">
            <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-csc-navy-600/10 text-csc-navy-600 dark:text-csc-navy-300">
                <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25" /></svg>
            </div>
            <div>
                <h1 class="text-2xl font-bold text-csc-navy-600 dark:text-white">OCR COURSE KEYWORDS</h1>
                <p class="text-sm text-gray-600 dark:text-gray-500 mt-1">ADD A DEGREE OR COURSE ABBREVIATION HERE SO THE OCR/TEXT EXTRACTION CAN RECOGNIZE IT ON FUTURE UPLOADS. KEYWORDS ARE ALWAYS STORED IN UPPERCASE.</p>
            </div>
        </div>

        <div class="bg-white dark:bg-surface-900 border border-gray-200 dark:border-white/10 rounded-xl p-4 sm:p-6 mb-6 hge-shadow">
            <form id="add-keyword-form" class="flex flex-col sm:flex-row items-start sm:items-end gap-3">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <div class="flex-1 w-full">
                    <label class="mb-1.5 block text-xs font-bold tracking-wide text-csc-navy-600 dark:text-csc-navy-300" for="new-keyword">NEW DEGREE / COURSE KEYWORD</label>
                    <input id="new-keyword" name="keyword" type="text" maxlength="50" placeholder="e.g. BSCRIM" autocomplete="off"
                        class="w-full uppercase rounded-xl bg-gray-50 dark:bg-surface-800 border border-gray-200 dark:border-white/10 px-3 py-2.5 text-sm placeholder:text-gray-400 placeholder:normal-case dark:placeholder:text-gray-600 focus:border-csc-navy-500 focus:ring-2 focus:ring-csc-navy-500/20 focus:outline-none">
                </div>
                <button type="submit" id="add-keyword-btn"
                    class="w-full sm:w-auto inline-flex items-center justify-center gap-1.5 rounded-xl bg-csc-navy-600 hover:bg-csc-navy-500 text-white text-sm font-semibold px-4 py-2.5 transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                    ADD KEYWORD
                </button>
            </form>
        </div>

        <div class="bg-white dark:bg-surface-900 border border-gray-200 dark:border-white/10 rounded-xl overflow-x-auto hge-shadow">
            <table class="w-full text-sm text-left">
                <thead>
                    <tr class="text-sm font-semibold text-gray-900 dark:text-white border-b border-gray-200 dark:border-white/10">
                        <th class="px-4 py-3">KEYWORD</th>
                        <th class="px-4 py-3">DATE ADDED</th>
                        <th class="px-4 py-3 text-right">ACTION</th>
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
                                <td class="px-4 py-3 text-right">
                                    <button type="button" class="remove-keyword-btn rounded-lg bg-csc-red-600/10 hover:bg-csc-red-600/20 text-csc-red-600 dark:text-csc-red-400 p-1.5" title="Remove keyword"
                                        data-keyword-id="<?= (int) $keyword['id'] ?>"
                                        data-keyword="<?= e($keyword['keyword']) ?>">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" /></svg>
                                    </button>
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
