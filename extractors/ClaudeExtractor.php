<?php

require_once __DIR__ . '/ExtractorInterface.php';
require_once __DIR__ . '/GeminiExtractor.php';

/**
 * Same job as GeminiExtractor, done by Claude (Anthropic Messages API): the
 * page(s) of a list — a PDF slice or a JPG/PNG — go to the model as they are and
 * it returns the graduate rows as JSON. Used for the API-key slots an admin
 * connected to "Claude" in Settings -> AI Extraction Settings.
 *
 * Called over plain HTTP with curl, like GeminiExtractor: this app has no
 * working Composer in its deployment environment, so the Anthropic SDK is not
 * available to it.
 *
 * Every failure (no key, unreadable file, network error, non-200, refusal,
 * truncated or unparseable output) returns the same ['status' => 'failed',
 * 'rows' => []] shape as the other extractors, so the fallback chain in
 * api/extract_pdf.php carries on unchanged. The result carries the same
 * `unreachable` / `timed_out` flags as GeminiExtractor's, plus `quota` (the key
 * hit its rate limit or has no credit left).
 */
class ClaudeExtractor implements ExtractorInterface
{
    private const DEFAULT_API_URL = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';

    /** Plenty for 2 pages of rows; a response that still hits it is treated as failed (truncated). */
    private const MAX_TOKENS = 16000;

    /** @var string[] */
    private array $apiKeys;
    private string $model;
    private string $apiUrl;
    private ?float $deadline = null;

    /**
     * @param string[]|string|null $apiKeys One key or several, tried in order (empty ones dropped).
     * @param string|null $apiUrl Override for the real endpoint — exists only so tests can point at
     *     an unreachable or local stub address.
     */
    public function __construct($apiKeys = null, ?string $model = null, ?string $apiUrl = null)
    {
        if ($apiKeys === null) {
            $apiKeys = [defined('AI_EXTRACTION_KEY_5') ? AI_EXTRACTION_KEY_5 : ''];
        } elseif (is_string($apiKeys)) {
            $apiKeys = [$apiKeys];
        }

        $this->apiKeys = array_values(array_filter($apiKeys, static fn (string $key): bool => $key !== ''));
        $this->model = $model ?? (defined('CLAUDE_MODEL') ? CLAUDE_MODEL : 'claude-opus-5-5');
        $this->apiUrl = $apiUrl ?? self::DEFAULT_API_URL;
    }

    /** See GeminiExtractor::setDeadline(): no request is started or allowed to run past this unix time. */
    public function setDeadline(float $unixTime): void
    {
        $this->deadline = $unixTime;
    }

    /**
     * @return array{status: 'ok'|'failed', rows: array<int, array<string, mixed>>, unreachable: bool, timed_out: bool, quota: bool}
     */
    public function extract(string $filePath): array
    {
        $failed = static fn (bool $unreachable = false, bool $timedOut = false, bool $quota = false): array
            => ['status' => 'failed', 'rows' => [], 'unreachable' => $unreachable, 'timed_out' => $timedOut, 'quota' => $quota];

        if (empty($this->apiKeys)) {
            return $failed();
        }

        $bytes = @file_get_contents($filePath);
        if ($bytes === false) {
            return $failed();
        }

        $payload = self::buildRequestPayload(base64_encode($bytes), GeminiExtractor::mimeTypeForPath($filePath), $this->model);

        try {
            $decoded = $this->callApi($payload);
        } catch (\Throwable $e) {
            error_log('ClaudeExtractor: API call failed: ' . $e->getMessage());
            if ($e instanceof RetryableGeminiApiException) {
                $timedOut = $e->timedOut;

                return $failed($e->httpCode === null && !$timedOut, $timedOut, in_array($e->httpCode, [429, 402], true));
            }

            return $failed();
        }

        $rows = self::parseResponseRows($decoded);
        if (empty($rows) && self::isEmptyListResponse($decoded)) {
            // The model read the page and found no graduates on it (a signature or cover page): a
            // real answer, not a failure — the caller decides whether an empty page is acceptable.
            return ['status' => 'ok', 'rows' => [], 'unreachable' => false, 'timed_out' => false, 'quota' => false];
        }
        if (empty($rows)) {
            error_log('ClaudeExtractor: no rows in the response (stop_reason=' . ($decoded['stop_reason'] ?? 'none') . ', output_tokens=' . ($decoded['usage']['output_tokens'] ?? '?') . ')');
        }

        return ['status' => empty($rows) ? 'failed' : 'ok', 'rows' => $rows, 'unreachable' => false, 'timed_out' => false, 'quota' => false];
    }

