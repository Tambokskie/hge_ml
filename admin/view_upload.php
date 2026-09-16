<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/uploads.php';

require_login();

$uploadId = (int) ($_GET['id'] ?? 0);

try {
    $upload = $uploadId > 0 ? get_upload_by_id(get_pdo(), $uploadId) : null;
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
<main class="lg:ml-64 min-h-screen bg-surface-950">
    <div class="p-6 lg:p-8 max-w-2xl">
        <h1 class="text-xl font-semibold text-white mb-6">UPLOAD DETAILS</h1>

        <?php if ($upload === null): ?>
            <div class="rounded-xl border border-white/10 bg-surface-900 p-6 text-center text-gray-400">
                <p class="text-sm">NO UPLOAD RECORD FOUND.</p>
            </div>
        <?php else: ?>
            <div class="rounded-xl border border-white/10 bg-surface-900 p-6 space-y-4">
                <div>
                    <p class="text-xs text-gray-500">SCHOOL</p>
                    <p class="text-sm text-white"><?= e_upper($upload['school_name']) ?></p>
                </div>
                <div>
                    <p class="text-xs text-gray-500">SCHOOL YEAR</p>
                    <p class="text-sm text-white"><?= e($upload['school_year']) ?></p>
                </div>
                <div>
                    <p class="text-xs text-gray-500">FILE NAME</p>
                    <p class="text-sm text-white"><?= e($upload['original_filename']) ?></p>
                </div>
                <div>
                    <p class="text-xs text-gray-500">DATE UPLOADED</p>
                    <p class="text-sm text-white"><?= e_upper(date('F j, Y', strtotime($upload['uploaded_at']))) ?></p>
                </div>
                <div>
                    <p class="text-xs text-gray-500">RECORDS</p>
                    <p class="text-sm text-white"><?= (int) $upload['records_extracted'] ?> RECORDS — <?= e_upper($upload['extraction_status']) ?></p>
                    <?php if (user_has_role($currentUser, 'administrator') && $upload['extraction_status'] !== 'reviewed'): ?>
                        <?php if ($upload['extraction_status'] === 'pending'): ?>
                            <button type="button" id="extract-records-btn" data-upload-id="<?= (int) $upload['id'] ?>"
                                class="mt-2 inline-block rounded-lg bg-accent-600 hover:bg-accent-500 text-white text-sm font-medium px-4 py-2 transition-colors">EXTRACT RECORDS</button>
                        <?php else: ?>
                            <a href="<?= e(APP_BASE_URL) ?>/admin/review.php?upload_id=<?= (int) $upload['id'] ?>"
                                class="mt-2 inline-block rounded-lg bg-accent-600 hover:bg-accent-500 text-white text-sm font-medium px-4 py-2 transition-colors">CONTINUE REVIEW</a>
                            <button type="button" id="extract-records-btn" data-upload-id="<?= (int) $upload['id'] ?>"
                                class="mt-2 ml-2 inline-block rounded-lg border border-white/10 text-gray-300 hover:bg-white/5 text-sm px-4 py-2 transition-colors">RE-EXTRACT RECORDS</button>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <a href="<?= e(APP_BASE_URL) ?>/api/serve_pdf.php?id=<?= (int) $upload['id'] ?>" target="_blank"
                   class="inline-block rounded-lg bg-accent-600 hover:bg-accent-500 text-white text-sm font-medium px-4 py-2 transition-colors">VIEW PDF</a>
            </div>
        <?php endif; ?>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
<?php if (user_has_role($currentUser, 'administrator') && $upload !== null && $upload['extraction_status'] !== 'reviewed'): ?>
<script>
window.HGE_VIEW_UPLOAD = {
    csrfToken: <?= json_encode($csrfToken) ?>,
    apiBase: <?= json_encode(APP_BASE_URL) ?>
};
</script>
<script src="<?= e(asset_url('assets/js/view_upload.js')) ?>"></script>
<?php endif; ?>
