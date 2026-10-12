<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/uploads.php';
require_once __DIR__ . '/../includes/extraction_review.php';
require_once __DIR__ . '/../includes/graduates.php';
require_once __DIR__ . '/../includes/duplicates.php';

require_role('administrator');

header('Content-Type: application/json');

if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'YOUR SESSION EXPIRED. PLEASE TRY AGAIN.']);
    exit;
}

$uploadId = (int) ($_POST['upload_id'] ?? 0);

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

    // Duplicates are NAME matches, wherever they are saved (any school, any year).
    $saved = find_saved_duplicates_by_name($pdo, $uploadId);
    $groups = find_staged_duplicate_groups($review['rows'], $saved);

    foreach ($groups as &$group) {
        foreach ($group['saved'] as &$match) {
            $match['uploaded_on'] = date('M j, Y', strtotime((string) $match['uploaded_at']));
            $match['applied_on_label'] = $match['applied_on'] !== null ? date('M j, Y', strtotime($match['applied_on'])) : null;
        }
        unset($match);
    }
    unset($group);

    echo json_encode(['status' => 'success', 'groups' => $groups], JSON_INVALID_UTF8_SUBSTITUTE);
} catch (\Throwable $e) {
    error_log('Check save duplicates failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'THE DUPLICATE CHECK COULD NOT BE COMPLETED. PLEASE TRY AGAIN.']);
}
