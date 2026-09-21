<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/uploads.php';
require_once __DIR__ . '/../includes/extraction_review.php';
require_once __DIR__ . '/../includes/ocr_tools.php';
require_once __DIR__ . '/../extractors/ExtractorInterface.php';
require_once __DIR__ . '/../extractors/ParsesHonorRows.php';
require_once __DIR__ . '/../extractors/generic_extractor.php';
require_once __DIR__ . '/../extractors/ocr_extractor.php';

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

    try {
        $result = (new GenericExtractor())->extract($upload['file_path']);
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

            $result = (new OcrExtractor())->extract($upload['file_path']);
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
