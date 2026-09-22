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
                    <p class="text-sm text-gray-900 dark:text-white"><?= e_upper($upload['school_name']) ?></p>
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
                            <button type="button" id="extract-records-btn" data-upload-id="<?= (int) $upload['id'] ?>"
                                class="mt-2 ml-2 inline-block rounded-lg border border-gray-200 dark:border-white/10 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5 text-sm px-4 py-2 transition-colors">RE-EXTRACT RECORDS</button>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <button type="button" id="view-pdf-btn"
                        data-pdf-url="<?= e(APP_BASE_URL) ?>/api/serve_pdf.php?id=<?= (int) $upload['id'] ?>"
                        data-filename="<?= e($upload['original_filename']) ?>"
                        class="inline-flex items-center gap-2 rounded-lg bg-accent-600 hover:bg-accent-500 text-white text-sm font-medium px-4 py-2 transition-colors">VIEW PDF</button>
            </div>

            <div id="pdf-drawer-overlay" class="hidden fixed inset-0 z-40 bg-black/60 opacity-0 transition-opacity duration-300 ease-out"></div>
            <aside id="pdf-drawer" role="dialog" aria-modal="true" aria-label="PDF preview"
                   class="fixed inset-y-0 right-0 z-40 flex w-full max-w-full translate-x-full flex-col border-l border-gray-200 bg-white shadow-2xl transition-transform duration-300 ease-out sm:w-[92vw] lg:w-[960px] dark:border-white/10 dark:bg-surface-900">
                <div class="flex items-center justify-between gap-3 border-b border-gray-200 dark:border-white/10 px-4 py-3">
                    <p id="pdf-drawer-filename" class="min-w-0 truncate text-sm font-semibold text-gray-900 dark:text-white">PDF PREVIEW</p>
                    <div class="flex shrink-0 items-center gap-2">
                        <a id="pdf-drawer-new-tab" href="#" target="_blank" rel="noopener"
                           class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 dark:border-white/10 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5 text-xs font-semibold px-3 py-1.5 transition-colors">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" /></svg>
                            OPEN IN NEW TAB
                        </a>
                        <button type="button" id="pdf-drawer-close" aria-label="Close"
                                class="rounded-lg p-1.5 text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-white/5 transition-colors">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M6 18L18 6M6 6l12 12" /></svg>
                        </button>
                    </div>
                </div>
                <div class="relative min-h-0 flex-1 bg-gray-100 dark:bg-surface-950">
                    <div id="pdf-drawer-loading" class="hidden absolute inset-0 flex flex-col items-center justify-center gap-3 bg-gray-100 dark:bg-surface-950">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 animate-spin text-accent-600 dark:text-accent-400" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                        </svg>
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">LOADING PDF...</p>
                    </div>
                    <iframe id="pdf-drawer-frame" src="" title="PDF preview" class="h-full w-full border-0"></iframe>
                </div>
            </aside>
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
