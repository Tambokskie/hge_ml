<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;
use Tests\TestDb;

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

class SeedAdminTest extends TestCase
{
    protected function setUp(): void
    {
        TestDb::resetSchema();
    }

    public function testCreateAdminInsertsLoginableAdministrator(): void
    {
        $pdo = TestDb::pdo();
        create_or_update_admin($pdo, 'admin', 'admin@hge.local', 'secret123');

        $user = attempt_login($pdo, 'admin', 'secret123');
        $this->assertNotNull($user);
        $this->assertSame('administrator', $user['role']);
    }

    public function testCreateAdminIsIdempotentAndUpdatesPassword(): void
    {
        $pdo = TestDb::pdo();
        create_or_update_admin($pdo, 'admin', 'admin@hge.local', 'first-password');
        create_or_update_admin($pdo, 'admin', 'admin@hge.local', 'second-password');

        $this->assertNull(attempt_login($pdo, 'admin', 'first-password'));
        $this->assertNotNull(attempt_login($pdo, 'admin', 'second-password'));

        $count = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE username = 'admin'")->fetchColumn();
        $this->assertSame(1, $count);
    }
}
