<?php

/**
 * One-off: gives the 2nd, 3rd... upload of a school+year the "_dupNN" stored file name
 * (see build_stored_filename()). An upload is only renamed when an EARLIER upload exists
 * for the same school and year; the first one, and a lone #2 whose #1 is gone, keep their
 * names. The file on disk is renamed together with stored_filename/file_path.
 *
 *   php database/rename_duplicate_upload_files.php            # show what would change
 *   php database/rename_duplicate_upload_files.php --apply    # do it
 */

if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/uploads.php';

$apply = in_array('--apply', $argv, true);
$pdo = get_pdo();

$rows = $pdo->query(
    'SELECT u.id, u.school_id, u.school_year, u.upload_number, u.stored_filename, u.file_path
     FROM latin_honor_uploads u
     WHERE EXISTS (
         SELECT 1 FROM latin_honor_uploads e
         WHERE e.school_id = u.school_id AND e.school_year = u.school_year AND e.upload_number < u.upload_number
     )
     ORDER BY u.id'
)->fetchAll();

$changed = 0;
foreach ($rows as $row) {
    if (upload_dup_tag((string) $row['stored_filename']) !== null) {
        continue;
    }
    echo "upload #{$row['id']} (number {$row['upload_number']}): {$row['stored_filename']}";
    if (!$apply) {
        echo "  -> would be renamed\n";
        continue;
    }
    $result = rename_upload_file_to_match_school($pdo, (int) $row['id']);
    if (!$result['ok']) {
        echo "  -> FAILED: {$result['error']}\n";
    } elseif (!$result['renamed']) {
        echo "  -> not renamed (file not on disk or already named)\n";
    } else {
        $changed++;
        echo "  -> {$result['to']}\n";
    }
}

echo $apply ? "Done: {$changed} renamed.\n" : "Dry run only. Re-run with --apply to rename.\n";
