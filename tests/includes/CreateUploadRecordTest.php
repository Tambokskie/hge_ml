<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;
use Tests\TestDb;

require_once __DIR__ . '/../../includes/uploads.php';

class CreateUploadRecordTest extends TestCase
{
    protected function setUp(): void
    {
        TestDb::resetSchema();
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO schools (school_name) VALUES ('ABC University')");
        $pdo->exec("INSERT INTO users (name, username, email, password, role) VALUES ('Admin', 'admin', 'a@a.com', 'x', 'administrator')");
    }

    public function testCreateUploadRecordInsertsAndReturnsFullRow(): void
    {
        $pdo = TestDb::pdo();

        $upload = create_upload_record($pdo, [
            'school_id' => 1,
            'school_year' => '2025',
            'original_filename' => 'Honor List.pdf',
            'stored_filename' => 'ABC_UNIVERSITY_2025_1.pdf',
            'file_path' => '/storage/pdfs/ABC_UNIVERSITY_2025_1.pdf',
            'file_size' => 12345,
            'file_hash' => hash('sha256', 'test-content'),
            'uploaded_by' => 1,
            'upload_number' => 1,
        ]);

        $this->assertGreaterThan(0, $upload['id']);
        $this->assertSame(1, $upload['school_id']);
        $this->assertSame('2025', $upload['school_year']);
        $this->assertSame('Honor List.pdf', $upload['original_filename']);
        $this->assertSame('ABC_UNIVERSITY_2025_1.pdf', $upload['stored_filename']);
        $this->assertSame(1, $upload['upload_number']);
        $this->assertSame('pending', $upload['extraction_status']);
        $this->assertSame(0, $upload['records_extracted']);

        $count = (int) $pdo->query('SELECT COUNT(*) FROM latin_honor_uploads')->fetchColumn();
        $this->assertSame(1, $count);
    }
}
