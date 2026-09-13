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
