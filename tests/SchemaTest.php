<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

class SchemaTest extends TestCase
{
    protected function setUp(): void
    {
        TestDb::resetSchema();
    }

    public function testAllTablesExist(): void
    {
        $pdo = TestDb::pdo();
        $tables = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);

        $this->assertEqualsCanonicalizing(
            ['users', 'schools', 'latin_honor_uploads', 'latin_honor_graduates', 'activity_log'],
            $tables
        );
    }

    public function testGraduateMustReferenceExistingUpload(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO schools (school_name) VALUES ('TEST UNIVERSITY')");

        $this->expectException(\PDOException::class);
        $pdo->exec(
            "INSERT INTO latin_honor_graduates (upload_id, school_id, graduate_name, latin_honor, graduation_year)
             VALUES (999, 1, 'JUAN DELA CRUZ', 'CUM LAUDE', 2025)"
        );
    }

    public function testGraduateNameHasFulltextIndex(): void
    {
        $pdo = TestDb::pdo();
        $indexes = $pdo->query("SHOW INDEX FROM latin_honor_graduates WHERE Key_name = 'ft_graduate_name'")->fetchAll();

        $this->assertNotEmpty($indexes);
        $this->assertSame('FULLTEXT', $indexes[0]['Index_type']);
    }
}
