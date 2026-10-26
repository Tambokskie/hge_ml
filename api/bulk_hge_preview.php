<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/duplicates.php';
require_once __DIR__ . '/../includes/bulk_hge.php';
require_once __DIR__ . '/../includes/bulk_hge_review.php';

require_role('administrator');

header('Content-Type: application/json');

if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'YOUR SESSION EXPIRED. PLEASE TRY AGAIN.']);
    exit;
}

$file = $_FILES['file'] ?? null;
if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
    $tooBig = is_array($file) && in_array($file['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
    echo json_encode(['status' => 'error', 'message' => $tooBig ? 'THE FILE IS TOO LARGE.' : 'PLEASE CHOOSE AN .XLS, .XLSX OR .CSV FILE TO UPLOAD.']);
    exit;
}

// Parsing up to 50,000 rows and matching each against every saved name can take a few seconds.
@set_time_limit(180);

try {
    $read = bulk_hge_read_upload((string) $file['tmp_name'], (string) $file['name'], (int) $file['size']);
    if (!$read['ok']) {
        echo json_encode(['status' => 'error', 'message' => $read['error']]);
        exit;
    }

    $parsed = parse_bulk_hge_rows($read['rows'], (bool) ($read['date1904'] ?? false));
    if (!$parsed['ok']) {
        echo json_encode(['status' => 'error', 'message' => $parsed['error']]);
        exit;
    }
    if ($parsed['people'] === [] && $parsed['errors'] === []) {
        echo json_encode(['status' => 'error', 'message' => 'THE FILE HAS NO DATA ROWS BELOW THE HEADER.']);
        exit;
    }

    $pdo = get_pdo();
    $preview = bulk_hge_build_preview($pdo, $parsed['people'], $parsed['errors']);
    // Rows whose date is in a year before the graduate's graduation year are reported with the
    // unreadable rows (the review file lists them too) and are never filed.
    $parsed['errors'] = array_merge($parsed['errors'], $preview['date_errors']);

    // Uncertain applicants an administrator already filed or dismissed (or whose
    // records are all filed for that date) are never raised again, so they are
    // not "needs review": counting them would offer a review that is not there.
    $handled = 0;
    try {
        $verdicts = bulk_hge_review_classify($pdo, $preview['review']);
        $open = [];
        foreach ($preview['review'] as $i => $item) {
            if ($verdicts[$i] === 'open') {
                $open[] = $item;
            } else {
                $handled++;
            }
        }
        $preview['review'] = $open;
        $preview['counts']['review'] = count($open);
    } catch (\PDOException $e) {
        // Review table not created yet: confirming reports that; the preview still works.
        if (bulk_hge_review_missing_table_message($e) === null) {
            throw $e;
        }
    }
    $preview['counts']['handled'] = $handled;
} catch (\Throwable $e) {
    error_log('bulk_hge_preview failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'THE FILE COULD NOT BE PROCESSED. PLEASE TRY AGAIN.']);
    exit;
}

// A readable label for the activity log and UI only; never used as a path.
$label = preg_replace('/[^A-Za-z0-9 ._()\-]/', '', basename((string) $file['name'])) ?? '';
$label = mb_substr($label, 0, 80);

$token = bin2hex(random_bytes(16));
$_SESSION['bulk_hge_preview'] = [
    'token' => $token,
    'created' => time(),
    'filename' => $label,
    'pairs' => $preview['to_file'],
    'review' => $preview['review'],
    'invalid' => $parsed['errors'],
];

// Uncertain applicants are saved for review when the admin confirms (see
// bulk_hge_apply.php); unreadable rows can only be fixed in the file, so they
// are listed here, capped so the response stays small (the review file has all).
$listLimit = 300;
$invalidList = array_map(static fn (array $item): array => [
    'row' => $item['row'],
    'name' => (string) $item['name'],
    'message' => (string) $item['message'],
], array_slice($parsed['errors'], 0, $listLimit));

echo json_encode([
    'status' => 'success',
    'token' => $token,
    'filename' => $label,
    'rows_read' => $parsed['rows_read'],
    'duplicate_rows' => $parsed['duplicate_rows'],
    'counts' => $preview['counts'],
    'invalid' => $invalidList,
], JSON_INVALID_UTF8_SUBSTITUTE);
