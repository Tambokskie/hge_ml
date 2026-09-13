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
}
