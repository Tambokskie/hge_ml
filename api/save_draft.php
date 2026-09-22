<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/uploads.php';
require_once __DIR__ . '/../includes/extraction_review.php';

require_role('administrator');

header('Content-Type: application/json');

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

    $review = get_extraction_review($uploadId);
    if ($review === null) {
        echo json_encode(['status' => 'error', 'message' => 'NO ACTIVE REVIEW FOR THIS UPLOAD.']);
        exit;
    }

    $currentUser = current_user();
    $result = save_extraction_draft($pdo, $uploadId, (int) $currentUser['id'], $review['rows']);

    if ($result['status'] === 'success') {
        log_activity(
            $pdo,
            (int) $currentUser['id'],
            'record_draft_saved',
            'SAVED DRAFT FOR ' . $upload['school_name'] . ' (' . $upload['school_year'] . ') — ' . count($review['rows']) . ' RECORD(S)'
        );
    }

    echo json_encode($result + ['redirect_url' => APP_BASE_URL . '/admin/view_upload.php?id=' . $uploadId]);
} catch (\Throwable $e) {
    error_log('Save draft failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'THE DRAFT COULD NOT BE SAVED. PLEASE TRY AGAIN.']);
    exit;
}
