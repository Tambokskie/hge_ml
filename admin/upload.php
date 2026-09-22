<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/schools.php';

require_role('administrator');

$pdo = get_pdo();
$schoolNames = get_active_school_names($pdo);
$uploadedSchools = get_schools_with_uploads($pdo);
$currentUser = current_user();
$currentPage = 'upload';
$csrfToken = csrf_token();

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main class="lg:ml-64 min-h-screen bg-gray-50 dark:bg-surface-950">
    <div class="p-6 lg:p-8">
        <div class="max-w-5xl mx-auto grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
            <div class="lg:col-span-2 bg-white dark:bg-surface-900 border border-gray-200 dark:border-white/10 rounded-xl p-6 sm:p-8 hge-shadow motion-safe:animate-fade-in-up" style="animation-delay: 0ms">
                <h1 class="text-xl font-semibold text-accent-700 dark:text-accent-400 mb-6 text-center">UPLOAD LATIN HONOR LIST</h1>

                <form id="upload-form" action="<?= e(APP_BASE_URL) ?>/api/upload_pdf.php" data-view-upload-url="<?= e(APP_BASE_URL) ?>/admin/view_upload.php" data-extract-url="<?= e(APP_BASE_URL) ?>/api/extract_pdf.php" method="post" enctype="multipart/form-data" class="space-y-5">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

                    <div>
                        <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1" for="school_name">SCHOOL NAME</label>
                        <div class="flex items-center gap-2">
                            <select id="school_name" name="school_name" required
                                   class="flex-1 min-w-0 rounded-lg bg-gray-100 dark:bg-surface-800 border border-gray-200 dark:border-white/10 px-3 py-2 text-sm focus:outline-none focus:border-accent-500">
                                <option value="" disabled selected>SELECT A SCHOOL</option>
                                <?php foreach ($schoolNames as $name): ?>
                                    <option value="<?= e($name) ?>"><?= e_upper($name) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <a href="<?= e(APP_BASE_URL) ?>/admin/schools.php" class="shrink-0 inline-flex items-center gap-2 rounded-lg border border-gray-200 dark:border-white/10 bg-white dark:bg-surface-900 px-3 py-2 text-xs font-semibold text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-white/5 transition-colors">SCHOOL MANAGEMENT</a>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1" for="school_year">SCHOOL YEAR</label>
                        <input type="text" id="school_year" name="school_year" required placeholder="e.g. 2025"
                               inputmode="numeric" pattern="[0-9]*" maxlength="4" autocomplete="off"
                               class="w-full rounded-lg bg-gray-100 dark:bg-surface-800 border border-gray-200 dark:border-white/10 px-3 py-2 text-sm focus:outline-none focus:border-accent-500">
                        <p class="text-xs italic text-gray-400 dark:text-gray-600 mt-1">Only numbers are accepted in the SCHOOL YEAR field</p>
                    </div>

                    <div>
                        <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">PDF FILE</label>
                        <div id="dropzone" class="cursor-pointer rounded-xl border-2 border-dashed border-gray-300 dark:border-white/15 px-6 py-10 text-center text-sm text-gray-500 dark:text-gray-400 hover:border-accent-500 transition-colors">
                            <p>DRAG &amp; DROP PDF HERE</p>
                            <p class="text-xs mt-1">OR CLICK TO BROWSE</p>
                            <p class="text-xs text-gray-400 dark:text-gray-600 mt-2">PDF ONLY, UP TO 20 MB</p>
                        </div>
                        <input type="file" id="pdf_file" name="pdf_file" accept="application/pdf" required class="hidden">
                        <p id="file-info" class="text-xs text-gray-500 dark:text-gray-400 mt-2"></p>
                    </div>

                    <button type="submit" id="upload-submit-btn" class="w-full rounded-lg bg-accent-600 hover:bg-accent-500 text-white text-sm font-medium py-2 transition-colors disabled:opacity-50 disabled:cursor-not-allowed">UPLOAD</button>
                </form>

                <div id="upload-progress" class="hidden mt-4 rounded-lg border border-accent-500/30 bg-accent-500/10 px-4 py-3">
                    <div class="flex items-center justify-between gap-3 mb-2">
                        <span id="upload-progress-label" class="text-sm text-accent-600 dark:text-accent-300"></span>
                        <span id="upload-progress-percent" class="hidden shrink-0 text-xs font-semibold tabular-nums text-accent-600 dark:text-accent-300"></span>
                    </div>
                    <div class="relative h-2 w-full overflow-hidden rounded-full bg-accent-500/20">
                        <div id="upload-progress-bar" class="absolute inset-y-0 left-0 h-full rounded-full bg-accent-600 transition-[width] duration-300 ease-out" style="width: 0%"></div>
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-surface-900 border border-gray-200 dark:border-white/10 rounded-xl p-6 hge-shadow motion-safe:animate-fade-in-up" style="animation-delay: 200ms">
                <h2 class="text-sm font-semibold text-accent-700 dark:text-accent-400 mb-4 text-center">SCHOOLS UPLOADED</h2>
                <?php if (empty($uploadedSchools)): ?>
                    <p class="text-sm text-gray-600 dark:text-gray-500 text-center py-6">NO SCHOOLS UPLOADED YET.</p>
                <?php else: ?>
                    <ul class="divide-y divide-gray-100 dark:divide-white/5 max-h-[809px] overflow-y-auto">
                        <?php foreach ($uploadedSchools as $school): ?>
                            <li class="py-3 first:pt-0 last:pb-0">
                                <p class="text-sm font-medium text-gray-900 dark:text-white"><?= e_upper($school['school_name']) ?></p>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5"><?= $school['campus'] !== '' ? e_upper($school['campus']) : '—' ?></p>
                                <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5"><?= $school['address'] !== '' ? e_upper($school['address']) : '—' ?></p>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
<script src="<?= e(asset_url('assets/js/upload.js')) ?>"></script>
