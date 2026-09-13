<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/extraction_review.php';

class ExtractionReviewCoreTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testDeriveFallbackYearUsesLastFourDigitYearInRange(): void
    {
        $this->assertSame(2026, derive_fallback_year('2025-2026'));
    }

    public function testDeriveFallbackYearHandlesSingleYear(): void
    {
        $this->assertSame(2025, derive_fallback_year('2025'));
    }

    public function testDeriveFallbackYearReturnsNullWhenNoYearFound(): void
    {
        $this->assertNull(derive_fallback_year('N/A'));
    }

    public function testValidateStagedRowAcceptsCompleteValidRow(): void
    {
        $result = validate_staged_row([
            'graduate_name' => 'Juan Dela Cruz',
            'latin_honor' => 'CUM LAUDE',
            'graduation_date' => '2026-07-02',
            'graduation_year' => 2026,
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['errors']);
    }

    public function testValidateStagedRowAcceptsNullDate(): void
    {
        $result = validate_staged_row([
            'graduate_name' => 'Juan Dela Cruz',
            'latin_honor' => 'CUM LAUDE',
            'graduation_date' => null,
            'graduation_year' => 2026,
        ]);

        $this->assertTrue($result['ok']);
    }

    public function testValidateStagedRowRejectsEmptyName(): void
    {
        $result = validate_staged_row([
            'graduate_name' => '',
            'latin_honor' => 'CUM LAUDE',
            'graduation_date' => null,
            'graduation_year' => 2026,
        ]);

        $this->assertFalse($result['ok']);
        $this->assertContains('GRADUATE NAME IS REQUIRED.', $result['errors']);
    }

    public function testValidateStagedRowRejectsUnrecognizedLatinHonor(): void
    {
        $result = validate_staged_row([
            'graduate_name' => 'Juan Dela Cruz',
            'latin_honor' => 'WITH HONORS',
            'graduation_date' => null,
            'graduation_year' => 2026,
        ]);

        $this->assertFalse($result['ok']);
    }

    public function testValidateStagedRowRejectsInvalidYear(): void
    {
        $result = validate_staged_row([
            'graduate_name' => 'Juan Dela Cruz',
            'latin_honor' => 'CUM LAUDE',
            'graduation_date' => null,
            'graduation_year' => 0,
        ]);

        $this->assertFalse($result['ok']);
    }

    public function testValidateStagedRowRejectsYearBelowMysqlYearColumnRange(): void
    {
        $result = validate_staged_row([
            'graduate_name' => 'Juan Dela Cruz',
            'latin_honor' => 'CUM LAUDE',
            'graduation_date' => null,
            'graduation_year' => 1900,
        ]);

        $this->assertFalse($result['ok']);
    }

    public function testValidateStagedRowAcceptsMinimumValidMysqlYear(): void
    {
        $result = validate_staged_row([
            'graduate_name' => 'Juan Dela Cruz',
            'latin_honor' => 'CUM LAUDE',
            'graduation_date' => null,
            'graduation_year' => 1901,
        ]);

        $this->assertTrue($result['ok']);
    }

    public function testValidateStagedRowRejectsMalformedDate(): void
    {
        $result = validate_staged_row([
            'graduate_name' => 'Juan Dela Cruz',
            'latin_honor' => 'CUM LAUDE',
            'graduation_date' => '2026-13-40',
            'graduation_year' => 2026,
        ]);

        $this->assertFalse($result['ok']);
    }

    public function testStagedRowStatusReflectsValidation(): void
    {
        $valid = ['graduate_name' => 'Juan', 'latin_honor' => 'CUM LAUDE', 'graduation_date' => null, 'graduation_year' => 2026];
        $incomplete = ['graduate_name' => '', 'latin_honor' => '', 'graduation_date' => null, 'graduation_year' => 0];

        $this->assertSame('VALID', staged_row_status($valid));
        $this->assertSame('INCOMPLETE', staged_row_status($incomplete));
    }

    public function testStartGetAndClearExtractionReviewRoundTrip(): void
    {
        $rows = [['graduate_name' => 'Juan', 'latin_honor' => 'CUM LAUDE', 'graduation_date' => null, 'graduation_year' => 2026]];

        start_extraction_review(5, $rows);
        $review = get_extraction_review(5);

        $this->assertNotNull($review);
        $this->assertSame($rows, $review['rows']);

        clear_extraction_review();
        $this->assertNull(get_extraction_review(5));
    }

    public function testGetExtractionReviewReturnsNullForMismatchedUploadId(): void
    {
        start_extraction_review(5, []);

        $this->assertNull(get_extraction_review(999));
    }
}
