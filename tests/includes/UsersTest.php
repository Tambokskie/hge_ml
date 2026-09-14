<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;
use Tests\TestDb;

require_once __DIR__ . '/../../includes/users.php';
require_once __DIR__ . '/../../includes/auth.php';

class UsersTest extends TestCase
{
    protected function setUp(): void
    {
        TestDb::resetSchema();
    }

    public function testGetAllUsersReturnsAllOrderedByName(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO users (name, username, email, password, role) VALUES ('Zed Cruz', 'zed', 'zed@a.com', 'x', 'user')");
        $pdo->exec("INSERT INTO users (name, username, email, password, role) VALUES ('Ana Reyes', 'ana', 'ana@a.com', 'x', 'administrator')");

        $users = get_all_users($pdo);

        $this->assertCount(2, $users);
        $this->assertSame('Ana Reyes', $users[0]['name']);
        $this->assertSame('Zed Cruz', $users[1]['name']);
        $this->assertArrayNotHasKey('password', $users[0]);
    }

    public function testCreateUserInsertsNewUser(): void
    {
        $pdo = TestDb::pdo();

        $result = create_user($pdo, [
            'name' => 'Ana Reyes',
            'username' => 'ana',
            'email' => 'ana@a.com',
            'password' => 'secret123',
            'role' => 'user',
        ]);

        $this->assertSame('success', $result['status']);
        $this->assertArrayHasKey('id', $result);

        $count = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE username = 'ana'")->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testCreateUserAllowsLoginAfterCreation(): void
    {
        $pdo = TestDb::pdo();
        create_user($pdo, ['name' => 'Ana Reyes', 'username' => 'ana', 'email' => 'ana@a.com', 'password' => 'secret123', 'role' => 'user']);

        $user = attempt_login($pdo, 'ana', 'secret123');
        $this->assertNotNull($user);
        $this->assertSame('user', $user['role']);
    }

    public function testCreateUserRejectsMissingFields(): void
    {
        $result = create_user(TestDb::pdo(), ['name' => '', 'username' => 'ana', 'email' => 'ana@a.com', 'password' => 'secret123']);
        $this->assertSame('error', $result['status']);
    }

    public function testCreateUserRejectsShortPassword(): void
    {
        $result = create_user(TestDb::pdo(), ['name' => 'Ana', 'username' => 'ana', 'email' => 'ana@a.com', 'password' => 'short']);
        $this->assertSame('error', $result['status']);
    }

    public function testCreateUserRejectsInvalidRole(): void
    {
        $result = create_user(TestDb::pdo(), ['name' => 'Ana', 'username' => 'ana', 'email' => 'ana@a.com', 'password' => 'secret123', 'role' => 'superadmin']);
        $this->assertSame('error', $result['status']);
    }

    public function testCreateUserRejectsDuplicateUsername(): void
    {
        $pdo = TestDb::pdo();
        create_user($pdo, ['name' => 'Ana', 'username' => 'ana', 'email' => 'ana1@a.com', 'password' => 'secret123']);

        $result = create_user($pdo, ['name' => 'Another Ana', 'username' => 'ana', 'email' => 'ana2@a.com', 'password' => 'secret123']);

        $this->assertSame('error', $result['status']);
        $count = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE username = 'ana'")->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testCreateUserRejectsDuplicateEmail(): void
    {
        $pdo = TestDb::pdo();
        create_user($pdo, ['name' => 'Ana', 'username' => 'ana1', 'email' => 'ana@a.com', 'password' => 'secret123']);

        $result = create_user($pdo, ['name' => 'Another', 'username' => 'ana2', 'email' => 'ana@a.com', 'password' => 'secret123']);

        $this->assertSame('error', $result['status']);
    }

    public function testUpdateUserRoleChangesRole(): void
    {
        $pdo = TestDb::pdo();
        $created = create_user($pdo, ['name' => 'Ana', 'username' => 'ana', 'email' => 'ana@a.com', 'password' => 'secret123', 'role' => 'user']);

        $result = update_user_role($pdo, $created['id'], 'administrator');

        $this->assertSame('success', $result['status']);
        $role = $pdo->query("SELECT role FROM users WHERE username = 'ana'")->fetchColumn();
        $this->assertSame('administrator', $role);
    }

    public function testUpdateUserRoleRejectsInvalidRole(): void
    {
        $result = update_user_role(TestDb::pdo(), 1, 'superadmin');
        $this->assertSame('error', $result['status']);
    }

    public function testSetUserStatusTogglesStatus(): void
    {
        $pdo = TestDb::pdo();
        $created = create_user($pdo, ['name' => 'Ana', 'username' => 'ana', 'email' => 'ana@a.com', 'password' => 'secret123']);

        $result = set_user_status($pdo, $created['id'], 'disabled');

        $this->assertSame('success', $result['status']);
        $this->assertNull(attempt_login($pdo, 'ana', 'secret123'));
    }

    public function testSetUserStatusRejectsInvalidStatus(): void
    {
        $result = set_user_status(TestDb::pdo(), 1, 'archived');
        $this->assertSame('error', $result['status']);
    }

    public function testResetUserPasswordChangesPasswordAndAllowsLogin(): void
    {
        $pdo = TestDb::pdo();
        $created = create_user($pdo, ['name' => 'Ana', 'username' => 'ana', 'email' => 'ana@a.com', 'password' => 'old-password']);

        $result = reset_user_password($pdo, $created['id'], 'new-password-123');

        $this->assertSame('success', $result['status']);
        $this->assertNull(attempt_login($pdo, 'ana', 'old-password'));
        $this->assertNotNull(attempt_login($pdo, 'ana', 'new-password-123'));
    }

    public function testResetUserPasswordRejectsShortPassword(): void
    {
        $result = reset_user_password(TestDb::pdo(), 1, 'short');
        $this->assertSame('error', $result['status']);
    }
}
