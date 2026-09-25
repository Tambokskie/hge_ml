<?php

namespace Tests\Extractors;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../extractors/ExtractorInterface.php';
require_once __DIR__ . '/../../extractors/GeminiExtractor.php';

class GeminiExtractorTest extends TestCase
{
    protected function tearDown(): void
    {
        if (file_exists(\GeminiExtractor::QUOTA_STATUS_FILE)) {
            unlink(\GeminiExtractor::QUOTA_STATUS_FILE);
        }
    }

    // --- isQuotaExhausted() / the admin/upload.php notification signal ---

    public function testIsQuotaExhaustedIsFalseWhenNoStatusFileExists(): void
    {
        $this->assertFalse(\GeminiExtractor::isQuotaExhausted());
    }

    public function testIsQuotaExhaustedIsTrueRightAfterBeingMarked(): void
    {
        $this->writeQuotaStatus(date('Y-m-d H:i:s'));

        $this->assertTrue(\GeminiExtractor::isQuotaExhausted());
    }

    public function testIsQuotaExhaustedIsFalseOnceTheFlagIsStale(): void
    {
        // Older than the 24h free-tier-daily-reset assumption -> treated as
        // no longer meaningful, so a page load doesn't show a permanently
        // stuck "AI unavailable" notice long after quota would have reset.
        $this->writeQuotaStatus(date('Y-m-d H:i:s', time() - 90000));

        $this->assertFalse(\GeminiExtractor::isQuotaExhausted());
    }

    public function testIsQuotaExhaustedIsFalseOnCorruptStatusFile(): void
    {
        $dir = dirname(\GeminiExtractor::QUOTA_STATUS_FILE);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents(\GeminiExtractor::QUOTA_STATUS_FILE, 'not json');

        $this->assertFalse(\GeminiExtractor::isQuotaExhausted());
    }

    private function writeQuotaStatus(string $exhaustedAt): void
    {
        $dir = dirname(\GeminiExtractor::QUOTA_STATUS_FILE);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents(\GeminiExtractor::QUOTA_STATUS_FILE, json_encode(['exhausted_at' => $exhaustedAt]));
    }

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

    public function testExtractFailsImmediatelyWhenAllKeysInAnArrayAreEmpty(): void
    {
        // A configured-but-blank second key (GEMINI_API_KEY_2 left unset)
        // must not itself count as "a key to try" once filtered.
        $extractor = new \GeminiExtractor(['', ''], 'gemini-flash-latest');

        $result = $extractor->extract(__FILE__);

        $this->assertSame('failed', $result['status']);
        $this->assertSame([], $result['rows']);
    }

    public function testExtractAcceptsAnArrayOfKeysWithoutErroring(): void
    {
        // Two real-looking keys, no network reachable in this test (the file
        // doesn't exist, so extract() fails before any key is ever used) —
        // this only proves the array-of-keys constructor path doesn't throw
        // a type error; live key rotation is verified against the real API.
        $extractor = new \GeminiExtractor(['key-one', 'key-two'], 'gemini-flash-latest');

        $result = $extractor->extract('/no/such/file.pdf');

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
