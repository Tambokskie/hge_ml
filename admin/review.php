<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/uploads.php';
require_once __DIR__ . '/../includes/extraction_review.php';
require_once __DIR__ . '/../includes/graduates.php';

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

// Editing an already-reviewed upload: there is no leftover staged session or
// draft to resume, so seed one from what is actually saved in the database.
// save_staged_records() recognizes the 'id' on each of these rows and
// reconciles the DB against them (update/insert/delete) instead of blindly
// re-inserting everything on the next SAVE RECORDS.
if ($review === null && $upload['extraction_status'] === 'reviewed') {
    start_extraction_review($uploadId, get_graduates_for_upload($pdo, $uploadId));
    $review = get_extraction_review($uploadId);
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
        <div class="mb-6 flex items-start gap-4">
            <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-csc-navy-600/10 text-csc-navy-600 dark:text-csc-navy-300">
                <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125" /></svg>
            </div>
            <div>
                <h1 class="text-2xl font-bold text-csc-navy-600 dark:text-white">REVIEW EXTRACTED RECORDS</h1>
                <p class="text-sm text-gray-600 dark:text-gray-500 mt-1"><?= e_upper(format_school_display_name($upload['school_name'], $upload['campus'])) ?> — <?= e($upload['school_year']) ?></p>
            </div>
        </div>

        <?php if ($upload['extraction_status'] === 'failed'): ?>
            <div class="mb-6 rounded-lg border border-yellow-500/30 bg-yellow-500/10 text-yellow-400 text-sm px-4 py-3">
                TEXT COULD NOT BE RELIABLY EXTRACTED FROM THIS PDF. PLEASE ADD RECORDS MANUALLY BELOW.
            </div>
        <?php endif; ?>

        <?php if ($resumedDraftAt !== null): ?>
            <div class="mb-6 rounded-lg border border-csc-navy-500/30 bg-csc-navy-500/10 text-csc-navy-600 dark:text-csc-navy-300 text-sm px-4 py-3">
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
        <div class="bg-white dark:bg-surface-900 border border-gray-200 dark:border-white/10 rounded-xl p-4 sm:p-6 mb-6 hge-shadow lg:sticky lg:top-[74px] lg:z-10 motion-safe:animate-fade-in-up">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div class="sm:col-span-2">
                    <label class="mb-1.5 block text-xs font-bold tracking-wide text-csc-navy-600 dark:text-csc-navy-300">SEARCH GRADUATE NAME</label>
                    <div class="relative">
                        <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-gray-400 dark:text-gray-500">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" /></svg>
                        </span>
                        <input type="text" id="review-filter-name" placeholder="SEARCH BY NAME..."
                            class="w-full rounded-xl bg-gray-50 dark:bg-surface-800 border border-gray-200 dark:border-white/10 pl-10 pr-3 py-2.5 text-sm uppercase placeholder:text-gray-400 placeholder:normal-case dark:placeholder:text-gray-600 focus:border-csc-navy-500 focus:ring-2 focus:ring-csc-navy-500/20 focus:outline-none">
                    </div>
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-bold tracking-wide text-csc-navy-600 dark:text-csc-navy-300">COURSE</label>
                    <div class="relative">
                        <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-gray-400 dark:text-gray-500">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25" /></svg>
                        </span>
                        <select id="review-filter-course" class="w-full rounded-xl bg-gray-50 dark:bg-surface-800 border border-gray-200 dark:border-white/10 pl-10 pr-3 py-2.5 text-sm focus:border-csc-navy-500 focus:ring-2 focus:ring-csc-navy-500/20 focus:outline-none">
                            <option value="">ALL COURSES</option>
                            <?php foreach ($courseOptions as $courseOption): ?>
                                <option value="<?= e($courseOption) ?>"><?= e_upper($courseOption) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <p id="review-filter-empty" class="hidden text-sm text-gray-500 dark:text-gray-400 text-center pt-4">NO RECORDS MATCH YOUR SEARCH/FILTER.</p>

            <!-- All page-level actions grouped together, uniformly styled, below the filter inputs. -->
            <div class="mt-4 pt-4 border-t border-gray-200 dark:border-white/10 flex flex-wrap items-center gap-3">
                <button type="button" id="add-record-btn"
                    class="rounded-xl border border-csc-navy-500/40 text-csc-navy-600 dark:text-csc-navy-300 hover:bg-csc-navy-500/10 text-sm font-medium px-4 py-2.5 transition-colors">ADD RECORD</button>
                <button type="button" id="mass-update-date-btn"
                    class="rounded-xl border border-csc-navy-500/40 text-csc-navy-600 dark:text-csc-navy-300 hover:bg-csc-navy-500/10 text-sm font-medium px-4 py-2.5 transition-colors">MASS UPDATE DATE</button>
                <button type="button" id="multiple-deletion-btn"
                    class="rounded-xl border border-csc-red-500/40 text-csc-red-600 dark:text-csc-red-400 hover:bg-csc-red-500/10 text-sm font-medium px-4 py-2.5 transition-colors">MULTIPLE DELETION</button>
                <?php if (strtolower((string) pathinfo($upload['stored_filename'], PATHINFO_EXTENSION)) === 'xlsx'): ?>
                    <a href="<?= e(APP_BASE_URL) ?>/api/serve_pdf.php?id=<?= (int) $uploadId ?>" download="<?= e($upload['original_filename']) ?>"
                       class="rounded-xl border border-csc-navy-500/40 text-csc-navy-600 dark:text-csc-navy-300 hover:bg-csc-navy-500/10 text-sm font-medium px-4 py-2.5 transition-colors inline-flex items-center">DOWNLOAD UPLOADED TEMPLATE</a>
                <?php else: ?>
                <button type="button" id="view-pdf-btn"
                        data-pdf-url="<?= e(APP_BASE_URL) ?>/api/serve_pdf.php?id=<?= (int) $uploadId ?>"
                        data-filename="<?= e($upload['original_filename']) ?>"
                        class="rounded-xl border border-csc-navy-500/40 text-csc-navy-600 dark:text-csc-navy-300 hover:bg-csc-navy-500/10 text-sm font-medium px-4 py-2.5 transition-colors">VIEW UPLOADED PDF</button>
                <?php endif; ?>
                <button type="button" id="save-draft-btn"
                    class="rounded-xl border border-orange-500/40 text-orange-600 dark:text-orange-400 hover:bg-orange-500/10 text-sm font-medium px-4 py-2.5 transition-colors">SAVE AS DRAFT</button>
                <button type="button" id="save-records-btn"
                    class="rounded-xl border border-green-500/40 text-green-600 dark:text-green-400 hover:bg-green-500/10 text-sm font-medium px-4 py-2.5 transition-colors">SAVE RECORDS</button>
                <form method="post" class="inline">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="cancel" value="1">
                    <button type="submit"
                        class="rounded-xl border border-csc-navy-500/40 text-csc-navy-600 dark:text-csc-navy-300 hover:bg-csc-navy-500/10 text-sm font-medium px-4 py-2.5 transition-colors">CANCEL</button>
                </form>
            </div>
        </div>

        <div class="bg-white dark:bg-surface-900 border border-gray-200 dark:border-white/10 rounded-xl overflow-x-auto overflow-y-hidden hge-shadow">
            <table class="w-full text-sm text-left">
                <thead>
                    <tr class="text-sm font-semibold text-gray-900 dark:text-white border-b border-gray-200 dark:border-white/10">
                        <th class="px-4 py-3 w-10">
                            <input type="checkbox" id="review-select-all" aria-label="Select all records"
                                class="h-4 w-4 rounded border-gray-300 dark:border-white/20 text-csc-navy-600 focus:ring-csc-navy-500/40">
                        </th>
                        <th class="px-4 py-3">NO.</th>
                        <th class="px-4 py-3">LAST NAME</th>
                        <th class="px-4 py-3">GIVEN NAME</th>
                        <th class="px-4 py-3">MIDDLE NAME</th>
                        <th class="px-4 py-3">DEGREE/COURSE</th>
                        <th class="px-4 py-3">LATIN HONOR</th>
                        <th class="px-4 py-3">DATE OF GRADUATION</th>
                        <th class="px-4 py-3">YEAR</th>
                        <th class="px-4 py-3">STATUS</th>
                        <th class="px-4 py-3 text-right">ACTION</th>
                    </tr>
                </thead>
                <tbody id="review-rows-tbody">
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="11" class="px-4 py-6 text-center text-gray-600 dark:text-gray-500">NO RECORDS YET. CLICK ADD RECORD ABOVE.</td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $index => $row): ?>
                            <?php $status = staged_row_status($row); ?>
                            <?php $nameParts = split_graduate_name($row['graduate_name']); ?>
                            <tr class="border-b border-gray-100 dark:border-white/5 hover:bg-gray-100 dark:hover:bg-white/5 motion-safe:animate-fade-in-up" style="animation-delay: <?= (int) ($index * 50) ?>ms"
                                data-filter-name="<?= e(mb_strtoupper($row['graduate_name'])) ?>"
                                data-filter-course="<?= e($row['course'] ?? '') ?>">
                                <td class="px-4 py-3">
                                    <input type="checkbox" class="review-row-checkbox h-4 w-4 rounded border-gray-300 dark:border-white/20 text-csc-navy-600 focus:ring-csc-navy-500/40"
                                        data-row-index="<?= $index ?>" aria-label="Select record">
                                </td>
                                <td class="px-4 py-3"><?= $index + 1 ?></td>
                                <td class="px-4 py-3"><?= e_upper($nameParts['last_name']) ?></td>
                                <td class="px-4 py-3"><?= e_upper($nameParts['given_name']) ?></td>
                                <td class="px-4 py-3"><?= $nameParts['middle_name'] !== '' ? e_upper($nameParts['middle_name']) : '—' ?></td>
                                <td class="px-4 py-3"><?= ($row['course'] ?? '') !== '' ? e_upper($row['course']) : '—' ?></td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold <?= latin_honor_pill_class($row['latin_honor']) ?>"><?= e($row['latin_honor']) ?></span>
                                    <?php if (($row['source'] ?? null) === 'ocr'): ?>
                                        <span class="ml-1 inline-block rounded px-1.5 py-0.5 text-[10px] font-medium bg-yellow-500/20 text-yellow-400">OCR — VERIFY</span>
                                    <?php elseif (($row['source'] ?? null) === 'ai'): ?>
                                        <span class="ml-1 inline-block rounded px-1.5 py-0.5 text-[10px] font-medium bg-yellow-500/20 text-yellow-400">AI — VERIFY</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3"><?= e(format_date_display($row['graduation_date'])) ?></td>
                                <td class="px-4 py-3"><?= e((string) $row['graduation_year']) ?></td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold <?= $status === 'VALID' ? 'bg-green-500/15 text-green-700 dark:bg-green-500/10 dark:text-green-400' : 'bg-yellow-500/15 text-yellow-700 dark:bg-yellow-500/10 dark:text-yellow-400' ?>"><?= e($status) ?></span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <?php $actionBtnIndex = 0; ?>
                                    <div class="flex items-center justify-end gap-1.5">
                                        <button type="button" class="edit-row-btn rounded-lg bg-csc-navy-600/10 hover:bg-csc-navy-600/20 text-csc-navy-600 dark:text-csc-navy-300 p-1.5 motion-safe:animate-drop-in" style="animation-delay: <?= (int) ($index * 50 + ($actionBtnIndex++) * 70) ?>ms" title="Edit row"
                                            data-row-index="<?= $index ?>"
                                            data-name="<?= e($row['graduate_name']) ?>"
                                            data-last-name="<?= e($nameParts['last_name']) ?>"
                                            data-given-name="<?= e($nameParts['given_name']) ?>"
                                            data-middle-name="<?= e($nameParts['middle_name']) ?>"
                                            data-course="<?= e($row['course'] ?? '') ?>"
                                            data-honor="<?= e($row['latin_honor']) ?>"
                                            data-date="<?= e((string) $row['graduation_date']) ?>"
                                            data-year="<?= e((string) $row['graduation_year']) ?>">
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125" /></svg>
                                        </button>
                                        <button type="button" class="remove-row-btn rounded-lg bg-csc-red-600/10 hover:bg-csc-red-600/20 text-csc-red-600 dark:text-csc-red-400 p-1.5 motion-safe:animate-drop-in" style="animation-delay: <?= (int) ($index * 50 + ($actionBtnIndex++) * 70) ?>ms" title="Remove row"
                                            data-row-index="<?= $index ?>"
                                            data-name="<?= e($row['graduate_name']) ?>">
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" /></svg>
                                        </button>
                                    </div>
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
    viewUploadUrl: <?= json_encode(APP_BASE_URL . '/admin/view_upload.php?id=' . $uploadId) ?>,
    pdfDetails: {
        school: <?= json_encode(mb_strtoupper(format_school_display_name($upload['school_name'], $upload['campus']))) ?>,
        schoolYear: <?= json_encode($upload['school_year']) ?>,
        uploadedAt: <?= json_encode(mb_strtoupper(date('F j, Y', strtotime($upload['uploaded_at'])))) ?>
    }
};
</script>
<script src="<?= e(asset_url('assets/js/review.js')) ?>"></script>
