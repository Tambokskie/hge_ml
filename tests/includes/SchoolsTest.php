<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;
use Tests\TestDb;

require_once __DIR__ . '/../../includes/schools.php';

class SchoolsTest extends TestCase
{
    protected function setUp(): void
    {
        TestDb::resetSchema();
    }

    public function testFindOrCreateSchoolCreatesNewSchool(): void
    {
        $pdo = TestDb::pdo();
        $school = find_or_create_school($pdo, 'ABC University');

        $this->assertSame('ABC University', $school['school_name']);
        $count = (int) $pdo->query("SELECT COUNT(*) FROM schools WHERE school_name = 'ABC University'")->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testFindOrCreateSchoolReusesExistingExactMatch(): void
    {
        $pdo = TestDb::pdo();
        $first = find_or_create_school($pdo, 'ABC University');
        $second = find_or_create_school($pdo, 'ABC University');

        $this->assertSame($first['id'], $second['id']);
        $count = (int) $pdo->query("SELECT COUNT(*) FROM schools")->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testFindOrCreateSchoolReusesExistingCaseInsensitiveMatch(): void
    {
        $pdo = TestDb::pdo();
        $first = find_or_create_school($pdo, 'ABC University');
        $second = find_or_create_school($pdo, 'abc university');

        $this->assertSame($first['id'], $second['id']);
        $count = (int) $pdo->query("SELECT COUNT(*) FROM schools")->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testGetActiveSchoolNamesReturnsOnlyActiveSchoolsSortedAlphabetically(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO schools (school_name, status) VALUES ('XYZ College', 'active')");
        $pdo->exec("INSERT INTO schools (school_name, status) VALUES ('ABC University', 'active')");
        $pdo->exec("INSERT INTO schools (school_name, status) VALUES ('Disabled School', 'disabled')");

        $names = get_active_school_names($pdo);

        $this->assertSame(['ABC University', 'XYZ College'], $names);
    }

    public function testGetActiveSchoolsListReturnsIdAndNameForActiveSchoolsOnly(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO schools (school_name, status) VALUES ('XYZ College', 'active')");
        $pdo->exec("INSERT INTO schools (school_name, status) VALUES ('ABC University', 'active')");
        $pdo->exec("INSERT INTO schools (school_name, status) VALUES ('Disabled School', 'disabled')");

        $schools = get_active_schools_list($pdo);

        $this->assertCount(2, $schools);
        $this->assertSame('ABC University', $schools[0]['school_name']);
        $this->assertSame('XYZ College', $schools[1]['school_name']);
        $this->assertArrayHasKey('id', $schools[0]);
    }

    public function testGetAllSchoolsWithCountsReturnsAllRegardlessOfStatus(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO schools (school_name, status) VALUES ('Active School', 'active')");
        $pdo->exec("INSERT INTO schools (school_name, status) VALUES ('Disabled School', 'disabled')");

        $schools = get_all_schools_with_counts($pdo);

        $this->assertCount(2, $schools);
        $names = array_column($schools, 'school_name');
        $this->assertContains('Active School', $names);
        $this->assertContains('Disabled School', $names);
    }

    public function testGetAllSchoolsWithCountsIncludesUploadAndGraduateCounts(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO schools (school_name) VALUES ('ABC University')");
        $pdo->exec("INSERT INTO users (name, username, email, password, role) VALUES ('Admin', 'admin', 'a@a.com', 'x', 'administrator')");
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number)
             VALUES (1, '2025', 'a.pdf', 'ABC_UNIVERSITY_2025_1.PDF', '/x/a.pdf', 10, SHA2('a',256), 1, 1)"
        );
        $pdo->exec(
            "INSERT INTO latin_honor_graduates (upload_id, school_id, graduate_name, latin_honor, graduation_date, graduation_year)
             VALUES (1, 1, 'Juan Dela Cruz', 'CUM LAUDE', '2025-06-15', 2025)"
        );
        $pdo->exec(
            "INSERT INTO latin_honor_graduates (upload_id, school_id, graduate_name, latin_honor, graduation_date, graduation_year)
             VALUES (1, 1, 'Maria Santos', 'MAGNA CUM LAUDE', '2025-06-15', 2025)"
        );

        $schools = get_all_schools_with_counts($pdo);

        $this->assertSame(1, (int) $schools[0]['upload_count']);
        $this->assertSame(2, (int) $schools[0]['graduate_count']);
    }

    public function testCreateSchoolInsertsNewSchool(): void
    {
        $pdo = TestDb::pdo();

        $result = create_school($pdo, 'New School');

        $this->assertSame('success', $result['status']);
        $this->assertArrayHasKey('id', $result);
        $count = (int) $pdo->query("SELECT COUNT(*) FROM schools WHERE school_name = 'New School'")->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testCreateSchoolRejectsEmptyName(): void
    {
        $result = create_school(TestDb::pdo(), '   ');
        $this->assertSame('error', $result['status']);
    }

    public function testCreateSchoolRejectsDuplicateName(): void
    {
        $pdo = TestDb::pdo();
        create_school($pdo, 'ABC University');

        $result = create_school($pdo, 'ABC University');

        $this->assertSame('error', $result['status']);
        $count = (int) $pdo->query("SELECT COUNT(*) FROM schools WHERE school_name = 'ABC University'")->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testRenameSchoolChangesName(): void
    {
        $pdo = TestDb::pdo();
        $created = create_school($pdo, 'Old Name');

        $result = rename_school($pdo, $created['id'], 'New Name');

        $this->assertSame('success', $result['status']);
        $name = $pdo->query("SELECT school_name FROM schools WHERE id = {$created['id']}")->fetchColumn();
        $this->assertSame('New Name', $name);
    }

    public function testRenameSchoolRejectsDuplicateName(): void
    {
        $pdo = TestDb::pdo();
        create_school($pdo, 'ABC University');
        $created = create_school($pdo, 'XYZ College');

        $result = rename_school($pdo, $created['id'], 'ABC University');

        $this->assertSame('error', $result['status']);
        $name = $pdo->query("SELECT school_name FROM schools WHERE id = {$created['id']}")->fetchColumn();
        $this->assertSame('XYZ College', $name);
    }

    public function testSetSchoolStatusTogglesStatus(): void
    {
        $pdo = TestDb::pdo();
        $created = create_school($pdo, 'ABC University');

        $result = set_school_status($pdo, $created['id'], 'disabled');

        $this->assertSame('success', $result['status']);
        $status = $pdo->query("SELECT status FROM schools WHERE id = {$created['id']}")->fetchColumn();
        $this->assertSame('disabled', $status);
    }

    public function testSetSchoolStatusRejectsInvalidStatus(): void
    {
        $result = set_school_status(TestDb::pdo(), 1, 'archived');
        $this->assertSame('error', $result['status']);
    }
}
