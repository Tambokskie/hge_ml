<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;
use Tests\TestDb;

require_once __DIR__ . '/../../includes/uploads.php';

class UploadQueriesTest extends TestCase
{
    protected function setUp(): void
    {
        TestDb::resetSchema();
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO schools (school_name) VALUES ('ABC University'), ('XYZ College')");
        $pdo->exec("INSERT INTO users (name, username, email, password, role) VALUES ('Admin', 'admin', 'a@a.com', 'x', 'administrator')");
    }

    public function testGetRecentUploadsReturnsMostRecentFirstWithSchoolName(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number, uploaded_at)
             VALUES (1, '2024', 'a.pdf', 'ABC_UNIVERSITY_2024_1.pdf', '/x/a.pdf', 10, SHA2('a',256), 1, 1, '2024-01-01 00:00:00')"
        );
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number, uploaded_at)
             VALUES (2, '2025', 'b.pdf', 'XYZ_COLLEGE_2025_1.pdf', '/x/b.pdf', 10, SHA2('b',256), 1, 1, '2025-01-01 00:00:00')"
        );

        $recent = get_recent_uploads($pdo, 5);

        $this->assertCount(2, $recent);
        $this->assertSame('XYZ College', $recent[0]['school_name']);
        $this->assertSame('ABC University', $recent[1]['school_name']);
    }

    public function testGetRecentUploadsRespectsLimit(): void
    {
        $pdo = TestDb::pdo();
        for ($i = 1; $i <= 3; $i++) {
            $pdo->exec(
                "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number)
                 VALUES (1, '202{$i}', 'a{$i}.pdf', 'ABC_UNIVERSITY_202{$i}_1.pdf', '/x/a{$i}.pdf', 10, SHA2('a{$i}',256), 1, 1)"
            );
        }

        $this->assertCount(2, get_recent_uploads($pdo, 2));
    }

    public function testGetUploadByIdReturnsFullRow(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number)
             VALUES (1, '2025', 'a.pdf', 'ABC_UNIVERSITY_2025_1.pdf', '/x/a.pdf', 10, SHA2('a',256), 1, 1)"
        );
        $id = (int) $pdo->lastInsertId();

        $upload = get_upload_by_id($pdo, $id);

        $this->assertNotNull($upload);
        $this->assertSame('ABC University', $upload['school_name']);
        $this->assertSame('/x/a.pdf', $upload['file_path']);
        $this->assertSame('pending', $upload['extraction_status']);
    }

    public function testGetUploadByIdReturnsNullForUnknownId(): void
    {
        $this->assertNull(get_upload_by_id(TestDb::pdo(), 999));
    }
}
