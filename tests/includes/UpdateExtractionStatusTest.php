<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;
use Tests\TestDb;

require_once __DIR__ . '/../../includes/uploads.php';

class UpdateExtractionStatusTest extends TestCase
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

    public function testUpdatesStatusAndRecordCount(): void
    {
        $pdo = TestDb::pdo();

        update_upload_extraction_status($pdo, 1, 'extracted', 12);

        $row = $pdo->query('SELECT extraction_status, records_extracted FROM latin_honor_uploads WHERE id = 1')->fetch();
        $this->assertSame('extracted', $row['extraction_status']);
        $this->assertSame(12, (int) $row['records_extracted']);
    }

    public function testBumpsUpdatedAtEvenWhenStatusAndCountDoNotChange(): void
    {
        $pdo = TestDb::pdo();

        // MySQL does not fire a column's ON UPDATE CURRENT_TIMESTAMP unless some
        // other column in the row actually changes value — so re-saving a draft
        // that is already status='draft'/records=0 (the exact values save-as-draft
        // always writes) must not silently freeze "last checked" at whatever it
        // was after the FIRST draft save.
        $pdo->exec("UPDATE latin_honor_uploads SET extraction_status = 'draft', records_extracted = 0, updated_at = '2020-01-01 00:00:00' WHERE id = 1");

        update_upload_extraction_status($pdo, 1, 'draft', 0);

        $updatedAt = $pdo->query('SELECT updated_at FROM latin_honor_uploads WHERE id = 1')->fetchColumn();
        $this->assertGreaterThan('2020-01-01 00:00:00', $updatedAt);
    }

    public function testUpdateUploadChunkProgressPersistsTotalPagesAndCursor(): void
    {
        $pdo = TestDb::pdo();

        update_upload_chunk_progress($pdo, 1, 120, 45);

        $row = $pdo->query('SELECT total_pages, extracted_through_page FROM latin_honor_uploads WHERE id = 1')->fetch();
        $this->assertSame(120, (int) $row['total_pages']);
        $this->assertSame(45, (int) $row['extracted_through_page']);
    }

    public function testResetUploadChunkProgressClearsTotalPagesAndCursor(): void
    {
        $pdo = TestDb::pdo();
        update_upload_chunk_progress($pdo, 1, 120, 45);

        reset_upload_chunk_progress($pdo, 1);

        $row = $pdo->query('SELECT total_pages, extracted_through_page FROM latin_honor_uploads WHERE id = 1')->fetch();
        $this->assertNull($row['total_pages']);
        $this->assertSame(0, (int) $row['extracted_through_page']);
    }

    public function testUploadNeedsMoreExtractionIsFalseWhenTotalPagesIsNull(): void
    {
        $this->assertFalse(upload_needs_more_extraction(['total_pages' => null, 'extracted_through_page' => 0]));
    }

    public function testUploadNeedsMoreExtractionIsTrueWhenPagesRemain(): void
    {
        $this->assertTrue(upload_needs_more_extraction(['total_pages' => 120, 'extracted_through_page' => 45]));
    }

    public function testUploadNeedsMoreExtractionIsFalseWhenFullyExtracted(): void
    {
        $this->assertFalse(upload_needs_more_extraction(['total_pages' => 120, 'extracted_through_page' => 120]));
    }

    public function testGetUploadByIdIncludesChunkProgressColumns(): void
    {
        $pdo = TestDb::pdo();
        update_upload_chunk_progress($pdo, 1, 120, 45);

        $upload = get_upload_by_id($pdo, 1);

        $this->assertSame(120, $upload['total_pages']);
        $this->assertSame(45, $upload['extracted_through_page']);
    }
}
