<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/uploads.php';

require_login();

header('Content-Type: application/json');

$schoolId = (isset($_GET['school_id']) && (int) $_GET['school_id'] > 0) ? (int) $_GET['school_id'] : null;
$schoolYear = trim((string) ($_GET['school_year'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));

$filters = [
    'school_id' => $schoolId,
    'school_year' => $schoolYear !== '' ? $schoolYear : null,
];

try {
    $pdo = get_pdo();
    $result = search_uploads($pdo, $filters, $page);

    echo json_encode([
        'status' => 'success',
        'rows' => $result['rows'],
        'pagination' => $result['pagination'],
    ]);
} catch (\Throwable $e) {
    error_log('School records search failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'THE SEARCH COULD NOT BE COMPLETED. PLEASE TRY AGAIN.']);
}
