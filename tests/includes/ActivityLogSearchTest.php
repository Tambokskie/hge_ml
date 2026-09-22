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

    public function testGetRecentActivityExcludesSearchEntries(): void
    {
        $this->insertLogRow(1, 'login', null, '2026-01-01 10:00:00');
        $this->insertLogRow(1, 'search', null, '2026-01-01 11:00:00');
        $this->insertLogRow(1, 'pdf_upload', null, '2026-01-01 12:00:00');

        $rows = get_recent_activity(TestDb::pdo(), 6);

        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertNotSame('search', $row['action']);
        }
    }

    public function testGetRecentActivityOrdersMostRecentFirstAndRespectsLimit(): void
    {
        $this->insertLogRow(1, 'login', null, '2026-01-01 10:00:00');
        $this->insertLogRow(1, 'logout', null, '2026-01-02 10:00:00');
        $this->insertLogRow(1, 'pdf_upload', null, '2026-01-03 10:00:00');

        $rows = get_recent_activity(TestDb::pdo(), 2);

        $this->assertCount(2, $rows);
        $this->assertSame('pdf_upload', $rows[0]['action']);
        $this->assertSame('logout', $rows[1]['action']);
    }

    public function testGetRecentActivityHandlesNullUserIdAsSystemEvent(): void
    {
        $this->insertLogRow(null, 'db_backup', null, '2026-01-01 10:00:00');

        $rows = get_recent_activity(TestDb::pdo(), 6);

        $this->assertNull($rows[0]['user_name']);
    }

    public function testActivityIconPathReturnsDistinctPathsPerActionGroup(): void
    {
        $actions = [
            'login', 'logout', 'pdf_upload', 'pdf_delete',
            'record_created', 'record_updated', 'record_removed',
            'user_created', 'user_updated', 'user_removed',
            'school_created', 'school_updated', 'school_removed',
            'application_filed', 'db_backup',
        ];

        foreach ($actions as $action) {
            $this->assertNotSame('', activity_icon_path($action));
        }

        $this->assertSame(activity_icon_path('login'), activity_icon_path('logout'));
        $this->assertSame(activity_icon_path('user_created'), activity_icon_path('user_removed'));
        $this->assertNotSame(activity_icon_path('login'), activity_icon_path('pdf_upload'));
    }

    private function insertGraduate(string $name): int
    {
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO schools (school_name) VALUES ('ABC UNIVERSITY')");
        $schoolId = (int) $pdo->lastInsertId();
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, extraction_status, records_extracted)
             VALUES ({$schoolId}, '2025', 'list.pdf', 'stored.pdf', '/storage/pdfs/stored.pdf', 100, SHA2(RAND(),256), 1, 'reviewed', 1)"
        );
        $uploadId = (int) $pdo->lastInsertId();
        $stmt = $pdo->prepare(
            'INSERT INTO latin_honor_graduates (upload_id, school_id, graduate_name, latin_honor, graduation_year) VALUES (:upload_id, :school_id, :name, :honor, :year)'
        );
        $stmt->execute(['upload_id' => $uploadId, 'school_id' => $schoolId, 'name' => $name, 'honor' => 'CUM LAUDE', 'year' => 2025]);

        return (int) $pdo->lastInsertId();
    }

    public function testGetRecentActivityResolvesLegacyGraduateIdToName(): void
    {
        $graduateId = $this->insertGraduate('Juan Dela Cruz');
        $this->insertLogRow(1, 'application_filed', 'graduate_id=' . $graduateId, '2026-01-01 10:00:00');

        $rows = get_recent_activity(TestDb::pdo(), 6);

        $this->assertSame('graduate_name=Juan Dela Cruz', $rows[0]['details']);
    }

    public function testGetRecentActivityLegacyGraduateIdFallsBackWhenGraduateDeleted(): void
    {
        $this->insertLogRow(1, 'application_filed', 'graduate_id=999999', '2026-01-01 10:00:00');

        $rows = get_recent_activity(TestDb::pdo(), 6);

        $this->assertSame('graduate_name=UNKNOWN (RECORD DELETED)', $rows[0]['details']);
    }

    public function testGetRecentActivityLeavesNewFormatDetailsUnchanged(): void
    {
        $this->insertLogRow(1, 'application_filed', 'graduate_name=Maria Santos', '2026-01-01 10:00:00');

        $rows = get_recent_activity(TestDb::pdo(), 6);

        $this->assertSame('graduate_name=Maria Santos', $rows[0]['details']);
    }

    public function testGetRecentActivityLeavesUnrelatedDetailsUnchanged(): void
    {
        $this->insertLogRow(1, 'pdf_upload', 'upload_id=42', '2026-01-01 10:00:00');

        $rows = get_recent_activity(TestDb::pdo(), 6);

        $this->assertSame('upload_id=42', $rows[0]['details']);
    }

    public function testSearchActivityLogAlsoResolvesLegacyGraduateIdToName(): void
    {
        $graduateId = $this->insertGraduate('Pedro Reyes');
        $this->insertLogRow(1, 'record_updated', 'graduate_id=' . $graduateId, '2026-01-01 10:00:00');

        $result = search_activity_log(TestDb::pdo(), [], 1);

        $this->assertSame('graduate_name=Pedro Reyes', $result['rows'][0]['details']);
    }
}
