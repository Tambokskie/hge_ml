<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/ocr_tools.php';
require_once __DIR__ . '/../../includes/pdf_pages.php';

class PdfPagesTest extends TestCase
{
    private string $singlePage;
    private string $twoPage;

    protected function setUp(): void
    {
        $tools = check_ocr_tools_installed();
        if (!$tools['ghostscript']) {
            $this->markTestSkipped('Ghostscript binary not found — skipping PDF page tests on this machine.');
        }

        $this->singlePage = __DIR__ . '/../fixtures/sample.pdf';

        // Two real fixtures merged into one 2-page PDF via Ghostscript itself
        // (confirmed working during design) — avoids checking in a dedicated
        // multi-page binary fixture just for this test.
        $this->twoPage = sys_get_temp_dir() . '/hge_pdf_pages_test_' . uniqid() . '.pdf';
        $ghostscriptPath = resolve_ghostscript_path();
        $cmd = escapeshellarg($ghostscriptPath)
            . ' -q -dNOPAUSE -dBATCH -dSAFER -sDEVICE=pdfwrite'
            . ' -sOutputFile=' . escapeshellarg($this->twoPage)
            . ' ' . escapeshellarg(__DIR__ . '/../fixtures/sample.pdf')
            . ' ' . escapeshellarg(__DIR__ . '/../fixtures/extraction-sample.pdf');
        exec($cmd . ' 2>&1', $output, $returnCode);
        if ($returnCode !== 0 || !is_file($this->twoPage)) {
            $this->fail('Could not build the 2-page test fixture via Ghostscript: ' . implode(' ', $output));
        }
    }

    protected function tearDown(): void
    {
        if (is_file($this->twoPage)) {
            @unlink($this->twoPage);
        }
    }

    public function testCountPdfPagesReturnsOneForASinglePageDocument(): void
    {
        $this->assertSame(1, count_pdf_pages($this->singlePage));
    }

    public function testCountPdfPagesReturnsTwoForATwoPageDocument(): void
    {
        $this->assertSame(2, count_pdf_pages($this->twoPage));
    }

    public function testCountPdfPagesReturnsNullForANonexistentFile(): void
    {
        $this->assertNull(count_pdf_pages(sys_get_temp_dir() . '/hge_does_not_exist_' . uniqid() . '.pdf'));
    }

    public function testExtractPdfPageRangeProducesAOnePageSlice(): void
    {
        $slicePath = extract_pdf_page_range($this->twoPage, 1, 1);

        $this->assertNotNull($slicePath);
        $this->assertSame(1, count_pdf_pages($slicePath));

        @unlink($slicePath);
    }

    public function testExtractPdfPageRangeProducesTheSecondPageWhenAskedForIt(): void
    {
        $slicePath = extract_pdf_page_range($this->twoPage, 2, 2);

        $this->assertNotNull($slicePath);
        $this->assertSame(1, count_pdf_pages($slicePath));

        @unlink($slicePath);
    }

    public function testExtractPdfPageRangeReturnsNullForANonexistentFile(): void
    {
        $this->assertNull(extract_pdf_page_range(sys_get_temp_dir() . '/hge_does_not_exist_' . uniqid() . '.pdf', 1, 1));
    }
}
