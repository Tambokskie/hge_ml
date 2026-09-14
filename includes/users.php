<?php

function get_all_users(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT id, name, username, email, role, status FROM users ORDER BY name ASC');
    return $stmt->fetchAll();
}

function create_user(PDO $pdo, array $data): array
{
    $name = trim((string) ($data['name'] ?? ''));
    $username = trim((string) ($data['username'] ?? ''));
    $email = trim((string) ($data['email'] ?? ''));
    $password = (string) ($data['password'] ?? '');
    $role = (string) ($data['role'] ?? 'user');

    if ($name === '' || $username === '' || $email === '' || $password === '') {
        return ['status' => 'error', 'message' => 'ALL FIELDS ARE REQUIRED.'];
    }

    if (mb_strlen($password) < 8) {
        return ['status' => 'error', 'message' => 'PASSWORD MUST BE AT LEAST 8 CHARACTERS.'];
    }

    if (!in_array($role, ['administrator', 'user'], true)) {
        return ['status' => 'error', 'message' => 'INVALID ROLE.'];
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO users (name, username, email, password, role) VALUES (:name, :username, :email, :password, :role)'
        );
        $stmt->execute([
            'name' => $name,
            'username' => $username,
            'email' => $email,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $role,
        ]);
    } catch (\PDOException $e) {
        if ($e->getCode() === '23000') {
            return ['status' => 'error', 'message' => 'A USER WITH THIS USERNAME OR EMAIL ALREADY EXISTS.'];
        }
        throw $e;
    }

    return ['status' => 'success', 'message' => 'USER CREATED SUCCESSFULLY.', 'id' => (int) $pdo->lastInsertId()];
}

function update_user_role(PDO $pdo, int $id, string $role): array
{
    if (!in_array($role, ['administrator', 'user'], true)) {
        return ['status' => 'error', 'message' => 'INVALID ROLE.'];
    }

    $stmt = $pdo->prepare('UPDATE users SET role = :role WHERE id = :id');
    $stmt->execute(['role' => $role, 'id' => $id]);

    return ['status' => 'success', 'message' => 'ROLE UPDATED.'];
}

function set_user_status(PDO $pdo, int $id, string $status): array
{
    if (!in_array($status, ['active', 'disabled'], true)) {
        return ['status' => 'error', 'message' => 'INVALID STATUS.'];
    }

    $stmt = $pdo->prepare('UPDATE users SET status = :status WHERE id = :id');
    $stmt->execute(['status' => $status, 'id' => $id]);

    return ['status' => 'success', 'message' => 'STATUS UPDATED.'];
}

function reset_user_password(PDO $pdo, int $id, string $newPassword): array
{
    if (mb_strlen($newPassword) < 8) {
        return ['status' => 'error', 'message' => 'PASSWORD MUST BE AT LEAST 8 CHARACTERS.'];
    }

    $stmt = $pdo->prepare('UPDATE users SET password = :password WHERE id = :id');
    $stmt->execute(['password' => password_hash($newPassword, PASSWORD_DEFAULT), 'id' => $id]);

    return ['status' => 'success', 'message' => 'PASSWORD RESET SUCCESSFULLY.'];
}
