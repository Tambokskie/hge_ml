<?php

function find_or_create_school(PDO $pdo, string $schoolName): array
{
    $name = trim($schoolName);

    $stmt = $pdo->prepare('SELECT id, school_name FROM schools WHERE school_name = :name LIMIT 1');
    $stmt->execute(['name' => $name]);
    $row = $stmt->fetch();

    if ($row === false) {
        $stmt = $pdo->prepare('SELECT id, school_name FROM schools WHERE LOWER(school_name) = LOWER(:name) LIMIT 1');
        $stmt->execute(['name' => $name]);
        $row = $stmt->fetch();
    }

    if ($row !== false) {
        return [
            'id' => (int) $row['id'],
            'school_name' => $row['school_name'],
        ];
    }

    try {
        $stmt = $pdo->prepare('INSERT INTO schools (school_name) VALUES (:name)');
        $stmt->execute(['name' => $name]);

        return [
            'id' => (int) $pdo->lastInsertId(),
            'school_name' => $name,
        ];
    } catch (PDOException $e) {
        if ($e->getCode() !== '23000') {
            throw $e;
        }
    }

    // Race fallback: another request inserted the same name between our SELECT and INSERT.
    $stmt = $pdo->prepare('SELECT id, school_name FROM schools WHERE school_name = :name LIMIT 1');
    $stmt->execute(['name' => $name]);
    $row = $stmt->fetch();

    return [
        'id' => (int) $row['id'],
        'school_name' => $row['school_name'],
    ];
}

function get_school_by_id(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT id, school_name, campus FROM schools WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    if ($row === false) {
        return null;
    }

    return [
        'id' => (int) $row['id'],
        'school_name' => $row['school_name'],
        'campus' => $row['campus'],
    ];
}

/**
 * Exact (case/whitespace-insensitive) school_name + campus lookup, used by
 * the "Upload From Template" flow: unlike find_or_create_school() (used by
 * the PDF upload form, which will create a school on the fly), a template
 * upload must never silently create a school — it must point at the record
 * an administrator already added, so a typo or unmigrated school surfaces as
 * an actionable error instead of a silent duplicate school row.
 */
