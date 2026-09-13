<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/uploads.php';
require_once __DIR__ . '/../includes/extraction_review.php';

require_role('administrator');

$uploadId = (int) ($_GET['upload_id'] ?? 0);
$pdo = get_pdo();
$upload = $uploadId > 0 ? get_upload_by_id($pdo, $uploadId) : null;

if ($upload === null) {
    flash_set('error', 'UPLOAD RECORD NOT FOUND.');
    redirect(APP_BASE_URL . '/admin/upload.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['cancel'])) {
    if (csrf_verify($_POST['csrf_token'] ?? null)) {
        clear_extraction_review();
        flash_set('info', 'REVIEW CANCELLED. NO RECORDS WERE SAVED.');
    }
    redirect(APP_BASE_URL . '/admin/view_upload.php?id=' . $uploadId);
}

$review = get_extraction_review($uploadId);

if ($review === null) {
    flash_set('error', 'NO ACTIVE REVIEW FOR THIS UPLOAD. PLEASE EXTRACT RECORDS AGAIN.');
    redirect(APP_BASE_URL . '/admin/view_upload.php?id=' . $uploadId);
}

$rows = $review['rows'];
$currentUser = current_user();
$currentPage = 'upload';
$csrfToken = csrf_token();

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main class="lg:ml-64 min-h-screen bg-surface-950">
    <div class="p-6 lg:p-8">
        <h1 class="text-xl font-semibold text-white mb-2">REVIEW EXTRACTED RECORDS</h1>
        <p class="text-sm text-gray-500 mb-6"><?= e_upper($upload['school_name']) ?> — <?= e($upload['school_year']) ?></p>

        <?php if ($upload['extraction_status'] === 'failed'): ?>
            <div class="mb-6 rounded-lg border border-yellow-500/30 bg-yellow-500/10 text-yellow-400 text-sm px-4 py-3">
                TEXT COULD NOT BE RELIABLY EXTRACTED FROM THIS PDF. PLEASE ADD RECORDS MANUALLY BELOW.
            </div>
        <?php endif; ?>

        <div class="bg-surface-900 border border-white/10 rounded-xl overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead>
                    <tr class="text-xs text-gray-500 border-b border-white/10">
                        <th class="px-4 py-3">NO.</th>
                        <th class="px-4 py-3">GRADUATE NAME</th>
                        <th class="px-4 py-3">LATIN HONOR</th>
                        <th class="px-4 py-3">DATE OF GRADUATION</th>
                        <th class="px-4 py-3">YEAR</th>
                        <th class="px-4 py-3">STATUS</th>
                        <th class="px-4 py-3">ACTION</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="7" class="px-4 py-6 text-center text-gray-500">NO RECORDS YET. CLICK ADD RECORD BELOW.</td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $index => $row): ?>
                            <?php $status = staged_row_status($row); ?>
                            <tr class="border-b border-white/5 hover:bg-white/5">
                                <td class="px-4 py-3"><?= $index + 1 ?></td>
                                <td class="px-4 py-3"><?= e_upper($row['graduate_name']) ?></td>
                                <td class="px-4 py-3"><?= e($row['latin_honor']) ?></td>
                                <td class="px-4 py-3"><?= e(format_date_display($row['graduation_date'])) ?></td>
                                <td class="px-4 py-3"><?= e((string) $row['graduation_year']) ?></td>
                                <td class="px-4 py-3">
                                    <span class="<?= $status === 'VALID' ? 'text-green-400' : 'text-yellow-400' ?>"><?= e($status) ?></span>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <button type="button" class="edit-row-btn text-accent-400 hover:underline mr-3"
                                        data-row-index="<?= $index ?>"
                                        data-name="<?= e($row['graduate_name']) ?>"
                                        data-honor="<?= e($row['latin_honor']) ?>"
                                        data-date="<?= e((string) $row['graduation_date']) ?>"
                                        data-year="<?= e((string) $row['graduation_year']) ?>">EDIT</button>
                                    <button type="button" class="remove-row-btn text-red-400 hover:underline"
                                        data-row-index="<?= $index ?>"
                                        data-name="<?= e($row['graduate_name']) ?>">REMOVE</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="flex items-center justify-between mt-4">
            <p class="text-sm text-gray-400">TOTAL RECORDS: <?= count($rows) ?></p>
            <button type="button" id="add-record-btn" class="rounded-lg border border-white/10 text-gray-300 hover:bg-white/5 text-sm px-4 py-2">ADD RECORD</button>
        </div>

        <div class="flex items-center gap-3 mt-6">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="cancel" value="1">
                <button type="submit" class="rounded-lg border border-white/10 text-gray-300 hover:bg-white/5 text-sm px-4 py-2">CANCEL</button>
            </form>
            <button type="button" id="save-records-btn" class="rounded-lg bg-accent-600 hover:bg-accent-500 text-white text-sm font-medium px-4 py-2">SAVE RECORDS</button>
        </div>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
<script>
window.HGE_REVIEW = {
    uploadId: <?= (int) $uploadId ?>,
    csrfToken: <?= json_encode($csrfToken) ?>,
    apiBase: <?= json_encode(APP_BASE_URL) ?>,
    viewUploadUrl: <?= json_encode(APP_BASE_URL . '/admin/view_upload.php?id=' . $uploadId) ?>
};
</script>
<script src="<?= e(APP_BASE_URL) ?>/assets/js/review.js"></script>
