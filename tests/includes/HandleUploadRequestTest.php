<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;
use Tests\TestDb;

require_once __DIR__ . '/../../includes/pdf_files.php';
require_once __DIR__ . '/../../includes/schools.php';
require_once __DIR__ . '/../../includes/uploads.php';

class HandleUploadRequestTest extends TestCase
{
    private string $storageDir;
    private string $samplePdf;

    protected function setUp(): void
    {
        TestDb::resetSchema();
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO users (name, username, email, password, role) VALUES ('Admin', 'admin', 'a@a.com', 'x', 'administrator')");

        $this->samplePdf = __DIR__ . '/../fixtures/sample.pdf';
        $this->storageDir = sys_get_temp_dir() . '/hge_test_storage_' . uniqid();
        mkdir($this->storageDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storageDir . '/*') as $file) {
            unlink($file);
        }
        rmdir($this->storageDir);
    }

    private function fileField(): array
    {
        return [
            'name' => 'Honor List.pdf',
            'type' => 'application/pdf',
            'tmp_name' => $this->samplePdf,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($this->samplePdf),
        ];
    }

    public function testSuccessfulUploadCreatesSchoolAndUploadRecord(): void
    {
        $pdo = TestDb::pdo();

        $result = handle_upload_request(
            $pdo,
            ['school_name' => 'ABC University', 'school_year' => '2025'],
            ['pdf_file' => $this->fileField()],
            1,
            $this->storageDir
        );

        $this->assertSame('success', $result['status']);
        $this->assertSame(1, $result['upload']['upload_number']);
        $this->assertFileExists($this->storageDir . '/ABC_UNIVERSITY_2025_1.pdf');

        $schoolCount = (int) $pdo->query("SELECT COUNT(*) FROM schools WHERE school_name = 'ABC University'")->fetchColumn();
        $this->assertSame(1, $schoolCount);
    }

    public function testMissingSchoolNameReturnsError(): void
    {
        $result = handle_upload_request(
            TestDb::pdo(),
            ['school_name' => '', 'school_year' => '2025'],
            ['pdf_file' => $this->fileField()],
            1,
            $this->storageDir
        );

        $this->assertSame('error', $result['status']);
    }

    public function testOversizedFileReturnsError(): void
    {
        $result = handle_upload_request(
            TestDb::pdo(),
            ['school_name' => 'ABC University', 'school_year' => '2025'],
            ['pdf_file' => $this->fileField()],
            1,
            $this->storageDir,
            10 // maxBytes — the tiny fixture is still bigger than 10 bytes
        );

        $this->assertSame('error', $result['status']);
        $this->assertSame('THE FILE EXCEEDS THE MAXIMUM ALLOWED SIZE.', $result['message']);
    }

    public function testDuplicateSchoolYearReturnsDuplicateStatusWithoutConfirm(): void
    {
        $pdo = TestDb::pdo();
        handle_upload_request(
            $pdo,
            ['school_name' => 'ABC University', 'school_year' => '2025'],
            ['pdf_file' => $this->fileField()],
            1,
            $this->storageDir
        );

        $result = handle_upload_request(
            $pdo,
            ['school_name' => 'ABC University', 'school_year' => '2025'],
            ['pdf_file' => $this->fileField()],
            1,
            $this->storageDir
        );

        $this->assertSame('duplicate', $result['status']);
        $this->assertSame(1, $result['existing_upload']['id']);
    }

    public function testConfirmDuplicateProceedsAndIncrementsUploadNumber(): void
    {
        $pdo = TestDb::pdo();
        handle_upload_request(
            $pdo,
            ['school_name' => 'ABC University', 'school_year' => '2025'],
            ['pdf_file' => $this->fileField()],
            1,
            $this->storageDir
        );

        $result = handle_upload_request(
            $pdo,
            ['school_name' => 'ABC University', 'school_year' => '2025', 'confirm_duplicate' => '1'],
            ['pdf_file' => $this->fileField()],
            1,
            $this->storageDir
        );

        $this->assertSame('success', $result['status']);
        $this->assertSame(2, $result['upload']['upload_number']);
        $this->assertFileExists($this->storageDir . '/ABC_UNIVERSITY_2025_2.pdf');
    }

    public function testCrossSchoolFilenameCollisionReturnsErrorWithoutOverwriting(): void
    {
        $pdo = TestDb::pdo();

        // Two genuinely different schools whose names sanitize to the same
        // filename fragment ("ABC University" vs "ABC University."). Insert
        // both directly so they get distinct school_id rows, bypassing
        // find_or_create_school's dedup (which only matches exact/lowercase names).
        $pdo->exec("INSERT INTO schools (school_name) VALUES ('ABC University')");
        $pdo->exec("INSERT INTO schools (school_name) VALUES ('ABC University.')");

        $firstResult = handle_upload_request(
            $pdo,
            ['school_name' => 'ABC University', 'school_year' => '2025'],
            ['pdf_file' => $this->fileField()],
            1,
            $this->storageDir
        );

        $this->assertSame('success', $firstResult['status']);
        $destination = $this->storageDir . '/ABC_UNIVERSITY_2025_1.pdf';
        $this->assertFileExists($destination);
        $firstContents = file_get_contents($destination);

        $secondResult = handle_upload_request(
            $pdo,
            ['school_name' => 'ABC University.', 'school_year' => '2025'],
            ['pdf_file' => $this->fileField()],
            1,
            $this->storageDir
        );

        $this->assertSame('error', $secondResult['status']);
        $this->assertSame(
            'A FILE WITH THIS NAME ALREADY EXISTS FOR A DIFFERENT SCHOOL. PLEASE CONTACT AN ADMINISTRATOR.',
            $secondResult['message']
        );

        // The first school's file must be untouched by the second (rejected) upload.
        $this->assertSame($firstContents, file_get_contents($destination));

        // No upload row should exist for the second school.
        $secondSchoolId = (int) $pdo->query("SELECT id FROM schools WHERE school_name = 'ABC University.'")->fetchColumn();
        $uploadCount = (int) $pdo->query("SELECT COUNT(*) FROM latin_honor_uploads WHERE school_id = {$secondSchoolId}")->fetchColumn();
        $this->assertSame(0, $uploadCount);
    }

    public function testDatabaseFailureAfterFileCopyCleansUpOrphanedFile(): void
    {
        $pdo = TestDb::pdo();

        // uploaded_by has a FOREIGN KEY to users(id); a non-existent user id
        // lets the file copy succeed and then makes create_upload_record's
        // INSERT fail with a genuine DB constraint violation.
        $nonExistentUserId = 999999;

        $result = handle_upload_request(
            $pdo,
            ['school_name' => 'ABC University', 'school_year' => '2025'],
            ['pdf_file' => $this->fileField()],
            $nonExistentUserId,
            $this->storageDir
        );

        $this->assertSame('error', $result['status']);
        $this->assertSame('THE RECORD COULD NOT BE SAVED. PLEASE TRY AGAIN.', $result['message']);

        $destination = $this->storageDir . '/ABC_UNIVERSITY_2025_1.pdf';
        $this->assertFileDoesNotExist($destination);

        $uploadCount = (int) $pdo->query('SELECT COUNT(*) FROM latin_honor_uploads')->fetchColumn();
        $this->assertSame(0, $uploadCount);
    }

    public function testSchoolNameTooLongReturnsError(): void
    {
        $result = handle_upload_request(
            TestDb::pdo(),
            ['school_name' => str_repeat('A', 256), 'school_year' => '2025'],
            ['pdf_file' => $this->fileField()],
            1,
            $this->storageDir
        );

        $this->assertSame('error', $result['status']);
        $this->assertSame('SCHOOL NAME IS TOO LONG.', $result['message']);
    }

    public function testSchoolYearTooLongReturnsError(): void
    {
        $result = handle_upload_request(
            TestDb::pdo(),
            ['school_name' => 'ABC University', 'school_year' => '2025 - 2026'],
            ['pdf_file' => $this->fileField()],
            1,
            $this->storageDir
        );

        $this->assertSame('error', $result['status']);
        $this->assertSame('SCHOOL YEAR IS TOO LONG.', $result['message']);
    }
}
