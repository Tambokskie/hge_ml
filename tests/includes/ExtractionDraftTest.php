<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;
use Tests\TestDb;

require_once __DIR__ . '/../../includes/extraction_review.php';
require_once __DIR__ . '/../../includes/uploads.php';

class ExtractionDraftTest extends TestCase
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

    private function sampleRows(): array
    {
        return [
            ['graduate_name' => 'Juan Dela Cruz', 'course' => 'BS Computer Science', 'latin_honor' => 'CUM LAUDE', 'graduation_date' => '2025-06-15', 'graduation_year' => 2025],
            ['graduate_name' => '', 'course' => '', 'latin_honor' => '', 'graduation_date' => null, 'graduation_year' => 0],
        ];
    }

    public function testSaveExtractionDraftPersistsIncompleteRowsWithoutValidation(): void
    {
        $pdo = TestDb::pdo();

        $result = save_extraction_draft($pdo, 1, 1, $this->sampleRows());

        $this->assertSame('success', $result['status']);

        $graduateCount = (int) $pdo->query('SELECT COUNT(*) FROM latin_honor_graduates')->fetchColumn();
        $this->assertSame(0, $graduateCount, 'draft rows must never be written to latin_honor_graduates');

        $draftCount = (int) $pdo->query('SELECT COUNT(*) FROM extraction_review_drafts WHERE upload_id = 1')->fetchColumn();
        $this->assertSame(1, $draftCount);
    }

    public function testSaveExtractionDraftMarksUploadStatusAsDraftWithoutTouchingRecordsExtracted(): void
    {
        $pdo = TestDb::pdo();

        save_extraction_draft($pdo, 1, 1, $this->sampleRows());

        $upload = $pdo->query('SELECT extraction_status, records_extracted FROM latin_honor_uploads WHERE id = 1')->fetch();
        $this->assertSame('draft', $upload['extraction_status']);
        $this->assertSame(0, (int) $upload['records_extracted']);
    }

    public function testGetExtractionDraftReturnsNullWhenNoneExists(): void
    {
        $this->assertNull(get_extraction_draft(TestDb::pdo(), 1));
    }

    public function testGetExtractionDraftRoundTripsRows(): void
    {
        $pdo = TestDb::pdo();
        $rows = $this->sampleRows();

        save_extraction_draft($pdo, 1, 1, $rows);
        $draft = get_extraction_draft($pdo, 1);

        $this->assertNotNull($draft);
        $this->assertSame($rows, $draft['rows']);
        $this->assertNotNull($draft['updated_at']);
    }

    public function testSavingASecondDraftOverwritesTheFirstInsteadOfDuplicating(): void
    {
        $pdo = TestDb::pdo();

        save_extraction_draft($pdo, 1, 1, $this->sampleRows());
        save_extraction_draft($pdo, 1, 1, [
            ['graduate_name' => 'Maria Santos', 'course' => '', 'latin_honor' => 'MAGNA CUM LAUDE', 'graduation_date' => null, 'graduation_year' => 2025],
        ]);

        $draftCount = (int) $pdo->query('SELECT COUNT(*) FROM extraction_review_drafts WHERE upload_id = 1')->fetchColumn();
        $this->assertSame(1, $draftCount);

        $draft = get_extraction_draft($pdo, 1);
        $this->assertCount(1, $draft['rows']);
        $this->assertSame('Maria Santos', $draft['rows'][0]['graduate_name']);
    }

    public function testDeleteExtractionDraftRemovesIt(): void
    {
        $pdo = TestDb::pdo();
        save_extraction_draft($pdo, 1, 1, $this->sampleRows());

        delete_extraction_draft($pdo, 1);

        $this->assertNull(get_extraction_draft($pdo, 1));
    }

    public function testSaveStagedRecordsDeletesAnyExistingDraftOnFinalSave(): void
    {
        $pdo = TestDb::pdo();
        save_extraction_draft($pdo, 1, 1, $this->sampleRows());

        $validRows = [
            ['graduate_name' => 'Juan Dela Cruz', 'course' => 'BS Computer Science', 'latin_honor' => 'CUM LAUDE', 'graduation_date' => '2025-06-15', 'graduation_year' => 2025],
        ];
        $result = save_staged_records($pdo, 1, 1, $validRows);

        $this->assertSame('success', $result['status']);
        $this->assertNull(get_extraction_draft($pdo, 1));

        $upload = $pdo->query('SELECT extraction_status FROM latin_honor_uploads WHERE id = 1')->fetch();
        $this->assertSame('reviewed', $upload['extraction_status']);
    }

    public function testDeleteUploadAlsoRemovesItsDraft(): void
    {
        $pdo = TestDb::pdo();
        save_extraction_draft($pdo, 1, 1, $this->sampleRows());

        $result = delete_upload($pdo, 1);

        $this->assertTrue($result['ok']);
        $draftCount = (int) $pdo->query('SELECT COUNT(*) FROM extraction_review_drafts')->fetchColumn();
        $this->assertSame(0, $draftCount);
    }

    public function testAppendExtractionDraftRowsCreatesADraftWhenNoneExists(): void
    {
        $pdo = TestDb::pdo();
        $newRows = [
            ['graduate_name' => 'Juan Dela Cruz', 'course' => '', 'latin_honor' => 'CUM LAUDE', 'graduation_date' => null, 'graduation_year' => 2025],
        ];

        $result = append_extraction_draft_rows($pdo, 1, 1, $newRows);

        $this->assertSame('success', $result['status']);
        $draft = get_extraction_draft($pdo, 1);
        $this->assertSame($newRows, $draft['rows']);
    }

    public function testAppendExtractionDraftRowsAddsToAnExistingDraftWithoutDroppingPriorRows(): void
    {
        $pdo = TestDb::pdo();
        $firstChunkRows = [
            ['graduate_name' => 'Juan Dela Cruz', 'course' => '', 'latin_honor' => 'CUM LAUDE', 'graduation_date' => null, 'graduation_year' => 2025],
        ];
        save_extraction_draft($pdo, 1, 1, $firstChunkRows);

        $secondChunkRows = [
            ['graduate_name' => 'Maria Santos', 'course' => '', 'latin_honor' => 'MAGNA CUM LAUDE', 'graduation_date' => null, 'graduation_year' => 2025],
        ];
        append_extraction_draft_rows($pdo, 1, 1, $secondChunkRows);

        $draft = get_extraction_draft($pdo, 1);
        $this->assertCount(2, $draft['rows']);
        $this->assertSame('Juan Dela Cruz', $draft['rows'][0]['graduate_name']);
        $this->assertSame('Maria Santos', $draft['rows'][1]['graduate_name']);
    }
}
