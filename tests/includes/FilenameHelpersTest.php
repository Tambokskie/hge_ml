<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/pdf_files.php';

class FilenameHelpersTest extends TestCase
{
    public function testSanitizeForFilenameUppercasesAndCollapsesSymbols(): void
    {
        $this->assertSame('ABC_UNIVERSITY', sanitize_for_filename('ABC University'));
        $this->assertSame('ST_JOSEPH_S_COLLEGE', sanitize_for_filename("  St. Joseph's College!!  "));
    }

    public function testSanitizeForFilenameTrimsLeadingAndTrailingUnderscores(): void
    {
        $this->assertSame('EASTERN_VISAYAS_STATE_UNIVERSITY', sanitize_for_filename('---Eastern Visayas State University---'));
    }

    public function testBuildStoredFilenameProducesExpectedPattern(): void
    {
        $this->assertSame('ABC_UNIVERSITY_2025_1.pdf', build_stored_filename('ABC University', '2025', 1));
    }

    public function testBuildStoredFilenameIncludesUploadNumber(): void
    {
        $this->assertSame('ABC_UNIVERSITY_2025_3.pdf', build_stored_filename('ABC University', '2025', 3));
    }

    public function testBuildStoredFilenameIncludesCampusWhenProvided(): void
    {
        $this->assertSame(
            'VISAYAS_STATE_UNIVERSITY_ISABEL_2026_1.pdf',
            build_stored_filename('Visayas State University', '2026', 1, 'Isabel')
        );
    }

    public function testBuildStoredFilenameDisambiguatesSameNameDifferentCampuses(): void
    {
        $villaba = build_stored_filename('Visayas State University', '2026', 1, 'Villaba');
        $isabel = build_stored_filename('Visayas State University', '2026', 1, 'Isabel');

        $this->assertNotSame($villaba, $isabel);
    }

    public function testBuildStoredFilenameOmitsCampusSegmentWhenBlank(): void
    {
        $this->assertSame('ABC_UNIVERSITY_2025_1.pdf', build_stored_filename('ABC University', '2025', 1, ''));
    }
}
