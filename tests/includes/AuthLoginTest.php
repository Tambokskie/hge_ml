<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;
use Tests\TestDb;

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

class AuthLoginTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
        TestDb::resetSchema();
        $pdo = TestDb::pdo();
        $pdo->exec(
            "INSERT INTO users (name, username, email, password, role, status)
             VALUES ('System Administrator', 'admin', 'admin@hge.local', '" . password_hash('secret123', PASSWORD_DEFAULT) . "', 'administrator', 'active')"
        );
        $pdo->exec(
            "INSERT INTO users (name, username, email, password, role, status)
             VALUES ('Disabled User', 'disableduser', 'disabled@hge.local', '" . password_hash('secret123', PASSWORD_DEFAULT) . "', 'user', 'disabled')"
        );
    }

    public function testAttemptLoginSucceedsWithCorrectCredentials(): void
    {
        $user = attempt_login(TestDb::pdo(), 'admin', 'secret123');

        $this->assertNotNull($user);
        $this->assertSame('admin', $user['username']);
        $this->assertSame('administrator', $user['role']);
        $this->assertArrayNotHasKey('password', $user);
    }

    public function testAttemptLoginFailsWithWrongPassword(): void
    {
        $this->assertNull(attempt_login(TestDb::pdo(), 'admin', 'wrong-password'));
    }

    public function testAttemptLoginFailsForUnknownUsername(): void
    {
        $this->assertNull(attempt_login(TestDb::pdo(), 'nobody', 'secret123'));
    }

    public function testAttemptLoginFailsForDisabledAccount(): void
    {
        $this->assertNull(attempt_login(TestDb::pdo(), 'disableduser', 'secret123'));
    }

    public function testLoginUserThenCurrentUserReturnsSessionData(): void
    {
        $user = attempt_login(TestDb::pdo(), 'admin', 'secret123');
        login_user($user);

        $current = current_user();
        $this->assertSame('admin', $current['username']);
        $this->assertSame('administrator', $current['role']);
    }

    public function testLogoutUserClearsCurrentUser(): void
    {
        $user = attempt_login(TestDb::pdo(), 'admin', 'secret123');
        login_user($user);
        logout_user();

        $this->assertNull(current_user());
    }
}
