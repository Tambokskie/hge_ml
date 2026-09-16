# OCR Fallback Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an automatic OCR fallback (local Tesseract + Ghostscript) to the PDF extraction pipeline, so a scanned/image-only PDF that `GenericExtractor` can't read is retried via OCR before the admin has to fall back to manual entry.

**Architecture:** The line-parsing logic shared between `GenericExtractor` (real text layer) and a new `OcrExtractor` (rasterize pages via Ghostscript, OCR each via Tesseract) moves into a shared trait. `api/extract_pdf.php` tries `GenericExtractor` first and automatically retries with `OcrExtractor` only if that fails. Every extracted row carries a `source` tag (`'text'`, `'ocr'`, or — once a human edits it in the review UI — `'manual'`) so `admin/review.php` can flag OCR-derived rows for extra scrutiny.

**Tech Stack:** PHP 8.2, `smalot/pdfparser` (existing), `thiagoalessio/tesseract_ocr` (new Composer dependency), Ghostscript CLI (new OS-level binary), Tesseract OCR CLI (new OS-level binary), PHPUnit 10.

**Spec:** `docs/superpowers/specs/2026-09-12-hge-database-design.md`, section 17 (OCR fallback addendum) — read this before starting, all decisions including the `\s*` regex fix are final and already empirically validated against real OCR output.

## Global Constraints

- OCR runs synchronously, in the same request as today's extraction — no queue/async infrastructure exists anywhere in this project and none is added here.
- Automatic fallback only: `GenericExtractor` is always tried first; `OcrExtractor` is only invoked when `GenericExtractor`'s result is `status: 'failed'`. There is no user-facing toggle or separate "try OCR" button.
- Provenance rule: any row that goes through `set_staged_row()` (backing both ADD RECORD and EDIT in the review UI) gets `'source' => 'manual'`, regardless of what it was before. Only a row still exactly as an extractor produced it keeps `'text'` or `'ocr'`.
- The `\s*` regex fix (loosened from `\s+`) applies to **both** extractors via the shared trait — it is not an OCR-only patch. This is deliberate: it closes a real bug (silent misclassification/silent row-loss) and is strictly safer for clean text-based input too.
- Binary paths are configurable via `TESSERACT_PATH` / `GHOSTSCRIPT_PATH` env vars, defaulting to the exact confirmed-working paths on this dev machine: `C:\Program Files\Tesseract-OCR\tesseract.exe` and `C:\Program Files\gs\gs10.08.0\bin\gswin64c.exe` — same `getenv() ?: <default>` pattern `config/database.php` already uses.
- `php` is not on PATH in this environment — every test run uses the full path `C:/xampp/php/php.exe`.
- All user-facing strings stay UPPERCASE, matching every existing message in this project.
- All DB access uses PDO prepared statements — unaffected by this plan (no schema changes), but any new SQL must follow this rule.

---

### Task 1: Shared row-parsing trait + `GenericExtractor` refactor + regex fix

**Files:**
- Create: `extractors/ParsesHonorRows.php`
- Modify: `extractors/generic_extractor.php`
- Modify: `extractors/ExtractorInterface.php`
- Test: `tests/extractors/GenericExtractorTest.php`

**Interfaces:**
- Produces (for Task 2 to consume): `trait ParsesHonorRows` with a `protected function parseRows(string $text): array` method — splits `$text` on line breaks, runs each line through honor/name/date matching, returns an array of `['graduate_name' => string, 'latin_honor' => string, 'graduation_date' => ?string]` rows (no `source` key — callers add that themselves, since it differs per extractor).
- `ExtractorInterface::extract()`'s return shape gains a `source` key per row, documented as `'text'|'ocr'` at the interface level (`'manual'` is a review-UI-only value added later by Task 3, never by an extractor).

- [ ] **Step 1: Write the failing tests in `tests/extractors/GenericExtractorTest.php`**

Add this new test method at the end of the class, right before the closing `}`:

```php
    public function testEveryRowCarriesTextSourceTag(): void
    {
        $result = (new \GenericExtractor())->extract($this->sample);

        $this->assertNotEmpty($result['rows']);
        foreach ($result['rows'] as $row) {
            $this->assertSame('text', $row['source']);
        }
    }
```

- [ ] **Step 2: Run the test file to confirm the new test fails**

