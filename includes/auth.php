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

function fetch_current_role_and_status(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare('SELECT role, status FROM users WHERE id = :id');
    $stmt->execute(['id' => $userId]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
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
    // NOTE: this hash's cost factor (10) currently matches PASSWORD_DEFAULT's
    // cost on this PHP build, which is why timing parity holds. If PHP's
    // default algorithm/cost ever changes, this literal must be regenerated
    // to match, or the timing side-channel silently reopens.
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
    $_SESSION['last_activity'] = time();
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
    $user = current_user();
    if ($user === null) {
        redirect(APP_BASE_URL . '/login.php');
    }

    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_IDLE_TIMEOUT) {
        logout_user();
        redirect(APP_BASE_URL . '/login.php?timeout=1');
    }
    $_SESSION['last_activity'] = time();

    $fresh = fetch_current_role_and_status(get_pdo(), (int) $user['id']);

    if ($fresh === null || $fresh['status'] !== 'active') {
        logout_user();
        redirect(APP_BASE_URL . '/login.php');
    }

    if ($fresh['role'] !== $user['role']) {
        $_SESSION['user']['role'] = $fresh['role'];
    }
}

function require_role(string $role): void
{
    require_login();
    $user = current_user();
    if (!user_has_role($user, $role)) {
        http_response_code(403);
        die('YOU DO NOT HAVE PERMISSION TO ACCESS THIS PAGE.');
    }
}

/**
 * Sensitive settings pages beyond require_role() (e.g. OCR course keywords)
 * are gated behind a fresh credential re-check, the same step-up pattern
 * CLAUDE.md documents for api/backup_database.php — a valid session + role
 * isn't enough. Destinations are a fixed whitelist, never built from raw
 * request input, so `next` can't be turned into an open redirect or a way
 * to name an arbitrary purpose.
 */
function step_up_destinations(): array
{
    return [
        'ocr_courses' => ['purpose' => 'ocr_courses', 'path' => '/admin/ocr_courses.php', 'label' => 'OCR COURSE KEYWORDS'],
    ];
}

function has_step_up(string $purpose): bool
{
    ensure_session();
    $stepUp = $_SESSION['step_up'] ?? null;

    return is_array($stepUp)
        && ($stepUp['purpose'] ?? null) === $purpose
        && ($stepUp['expires_at'] ?? 0) >= time();
}

function grant_step_up(string $purpose, int $ttlSeconds = 600): void
{
    ensure_session();
    $_SESSION['step_up'] = [
        'purpose' => $purpose,
        'expires_at' => time() + $ttlSeconds,
    ];
}

/**
 * Page-load gate: denies direct/bookmarked/typed-URL access to a step-up
 * protected admin page whenever the session doesn't already hold a valid,
 * unexpired grant for that exact purpose — redirecting to the credential
 * check instead of ever rendering the protected page's content.
 */
function require_step_up(string $purpose, string $nextKey): void
{
    if (!has_step_up($purpose)) {
        redirect(APP_BASE_URL . '/admin/verify_identity.php?next=' . urlencode($nextKey));
    }
}
