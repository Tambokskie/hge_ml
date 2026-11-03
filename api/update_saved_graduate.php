<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/graduates.php';
require_once __DIR__ . '/../includes/extraction_review.php';

require_role('administrator');

header('Content-Type: application/json');

if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'YOUR SESSION EXPIRED. PLEASE TRY AGAIN.']);
    exit;
}

$id = (int) ($_POST['id'] ?? 0);

$data = [
    'graduate_name' => trim((string) ($_POST['graduate_name'] ?? '')),
    'course' => trim((string) ($_POST['course'] ?? '')),
    'latin_honor' => (string) ($_POST['latin_honor'] ?? ''),
    'graduation_date' => (string) ($_POST['graduation_date'] ?? ''),
    'graduation_year' => (string) ($_POST['graduation_year'] ?? ''),
];

$validation = validate_staged_row([
    'graduate_name' => $data['graduate_name'],
    'latin_honor' => $data['latin_honor'],
    'graduation_date' => $data['graduation_date'] !== '' ? $data['graduation_date'] : null,
    'graduation_year' => $data['graduation_year'],
]);

if (!$validation['ok']) {
    echo json_encode(['status' => 'error', 'message' => implode(' ', $validation['errors'])]);
    exit;
}

try {
    $pdo = get_pdo();
    $existing = $id > 0 ? get_graduate_by_id($pdo, $id) : null;

    if ($existing === null) {
        echo json_encode(['status' => 'error', 'message' => 'GRADUATE RECORD NOT FOUND.']);
        exit;
    }

    update_graduate($pdo, $id, [
        'graduate_name' => $data['graduate_name'],
        'course' => $data['course'],
        'latin_honor' => $data['latin_honor'],
        'graduation_date' => $data['graduation_date'] !== '' ? $data['graduation_date'] : null,
        // the year always follows the date when there is one (resolve_graduation_year())
        'graduation_year' => resolve_graduation_year($data['graduation_date'] !== '' ? $data['graduation_date'] : null, $data['graduation_year']),
    ]);

    $currentUser = current_user();
    log_activity($pdo, $currentUser['id'] ?? null, 'record_updated', 'graduate_name=' . $data['graduate_name']);
} catch (\Throwable $e) {
    error_log('update_saved_graduate failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'THE RECORD COULD NOT BE UPDATED. PLEASE TRY AGAIN.']);
    exit;
}

echo json_encode(['status' => 'success', 'message' => 'RECORD UPDATED.']);
