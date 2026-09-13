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
