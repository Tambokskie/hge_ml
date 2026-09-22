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

// Belt-and-suspenders: even with the page-load gate on admin/ocr_courses.php,
// this endpoint is reachable directly, so it re-checks the same step-up
// grant rather than trusting that the request came from the gated page.
if (!has_step_up('ocr_courses')) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'YOUR SECURITY VERIFICATION HAS EXPIRED. PLEASE VERIFY YOUR IDENTITY AGAIN.']);
    exit;
}

try {
    $pdo = get_pdo();
    $keyword = trim((string) ($_POST['keyword'] ?? ''));

    $result = add_ocr_course_keyword($pdo, $keyword, (int) current_user()['id']);

    if ($result['status'] === 'success') {
        try {
            log_activity($pdo, current_user()['id'] ?? null, 'ocr_keyword_created', 'keyword=' . $result['keyword']);
        } catch (\Throwable $logError) {
            error_log('Activity log failed: ' . $logError->getMessage());
        }
    }

    echo json_encode($result);
} catch (\Throwable $e) {
    error_log('Add OCR keyword failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'THE KEYWORD COULD NOT BE ADDED. PLEASE TRY AGAIN.']);
}
