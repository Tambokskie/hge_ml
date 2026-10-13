<?php

// Creates (or drops) a SEPARATE database, hge_help_shots, holding entirely made-up data for the Help page
// screenshots. It never touches the real database (hge_masterlist).
//
//   php tools/help-screenshots/seed.php         create + fill hge_help_shots
//   php tools/help-screenshots/seed.php drop    remove it again

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$dbName = 'hge_help_shots';
$root = new PDO('mysql:host=127.0.0.1;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$root->exec("DROP DATABASE IF EXISTS $dbName");
if (($argv[1] ?? '') === 'drop') {
    echo "dropped $dbName\n";
    exit(0);
}
$root->exec("CREATE DATABASE $dbName CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo = new PDO("mysql:host=127.0.0.1;dbname=$dbName;charset=utf8mb4", 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+08:00'",
]);

$schema = file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql');
$schema = preg_replace('/^CREATE DATABASE.*$/m', '', $schema);
$schema = preg_replace('/^USE .*$/m', '', $schema);
foreach (array_filter(array_map('trim', explode(';', $schema))) as $stmt) {
    $pdo->exec($stmt);
}
mt_srand(20261013);

$hash = password_hash('not-a-real-password', PASSWORD_DEFAULT);
$u = $pdo->prepare('INSERT INTO users (name, username, email, password, role, status, created_at) VALUES (?,?,?,?,?,?,?)');
foreach ([
    ['Maria Santos', 'msantos', 'maria.santos@example.test', 'administrator', 'active', '2025-06-02 09:00:00'],
    ['Juan Reyes', 'jreyes', 'juan.reyes@example.test', 'user', 'active', '2025-06-10 10:30:00'],
    ['Ana Lopez', 'alopez', 'ana.lopez@example.test', 'user', 'active', '2025-08-18 08:15:00'],
    ['Pedro Ramos', 'pramos', 'pedro.ramos@example.test', 'user', 'disabled', '2025-09-01 13:00:00'],
    ['Liza Bautista', 'lbautista', 'liza.bautista@example.test', 'administrator', 'active', '2025-07-21 14:20:00'],
] as $r) {
    $u->execute([$r[0], $r[1], $r[2], $hash, $r[3], $r[4], $r[5]]);
}

$schools = [
    ['Sample State University', '123 College Avenue, Sample City', 'Main Campus'],
    ['Sample State University', '45 North Road, Sample City', 'North Campus'],
    ['Riverside College', '8 River Street, Riverside', ''],
    ['Mabini Community College', '22 Plaza Road, Mabini', ''],
    ['Harbor Institute of Technology', '5 Port Area Drive, Harbor City', ''],
    ['Greenfield University', '90 Greenfield Boulevard, Greenfield', ''],
    ['St. Anselm College', '14 Church Street, San Anselmo', ''],
];
$s = $pdo->prepare('INSERT INTO schools (school_name, address, campus, status) VALUES (?,?,?,?)');
foreach ($schools as $i => $r) {
    $s->execute([$r[0], $r[1], $r[2], $i === 6 ? 'disabled' : 'active']);
}

// school id, school year, status, uploaded at, file type, number of graduates
$uploads = [
    [1, '2024', 'reviewed', '2025-02-11 10:12:00', 'pdf', 150],
    [1, '2025', 'reviewed', '2025-09-15 09:30:00', 'pdf', 140],
    [2, '2025', 'reviewed', '2025-09-16 11:05:00', 'xlsx', 90],
    [3, '2025', 'reviewed', '2025-10-02 14:45:00', 'pdf', 120],
    [4, '2025', 'reviewed', '2025-11-20 13:20:00', 'pdf', 80],
    [5, '2026', 'reviewed', '2026-06-18 10:00:00', 'pdf', 110],
    [6, '2026', 'reviewed', '2026-07-09 15:10:00', 'pdf', 130],
    [3, '2026', 'extracted', '2026-09-29 09:05:00', 'pdf', 11], // upload 8: the one the review screenshot is taken on
    [4, '2026', 'draft', '2026-10-07 16:40:00', 'pdf', 0],
    [2, '2026', 'failed', '2026-10-10 08:55:00', 'pdf', 0],
];
$up = $pdo->prepare('INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number, extraction_status, records_extracted, total_pages, extracted_through_page, uploaded_at) VALUES (?,?,?,?,?,?,SHA2(?,256),?,1,?,?,?,?,?)');
foreach ($uploads as $i => [$sid, $yr, $st, $at, $ext, $n]) {
    $name = strtoupper(str_replace([' ', '.'], ['_', ''], $schools[$sid - 1][0] . ($schools[$sid - 1][2] ? '_' . $schools[$sid - 1][2] : ''))) . "_{$yr}_1.$ext";
    $pages = $ext === 'pdf' ? max(2, (int) ceil(max($n, 24) / 22)) : null;
    $through = $st === 'reviewed' || $st === 'extracted' ? ($pages ?? 0) : ($st === 'draft' ? 2 : 0);
    $up->execute([$sid, $yr, "Latin Honors $yr.$ext", $name, 'storage/' . ($ext === 'pdf' ? 'pdfs/' : 'templates/') . $name, 240000 + $i * 13111, "seed$i", $i % 2 ? 2 : 1, $st, $n, $pages, $through, $at]);
}

