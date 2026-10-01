<?php

// Disposable per-upload progress snapshots (storage/cache/, same pattern as
// gemini_quota_status.json) — written by api/extract_pdf.php as it moves
// through extraction stages, polled by api/extraction_progress.php from the
// browser while that long-running request is still in flight. Not in the DB:
// this is UI-only state with no value once extraction finishes.

function extraction_progress_file(int $uploadId): string
{
    return __DIR__ . '/../storage/cache/extraction_progress_' . $uploadId . '.json';
}

function write_extraction_progress(int $uploadId, int $percent, string $label): void
{
    $dir = dirname(extraction_progress_file($uploadId));
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }

    @file_put_contents(extraction_progress_file($uploadId), json_encode([
        'percent' => max(0, min(100, $percent)),
        'label' => $label,
    ]));
}

function read_extraction_progress(int $uploadId): ?array
{
    $raw = @file_get_contents(extraction_progress_file($uploadId));
    if ($raw === false) {
        return null;
    }

    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function clear_extraction_progress(int $uploadId): void
{
    @unlink(extraction_progress_file($uploadId));
}
