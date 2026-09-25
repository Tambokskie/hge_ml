<?php

require_once __DIR__ . '/ExtractorInterface.php';

/**
 * Internal signal that a Gemini API failure is worth a short retry (429/503).
 * Carries the HTTP status so the caller can tell "this specific key is out of
 * quota, try the next one" (429) apart from "the model is overloaded right
 * now, wait a moment and try the same key again" (503, shared infrastructure
 * — a second key doesn't route around it, but is tried as a last resort
 * after this key's own retries are exhausted).
 */
class RetryableGeminiApiException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $httpCode)
    {
        parent::__construct($message);
    }
}

/**
 * Primary extraction path: sends the PDF directly to Gemini and asks it to
 * read the document and return structured JSON, rather than reading a text
 * layer or OCR output and pattern-matching it line by line. Unlike
 * GenericExtractor/OcrExtractor, this understands layout (wrapped table
 * cells, borderless multi-column lists, narrative certification letters)
 * without needing a bespoke handler per layout.
 *
 * Every failure mode (no API key configured, unreadable file, network error,
 * non-200 response, unparseable/empty output) returns the same
 * ['status' => 'failed', 'rows' => []] shape as the other extractors, so
 * api/extract_pdf.php's existing GenericExtractor -> OcrExtractor fallback
 * chain runs unchanged whenever this can't produce a result.
 */
class GeminiExtractor implements ExtractorInterface
{
    /**
     * Shared flag (all keys/every admin, not per-session) recording the last
     * time every configured key came back quota-exhausted (429) — read by
     * admin/upload.php to show a "free AI extraction is currently
     * unavailable" notice. Lives under storage/ (already .htaccess-denied to
     * direct web access, same as storage/pdfs and storage/templates) rather
     * than the database, since it's disposable, ephemeral cache state, not a
     * record worth a schema change.
     */
    public const QUOTA_STATUS_FILE = __DIR__ . '/../storage/cache/gemini_quota_status.json';

    /** How long a "quota exhausted" flag is treated as still meaningful — the
     * free tier's daily quota resets roughly every 24h, so beyond that the
     * flag is stale rather than proof anything is still wrong. */
    private const QUOTA_STATUS_FRESH_SECONDS = 86400;

    /** @var string[] */
    private array $apiKeys;
    private string $model;

    /**
     * @param string[]|string|null $apiKeys One key, several (tried in order —
     *     a second free-tier key from a separate Google account roughly
     *     doubles the effective daily quota), or null to read
     *     GEMINI_API_KEY/GEMINI_API_KEY_2 from config/gemini.php. Empty
     *     values are dropped, so an unconfigured second key is simply not
     *     tried rather than causing an error.
     */
    public function __construct($apiKeys = null, ?string $model = null)
    {
        if ($apiKeys === null) {
            $apiKeys = [
                defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '',
                defined('GEMINI_API_KEY_2') ? GEMINI_API_KEY_2 : '',
            ];
        } elseif (is_string($apiKeys)) {
            $apiKeys = [$apiKeys];
        }

        $this->apiKeys = array_values(array_filter($apiKeys, static fn (string $key): bool => $key !== ''));
        $this->model = $model ?? (defined('GEMINI_MODEL') ? GEMINI_MODEL : 'gemini-flash-latest');
    }

    public function extract(string $filePath): array
    {
        if (empty($this->apiKeys)) {
            return ['status' => 'failed', 'rows' => []];
        }

        $pdfBytes = @file_get_contents($filePath);
        if ($pdfBytes === false) {
            return ['status' => 'failed', 'rows' => []];
        }

        $payload = self::buildGeminiRequestPayload(base64_encode($pdfBytes));

        try {
            $decoded = $this->callGeminiApi($payload);
        } catch (\Throwable $e) {
            error_log('GeminiExtractor: API call failed: ' . $e->getMessage());
            // Only a 429 on the last key tried means every configured key is
            // genuinely out of quota — a 503 or transport error says nothing
            // about quota, so it must not trigger this notice.
            if ($e instanceof RetryableGeminiApiException && $e->httpCode === 429) {
                self::markQuotaExhausted();
            }
            return ['status' => 'failed', 'rows' => []];
        }

        self::clearQuotaExhausted();

        $rows = self::parseGeminiResponseRows($decoded);

        return ['status' => empty($rows) ? 'failed' : 'ok', 'rows' => $rows];
    }

