<?php

namespace Tests\Extractors;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../extractors/ExtractorInterface.php';
require_once __DIR__ . '/../../extractors/ClaudeExtractor.php';

/** ClaudeExtractor: the pure request/response halves, and the failures that need no network. */
class ClaudeExtractorTest extends TestCase
{
    private function response(string $text, string $stopReason = 'end_turn'): array
    {
        return ['stop_reason' => $stopReason, 'content' => [['type' => 'text', 'text' => $text]]];
    }

    public function testAPdfGoesInAsADocumentBlockAheadOfTheInstructions(): void
    {
        $payload = \ClaudeExtractor::buildRequestPayload('QkFTRTY0', 'application/pdf', 'claude-opus-5-5');

        $this->assertSame('claude-opus-5-5', $payload['model']);
        $content = $payload['messages'][0]['content'];
        $this->assertSame('document', $content[0]['type']);
        $this->assertSame(['type' => 'base64', 'media_type' => 'application/pdf', 'data' => 'QkFTRTY0'], $content[0]['source']);
        $this->assertSame('text', $content[1]['type']);
        $this->assertStringContainsString('"rows" property', $content[1]['text']);
        $this->assertStringNotContainsString('Return ONLY the JSON array', $content[1]['text']);
    }

    public function testAnImageGoesInAsAnImageBlockWithItsOwnMediaType(): void
    {
        $payload = \ClaudeExtractor::buildRequestPayload('QUJD', 'image/png', 'claude-opus-5-5');

        $block = $payload['messages'][0]['content'][0];
        $this->assertSame('image', $block['type']);
        $this->assertSame('image/png', $block['source']['media_type']);
    }

    public function testTheRequestAsksForSchemaShapedJsonAndSendsNoParametersTheModelRejects(): void
    {
        $payload = \ClaudeExtractor::buildRequestPayload('QUJD', 'application/pdf', 'claude-opus-5-5');

        $format = $payload['output_config']['format'];
        $this->assertSame('json_schema', $format['type']);
        $this->assertSame(['rows'], $format['schema']['required']);
        $this->assertSame(
            ['CUM LAUDE', 'MAGNA CUM LAUDE', 'SUMMA CUM LAUDE'],
            $format['schema']['properties']['rows']['items']['properties']['latin_honor']['enum']
        );
        foreach (['thinking', 'temperature', 'top_p', 'top_k', 'tool_choice'] as $rejected) {
            $this->assertArrayNotHasKey($rejected, $payload);
        }
        $this->assertGreaterThan(0, $payload['max_tokens']);
    }

    public function testRowsAreMappedIntoThePipelineShape(): void
    {
        $text = json_encode(['rows' => [
            ['graduate_name' => 'REYES, ANA', 'course' => 'BSA', 'latin_honor' => 'cum laude', 'graduation_date' => '2026-04-01'],
            ['graduate_name' => 'SANTOS, JOSE', 'course' => 'BSN', 'latin_honor' => 'SUMMA CUM LAUDE', 'graduation_date' => null],
        ]]);

        $rows = \ClaudeExtractor::parseResponseRows($this->response($text));

        $this->assertCount(2, $rows);
        $this->assertSame(['graduate_name' => 'REYES, ANA', 'course' => 'BSA', 'latin_honor' => 'CUM LAUDE', 'graduation_date' => '2026-04-01', 'source' => 'ai'], $rows[0]);
        $this->assertNull($rows[1]['graduation_date']);
    }

    public function testARowWithNoNameIsSkippedButNothingElseIsDropped(): void
    {
        $text = json_encode(['rows' => [
            ['graduate_name' => '  ', 'course' => 'BSA', 'latin_honor' => 'CUM LAUDE', 'graduation_date' => null],
            ['graduate_name' => 'DELA CRUZ, JUAN', 'course' => '', 'latin_honor' => 'MAGNA CUM LAUDE', 'graduation_date' => ''],
        ]]);

        $rows = \ClaudeExtractor::parseResponseRows($this->response($text));

        $this->assertCount(1, $rows);
        $this->assertSame('DELA CRUZ, JUAN', $rows[0]['graduate_name']);
        $this->assertNull($rows[0]['graduation_date']);
    }

    /** @return array<string, array{0: array}> */
    public static function unusableResponses(): array
    {
        $good = json_encode(['rows' => [['graduate_name' => 'A B', 'course' => 'X', 'latin_honor' => 'CUM LAUDE', 'graduation_date' => null]]]);

        return [
            'a refusal' => [['stop_reason' => 'refusal', 'content' => [['type' => 'text', 'text' => $good]]]],
            'cut off at max_tokens' => [['stop_reason' => 'max_tokens', 'content' => [['type' => 'text', 'text' => $good]]]],
            'no text block' => [['stop_reason' => 'end_turn', 'content' => [['type' => 'thinking', 'thinking' => '']]]],
            'not JSON' => [['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'sorry']]]],
            'no rows property' => [['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => '[]']]]],
            'rows is not a list' => [['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => '{"rows":{"a":1}}']]]],
            'empty response' => [[]],
        ];
    }

    /** @dataProvider unusableResponses */
    public function testAnUnusableResponseYieldsNoRows(array $response): void
    {
        $this->assertSame([], \ClaudeExtractor::parseResponseRows($response));
    }

    public function testTheTextBlockIsFoundAfterAThinkingBlock(): void
    {
        $text = json_encode(['rows' => [['graduate_name' => 'A B', 'course' => 'X', 'latin_honor' => 'CUM LAUDE', 'graduation_date' => null]]]);
        $response = ['stop_reason' => 'end_turn', 'content' => [['type' => 'thinking', 'thinking' => ''], ['type' => 'text', 'text' => $text]]];

        $this->assertCount(1, \ClaudeExtractor::parseResponseRows($response));
    }

    public function testExtractFailsAtOnceWithNoKeyAndMakesNoNetworkCall(): void
    {
        $extractor = new \ClaudeExtractor([], null, 'http://127.0.0.1:1/v1/messages');

        $result = $extractor->extract(__FILE__);

        $this->assertSame('failed', $result['status']);
        $this->assertFalse($result['unreachable']);
    }

    public function testExtractDropsEmptyKeysAndFailsWhenTheFileIsMissing(): void
    {
        $extractor = new \ClaudeExtractor(['', ''], null, 'http://127.0.0.1:1/v1/messages');
        $this->assertSame('failed', $extractor->extract(__FILE__)['status']);

        $extractor = new \ClaudeExtractor(['sk-ant-test'], null, 'http://127.0.0.1:1/v1/messages');
        $this->assertSame('failed', $extractor->extract(__DIR__ . '/no-such-file.pdf')['status']);
    }

    public function testAnUnreachableServiceIsReportedAsUnreachableNotAsATimeout(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'cle');
        file_put_contents($file, '%PDF-1.4 test');
        $extractor = new \ClaudeExtractor(['sk-ant-test'], null, 'http://127.0.0.1:1/v1/messages');

        $result = $extractor->extract($file);
        unlink($file);

        $this->assertSame('failed', $result['status']);
        $this->assertTrue($result['unreachable']);
        $this->assertFalse($result['timed_out']);
        $this->assertFalse($result['quota']);
    }
}
