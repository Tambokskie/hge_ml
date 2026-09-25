<?php

require_once __DIR__ . '/ExtractorInterface.php';

/** Internal signal that a Gemini API failure is worth a short retry (429/503). */
class RetryableGeminiApiException extends \RuntimeException
{
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
    private string $apiKey;
    private string $model;

    public function __construct(?string $apiKey = null, ?string $model = null)
    {
        $this->apiKey = $apiKey ?? (defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '');
        $this->model = $model ?? (defined('GEMINI_MODEL') ? GEMINI_MODEL : 'gemini-flash-latest');
    }

    public function extract(string $filePath): array
    {
        if ($this->apiKey === '') {
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
            return ['status' => 'failed', 'rows' => []];
        }

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
     * Retries a couple of times on 429 (rate limited) / 503 (temporarily
     * overloaded) specifically — both observed in practice on the free tier
     * under load — since the whole point of this being the *primary* path is
     * that a transient blip on Google's side shouldn't hand every upload
     * straight to the OCR fallback. Anything else (bad request, auth
     * failure, malformed response) fails immediately; retrying those would
     * just waste time before the fallback that's actually going to work.
     *
     * @throws \RuntimeException on any transport/response failure
     */
    private function callGeminiApi(array $payload): array
    {
        $maxAttempts = 3;
        $lastError = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return $this->requestOnce($payload);
            } catch (RetryableGeminiApiException $e) {
                $lastError = $e;
                if ($attempt < $maxAttempts) {
                    usleep(500000 * $attempt); // 0.5s, then 1s
                }
            }
        }

        throw new \RuntimeException($lastError->getMessage());
    }

    /**
     * @throws RetryableGeminiApiException on 429/503 (caller retries)
     * @throws \RuntimeException on any other transport/response failure
     */
    private function requestOnce(array $payload): array
    {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
            . rawurlencode($this->model) . ':generateContent?key=' . rawurlencode($this->apiKey);

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
            throw new RetryableGeminiApiException("Gemini API returned HTTP {$httpCode}: " . substr((string) $response, 0, 500));
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
