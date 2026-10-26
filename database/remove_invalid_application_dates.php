<?php

// One-off clean-up for the rule "the date applied / date approved may not be in a year before the
// graduate's graduation year": finds every application that breaks it and, with --apply, removes it
// (application_date is NOT NULL, so removing the dates removes the application row — the graduate goes
// back to "not applied").
//
//   php database/remove_invalid_application_dates.php           lists them, changes nothing
//   php database/remove_invalid_application_dates.php --apply   saves a CSV copy to storage/cache/, then removes them
//
// Safe to run again: it only ever touches applications that still break the rule.

if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/graduates.php';
require_once __DIR__ . '/../includes/activity.php';

$apply = in_array('--apply', $argv, true);
$pdo = get_pdo();

$invalid = find_invalid_application_dates($pdo);
$total = (int) $pdo->query('SELECT COUNT(*) FROM graduate_applications')->fetchColumn();

echo 'Applications checked: ' . $total . "\n";
echo 'Breaking the rule:    ' . count($invalid) . "\n";
foreach ($invalid as $row) {
    echo '  application #' . $row['application_id'] . '  graduate #' . $row['graduate_id'] . '  ' . $row['graduate_name']
        . '  graduated ' . $row['effective_year'] . '  applied ' . $row['application_date']
        . '  approved ' . ($row['approved_at'] ?? '-') . "\n";
}

if (!$apply || $invalid === []) {
    echo $invalid === [] ? "Nothing to remove.\n" : "Dry run: nothing was changed. Run again with --apply to remove them.\n";
    exit(0);
}

$backup = __DIR__ . '/../storage/cache/removed_invalid_application_dates_' . date('Ymd_His') . '.csv';
$handle = fopen($backup, 'wb');
if ($handle === false) {
    exit("Could not write the backup file, nothing was removed.\n");
}
fputcsv($handle, array_keys($invalid[0]));
foreach ($invalid as $row) {
    fputcsv($handle, $row);
}
fclose($handle);
echo 'Backup saved: ' . realpath($backup) . "\n";

$removed = remove_invalid_application_dates($pdo);
foreach ($removed as $row) {
    log_activity($pdo, null, 'record_updated', 'invalid application dates removed: graduate_name=' . $row['graduate_name']);
}

echo 'Removed: ' . count($removed) . "\n";
echo 'Still breaking the rule: ' . count(find_invalid_application_dates($pdo)) . "\n";