$first = ['Juan', 'Maria', 'Jose', 'Ana', 'Carlo', 'Liza', 'Mark', 'Grace', 'Paolo', 'Joy', 'Miguel', 'Sofia', 'Rafael', 'Elena', 'Daniel', 'Patricia', 'Luis', 'Carmen', 'Andres', 'Isabel', 'Noel', 'Rhea', 'Kevin', 'Angela', 'Victor', 'Bianca', 'Ramon', 'Teresa', 'Dennis', 'Camille'];
$last = ['Dela Cruz', 'Santos', 'Reyes', 'Garcia', 'Mendoza', 'Torres', 'Flores', 'Navarro', 'Castillo', 'Aquino', 'Villanueva', 'Ramos', 'Bautista', 'Domingo', 'Salazar', 'Pascual', 'Macaraeg', 'Lim', 'Tan', 'Soriano', 'Cabrera', 'Fernandez', 'Ocampo', 'Valdez', 'Rivera', 'Gonzales', 'Mercado', 'Padilla', 'Estrada', 'Aguilar'];
$courses = ['BS Information Technology', 'BS Accountancy', 'BS Nursing', 'BS Education', 'BS Business Administration', 'BS Civil Engineering', 'AB Communication', 'BS Psychology', 'BS Hospitality Management', 'BS Criminology'];
$honors = ['CUM LAUDE', 'CUM LAUDE', 'CUM LAUDE', 'CUM LAUDE', 'MAGNA CUM LAUDE', 'MAGNA CUM LAUDE', 'SUMMA CUM LAUDE'];
$g = $pdo->prepare('INSERT INTO latin_honor_graduates (upload_id, school_id, graduate_name, course, latin_honor, graduation_date, graduation_year, source) VALUES (?,?,?,?,?,?,?,?)');
$used = [];
foreach ($uploads as $i => [$sid, $yr, $st, $at, $ext, $n]) {
    if ($st !== 'reviewed') {
        continue; // lists that are not saved yet have no saved records
    }
    for ($k = 0; $k < $n; $k++) {
        do {
            $name = strtoupper($last[mt_rand(0, count($last) - 1)] . ', ' . $first[mt_rand(0, count($first) - 1)] . ' ' . chr(65 + mt_rand(0, 25)) . '.');
        } while (isset($used[$name]));
        $used[$name] = true;
        $month = [3, 4, 5, 6, 6, 6, 7][mt_rand(0, 6)];
        $g->execute([$i + 1, $sid, $name, $courses[mt_rand(0, 9)], $honors[mt_rand(0, 6)], sprintf('%04d-%02d-%02d', $yr, $month, mt_rand(10, 28)), $yr, ['ai', 'ai', 'ocr', 'text', 'manual'][mt_rand(0, 4)]]);
    }
}
$grads = $pdo->query('SELECT id, graduation_date FROM latin_honor_graduates ORDER BY id')->fetchAll();

// the same person present on another school's list (for the duplicates screenshot)
$dup = $pdo->prepare("INSERT INTO latin_honor_graduates (upload_id, school_id, graduate_name, course, latin_honor, graduation_date, graduation_year, source) SELECT 7, 6, graduate_name, course, latin_honor, ?, 2026, 'ai' FROM latin_honor_graduates WHERE id = ?");
foreach ([5, 41, 77, 160, 188] as $id) {
    $dup->execute(['2026-06-' . mt_rand(10, 28), $id]);
}

// about a third have a filed application, one to four months after graduating
$ap = $pdo->prepare('INSERT INTO graduate_applications (graduate_id, applied_by, application_date) VALUES (?,?,?)');
foreach ($grads as $row) {
    if (mt_rand(1, 100) <= 35) {
        $d = new DateTime($row['graduation_date']);
        $d->modify('+' . mt_rand(20, 120) . ' days');
        if ($d > new DateTime('2026-10-12')) {
            continue;
        }
        $ap->execute([$row['id'], mt_rand(1, 2) === 1 ? 1 : 2, $d->format('Y-m-d')]);
    }
}

