<?php

function sanitize_for_filename(string $value): string
{
    $upper = mb_strtoupper(trim($value));
    $sanitized = preg_replace('/[^A-Z0-9]+/', '_', $upper);
    return trim($sanitized, '_');
}

function build_stored_filename(string $schoolName, string $schoolYear, int $uploadNumber, string $campus = '', string $extension = 'pdf'): string
{
    $schoolPart = sanitize_for_filename($schoolName);
    $yearPart = sanitize_for_filename($schoolYear);
    $campusPart = sanitize_for_filename($campus);

    $namePart = $campusPart !== '' ? "{$schoolPart}_{$campusPart}" : $schoolPart;

    // The first upload for a school+year keeps the plain number (..._2025_1); the 2nd, 3rd...
    // are marked as duplicates (..._2025_dup01, ..._2025_dup02) so they are told apart at a glance.
    $suffix = $uploadNumber > 1 ? sprintf('dup%02d', $uploadNumber - 1) : (string) $uploadNumber;

    return "{$namePart}_{$yearPart}_{$suffix}.{$extension}";
}

/**
 * The duplicate-upload tag of a stored file name ("DUP01" for ..._2025_dup01.pdf), or
 * null for a first upload. Read from the file name so an upload that was never renamed
 * (e.g. a lone #2 whose #1 no longer exists) is not labelled a duplicate.
 */
function upload_dup_tag(string $storedFilename): ?string
{
    return preg_match('/_dup(\d{2,})\.[A-Za-z0-9]+$/', $storedFilename, $m) === 1 ? 'DUP' . $m[1] : null;
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
