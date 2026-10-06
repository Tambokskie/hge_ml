<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/gemini.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/uploads.php';
require_once __DIR__ . '/../includes/extraction_review.php';
require_once __DIR__ . '/../includes/ocr_tools.php';
require_once __DIR__ . '/../includes/ocr_keywords.php';
require_once __DIR__ . '/../includes/app_settings.php';
require_once __DIR__ . '/../includes/extraction_progress.php';
require_once __DIR__ . '/../includes/pdf_pages.php';
require_once __DIR__ . '/../extractors/ExtractorInterface.php';
require_once __DIR__ . '/../extractors/ParsesHonorRows.php';
require_once __DIR__ . '/../extractors/GeminiExtractor.php';
require_once __DIR__ . '/../extractors/generic_extractor.php';
require_once __DIR__ . '/../extractors/ocr_extractor.php';
require_once __DIR__ . '/../extractors/TemplateExtractor.php';

// Chunk size for AI extraction — see
// docs/superpowers/specs/2026-10-06-chunked-ai-extraction-design.md. A
// document with total_pages <= this fits in one chunk and behaves exactly
// as before chunking existed; a longer document needs one
// "Continue Extraction" click per additional chunk.
const AI_EXTRACTION_CHUNK_PAGE_SIZE = 15;

require_role('administrator');

header('Content-Type: application/json');
ini_set('max_execution_time', 120);

if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'YOUR SESSION EXPIRED. PLEASE TRY AGAIN.']);
    exit;
}

$uploadId = (int) ($_POST['upload_id'] ?? 0);
$pdo = get_pdo();

// This request can run for a long time (AI call, OCR). PHP's default session
// handler holds an exclusive lock on the session file for as long as it's
// open, which would otherwise block api/extraction_progress.php's polling
// requests (same browser session) until this request finished — defeating
// the whole point of polling for live progress. Nothing below needs to read
// $_SESSION again; start_extraction_review() re-opens it (via ensure_session())
// only once, right at the end, to stage the extracted rows.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

if ($uploadId > 0) {
    write_extraction_progress($uploadId, 5, 'PREPARING DOCUMENT...');
}

