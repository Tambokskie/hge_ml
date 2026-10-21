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
$isAdmin = user_has_role($currentUser, 'administrator');

if ($upload !== null) {
    $status = $upload['extraction_status'];
    $statusPill = upload_status_pill($status);

    $bannerMessage = [
        'pending' => 'YOUR FILE HAS BEEN UPLOADED AND IS READY FOR EXTRACTION.',
        'extracted' => 'YOUR FILE HAS BEEN EXTRACTED AND IS READY FOR REVIEW. YOU CAN CONTINUE THE REVIEW OR DOWNLOAD A COPY FOR YOUR RECORDS.',
        'draft' => 'YOU HAVE A SAVED DRAFT FOR THIS UPLOAD. RESUME THE REVIEW WHEN YOU\'RE READY TO FINISH IT.',
        'reviewed' => 'THIS UPLOAD HAS BEEN REVIEWED AND ITS RECORDS ARE SAVED TO THE DATABASE.',
        'failed' => 'TEXT COULD NOT BE RELIABLY EXTRACTED FROM THIS PDF. YOU CAN ADD RECORDS MANUALLY IN THE REVIEW SCREEN.',
    ][$status] ?? 'YOUR FILE HAS BEEN SUCCESSFULLY UPLOADED.';

    $canManageExtraction = $isAdmin && $status !== 'reviewed';
    $canRemove = $isAdmin && (int) $upload['records_extracted'] === 0;

    // Moving the upload (and its saved records) to another campus of the same school.
    $otherCampuses = [];
    $campusRecordCount = 0;
    if ($isAdmin) {
        $otherCampuses = get_other_campuses_for_upload($pdo, (int) $upload['id']);
        $campusCountStmt = $pdo->prepare('SELECT COUNT(*) FROM latin_honor_graduates WHERE upload_id = :id');
        $campusCountStmt->execute(['id' => (int) $upload['id']]);
        $campusRecordCount = (int) $campusCountStmt->fetchColumn();
    }
    $fileExtension = mb_strtoupper((string) pathinfo($upload['stored_filename'], PATHINFO_EXTENSION));
    $isTemplateUpload = $fileExtension === 'XLSX';
    $needsMoreExtraction = upload_needs_more_extraction($upload);

    // An upload whose records are already saved can be re-extracted (for example when an
    // earlier OCR reading was unclear). Records with a filed application ("tagged") are
    // carried along untouched; see merge_reextracted_rows(). The counts feed the confirmation.
    $canReextract = $isAdmin && $status === 'reviewed';
    $savedCount = 0;
    $taggedCount = 0;
    if ($canReextract) {
        $countStmt = $pdo->prepare(
            'SELECT COUNT(*) AS saved, COALESCE(SUM(a.id IS NOT NULL), 0) AS tagged
             FROM latin_honor_graduates g
             LEFT JOIN graduate_applications a ON a.graduate_id = g.id
             WHERE g.upload_id = :upload_id'
        );
        $countStmt->execute(['upload_id' => (int) $upload['id']]);
        $counts = $countStmt->fetch();
        $savedCount = (int) $counts['saved'];
        $taggedCount = (int) $counts['tagged'];
    }
}

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main class="lg:ml-64 min-h-screen bg-gray-50 dark:bg-surface-950">
    <div class="p-6 lg:p-8">
        <div class="max-w-6xl mx-auto">
            <div class="mb-6 flex items-start gap-4">
                <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-csc-navy-600/10 text-csc-navy-600 dark:text-csc-navy-300">
                    <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" /></svg>
                </div>
                <div>
                    <h1 class="text-2xl font-bold text-csc-navy-600 dark:text-white">UPLOAD DETAILS</h1>
                    <p class="text-sm text-gray-600 dark:text-gray-500 mt-1">VIEW AND MANAGE YOUR UPLOADED DOCUMENT.</p>
                </div>
            </div>

        <?php if ($upload === null): ?>
            <div class="rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-surface-900 p-6 text-center text-gray-500 dark:text-gray-400 hge-shadow">
                <p class="text-sm">NO UPLOAD RECORD FOUND.</p>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
                <div class="lg:col-span-2 bg-white dark:bg-surface-900 border border-gray-200 dark:border-white/10 rounded-xl p-6 hge-shadow motion-safe:animate-fade-in-up" style="animation-delay: 0ms">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex items-start gap-3 min-w-0">
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-csc-red-600/10 text-csc-red-600 dark:text-csc-red-400">
                                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" /></svg>
                            </span>
                            <div class="min-w-0">
                                <p class="text-base font-bold text-gray-900 dark:text-white break-words"><?= e($upload['original_filename']) ?></p>
                                <p class="flex items-center gap-1.5 text-xs text-green-600 dark:text-green-400 mt-1">
                                    DOCUMENT UPLOADED SUCCESSFULLY
                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                                </p>
                            </div>
                        </div>
                        <span class="shrink-0 inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold <?= $statusPill['class'] ?>"><?= e($statusPill['label']) ?></span>
                    </div>

                    <div class="my-5 border-t border-gray-100 dark:border-white/10"></div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-4">
                        <div class="flex items-start gap-2.5">
                            <svg class="h-5 w-5 shrink-0 text-gray-400 dark:text-gray-500 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" /></svg>
                            <div class="min-w-0">
                                <p class="text-[11px] font-bold tracking-wide text-gray-500 dark:text-gray-500">SCHOOL</p>
                                <p class="text-sm text-gray-900 dark:text-white truncate"><?= e_upper(format_school_display_name($upload['school_name'], $upload['campus'])) ?></p>
                            </div>
                        </div>
                        <div class="flex items-start gap-2.5">
                            <svg class="h-5 w-5 shrink-0 text-gray-400 dark:text-gray-500 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" /></svg>
                            <div class="min-w-0">
                                <p class="text-[11px] font-bold tracking-wide text-gray-500 dark:text-gray-500">STORED FILE NAME</p>
                                <p class="text-sm text-gray-900 dark:text-white truncate"><?= e($upload['stored_filename']) ?></p>
                            </div>
                        </div>
                        <div class="flex items-start gap-2.5">
                            <svg class="h-5 w-5 shrink-0 text-gray-400 dark:text-gray-500 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" /></svg>
                            <div class="min-w-0">
                                <p class="text-[11px] font-bold tracking-wide text-gray-500 dark:text-gray-500">SCHOOL YEAR</p>
                                <p class="text-sm text-gray-900 dark:text-white"><?= e($upload['school_year']) ?></p>
                            </div>
                        </div>
                        <div class="flex items-start gap-2.5">
                            <svg class="h-5 w-5 shrink-0 text-gray-400 dark:text-gray-500 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" /></svg>
                            <div class="min-w-0">
                                <p class="text-[11px] font-bold tracking-wide text-gray-500 dark:text-gray-500">FILE FORMAT</p>
                                <p class="text-sm text-gray-900 dark:text-white"><?= e($fileExtension) ?></p>
                            </div>
                        </div>
                        <div class="flex items-start gap-2.5">
                            <svg class="h-5 w-5 shrink-0 text-gray-400 dark:text-gray-500 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" /></svg>
                            <div class="min-w-0">
                                <p class="text-[11px] font-bold tracking-wide text-gray-500 dark:text-gray-500">UPLOADED BY</p>
                                <p class="text-sm text-gray-900 dark:text-white truncate"><?= $upload['uploaded_by_name'] !== null ? e_upper($upload['uploaded_by_name']) : 'SYSTEM' ?></p>
                            </div>
                        </div>
                        <div class="flex items-start gap-2.5">
                            <svg class="h-5 w-5 shrink-0 text-gray-400 dark:text-gray-500 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375" /></svg>
                            <div class="min-w-0">
                                <p class="text-[11px] font-bold tracking-wide text-gray-500 dark:text-gray-500">RECORDS</p>
                                <p class="text-sm text-gray-900 dark:text-white">
                                    <?php if ($status === 'draft'): ?>
                                        <?= (int) $draftRecordCount ?> IN DRAFT
                                        <?php if ($needsMoreExtraction): ?>
                                            <span class="block text-xs text-gray-500 dark:text-gray-500 mt-0.5">PAGES <?= (int) $upload['extracted_through_page'] ?> OF <?= (int) $upload['total_pages'] ?> EXTRACTED</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?= (int) $upload['records_extracted'] ?> EXTRACTED
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                        <div class="flex items-start gap-2.5">
                            <svg class="h-5 w-5 shrink-0 text-gray-400 dark:text-gray-500 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" /></svg>
                            <div class="min-w-0">
                                <p class="text-[11px] font-bold tracking-wide text-gray-500 dark:text-gray-500">DATE UPLOADED</p>
                                <p class="text-sm text-gray-900 dark:text-white"><?= e_upper(date('F j, Y', strtotime($upload['uploaded_at']))) ?></p>
                            </div>
                        </div>
                        <div class="flex items-start gap-2.5 hidden" id="extraction-error-row">
                            <svg class="h-5 w-5 shrink-0 text-csc-red-600 dark:text-csc-red-400 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>
                            <div class="min-w-0">
                                <p class="text-[11px] font-bold tracking-wide text-csc-red-600 dark:text-csc-red-400">LAST EXTRACTION ERROR</p>
                                <p class="text-sm text-csc-red-600 dark:text-csc-red-400" id="extraction-error-message"></p>
                            </div>
                        </div>
                    </div>

                    <div class="my-5 border-t border-gray-100 dark:border-white/10"></div>

                    <div class="flex flex-wrap items-center gap-3">
    <?php if ($canReextract): ?>
        <?php $reextractData = 'data-upload-id="' . (int) $upload['id'] . '" data-should-confirm="true" data-reextract="1" data-saved-count="' . $savedCount . '" data-tagged-count="' . $taggedCount . '"'; ?>
        <button type="button" id="extract-records-btn" <?= $reextractData ?>
            class="inline-flex items-center gap-1.5 rounded-xl bg-csc-navy-600 hover:bg-csc-navy-500 text-white text-sm font-semibold px-4 py-2.5 transition-colors">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>
            <?= $isTemplateUpload ? 'RE-READ TEMPLATE' : 'RE-EXTRACT WITH AI' ?>
        </button>
        <?php if (!$isTemplateUpload): ?>
            <button type="button" id="extract-ocr-btn" <?= $reextractData ?>
                class="inline-flex items-center gap-1.5 rounded-xl border border-csc-navy-500/40 text-csc-navy-600 dark:text-csc-navy-300 hover:bg-csc-navy-500/10 text-sm font-semibold px-4 py-2.5 transition-colors">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" /></svg>
                RE-EXTRACT VIA TEXT/OCR
            </button>
        <?php endif; ?>
        <?php if ($taggedCount > 0): ?>
            <p class="basis-full text-xs text-gray-500 dark:text-gray-500"><?= (int) $taggedCount ?> RECORD(S) WITH A FILED APPLICATION ARE KEPT EXACTLY AS THEY ARE WHEN YOU RE-EXTRACT.</p>
        <?php endif; ?>
    <?php endif; ?>
                        <?php if ($canManageExtraction): ?>
                            <?php if ($status === 'pending'): ?>
                                <button type="button" id="extract-records-btn" data-upload-id="<?= (int) $upload['id'] ?>"
                                    class="inline-flex items-center gap-1.5 rounded-xl bg-csc-navy-600 hover:bg-csc-navy-500 text-white text-sm font-semibold px-4 py-2.5 transition-colors">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>
                                    EXTRACT RECORDS
                                </button>
                                <button type="button" id="extract-ocr-btn" data-upload-id="<?= (int) $upload['id'] ?>"
                                    class="inline-flex items-center gap-1.5 rounded-xl border border-csc-navy-500/40 text-csc-navy-600 dark:text-csc-navy-300 hover:bg-csc-navy-500/10 text-sm font-semibold px-4 py-2.5 transition-colors">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" /></svg>
                                    EXTRACT VIA TEXT/OCR
                                </button>
                            <?php elseif ($needsMoreExtraction): ?>
                                <button type="button" id="continue-extraction-btn" data-upload-id="<?= (int) $upload['id'] ?>"
                                    class="inline-flex items-center gap-1.5 rounded-xl bg-csc-navy-600 hover:bg-csc-navy-500 text-white text-sm font-semibold px-4 py-2.5 transition-colors">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>
                                    CONTINUE EXTRACTION
                                </button>
                                <button type="button" id="extract-records-btn" data-upload-id="<?= (int) $upload['id'] ?>" data-should-confirm="true"
                                    class="inline-flex items-center gap-1.5 rounded-xl border border-csc-navy-500/40 text-csc-navy-600 dark:text-csc-navy-300 hover:bg-csc-navy-500/10 text-sm font-semibold px-4 py-2.5 transition-colors">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>
                                    USE AI EXTRACTION
                                </button>
                                <button type="button" id="extract-ocr-btn" data-upload-id="<?= (int) $upload['id'] ?>" data-should-confirm="true"
                                    class="inline-flex items-center gap-1.5 rounded-xl border border-csc-navy-500/40 text-csc-navy-600 dark:text-csc-navy-300 hover:bg-csc-navy-500/10 text-sm font-semibold px-4 py-2.5 transition-colors">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" /></svg>
                                    EXTRACT VIA TEXT/OCR
                                </button>
                            <?php else: ?>
                                <a href="<?= e(APP_BASE_URL) ?>/admin/review.php?upload_id=<?= (int) $upload['id'] ?>"
                                    class="inline-flex items-center gap-1.5 rounded-xl bg-csc-navy-600 hover:bg-csc-navy-500 text-white text-sm font-semibold px-4 py-2.5 transition-colors">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>
                                    <?= $status === 'draft' ? 'RESUME DRAFT REVIEW' : 'CONTINUE REVIEW' ?>
                                </a>
                                <button type="button" id="extract-records-btn" data-upload-id="<?= (int) $upload['id'] ?>" data-should-confirm="true"
                                    class="inline-flex items-center gap-1.5 rounded-xl border border-csc-navy-500/40 text-csc-navy-600 dark:text-csc-navy-300 hover:bg-csc-navy-500/10 text-sm font-semibold px-4 py-2.5 transition-colors">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>
                                    USE AI EXTRACTION
                                </button>
                                <button type="button" id="extract-ocr-btn" data-upload-id="<?= (int) $upload['id'] ?>" data-should-confirm="true"
                                    class="inline-flex items-center gap-1.5 rounded-xl border border-csc-navy-500/40 text-csc-navy-600 dark:text-csc-navy-300 hover:bg-csc-navy-500/10 text-sm font-semibold px-4 py-2.5 transition-colors">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" /></svg>
                                    EXTRACT VIA TEXT/OCR
                                </button>
                            <?php endif; ?>
                        <?php endif; ?>
                        <a href="<?= e(APP_BASE_URL) ?>/admin/upload.php"
                            class="inline-flex items-center gap-1.5 rounded-xl border border-gray-300 dark:border-white/15 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5 text-sm font-semibold px-4 py-2.5 transition-colors">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" /></svg>
                            BACK TO UPLOAD
                        </a>
                    </div>
                </div>

                <div class="space-y-6">
                    <div class="rounded-xl border border-csc-navy-500/20 bg-csc-navy-600/5 dark:bg-csc-navy-600/10 p-5 motion-safe:animate-fade-in-up" style="animation-delay: 100ms">
                        <div class="flex items-start gap-3">
                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-csc-navy-600 text-white">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" /></svg>
                            </span>
                            <div>
                                <p class="text-sm font-bold text-csc-navy-600 dark:text-csc-navy-300">DOCUMENT UPLOADED!</p>
                                <p class="text-xs text-gray-600 dark:text-gray-400 mt-1"><?= $bannerMessage ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-surface-900 border border-gray-200 dark:border-white/10 rounded-xl p-5 hge-shadow motion-safe:animate-fade-in-up" style="animation-delay: 200ms">
                        <div class="flex items-center gap-2 mb-3">
                            <svg class="h-5 w-5 text-csc-navy-600 dark:text-csc-navy-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456z" /></svg>
                            <h2 class="text-sm font-bold text-csc-navy-600 dark:text-white">QUICK ACTIONS</h2>
                        </div>
                        <div class="divide-y divide-gray-100 dark:divide-white/5">
                            <?php if (!$isTemplateUpload): ?>
                            <button type="button" class="view-pdf-btn w-full flex items-center gap-3 py-3 first:pt-0 group text-left"
                                    data-pdf-url="<?= e(APP_BASE_URL) ?>/api/serve_pdf.php?id=<?= (int) $upload['id'] ?>"
                                    data-filename="<?= e($upload['original_filename']) ?>">
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-csc-navy-600/10 text-csc-navy-600 dark:text-csc-navy-300">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" /></svg>
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-semibold text-gray-900 dark:text-white">VIEW DOCUMENT</span>
                                    <span class="block text-xs text-gray-500 dark:text-gray-500">OPEN THE UPLOADED FILE IN A NEW TAB.</span>
                                </span>
                                <svg class="h-4 w-4 shrink-0 text-gray-300 dark:text-gray-700 group-hover:text-csc-navy-500 transition-colors" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                            </button>
                            <?php endif; ?>

                            <a href="<?= e(APP_BASE_URL) ?>/api/serve_pdf.php?id=<?= (int) $upload['id'] ?>" download="<?= e($upload['original_filename']) ?>" class="flex items-center gap-3 py-3 <?= $isTemplateUpload ? 'first:pt-0 ' : '' ?>group">
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-csc-navy-600/10 text-csc-navy-600 dark:text-csc-navy-300">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-semibold text-gray-900 dark:text-white">DOWNLOAD <?= $isTemplateUpload ? 'FILE' : 'PDF' ?></span>
                                    <span class="block text-xs text-gray-500 dark:text-gray-500">SAVE A COPY TO YOUR DEVICE.</span>
                                </span>
                                <svg class="h-4 w-4 shrink-0 text-gray-300 dark:text-gray-700 group-hover:text-csc-navy-500 transition-colors" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                            </a>

                            <?php if ($isAdmin && $status === 'reviewed'): ?>
                                <a href="<?= e(APP_BASE_URL) ?>/admin/review.php?upload_id=<?= (int) $upload['id'] ?>" class="flex items-center gap-3 py-3 last:pb-0 group">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-csc-navy-600/10 text-csc-navy-600 dark:text-csc-navy-300">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125" /></svg>
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block text-sm font-semibold text-gray-900 dark:text-white">EDIT RECORDS</span>
                                        <span class="block text-xs text-gray-500 dark:text-gray-500">ADD, EDIT, OR REMOVE SAVED RECORDS.</span>
                                    </span>
                                    <svg class="h-4 w-4 shrink-0 text-gray-300 dark:text-gray-700 group-hover:text-csc-navy-500 transition-colors" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                                </a>
                            <?php endif; ?>

                            <?php if (($canManageExtraction && $status !== 'pending') || $canReextract): ?>
                                <button type="button" id="quick-reextract-btn" data-upload-id="<?= (int) $upload['id'] ?>" data-should-confirm="true"
                                        class="w-full flex items-center gap-3 py-3 group text-left">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-csc-navy-600/10 text-csc-navy-600 dark:text-csc-navy-300">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block text-sm font-semibold text-gray-900 dark:text-white"><?= $canReextract ? 'RE-EXTRACT RECORDS' : 'USE AI EXTRACTION' ?></span>
                                        <span class="block text-xs text-gray-500 dark:text-gray-500"><?= $canReextract ? 'READ THE FILE AGAIN. RECORDS WITH A FILED APPLICATION ARE KEPT.' : 'UPDATE EXTRACTED DATA FROM THE FILE.' ?></span>
                                    </span>
                                    <svg class="h-4 w-4 shrink-0 text-gray-300 dark:text-gray-700 group-hover:text-csc-navy-500 transition-colors" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                                </button>
                            <?php endif; ?>

                            <?php if ($isAdmin): ?>
                                <button type="button" id="change-campus-btn"
                                        data-upload-id="<?= (int) $upload['id'] ?>"
                                        data-current="<?= e(format_school_display_name($upload['school_name'], $upload['campus'])) ?>"
                                        data-campuses="<?= e(json_encode($otherCampuses)) ?>"
                                        data-record-count="<?= $campusRecordCount ?>"
                                        class="w-full flex items-center gap-3 py-3 group text-left">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-csc-navy-600/10 text-csc-navy-600 dark:text-csc-navy-300">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21L3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5" /></svg>
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block text-sm font-semibold text-gray-900 dark:text-white">CHANGE CAMPUS</span>
                                        <span class="block text-xs text-gray-500 dark:text-gray-500">MOVE THIS UPLOAD AND ITS RECORDS TO ANOTHER CAMPUS OF THE SAME SCHOOL.</span>
                                    </span>
                                    <svg class="h-4 w-4 shrink-0 text-gray-300 dark:text-gray-700 group-hover:text-csc-navy-500 transition-colors" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                                </button>
                            <?php endif; ?>
                            <?php if ($canRemove): ?>
                                <button type="button" id="remove-upload-btn"
                                        data-upload-id="<?= (int) $upload['id'] ?>"
                                        data-label="<?= e(format_school_display_name($upload['school_name'], $upload['campus']) . ' (' . $upload['school_year'] . ')') ?>"
                                        class="w-full flex items-center gap-3 py-3 last:pb-0 group text-left">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-csc-red-600/10 text-csc-red-600 dark:text-csc-red-400">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" /></svg>
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block text-sm font-semibold text-csc-red-600 dark:text-csc-red-400">REMOVE FILE</span>
                                        <span class="block text-xs text-gray-500 dark:text-gray-500">DELETE THIS UPLOAD (MOVES TO THE RECYCLE BIN).</span>
                                    </span>
                                    <svg class="h-4 w-4 shrink-0 text-gray-300 dark:text-gray-700 group-hover:text-csc-red-500 transition-colors" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="rounded-xl border border-gray-200 dark:border-white/10 bg-gray-50 dark:bg-white/5 p-5 motion-safe:animate-fade-in-up" style="animation-delay: 300ms">
                        <div class="flex items-start gap-3">
                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-csc-navy-600/10 text-csc-navy-600 dark:text-csc-navy-300">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 18v-5.25m0 0a6.01 6.01 0 001.5-.189m-1.5.189a6.01 6.01 0 01-1.5-.189m3.75 7.478a12.06 12.06 0 01-4.5 0m3.75 2.383a14.406 14.406 0 01-3 0M14.25 18v-.192c0-.983.658-1.823 1.508-2.316a7.5 7.5 0 10-7.517 0c.85.493 1.509 1.333 1.509 2.316V18" /></svg>
                            </span>
                            <div>
                                <p class="text-sm font-bold text-gray-900 dark:text-white">NEED HELP?</p>
                                <p class="text-xs text-gray-500 dark:text-gray-500 mt-1">IF YOU HAVE ANY ISSUES WITH THIS FILE, PLEASE CONTACT YOUR SYSTEM ADMINISTRATOR.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!$isTemplateUpload): ?>
                <?php include __DIR__ . '/../includes/pdf_drawer.php'; ?>
            <?php endif; ?>
        <?php endif; ?>
        </div>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
<?php if ($upload !== null): ?>
<script>
window.HGE_VIEW_UPLOAD = {
    csrfToken: <?= json_encode($csrfToken) ?>,
    apiBase: <?= json_encode(APP_BASE_URL) ?>,
    schoolsUrl: <?= json_encode(APP_BASE_URL . '/admin/schools.php') ?>,
    dashboardUrl: <?= json_encode(APP_BASE_URL . '/admin/dashboard.php') ?>,
    pdfDetails: {
        school: <?= json_encode(mb_strtoupper(format_school_display_name($upload['school_name'], $upload['campus']))) ?>,
        schoolYear: <?= json_encode($upload['school_year']) ?>,
        uploadedAt: <?= json_encode(mb_strtoupper(date('F j, Y', strtotime($upload['uploaded_at'])))) ?>
    }
};
</script>
<script src="<?= e(asset_url('assets/js/view_upload.js')) ?>"></script>
<?php endif; ?>