function find_school_by_exact_name_campus(PDO $pdo, string $schoolName, string $campus): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, school_name, campus FROM schools
         WHERE UPPER(TRIM(school_name)) = UPPER(TRIM(:name)) AND UPPER(TRIM(campus)) = UPPER(TRIM(:campus))
         LIMIT 1'
    );
    $stmt->execute(['name' => $schoolName, 'campus' => $campus]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

function get_active_schools_list(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT id, school_name, campus FROM schools WHERE status = 'active' ORDER BY school_name ASC");
    return $stmt->fetchAll();
}

function get_schools_with_uploads(PDO $pdo): array
{
    $sql = "SELECT s.school_name, s.campus, s.address
            FROM schools s
            WHERE EXISTS (SELECT 1 FROM latin_honor_uploads u WHERE u.school_id = s.id)
            ORDER BY s.school_name ASC";
    $stmt = $pdo->query($sql);
    return $stmt->fetchAll();
}

function get_all_schools_with_counts(PDO $pdo): array
{
    $sql = "SELECT s.id, s.school_name, s.address, s.campus, s.status,
                   (SELECT COUNT(*) FROM latin_honor_uploads u WHERE u.school_id = s.id) AS upload_count,
                   (SELECT COUNT(*) FROM latin_honor_graduates g WHERE g.school_id = s.id) AS graduate_count
            FROM schools s
            ORDER BY s.school_name ASC";
    $stmt = $pdo->query($sql);
    return $stmt->fetchAll();
}

function create_school(PDO $pdo, string $name, string $address, string $campus = ''): array
{
    $name = mb_strtoupper(trim($name));
    $address = mb_strtoupper(trim($address));
    $campus = mb_strtoupper(trim($campus));

    if ($name === '') {
        return ['status' => 'error', 'message' => 'SCHOOL NAME IS REQUIRED.'];
    }

    if (mb_strlen($name) > 255) {
        return ['status' => 'error', 'message' => 'SCHOOL NAME IS TOO LONG.'];
    }

    if ($address === '') {
        return ['status' => 'error', 'message' => 'SCHOOL ADDRESS IS REQUIRED.'];
    }

    if (mb_strlen($address) > 500) {
        return ['status' => 'error', 'message' => 'SCHOOL ADDRESS IS TOO LONG.'];
    }

    if (mb_strlen($campus) > 255) {
        return ['status' => 'error', 'message' => 'CAMPUS IS TOO LONG.'];
    }

    try {
        $stmt = $pdo->prepare('INSERT INTO schools (school_name, address, campus) VALUES (:name, :address, :campus)');
        $stmt->execute(['name' => $name, 'address' => $address, 'campus' => $campus]);
    } catch (\PDOException $e) {
        if ($e->getCode() === '23000') {
            return ['status' => 'error', 'message' => 'A SCHOOL WITH THIS NAME AND CAMPUS ALREADY EXISTS.'];
        }
        throw $e;
    }

    return ['status' => 'success', 'message' => 'SCHOOL CREATED SUCCESSFULLY.', 'id' => (int) $pdo->lastInsertId()];
}

function rename_school(PDO $pdo, int $id, string $newName, string $address, string $campus = ''): array
{
    $newName = mb_strtoupper(trim($newName));
    $address = mb_strtoupper(trim($address));
    $campus = mb_strtoupper(trim($campus));

    if ($newName === '') {
        return ['status' => 'error', 'message' => 'SCHOOL NAME IS REQUIRED.'];
    }

    if (mb_strlen($newName) > 255) {
        return ['status' => 'error', 'message' => 'SCHOOL NAME IS TOO LONG.'];
    }

    if ($address === '') {
        return ['status' => 'error', 'message' => 'SCHOOL ADDRESS IS REQUIRED.'];
    }

    if (mb_strlen($address) > 500) {
        return ['status' => 'error', 'message' => 'SCHOOL ADDRESS IS TOO LONG.'];
    }

    if (mb_strlen($campus) > 255) {
        return ['status' => 'error', 'message' => 'CAMPUS IS TOO LONG.'];
    }

    try {
        $stmt = $pdo->prepare('UPDATE schools SET school_name = :name, address = :address, campus = :campus WHERE id = :id');
        $stmt->execute(['name' => $newName, 'address' => $address, 'campus' => $campus, 'id' => $id]);
    } catch (\PDOException $e) {
        if ($e->getCode() === '23000') {
            return ['status' => 'error', 'message' => 'A SCHOOL WITH THIS NAME AND CAMPUS ALREADY EXISTS.'];
        }
        throw $e;
    }

    return ['status' => 'success', 'message' => 'SCHOOL RENAMED.'];
}

function set_school_status(PDO $pdo, int $id, string $status): array
{
    if (!in_array($status, ['active', 'disabled'], true)) {
        return ['status' => 'error', 'message' => 'INVALID STATUS.'];
    }

    $stmt = $pdo->prepare('UPDATE schools SET status = :status WHERE id = :id');
    $stmt->execute(['status' => $status, 'id' => $id]);

    return ['status' => 'success', 'message' => 'STATUS UPDATED.'];
}

function remove_school(PDO $pdo, int $id, int $requestingUserId, string $password): array
{
    $stmt = $pdo->prepare('SELECT password FROM users WHERE id = :id');
    $stmt->execute(['id' => $requestingUserId]);
    $hash = $stmt->fetchColumn();

    if ($hash === false || !password_verify($password, $hash)) {
        return ['status' => 'error', 'message' => 'INCORRECT PASSWORD.'];
    }

    $existsStmt = $pdo->prepare('SELECT school_name FROM schools WHERE id = :id');
    $existsStmt->execute(['id' => $id]);
    $schoolName = $existsStmt->fetchColumn();
    if ($schoolName === false) {
        return ['status' => 'error', 'message' => 'SCHOOL NOT FOUND.'];
    }

    $countStmt = $pdo->prepare(
        'SELECT
            (SELECT COUNT(*) FROM latin_honor_uploads WHERE school_id = :id1) AS upload_count,
            (SELECT COUNT(*) FROM latin_honor_graduates WHERE school_id = :id2) AS graduate_count'
    );
    $countStmt->execute(['id1' => $id, 'id2' => $id]);
    $counts = $countStmt->fetch();
    $uploadCount = (int) $counts['upload_count'];
    $graduateCount = (int) $counts['graduate_count'];

    if ($uploadCount > 0 || $graduateCount > 0) {
        return ['status' => 'error', 'message' => 'CANNOT REMOVE — THIS SCHOOL HAS ' . $uploadCount . ' UPLOAD(S) AND ' . $graduateCount . ' GRADUATE RECORD(S).'];
    }

    $deleteStmt = $pdo->prepare('DELETE FROM schools WHERE id = :id');
    $deleteStmt->execute(['id' => $id]);

    return ['status' => 'success', 'message' => 'SCHOOL REMOVED.', 'school_name' => $schoolName];
}
