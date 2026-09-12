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
