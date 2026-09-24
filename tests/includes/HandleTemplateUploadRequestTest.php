<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;
use Tests\TestDb;

require_once __DIR__ . '/../../includes/template_upload.php';
require_once __DIR__ . '/../../includes/xlsx_writer.php';

class HandleTemplateUploadRequestTest extends TestCase
{
    private string $storageDir;
    private array $tempFiles = [];

    protected function setUp(): void
    {
        if (!defined('APP_BASE_URL')) {
            define('APP_BASE_URL', '/hge_masterlist');
        }

        TestDb::resetSchema();
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO users (name, username, email, password, role) VALUES ('Admin', 'admin', 'a@a.com', 'x', 'administrator')");

        $this->storageDir = sys_get_temp_dir() . '/hge_test_template_storage_' . uniqid();
        mkdir($this->storageDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storageDir . '/*') as $file) {
            unlink($file);
        }
        rmdir($this->storageDir);

        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->tempFiles = [];

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
            $_SESSION = [];
        }
    }

    /**
     * Builds a filled-in template .xlsx exactly like a real user's would
     * look, saved to a temp file, returning a $_FILES-shaped array.
     */
    private function templateFile(array $header = [], array $dataRows = []): array
    {
        $header = array_merge([
            'school_name' => 'VISAYAS STATE UNIVERSITY',
            'campus' => 'MAIN CAMPUS',
            'date' => '24/07/2025',
            'year' => '2025',
        ], $header);

        if (empty($dataRows)) {
            $dataRows = [
                ['sequence' => '1', 'last_name' => 'Dela Cruz', 'given_name' => 'Juan', 'middle_name' => 'Santos', 'latin_honor' => 'CUM LAUDE', 'course' => 'BS Computer Science'],
            ];
        }

        $rows = [
            1 => [1 => ['value' => $header['school_name'], 'style' => 1]],
            2 => [1 => ['value' => $header['campus'], 'style' => 1]],
            3 => [1 => ['value' => $header['date'], 'style' => 1]],
            4 => [1 => ['value' => $header['year'], 'style' => 1]],
            6 => [
                1 => ['value' => 'SEQUENCE', 'style' => 1], 2 => ['value' => 'LAST NAME', 'style' => 1],
                3 => ['value' => 'GIVEN NAME', 'style' => 1], 4 => ['value' => 'MIDDLE NAME', 'style' => 1],
                5 => ['value' => 'LATIN HONOR', 'style' => 1], 6 => ['value' => 'COURSE', 'style' => 1],
            ],
        ];

        $rowNum = 7;
        foreach ($dataRows as $dataRow) {
            $rows[$rowNum] = [
                1 => ['value' => $dataRow['sequence'] ?? '', 'type' => 'n'],
                2 => ['value' => $dataRow['last_name'] ?? ''],
                3 => ['value' => $dataRow['given_name'] ?? ''],
                4 => ['value' => $dataRow['middle_name'] ?? ''],
                5 => ['value' => $dataRow['latin_honor'] ?? ''],
                6 => ['value' => $dataRow['course'] ?? ''],
            ];
            $rowNum++;
        }

        $bytes = xlsx_zip_parts([
            '[Content_Types].xml' => xlsx_content_types_xml(),
            '_rels/.rels' => xlsx_package_rels_xml(),
            'xl/workbook.xml' => xlsx_workbook_xml('TEMPLATE'),
            'xl/_rels/workbook.xml.rels' => xlsx_workbook_rels_xml(),
            'xl/styles.xml' => xlsx_styles_xml(),
            'xl/worksheets/sheet1.xml' => xlsx_build_sheet_xml($rows),
        ]);

        $path = sys_get_temp_dir() . '/hge_template_upload_test_' . uniqid() . '.xlsx';
        file_put_contents($path, $bytes);
        $this->tempFiles[] = $path;

        return [
            'name' => 'Honor List Template.xlsx',
            'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'tmp_name' => $path,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($path),
        ];
    }

    public function testSuccessfulUploadStagesRowsForReview(): void
    {
        $pdo = TestDb::pdo();
        create_school($pdo, 'Visayas State University', 'Main Address', 'Main Campus');

        $result = handle_template_upload_request($pdo, [], ['excel_file' => $this->templateFile()], 1, $this->storageDir);

        $this->assertSame('success', $result['status']);
        $this->assertFileExists($this->storageDir . '/VISAYAS_STATE_UNIVERSITY_MAIN_CAMPUS_2025_1.xlsx');

        $upload = get_upload_by_id($pdo, $result['upload']['id']);
        $this->assertSame('extracted', $upload['extraction_status']);
        $this->assertSame('2025', $upload['school_year']);

        $review = get_extraction_review($result['upload']['id']);
        $this->assertNotNull($review);
        $this->assertCount(1, $review['rows']);
        $this->assertSame('DELA CRUZ JUAN SANTOS', $review['rows'][0]['graduate_name']);
        $this->assertSame('CUM LAUDE', $review['rows'][0]['latin_honor']);
    }

    public function testSchoolNotInSchoolManagementIsRejected(): void
    {
        $pdo = TestDb::pdo();
        // Deliberately no create_school() call — the template names a school
        // that was never added to School Management.

        $result = handle_template_upload_request($pdo, [], ['excel_file' => $this->templateFile()], 1, $this->storageDir);

        $this->assertSame('school_not_found', $result['status']);
        $this->assertStringContainsString('NOT YET IN SCHOOL MANAGEMENT', $result['message']);

        $uploadCount = (int) $pdo->query('SELECT COUNT(*) FROM latin_honor_uploads')->fetchColumn();
        $this->assertSame(0, $uploadCount);
    }

    public function testDuplicateUploadRequiresConfirmation(): void
    {
        $pdo = TestDb::pdo();
        create_school($pdo, 'Visayas State University', 'Main Address', 'Main Campus');

        $first = handle_template_upload_request($pdo, [], ['excel_file' => $this->templateFile()], 1, $this->storageDir);
        $this->assertSame('success', $first['status']);

        $second = handle_template_upload_request($pdo, [], ['excel_file' => $this->templateFile()], 1, $this->storageDir);
        $this->assertSame('duplicate', $second['status']);

        $third = handle_template_upload_request(
            $pdo,
            ['confirm_duplicate' => '1'],
            ['excel_file' => $this->templateFile()],
            1,
            $this->storageDir
        );
        $this->assertSame('success', $third['status']);
        $this->assertSame(2, $third['upload']['upload_number']);
    }

    public function testWrongTemplateFormatIsRejected(): void
    {
        $pdo = TestDb::pdo();
        create_school($pdo, 'Visayas State University', 'Main Address', 'Main Campus');

        // An unrelated spreadsheet: no SEQUENCE/LAST NAME/... column-header
        // row anywhere, which is the template's one structural fingerprint.
        $rows = [
            1 => [1 => ['value' => 'VISAYAS STATE UNIVERSITY', 'style' => 1]],
            2 => [1 => ['value' => 'MAIN CAMPUS', 'style' => 1]],
            3 => [1 => ['value' => '24/07/2025', 'style' => 1]],
            4 => [1 => ['value' => '2025', 'style' => 1]],
            6 => [1 => ['value' => 'NAME', 'style' => 1], 2 => ['value' => 'DEGREE', 'style' => 1]],
        ];
        $bytes = xlsx_zip_parts([
            '[Content_Types].xml' => xlsx_content_types_xml(),
            '_rels/.rels' => xlsx_package_rels_xml(),
            'xl/workbook.xml' => xlsx_workbook_xml('TEMPLATE'),
            'xl/_rels/workbook.xml.rels' => xlsx_workbook_rels_xml(),
            'xl/styles.xml' => xlsx_styles_xml(),
            'xl/worksheets/sheet1.xml' => xlsx_build_sheet_xml($rows),
        ]);
        $path = sys_get_temp_dir() . '/hge_wrong_template_test_' . uniqid() . '.xlsx';
        file_put_contents($path, $bytes);
        $this->tempFiles[] = $path;
        $file = ['name' => 'wrong.xlsx', 'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => filesize($path)];

        $result = handle_template_upload_request($pdo, [], ['excel_file' => $file], 1, $this->storageDir);

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('DOES NOT LOOK LIKE THE LATIN HONOR TEMPLATE', $result['message']);
    }

    public function testRowsMissingRequiredFieldsAreRejectedBeforeCreatingAnUpload(): void
    {
        $pdo = TestDb::pdo();
        create_school($pdo, 'Visayas State University', 'Main Address', 'Main Campus');

        $file = $this->templateFile([], [
            ['sequence' => '1', 'last_name' => '', 'given_name' => 'Juan', 'latin_honor' => 'CUM LAUDE', 'course' => 'BS Computer Science'],
        ]);
        $result = handle_template_upload_request($pdo, [], ['excel_file' => $file], 1, $this->storageDir);

        $this->assertSame('error', $result['status']);
        $uploadCount = (int) $pdo->query('SELECT COUNT(*) FROM latin_honor_uploads')->fetchColumn();
        $this->assertSame(0, $uploadCount);
    }

    public function testOversizedFileReturnsError(): void
    {
        $pdo = TestDb::pdo();
        create_school($pdo, 'Visayas State University', 'Main Address', 'Main Campus');

        $result = handle_template_upload_request($pdo, [], ['excel_file' => $this->templateFile()], 1, $this->storageDir, 10);

        $this->assertSame('error', $result['status']);
        $this->assertSame('THE FILE EXCEEDS THE MAXIMUM ALLOWED SIZE.', $result['message']);
    }
}
