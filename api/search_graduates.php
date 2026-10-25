<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/graduates.php';

require_login();

header('Content-Type: application/json');

$schoolId = (isset($_GET['school_id']) && (int) $_GET['school_id'] > 0) ? (int) $_GET['school_id'] : null;
$year = (isset($_GET['year']) && (int) $_GET['year'] > 0) ? (int) $_GET['year'] : null;
$name = trim((string) ($_GET['name'] ?? ''));
$status = ($_GET['status'] ?? '') === 'pending' ? 'pending' : '';
$appliedYear = (isset($_GET['applied_year']) && (int) $_GET['applied_year'] > 0) ? (int) $_GET['applied_year'] : null;
$page = max(1, (int) ($_GET['page'] ?? 1));

$filters = [
    'school_id' => $schoolId,
    'graduation_year' => $year,
    'name' => $name,
    'status' => $status,
    'applied_year' => $appliedYear,
];

// Same as admin/lookup.php: ALL YEARS is the default year filter, so a search with
// nothing filled in lists everything instead of asking for a filter first.
$hasFilters = true;

try {
    if (!$hasFilters) {
        echo json_encode(['status' => 'success', 'has_filters' => false, 'rows' => [], 'pagination' => null]);
        exit;
    }

    $pdo = get_pdo();
    $result = search_graduates($pdo, $filters, $page);

    try {
        $currentUser = current_user();
        log_activity($pdo, $currentUser['id'] ?? null, 'search', build_search_log_details($filters));
    } catch (\Throwable $logError) {
        error_log('Activity log failed: ' . $logError->getMessage());
    }

    echo json_encode([
        'status' => 'success',
        'has_filters' => true,
        'rows' => $result['rows'],
        'pagination' => $result['pagination'],
        'applied_year' => $appliedYear,
    ]);
} catch (\Throwable $e) {
    error_log('Graduate search failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'THE SEARCH COULD NOT BE COMPLETED. PLEASE TRY AGAIN.']);
}
