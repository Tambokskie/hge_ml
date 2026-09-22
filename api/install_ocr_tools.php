<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ocr_tools.php';

require_role('administrator');

header('Content-Type: application/json');
ini_set('max_execution_time', 300);

if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'YOUR SESSION EXPIRED. PLEASE TRY AGAIN.']);
    exit;
}

try {
    $before = check_ocr_tools_installed();

    if (!$before['ghostscript']) {
        install_ghostscript();
    }

    if (!$before['tesseract']) {
        install_tesseract();
    }

    $after = check_ocr_tools_installed();

    if ($after['ghostscript'] && $after['tesseract']) {
        echo json_encode(['status' => 'success', 'message' => 'OCR TOOLS INSTALLED SUCCESSFULLY.']);
        exit;
    }

    $stillMissing = array_keys(array_filter($after, fn (bool $installed) => !$installed));

    echo json_encode([
        'status' => 'error',
        'message' => 'THE FOLLOWING TOOL(S) COULD NOT BE INSTALLED AUTOMATICALLY: '
            . mb_strtoupper(implode(', ', $stillMissing)) . '. PLEASE INSTALL THEM MANUALLY AND TRY AGAIN.',
    ]);
} catch (\Throwable $e) {
    error_log('OCR tool installation failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'OCR TOOL INSTALLATION FAILED. PLEASE TRY AGAIN.']);
}
