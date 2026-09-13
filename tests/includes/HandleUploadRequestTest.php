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
}
