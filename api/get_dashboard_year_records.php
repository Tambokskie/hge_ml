<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();

header('Content-Type: application/json');

$year = (int) ($_GET['year'] ?? 0);

if ($year <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'INVALID YEAR.']);
    exit;
}

try {
    $count = get_graduate_count_by_year(get_pdo(), $year);

    echo json_encode(['status' => 'success', 'year' => $year, 'count' => $count]);
} catch (\Throwable $e) {
    error_log('get_dashboard_year_records failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'THE RECORD COUNT COULD NOT BE LOADED. PLEASE TRY AGAIN.']);
}
