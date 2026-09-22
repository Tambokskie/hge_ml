<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/graduates.php';

require_role('administrator');

header('Content-Type: application/json');

if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'YOUR SESSION EXPIRED. PLEASE TRY AGAIN.']);
    exit;
}

$schoolId = (int) ($_POST['school_id'] ?? 0);
$year = (int) ($_POST['year'] ?? 0);
$date = trim((string) ($_POST['graduation_date'] ?? ''));

if ($schoolId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'PLEASE SELECT A SCHOOL.']);
    exit;
}

if ($year <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'PLEASE SELECT A YEAR.']);
    exit;
}

if ($date === '') {
    echo json_encode(['status' => 'error', 'message' => 'PLEASE SELECT A DATE OF GRADUATION.']);
    exit;
}

try {
    $pdo = get_pdo();
    $result = bulk_update_graduation_date($pdo, $schoolId, $year, $date);

    if ($result['status'] === 'success') {
        $currentUser = current_user();
        log_activity(
            $pdo,
            $currentUser['id'] ?? null,
            'record_updated',
            'BULK GRADUATION DATE UPDATE — school_id=' . $schoolId . ', year=' . $year . ', date=' . $date . ', updated=' . $result['updated']
        );
    }

    echo json_encode($result);
} catch (\Throwable $e) {
    error_log('bulk_update_graduation_date failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'THE RECORDS COULD NOT BE UPDATED. PLEASE TRY AGAIN.']);
}
