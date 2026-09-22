<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/graduates.php';

require_login();

header('Content-Type: application/json');

if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'YOUR SESSION EXPIRED. PLEASE TRY AGAIN.']);
    exit;
}

$graduateId = (int) ($_POST['graduate_id'] ?? 0);
$applicationDate = trim((string) ($_POST['application_date'] ?? ''));
$currentUser = current_user();

$parsedDate = DateTime::createFromFormat('Y-m-d', $applicationDate);
if (!$parsedDate || $parsedDate->format('Y-m-d') !== $applicationDate) {
    echo json_encode(['status' => 'error', 'message' => 'PLEASE SELECT A VALID DATE OF APPLICATION.']);
    exit;
}

try {
    $pdo = get_pdo();
    $graduate = $graduateId > 0 ? get_graduate_by_id($pdo, $graduateId) : null;

    if ($graduate === null) {
        echo json_encode(['status' => 'error', 'message' => 'GRADUATE RECORD NOT FOUND.']);
        exit;
    }

    $result = file_application($pdo, $graduateId, (int) $currentUser['id'], $applicationDate);

    if (!$result['ok']) {
        echo json_encode(['status' => 'error', 'message' => $result['error']]);
        exit;
    }

    log_activity($pdo, $currentUser['id'] ?? null, 'application_filed', 'graduate_name=' . $graduate['graduate_name']);
} catch (\Throwable $e) {
    error_log('file_application failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'THE APPLICATION COULD NOT BE RECORDED. PLEASE TRY AGAIN.']);
    exit;
}

echo json_encode(['status' => 'success', 'message' => 'APPLICATION RECORDED SUCCESSFULLY.']);
