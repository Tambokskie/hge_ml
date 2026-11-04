<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/uploads.php';

require_login();

header('Content-Type: application/json');

// The value list of one SCHOOL RECORDS column for its Excel-style filter box (admin/school_records.php). Takes
// the same filters as api/search_school_records.php (the column's own filter is ignored).
$column = (string) ($_GET['column'] ?? '');
if (!isset(upload_filter_columns()[$column])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'UNKNOWN COLUMN.']);
    exit;
}

$schoolYear = trim((string) ($_GET['school_year'] ?? ''));
$filters = [
    'school_id' => (isset($_GET['school_id']) && (int) $_GET['school_id'] > 0) ? (int) $_GET['school_id'] : null,
    'school_year' => $schoolYear !== '' ? $schoolYear : null,
    'columns' => parse_upload_column_filters($_GET['cf'] ?? null),
];

try {
    $result = get_upload_column_values(get_pdo(), $filters, $column, (string) ($_GET['q'] ?? ''));
    echo json_encode(['status' => 'success'] + $result);
} catch (\Throwable $e) {
    error_log('Upload column values failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'THE VALUES COULD NOT BE LOADED. PLEASE TRY AGAIN.']);
}
