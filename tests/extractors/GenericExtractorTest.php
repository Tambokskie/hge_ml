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

    public function testCourseIsExtractedEvenWhenTheDocumentNeverLiterallySaysDegreeCourseOrProgram(): void
    {
        // Real-world bug: this document's header didn't OCR into any of
        // "DEGREE"/"COURSE"/"PROGRAM" (it may have been garbled or phrased
        // differently), and the OCR text has no double-space gaps between
        // columns either. The course keyword itself ("BACHELOR") must be
        // enough of a signal on its own — extraction must not depend on a
        // separate whole-document header check.
        $text = 'EASTERN VISAYAS STATE UNIVERSITY LIST OF GRADUATES' . "\n"
            . "1 ABELLA, AZREELE LIV VI BACHELOR OF SCIENCE IN ARCHITECTURE CUM LAUDE JUNE 26, 2026 2026 VALID\n";
        $extractor = new \GenericExtractor();
        $rows = $this->callParseRows($extractor, $text);

        $this->assertCount(1, $rows);
        $this->assertSame('ABELLA, AZREELE LIV VI', $rows[0]['graduate_name']);
        $this->assertSame('BACHELOR OF SCIENCE IN ARCHITECTURE', $rows[0]['course']);
        $this->assertSame('CUM LAUDE', $rows[0]['latin_honor']);
    }

    public function testLeadingCurlyQuoteAndPipeOcrArtifactsAreStrippedFromName(): void
    {
        // OCR sometimes emits curly/smart quote characters instead of a
        // plain ASCII apostrophe — the cleanup must not be limited to a
        // fixed punctuation whitelist.
        $text = "2 \u{2019}|AVELINO, JOHN ARVIN BALDESCO 171 BACHELOR OF SCIENCE IN ARCHITECTURE CUM LAUDE JUNE 26, 2026\n";
        $extractor = new \GenericExtractor();
        $rows = $this->callParseRows($extractor, $text);

        $this->assertCount(1, $rows);
        $this->assertSame('AVELINO, JOHN ARVIN BALDESCO', $rows[0]['graduate_name']);
        $this->assertSame('BACHELOR OF SCIENCE IN ARCHITECTURE', $rows[0]['course']);
    }

    public function testStandalonePipeCharacterIsUsedAsNameCourseBoundaryWhenTheDegreeKeywordIsUnrecognizable(): void
    {
        // Real-world bug: OCR garbled "BACHELOR" beyond recognition
        // ("PASTEL") so no degree keyword matches, but it preserved the
        // table's column border as a literal "|" — that alone is enough to
        // stop the whole line (name + unreadable course text) from being
        // dumped entirely into the name field.
        $text = "137 CI 2 RMA, ANCES ROSE JOMILLO 1G5 | PASTEL OF TECHNOLOGY AND IVELTOAD EDUCTION HOME MAGNA CUM LAUDE JUNE 25, 2026\n";
        $extractor = new \GenericExtractor();
        $rows = $this->callParseRows($extractor, $text);

        $this->assertCount(1, $rows);
        $this->assertSame('CI 2 RMA, ANCES ROSE JOMILLO 1G5', $rows[0]['graduate_name']);
        $this->assertSame('PASTEL OF TECHNOLOGY AND IVELTOAD EDUCTION HOME', $rows[0]['course']);
        $this->assertSame('MAGNA CUM LAUDE', $rows[0]['latin_honor']);
    }

    public function testPipeEmbeddedInsideANameIsNotMistakenForAColumnBoundary(): void
    {
        // A pipe with no surrounding whitespace is far more likely to be a
        // misread "I"/"l" inside the name itself than a real column break,
        // so it must not truncate the name.
        $text = "DEGREE/COURSE\nGARC|A, JUAN BACHELOR OF SCIENCE IN ARCHITECTURE CUM LAUDE JUNE 26, 2026\n";
        $extractor = new \GenericExtractor();
        $rows = $this->callParseRows($extractor, $text);

        $this->assertCount(1, $rows);
        $this->assertSame('GARC|A, JUAN', $rows[0]['graduate_name']);
    }

    public function testLeadingRowNumberAndOcrPunctuationArtifactsAreStrippedFromName(): void
    {
        // Real OCR output: row number "2" followed by a stray quote and
        // bracket, then a roll number ("171") merged onto the name before
        // the course column — none of this belongs in the graduate name.
        $text = "DEGREE/COURSE   HONORS\n"
            . "2 '[AVELINO, JOHN ARVIN BALDESCO 171 BACHELOR OF SCIENCE IN ARCHITECTURE CUM LAUDE JUNE 26, 2026\n";
        $extractor = new \GenericExtractor();
        $rows = $this->callParseRows($extractor, $text);

        $this->assertCount(1, $rows);
        $this->assertSame('AVELINO, JOHN ARVIN BALDESCO', $rows[0]['graduate_name']);
        $this->assertSame('BACHELOR OF SCIENCE IN ARCHITECTURE', $rows[0]['course']);
        $this->assertSame('CUM LAUDE', $rows[0]['latin_honor']);
        $this->assertSame('2026-06-26', $rows[0]['graduation_date']);
    }

    public function testPlainLeadingRowNumberAndTrailingRollNumberAreStrippedFromName(): void
    {
        $text = "DEGREE/COURSE   HONORS\n"
            . "4 BUNA, RON RIANNE ERNEST COMBONG 147 BACHELOR OF SCIENCE IN ARCHITECTURE MAGNA CUM LAUDE JUNE 26, 2026 2026 VALID\n";
        $extractor = new \GenericExtractor();
        $rows = $this->callParseRows($extractor, $text);

        $this->assertCount(1, $rows);
        $this->assertSame('BUNA, RON RIANNE ERNEST COMBONG', $rows[0]['graduate_name']);
        $this->assertSame('BACHELOR OF SCIENCE IN ARCHITECTURE', $rows[0]['course']);
        $this->assertSame('MAGNA CUM LAUDE', $rows[0]['latin_honor']);
        $this->assertSame('2026-06-26', $rows[0]['graduation_date']);
    }

    public function testCourseAnchorRecognizesDegreeKeywordsBeyondBachelor(): void
    {
        $text = "DEGREE/COURSE   HONORS\n"
            . "1 SANTOS, MARIA L. MASTER OF ARTS IN EDUCATION SUMMA CUM LAUDE JUNE 26, 2026\n";
        $extractor = new \GenericExtractor();
        $rows = $this->callParseRows($extractor, $text);

        $this->assertCount(1, $rows);
        $this->assertSame('SANTOS, MARIA L.', $rows[0]['graduate_name']);
        $this->assertSame('MASTER OF ARTS IN EDUCATION', $rows[0]['course']);
        $this->assertSame('SUMMA CUM LAUDE', $rows[0]['latin_honor']);
    }

    public function testOcrVerifyMarkerNeverLeaksIntoTheLatinHonorValue(): void
    {
        $text = "DEGREE/COURSE   HONORS\n"
            . "1 REYES, PEDRO A. BACHELOR OF SCIENCE IN NURSING MAGNA CUM LAUDE OCR_VERIFY JUNE 26, 2026\n";
        $extractor = new \GenericExtractor();
        $rows = $this->callParseRows($extractor, $text);

        $this->assertCount(1, $rows);
        $this->assertSame('MAGNA CUM LAUDE', $rows[0]['latin_honor']);
    }

    public function testInteriorApostropheInNameIsNeverStrippedByArtifactCleanup(): void
    {
        $text = "DEGREE/COURSE   HONORS\n"
            . "3 O'BRIEN-SMITH, JOSE MIGUEL BACHELOR OF SCIENCE IN ARCHITECTURE CUM LAUDE JUNE 26, 2026\n";
        $extractor = new \GenericExtractor();
        $rows = $this->callParseRows($extractor, $text);

        $this->assertCount(1, $rows);
        $this->assertSame("O'BRIEN-SMITH, JOSE MIGUEL", $rows[0]['graduate_name']);
    }

    /**
     * @dataProvider abbreviatedDegreeFormatProvider
     */
    public function testRecognizesAbbreviatedAndPunctuatedDegreeFormatsNotJustBachelor(string $courseText, string $expectedCourse): void
    {
        $text = "DEGREE/COURSE\n2 AVELINO, JOHN ARVIN BALDESCO {$courseText} CUM LAUDE JUNE 26, 2026\n";
        $extractor = new \GenericExtractor();
        $rows = $this->callParseRows($extractor, $text);

        $this->assertCount(1, $rows);
        $this->assertSame('AVELINO, JOHN ARVIN BALDESCO', $rows[0]['graduate_name']);
        $this->assertSame($expectedCourse, $rows[0]['course']);
    }

    public static function abbreviatedDegreeFormatProvider(): array
    {
        return [
            'full name' => ['BACHELOR OF SCIENCE IN ACCOUNTANCY', 'BACHELOR OF SCIENCE IN ACCOUNTANCY'],
            'BS + noun' => ['BS ACCOUNTANCY', 'BS ACCOUNTANCY'],
            'BS IN + noun' => ['BS IN ACCOUNTANCY', 'BS IN ACCOUNTANCY'],
            'B.S. + noun' => ['B.S. ACCOUNTANCY', 'B.S. ACCOUNTANCY'],
            'B.S. IN + noun' => ['B.S. IN ACCOUNTANCY', 'B.S. IN ACCOUNTANCY'],
            'BSA acronym' => ['BSA', 'BSA'],
            'BSIT acronym' => ['BSIT', 'BSIT'],
            'BSCS acronym' => ['BSCS', 'BSCS'],
            'BS COMPUTER SCIENCE' => ['BS COMPUTER SCIENCE', 'BS COMPUTER SCIENCE'],
            'BA + noun' => ['BA COMMUNICATION', 'BA COMMUNICATION'],
            'BA IN + noun' => ['BA IN COMMUNICATION', 'BA IN COMMUNICATION'],
        ];
    }

    public function testCourseIsNotAutomaticallyNormalizedToItsFullFormName(): void
    {
        // The ticket is explicit: preserve the school's original wording —
        // "BS ACCOUNTANCY" must never be silently rewritten to "BACHELOR
        // OF SCIENCE IN ACCOUNTANCY".
        $text = "DEGREE/COURSE\n2 AVELINO, JOHN ARVIN BALDESCO BS ACCOUNTANCY CUM LAUDE JUNE 26, 2026\n";
        $extractor = new \GenericExtractor();
        $rows = $this->callParseRows($extractor, $text);

        $this->assertSame('BS ACCOUNTANCY', $rows[0]['course']);
    }

    public function testDegreeAbbreviationInsideAFilipinoNameDoesNotFalselyAnchorTheCourse(): void
    {
        // "Ma." is a common Filipino name element (short for "Maria"), not
        // "Master of Arts" — the real course token, immediately before the
        // honor, must win over the earlier false-positive match.
        $text = "DEGREE/COURSE\n2 SANTOS, JUAN MA. DELA CRUZ BS ACCOUNTANCY CUM LAUDE JUNE 26, 2026\n";
        $extractor = new \GenericExtractor();
        $rows = $this->callParseRows($extractor, $text);

        $this->assertCount(1, $rows);
        $this->assertSame('SANTOS, JUAN MA. DELA CRUZ', $rows[0]['graduate_name']);
        $this->assertSame('BS ACCOUNTANCY', $rows[0]['course']);
    }

    public function testPunctuatedMasterOfArtsAbbreviationIsRecognized(): void
    {
        $text = "DEGREE/COURSE\n5 SANTOS, PEDRO M.A. IN EDUCATION MAGNA CUM LAUDE JUNE 26, 2026\n";
        $extractor = new \GenericExtractor();
        $rows = $this->callParseRows($extractor, $text);

        $this->assertCount(1, $rows);
        $this->assertSame('SANTOS, PEDRO', $rows[0]['graduate_name']);
        $this->assertSame('M.A. IN EDUCATION', $rows[0]['course']);
    }

    public function testShortAbbreviationDoesNotFalselyMatchInsideALongerWord(): void
    {
        // "BALDESCO" contains "BA" as a substring but not as its own word
        // — must not be mistaken for the "BA" degree abbreviation.
        $text = "DEGREE/COURSE\n2 BALDESCO, JUAN BS ACCOUNTANCY CUM LAUDE JUNE 26, 2026\n";
        $extractor = new \GenericExtractor();
        $rows = $this->callParseRows($extractor, $text);

        $this->assertCount(1, $rows);
        $this->assertSame('BALDESCO, JUAN', $rows[0]['graduate_name']);
        $this->assertSame('BS ACCOUNTANCY', $rows[0]['course']);
    }

    private function callParseRows(\GenericExtractor $extractor, string $text): array
    {
        $reflection = new \ReflectionMethod($extractor, 'parseRows');
        $reflection->setAccessible(true);

        return $reflection->invoke($extractor, $text);
    }
}
