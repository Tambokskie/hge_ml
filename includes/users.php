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

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['status' => 'error', 'message' => 'PLEASE ENTER A VALID EMAIL ADDRESS.'];
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

function remove_user(PDO $pdo, int $id, int $requestingUserId, string $password): array
{
    if ($id === $requestingUserId) {
        return ['status' => 'error', 'message' => 'YOU CANNOT REMOVE YOUR OWN ACCOUNT.'];
    }

    $stmt = $pdo->prepare('SELECT password FROM users WHERE id = :id');
    $stmt->execute(['id' => $requestingUserId]);
    $hash = $stmt->fetchColumn();

    if ($hash === false || !password_verify($password, $hash)) {
        return ['status' => 'error', 'message' => 'INCORRECT PASSWORD.'];
    }

    $targetStmt = $pdo->prepare('SELECT name, role FROM users WHERE id = :id');
    $targetStmt->execute(['id' => $id]);
    $target = $targetStmt->fetch();
    if ($target === false) {
        return ['status' => 'error', 'message' => 'USER NOT FOUND.'];
    }

    if ($target['role'] === 'administrator') {
        return ['status' => 'error', 'message' => 'ADMINISTRATOR ACCOUNTS CANNOT BE REMOVED.'];
    }

    $uploadCountStmt = $pdo->prepare('SELECT COUNT(*) FROM latin_honor_uploads WHERE uploaded_by = :id');
    $uploadCountStmt->execute(['id' => $id]);
    $uploadCount = (int) $uploadCountStmt->fetchColumn();

    if ($uploadCount > 0) {
        return ['status' => 'error', 'message' => 'CANNOT REMOVE — THIS USER HAS UPLOADED ' . $uploadCount . ' FILE(S).'];
    }

    try {
        $pdo->beginTransaction();
        // activity_log.user_id is nullable specifically to allow this: the
        // audit trail survives the account being removed, just with its
        // actor anonymized, rather than blocking removal on login history
        // that virtually every account will have.
        $pdo->prepare('UPDATE activity_log SET user_id = NULL WHERE user_id = :id')->execute(['id' => $id]);
        $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $id]);
        $pdo->commit();
    } catch (\Throwable $e) {
        try {
            $pdo->rollBack();
        } catch (\Throwable $rollbackError) {
            error_log('remove_user rollback failed: ' . $rollbackError->getMessage());
        }
        error_log('remove_user failed: ' . $e->getMessage());
        return ['status' => 'error', 'message' => 'THE USER COULD NOT BE REMOVED. PLEASE TRY AGAIN.'];
    }

    return ['status' => 'success', 'message' => 'USER REMOVED.', 'name' => $target['name']];
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
