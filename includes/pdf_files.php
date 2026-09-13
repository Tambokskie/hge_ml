<?php

function sanitize_for_filename(string $value): string
{
    $upper = mb_strtoupper(trim($value));
    $sanitized = preg_replace('/[^A-Z0-9]+/', '_', $upper);
    return trim($sanitized, '_');
}

function build_stored_filename(string $schoolName, string $schoolYear, int $uploadNumber): string
{
    $schoolPart = sanitize_for_filename($schoolName);
    $yearPart = sanitize_for_filename($schoolYear);

    return "{$schoolPart}_{$yearPart}_{$uploadNumber}.pdf";
}

function validate_pdf_file_content(string $path, int $size, int $maxBytes): array
{
    if ($size > $maxBytes) {
        return ['ok' => false, 'error' => 'THE FILE EXCEEDS THE MAXIMUM ALLOWED SIZE.'];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $path);
    finfo_close($finfo);

    if ($mime !== 'application/pdf') {
        return ['ok' => false, 'error' => 'ONLY PDF FILES ARE ACCEPTED.'];
    }

    return ['ok' => true, 'error' => null];
}

function validate_pdf_upload(array $file, int $maxBytes): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'THE FILE COULD NOT BE UPLOADED. PLEASE TRY AGAIN.'];
    }

    $tmpName = $file['tmp_name'] ?? '';
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        return ['ok' => false, 'error' => 'THE FILE COULD NOT BE UPLOADED. PLEASE TRY AGAIN.'];
    }

    $size = filesize($tmpName);
    if ($size === false) {
        return ['ok' => false, 'error' => 'THE FILE COULD NOT BE UPLOADED. PLEASE TRY AGAIN.'];
    }

    return validate_pdf_file_content($tmpName, $size, $maxBytes);
}
