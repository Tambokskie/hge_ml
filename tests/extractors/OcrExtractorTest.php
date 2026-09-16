<?php

namespace Tests\Extractors;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../extractors/ExtractorInterface.php';
require_once __DIR__ . '/../../extractors/ParsesHonorRows.php';
require_once __DIR__ . '/../../extractors/ocr_extractor.php';

class OcrExtractorTest extends TestCase
{
    private string $sample;

    protected function setUp(): void
    {
        $this->sample = __DIR__ . '/../fixtures/scanned-sample.pdf';

        $tesseractPath = getenv('TESSERACT_PATH') ?: 'C:\Program Files\Tesseract-OCR\tesseract.exe';
        $ghostscriptPath = getenv('GHOSTSCRIPT_PATH') ?: 'C:\Program Files\gs\gs10.08.0\bin\gswin64c.exe';

        if (!is_file($tesseractPath) || !is_file($ghostscriptPath)) {
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
}
