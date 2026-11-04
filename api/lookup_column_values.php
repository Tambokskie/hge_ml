<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/graduates.php';

require_login();

header('Content-Type: application/json');

// The value list of one Look-Up column for its Excel-style filter box (admin/lookup.php). Takes the same
// filters as api/search_graduates.php (the column's own filter is ignored, see build_graduate_search_conditions()).
$column = (string) ($_GET['column'] ?? '');
if (!isset(graduate_filter_columns()[$column])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'UNKNOWN COLUMN.']);
    exit;
}

$status = in_array($_GET['status'] ?? '', ['pending', 'approved'], true) ? $_GET['status'] : '';

$filters = [
    'school_id' => (isset($_GET['school_id']) && (int) $_GET['school_id'] > 0) ? (int) $_GET['school_id'] : null,
    'graduation_year' => (isset($_GET['year']) && (int) $_GET['year'] > 0) ? (int) $_GET['year'] : null,
    'name' => trim((string) ($_GET['name'] ?? '')),
    'status' => $status,
    'applied_year' => (isset($_GET['applied_year']) && (int) $_GET['applied_year'] > 0) ? (int) $_GET['applied_year'] : null,
    'columns' => parse_graduate_column_filters($_GET['cf'] ?? null),
];

try {
    $result = get_graduate_column_values(get_pdo(), $filters, $column, (string) ($_GET['q'] ?? ''));
    echo json_encode(['status' => 'success'] + $result);
} catch (\Throwable $e) {
    error_log('Column values failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'THE VALUES COULD NOT BE LOADED. PLEASE TRY AGAIN.']);
}
