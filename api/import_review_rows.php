<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/uploads.php';
require_once __DIR__ . '/../includes/extraction_review.php';
require_once __DIR__ . '/../includes/duplicates.php';
require_once __DIR__ . '/../includes/bulk_hge.php';
require_once __DIR__ . '/../includes/review_import.php';

require_role('administrator');

header('Content-Type: application/json');

function import_error(string $message, int $status = 200): void
{
    http_response_code($status);
    echo json_encode(['status' => 'error', 'message' => $message]);
    exit;
}

if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    import_error('YOUR SESSION EXPIRED. PLEASE TRY AGAIN.', 400);
}

$uploadId = (int) ($_POST['upload_id'] ?? 0);
$file = $_FILES['file'] ?? null;

if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
    import_error('PLEASE CHOOSE AN EXCEL FILE TO UPLOAD.');
}

try {
    $pdo = get_pdo();
    $upload = $uploadId > 0 ? get_upload_by_id($pdo, $uploadId) : null;
    if ($upload === null) {
        import_error('UPLOAD RECORD NOT FOUND.');
    }
    if (get_extraction_review($uploadId) === null) {
        import_error('NO ACTIVE REVIEW FOR THIS UPLOAD.');
    }

    $sheet = bulk_hge_read_upload((string) $file['tmp_name'], (string) $file['name'], (int) $file['size']);
    if (!$sheet['ok']) {
        import_error($sheet['error']);
    }

    $parsed = parse_review_import_rows($sheet['rows'], derive_fallback_year((string) $upload['school_year']));
    if (!$parsed['ok']) {
        import_error($parsed['error']);
    }

    if ($parsed['rows'] === []) {
        echo json_encode([
            'status' => 'error',
            'message' => 'NO ROWS COULD BE ADDED.',
            'skipped' => array_slice($parsed['skipped'], 0, 50),
            'skipped_count' => count($parsed['skipped']),
        ]);
        exit;
    }

    $result = append_staged_rows($uploadId, $parsed['rows']);
    if (!$result['ok']) {
        import_error($result['error']);
    }

    $currentUser = current_user();
    log_activity(
        $pdo,
        $currentUser['id'] ?? null,
        'record_created',
        $result['added'] . ' RECORD(S) ADDED FROM EXCEL FILE ' . mb_substr((string) $file['name'], 0, 100) . ' FOR upload_id=' . $uploadId . ' (STAGED, PENDING REVIEW, MANUAL)'
    );

    echo json_encode([
        'status' => 'success',
        'message' => $result['added'] . ' RECORD(S) ADDED FROM THE FILE.',
        'added' => $result['added'],
        'undated' => $parsed['undated'],
        'skipped' => array_slice($parsed['skipped'], 0, 50),
        'skipped_count' => count($parsed['skipped']),
    ], JSON_INVALID_UTF8_SUBSTITUTE);
} catch (\Throwable $e) {
    error_log('Import review rows failed: ' . $e->getMessage());
    import_error('THE FILE COULD NOT BE PROCESSED. PLEASE TRY AGAIN.', 500);
}
