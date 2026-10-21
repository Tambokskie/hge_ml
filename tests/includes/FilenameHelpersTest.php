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

    public function testBuildStoredFilenameMarksSecondAndLaterUploadsAsDuplicates(): void
    {
        $this->assertSame('ABC_UNIVERSITY_2025_dup01.pdf', build_stored_filename('ABC University', '2025', 2));
        $this->assertSame('ABC_UNIVERSITY_2025_dup02.pdf', build_stored_filename('ABC University', '2025', 3));
        $this->assertSame('ABC_UNIVERSITY_ISABEL_2025_dup01.xlsx', build_stored_filename('ABC University', '2025', 2, 'Isabel', 'xlsx'));
    }

    public function testUploadDupTagIsReadFromTheStoredFilename(): void
    {
        $this->assertSame('DUP01', upload_dup_tag('ABC_UNIVERSITY_2025_dup01.pdf'));
        $this->assertSame('DUP12', upload_dup_tag('ABC_UNIVERSITY_2025_dup12.xlsx'));
        $this->assertNull(upload_dup_tag('ABC_UNIVERSITY_2025_1.pdf'));
        $this->assertNull(upload_dup_tag('ABC_UNIVERSITY_2026_2.pdf'), 'a lone #2 that was never renamed is not a duplicate');
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
