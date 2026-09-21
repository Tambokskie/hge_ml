<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;
use Tests\TestDb;

require_once __DIR__ . '/../../includes/uploads.php';

class DeleteUploadTest extends TestCase
{
    protected function setUp(): void
    {
        TestDb::resetSchema();
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO schools (school_name) VALUES ('ABC University')");
        $pdo->exec("INSERT INTO users (name, username, email, password, role) VALUES ('Admin', 'admin', 'a@a.com', 'x', 'administrator')");
    }

    private function insertUpload(string $filePath, int $recordsExtracted): int
    {
        $pdo = TestDb::pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number, records_extracted)
             VALUES (1, "2025", "a.pdf", "ABC_UNIVERSITY_2025_1.pdf", :file_path, 10, SHA2(:file_path, 256), 1, 1, :records)'
        );
        $stmt->execute(['file_path' => $filePath, 'records' => $recordsExtracted]);

        return (int) $pdo->lastInsertId();
    }

    public function testDeletesTheUploadRowWhenZeroRecordsExtracted(): void
    {
        $id = $this->insertUpload('/x/nonexistent.pdf', 0);

        $result = delete_upload(TestDb::pdo(), $id);

        $this->assertTrue($result['ok']);
        $this->assertNull(get_upload_by_id(TestDb::pdo(), $id));
    }

    public function testRejectsDeletionWhenRecordsHaveBeenExtracted(): void
    {
        $id = $this->insertUpload('/x/nonexistent.pdf', 3);

        $result = delete_upload(TestDb::pdo(), $id);

        $this->assertFalse($result['ok']);
        $this->assertNotNull(get_upload_by_id(TestDb::pdo(), $id));
    }

    public function testReturnsErrorForUnknownUploadId(): void
    {
        $result = delete_upload(TestDb::pdo(), 999999);

        $this->assertFalse($result['ok']);
    }

    public function testDeletesTheAssociatedPdfFileFromDisk(): void
    {
        $tmpFile = sys_get_temp_dir() . '/hge_delete_upload_test_' . uniqid() . '.pdf';
        file_put_contents($tmpFile, 'fake pdf content');
        $id = $this->insertUpload($tmpFile, 0);

        $result = delete_upload(TestDb::pdo(), $id);

        $this->assertTrue($result['ok']);
        $this->assertFileDoesNotExist($tmpFile);
    }

    public function testAlsoRemovesAnyGraduateRowsLinkedToTheUpload(): void
    {
        $id = $this->insertUpload('/x/nonexistent.pdf', 0);
        TestDb::pdo()->exec(
            "INSERT INTO latin_honor_graduates (upload_id, school_id, graduate_name, latin_honor, graduation_date, graduation_year)
             VALUES ({$id}, 1, 'Juan Dela Cruz', 'CUM LAUDE', '2025-06-15', 2025)"
        );

        delete_upload(TestDb::pdo(), $id);

        $count = (int) TestDb::pdo()->query('SELECT COUNT(*) FROM latin_honor_graduates')->fetchColumn();
        $this->assertSame(0, $count);
    }
}
