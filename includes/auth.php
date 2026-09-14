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

    // A fixed, pre-generated bcrypt hash with no matching plaintext password.
    // Used only to keep password_verify()'s timing identical whether or not
    // the username exists, closing a username-enumeration side channel.
    $dummyHash = '$2y$10$237rIx3i.u4rgcupmeT9IeWgfVojp/ALex2FJywvBWhCDVYo0GCo.';
    $hashToVerify = ($user && $user['status'] === 'active') ? $user['password'] : $dummyHash;
    $passwordValid = password_verify($password, $hashToVerify);

    if (!$user || $user['status'] !== 'active' || !$passwordValid) {
        return null;
    }

    unset($user['password']);
    return $user;
}

function login_user(array $user): void
{
    ensure_session();
    session_regenerate_id(true);
    unset($_SESSION['csrf_token']);
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

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }

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
