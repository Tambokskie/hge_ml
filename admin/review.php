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
$resumedDraftAt = null;

if ($review === null) {
    $draft = get_extraction_draft($pdo, $uploadId);
    if ($draft !== null) {
        start_extraction_review($uploadId, $draft['rows']);
        $review = get_extraction_review($uploadId);
        $resumedDraftAt = $draft['updated_at'];
    }
}

if ($review === null) {
    flash_set('error', 'NO ACTIVE REVIEW FOR THIS UPLOAD. PLEASE EXTRACT RECORDS AGAIN.');
    redirect(APP_BASE_URL . '/admin/view_upload.php?id=' . $uploadId);
}

$rows = $review['rows'];
$courseOptions = array_values(array_unique(array_filter(array_map(
    static fn (array $row): string => trim((string) ($row['course'] ?? '')),
    $rows
))));
sort($courseOptions);

$currentUser = current_user();
$currentPage = 'upload';
$csrfToken = csrf_token();

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main class="lg:ml-64 min-h-screen bg-gray-50 dark:bg-surface-950">
    <div class="p-6 lg:p-8">
        <h1 class="text-xl font-semibold text-gray-900 dark:text-white mb-2">REVIEW EXTRACTED RECORDS</h1>
        <p class="text-sm text-gray-600 dark:text-gray-500 mb-6"><?= e_upper(format_school_display_name($upload['school_name'], $upload['campus'])) ?> — <?= e($upload['school_year']) ?></p>

        <?php if ($upload['extraction_status'] === 'failed'): ?>
            <div class="mb-6 rounded-lg border border-yellow-500/30 bg-yellow-500/10 text-yellow-400 text-sm px-4 py-3">
                TEXT COULD NOT BE RELIABLY EXTRACTED FROM THIS PDF. PLEASE ADD RECORDS MANUALLY BELOW.
            </div>
        <?php endif; ?>

        <?php if ($resumedDraftAt !== null): ?>
            <div class="mb-6 rounded-lg border border-accent-500/30 bg-accent-500/10 text-accent-600 dark:text-accent-300 text-sm px-4 py-3">
                RESUMED FROM YOUR SAVED DRAFT (LAST SAVED <?= e_upper(date('F j, Y g:i A', strtotime($resumedDraftAt))) ?>). THIS DRAFT IS NOT PART OF THE OFFICIAL RECORDS UNTIL YOU CLICK SAVE RECORDS.
            </div>
        <?php endif; ?>

        <!-- Sticks below the app header (which is sticky top-0, ~58px tall)
             once the page scrolls, so search/filter/mass-update stay reachable
             without scrolling back up. The extra 16px in the top offset keeps
             a visible gap between the header bar and the card while stuck,
             instead of the card butting right up against it. Only on lg+: on
             small screens the stacked card is too tall relative to the
             viewport for sticky to be usable, so it scrolls normally there
             instead. -->
        <div class="bg-white dark:bg-surface-900 border border-gray-200 dark:border-white/10 rounded-xl p-4 sm:p-6 mb-6 hge-shadow lg:sticky lg:top-[74px] lg:z-10">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div class="sm:col-span-2">
                    <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">SEARCH GRADUATE NAME</label>
                    <input type="text" id="review-filter-name" placeholder="SEARCH BY NAME..."
                        class="w-full rounded-lg bg-gray-100 dark:bg-surface-800 border border-gray-200 dark:border-white/10 px-3 py-2 text-sm uppercase placeholder:text-gray-400 dark:placeholder:text-gray-600">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">COURSE</label>
                    <select id="review-filter-course" class="w-full rounded-lg bg-gray-100 dark:bg-surface-800 border border-gray-200 dark:border-white/10 px-3 py-2 text-sm">
                        <option value="">ALL COURSES</option>
                        <?php foreach ($courseOptions as $courseOption): ?>
                            <option value="<?= e($courseOption) ?>"><?= e_upper($courseOption) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <p id="review-filter-empty" class="hidden text-sm text-gray-500 dark:text-gray-400 text-center pt-4">NO RECORDS MATCH YOUR SEARCH/FILTER.</p>

            <!-- All page-level actions grouped together, uniformly styled, below the filter inputs. -->
            <div class="mt-4 pt-4 border-t border-gray-200 dark:border-white/10 flex flex-wrap items-center gap-3">
                <button type="button" id="add-record-btn"
                    class="rounded-lg border border-accent-500/40 text-accent-600 dark:text-accent-300 hover:bg-accent-500/10 text-sm font-medium px-4 py-2 transition-colors">ADD RECORD</button>
                <button type="button" id="mass-update-date-btn"
                    class="rounded-lg border border-accent-500/40 text-accent-600 dark:text-accent-300 hover:bg-accent-500/10 text-sm font-medium px-4 py-2 transition-colors">MASS UPDATE DATE</button>
                <button type="button" id="view-pdf-btn"
                        data-pdf-url="<?= e(APP_BASE_URL) ?>/api/serve_pdf.php?id=<?= (int) $uploadId ?>"
                        data-filename="<?= e($upload['original_filename']) ?>"
                        class="rounded-lg border border-accent-500/40 text-accent-600 dark:text-accent-300 hover:bg-accent-500/10 text-sm font-medium px-4 py-2 transition-colors">VIEW UPLOADED PDF</button>
                <button type="button" id="save-draft-btn"
                    class="rounded-lg border border-orange-500/40 text-orange-600 dark:text-orange-400 hover:bg-orange-500/10 text-sm font-medium px-4 py-2 transition-colors">SAVE AS DRAFT</button>
                <button type="button" id="save-records-btn"
                    class="rounded-lg border border-green-500/40 text-green-600 dark:text-green-400 hover:bg-green-500/10 text-sm font-medium px-4 py-2 transition-colors">SAVE RECORDS</button>
                <form method="post" class="inline">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="cancel" value="1">
                    <button type="submit"
                        class="rounded-lg border border-accent-500/40 text-accent-600 dark:text-accent-300 hover:bg-accent-500/10 text-sm font-medium px-4 py-2 transition-colors">CANCEL</button>
                </form>
            </div>
        </div>

        <div class="bg-white dark:bg-surface-900 border border-gray-200 dark:border-white/10 rounded-xl overflow-x-auto hge-shadow">
            <table class="w-full text-sm text-left">
                <thead>
                    <tr class="text-xs text-gray-600 dark:text-gray-500 border-b border-gray-200 dark:border-white/10">
                        <th class="px-4 py-3">NO.</th>
                        <th class="px-4 py-3">GRADUATE NAME</th>
                        <th class="px-4 py-3">DEGREE/COURSE</th>
                        <th class="px-4 py-3">LATIN HONOR</th>
                        <th class="px-4 py-3">DATE OF GRADUATION</th>
                        <th class="px-4 py-3">YEAR</th>
                        <th class="px-4 py-3">STATUS</th>
                        <th class="px-4 py-3">ACTION</th>
                    </tr>
                </thead>
                <tbody id="review-rows-tbody">
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="8" class="px-4 py-6 text-center text-gray-600 dark:text-gray-500">NO RECORDS YET. CLICK ADD RECORD ABOVE.</td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $index => $row): ?>
                            <?php $status = staged_row_status($row); ?>
                            <tr class="border-b border-gray-100 dark:border-white/5 hover:bg-gray-100 dark:hover:bg-white/5"
                                data-filter-name="<?= e(mb_strtoupper($row['graduate_name'])) ?>"
                                data-filter-course="<?= e($row['course'] ?? '') ?>">
                                <td class="px-4 py-3"><?= $index + 1 ?></td>
                                <td class="px-4 py-3"><?= e_upper($row['graduate_name']) ?></td>
                                <td class="px-4 py-3"><?= ($row['course'] ?? '') !== '' ? e_upper($row['course']) : '—' ?></td>
                                <td class="px-4 py-3">
                                    <?= e($row['latin_honor']) ?>
                                    <?php if (($row['source'] ?? null) === 'ocr'): ?>
                                        <span class="ml-2 inline-block rounded px-1.5 py-0.5 text-[10px] font-medium bg-yellow-500/20 text-yellow-400">OCR — VERIFY</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3"><?= e(format_date_display($row['graduation_date'])) ?></td>
                                <td class="px-4 py-3"><?= e((string) $row['graduation_year']) ?></td>
                                <td class="px-4 py-3">
                                    <span class="<?= $status === 'VALID' ? 'text-green-400' : 'text-yellow-400' ?>"><?= e($status) ?></span>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <button type="button" class="edit-row-btn text-accent-400 hover:underline mr-3"
                                        data-row-index="<?= $index ?>"
                                        data-name="<?= e($row['graduate_name']) ?>"
                                        data-course="<?= e($row['course'] ?? '') ?>"
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

        <p class="text-sm text-gray-500 dark:text-gray-400 mt-4">TOTAL RECORDS: <?= count($rows) ?></p>
    </div>
    <?php include __DIR__ . '/../includes/pdf_drawer.php'; ?>
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
<script src="<?= e(asset_url('assets/js/review.js')) ?>"></script>
