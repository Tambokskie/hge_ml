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
        // The standalone row number "2" is stripped as noise; "1G5" is left
        // alone since it isn't a pure number (see sanitizeExtractedText).
        $this->assertSame('CI RMA, ANCES ROSE JOMILLO 1G5', $rows[0]['graduate_name']);
        $this->assertSame('PASTEL OF TECHNOLOGY AND IVELTOAD EDUCTION HOME', $rows[0]['course']);
        $this->assertSame('MAGNA CUM LAUDE', $rows[0]['latin_honor']);
    }

    public function testPipeEmbeddedInsideANameIsNotMistakenForAColumnBoundary(): void
    {
        // A pipe with no surrounding whitespace is far more likely to be a
        // misread "I"/"l" inside the name itself than a real column break,
        // so it must not truncate the name — though it is still stripped
        // out of the final name as an unwanted symbol.
        $text = "DEGREE/COURSE\nGARC|A, JUAN BACHELOR OF SCIENCE IN ARCHITECTURE CUM LAUDE JUNE 26, 2026\n";
        $extractor = new \GenericExtractor();
        $rows = $this->callParseRows($extractor, $text);

        $this->assertCount(1, $rows);
        $this->assertSame('GARCA, JUAN', $rows[0]['graduate_name']);
    }

    public function testStraySymbolsAreStrippedFromNameAndCourse(): void
    {
        $text = "DEGREE/COURSE\n2 {AVELINO}, [JOHN] ARVIN~ BS| ACCOUNTANCY} CUM LAUDE JUNE 26, 2026\n";
        $extractor = new \GenericExtractor();
        $rows = $this->callParseRows($extractor, $text);

        $this->assertCount(1, $rows);
        $this->assertSame('AVELINO, JOHN ARVIN', $rows[0]['graduate_name']);
        $this->assertSame('BS ACCOUNTANCY', $rows[0]['course']);
    }

    public function testGwaFigureBetweenCourseAndHonorIsNotExtractedIntoCourse(): void
    {
        // The GWA column doesn't always sit before the course keyword — some
        // layouts place it between the course and the honor phrase instead,
        // where it must still never bleed into the course text.
        $text = "DEGREE/COURSE   GWA   HONORS\n"
            . "2 AVELINO, JOHN ARVIN BALDESCO BS ACCOUNTANCY  1.75  CUM LAUDE JUNE 26, 2026\n";
        $extractor = new \GenericExtractor();
        $rows = $this->callParseRows($extractor, $text);

        $this->assertCount(1, $rows);
        $this->assertSame('AVELINO, JOHN ARVIN BALDESCO', $rows[0]['graduate_name']);
        $this->assertSame('BS ACCOUNTANCY', $rows[0]['course']);
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

    public function testDittoMarksInheritThePrecedingRowsHonorAndDate(): void
    {
        // Real-world layout: schools frequently write "- do -" ("ditto") in
        // the Honor Received / Date of Graduation columns instead of
        // repeating the same value on every row of a run.
        $text = "Name of Graduate    Course    Honor Received    Date of Graduation\n"
            . "ABELLAR, JOMARI JESS S.    BSREM    SUMMA CUM LAUDE    APRIL 24, 2026\n"
            . "CHING, SERENITY JADE C.    BSA    -do-    -do-\n"
            . "VILLEGAS, LANCE MARCO M.    BSA    MAGNA CUM LAUDE    APRIL 24, 2026\n"
            . "DELMONTE, RON CHRISTIAN M.    BSA    - do -    - do -\n";

        $extractor = new \GenericExtractor();
        $rows = $this->callParseRows($extractor, $text);

        $this->assertCount(4, $rows);

        $this->assertSame('ABELLAR, JOMARI JESS S.', $rows[0]['graduate_name']);
        $this->assertSame('BSREM', $rows[0]['course']);
        $this->assertSame('SUMMA CUM LAUDE', $rows[0]['latin_honor']);
        $this->assertSame('2026-04-24', $rows[0]['graduation_date']);

        $this->assertSame('CHING, SERENITY JADE C.', $rows[1]['graduate_name']);
        $this->assertSame('BSA', $rows[1]['course']);
        $this->assertSame('SUMMA CUM LAUDE', $rows[1]['latin_honor']);
        $this->assertSame('2026-04-24', $rows[1]['graduation_date']);

        $this->assertSame('MAGNA CUM LAUDE', $rows[2]['latin_honor']);

        $this->assertSame('DELMONTE, RON CHRISTIAN M.', $rows[3]['graduate_name']);
        $this->assertSame('BSA', $rows[3]['course']);
        $this->assertSame('MAGNA CUM LAUDE', $rows[3]['latin_honor']);
        $this->assertSame('2026-04-24', $rows[3]['graduation_date']);
    }

    public function testDittoMarkRecognizesCommonOcrRenderings(): void
    {
        $text = "1 JUAN DELA CRUZ    BSA    CUM LAUDE    JUNE 26, 2026\n"
            . "2 MARIA SANTOS    BSA    -do-    -do-\n"
            . "3 PEDRO REYES    BSA    - do -    - do -\n"
            . "4 ANA GARCIA    BSA    -dó-    -dó-\n"
            . "5 LUIS TORRES    BSA    \xe2\x80\x94do\xe2\x80\x94    \xe2\x80\x94do\xe2\x80\x94\n";
        $extractor = new \GenericExtractor();
        $rows = $this->callParseRows($extractor, $text);

        $this->assertCount(5, $rows);
        foreach ($rows as $index => $row) {
            $this->assertSame('CUM LAUDE', $row['latin_honor'], "Row {$index} honor mismatch");
            $this->assertSame('2026-06-26', $row['graduation_date'], "Row {$index} date mismatch");
        }
    }

    public function testHandlesRealTesseractOutputOfAPhilippineHonorListWithDittoMarks(): void
    {
        // tests/fixtures/ditto-sample-ocr-output.txt is verbatim Tesseract
        // output from a real school's honor list image (26 graduates across
        // three honor tiers, using "- do -" ditto marks). It exercises every
        // real OCR failure mode the parser has to tolerate: no double-space
        // column gaps (Tesseract collapses them to one), ditto marks OCR'd
        // as "do", "odo", "-do-", or unrecognizable noise like "cae"/"205",
        // and one row (LABADO) so garbled ("RRA" for "BSA") even the course
        // keyword can't be recovered — that one is expected to be dropped
        // for a human to add manually, same as any other unparsable line.
        $text = file_get_contents(__DIR__ . '/../fixtures/ditto-sample-ocr-output.txt');
        $extractor = new \GenericExtractor();
        $rows = $this->callParseRows($extractor, $text);

        // 26 real graduates in the source image, minus the one whose course
        // keyword OCR destroyed beyond recognition.
        $this->assertCount(25, $rows);

        $byName = [];
        foreach ($rows as $row) {
            $byName[$row['graduate_name']] = $row;
        }

        $this->assertArrayHasKey('ABELLAR, JOMARIESS 8,', $byName);
        $first = $byName['ABELLAR, JOMARIESS 8,'];
        $this->assertSame('BSREM', $first['course']);
        $this->assertSame('SUMMA CUM LAUDE', $first['latin_honor']);
        $this->assertSame('2026-04-24', $first['graduation_date']);

        // Ditto row right after it, in the same SUMMA CUM LAUDE tier.
        $ching = $byName['CHING, SERENITY JADE C.'];
        $this->assertSame('BSA', $ching['course']);
        $this->assertSame('SUMMA CUM LAUDE', $ching['latin_honor']);
        $this->assertSame('2026-04-24', $ching['graduation_date']);

        // Tier changes to MAGNA CUM LAUDE here, spelled out despite the OCR
        // noise ("CUMLAUDE" with no space) — and every ditto row after it
        // must follow the new tier, not the old one.
        $villegas = $byName['VILLEGAS, LANCE MARCO M,'];
        $this->assertSame('MAGNA CUM LAUDE', $villegas['latin_honor']);
        $this->assertSame('2026-04-24', $villegas['graduation_date']);

        $delmonte = $byName['DELMONTE, RON CHRISTIAN M,'];
        $this->assertSame('MAGNA CUM LAUDE', $delmonte['latin_honor']);
        $this->assertSame('2026-04-24', $delmonte['graduation_date']);

        // Tier changes again to CUM LAUDE. This specific row's own date cell
        // OCR'd as unrecognizable noise ("-do-" swallowed by the honor
        // match), leaving extractDate() with nothing — it must still
        // inherit the last real date rather than end up blank.
        $ronquillo = $byName['RONQUILLO, DEANNE LOUISE.'];
        $this->assertSame('CUM LAUDE', $ronquillo['latin_honor']);
        $this->assertSame('2026-04-24', $ronquillo['graduation_date']);

        // Ditto row after the CUM LAUDE tier change follows the new tier.
        $maurillo = $byName['MAURILLO, HANNAH MAEN,'];
        $this->assertSame('CUM LAUDE', $maurillo['latin_honor']);
        $this->assertSame('2026-04-24', $maurillo['graduation_date']);

        // Hyphenated course suffixes the keyword list doesn't spell out
        // in full are still captured whole, not truncated to "BSBA".
        $this->assertSame('BSBA-MM', $byName['SOMBISE, CARINA ELISE']['course']);
        $this->assertSame('BSBA-HRM', $byName['MAESTRE, DONNA KAREN.']['course']);

        $this->assertArrayNotHasKey('TAMADO, DON FDWARD T.', $byName);
    }

    public function testDittoRowWithoutARecognizableCourseIsSkipped(): void
    {
        // A ditto row is recognized by anchoring on its course keyword —
        // without one there's no reliable boundary between name and
        // honor/date noise, so (consistent with other unparsable lines)
        // it's left out rather than guessed at.
        $text = "JUAN DELA CRUZ    BSA    CUM LAUDE    JUNE 26, 2026\n"
            . "MARIA SANTOS    -do-    -do-\n";
        $extractor = new \GenericExtractor();
        $rows = $this->callParseRows($extractor, $text);

        $this->assertCount(1, $rows);
        $this->assertSame('JUAN DELA CRUZ', $rows[0]['graduate_name']);
    }

    public function testFirstRowCannotBeADittoAndProducesNoRowIfItIs(): void
    {
        // With nothing preceding it, a would-be ditto row has no honor to
        // inherit and is dropped rather than silently invented.
        $text = "JUAN DELA CRUZ    BSA    -do-    -do-\n";
        $extractor = new \GenericExtractor();
        $rows = $this->callParseRows($extractor, $text);

        $this->assertCount(1, $rows);
        $this->assertSame('', $rows[0]['latin_honor']);
        $this->assertNull($rows[0]['graduation_date']);
    }

    public function testUnparsableProseLineWithoutHonorOrDittoIsSkipped(): void
    {
        $text = "This is a list of honor graduates for school year 2025-2026.\n"
            . "JUAN DELA CRUZ    BSA    CUM LAUDE    JUNE 26, 2026\n";
        $extractor = new \GenericExtractor();
        $rows = $this->callParseRows($extractor, $text);

        $this->assertCount(1, $rows);
        $this->assertSame('JUAN DELA CRUZ', $rows[0]['graduate_name']);
    }

    public function testBsremCourseAbbreviationIsRecognized(): void
    {
        $text = "DEGREE/COURSE\n2 AVELINO, JOHN ARVIN BALDESCO BSREM CUM LAUDE JUNE 26, 2026\n";
        $extractor = new \GenericExtractor();
        $rows = $this->callParseRows($extractor, $text);

        $this->assertCount(1, $rows);
        $this->assertSame('BSREM', $rows[0]['course']);
    }

    public function testHonorPhraseSplitAcrossWrappedCourseLinesIsRecovered(): void
    {
        // tests/fixtures/wrapped-course-honor-split-ocr-output.txt is
        // verbatim Tesseract output of a real school's honor list (16
        // graduates, name-first layout) whose Course column wraps onto 2-3
        // lines per row. That wrapping pushes the honor phrase itself
        // across the OCR line boundary — e.g. one line ends "... Magna Cum"
        // and the next reads "Accountancy Laude July 04, 2025" — which
        // used to mean matchHonor() found the phrase on neither line and
        // the whole row, name included, was silently dropped.
        $text = file_get_contents(__DIR__ . '/../fixtures/wrapped-course-honor-split-ocr-output.txt');
        $extractor = new \GenericExtractor();
        $rows = $this->callParseRows($extractor, $text);

        // 13 of the 16 real graduates are recoverable this way. The other
        // 3 are lost to OCR failures no text heuristic can fix: one row's
        // "CUM" was truncated mid-word ("M Cu"), one's honor OCR'd as
        // unrecognizable noise ("Magne a"), and one row sat on a colored
        // highlight Tesseract couldn't read at all.
        $this->assertCount(13, $rows);

        $byName = [];
        foreach ($rows as $row) {
            $byName[$row['graduate_name']] = $row;
        }

        // Every recovered row's honor tier and date must be correct — the
        // whole point of the fix — even though course text is sometimes
        // truncated to its first visual line (acceptable: OCR rows are
        // already flagged "OCR — VERIFY" for a human to tidy up).
        $this->assertArrayHasKey('Contajo, Erika T.', $byName);
        $this->assertSame('SUMMA CUM LAUDE', $byName['Contajo, Erika T.']['latin_honor']);
        $this->assertSame('2025-07-04', $byName['Contajo, Erika T.']['graduation_date']);

        $this->assertSame('MAGNA CUM LAUDE', $byName['Parrilla, Jejomar V.']['latin_honor']);
        $this->assertSame('2025-07-04', $byName['Parrilla, Jejomar V.']['graduation_date']);

        $this->assertSame('CUM LAUDE', $byName['Dacal, Leo Meian E.']['latin_honor']);
        $this->assertSame('2025-07-04', $byName['Dacal, Leo Meian E.']['graduation_date']);

        // "ACHELOR" (Bachelor with its decorative leading "B" dropped by
        // OCR) still anchors the course split correctly.
        $this->assertArrayHasKey('Binueza, Jewel D.', $byName);
        $this->assertStringContainsStringIgnoringCase('achelor of Science', $byName['Binueza, Jewel D.']['course']);
    }

    public function testHonorPhraseMergeNeverBleedsIntoAnUnrelatedFollowingRow(): void
    {
        // Regression guard for the fix above: a genuine ditto row (no honor
        // phrase of its own) must never be merged with a real, unrelated
        // row that happens to follow it just because that row's honor
        // phrase is technically "the next line".
        $text = "JUAN DELA CRUZ    BSA    -do-    -do-\n"
            . "MARIA SANTOS    BSA    MAGNA CUM LAUDE    APRIL 24, 2026\n";
        $extractor = new \GenericExtractor();
        $rows = $this->callParseRows($extractor, $text);

        $this->assertCount(2, $rows);
        $this->assertSame('MARIA SANTOS', $rows[1]['graduate_name']);
        $this->assertSame('MAGNA CUM LAUDE', $rows[1]['latin_honor']);
    }

    public function testFusedCumLaudeWithNoSpaceStillMatches(): void
    {
        // Real OCR sometimes drops the space entirely ("SUMMA CUMLAUDE")
        // rather than adding one — the loosened gap between CUM and LAUDE
        // must still accept a zero-width gap, not just a wider one.
        $text = "DEGREE/COURSE\n1 ABELLAR, JOMARI JESS S. BSREM SUMMA CUMLAUDE APRIL 24, 2026\n";
        $extractor = new \GenericExtractor();
        $rows = $this->callParseRows($extractor, $text);

        $this->assertCount(1, $rows);
        $this->assertSame('SUMMA CUM LAUDE', $rows[0]['latin_honor']);
    }

    public function testBpedAndBsabCourseAbbreviationsAreRecognized(): void
    {
        // tests/fixtures/bped-bsab-course-ocr-output.txt is verbatim
        // Tesseract output of a real school's honor list (38 graduates)
        // whose Course column uses "BPED" (Bachelor of Physical Education)
        // and "BSAB" (Bachelor of Science in Agribusiness) — abbreviations
        // that weren't in COURSE_KEYWORD_PATTERN, so every row using them
        // was silently dropped (no anchor to find the course/name split on).
        $text = file_get_contents(__DIR__ . '/../fixtures/bped-bsab-course-ocr-output.txt');
        $extractor = new \GenericExtractor();
        $rows = $this->callParseRows($extractor, $text);

        $byName = [];
        foreach ($rows as $row) {
            $byName[$row['graduate_name']] = $row;
        }

        $this->assertArrayHasKey('Magbanua, Christine Key P.', $byName);
        $this->assertSame('BPED', $byName['Magbanua, Christine Key P.']['course']);
        $this->assertSame('CUM LAUDE', $byName['Magbanua, Christine Key P.']['latin_honor']);

        $this->assertArrayHasKey('Corbo, Lejaen F.', $byName);
        $this->assertSame('BSAB', $byName['Corbo, Lejaen F.']['course']);

        $this->assertArrayHasKey('Dayandayan, Darwin C.', $byName);
        $this->assertSame('BSAB', $byName['Dayandayan, Darwin C.']['course']);

        // All 10 BPED rows in the source document must be recovered.
        $bpedCount = count(array_filter($rows, static fn (array $row): bool => $row['course'] === 'BPED'));
        $this->assertSame(10, $bpedCount);
    }

    public function testAdminManagedCourseKeywordIsRecognizedOnceRegistered(): void
    {
        // A course abbreviation nobody hardcoded has no anchor to split the
        // course text from the name on, until an administrator adds it via
        // the OCR Course Keywords settings page
        // (includes/ocr_keywords.php -> setExtraCourseKeywords()). Without
        // that anchor the row still parses (name-first layouts don't require
        // a course match to find the honor phrase), but "course" comes back
        // empty and the course text is left stuck inside "graduate_name".
        $text = "DEGREE/COURSE\n1 SANTOS, JUAN D. BSCRIM CUM LAUDE APRIL 24, 2026\n";

        $withoutKeyword = new \GenericExtractor();
        $rowsWithout = $this->callParseRows($withoutKeyword, $text);
        $this->assertCount(1, $rowsWithout);
        $this->assertSame('', $rowsWithout[0]['course']);
        $this->assertStringContainsString('BSCRIM', $rowsWithout[0]['graduate_name']);

        $withKeyword = new \GenericExtractor(['BSCRIM']);
        $rows = $this->callParseRows($withKeyword, $text);
        $this->assertCount(1, $rows);
        $this->assertSame('BSCRIM', $rows[0]['course']);
        $this->assertStringNotContainsString('BSCRIM', $rows[0]['graduate_name']);
    }

    public function testCustomCourseKeywordIsRegexEscapedNotInterpretedAsAPattern(): void
    {
        // A keyword containing a literal "." must match only that literal
        // character, never "any character" — proof the constructor path
        // preg_quote()'s admin-supplied keywords before compiling them in.
        $extractor = new \GenericExtractor(['B.I.T.']);

        $literalMatch = $this->callParseRows($extractor, "1 CRUZ, ANA B. B.I.T. CUM LAUDE APRIL 24, 2026\n");
        $this->assertSame('B.I.T.', $literalMatch[0]['course']);

        $wouldMatchIfDotWereWildcard = $this->callParseRows($extractor, "1 CRUZ, ANA B. BXITX CUM LAUDE APRIL 24, 2026\n");
        $this->assertSame('', $wouldMatchIfDotWereWildcard[0]['course']);
    }

    public function testSetExtraCourseKeywordsNormalizesCaseAndWhitespace(): void
    {
        $extractor = new \GenericExtractor();
        $extractor->setExtraCourseKeywords(['  bscrim  ']);

        $rows = $this->callParseRows($extractor, "1 SANTOS, JUAN D. BSCRIM CUM LAUDE APRIL 24, 2026\n");
        $this->assertCount(1, $rows);
        $this->assertSame('BSCRIM', $rows[0]['course']);
    }

    private function callParseRows(\GenericExtractor $extractor, string $text): array
    {
        $reflection = new \ReflectionMethod($extractor, 'parseRows');
        $reflection->setAccessible(true);

        return $reflection->invoke($extractor, $text);
    }
}
