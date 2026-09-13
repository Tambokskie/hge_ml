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
        $this->assertSame('ONLY PDF FILES ARE ACCEPTED.', $result['error']);
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