    /**
     * Pure — builds the request body. No network I/O, unit-testable directly.
     */
    public static function buildGeminiRequestPayload(string $pdfBase64): array
    {
        return [
            'contents' => [[
                'parts' => [
                    ['text' => self::extractionPrompt()],
                    ['inline_data' => ['mime_type' => 'application/pdf', 'data' => $pdfBase64]],
                ],
            ]],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
            ],
        ];
    }

    /**
     * Pure — turns a decoded Gemini API response into the row shape the rest
     * of the extraction pipeline expects. A row is only skipped when it has
     * no graduate name at all; every other missing/invalid field is passed
     * through as-is (never silently dropping a real graduate row) so the
     * review page's own validation flags it INCOMPLETE for a human to fix,
     * the same way a hand-typed row with a blank field would be.
     */
    public static function parseGeminiResponseRows(array $decodedApiResponse): array
    {
        $text = $decodedApiResponse['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if (!is_string($text)) {
            return [];
        }

        $items = json_decode($text, true);
        if (!is_array($items) || array_is_list($items) === false) {
            return [];
        }

        $rows = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $name = trim((string) ($item['graduate_name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $honor = mb_strtoupper(trim((string) ($item['latin_honor'] ?? '')));
            $date = $item['graduation_date'] ?? null;

            $rows[] = [
                'graduate_name' => $name,
                'course' => trim((string) ($item['course'] ?? '')),
                'latin_honor' => $honor,
                'graduation_date' => (is_string($date) && $date !== '') ? $date : null,
                'source' => 'ai',
            ];
        }

        return $rows;
    }

    /**
     * Tries each configured key in turn. Within one key: a 503 (model
     * temporarily overloaded — shared infrastructure, not this key's fault)
     * gets a couple of short-backoff retries on that same key, since a
     * different key wouldn't route around it anyway. A 429 (this key's own
     * quota is exhausted) skips straight to the next key with no wasted
     * wait — that's the entire point of having a second key. Only after
     * every key has been tried does this give up; anything else (bad
     * request, malformed response) fails immediately without burning
     * another key on a problem no key can fix.
     *
     * @throws \RuntimeException on any transport/response failure
     */
    private function callGeminiApi(array $payload): array
    {
        $maxAttemptsPerKey = 3;
        $lastError = null;

        foreach ($this->apiKeys as $apiKey) {
            for ($attempt = 1; $attempt <= $maxAttemptsPerKey; $attempt++) {
                try {
                    return $this->requestOnce($payload, $apiKey);
                } catch (RetryableGeminiApiException $e) {
                    $lastError = $e;
                    if ($e->httpCode === 429) {
                        continue 2; // this key is out of quota — next key, no wait
                    }
                    if ($attempt < $maxAttemptsPerKey) {
                        usleep(500000 * $attempt); // 503: 0.5s, then 1s, same key
                    }
                }
            }
        }

        // Re-thrown as-is (not wrapped) so extract() can see whether the very
        // last failure was specifically a 429 — the only signal worth
        // recording as "quota exhausted" for the admin/upload.php notice.
        throw $lastError;
    }

    /**
     * @throws RetryableGeminiApiException on 429/503 (caller retries/rotates keys)
     * @throws \RuntimeException on any other transport/response failure
     */
    private function requestOnce(array $payload, string $apiKey): array
    {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
            . rawurlencode($this->model) . ':generateContent?key=' . rawurlencode($apiKey);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 90,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlErrno !== 0 || $response === false) {
            throw new \RuntimeException("curl error ({$curlErrno}): {$curlError}");
        }
        if ($httpCode === 429 || $httpCode === 503) {
            throw new RetryableGeminiApiException("Gemini API returned HTTP {$httpCode}: " . substr((string) $response, 0, 500), $httpCode);
        }
        if ($httpCode !== 200) {
            throw new \RuntimeException("Gemini API returned HTTP {$httpCode}: " . substr((string) $response, 0, 500));
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Gemini API response was not valid JSON');
        }

        return $decoded;
    }

    /**
     * Read by admin/upload.php to decide whether to show a "free AI
     * extraction is currently unavailable" notice. A missing, unreadable, or
     * corrupt status file is treated the same as "not exhausted" — this is
     * an optional convenience notice, never something that should itself
     * break the upload page.
     */
    public static function isQuotaExhausted(): bool
    {
        $raw = @file_get_contents(self::QUOTA_STATUS_FILE);
        if ($raw === false) {
            return false;
        }

        $data = json_decode($raw, true);
        $exhaustedAt = is_array($data) ? ($data['exhausted_at'] ?? null) : null;
        if (!is_string($exhaustedAt)) {
            return false;
        }

        $timestamp = strtotime($exhaustedAt);
        if ($timestamp === false) {
            return false;
        }

        return (time() - $timestamp) < self::QUOTA_STATUS_FRESH_SECONDS;
    }

    private static function markQuotaExhausted(): void
    {
        $dir = dirname(self::QUOTA_STATUS_FILE);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents(self::QUOTA_STATUS_FILE, json_encode(['exhausted_at' => date('Y-m-d H:i:s')]), LOCK_EX);
    }

    private static function clearQuotaExhausted(): void
    {
        if (file_exists(self::QUOTA_STATUS_FILE)) {
            @unlink(self::QUOTA_STATUS_FILE);
        }
    }

    private static function extractionPrompt(): string
    {
        return <<<PROMPT
You are extracting a Latin Honor graduate list from a scanned PDF for a
Philippine government eligibility database. Read the document and return a
JSON array. Each element must have exactly these fields:
- graduate_name: the full name of the honor graduate, exactly as printed (do not reorder or reformat)
- course: the degree/course exactly as printed
- latin_honor: one of "CUM LAUDE", "MAGNA CUM LAUDE", "SUMMA CUM LAUDE"
- graduation_date: the date of graduation in YYYY-MM-DD format

Rules:
- Only include actual honor graduates listed in the table/list.
- Do NOT include signatories, registrars, deans, campus directors, or anyone in an "Approved by" / signature block — they are not graduates.
- If the document is a narrative letter (not a table) listing names under section headers (e.g. MALE/FEMALE), still extract every individual named as a graduate, using the honor/course/date stated in the surrounding text for all of them.
- Return ONLY the JSON array, no other text.
PROMPT;
    }
}
