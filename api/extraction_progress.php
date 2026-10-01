<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/extraction_progress.php';

require_role('administrator');

header('Content-Type: application/json');

$uploadId = (int) ($_GET['upload_id'] ?? 0);

if ($uploadId <= 0) {
    echo json_encode(['percent' => 0, 'label' => '']);
    exit;
}

$progress = read_extraction_progress($uploadId);

echo json_encode($progress ?? ['percent' => 0, 'label' => '']);
