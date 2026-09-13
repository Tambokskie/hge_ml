<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/schools.php';

require_role('administrator');

$pdo = get_pdo();
$schoolNames = get_active_school_names($pdo);
$currentUser = current_user();
$currentPage = 'upload';
$csrfToken = csrf_token();

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main class="lg:ml-64 min-h-screen bg-surface-950">
    <div class="p-6 lg:p-8 max-w-2xl">
        <h1 class="text-xl font-semibold text-white mb-6">UPLOAD LATIN HONOR LIST</h1>

        <form id="upload-form" action="<?= e(APP_BASE_URL) ?>/api/upload_pdf.php" data-view-upload-url="<?= e(APP_BASE_URL) ?>/admin/view_upload.php" method="post" enctype="multipart/form-data" class="space-y-5">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

            <div>
                <label class="block text-xs text-gray-400 mb-1" for="school_name">SCHOOL NAME</label>
                <input list="school-names" id="school_name" name="school_name" required
                       class="w-full rounded-lg bg-surface-800 border border-white/10 px-3 py-2 text-sm focus:outline-none focus:border-accent-500">
                <datalist id="school-names">
                    <?php foreach ($schoolNames as $name): ?>
                        <option value="<?= e($name) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>

            <div>
                <label class="block text-xs text-gray-400 mb-1" for="school_year">SCHOOL YEAR</label>
                <input type="text" id="school_year" name="school_year" required placeholder="e.g. 2025"
                       class="w-full rounded-lg bg-surface-800 border border-white/10 px-3 py-2 text-sm focus:outline-none focus:border-accent-500">
            </div>

            <div>
                <label class="block text-xs text-gray-400 mb-1">PDF FILE</label>
                <div id="dropzone" class="cursor-pointer rounded-xl border-2 border-dashed border-white/15 px-6 py-10 text-center text-sm text-gray-400 hover:border-accent-500 transition-colors">
                    <p>DRAG &amp; DROP PDF HERE</p>
                    <p class="text-xs mt-1">OR CLICK TO BROWSE</p>
                    <p class="text-xs text-gray-600 mt-2">PDF ONLY, UP TO 20 MB</p>
                </div>
                <input type="file" id="pdf_file" name="pdf_file" accept="application/pdf" required class="hidden">
                <p id="file-info" class="text-xs text-gray-400 mt-2"></p>
            </div>

            <button type="submit" class="w-full rounded-lg bg-accent-600 hover:bg-accent-500 text-white text-sm font-medium py-2 transition-colors">UPLOAD</button>
        </form>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
<script src="<?= e(APP_BASE_URL) ?>/assets/js/upload.js"></script>