$k = $pdo->prepare('INSERT INTO ocr_course_keywords (keyword, created_by, created_at) VALUES (?,1,?)');
foreach (['BSCRIM' => '2026-03-04 10:00:00', 'BSHM' => '2026-03-04 10:02:00', 'BSMA' => '2026-05-19 14:10:00', 'BSPSYCH' => '2026-08-02 09:30:00'] as $kw => $at) {
    $k->execute([$kw, $at]);
}

$log = $pdo->prepare('INSERT INTO activity_log (user_id, action, details, created_at) VALUES (?,?,?,?)');
foreach ([
    [1, 'login', 'Signed in', '-1 hours'], [2, 'search', 'Searched: DELA CRUZ', '-2 hours'], [2, 'application_filed', 'Filed application for DELA CRUZ, JUAN A.', '-3 hours'],
    [1, 'pdf_upload', 'Uploaded Latin Honors 2026.pdf for Riverside College (2026)', '-1 days'], [1, 'record_created', 'Saved 110 records for Harbor Institute of Technology (2026)', '-2 days'],
    [3, 'login', 'Signed in', '-2 days'], [3, 'search', 'Searched: SANTOS', '-2 days'], [1, 'school_updated', 'Updated school Mabini Community College', '-4 days'],
    [5, 'user_created', 'Created user Ana Lopez', '-6 days'], [1, 'record_removed', 'Removed REYES, MARIA C. — wrong school list', '-6 days'], [1, 'record_restored', 'Restored REYES, MARIA C.', '-6 days'],
    [1, 'ocr_keyword_created', 'Added OCR course keyword BSPSYCH', '-8 days'], [2, 'application_filed', 'Filed application for SANTOS, ANA B.', '-9 days'], [1, 'template_upload', 'Uploaded template for Sample State University North Campus (2025)', '-12 days'],
    [5, 'login', 'Signed in', '-13 days'], [1, 'db_backup', 'Created a database backup', '-15 days'], [2, 'logout', 'Signed out', '-16 days'], [1, 'record_draft_saved', 'Saved a draft for Mabini Community College (2026)', '-18 days'],
] as [$uid, $act, $det, $off]) {
    $log->execute([$uid, $act, $det, (new DateTime("now $off"))->format('Y-m-d H:i:s')]);
}

// recycle bin: two removed records and a removed keyword
$rb = $pdo->prepare('INSERT INTO graduate_recycle_bin (original_id, upload_id, school_id, graduate_name, course, latin_honor, graduation_date, graduation_year, source, deleted_by, deleted_at, deleted_reason, deleted_via) VALUES (?,?,?,?,?,?,?,?,?,1,?,?,?)');
$rb->execute([9001, 4, 3, 'REYES, MARIA C.', 'BS Nursing', 'CUM LAUDE', '2025-06-20', 2025, 'ocr', (new DateTime('-6 days'))->format('Y-m-d H:i:s'), 'Listed under the wrong school', 'manual']);
$rb->execute([9002, 6, 5, 'LIM, DANIEL T.', 'BS Accountancy', 'MAGNA CUM LAUDE', '2026-06-12', 2026, 'ai', (new DateTime('-3 days'))->format('Y-m-d H:i:s'), 'Duplicate of an applied record', 'duplicate_cleanup']);
$ri = $pdo->prepare("INSERT INTO recycle_bin_items (item_type, original_id, label, details, payload, deleted_by, deleted_at, deleted_reason) VALUES ('ocr_keyword', 99, 'BSTEMP', 'OCR course keyword', ?, 1, ?, 'Added by mistake')");
$ri->execute([json_encode(['keyword' => 'BSTEMP', 'created_by' => 1, 'created_at' => '2026-09-01 10:00:00']), (new DateTime('-5 days'))->format('Y-m-d H:i:s')]);

// bulk update: applicants waiting for a decision (the NEEDS REVIEW list)
$bk = $pdo->prepare("INSERT INTO bulk_hge_review_items (person_key, last_name, first_name, mi, middle_name, ext_name, application_date, reason, candidate_ids, source_filename, file_row, status, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,'pending',1)");
$bk->execute([sha1('a'), 'Garcia', 'Elena', 'M', 'Mercado', '', '2026-09-14', 'ambiguous', json_encode([12, 140]), 'hge_applicants.xlsx', 18]);
$bk->execute([sha1('b'), 'Torres', 'Daniel', '', '', '', '2026-09-20', 'loose', json_encode([33]), 'hge_applicants.xlsx', 27]);
$bk->execute([sha1('c'), 'Aquino', 'Rhea', 'P', 'Padilla', 'JR', '2026-09-22', 'claimed', json_encode([58, 59]), 'hge_applicants.xlsx', 31]);

foreach (['users', 'schools', 'latin_honor_uploads', 'latin_honor_graduates', 'graduate_applications', 'activity_log'] as $t) {
    echo str_pad($t, 24), $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn(), "\n";
}
