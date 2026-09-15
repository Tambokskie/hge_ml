<?php

function find_or_create_school(PDO $pdo, string $schoolName): array
{
    $name = trim($schoolName);

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

    $stmt = $pdo->prepare('SELECT id, school_name FROM schools WHERE school_name = :name LIMIT 1');
    $stmt->execute(['name' => $name]);
    $row = $stmt->fetch();

    if ($row === false) {
        $stmt = $pdo->prepare('SELECT id, school_name FROM schools WHERE LOWER(school_name) = LOWER(:name) LIMIT 1');
        $stmt->execute(['name' => $name]);
        $row = $stmt->fetch();
    }

    return [
        'id' => (int) $row['id'],
        'school_name' => $row['school_name'],
    ];
}

function get_active_school_names(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT school_name FROM schools WHERE status = 'active' ORDER BY school_name ASC");
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function get_active_schools_list(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT id, school_name FROM schools WHERE status = 'active' ORDER BY school_name ASC");
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

function create_school(PDO $pdo, string $name, string $address = '', string $campus = ''): array
{
    $name = trim($name);
    $address = trim($address);
    $campus = trim($campus);

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

function rename_school(PDO $pdo, int $id, string $newName, string $address = '', string $campus = ''): array
{
    $newName = trim($newName);
    $address = trim($address);
    $campus = trim($campus);

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
