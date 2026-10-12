<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ocr_keywords.php';

require_role('administrator');

header('Content-Type: application/json');

if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'YOUR SESSION EXPIRED. PLEASE TRY AGAIN.']);
    exit;
}

try {
    $pdo = get_pdo();
    $id = (int) ($_POST['id'] ?? 0);

    $result = remove_ocr_course_keyword($pdo, $id, current_user()['id'] ?? null);

    if ($result['status'] === 'success') {
        try {
            log_activity($pdo, current_user()['id'] ?? null, 'ocr_keyword_removed', 'keyword=' . $result['keyword'] . ' (moved to recycle bin)');
        } catch (\Throwable $logError) {
            error_log('Activity log failed: ' . $logError->getMessage());
        }
    }

    echo json_encode($result);
} catch (\Throwable $e) {
    error_log('Remove OCR keyword failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'THE KEYWORD COULD NOT BE REMOVED. PLEASE TRY AGAIN.']);
}
