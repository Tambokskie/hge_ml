<?php

namespace Tests\Extractors;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/ocr_tools.php';
require_once __DIR__ . '/../../extractors/ExtractorInterface.php';
require_once __DIR__ . '/../../extractors/ParsesHonorRows.php';
require_once __DIR__ . '/../../extractors/ocr_extractor.php';

class OcrExtractorTest extends TestCase
{
    private string $sample;

    protected function setUp(): void
    {
        $this->sample = __DIR__ . '/../fixtures/scanned-sample.pdf';

        $tools = check_ocr_tools_installed();
        if (!$tools['tesseract'] || !$tools['ghostscript']) {
            $this->markTestSkipped('Tesseract and/or Ghostscript binary not found — skipping OCR tests on this machine.');
        }
    }

    public function testExtractsAllNineRowsFromScannedPdf(): void
    {
        $result = (new \OcrExtractor())->extract($this->sample);

        $this->assertSame('ok', $result['status']);
        $this->assertCount(9, $result['rows']);
    }

    public function testEveryRowCarriesOcrSourceTag(): void
    {
        $result = (new \OcrExtractor())->extract($this->sample);

        foreach ($result['rows'] as $row) {
            $this->assertSame('ocr', $row['source']);
        }
    }

    public function testSummaCumLaudeIsNotMisclassifiedDespiteOcrDroppingTheSpace(): void
    {
        $result = (new \OcrExtractor())->extract($this->sample);

        $summaRow = null;
        foreach ($result['rows'] as $row) {
            if (stripos($row['graduate_name'], 'Amarillo') !== false) {
                $summaRow = $row;
                break;
            }
        }

        $this->assertNotNull($summaRow, 'Expected to find a row for "Amarillo" in the OCR output.');
        $this->assertSame('SUMMA CUM LAUDE', $summaRow['latin_honor']);
    }

    public function testMagnaCumLaudeRowIsNotSilentlyDroppedDespiteOcrMergingTheWords(): void
    {
        $result = (new \OcrExtractor())->extract($this->sample);

        $magnaRow = null;
        foreach ($result['rows'] as $row) {
            if (stripos($row['graduate_name'], 'Amascual') !== false) {
                $magnaRow = $row;
                break;
            }
        }

        $this->assertNotNull($magnaRow, 'Expected to find a row for "Amascual" in the OCR output — this row was silently dropped before the \s* regex fix.');
        $this->assertSame('MAGNA CUM LAUDE', $magnaRow['latin_honor']);
    }

    public function testFailedPdfParseReturnsFailedStatus(): void
    {
        $result = (new \OcrExtractor())->extract(__DIR__ . '/../fixtures/sample.pdf');

        $this->assertSame('failed', $result['status']);
        $this->assertSame([], $result['rows']);
    }

    public function testCourseIsExtractedFromRealOcrOutputDespiteNoisySpacing(): void
    {
        $result = (new \OcrExtractor())->extract($this->sample);

        $expectedCoursesByNameFragment = [
            'Abrio' => 'BA Psychology',
            'Agudera' => 'BS Management',
            'Amarillo' => 'BS Biology',
            'Amascual' => 'BS Management',
            'Balmonte' => 'BS Management',
            'Bersoza' => 'BA Political Science',
            'Borja' => 'BA Political Science',
            'Cabagio' => 'BS Management',
        ];

        foreach ($expectedCoursesByNameFragment as $nameFragment => $expectedCourse) {
            $row = null;
            foreach ($result['rows'] as $candidate) {
                if (stripos($candidate['graduate_name'], $nameFragment) !== false) {
                    $row = $candidate;
                    break;
                }
            }

            $this->assertNotNull($row, "Expected to find a row for \"{$nameFragment}\" in the OCR output.");
            $this->assertSame($expectedCourse, $row['course'], "Course mismatch for \"{$nameFragment}\"");
        }
    }

    public function testCourseFirstLayoutIsNotReliablyExtractableFromRealOcrOutput(): void
    {
        // Real finding: unlike the name-first layout above, a course-first
        // document (course, name, date, honor) has no numeric/keyword anchor
        // between course and name — only a whitespace column gap tells them
        // apart. Tesseract collapses every multi-space gap in this fixture
        // down to a single space, so that signal is gone from the OCR text
        // and the parser correctly refuses to guess where course ends and
        // name begins, rather than risk silently saving a wrong split.
        // Confirmed via manual probe: Tesseract reads every word correctly
        // (including the honor phrases and the comma in "Dela Cruz, Juan
        // M.") — it is the collapsed spacing, not misread text, that defeats
        // extraction here. The same layout parses correctly when the source
        // PDF has a real text layer (see
        // GenericExtractorTest::testCourseFirstLayoutSplitsCourseNameDateAndHonorCorrectly),
        // where the original column spacing is preserved exactly.
        $result = (new \OcrExtractor())->extract(__DIR__ . '/../fixtures/course-first-scanned.pdf');

        $this->assertSame('failed', $result['status']);
        $this->assertSame([], $result['rows']);
    }

    public function testOnPageProgressCallbackFiresOncePerPageWithCorrectTotal(): void
    {
        $calls = [];
        $onPageProgress = function (int $currentPage, int $totalPages) use (&$calls): void {
            $calls[] = [$currentPage, $totalPages];
        };

        (new \OcrExtractor([], $onPageProgress))->extract($this->sample);

        // scanned-sample.pdf is a single page — one call, reporting itself
        // as page 1 of 1.
        $this->assertSame([[1, 1]], $calls);
    }
}