Run: `"/c/xampp/php/php.exe" vendor/bin/phpunit tests/extractors/GenericExtractorTest.php`
Expected: the new `testEveryRowCarriesTextSourceTag` test FAILS (`source` key doesn't exist yet); the other 6 existing tests still PASS (they don't check for `source` at all).

- [ ] **Step 3: Create `extractors/ParsesHonorRows.php`**

```php
<?php

trait ParsesHonorRows
{
    protected function parseRows(string $text): array
    {
        $rows = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            $row = $this->parseLine($line);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function parseLine(string $line): ?array
    {
        $honor = $this->normalizeLatinHonor($line);
        if ($honor === null) {
            return null;
        }

        $columns = preg_split('/\s{2,}/', trim($line));
        $name = trim($columns[0] ?? '');
        if ($name === '') {
            return null;
        }

        return [
            'graduate_name' => $name,
            'latin_honor' => $honor,
            'graduation_date' => $this->extractDate($line),
        ];
    }

    private function normalizeLatinHonor(string $text): ?string
    {
        if (preg_match('/SUMMA\s*CUM\s*LAUDE/i', $text)) {
            return 'SUMMA CUM LAUDE';
        }
        if (preg_match('/MAGNA\s*CUM\s*LAUDE/i', $text)) {
            return 'MAGNA CUM LAUDE';
        }
        if (preg_match('/CUM\s*LAUDE/i', $text)) {
            return 'CUM LAUDE';
        }

        return null;
    }

    private function extractDate(string $text): ?string
    {
        $patterns = [
            '/\b(\d{1,2})\s+([A-Za-z]{3,9})\.?\s+(\d{4})\b/',
            '/\b([A-Za-z]{3,9})\.?\s+(\d{1,2}),?\s+(\d{4})\b/',
            '/\b(\d{4})-(\d{2})-(\d{2})\b/',
            '/\b(\d{1,2})\/(\d{1,2})\/(\d{4})\b/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                $ts = strtotime($m[0]);
                if ($ts !== false) {
                    return date('Y-m-d', $ts);
                }
            }
        }

        return null;
    }
}
```

This is the exact logic already in `extractors/generic_extractor.php` today, with two changes: the three `normalizeLatinHonor()` regexes loosened from `\s+` to `\s*` (the empirically-validated fix — real Tesseract output sometimes drops the space between honor words, e.g. `"summa cum laude"` → `"summacum laude"`, which the old `\s+` patterns either misclassify as plain `CUM LAUDE` or fail to match at all), and the line-split loop extracted into a new `parseRows()` wrapper method.

- [ ] **Step 4: Replace `extractors/generic_extractor.php`**

```php
<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/ExtractorInterface.php';
require_once __DIR__ . '/ParsesHonorRows.php';

class GenericExtractor implements ExtractorInterface
{
    use ParsesHonorRows;

    public function extract(string $filePath): array
    {
        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($filePath);
            $text = $pdf->getText();
        } catch (\Throwable $e) {
            error_log('GenericExtractor: PDF parse failed: ' . $e->getMessage());
            return ['status' => 'failed', 'rows' => []];
        }

        $rows = array_map(function (array $row): array {
            $row['source'] = 'text';
            return $row;
        }, $this->parseRows($text));

        return [
            'status' => empty($rows) ? 'failed' : 'ok',
            'rows' => $rows,
        ];
    }
}
```

- [ ] **Step 5: Update `extractors/ExtractorInterface.php`'s docblock**

Replace the file with:

```php
<?php

interface ExtractorInterface
{
    /**
     * @return array{status: 'ok'|'failed', rows: array<array{graduate_name: string, latin_honor: string, graduation_date: ?string, source: 'text'|'ocr'}>}
     */
    public function extract(string $filePath): array;
}
```

- [ ] **Step 6: Run the full suite to verify everything passes**

