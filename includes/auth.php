<?php

function csrf_token(): string
{
    ensure_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_verify(?string $token): bool
{
    ensure_session();
    return is_string($token) && isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function user_has_role(?array $user, string $role): bool
{
    if ($user === null) {
        return false;
    }
    return $user['role'] === $role || $user['role'] === 'administrator';
}

function current_user(): ?array
{
    ensure_session();
    return $_SESSION['user'] ?? null;
}

function attempt_login(PDO $pdo, string $username, string $password): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, name, username, email, password, role, status FROM users WHERE username = :username LIMIT 1'
    );
    $stmt->execute(['username' => $username]);
    $user = $stmt->fetch();

    if (!$user || $user['status'] !== 'active') {
        return null;
    }

    if (!password_verify($password, $user['password'])) {
        return null;
    }

    unset($user['password']);
    return $user;
}

function login_user(array $user): void
{
    ensure_session();
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id' => $user['id'],
        'name' => $user['name'],
        'username' => $user['username'],
        'role' => $user['role'],
    ];
}

function logout_user(): void
{
    ensure_session();
    $_SESSION = [];
    session_destroy();
}

function require_login(): void
{
    if (current_user() === null) {
        redirect(APP_BASE_URL . '/login.php');
    }
}

function require_role(string $role): void
{
    $user = current_user();
    if ($user === null) {
        redirect(APP_BASE_URL . '/login.php');
    }
    if (!user_has_role($user, $role)) {
        http_response_code(403);
        die('YOU DO NOT HAVE PERMISSION TO ACCESS THIS PAGE.');
    }
}
