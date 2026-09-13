<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/extraction_review.php';

require_role('administrator');

header('Content-Type: application/json');

if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'YOUR SESSION EXPIRED. PLEASE TRY AGAIN.']);
    exit;
}

$uploadId = (int) ($_POST['upload_id'] ?? 0);
$rowIndex = isset($_POST['row_index']) && $_POST['row_index'] !== '' ? (int) $_POST['row_index'] : null;

$data = [
    'graduate_name' => trim((string) ($_POST['graduate_name'] ?? '')),
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

$result = set_staged_row($uploadId, $rowIndex, $data);

if (!$result['ok']) {
    echo json_encode(['status' => 'error', 'message' => $result['error']]);
    exit;
}

echo json_encode(['status' => 'success', 'message' => $rowIndex === null ? 'RECORD ADDED.' : 'RECORD UPDATED.']);
