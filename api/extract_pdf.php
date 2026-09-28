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
require_once __DIR__ . '/../extractors/ExtractorInterface.php';
require_once __DIR__ . '/../extractors/ParsesHonorRows.php';
require_once __DIR__ . '/../extractors/GeminiExtractor.php';
require_once __DIR__ . '/../extractors/generic_extractor.php';
require_once __DIR__ . '/../extractors/ocr_extractor.php';
require_once __DIR__ . '/../extractors/TemplateExtractor.php';

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

try {
    $upload = $uploadId > 0 ? get_upload_by_id($pdo, $uploadId) : null;

    if ($upload === null) {
        echo json_encode(['status' => 'error', 'message' => 'UPLOAD RECORD NOT FOUND.']);
        exit;
    }

    if ($upload['extraction_status'] === 'reviewed') {
        echo json_encode(['status' => 'error', 'message' => 'THIS UPLOAD HAS ALREADY BEEN REVIEWED AND SAVED.']);
        exit;
    }

    $isTemplateUpload = strtolower((string) pathinfo($upload['stored_filename'], PATHINFO_EXTENSION)) === 'xlsx';

    try {
        if ($isTemplateUpload) {
            $result = (new TemplateExtractor())->extract($upload['file_path']);
            if ($result['status'] === 'failed') {
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
                $result = (new GenericExtractor($extraCourseKeywords))->extract($upload['file_path']);
            } else {
                // AI extraction is the primary path: it reads the PDF directly
                // and understands layout (wrapped cells, borderless columns,
                // narrative letters) without a bespoke handler per layout.
                // resolve_gemini_api_keys() prefers a key saved via Settings ->
                // AI Extraction (admin/users.php) over the env-var default.
                $geminiExtractor = new GeminiExtractor(resolve_gemini_api_keys($pdo));
                $result = $geminiExtractor->extract($upload['file_path']);

                if ($result['status'] === 'failed' && !GeminiExtractor::isQuotaExhausted() && empty($result['unreachable'])) {
                    // Today's estimated AI usage still has room and Google was
                    // actually reachable, so this wasn't a quota or connectivity
                    // problem — most likely a transient overload or a one-off
                    // unparseable response. Give AI a single extra try before
                    // conceding to the OCR fallback (bounded, not a loop, so a
                    // persistently failing document can't blow past PHP's
                    // execution time limit).
                    $result = $geminiExtractor->extract($upload['file_path']);
                }

                $aiQuotaExhausted = GeminiExtractor::isQuotaExhausted();
                $aiUnreachable = !empty($result['unreachable']);

                if ($result['status'] === 'failed') {
                    // GenericExtractor is a fast, free text-layer read, not
                    // OCR — try it before asking the admin anything.
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
                    echo json_encode([
                        'status' => 'ocr_tools_missing',
                        'missing' => array_map('mb_strtoupper', $missingTools),
                        'message' => 'THIS PDF HAS NO EXTRACTABLE TEXT AND REQUIRES OCR TO READ IT. THE FOLLOWING REQUIRED TOOL(S) ARE NOT INSTALLED: '
                            . mb_strtoupper(implode(', ', $missingTools)) . '.',
                    ]);
                    exit;
                }

                $result = (new OcrExtractor($extraCourseKeywords))->extract($upload['file_path']);
            }
        }
    } catch (\Throwable $e) {
        error_log('Extraction failed: ' . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'THE FILE COULD NOT BE PROCESSED.']);
        exit;
    }

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

    echo json_encode([
        'status' => 'success',
        'extraction_status' => $extractionStatus,
        'redirect_url' => APP_BASE_URL . '/admin/review.php?upload_id=' . $uploadId,
    ]);
} catch (\Throwable $e) {
    error_log('PDF extraction failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'THE FILE COULD NOT BE PROCESSED. PLEASE TRY AGAIN.']);
    exit;
}
