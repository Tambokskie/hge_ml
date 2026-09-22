<?php

const GHOSTSCRIPT_INSTALLER_URL = 'https://github.com/ArtifexSoftware/ghostpdl-downloads/releases/download/gs10080/gs10080w64.exe';
const GHOSTSCRIPT_INSTALLER_SHA256 = '52a91b8bf09298788d7a57b9206127026c23eacd75405f0a131e26dc381dce50';

function resolve_ghostscript_path(): string
{
    $envPath = getenv('GHOSTSCRIPT_PATH');
    if ($envPath) {
        return $envPath;
    }

    $default = 'C:\Program Files\gs\gs10.08.0\bin\gswin64c.exe';
    if (is_file($default)) {
        return $default;
    }

    // Ghostscript's installer folders itself by version (e.g. gs10.08.0), so a
    // freshly installed copy may land at a different version than the
    // hardcoded default above.
    $matches = glob('C:\Program Files\gs\gs*\bin\gswin64c.exe') ?: [];
    if (!empty($matches)) {
        natsort($matches);
        return (string) end($matches);
    }

    return $default;
}

function resolve_tesseract_path(): string
{
    return getenv('TESSERACT_PATH') ?: 'C:\Program Files\Tesseract-OCR\tesseract.exe';
}

function is_ocr_tool_working(string $executablePath): bool
{
    if (!is_file($executablePath)) {
        return false;
    }

    exec(escapeshellarg($executablePath) . ' --version 2>&1', $output, $returnCode);

    return $returnCode === 0;
}

/**
 * @return array{ghostscript: bool, tesseract: bool}
 */
function check_ocr_tools_installed(): array
{
    return [
        'ghostscript' => is_ocr_tool_working(resolve_ghostscript_path()),
        'tesseract' => is_ocr_tool_working(resolve_tesseract_path()),
    ];
}

/**
 * @return array{ok: bool, error?: string}
 */
function install_ghostscript(): array
{
    $tmpFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gs_installer_' . uniqid() . '.exe';

    $context = stream_context_create([
        'http' => ['timeout' => 180],
        'https' => ['timeout' => 180],
    ]);

    if (!@copy(GHOSTSCRIPT_INSTALLER_URL, $tmpFile, $context)) {
        error_log('install_ghostscript: download failed from ' . GHOSTSCRIPT_INSTALLER_URL);
        return ['ok' => false, 'error' => 'GHOSTSCRIPT INSTALLER COULD NOT BE DOWNLOADED.'];
    }

    $actualHash = hash_file('sha256', $tmpFile);
    if ($actualHash !== GHOSTSCRIPT_INSTALLER_SHA256) {
        @unlink($tmpFile);
        error_log('install_ghostscript: checksum mismatch, expected ' . GHOSTSCRIPT_INSTALLER_SHA256 . ' got ' . $actualHash);
        return ['ok' => false, 'error' => 'GHOSTSCRIPT INSTALLER FAILED INTEGRITY VERIFICATION.'];
    }

    exec(escapeshellarg($tmpFile) . ' /S 2>&1', $output, $returnCode);
    @unlink($tmpFile);

    if ($returnCode !== 0) {
        error_log('install_ghostscript: silent install exited ' . $returnCode . ': ' . implode(' ', $output));
        return ['ok' => false, 'error' => 'GHOSTSCRIPT INSTALLATION FAILED.'];
    }

    return ['ok' => true];
}

/**
 * @return array{ok: bool, error?: string}
 */
function install_tesseract(): array
{
    exec('winget install --id UB-Mannheim.TesseractOCR -e --silent --accept-package-agreements --accept-source-agreements 2>&1', $output, $returnCode);

    if ($returnCode !== 0) {
        error_log('install_tesseract: winget install exited ' . $returnCode . ': ' . implode(' ', $output));
        return ['ok' => false, 'error' => 'TESSERACT INSTALLATION FAILED.'];
    }

    return ['ok' => true];
}