    /** Pure — the JSON schema the model's answer must follow. */
    public static function responseSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'rows' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'graduate_name' => ['type' => 'string'],
                            'course' => ['type' => 'string'],
                            'latin_honor' => ['type' => 'string', 'enum' => ['CUM LAUDE', 'MAGNA CUM LAUDE', 'SUMMA CUM LAUDE']],
                            'graduation_date' => ['anyOf' => [['type' => 'string'], ['type' => 'null']]],
                        ],
                        'required' => ['graduate_name', 'course', 'latin_honor', 'graduation_date'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['rows'],
            'additionalProperties' => false,
        ];
    }

    /** Pure — the instructions are GeminiExtractor's, with the answer wrapped in an object (structured output). */
    public static function extractionPrompt(): string
    {
        return str_replace(
            'Return ONLY the JSON array, no other text.',
            'Return the JSON array as the "rows" property of a JSON object, no other text.',
            GeminiExtractor::extractionPrompt()
        );
    }

    /**
     * Pure — builds the request body. A PDF goes in as a `document` block and a JPG/PNG as an `image`
     * block, ahead of the instructions. Thinking is left at the model's default (it cannot be turned
     * off on Claude Opus 5.5) and effort is lowered: reading a list is transcription, not reasoning,
     * and a lower effort keeps a request well inside the time limit.
     */
    public static function buildRequestPayload(string $base64, string $mimeType, string $model): array
    {
        $media = $mimeType === 'application/pdf'
            ? ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $base64]]
            : ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mimeType, 'data' => $base64]];

        return [
            'model' => $model,
            'max_tokens' => self::MAX_TOKENS,
            'output_config' => [
                'effort' => 'low',
                'format' => ['type' => 'json_schema', 'schema' => self::responseSchema()],
            ],
            'messages' => [[
                'role' => 'user',
                'content' => [$media, ['type' => 'text', 'text' => self::extractionPrompt()]],
            ]],
        ];
    }

    /** Pure — true when the model finished normally and answered with an empty `rows` list. */
    public static function isEmptyListResponse(array $decodedApiResponse): bool
    {
        if (($decodedApiResponse['stop_reason'] ?? null) !== 'end_turn') {
            return false;
        }
        foreach (($decodedApiResponse['content'] ?? []) as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $decoded = json_decode($block['text'], true);

                return is_array($decoded) && ($decoded['rows'] ?? null) === [];
            }
        }

        return false;
    }

    /**
     * Pure — turns a decoded Messages API response into the pipeline's row shape. A refusal or a
     * response cut off at max_tokens yields no rows (better to fail the chunk than to silently
     * save a partial list).
     */
    public static function parseResponseRows(array $decodedApiResponse): array
    {
        $stopReason = $decodedApiResponse['stop_reason'] ?? null;
        if ($stopReason === 'refusal' || $stopReason === 'max_tokens') {
            return [];
        }

        $text = null;
        foreach (($decodedApiResponse['content'] ?? []) as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $text = $block['text'];
                break;
            }
        }
        if ($text === null) {
            return [];
        }

        $decoded = json_decode($text, true);
        $items = is_array($decoded) ? ($decoded['rows'] ?? null) : null;
        if (!is_array($items) || !array_is_list($items)) {
            return [];
        }

        return GeminiExtractor::normalizeExtractedItems($items);
    }

    /**
     * Tries each key in turn. Within one key an overloaded API (529) or a transport failure gets a
     * couple of short-backoff retries; a rate limit (429), an exhausted credit balance or a key the
     * API rejects (401/403) moves straight on to the next key; a timeout moves on without repeating
     * (it would only wait as long again). Anything else fails at once — no key fixes a bad request.
     *
     * @throws RetryableGeminiApiException for the failures worth another key / the caller's flags
     * @throws \RuntimeException on any other response failure
     */
    private function callApi(array $payload): array
    {
        $maxAttemptsPerKey = 3;
        $lastError = null;

        foreach ($this->apiKeys as $apiKey) {
            for ($attempt = 1; $attempt <= $maxAttemptsPerKey; $attempt++) {
                if (GeminiExtractor::requestTimeoutFor($this->deadline, microtime(true)) === null) {
                    throw $lastError !== null && $lastError->timedOut
                        ? $lastError
                        : new RetryableGeminiApiException('time budget for the AI request is used up', null, true);
                }

                try {
                    return $this->requestOnce($payload, $apiKey);
                } catch (RetryableGeminiApiException $e) {
                    $lastError = $e;
                    if (in_array($e->httpCode, [429, 402, 401, 403], true) || $e->timedOut) {
                        continue 2;
                    }
                    if ($attempt < $maxAttemptsPerKey) {
                        usleep(500000 * $attempt);
                    }
                }
            }
        }

        throw $lastError;
    }

    /**
     * @throws RetryableGeminiApiException see callApi()
     * @throws \RuntimeException on any other response failure
     */
    private function requestOnce(array $payload, string $apiKey): array
    {
        $ch = curl_init($this->apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $apiKey,
                'anthropic-version: ' . self::API_VERSION,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => GeminiExtractor::requestTimeoutFor($this->deadline, microtime(true)) ?? GeminiExtractor::MIN_ATTEMPT_SECONDS,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        $connectTime = (float) curl_getinfo($ch, CURLINFO_CONNECT_TIME);
        curl_close($ch);

        if ($curlErrno === CURLE_OPERATION_TIMEDOUT && $connectTime > 0) {
            throw new RetryableGeminiApiException("curl error ({$curlErrno}): {$curlError}", null, true);
        }
        if ($curlErrno !== 0 || $response === false) {
            throw new RetryableGeminiApiException("curl error ({$curlErrno}): {$curlError}", null);
        }

        if (in_array($httpCode, [429, 529, 503, 401, 403], true)) {
            throw new RetryableGeminiApiException("Claude API returned HTTP {$httpCode}: " . substr((string) $response, 0, 500), $httpCode);
        }
        if ($httpCode === 400 && stripos((string) $response, 'credit balance') !== false) {
            // An account with no credit left answers 400, but it is a quota problem: another key may still work.
            throw new RetryableGeminiApiException('Claude API: credit balance is too low', 402);
        }
        if ($httpCode !== 200) {
            throw new \RuntimeException("Claude API returned HTTP {$httpCode}: " . substr((string) $response, 0, 500));
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Claude API response was not valid JSON');
        }

        return $decoded;
    }
}
