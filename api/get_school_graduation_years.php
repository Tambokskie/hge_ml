<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/graduates.php';

require_role('administrator');

header('Content-Type: application/json');

$schoolId = (int) ($_GET['school_id'] ?? 0);

if ($schoolId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'SCHOOL NOT FOUND.']);
    exit;
}

try {
    $pdo = get_pdo();
    $years = get_graduation_years_for_school($pdo, $schoolId);

    echo json_encode(['status' => 'success', 'years' => $years]);
} catch (\Throwable $e) {
    error_log('get_school_graduation_years failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'THE YEARS COULD NOT BE LOADED. PLEASE TRY AGAIN.']);
}
