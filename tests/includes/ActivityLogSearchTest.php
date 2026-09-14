<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;
use Tests\TestDb;

require_once __DIR__ . '/../../includes/activity.php';

class ActivityLogSearchTest extends TestCase
{
    protected function setUp(): void
    {
        TestDb::resetSchema();
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO users (name, username, email, password, role) VALUES ('Ana Reyes', 'ana', 'ana@a.com', 'x', 'administrator')");
        $pdo->exec("INSERT INTO users (name, username, email, password, role) VALUES ('Ben Cruz', 'ben', 'ben@a.com', 'x', 'user')");
    }

    private function insertLogRow(?int $userId, string $action, ?string $details, string $createdAt): void
    {
        $stmt = TestDb::pdo()->prepare(
            'INSERT INTO activity_log (user_id, action, details, created_at) VALUES (:user_id, :action, :details, :created_at)'
        );
        $stmt->execute(['user_id' => $userId, 'action' => $action, 'details' => $details, 'created_at' => $createdAt]);
    }

    public function testFiltersByUserId(): void
    {
        $this->insertLogRow(1, 'login', null, '2026-01-01 10:00:00');
        $this->insertLogRow(2, 'login', null, '2026-01-01 11:00:00');

        $result = search_activity_log(TestDb::pdo(), ['user_id' => 1], 1);

        $this->assertCount(1, $result['rows']);
        $this->assertSame('ana', $result['rows'][0]['username']);
    }

    public function testFiltersByAction(): void
    {
        $this->insertLogRow(1, 'login', null, '2026-01-01 10:00:00');
        $this->insertLogRow(1, 'logout', null, '2026-01-01 11:00:00');

        $result = search_activity_log(TestDb::pdo(), ['action' => 'logout'], 1);

        $this->assertCount(1, $result['rows']);
        $this->assertSame('logout', $result['rows'][0]['action']);
    }

    public function testFiltersByDateRange(): void
    {
        $this->insertLogRow(1, 'login', null, '2026-01-01 10:00:00');
        $this->insertLogRow(1, 'login', null, '2026-03-01 10:00:00');
        $this->insertLogRow(1, 'login', null, '2026-06-01 10:00:00');

        $result = search_activity_log(TestDb::pdo(), ['date_from' => '2026-02-01', 'date_to' => '2026-04-01'], 1);

        $this->assertCount(1, $result['rows']);
        $this->assertSame('2026-03-01 10:00:00', $result['rows'][0]['created_at']);
    }

    public function testCombinesFiltersWithAnd(): void
    {
        $this->insertLogRow(1, 'login', null, '2026-01-01 10:00:00');
        $this->insertLogRow(2, 'login', null, '2026-01-01 11:00:00');
        $this->insertLogRow(1, 'logout', null, '2026-01-01 12:00:00');

        $result = search_activity_log(TestDb::pdo(), ['user_id' => 1, 'action' => 'login'], 1);

        $this->assertCount(1, $result['rows']);
    }

    public function testReturnsRowsMostRecentFirst(): void
    {
        $this->insertLogRow(1, 'login', null, '2026-01-01 10:00:00');
        $this->insertLogRow(1, 'logout', null, '2026-01-02 10:00:00');

        $result = search_activity_log(TestDb::pdo(), [], 1);

        $this->assertSame('logout', $result['rows'][0]['action']);
        $this->assertSame('login', $result['rows'][1]['action']);
    }

    public function testHandlesNullUserIdAsSystemEvent(): void
    {
        $this->insertLogRow(null, 'login', null, '2026-01-01 10:00:00');

        $result = search_activity_log(TestDb::pdo(), [], 1);

        $this->assertNull($result['rows'][0]['user_id']);
        $this->assertNull($result['rows'][0]['user_name']);
    }

    public function testPaginatesResults(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->insertLogRow(1, 'login', null, "2026-01-0{$i} 10:00:00");
        }

        $page1 = search_activity_log(TestDb::pdo(), [], 1, 2);
        $page2 = search_activity_log(TestDb::pdo(), [], 2, 2);

        $this->assertCount(2, $page1['rows']);
        $this->assertCount(1, $page2['rows']);
        $this->assertSame(3, $page1['pagination']['total_rows']);
    }
}
