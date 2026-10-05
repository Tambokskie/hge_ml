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
            ['graduate_name' => 'Juan Dela Cruz', 'course' => 'BS Computer Science', 'latin_honor' => 'CUM LAUDE', 'graduation_date' => '2025-06-15', 'graduation_year' => 2025],
            ['graduate_name' => 'Maria Santos', 'course' => 'BA Political Science', 'latin_honor' => 'MAGNA CUM LAUDE', 'graduation_date' => null, 'graduation_year' => 2025],
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

    public function testSavesCourseFieldAlongsideEachRow(): void
    {
        $pdo = TestDb::pdo();

        save_staged_records($pdo, 1, 1, $this->validRows());

        $courses = $pdo->query('SELECT course FROM latin_honor_graduates WHERE upload_id = 1 ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['BS Computer Science', 'BA Political Science'], $courses);
    }

    public function testSavesEmptyCourseWhenRowHasNoCourseKey(): void
    {
        $pdo = TestDb::pdo();
        $rows = [
            ['graduate_name' => 'Pedro Reyes', 'latin_honor' => 'CUM LAUDE', 'graduation_date' => null, 'graduation_year' => 2025],
        ];

        $result = save_staged_records($pdo, 1, 1, $rows);

        $this->assertSame('success', $result['status']);
        $course = $pdo->query('SELECT course FROM latin_honor_graduates WHERE upload_id = 1')->fetchColumn();
        $this->assertSame('', $course);
    }

    public function testSavesSourceAlongsideEachRow(): void
    {
        $pdo = TestDb::pdo();
        $rows = $this->validRows();
        $rows[0]['source'] = 'ai';
        $rows[1]['source'] = 'ocr';

        save_staged_records($pdo, 1, 1, $rows);

        $sources = $pdo->query('SELECT source FROM latin_honor_graduates WHERE upload_id = 1 ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['ai', 'ocr'], $sources);
    }

    public function testSavesNullSourceWhenRowHasNoSourceKey(): void
    {
        $pdo = TestDb::pdo();

        save_staged_records($pdo, 1, 1, $this->validRows());

        $source = $pdo->query('SELECT source FROM latin_honor_graduates WHERE upload_id = 1 LIMIT 1')->fetchColumn();
        $this->assertNull($source);
    }

    public function testReSavingAnEditedRowOverwritesItsSourceToManual(): void
    {
        // Mirrors set_staged_row(): the moment an admin edits a row through
        // the review form, it stamps 'source' => 'manual' on it, regardless
        // of what extractor originally produced it.
        $pdo = TestDb::pdo();
        $rows = $this->validRows();
        $rows[0]['source'] = 'ocr';
        save_staged_records($pdo, 1, 1, $rows);
        $existingId = (int) $pdo->query("SELECT id FROM latin_honor_graduates WHERE graduate_name = 'Juan Dela Cruz'")->fetchColumn();

        $rows = $this->validRows();
        $rows[0]['id'] = $existingId;
        $rows[0]['source'] = 'manual';

        save_staged_records($pdo, 1, 1, $rows);

        $source = $pdo->query("SELECT source FROM latin_honor_graduates WHERE id = {$existingId}")->fetchColumn();
        $this->assertSame('manual', $source);
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

    public function testReSavingWithAnIdUpdatesTheExistingRowInsteadOfInserting(): void
    {
        $pdo = TestDb::pdo();
        save_staged_records($pdo, 1, 1, $this->validRows());
        $existingId = (int) $pdo->query("SELECT id FROM latin_honor_graduates WHERE graduate_name = 'Juan Dela Cruz'")->fetchColumn();

        $rows = $this->validRows();
        $rows[0]['id'] = $existingId;
        $rows[0]['graduate_name'] = 'Juan D. Cruz';

        $result = save_staged_records($pdo, 1, 1, $rows);

        $this->assertSame('success', $result['status']);
        $count = (int) $pdo->query('SELECT COUNT(*) FROM latin_honor_graduates WHERE upload_id = 1')->fetchColumn();
        $this->assertSame(2, $count, 'editing an existing row must not insert a duplicate');
        $name = $pdo->query("SELECT graduate_name FROM latin_honor_graduates WHERE id = {$existingId}")->fetchColumn();
        $this->assertSame('Juan D. Cruz', $name);
    }

    public function testReSavingWithAnAddedRowInsertsOnlyTheNewOne(): void
    {
        $pdo = TestDb::pdo();
        save_staged_records($pdo, 1, 1, $this->validRows());
        $existingRows = $pdo->query('SELECT id FROM latin_honor_graduates WHERE upload_id = 1')->fetchAll(\PDO::FETCH_COLUMN);

        $rows = $this->validRows();
        $rows[0]['id'] = (int) $existingRows[0];
        $rows[1]['id'] = (int) $existingRows[1];
        $rows[] = ['graduate_name' => 'Pedro Reyes', 'course' => '', 'latin_honor' => 'SUMMA CUM LAUDE', 'graduation_date' => null, 'graduation_year' => 2025];

        $result = save_staged_records($pdo, 1, 1, $rows);

        $this->assertSame('success', $result['status']);
        $count = (int) $pdo->query('SELECT COUNT(*) FROM latin_honor_graduates WHERE upload_id = 1')->fetchColumn();
        $this->assertSame(3, $count);
    }

    public function testReSavingWithoutARowDeletesItFromTheDatabase(): void
    {
        $pdo = TestDb::pdo();
        save_staged_records($pdo, 1, 1, $this->validRows());
        $existingRows = $pdo->query('SELECT id FROM latin_honor_graduates WHERE upload_id = 1')->fetchAll(\PDO::FETCH_COLUMN);

        $rows = [$this->validRows()[0]];
        $rows[0]['id'] = (int) $existingRows[0];

        $result = save_staged_records($pdo, 1, 1, $rows);

        $this->assertSame('success', $result['status']);
        $count = (int) $pdo->query('SELECT COUNT(*) FROM latin_honor_graduates WHERE upload_id = 1')->fetchColumn();
        $this->assertSame(1, $count);
        $this->assertSame(1, (int) $pdo->query('SELECT records_extracted FROM latin_honor_uploads WHERE id = 1')->fetchColumn());
    }

    public function testReSavingBlocksRemovingARowWithAFiledApplicationAndLeavesEverythingUnchanged(): void
    {
        $pdo = TestDb::pdo();
        save_staged_records($pdo, 1, 1, $this->validRows());
        $existingRows = $pdo->query('SELECT id FROM latin_honor_graduates WHERE upload_id = 1')->fetchAll(\PDO::FETCH_COLUMN);
        $pdo->prepare('INSERT INTO graduate_applications (graduate_id, applied_by, application_date) VALUES (:id, 1, "2026-09-17")')
            ->execute(['id' => $existingRows[0]]);

        // Drops the applied-for row from the resubmitted set, simulating an
        // admin removing it during a re-review.
        $rows = [$this->validRows()[1]];
        $rows[0]['id'] = (int) $existingRows[1];

        $result = save_staged_records($pdo, 1, 1, $rows);

        $this->assertSame('error', $result['status']);
        $count = (int) $pdo->query('SELECT COUNT(*) FROM latin_honor_graduates WHERE upload_id = 1')->fetchColumn();
        $this->assertSame(2, $count, 'the failed reconciliation must roll back, leaving both original rows intact');
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
