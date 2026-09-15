<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/schools.php';

require_role('administrator');

header('Content-Type: application/json');

if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'YOUR SESSION EXPIRED. PLEASE TRY AGAIN.']);
    exit;
}

$schoolId = (int) ($_POST['school_id'] ?? 0);
$currentUser = current_user();

if ($schoolId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'SCHOOL NOT FOUND.']);
    exit;
}

$nameSubmitted = isset($_POST['name']);
$name = $nameSubmitted ? trim((string) $_POST['name']) : null;
$address = $nameSubmitted ? trim((string) ($_POST['address'] ?? '')) : null;
$campus = $nameSubmitted ? trim((string) ($_POST['campus'] ?? '')) : null;
$status = isset($_POST['status']) && $_POST['status'] !== '' ? (string) $_POST['status'] : null;

if ($nameSubmitted && $name === '') {
    echo json_encode(['status' => 'error', 'message' => 'SCHOOL NAME IS REQUIRED.']);
    exit;
}

try {
    $pdo = get_pdo();

    $existsStmt = $pdo->prepare('SELECT 1 FROM schools WHERE id = :id');
    $existsStmt->execute(['id' => $schoolId]);
    if ($existsStmt->fetchColumn() === false) {
        echo json_encode(['status' => 'error', 'message' => 'SCHOOL NOT FOUND.']);
        exit;
    }

    $pdo->beginTransaction();
    $details = [];

    if ($name !== null) {
        $result = rename_school($pdo, $schoolId, $name, $address, $campus);
        if ($result['status'] !== 'success') {
            $pdo->rollBack();
            echo json_encode($result);
            exit;
        }
        $details[] = 'name=' . $name . ', address=' . $address . ', campus=' . $campus;
    }

    if ($status !== null) {
        $result = set_school_status($pdo, $schoolId, $status);
        if ($result['status'] !== 'success') {
            $pdo->rollBack();
            echo json_encode($result);
            exit;
        }
        $details[] = 'status=' . $status;
    }

    if (empty($details)) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'NO CHANGES WERE SUBMITTED.']);
        exit;
    }

    $pdo->commit();

    try {
        log_activity($pdo, $currentUser['id'] ?? null, 'school_updated', 'school_id=' . $schoolId . ', ' . implode(', ', $details));
    } catch (\Throwable $logError) {
        error_log('Activity log failed: ' . $logError->getMessage());
    }

    echo json_encode(['status' => 'success', 'message' => 'SCHOOL UPDATED SUCCESSFULLY.']);
} catch (\Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Update school failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'THE SCHOOL COULD NOT BE UPDATED. PLEASE TRY AGAIN.']);
}
