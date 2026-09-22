<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/uploads.php';
require_once __DIR__ . '/../includes/extraction_review.php';

require_login();

$uploadId = (int) ($_GET['id'] ?? 0);
$draftRecordCount = null;

try {
    $pdo = get_pdo();
    $upload = $uploadId > 0 ? get_upload_by_id($pdo, $uploadId) : null;
    if ($upload !== null && $upload['extraction_status'] === 'draft') {
        $draft = get_extraction_draft($pdo, $uploadId);
        $draftRecordCount = $draft !== null ? count($draft['rows']) : 0;
    }
} catch (\Throwable $e) {
    error_log('Upload lookup failed: ' . $e->getMessage());
    $upload = null;
}

$currentUser = current_user();
$currentPage = 'upload';
$csrfToken = csrf_token();

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main class="lg:ml-64 min-h-screen bg-gray-50 dark:bg-surface-950">
    <div class="p-6 lg:p-8 max-w-2xl">
        <h1 class="text-xl font-semibold text-gray-900 dark:text-white mb-6">UPLOAD DETAILS</h1>

        <?php if ($upload === null): ?>
            <div class="rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-surface-900 p-6 text-center text-gray-500 dark:text-gray-400 hge-shadow">
                <p class="text-sm">NO UPLOAD RECORD FOUND.</p>
            </div>
        <?php else: ?>
            <div class="rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-surface-900 p-6 space-y-4 hge-shadow">
                <div>
                    <p class="text-xs text-gray-600 dark:text-gray-500">SCHOOL</p>
                    <p class="text-sm text-gray-900 dark:text-white"><?= e_upper(format_school_display_name($upload['school_name'], $upload['campus'])) ?></p>
                </div>
                <div>
                    <p class="text-xs text-gray-600 dark:text-gray-500">SCHOOL YEAR</p>
                    <p class="text-sm text-gray-900 dark:text-white"><?= e($upload['school_year']) ?></p>
                </div>
                <div>
                    <p class="text-xs text-gray-600 dark:text-gray-500">FILE NAME</p>
                    <p class="text-sm text-gray-900 dark:text-white"><?= e($upload['original_filename']) ?></p>
                </div>
                <div>
                    <p class="text-xs text-gray-600 dark:text-gray-500">DATE UPLOADED</p>
                    <p class="text-sm text-gray-900 dark:text-white"><?= e_upper(date('F j, Y', strtotime($upload['uploaded_at']))) ?></p>
                </div>
                <div>
                    <p class="text-xs text-gray-600 dark:text-gray-500">RECORDS</p>
                    <p class="text-sm text-gray-900 dark:text-white">
                        <?php if ($upload['extraction_status'] === 'draft'): ?>
                            <?= (int) $draftRecordCount ?> RECORD(S) IN DRAFT
                            <span class="ml-1 inline-block rounded px-1.5 py-0.5 text-[10px] font-medium bg-yellow-500/20 text-yellow-400">NOT AN OFFICIAL RECORD YET</span>
                        <?php else: ?>
                            <?= (int) $upload['records_extracted'] ?> RECORDS — <?= e_upper($upload['extraction_status']) ?>
                        <?php endif; ?>
                    </p>
                    <?php if (user_has_role($currentUser, 'administrator') && $upload['extraction_status'] !== 'reviewed'): ?>
                        <?php if ($upload['extraction_status'] === 'pending'): ?>
                            <button type="button" id="extract-records-btn" data-upload-id="<?= (int) $upload['id'] ?>"
                                class="mt-2 inline-block rounded-lg bg-accent-600 hover:bg-accent-500 text-white text-sm font-medium px-4 py-2 transition-colors">EXTRACT RECORDS</button>
                        <?php else: ?>
                            <a href="<?= e(APP_BASE_URL) ?>/admin/review.php?upload_id=<?= (int) $upload['id'] ?>"
                                class="mt-2 inline-block rounded-lg bg-accent-600 hover:bg-accent-500 text-white text-sm font-medium px-4 py-2 transition-colors"><?= $upload['extraction_status'] === 'draft' ? 'RESUME DRAFT REVIEW' : 'CONTINUE REVIEW' ?></a>
                            <button type="button" id="extract-records-btn" data-upload-id="<?= (int) $upload['id'] ?>" data-records-extracted="<?= (int) $upload['records_extracted'] ?>"
                                class="mt-2 ml-2 inline-block rounded-lg border border-gray-200 dark:border-white/10 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5 text-sm px-4 py-2 transition-colors">RE-EXTRACT RECORDS</button>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <button type="button" id="view-pdf-btn"
                        data-pdf-url="<?= e(APP_BASE_URL) ?>/api/serve_pdf.php?id=<?= (int) $upload['id'] ?>"
                        data-filename="<?= e($upload['original_filename']) ?>"
                        class="inline-flex items-center gap-2 rounded-lg bg-accent-600 hover:bg-accent-500 text-white text-sm font-medium px-4 py-2 transition-colors">VIEW PDF</button>
            </div>

            <?php include __DIR__ . '/../includes/pdf_drawer.php'; ?>
        <?php endif; ?>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
<?php if ($upload !== null): ?>
<script>
window.HGE_VIEW_UPLOAD = {
    csrfToken: <?= json_encode($csrfToken) ?>,
    apiBase: <?= json_encode(APP_BASE_URL) ?>
};
</script>
<script src="<?= e(asset_url('assets/js/view_upload.js')) ?>"></script>
<?php endif; ?>
