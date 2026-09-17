<?php

namespace Tests\Extractors;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../extractors/ExtractorInterface.php';
require_once __DIR__ . '/../../extractors/generic_extractor.php';

class GenericExtractorTest extends TestCase
{
    private string $sample;

    protected function setUp(): void
    {
        $this->sample = __DIR__ . '/../fixtures/extraction-sample.pdf';
    }

    public function testExtractsExactlyNineRowsSkippingProseAndHeader(): void
    {
        $extractor = new \GenericExtractor();
        $result = $extractor->extract($this->sample);

        $this->assertSame('ok', $result['status']);
        $this->assertCount(9, $result['rows']);
    }

    public function testFirstRowParsedCorrectly(): void
    {
        $result = (new \GenericExtractor())->extract($this->sample);

        $this->assertSame('Abrio, Jeny Lumpay', $result['rows'][0]['graduate_name']);
        $this->assertSame('CUM LAUDE', $result['rows'][0]['latin_honor']);
        $this->assertSame('2026-07-02', $result['rows'][0]['graduation_date']);
    }

    public function testNormalizesSummaAndMagnaCumLaudeDespiteCaseAndSpacingVariance(): void
    {
        $result = (new \GenericExtractor())->extract($this->sample);

        $this->assertSame('SUMMA CUM LAUDE', $result['rows'][2]['latin_honor']);
        $this->assertSame('MAGNA CUM LAUDE', $result['rows'][3]['latin_honor']);
        // "magna   cum   laude" (extra internal spaces)
        $this->assertSame('MAGNA CUM LAUDE', $result['rows'][4]['latin_honor']);
    }

    public function testParsesAbbreviatedDateFormat(): void
    {
        $result = (new \GenericExtractor())->extract($this->sample);

        // "2 Jul 2026"
        $this->assertSame('2026-07-02', $result['rows'][6]['graduation_date']);
    }

    public function testMissingDateResultsInNullNotAnError(): void
    {
        $result = (new \GenericExtractor())->extract($this->sample);

        $this->assertSame('Cabagio, Jonah Mae Laurente', $result['rows'][7]['graduate_name']);
        $this->assertNull($result['rows'][7]['graduation_date']);
    }

    public function testNameWithApostropheAndHyphenIsCapturedIntact(): void
    {
        $result = (new \GenericExtractor())->extract($this->sample);

        $this->assertSame("O'Brien-Smith, Jose Miguel", $result['rows'][8]['graduate_name']);
    }

    public function testMalformedPdfThatThrowsDuringParsingReturnsFailedStatus(): void
    {
        // Reuses Phase 2's existing minimal fixture, which parseFile() cannot
        // parse (no valid xref table) and throws — this must be caught, not
        // allowed to propagate as a fatal error.
        $extractor = new \GenericExtractor();
        $result = $extractor->extract(__DIR__ . '/../fixtures/sample.pdf');

        $this->assertSame('failed', $result['status']);
        $this->assertSame([], $result['rows']);
    }

    public function testEveryRowCarriesTextSourceTag(): void
    {
        $result = (new \GenericExtractor())->extract($this->sample);

        $this->assertNotEmpty($result['rows']);
        foreach ($result['rows'] as $row) {
            $this->assertSame('text', $row['source']);
        }
    }

    public function testCourseIsExtractedIntoItsOwnFieldSeparateFromName(): void
    {
        $result = (new \GenericExtractor())->extract($this->sample);

        $expectedCourses = [
            'BA Psychology',
            'BS Management',
            'BS Biology',
            'BS Management',
            'BS Management',
            'BA Political Science',
            'BA Political Science',
            'BS Management',
            'BS Biology',
        ];

        foreach ($expectedCourses as $index => $expectedCourse) {
            $this->assertSame($expectedCourse, $result['rows'][$index]['course'], "Row {$index} course mismatch");
        }

        // Name must no longer carry the GWA number or the course text.
        $this->assertSame('Abrio, Jeny Lumpay', $result['rows'][0]['graduate_name']);
    }

    public function testCourseIsBlankWhenDocumentHasNoDegreeOrGwaColumnHeader(): void
    {
        // A minimal single-row document with no "GWA"/"Degree" header at all —
        // the parser must not invent a course value out of nothing.
        $text = "Some Honor List\nJuan Dela Cruz    cum laude    02 July 2026\n";
        $extractor = new \GenericExtractor();
        $rows = $this->callParseRows($extractor, $text);

        $this->assertCount(1, $rows);
        $this->assertSame('Juan Dela Cruz', $rows[0]['graduate_name']);
        $this->assertSame('', $rows[0]['course']);
    }

    public function testBachelorAnchorTrimsLeadingGarbageFromCourseInNameFirstLayout(): void
    {
        // Course is spelled out in full starting with "Bachelor" rather than
        // abbreviated — the GWA number sits directly before it with no gap,
        // which the old GWA-end anchor would have folded into the course text.
        $text = "DEGREE/COURSE   GWA   HONORS\n"
            . "Dela Cruz, Juan M.   1.5000Bachelor of Science in Computer Science   cum laude   02 July 2026\n";
        $extractor = new \GenericExtractor();
        $rows = $this->callParseRows($extractor, $text);

        $this->assertCount(1, $rows);
        $this->assertSame('Dela Cruz, Juan M.', $rows[0]['graduate_name']);
        $this->assertSame('Bachelor of Science in Computer Science', $rows[0]['course']);
    }

    public function testCourseFirstLayoutSplitsCourseNameDateAndHonorCorrectly(): void
    {
        // Column order here is Course, Name, Date, Honor (honor last) —
        // the opposite order from every other fixture, exercised via the
        // real PDF's real text layer so column spacing is preserved exactly
        // as smalot/pdfparser reproduces it.
        $result = (new \GenericExtractor())->extract(__DIR__ . '/../fixtures/course-first-sample.pdf');

        $this->assertSame('ok', $result['status']);
        $this->assertCount(3, $result['rows']);

        $this->assertSame('BACHELOR OF SCIENCE IN COMPUTER SCIENCE', $result['rows'][0]['course']);
        $this->assertSame('DELA CRUZ, JUAN M.', $result['rows'][0]['graduate_name']);
        $this->assertSame('2026-07-02', $result['rows'][0]['graduation_date']);
        $this->assertSame('CUM LAUDE', $result['rows'][0]['latin_honor']);

        $this->assertSame('BACHELOR OF ARTS IN PSYCHOLOGY', $result['rows'][1]['course']);
        $this->assertSame('SANTOS, MARIA L.', $result['rows'][1]['graduate_name']);
        $this->assertSame('MAGNA CUM LAUDE', $result['rows'][1]['latin_honor']);

        $this->assertSame('BACHELOR OF SCIENCE IN NURSING', $result['rows'][2]['course']);
        $this->assertSame('REYES, PEDRO A.', $result['rows'][2]['graduate_name']);
        $this->assertSame('SUMMA CUM LAUDE', $result['rows'][2]['latin_honor']);
    }

    private function callParseRows(\GenericExtractor $extractor, string $text): array
    {
        $reflection = new \ReflectionMethod($extractor, 'parseRows');
        $reflection->setAccessible(true);

        return $reflection->invoke($extractor, $text);
    }
}
