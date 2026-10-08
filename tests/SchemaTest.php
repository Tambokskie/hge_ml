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
            ['users', 'schools', 'latin_honor_uploads', 'extraction_review_drafts', 'latin_honor_graduates', 'activity_log', 'graduate_applications', 'ocr_course_keywords', 'app_settings', 'graduate_recycle_bin', 'bulk_hge_review_items'],
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

    public function testUploadNumberColumnAndUniqueConstraintExist(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO schools (school_name) VALUES ('TEST UNIVERSITY')");
        $pdo->exec(
            "INSERT INTO users (name, username, email, password, role) VALUES ('Admin', 'admin', 'a@a.com', 'x', 'administrator')"
        );
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number)
             VALUES (1, '2025', 'list.pdf', 'TEST_UNIVERSITY_2025_1.pdf', '/storage/pdfs/x.pdf', 100, SHA2('a', 256), 1, 1)"
        );

        $this->expectException(\PDOException::class);
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number)
             VALUES (1, '2025', 'list2.pdf', 'TEST_UNIVERSITY_2025_1.pdf', '/storage/pdfs/y.pdf', 100, SHA2('b', 256), 1, 1)"
        );
    }

    public function testUploadsTableHasChunkProgressColumnsWithSafeDefaults(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO schools (school_name) VALUES ('TEST UNIVERSITY')");
        $pdo->exec("INSERT INTO users (name, username, email, password, role) VALUES ('Admin', 'admin', 'a@a.com', 'x', 'administrator')");
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number)
             VALUES (1, '2025', 'list.pdf', 'TEST_UNIVERSITY_2025_1.pdf', '/storage/pdfs/x.pdf', 100, SHA2('a', 256), 1, 1)"
        );

        $row = $pdo->query('SELECT total_pages, extracted_through_page FROM latin_honor_uploads WHERE id = 1')->fetch();

        $this->assertNull($row['total_pages']);
        $this->assertSame(0, (int) $row['extracted_through_page']);
    }
}
