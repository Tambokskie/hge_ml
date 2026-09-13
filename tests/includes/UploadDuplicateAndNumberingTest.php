<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;
use Tests\TestDb;

require_once __DIR__ . '/../../includes/uploads.php';

class UploadDuplicateAndNumberingTest extends TestCase
{
    protected function setUp(): void
    {
        TestDb::resetSchema();
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO schools (school_name) VALUES ('ABC University')");
        $pdo->exec("INSERT INTO users (name, username, email, password, role) VALUES ('Admin', 'admin', 'a@a.com', 'x', 'administrator')");
    }

    public function testFindExistingUploadReturnsNullWhenNoneExists(): void
    {
        $this->assertNull(find_existing_upload(TestDb::pdo(), 1, '2025'));
    }

    public function testFindExistingUploadReturnsMostRecentMatch(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number)
             VALUES (1, '2025', 'a.pdf', 'ABC_UNIVERSITY_2025_1.pdf', '/x/a.pdf', 10, SHA2('a',256), 1, 1)"
        );

        $existing = find_existing_upload($pdo, 1, '2025');

        $this->assertNotNull($existing);
        $this->assertSame('ABC University', $existing['school_name']);
        $this->assertSame('a.pdf', $existing['original_filename']);
    }

    public function testNextUploadNumberIsOneWhenNoneExistYet(): void
    {
        $this->assertSame(1, next_upload_number(TestDb::pdo(), 1, '2025'));
    }

    public function testNextUploadNumberIncrementsPastExistingMax(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number)
             VALUES (1, '2025', 'a.pdf', 'ABC_UNIVERSITY_2025_1.pdf', '/x/a.pdf', 10, SHA2('a',256), 1, 1)"
        );

        $this->assertSame(2, next_upload_number($pdo, 1, '2025'));
    }

    public function testNextUploadNumberIsIndependentPerSchoolYear(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number)
             VALUES (1, '2024', 'a.pdf', 'ABC_UNIVERSITY_2024_1.pdf', '/x/a.pdf', 10, SHA2('a',256), 1, 1)"
        );

        $this->assertSame(1, next_upload_number($pdo, 1, '2025'));
    }
}
