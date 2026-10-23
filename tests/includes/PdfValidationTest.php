<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/pdf_files.php';

class PdfValidationTest extends TestCase
{
    private string $samplePdf;
    private string $notAPdf;

    protected function setUp(): void
    {
        $this->samplePdf = __DIR__ . '/../fixtures/sample.pdf';
        $this->notAPdf = __DIR__ . '/../fixtures/not-a-pdf.txt';
    }

    public function testValidatePdfFileContentAcceptsRealPdf(): void
    {
        $result = validate_pdf_file_content($this->samplePdf, filesize($this->samplePdf), 20971520);
        $this->assertTrue($result['ok']);
        $this->assertNull($result['error']);
    }

    public function testValidatePdfFileContentRejectsNonPdfMimeType(): void
    {
        $result = validate_pdf_file_content($this->notAPdf, filesize($this->notAPdf), 20971520);
        $this->assertFalse($result['ok']);
        $this->assertSame('ONLY PDF, JPG/JPEG OR PNG FILES ARE ACCEPTED.', $result['error']);
    }

    public function testValidatePdfFileContentReportsPdfExtension(): void
    {
        $result = validate_pdf_file_content($this->samplePdf, filesize($this->samplePdf), 20971520);
        $this->assertSame('pdf', $result['extension']);
    }

    public function testValidatePdfFileContentAcceptsJpegAndPngByContent(): void
    {
        // 1x1 images, written to a name with a misleading extension: only the content counts.
        $jpeg = $this->tempFile(base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA='));
        $png = $this->tempFile(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));

        $jpegResult = validate_pdf_file_content($jpeg, filesize($jpeg), 20971520);
        $pngResult = validate_pdf_file_content($png, filesize($png), 20971520);

        $this->assertTrue($jpegResult['ok']);
        $this->assertSame('jpg', $jpegResult['extension']);
        $this->assertTrue($pngResult['ok']);
        $this->assertSame('png', $pngResult['extension']);
    }

    public function testValidatePdfFileContentRejectsATextFileNamedAsAnImage(): void
    {
        $fake = $this->tempFile('this is not an image', 'fake.jpg');
        $result = validate_pdf_file_content($fake, filesize($fake), 20971520);
        $this->assertFalse($result['ok']);
    }

    public function testImageFilenameHelpers(): void
    {
        $this->assertTrue(is_image_filename('ABC_2025_1.jpg'));
        $this->assertTrue(is_image_filename('ABC_2025_dup01.PNG'));
        $this->assertTrue(is_image_filename('x.jpeg'));
        $this->assertFalse(is_image_filename('ABC_2025_1.pdf'));
        $this->assertSame('image/jpeg', mime_type_for_filename('a.jpg'));
        $this->assertSame('image/png', mime_type_for_filename('a.png'));
        $this->assertSame('application/pdf', mime_type_for_filename('a.pdf'));
        $this->assertSame('ABC_UNIVERSITY_2025_1.jpg', build_stored_filename('ABC University', '2025', 1, '', 'jpg'));
    }

    private function tempFile(string $contents, string $name = 'upload.bin'): string
    {
        $path = sys_get_temp_dir() . '/hge_test_' . uniqid() . '_' . $name;
        file_put_contents($path, $contents);
        register_shutdown_function(static fn () => @unlink($path));

        return $path;
    }

    public function testValidatePdfFileContentRejectsOversizedFile(): void
    {
        $result = validate_pdf_file_content($this->samplePdf, 999, 500);
        $this->assertFalse($result['ok']);
        $this->assertSame('THE FILE EXCEEDS THE MAXIMUM ALLOWED SIZE.', $result['error']);
    }

    public function testValidatePdfUploadRejectsPhpUploadError(): void
    {
        $file = ['error' => UPLOAD_ERR_INI_SIZE, 'tmp_name' => $this->samplePdf];
        $result = validate_pdf_upload($file, 20971520);
        $this->assertFalse($result['ok']);
        $this->assertSame('THE FILE COULD NOT BE UPLOADED. PLEASE TRY AGAIN.', $result['error']);
    }

    public function testValidatePdfUploadRejectsNonUploadedFile(): void
    {
        // A real file on disk that was never submitted via HTTP — is_uploaded_file() must reject it.
        $file = ['error' => UPLOAD_ERR_OK, 'tmp_name' => $this->samplePdf];
        $result = validate_pdf_upload($file, 20971520);
        $this->assertFalse($result['ok']);
        $this->assertSame('THE FILE COULD NOT BE UPLOADED. PLEASE TRY AGAIN.', $result['error']);
    }
}