Run: `"/c/xampp/php/php.exe" vendor/bin/phpunit`
Expected: OK, all tests passing (baseline + the new `testEveryRowCarriesTextSourceTag` test), including all 6 pre-existing `GenericExtractorTest` assertions still green (they check individual fields like `graduate_name`/`latin_honor`/`graduation_date` via `assertSame` on specific array keys, never the whole row array, so the new `source` key doesn't break them — confirmed by reading the file before writing this plan).

- [ ] **Step 7: Commit**

```bash
git add extractors/ParsesHonorRows.php extractors/generic_extractor.php extractors/ExtractorInterface.php tests/extractors/GenericExtractorTest.php
git commit -m "refactor: extract shared row-parsing trait, fix honor regex whitespace tolerance"
```

---

### Task 2: `OcrExtractor` + Composer dependency + `OcrExtractorTest`

**Files:**
- Modify: `composer.json`
- Create: `extractors/ocr_extractor.php`
- Test: `tests/extractors/OcrExtractorTest.php`

**Interfaces:**
- Consumes: `trait ParsesHonorRows` and its `parseRows(string $text): array` method from Task 1. `ExtractorInterface` (unchanged file, already updated in Task 1).
- Produces (for Task 3 to consume): `class OcrExtractor implements ExtractorInterface` with `extract(string $filePath): array`, same return shape as `GenericExtractor`, every row carrying `'source' => 'ocr'`.

- [ ] **Step 1: Add the Composer dependency**

This project has no `composer.phar` checked in (per this project's established precedent — download it fresh):

```bash
curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php
"/c/xampp/php/php.exe" /tmp/composer-setup.php --install-dir=. --filename=composer.phar
"/c/xampp/php/php.exe" composer.phar require thiagoalessio/tesseract_ocr:^2.13
rm /tmp/composer-setup.php composer.phar
```

Confirm `composer.json`'s `require` block now includes `"thiagoalessio/tesseract_ocr": "^2.13"` and that `composer.lock` was updated. Delete `composer.phar` after use (matching this project's established precedent of not checking it in).

- [ ] **Step 2: Write the failing tests in `tests/extractors/OcrExtractorTest.php`**

```php
<?php

namespace Tests\Extractors;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../extractors/ExtractorInterface.php';
require_once __DIR__ . '/../../extractors/ParsesHonorRows.php';
require_once __DIR__ . '/../../extractors/ocr_extractor.php';

class OcrExtractorTest extends TestCase
{
    private string $sample;

    protected function setUp(): void
    {
        $this->sample = __DIR__ . '/../fixtures/scanned-sample.pdf';

        $tesseractPath = getenv('TESSERACT_PATH') ?: 'C:\Program Files\Tesseract-OCR\tesseract.exe';
        $ghostscriptPath = getenv('GHOSTSCRIPT_PATH') ?: 'C:\Program Files\gs\gs10.08.0\bin\gswin64c.exe';

        if (!is_file($tesseractPath) || !is_file($ghostscriptPath)) {
            $this->markTestSkipped('Tesseract and/or Ghostscript binary not found — skipping OCR tests on this machine.');
        }
    }

    public function testExtractsAllNineRowsFromScannedPdf(): void
    {
        $result = (new \OcrExtractor())->extract($this->sample);

        $this->assertSame('ok', $result['status']);
        $this->assertCount(9, $result['rows']);
    }

    public function testEveryRowCarriesOcrSourceTag(): void
    {
        $result = (new \OcrExtractor())->extract($this->sample);

        foreach ($result['rows'] as $row) {
            $this->assertSame('ocr', $row['source']);
        }
    }

    public function testSummaCumLaudeIsNotMisclassifiedDespiteOcrDroppingTheSpace(): void
    {
        $result = (new \OcrExtractor())->extract($this->sample);

        $summaRow = null;
        foreach ($result['rows'] as $row) {
            if (stripos($row['graduate_name'], 'Amarillo') !== false) {
                $summaRow = $row;
                break;
            }
        }

        $this->assertNotNull($summaRow, 'Expected to find a row for "Amarillo" in the OCR output.');
        $this->assertSame('SUMMA CUM LAUDE', $summaRow['latin_honor']);
    }

    public function testMagnaCumLaudeRowIsNotSilentlyDroppedDespiteOcrMergingTheWords(): void
    {
        $result = (new \OcrExtractor())->extract($this->sample);

        $magnaRow = null;
        foreach ($result['rows'] as $row) {
            if (stripos($row['graduate_name'], 'Amascual') !== false) {
                $magnaRow = $row;
                break;
            }
        }

        $this->assertNotNull($magnaRow, 'Expected to find a row for "Amascual" in the OCR output — this row was silently dropped before the \s* regex fix.');
        $this->assertSame('MAGNA CUM LAUDE', $magnaRow['latin_honor']);
    }

    public function testFailedPdfParseReturnsFailedStatus(): void
    {
        $result = (new \OcrExtractor())->extract(__DIR__ . '/../fixtures/sample.pdf');

        $this->assertSame('failed', $result['status']);
        $this->assertSame([], $result['rows']);
    }
}
```

The last test (`testFailedPdfParseReturnsFailedStatus`) reuses the existing minimal malformed fixture (`tests/fixtures/sample.pdf`, already used by `GenericExtractorTest`) — Ghostscript cannot rasterize a PDF with no valid xref table, so this exercises the "Ghostscript fails" path returning `['status' => 'failed', 'rows' => []]`.

- [ ] **Step 3: Run the test file to confirm it fails**

Run: `"/c/xampp/php/php.exe" vendor/bin/phpunit tests/extractors/OcrExtractorTest.php`
Expected: FAIL — `OcrExtractor` class doesn't exist yet (`Class "OcrExtractor" not found` or similar).

- [ ] **Step 4: Create `extractors/ocr_extractor.php`**

```php
<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/ExtractorInterface.php';
require_once __DIR__ . '/ParsesHonorRows.php';

class OcrExtractor implements ExtractorInterface
{
    use ParsesHonorRows;

    public function extract(string $filePath): array
    {
        $ghostscriptPath = getenv('GHOSTSCRIPT_PATH') ?: 'C:\Program Files\gs\gs10.08.0\bin\gswin64c.exe';
        $tesseractPath = getenv('TESSERACT_PATH') ?: 'C:\Program Files\Tesseract-OCR\tesseract.exe';

        $tmpDir = sys_get_temp_dir() . '/hge_ocr_' . uniqid();

        if (!mkdir($tmpDir, 0777, true) && !is_dir($tmpDir)) {
            error_log('OcrExtractor: could not create temp directory ' . $tmpDir);
            return ['status' => 'failed', 'rows' => []];
        }

        try {
            $outputPattern = $tmpDir . '/page_%03d.png';
            $cmd = escapeshellarg($ghostscriptPath)
                . ' -dNOPAUSE -dBATCH -dSAFER -sDEVICE=png16m -r300'
                . ' -sOutputFile=' . escapeshellarg($outputPattern)
                . ' ' . escapeshellarg($filePath);

            exec($cmd . ' 2>&1', $output, $returnCode);

            $pageImages = glob($tmpDir . '/page_*.png') ?: [];
            sort($pageImages);

            if ($returnCode !== 0 || empty($pageImages)) {
                error_log('OcrExtractor: Ghostscript rasterization failed (exit ' . $returnCode . '): ' . implode(' ', $output));
                return ['status' => 'failed', 'rows' => []];
            }

            $textParts = [];
            foreach ($pageImages as $imagePath) {
                try {
                    $pageText = (new \thiagoalessio\TesseractOCR\TesseractOCR($imagePath))
                        ->executable($tesseractPath)
                        ->run();
                    $textParts[] = $pageText;
                } catch (\Throwable $e) {
                    error_log('OcrExtractor: Tesseract failed on ' . $imagePath . ': ' . $e->getMessage());
                }
            }

            $text = implode("\n", $textParts);
            $rows = array_map(function (array $row): array {
                $row['source'] = 'ocr';
                return $row;
            }, $this->parseRows($text));

            return [
                'status' => empty($rows) ? 'failed' : 'ok',
                'rows' => $rows,
            ];
        } finally {
            foreach (glob($tmpDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($tmpDir);
        }
    }
}
```

- [ ] **Step 5: Run the test file to verify it passes**

Run: `"/c/xampp/php/php.exe" vendor/bin/phpunit tests/extractors/OcrExtractorTest.php`
Expected: PASS — all 5 tests green (Tesseract and Ghostscript are genuinely installed on this dev machine, so none of them skip here).

- [ ] **Step 6: Run the full suite**

Run: `"/c/xampp/php/php.exe" vendor/bin/phpunit`
Expected: OK, all tests passing (Task 1's baseline plus the 5 new `OcrExtractorTest` tests).

- [ ] **Step 7: Commit**

```bash
git add composer.json composer.lock extractors/ocr_extractor.php tests/extractors/OcrExtractorTest.php
git commit -m "feat: add OcrExtractor with Ghostscript+Tesseract fallback pipeline"
```

---

### Task 3: Orchestration, provenance rule, and review-UI confidence badge

**Files:**
- Modify: `api/extract_pdf.php`
- Modify: `includes/extraction_review.php`
- Modify: `admin/review.php`

**Interfaces:**
- Consumes: `GenericExtractor` (Task 1, unchanged shape), `OcrExtractor` (Task 2), both returning `['status' => 'ok'|'failed', 'rows' => [...each row has 'source' => 'text'|'ocr'...]]`.
- Produces: no new interfaces — this task wires existing pieces together and is the last code task before manual verification.

- [ ] **Step 1: Replace `api/extract_pdf.php`**

```php
<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/uploads.php';
require_once __DIR__ . '/../includes/extraction_review.php';
require_once __DIR__ . '/../extractors/ExtractorInterface.php';
require_once __DIR__ . '/../extractors/ParsesHonorRows.php';
require_once __DIR__ . '/../extractors/generic_extractor.php';
require_once __DIR__ . '/../extractors/ocr_extractor.php';

require_role('administrator');

header('Content-Type: application/json');
ini_set('max_execution_time', 120);

if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'YOUR SESSION EXPIRED. PLEASE TRY AGAIN.']);
    exit;
}

$uploadId = (int) ($_POST['upload_id'] ?? 0);
$pdo = get_pdo();

try {
    $upload = $uploadId > 0 ? get_upload_by_id($pdo, $uploadId) : null;

    if ($upload === null) {
        echo json_encode(['status' => 'error', 'message' => 'UPLOAD RECORD NOT FOUND.']);
        exit;
    }

    if ($upload['extraction_status'] === 'reviewed') {
        echo json_encode(['status' => 'error', 'message' => 'THIS UPLOAD HAS ALREADY BEEN REVIEWED AND SAVED.']);
        exit;
    }

    try {
        $result = (new GenericExtractor())->extract($upload['file_path']);
        if ($result['status'] === 'failed') {
            $result = (new OcrExtractor())->extract($upload['file_path']);
        }
    } catch (\Throwable $e) {
        error_log('Extraction failed: ' . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'THE FILE COULD NOT BE PROCESSED.']);
        exit;
    }

    $fallbackYear = derive_fallback_year($upload['school_year']);

    $rows = array_map(function (array $row) use ($fallbackYear) {
        $row['graduation_year'] = $row['graduation_date'] !== null
            ? (int) date('Y', strtotime($row['graduation_date']))
            : $fallbackYear;
        return $row;
    }, $result['rows']);

    start_extraction_review($uploadId, $rows);

    $extractionStatus = $result['status'] === 'ok' ? 'extracted' : 'failed';
    update_upload_extraction_status($pdo, $uploadId, $extractionStatus, 0);

    echo json_encode([
        'status' => 'success',
        'extraction_status' => $extractionStatus,
        'redirect_url' => APP_BASE_URL . '/admin/review.php?upload_id=' . $uploadId,
    ]);
} catch (\Throwable $e) {
    error_log('PDF extraction failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'THE FILE COULD NOT BE PROCESSED. PLEASE TRY AGAIN.']);
    exit;
}
```

The only behavioral changes from the current file: the new `require_once`s for `ParsesHonorRows.php` and `ocr_extractor.php`, `ini_set('max_execution_time', 120);` right after `header()`, and the extraction call now tries `GenericExtractor` first and automatically retries with `OcrExtractor` if the first attempt's status is `'failed'`. Everything else — the upload lookup, the `reviewed`-status guard, the `$fallbackYear` mapping, `start_extraction_review()`, `update_upload_extraction_status()`, the JSON response shape, error handling — is unchanged.

- [ ] **Step 2: Modify `includes/extraction_review.php`'s `set_staged_row()`**

Find this block (currently building `$row` inside `set_staged_row()`):

```php
    $row = [
        'graduate_name' => trim((string) ($data['graduate_name'] ?? '')),
        'latin_honor' => (string) ($data['latin_honor'] ?? ''),
        'graduation_date' => (($data['graduation_date'] ?? '') !== '') ? $data['graduation_date'] : null,
        'graduation_year' => (int) ($data['graduation_year'] ?? 0),
    ];
```

Replace it with:

```php
    $row = [
        'graduate_name' => trim((string) ($data['graduate_name'] ?? '')),
        'latin_honor' => (string) ($data['latin_honor'] ?? ''),
        'graduation_date' => (($data['graduation_date'] ?? '') !== '') ? $data['graduation_date'] : null,
        'graduation_year' => (int) ($data['graduation_year'] ?? 0),
        'source' => 'manual',
    ];
```

This is the provenance-clearing rule: `set_staged_row()` backs both the ADD RECORD and EDIT flows in `admin/review.php` (see `assets/js/review.js`'s `openEditModal()`, used for both `rowIndex === null` and a real index) — any row that passes through here, whether brand new or a correction to an OCR/text row, is now `'manual'` and stops showing the OCR badge.

- [ ] **Step 3: Add the OCR confidence badge in `admin/review.php`**

Find this line in the table row rendering (inside the `foreach ($rows as $index => $row)` loop):

```php
                                <td class="px-4 py-3"><?= e($row['latin_honor']) ?></td>
```

Replace it with:

```php
                                <td class="px-4 py-3">
                                    <?= e($row['latin_honor']) ?>
                                    <?php if (($row['source'] ?? null) === 'ocr'): ?>
                                        <span class="ml-2 inline-block rounded px-1.5 py-0.5 text-[10px] font-medium bg-yellow-500/20 text-yellow-400">OCR — VERIFY</span>
                                    <?php endif; ?>
                                </td>
```

- [ ] **Step 4: Run the full suite**

Run: `"/c/xampp/php/php.exe" vendor/bin/phpunit`
Expected: OK, same count as Task 2 (this task touches an API wrapper, a review-session helper, and a view — none have dedicated PHPUnit coverage beyond what Tasks 1/2 already added, per this project's established convention; full coverage happens in Task 4's manual walkthrough).

- [ ] **Step 5: Commit**

```bash
git add api/extract_pdf.php includes/extraction_review.php admin/review.php
git commit -m "feat: wire OCR fallback into extraction, add provenance rule and review-UI badge"
```

---

### Task 4: End-to-end manual verification

This task has no code changes — it verifies the real, previously-failing upload now extracts successfully via the new OCR fallback, against the live dev database (no throwaway server/account needed, since upload #9 already exists there from the original bug investigation).

**Files:** none (verification only).

- [ ] **Step 1: Confirm the full suite is green before starting**

Run: `"/c/xampp/php/php.exe" vendor/bin/phpunit`
Expected: OK, all tests passing.

- [ ] **Step 2: Confirm upload #9's current state**

```bash
"/c/xampp/mysql/bin/mysql.exe" -h 127.0.0.1 -u root hge_masterlist -e "SELECT id, school_id, original_filename, extraction_status, records_extracted FROM latin_honor_uploads WHERE id = 9;"
```

Expected: `extraction_status = 'failed'`, `records_extracted = 0` (its state from the original bug report — if this upload no longer exists or is in a different state, use whatever real scanned PDF is available instead, re-uploading it via `admin/upload.php` if needed).

- [ ] **Step 3: Log in and re-run extraction via the browser**

Log in as an administrator (the real `admin` account or an existing session), navigate to `admin/view_upload.php?id=9` (or the equivalent page for whatever upload is being used), and trigger extraction again (the EXTRACT action — same flow as any other upload).

Confirm:
- The request succeeds (no timeout, no 500 error) — this exercises the new `ini_set('max_execution_time', 120)` headroom.
- `extraction_status` becomes `extracted` (not `failed`) — confirms the OCR fallback actually ran and found rows, verified via the DB: `SELECT extraction_status, records_extracted FROM latin_honor_uploads WHERE id = 9;` should no longer show `failed`.
- The review page (`admin/review.php?upload_id=9`) shows extracted rows (not the empty "NO RECORDS YET" state), each with the "OCR — VERIFY" badge next to its LATIN HONOR value.

- [ ] **Step 4: Confirm the provenance rule**

Click EDIT on one OCR-flagged row, change any field (e.g. correct a name if OCR introduced any error), click SAVE. Confirm:
- The row's OCR badge is gone after the page reloads (its `source` is now `'manual'`).
- Every other, untouched row still shows its OCR badge.

- [ ] **Step 5: Confirm SAVE RECORDS still works end-to-end**

Ensure every row shows `VALID` status (fix any `INCOMPLETE` ones via EDIT — a real scanned document's OCR output may need minor corrections, which is exactly what this whole review step exists for). Click SAVE RECORDS. Confirm:
- Success toast appears, redirect to `admin/view_upload.php?id=9`.
- `latin_honor_graduates` now has rows for this upload: `SELECT COUNT(*) FROM latin_honor_graduates WHERE upload_id = 9;`.
- `latin_honor_uploads.extraction_status` for id 9 is now `reviewed`.

- [ ] **Step 6: Update the SDD ledger**

Record a Task 4 entry summarizing what was verified, matching the format used for every prior phase/addendum's final manual-verification task in this project.
