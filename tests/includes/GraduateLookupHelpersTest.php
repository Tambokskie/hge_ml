<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;
use Tests\TestDb;

require_once __DIR__ . '/../../includes/functions.php';

class GraduateLookupHelpersTest extends TestCase
{
    protected function setUp(): void
    {
        TestDb::resetSchema();
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO schools (school_name) VALUES ('ABC University')");
        $pdo->exec("INSERT INTO users (name, username, email, password, role) VALUES ('Admin', 'admin', 'a@a.com', 'x', 'administrator')");
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number)
             VALUES (1, '2025', 'a.pdf', 'ABC_UNIVERSITY_2025_1.PDF', '/x/a.pdf', 10, SHA2('a',256), 1, 1)"
        );
    }

    public function testGetDistinctGraduationYearsReturnsDescendingIntegers(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO latin_honor_graduates (upload_id, school_id, graduate_name, latin_honor, graduation_date, graduation_year) VALUES (1, 1, 'A', 'CUM LAUDE', '2023-06-01', 2023)");
        $pdo->exec("INSERT INTO latin_honor_graduates (upload_id, school_id, graduate_name, latin_honor, graduation_date, graduation_year) VALUES (1, 1, 'B', 'CUM LAUDE', '2025-06-01', 2025)");
        $pdo->exec("INSERT INTO latin_honor_graduates (upload_id, school_id, graduate_name, latin_honor, graduation_date, graduation_year) VALUES (1, 1, 'C', 'CUM LAUDE', '2023-06-02', 2023)");

        $years = get_distinct_graduation_years($pdo);

        $this->assertSame([2025, 2023], $years);
    }

    public function testGetDistinctGraduationYearsReturnsEmptyArrayWhenNoGraduates(): void
    {
        $this->assertSame([], get_distinct_graduation_years(TestDb::pdo()));
    }

    public function testLogActivityInsertsRowWithUserIdActionAndDetails(): void
    {
        $pdo = TestDb::pdo();

        log_activity($pdo, 1, 'search', 'name=cruz');

        $row = $pdo->query('SELECT user_id, action, details FROM activity_log ORDER BY id DESC LIMIT 1')->fetch();
        $this->assertSame(1, (int) $row['user_id']);
        $this->assertSame('search', $row['action']);
        $this->assertSame('name=cruz', $row['details']);
    }

    public function testLogActivityAllowsNullUserId(): void
    {
        $pdo = TestDb::pdo();

        log_activity($pdo, null, 'search', null);

        $row = $pdo->query('SELECT user_id, details FROM activity_log ORDER BY id DESC LIMIT 1')->fetch();
        $this->assertNull($row['user_id']);
        $this->assertNull($row['details']);
    }

    public function testLogActivityPurgesEntriesOlderThanSixMonths(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO activity_log (user_id, action, details, created_at) VALUES (1, 'login', 'old', DATE_SUB(NOW(), INTERVAL 7 MONTH))");
        $pdo->exec("INSERT INTO activity_log (user_id, action, details, created_at) VALUES (1, 'login', 'recent', DATE_SUB(NOW(), INTERVAL 1 MONTH))");

        log_activity($pdo, 1, 'search', 'name=test');

        $count = (int) $pdo->query('SELECT COUNT(*) FROM activity_log')->fetchColumn();
        $this->assertSame(2, $count);

        $oldStillExists = (int) $pdo->query("SELECT COUNT(*) FROM activity_log WHERE details = 'old'")->fetchColumn();
        $this->assertSame(0, $oldStillExists);

        $recentStillExists = (int) $pdo->query("SELECT COUNT(*) FROM activity_log WHERE details = 'recent'")->fetchColumn();
        $this->assertSame(1, $recentStillExists);
    }
}
