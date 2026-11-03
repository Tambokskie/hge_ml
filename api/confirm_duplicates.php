<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/uploads.php';
require_once __DIR__ . '/../includes/extraction_review.php';
require_once __DIR__ . '/../includes/duplicates.php';

require_role('administrator');

header('Content-Type: application/json');

if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'YOUR SESSION EXPIRED. PLEASE TRY AGAIN.']);
    exit;
}

// CONFIRM DUPLICATES (SAVE RECORDS prompt): a person has reviewed the chosen review rows and confirms they
// are duplicates of records already saved. Each row is removed from the review and what was found about it
// is stored against the saved original (record_duplicate_findings()), which then shows DUPLICATE FOUND.
$uploadId = (int) ($_POST['upload_id'] ?? 0);
$rowIndexes = array_map('intval', array_filter(
    array_map('trim', explode(',', (string) ($_POST['row_indexes'] ?? ''))),
    static fn (string $value): bool => $value !== ''
));

if (empty($rowIndexes)) {
    echo json_encode(['status' => 'error', 'message' => 'NO RECORDS SELECTED.']);
    exit;
}

$reason = trim((string) ($_POST['reason'] ?? ''));
$reasonValidation = validate_removal_reason($reason);
if (!$reasonValidation['ok']) {
    echo json_encode(['status' => 'error', 'message' => $reasonValidation['error']]);
    exit;
}

try {
    $pdo = get_pdo();
    $upload = $uploadId > 0 ? get_upload_by_id($pdo, $uploadId) : null;
    if ($upload === null) {
        echo json_encode(['status' => 'error', 'message' => 'UPLOAD RECORD NOT FOUND.']);
        exit;
    }

    $review = get_extraction_review($uploadId);
    if ($review === null) {
        echo json_encode(['status' => 'error', 'message' => 'NO ACTIVE REVIEW FOR THIS UPLOAD.']);
        exit;
    }

    $chosen = [];
    foreach (array_unique($rowIndexes) as $index) {
        if (!array_key_exists($index, $review['rows'])) {
            continue;
        }
        if (staged_row_is_locked($review['rows'][$index])) {
            echo json_encode(['status' => 'error', 'message' => STAGED_ROW_LOCKED_MESSAGE]);
            exit;
        }
        $chosen[$index] = $review['rows'][$index];
    }
    if ($chosen === []) {
        echo json_encode(['status' => 'error', 'message' => 'NO VALID RECORDS WERE SELECTED.']);
        exit;
    }

    if (!duplicate_findings_table_exists($pdo)) {
        echo json_encode(['status' => 'error', 'message' => 'THE DUPLICATE FINDINGS TABLE IS MISSING. ASK THE ADMINISTRATOR TO RUN database/add_graduate_duplicate_findings.sql.']);
        exit;
    }

    $currentUser = current_user();
    $found = record_duplicate_findings($pdo, [
        'upload_id' => $uploadId,
        'school' => format_school_display_name((string) $upload['school_name'], $upload['campus'] ?? null),
        'filename' => (string) $upload['original_filename'],
    ], $chosen, isset($currentUser['id']) ? (int) $currentUser['id'] : null);

    // the findings are stored; now the rows leave the review
    $removed = remove_staged_rows($uploadId, array_keys($chosen));
    if (!$removed['ok']) {
        echo json_encode(['status' => 'error', 'message' => $removed['error']]);
        exit;
    }

    log_activity(
        $pdo,
        $currentUser['id'] ?? null,
        'record_removed',
        $removed['removed'] . ' DUPLICATE RECORD(S) CONFIRMED AND REMOVED FROM upload_id=' . $uploadId . ', reason=' . $reason
        . ' (STAGED, PENDING REVIEW); ' . $found['originals'] . ' SAVED ORIGINAL(S) TAGGED DUPLICATE FOUND'
    );
} catch (\Throwable $e) {
    error_log('confirm_duplicates failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'THE DUPLICATES COULD NOT BE CONFIRMED. NOTHING WAS CHANGED. PLEASE TRY AGAIN.']);
    exit;
}

$message = $removed['removed'] . ' DUPLICATE RECORD(S) REMOVED.';
if ($found['originals'] > 0) {
    $message .= ' ' . $found['originals'] . ' SAVED RECORD(S) TAGGED WITH DUPLICATE FOUND.';
}
if ($found['untagged'] > 0) {
    $message .= ' ' . $found['untagged'] . ' HAD NO SAVED ORIGINAL (A COPY WITHIN THIS LIST), SO NOTHING WAS TAGGED FOR THEM.';
}

echo json_encode(['status' => 'success', 'message' => $message]);
