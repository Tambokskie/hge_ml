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
    $sql = "SELECT s.id, s.school_name, s.status,
                   (SELECT COUNT(*) FROM latin_honor_uploads u WHERE u.school_id = s.id) AS upload_count,
                   (SELECT COUNT(*) FROM latin_honor_graduates g WHERE g.school_id = s.id) AS graduate_count
            FROM schools s
            ORDER BY s.school_name ASC";
    $stmt = $pdo->query($sql);
    return $stmt->fetchAll();
}

function create_school(PDO $pdo, string $name): array
{
    $name = trim($name);

    if ($name === '') {
        return ['status' => 'error', 'message' => 'SCHOOL NAME IS REQUIRED.'];
    }

    if (mb_strlen($name) > 255) {
        return ['status' => 'error', 'message' => 'SCHOOL NAME IS TOO LONG.'];
    }

    try {
        $stmt = $pdo->prepare('INSERT INTO schools (school_name) VALUES (:name)');
        $stmt->execute(['name' => $name]);
    } catch (\PDOException $e) {
        if ($e->getCode() === '23000') {
            return ['status' => 'error', 'message' => 'A SCHOOL WITH THIS NAME ALREADY EXISTS.'];
        }
        throw $e;
    }

    return ['status' => 'success', 'message' => 'SCHOOL CREATED SUCCESSFULLY.', 'id' => (int) $pdo->lastInsertId()];
}

function rename_school(PDO $pdo, int $id, string $newName): array
{
    $newName = trim($newName);

    if ($newName === '') {
        return ['status' => 'error', 'message' => 'SCHOOL NAME IS REQUIRED.'];
    }

    if (mb_strlen($newName) > 255) {
        return ['status' => 'error', 'message' => 'SCHOOL NAME IS TOO LONG.'];
    }

    try {
        $stmt = $pdo->prepare('UPDATE schools SET school_name = :name WHERE id = :id');
        $stmt->execute(['name' => $newName, 'id' => $id]);
    } catch (\PDOException $e) {
        if ($e->getCode() === '23000') {
            return ['status' => 'error', 'message' => 'A SCHOOL WITH THIS NAME ALREADY EXISTS.'];
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
