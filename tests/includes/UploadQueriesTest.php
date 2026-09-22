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
        // Already extracted, so the pending-first/oldest-first tiering
        // below doesn't apply — plain most-recent-first ordering.
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number, uploaded_at, records_extracted)
             VALUES (1, '2024', 'a.pdf', 'ABC_UNIVERSITY_2024_1.pdf', '/x/a.pdf', 10, SHA2('a',256), 1, 1, '2024-01-01 00:00:00', 10)"
        );
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number, uploaded_at, records_extracted)
             VALUES (2, '2025', 'b.pdf', 'XYZ_COLLEGE_2025_1.pdf', '/x/b.pdf', 10, SHA2('b',256), 1, 1, '2025-01-01 00:00:00', 12)"
        );

        $recent = get_recent_uploads($pdo, 5);

        $this->assertCount(2, $recent);
        $this->assertSame('XYZ College', $recent[0]['school_name']);
        $this->assertSame('ABC University', $recent[1]['school_name']);
    }

    public function testGetRecentUploadsPutsZeroExtractedRecordsFirstRegardlessOfDate(): void
    {
        $pdo = TestDb::pdo();
        // Newest upload already has extracted records...
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number, uploaded_at, records_extracted)
             VALUES (1, '2026', 'c.pdf', 'ABC_UNIVERSITY_2026_1.pdf', '/x/c.pdf', 10, SHA2('c',256), 1, 1, '2026-03-01 00:00:00', 15)"
        );
        // ...but an older upload still hasn't been extracted, so it must
        // surface first regardless of being the least recent upload.
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number, uploaded_at, records_extracted)
             VALUES (2, '2024', 'a.pdf', 'XYZ_COLLEGE_2024_1.pdf', '/x/a.pdf', 10, SHA2('a',256), 1, 1, '2024-01-01 00:00:00', 0)"
        );

        $recent = get_recent_uploads($pdo, 5);

        $this->assertSame('XYZ College', $recent[0]['school_name']);
        $this->assertSame(0, (int) $recent[0]['records_extracted']);
        $this->assertSame('ABC University', $recent[1]['school_name']);
    }

    public function testGetRecentUploadsOrdersMultipleZeroExtractedOldestFirst(): void
    {
        $pdo = TestDb::pdo();
        // Oldest pending upload — this is the one to prioritize, so it must
        // lead the list even though it's the least recently uploaded.
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number, uploaded_at, records_extracted)
             VALUES (1, '2025', 'a.pdf', 'ABC_UNIVERSITY_2025_1.pdf', '/x/a.pdf', 10, SHA2('a',256), 1, 1, '2025-01-01 00:00:00', 0)"
        );
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number, uploaded_at, records_extracted)
             VALUES (2, '2026', 'b.pdf', 'XYZ_COLLEGE_2026_1.pdf', '/x/b.pdf', 10, SHA2('b',256), 1, 1, '2026-01-01 00:00:00', 0)"
        );
        // A non-zero upload sitting between them by date must still sort
        // after both zero-extracted rows, most-recent first as before.
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number, uploaded_at, records_extracted)
             VALUES (1, '2025', 'c.pdf', 'ABC_UNIVERSITY_2025_2.pdf', '/x/c.pdf', 10, SHA2('c',256), 1, 2, '2025-06-01 00:00:00', 5)"
        );

        $recent = get_recent_uploads($pdo, 5);

        $this->assertSame('ABC University', $recent[0]['school_name']);
        $this->assertSame(0, (int) $recent[0]['records_extracted']);
        $this->assertSame('XYZ College', $recent[1]['school_name']);
        $this->assertSame(0, (int) $recent[1]['records_extracted']);
        $this->assertSame(5, (int) $recent[2]['records_extracted']);
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

    public function testGetUploadByIdIncludesSchoolCampus(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec("UPDATE schools SET campus = 'MAIN CAMPUS' WHERE id = 1");
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number)
             VALUES (1, '2025', 'a.pdf', 'ABC_UNIVERSITY_2025_1.pdf', '/x/a.pdf', 10, SHA2('a',256), 1, 1)"
        );
        $id = (int) $pdo->lastInsertId();

        $upload = get_upload_by_id($pdo, $id);

        $this->assertSame('MAIN CAMPUS', $upload['campus']);
    }

    public function testGetRecentUploadsIncludesSchoolCampus(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec("UPDATE schools SET campus = 'MAIN CAMPUS' WHERE id = 1");
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number)
             VALUES (1, '2025', 'a.pdf', 'ABC_UNIVERSITY_2025_1.pdf', '/x/a.pdf', 10, SHA2('a',256), 1, 1)"
        );

        $recent = get_recent_uploads($pdo, 5);

        $this->assertSame('MAIN CAMPUS', $recent[0]['campus']);
    }

    public function testGetUploadByIdReturnsNullForUnknownId(): void
    {
        $this->assertNull(get_upload_by_id(TestDb::pdo(), 999));
    }
}
