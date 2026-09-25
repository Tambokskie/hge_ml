<?php

namespace Tests\Extractors;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../extractors/ExtractorInterface.php';
require_once __DIR__ . '/../../extractors/GeminiExtractor.php';

class GeminiExtractorTest extends TestCase
{
    // --- buildGeminiRequestPayload() ---

    public function testBuildRequestPayloadEmbedsThePdfAsInlineBase64Data(): void
    {
        $payload = \GeminiExtractor::buildGeminiRequestPayload('BASE64DATA==');

        $inlineData = $payload['contents'][0]['parts'][1]['inline_data'];
        $this->assertSame('application/pdf', $inlineData['mime_type']);
        $this->assertSame('BASE64DATA==', $inlineData['data']);
    }

    public function testBuildRequestPayloadIncludesANonEmptyPromptBeforeTheDocument(): void
    {
        $payload = \GeminiExtractor::buildGeminiRequestPayload('X');

        $promptText = $payload['contents'][0]['parts'][0]['text'];
        $this->assertIsString($promptText);
        $this->assertNotSame('', trim($promptText));
    }

    public function testBuildRequestPayloadForcesJsonOutput(): void
    {
        $payload = \GeminiExtractor::buildGeminiRequestPayload('X');

        $this->assertSame('application/json', $payload['generationConfig']['responseMimeType']);
    }

    // --- parseGeminiResponseRows() ---

    private function apiResponseWithText(string $text): array
    {
        return [
            'candidates' => [
                ['content' => ['parts' => [['text' => $text]]]],
            ],
        ];
    }

    public function testParseResponseRowsMapsAValidJsonArrayIntoRowShape(): void
    {
        $text = json_encode([
            ['graduate_name' => 'Juan Dela Cruz', 'course' => 'BSIT', 'latin_honor' => 'CUM LAUDE', 'graduation_date' => '2026-06-02'],
        ]);

        $rows = \GeminiExtractor::parseGeminiResponseRows($this->apiResponseWithText($text));

        $this->assertCount(1, $rows);
        $this->assertSame([
            'graduate_name' => 'Juan Dela Cruz',
            'course' => 'BSIT',
            'latin_honor' => 'CUM LAUDE',
            'graduation_date' => '2026-06-02',
            'source' => 'ai',
        ], $rows[0]);
    }

    public function testParseResponseRowsNormalizesHonorCasing(): void
    {
        $text = json_encode([
            ['graduate_name' => 'Juan Dela Cruz', 'course' => 'BSIT', 'latin_honor' => 'cum laude', 'graduation_date' => '2026-06-02'],
        ]);

        $rows = \GeminiExtractor::parseGeminiResponseRows($this->apiResponseWithText($text));

        $this->assertSame('CUM LAUDE', $rows[0]['latin_honor']);
    }

    public function testParseResponseRowsSkipsItemsWithNoGraduateName(): void
    {
        $text = json_encode([
            ['graduate_name' => '', 'course' => 'BSIT', 'latin_honor' => 'CUM LAUDE', 'graduation_date' => '2026-06-02'],
            ['graduate_name' => 'Maria Santos', 'course' => 'BSCS', 'latin_honor' => 'CUM LAUDE', 'graduation_date' => '2026-06-02'],
        ]);

        $rows = \GeminiExtractor::parseGeminiResponseRows($this->apiResponseWithText($text));

        $this->assertCount(1, $rows);
        $this->assertSame('Maria Santos', $rows[0]['graduate_name']);
    }

    public function testParseResponseRowsDoesNotDropARowJustForMissingCourseOrDate(): void
    {
        // Never silently drop a real graduate row over an incomplete field -
        // the review page's own validation already flags incomplete rows for
        // a human to fix; dropping here would make the row disappear instead.
        $text = json_encode([
            ['graduate_name' => 'Juan Dela Cruz', 'latin_honor' => '', 'graduation_date' => null],
        ]);

        $rows = \GeminiExtractor::parseGeminiResponseRows($this->apiResponseWithText($text));

        $this->assertCount(1, $rows);
        $this->assertSame('Juan Dela Cruz', $rows[0]['graduate_name']);
        $this->assertSame('', $rows[0]['course']);
        $this->assertSame('', $rows[0]['latin_honor']);
        $this->assertNull($rows[0]['graduation_date']);
    }

    public function testParseResponseRowsReturnsEmptyArrayWhenCandidatesAreMissing(): void
    {
        $rows = \GeminiExtractor::parseGeminiResponseRows(['unexpected' => 'shape']);

        $this->assertSame([], $rows);
    }

    public function testParseResponseRowsReturnsEmptyArrayWhenTextIsNotValidJson(): void
    {
        $rows = \GeminiExtractor::parseGeminiResponseRows($this->apiResponseWithText('not json at all'));

        $this->assertSame([], $rows);
    }

    public function testParseResponseRowsReturnsEmptyArrayWhenJsonIsNotAList(): void
    {
        $rows = \GeminiExtractor::parseGeminiResponseRows($this->apiResponseWithText(json_encode(['status' => 'ok'])));

        $this->assertSame([], $rows);
    }

    // --- extract() ---

    public function testExtractFailsImmediatelyWithNoApiKeyAndMakesNoNetworkCall(): void
    {
        $extractor = new \GeminiExtractor('', 'gemini-flash-latest');

        $result = $extractor->extract(__FILE__);

        $this->assertSame('failed', $result['status']);
        $this->assertSame([], $result['rows']);
    }

    public function testExtractFailsWhenFileDoesNotExist(): void
    {
        $extractor = new \GeminiExtractor('fake-key', 'gemini-flash-latest');

        $result = $extractor->extract('/no/such/file.pdf');

        $this->assertSame('failed', $result['status']);
        $this->assertSame([], $result['rows']);
    }
}
