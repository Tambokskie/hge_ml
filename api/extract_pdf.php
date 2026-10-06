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
// docs/superpowers/specs/2026-10-06-chunked-ai-extraction-design.md. Every
// document is sent to AI in chunks of this many pages — no exemption for a
// document that would fit in a single call, and no fallback to text/OCR
// extraction when a chunk fails. A document that completes in one chunk
// still finishes in a single click; a longer one needs one
// "Continue Extraction" click per additional chunk.
const AI_EXTRACTION_CHUNK_PAGE_SIZE = 3;

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
                // AI extraction always runs in page chunks, with no
                // exemption for a document that would fit in a single call
                // and no fallback to text/OCR extraction for a failed chunk
                // — the admin retries via "Continue Extraction" (or by
                // re-starting a fresh attempt for a first-chunk failure).
                // See docs/superpowers/specs/2026-10-06-chunked-ai-extraction-design.md
                // for the original design; this removes that design's
                // whole-document-chunk shortcut per an explicit later
                // decision to apply chunking uniformly, with no fallback
                // path, to every document regardless of size.
                $isContinuing = !empty($_POST['continue']);
                if (!$isContinuing) {
                    // Progress bookkeeping is safe to reset immediately — it
                    // carries no user data. The existing draft and session
                    // are NOT touched here: deleting them before this fresh
                    // attempt has even succeeded once would destroy a prior,
                    // still-valid draft if this attempt then fails (AI
                    // failure, a Ghostscript slice failure, or a PHP
                    // execution-time fatal are all real possibilities). They
                    // are only cleared once this attempt's first chunk
                    // actually succeeds, further down.
                    reset_upload_chunk_progress($pdo, $uploadId);
                    $upload['total_pages'] = null;
                    $upload['extracted_through_page'] = 0;
                }

                $totalPages = $upload['total_pages'];
                if ($totalPages === null) {
                    $totalPages = count_pdf_pages($upload['file_path']);
                    if ($totalPages === null) {
                        // Every document is chunked now, with no
                        // whole-document fallback, so Ghostscript is a hard
                        // requirement for any AI extraction — there is no
                        // "send the whole file instead" substitute when page
                        // counting fails.
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

                $chunkFirst = $chunk['first'];
                $chunkLast = $chunk['last'];
                $chunkFilePath = extract_pdf_page_range($upload['file_path'], $chunkFirst, $chunkLast);
                if ($chunkFilePath === null) {
                    clear_extraction_progress($uploadId);
                    echo json_encode(['status' => 'error', 'message' => 'THE FILE COULD NOT BE PROCESSED.']);
                    exit;
                }

                // resolve_gemini_api_keys() prefers a key saved via Settings ->
                // AI Extraction (admin/users.php) over the env-var default.
                $geminiExtractor = new GeminiExtractor(resolve_gemini_api_keys($pdo));
                write_extraction_progress($uploadId, 15, 'SENDING PAGES ' . $chunkFirst . '-' . $chunkLast . ' TO AI...');
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
                        'message' => $reason . ' PAGES ' . $chunkFirst . '-' . $chunkLast . ' WERE NOT EXTRACTED. PLEASE TRY AGAIN.',
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

                if (!$isContinuing) {
                    // First successful chunk of a fresh attempt — only
                    // now is it safe to discard whatever existed before.
                    delete_extraction_draft($pdo, $uploadId);
                    clear_extraction_review();
                    $appendResult = save_extraction_draft($pdo, $uploadId, (int) current_user()['id'], $rows);
                } else {
                    $appendResult = append_extraction_draft_rows($pdo, $uploadId, (int) current_user()['id'], $rows);
                }

                if ($appendResult['status'] === 'error') {
                    // The pages were extracted successfully, but the draft
                    // save itself failed — do NOT advance
                    // extracted_through_page, or "Continue Extraction"
                    // would skip these pages forever and silently lose
                    // their rows.
                    clear_extraction_progress($uploadId);
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'PAGES ' . $chunkFirst . '-' . $chunkLast . ' WERE EXTRACTED BUT COULD NOT BE SAVED. YOU CAN TRY CONTINUE EXTRACTION AGAIN.',
                    ]);
                    exit;
                }

                update_upload_chunk_progress($pdo, $uploadId, $totalPages, $chunkLast);
                clear_extraction_progress($uploadId);

                $hasMoreChunks = $chunkLast < $totalPages;
                echo json_encode([
                    'status' => 'success',
                    'extraction_status' => 'draft',
                    'redirect_url' => $hasMoreChunks
                        ? APP_BASE_URL . '/admin/view_upload.php?id=' . $uploadId
                        : APP_BASE_URL . '/admin/review.php?upload_id=' . $uploadId,
                ]);
                exit;
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

    if (!empty($useOcr) && $result['status'] === 'ok') {
        // A direct text/OCR extraction succeeded — if this upload had any
        // AI-chunking progress or draft in flight, it's now superseded by
        // this whole-document result. Only reset once we know this attempt
        // actually produced something, so a failed text/OCR attempt never
        // destroys a still-valid AI draft (mirrors the "defer until success"
        // principle the AI branch above already uses). Without this, stale
        // total_pages/extracted_through_page from an earlier AI attempt
        // would make upload_needs_more_extraction() keep blocking review.php
        // even though this fresh, complete text/OCR result is ready.
        reset_upload_chunk_progress($pdo, $uploadId);
        delete_extraction_draft($pdo, $uploadId);
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
