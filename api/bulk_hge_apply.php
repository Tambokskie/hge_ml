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

const BULK_HGE_PREVIEW_TTL = 1800;

$staged = $_SESSION['bulk_hge_preview'] ?? null;
$token = (string) ($_POST['token'] ?? '');

if (!is_array($staged) || $token === '' || !hash_equals((string) $staged['token'], $token)) {
    echo json_encode(['status' => 'error', 'message' => 'THERE IS NO PREVIEW TO CONFIRM. PLEASE UPLOAD THE FILE AGAIN.']);
    exit;
}

// One-time: whatever happens next, this preview cannot be confirmed twice.
unset($_SESSION['bulk_hge_preview']);

if (time() - (int) $staged['created'] > BULK_HGE_PREVIEW_TTL) {
    echo json_encode(['status' => 'error', 'message' => 'THE PREVIEW EXPIRED. PLEASE UPLOAD THE FILE AGAIN.']);
    exit;
}

$currentUser = current_user();

try {
    $pdo = get_pdo();
    // Filing the matched applications and saving the uncertain ones for review
    // happen together or not at all.
    $result = bulk_hge_apply_with_review($pdo, $staged['pairs'], $staged['review'], (int) $currentUser['id'], (string) $staged['filename']);

    if (!$result['ok']) {
        echo json_encode(['status' => 'error', 'message' => $result['error']]);
        exit;
    }

    log_activity(
        $pdo,
        $currentUser['id'] ?? null,
        'application_filed',
        'bulk update: ' . $result['filed'] . ' filed, ' . $result['approved'] . ' approved, ' . $result['skipped'] . ' skipped (vanished), ' . $result['invalid_date'] . ' left out (date before graduation year), '
            . ($result['review']['added'] + $result['review']['updated']) . ' saved for review, from ' . $staged['filename']
    );
} catch (\Throwable $e) {
    error_log('bulk_hge_apply endpoint failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'THE APPLICATIONS COULD NOT BE FILED. PLEASE TRY AGAIN.']);
    exit;
}

echo json_encode([
    'status' => 'success',
    'filed' => $result['filed'],
    'approved' => $result['approved'],
    'skipped' => $result['skipped'],
    'invalid_date' => $result['invalid_date'],
    'review_added' => $result['review']['added'],
    'review_updated' => $result['review']['updated'],
    'review_skipped' => $result['review']['skipped'],
    'message' => number_format($result['filed']) . ' APPLICATION(S) FILED, ' . number_format($result['approved']) . ' APPROVED.',
]);
