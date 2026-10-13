<?php

// Signs a sample user in for the Help page screenshot tool (capture.mjs). It exists ONLY for the throw-away
// `php -S` server that capture.mjs starts against the made-up-data database, and refuses to do anything else:
// it is a login bypass, so it must never answer under Apache or against the real database.
if (PHP_SAPI !== 'cli-server' || getenv('DB_NAME') !== 'hge_help_shots') {
    http_response_code(403);
    exit('Only for the Help screenshot tool.');
}

$base = dirname(__DIR__, 2) . '/';
require_once $base . 'config/database.php';
require_once $base . 'includes/functions.php';
require_once $base . 'includes/auth.php';
require_once $base . 'includes/extraction_review.php';

$pdo = get_pdo();
$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([(int) ($_GET['uid'] ?? 1)]);
$user = $stmt->fetch();
login_user($user);
csrf_token();

// ?stage=<upload id> also puts a made-up extraction result into the session, for the review screen.
if (isset($_GET['stage'])) {
    $row = static fn (string $name, string $course, string $honor, string $source = 'ai'): array => [
        'graduate_name' => $name, 'course' => $course, 'latin_honor' => $honor,
        'graduation_date' => '2026-06-20', 'graduation_year' => 2026, 'source' => $source,
    ];
    start_extraction_review((int) $_GET['stage'], [
        $row('ABAD, CARLA M.', 'BS Information Technology', 'CUM LAUDE'),
        $row('BONDOC, RAFAEL T.', 'BS Accountancy', 'MAGNA CUM LAUDE'),
        $row('CASTRO, ELENA P.', 'BS Education', 'CUM LAUDE'),
        $row('DAYAO, MARK A.', 'BS Civil Engineering', 'SUMMA CUM LAUDE'),
        $row('ESPINO, GRACE L.', 'BS Nursing', 'CUM LAUDE'),
        $row('FRANCISCO, LUIS D.', 'AB Communication', ''),
        $row('GALANG, SOFIA R.', 'BS Psychology', 'MAGNA CUM LAUDE'),
        $row('HIPOLITO, KEVIN S.', 'BS Business Administration', 'CUM LAUDE', 'manual'),
        $row('IGNACIO, ISABEL C.', 'BS Hospitality Management', 'CUM LAUDE'),
        $row('JIMENEZ, PAOLO V.', 'BS Criminology', 'CUM LAUDE'),
        $row('SANTOS, ANA B.', 'BS Nursing', 'CUM LAUDE'),
    ]);
}
echo 'ok ', $user['username'];
