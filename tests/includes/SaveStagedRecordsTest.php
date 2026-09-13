<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;
use Tests\TestDb;

require_once __DIR__ . '/../../includes/extraction_review.php';
require_once __DIR__ . '/../../includes/uploads.php';

class SaveStagedRecordsTest extends TestCase
{
    protected function setUp(): void
    {
        TestDb::resetSchema();
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO schools (school_name) VALUES ('ABC University')");
        $pdo->exec("INSERT INTO users (name, username, email, password, role) VALUES ('Admin', 'admin', 'a@a.com', 'x', 'administrator')");
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number)
             VALUES (1, '2025', 'a.pdf', 'ABC_UNIVERSITY_2025_1.pdf', '/x/a.pdf', 10, SHA2('a',256), 1, 1)"
        );
    }

    private function validRows(): array
    {
        return [
            ['graduate_name' => 'Juan Dela Cruz', 'latin_honor' => 'CUM LAUDE', 'graduation_date' => '2025-06-15', 'graduation_year' => 2025],
            ['graduate_name' => 'Maria Santos', 'latin_honor' => 'MAGNA CUM LAUDE', 'graduation_date' => null, 'graduation_year' => 2025],
        ];
    }

    public function testSavesAllRowsAndMarksUploadReviewed(): void
    {
        $pdo = TestDb::pdo();

        $result = save_staged_records($pdo, 1, 1, $this->validRows());

        $this->assertSame('success', $result['status']);

        $count = (int) $pdo->query('SELECT COUNT(*) FROM latin_honor_graduates WHERE upload_id = 1')->fetchColumn();
        $this->assertSame(2, $count);

        $upload = $pdo->query('SELECT extraction_status, records_extracted FROM latin_honor_uploads WHERE id = 1')->fetch();
        $this->assertSame('reviewed', $upload['extraction_status']);
        $this->assertSame(2, (int) $upload['records_extracted']);
    }

    public function testRejectsEmptyRowsWithoutTouchingTheDatabase(): void
    {
        $pdo = TestDb::pdo();

        $result = save_staged_records($pdo, 1, 1, []);

        $this->assertSame('error', $result['status']);
        $count = (int) $pdo->query('SELECT COUNT(*) FROM latin_honor_graduates')->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function testBlocksOnIncompleteRowNamingItsPosition(): void
    {
        $pdo = TestDb::pdo();
        $rows = $this->validRows();
        $rows[] = ['graduate_name' => '', 'latin_honor' => '', 'graduation_date' => null, 'graduation_year' => 0];

        $result = save_staged_records($pdo, 1, 1, $rows);

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('3', $result['message']);

        $count = (int) $pdo->query('SELECT COUNT(*) FROM latin_honor_graduates')->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function testRollsBackAllRowsOnDatabaseFailureAndLeavesUploadUnchanged(): void
    {
        $pdo = TestDb::pdo();

        // A nonexistent upload_id violates the FOREIGN KEY constraint on every
        // row's insert — proving that if the batch fails at the DB layer,
        // nothing is left partially inserted.
        $result = save_staged_records($pdo, 999999, 1, $this->validRows());

        $this->assertSame('error', $result['status']);

        $count = (int) $pdo->query('SELECT COUNT(*) FROM latin_honor_graduates')->fetchColumn();
        $this->assertSame(0, $count);

        $upload = $pdo->query('SELECT extraction_status FROM latin_honor_uploads WHERE id = 1')->fetch();
        $this->assertSame('pending', $upload['extraction_status']);
    }
}