try {
    $upload = $uploadId > 0 ? get_upload_by_id($pdo, $uploadId) : null;

    if ($upload === null) {
        clear_extraction_progress($uploadId);
        echo json_encode(['status' => 'error', 'message' => 'UPLOAD RECORD NOT FOUND.']);
        exit;
    }

    if ($upload['extraction_status'] === 'reviewed') {
        clear_extraction_progress($uploadId);
        echo json_encode(['status' => 'error', 'message' => 'THIS UPLOAD HAS ALREADY BEEN REVIEWED AND SAVED.']);
        exit;
    }

    $isTemplateUpload = strtolower((string) pathinfo($upload['stored_filename'], PATHINFO_EXTENSION)) === 'xlsx';

    try {
        if ($isTemplateUpload) {
            write_extraction_progress($uploadId, 40, 'READING TEMPLATE...');
            $result = (new TemplateExtractor())->extract($upload['file_path']);
            if ($result['status'] === 'failed') {
                clear_extraction_progress($uploadId);
                echo json_encode(['status' => 'error', 'message' => 'THE TEMPLATE COULD NOT BE RE-READ. IT MAY HAVE BEEN EDITED INTO AN INVALID FORMAT.']);
                exit;
            }
        } else {
            $extraCourseKeywords = array_column(get_ocr_course_keywords($pdo), 'keyword');
            $useOcr = !empty($_POST['use_ocr']);

            if ($useOcr) {
                // The admin already confirmed OCR after being told AI
                // couldn't handle this document (see the ocr_confirm_required
                // response below) — run the existing GenericExtractor ->
                // OcrExtractor chain directly, skipping AI entirely so this
                // retry isn't delayed by re-trying a path already known to fail.
                write_extraction_progress($uploadId, 20, 'READING DOCUMENT TEXT...');
                $result = (new GenericExtractor($extraCourseKeywords))->extract($upload['file_path']);
            } else {
                // AI extraction always runs in page chunks (see
                // docs/superpowers/specs/2026-10-06-chunked-ai-extraction-design.md):
                // a document short enough to fit in one chunk is extracted in
                // a single call exactly as before chunking existed, including
                // the full existing text/OCR fallback chain below; a longer
                // document is extracted one chunk per "Continue Extraction"
                // click, with no fallback to text/OCR for an individual
                // chunk's failure.
                $isContinuing = !empty($_POST['continue']);
                if (!$isContinuing) {
                    reset_upload_chunk_progress($pdo, $uploadId);
                    delete_extraction_draft($pdo, $uploadId);
                    $upload['total_pages'] = null;
                    $upload['extracted_through_page'] = 0;
                }

                $totalPages = $upload['total_pages'];
                if ($totalPages === null) {
                    $totalPages = count_pdf_pages($upload['file_path']);
                    if ($totalPages === null) {
                        clear_extraction_progress($uploadId);
                        echo json_encode(['status' => 'error', 'message' => 'THE FILE COULD NOT BE PROCESSED.']);
                        exit;
                    }
                }

                $chunk = next_chunk_range((int) $upload['extracted_through_page'], $totalPages, AI_EXTRACTION_CHUNK_PAGE_SIZE);
                if ($chunk === null) {
                    clear_extraction_progress($uploadId);
                    echo json_encode(['status' => 'error', 'message' => 'ALL PAGES HAVE ALREADY BEEN EXTRACTED FOR THIS UPLOAD.']);
                    exit;
                }

                $isWholeDocumentChunk = $chunk['first'] === 1 && $chunk['last'] === $totalPages;

                if ($isWholeDocumentChunk) {
                    // Fits in one chunk — no need to slice a copy at all,
                    // send the real stored file exactly as before chunking
                    // existed.
                    $chunkFilePath = $upload['file_path'];
                } else {
                    $chunkFilePath = extract_pdf_page_range($upload['file_path'], $chunk['first'], $chunk['last']);
                    if ($chunkFilePath === null) {
                        clear_extraction_progress($uploadId);
                        echo json_encode(['status' => 'error', 'message' => 'THE FILE COULD NOT BE PROCESSED.']);
                        exit;
                    }
                }

                // resolve_gemini_api_keys() prefers a key saved via Settings ->
                // AI Extraction (admin/users.php) over the env-var default.
                $geminiExtractor = new GeminiExtractor(resolve_gemini_api_keys($pdo));
                write_extraction_progress($uploadId, 15, 'SENDING PAGES ' . $chunk['first'] . '-' . $chunk['last'] . ' TO AI...');
                $result = $geminiExtractor->extract($chunkFilePath);

                if ($result['status'] === 'failed' && !GeminiExtractor::isQuotaExhausted() && empty($result['unreachable'])) {
                    // Today's estimated AI usage still has room and Google was
                    // actually reachable, so this wasn't a quota or connectivity
                    // problem — most likely a transient overload or a one-off
                    // unparseable response. Give AI a single extra try before
                    // conceding (bounded, not a loop, so a persistently failing
                    // chunk can't blow past PHP's execution time limit).
                    write_extraction_progress($uploadId, 25, 'RETRYING AI EXTRACTION...');
                    $result = $geminiExtractor->extract($chunkFilePath);
                }

                $aiQuotaExhausted = GeminiExtractor::isQuotaExhausted();
                $aiUnreachable = !empty($result['unreachable']);

                if (!$isWholeDocumentChunk) {
                    // A genuine partial chunk never falls back to text/OCR for
                    // just its own page range — either it succeeded, or the
                    // admin retries this same range later via Continue
                    // Extraction. See the spec's "AI path only" / "mark
                    // failed, retry later" decisions.
                    @unlink($chunkFilePath);

                    if ($result['status'] === 'failed') {
                        clear_extraction_progress($uploadId);
                        $reason = $aiQuotaExhausted
                            ? "TODAY'S FREE AI EXTRACTION QUOTA HAS BEEN USED UP."
                            : ($aiUnreachable
                                ? 'THE AI EXTRACTION SERVICE COULD NOT BE REACHED.'
                                : 'AI EXTRACTION WAS UNABLE TO PROCESS THESE PAGES.');
                        echo json_encode([
                            'status' => 'error',
                            'message' => $reason . ' PAGES ' . $chunk['first'] . '-' . $chunk['last'] . ' WERE NOT EXTRACTED. YOU CAN TRY CONTINUE EXTRACTION AGAIN.',
                        ]);
                        exit;
                    }

                    $fallbackYear = derive_fallback_year($upload['school_year']);
                    $rows = array_map(function (array $row) use ($fallbackYear) {
                        $row['graduation_year'] = $row['graduation_date'] !== null
                            ? (int) date('Y', strtotime($row['graduation_date']))
                            : $fallbackYear;
                        return $row;
                    }, $result['rows']);

                    append_extraction_draft_rows($pdo, $uploadId, (int) current_user()['id'], $rows);
                    update_upload_chunk_progress($pdo, $uploadId, $totalPages, $chunk['last']);
                    clear_extraction_progress($uploadId);

                    $hasMoreChunks = $chunk['last'] < $totalPages;
                    echo json_encode([
                        'status' => 'success',
                        'extraction_status' => 'draft',
                        'redirect_url' => $hasMoreChunks
                            ? APP_BASE_URL . '/admin/view_upload.php?id=' . $uploadId
                            : APP_BASE_URL . '/admin/review.php?upload_id=' . $uploadId,
                    ]);
                    exit;
                }

                // Whole document fit in one chunk: behaves exactly as before
                // chunking existed, including the existing text/OCR fallback
                // chain.
                if ($result['status'] === 'failed') {
                    // GenericExtractor is a fast, free text-layer read, not
                    // OCR — try it before asking the admin anything.
                    write_extraction_progress($uploadId, 40, 'READING DOCUMENT TEXT...');
                    $result = (new GenericExtractor($extraCourseKeywords))->extract($upload['file_path']);
                }

                if ($result['status'] === 'failed') {
                    // AI failed and this PDF has no text layer either — actual
                    // (Tesseract) OCR is the only remaining option, and it's
                    // slower and less accurate than AI/text-layer extraction.
                    // Ask before running it instead of silently falling back.
                    $reason = $aiQuotaExhausted
                        ? "TODAY'S FREE AI EXTRACTION QUOTA HAS BEEN USED UP."
                        : ($aiUnreachable
                            ? 'THE AI EXTRACTION SERVICE COULD NOT BE REACHED.'
                            : 'AI EXTRACTION WAS UNABLE TO PROCESS THIS DOCUMENT.');

                    clear_extraction_progress($uploadId);
                    echo json_encode([
                        'status' => 'ocr_confirm_required',
                        'message' => $reason . ' THIS DOCUMENT HAS NO SELECTABLE TEXT, SO OPTICAL CHARACTER RECOGNITION (OCR) IS NEEDED TO READ IT INSTEAD. '
                            . 'OCR RESULTS CAN BE LESS ACCURATE AND WILL NEED CAREFUL REVIEW. PROCEED WITH OCR EXTRACTION?',
                    ]);
                    exit;
                }
            }

            if ($result['status'] === 'failed') {
                $toolStatus = check_ocr_tools_installed();
                $missingTools = array_keys(array_filter($toolStatus, fn (bool $installed) => !$installed));

                if (!empty($missingTools)) {
                    clear_extraction_progress($uploadId);
                    echo json_encode([
                        'status' => 'ocr_tools_missing',
                        'missing' => array_map('mb_strtoupper', $missingTools),
                        'message' => 'THIS PDF HAS NO EXTRACTABLE TEXT AND REQUIRES OCR TO READ IT. THE FOLLOWING REQUIRED TOOL(S) ARE NOT INSTALLED: '
                            . mb_strtoupper(implode(', ', $missingTools)) . '.',
                    ]);
                    exit;
                }

                write_extraction_progress($uploadId, 50, 'RASTERIZING PDF PAGES...');
                $result = (new OcrExtractor($extraCourseKeywords, function (int $currentPage, int $totalPages) use ($uploadId): void {
                    // OCR's per-page loop is the only genuinely steppable part
                    // of the whole pipeline — spread across the remaining 55-95%
                    // so it's the one stage with a real, not estimated, percentage.
                    $percent = $totalPages > 0 ? 55 + (int) floor(($currentPage / $totalPages) * 40) : 55;
                    write_extraction_progress($uploadId, $percent, 'READING PAGE ' . $currentPage . ' OF ' . $totalPages . ' (OCR)...');
                }))->extract($upload['file_path']);
            }
        }
    } catch (\Throwable $e) {
        error_log('Extraction failed: ' . $e->getMessage());
        clear_extraction_progress($uploadId);
        echo json_encode(['status' => 'error', 'message' => 'THE FILE COULD NOT BE PROCESSED.']);
        exit;
    }

    write_extraction_progress($uploadId, 98, 'FINALIZING...');

    $fallbackYear = derive_fallback_year($upload['school_year']);

    $rows = array_map(function (array $row) use ($fallbackYear) {
        $row['graduation_year'] = $row['graduation_date'] !== null
            ? (int) date('Y', strtotime($row['graduation_date']))
            : $fallbackYear;
        return $row;
    }, $result['rows']);

    start_extraction_review($uploadId, $rows);

    $extractionStatus = $result['status'] === 'ok' ? 'extracted' : 'failed';
    update_upload_extraction_status($pdo, $uploadId, $extractionStatus, 0);

    clear_extraction_progress($uploadId);

    echo json_encode([
        'status' => 'success',
        'extraction_status' => $extractionStatus,
        'redirect_url' => APP_BASE_URL . '/admin/review.php?upload_id=' . $uploadId,
    ]);
} catch (\Throwable $e) {
    error_log('PDF extraction failed: ' . $e->getMessage());
    clear_extraction_progress($uploadId);
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'THE FILE COULD NOT BE PROCESSED. PLEASE TRY AGAIN.']);
    exit;
}
