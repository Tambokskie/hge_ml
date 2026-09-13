# HGE Database — Phase 3: Extraction + Review Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an administrator click EXTRACT RECORDS on an uploaded PDF, see the parsed graduate rows (or a clear "could not be extracted" message for scanned/malformed PDFs), edit/remove/add rows in a review screen, and only commit them to the database on explicit SAVE RECORDS.

**Architecture:** A class-based `ExtractorInterface`/`GenericExtractor` pair (matching the spec's explicit OOP framing for pluggable extractors) wraps `smalot/pdfparser`, using a line-by-line honor-keyword-match heuristic validated during brainstorming against two real PDFs. Extracted-but-unconfirmed rows live in `$_SESSION['extraction_review']` (one active review at a time) until SAVE RECORDS commits them transactionally. Thin API endpoints + a testable core mirror Phase 2's `handle_upload_request()` pattern.

**Tech Stack:** PHP 8.2.12, MariaDB 10.4.32 (PDO), PHPUnit ^10.5, `smalot/pdfparser` ^2.12, vanilla JS reusing Phase 2's `showToast()`/`showModal()`.

**Spec:** [docs/superpowers/specs/2026-09-12-hge-database-design.md](../specs/2026-09-12-hge-database-design.md) (section 12 is the Phase 3 addendum this plan implements, including the extraction heuristic validated against real data during brainstorming)

## Global Constraints

- Project root: `C:\xampp\htdocs\hge_masterlist`, served at `http://localhost/hge_masterlist/`.
- All SQL via PDO prepared statements.
- All dynamic output escaped at render time — server-side via `e()`/`e_upper()`, **and client-side**: any JS that builds HTML from row data (graduate names come from uploaded PDF content, i.e. untrusted) must escape it before insertion via `innerHTML`, per the exact lesson from Phase 2's stored-XSS fix.
- UPPERCASE display for major on-screen labels/values.
- Role/auth checks server-side: `require_role('administrator')` on every extraction/review page and endpoint (matches spec section 20 — only administrators update/remove/save records).
- CSRF token required and verified on every state-changing POST.
- Never expose raw PHP/MySQL errors to the browser; wrap DB-touching orchestration in try/catch per the pattern already established in Phase 2's `api/upload_pdf.php`/`api/serve_pdf.php`/`admin/view_upload.php`.
- `latin_honor` must be exactly one of `CUM LAUDE`, `MAGNA CUM LAUDE`, `SUMMA CUM LAUDE` (schema enum) — the extractor normalizes case/spacing variants to these exact canonical values before a row is ever created.
- Zero matched rows (from either a genuinely scanned/image PDF or a parse exception on a malformed PDF) → `extraction_status = 'failed'`, surfacing "TEXT COULD NOT BE RELIABLY EXTRACTED FROM THIS PDF" with a manual-entry path — never silently creates bad records.
- Extraction staging lives in `$_SESSION['extraction_review']`, not a new database table (brainstorming decision).
- `api/update_graduate.php`/`api/delete_graduate.php` operate on the session staging only in this phase, not already-saved `latin_honor_graduates` rows.

---

## Task 1: Add smalot/pdfparser dependency

**Files:**
- Modify: `composer.json`

**Interfaces:**
- Produces: `\Smalot\PdfParser\Parser` class available via Composer's autoloader (`vendor/autoload.php`, already loaded by `tests/bootstrap.php`; application code loads it explicitly in Task 2). Every later task that touches PDF parsing depends on this.

- [ ] **Step 1: Modify composer.json's require section**

Current:

```json
    "require": {
        "php": ">=8.1",
        "ext-pdo": "*",
        "ext-mbstring": "*"
    },
```

New:

```json
    "require": {
        "php": ">=8.1",
        "ext-pdo": "*",
        "ext-mbstring": "*",
        "ext-zlib": "*",
        "ext-iconv": "*",
        "smalot/pdfparser": "^2.12"
    },
```

- [ ] **Step 2: Install the dependency**

Run: `composer update smalot/pdfparser` (or `composer install` if `composer.json` was hand-edited and no `composer.lock` entry exists yet — either way, confirm `vendor/smalot/pdfparser/` appears afterward).

If `repo.packagist.org` times out (a transient connectivity issue was observed once during brainstorming, unrelated to this project — `getcomposer.org` and `github.com` were unaffected), simply retry; it resolved within minutes on its own.

- [ ] **Step 3: Smoke-test the autoloader**

Run: `php -r "require 'vendor/autoload.php'; var_dump(class_exists('Smalot\\PdfParser\\Parser'));"`
Expected: `bool(true)`

- [ ] **Step 4: Run the full PHPUnit suite to confirm no regressions**

Run: `php vendor/bin/phpunit`
Expected: all ~68 existing tests still pass (this task adds a dependency, no app code changes).

- [ ] **Step 5: Commit**

```bash
git add composer.json composer.lock
git commit -m "chore: add smalot/pdfparser dependency"
```

---

## Task 2: ExtractorInterface + GenericExtractor

**Files:**
- Create: `extractors/ExtractorInterface.php`
- Create: `extractors/generic_extractor.php`
- Create: `tests/fixtures/extraction-sample.pdf`
- Test: `tests/extractors/GenericExtractorTest.php`

**Interfaces:**
- Produces: `interface ExtractorInterface { public function extract(string $filePath): array; }` and `class GenericExtractor implements ExtractorInterface`, whose `extract()` returns `['status' => 'ok'|'failed', 'rows' => array]` where each row is `['graduate_name' => string, 'latin_honor' => string, 'graduation_date' => ?string]` (`graduation_date` in `Y-m-d` format or `null`). Task 7's `api/extract_pdf.php` instantiates and calls `GenericExtractor::extract()`.

**Design notes (validated during brainstorming against two real PDFs — do not deviate from this algorithm without re-testing against the fixtures below):**
- `smalot/pdfparser`'s `Parser::parseFile()->getText()` preserves one output line per drawn text line — confirmed by direct testing, which is what makes per-line regex matching viable.
- A line with no Latin Honor phrase (checked case/whitespace-insensitively, `SUMMA CUM LAUDE` and `MAGNA CUM LAUDE` checked *before* plain `CUM LAUDE` since it's a substring of the other two) is prose/header/signature and is skipped — no separate table-header detection is needed.
- For a matching line, split on runs of 2+ whitespace characters; the first column is the graduate name. PDF-extracted tabular text typically has single spaces within a name but wider gaps between columns.
- `Parser::parseFile()` **can throw an uncaught exception** on a malformed/non-conformant PDF (confirmed directly: a minimal PDF missing a valid xref table throws `Unable to find startxref`) — this must be caught and treated identically to zero-rows-matched (`status: 'failed'`), not allowed to propagate as a fatal error.

- [ ] **Step 1: Write ExtractorInterface**

```php
<?php

interface ExtractorInterface
{
    /**
     * @return array{status: 'ok'|'failed', rows: array<array{graduate_name: string, latin_honor: string, graduation_date: ?string}>}
     */
    public function extract(string $filePath): array;
}
```

Save as `extractors/ExtractorInterface.php`.

- [ ] **Step 2: Create the fixture: tests/fixtures/extraction-sample.pdf**

This is a real, valid, TEXT-based PDF (not scanned) modeled on an actual Philippine university Latin Honor list, built and verified during brainstorming to produce exactly 9 correctly-parsed rows with the `GenericExtractor` algorithm above. Write it with **exactly** this content (it is plain ASCII, no binary bytes):

```
%PDF-1.4
1 0 obj
<< /Type /Catalog /Pages 2 0 R >>
endobj
2 0 obj
<< /Type /Pages /Kids [3 0 R] /Count 1 >>
endobj
3 0 obj
<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R /F2 6 0 R >> >> /Contents 4 0 R >>
endobj
4 0 obj
<< /Length 2266 >>
stream
BT
/F2 14 Tf
1 0 0 1 50 780 Tm
(UNIVERSITY OF THE PHILIPPINES TACLOBAN) Tj
/F1 11 Tf
1 0 0 1 50 761 Tm
(OFFICE OF THE UNIVERSITY REGISTRAR) Tj
/F1 11 Tf
1 0 0 1 50 745 Tm
() Tj
/F1 11 Tf
1 0 0 1 50 729 Tm
(30 July 2026) Tj
/F1 11 Tf
1 0 0 1 50 713 Tm
() Tj
/F1 11 Tf
1 0 0 1 50 697 Tm
(ATTY. MARILYN E. TALDO) Tj
/F1 11 Tf
1 0 0 1 50 681 Tm
(Director IV) Tj
/F1 11 Tf
1 0 0 1 50 665 Tm
(Civil Service Commission) Tj
/F1 11 Tf
1 0 0 1 50 649 Tm
() Tj
/F1 11 Tf
1 0 0 1 50 633 Tm
(Dear Director Taldo,) Tj
/F1 11 Tf
1 0 0 1 50 617 Tm
() Tj
/F1 10 Tf
1 0 0 1 50 601 Tm
(In accordance with the Revised Memorandum of Agreement, we furnish) Tj
/F1 10 Tf
1 0 0 1 50 586 Tm
(your office with the list of our honor graduates for reference.) Tj
/F1 11 Tf
1 0 0 1 50 571 Tm
() Tj
/F2 9 Tf
1 0 0 1 50 555 Tm
(Name of Honor Graduates          GWA     Degree               Honors Received      Date of Graduation) Tj
/F1 9 Tf
1 0 0 1 50 541 Tm
(Abrio, Jeny Lumpay                1.5399  BA Psychology        cum laude            02 July 2026) Tj
/F1 9 Tf
1 0 0 1 50 527 Tm
(Agudera, Glazel Mae Gabatbat      1.4706  BS Management        CUM LAUDE            02 July 2026) Tj
/F1 9 Tf
1 0 0 1 50 513 Tm
(Amarillo, Gian Lester Baculo      1.1516  BS Biology           summa cum laude      02 July 2026) Tj
/F1 9 Tf
1 0 0 1 50 499 Tm
(Amascual, Ma. Audrey Marcellana   1.2827  BS Management        Magna Cum Laude      02 July 2026) Tj
/F1 9 Tf
1 0 0 1 50 485 Tm
(Balmonte, Joana Mae Lobo          1.3500  BS Management        magna   cum   laude  02 July 2026) Tj
/F1 9 Tf
1 0 0 1 50 471 Tm
(Bersoza, Jade Lynn Hernandez      1.5978  BA Political Science cum laude            02 July 2026) Tj
/F1 9 Tf
1 0 0 1 50 457 Tm
(Borja, Mark Louise Nofiez         1.5000  BA Political Science Cum Laude            2 Jul 2026) Tj
/F1 9 Tf
1 0 0 1 50 443 Tm
(Cabagio, Jonah Mae Laurente       1.7413  BS Management        cum laude            ) Tj
/F1 9 Tf
1 0 0 1 50 429 Tm
(O'Brien-Smith, Jose Miguel        1.6346  BS Biology           cum laude            02 July 2026) Tj
/F1 11 Tf
1 0 0 1 50 415 Tm
() Tj
/F1 11 Tf
1 0 0 1 50 399 Tm
(Truly yours,) Tj
/F1 11 Tf
1 0 0 1 50 383 Tm
() Tj
/F2 11 Tf
1 0 0 1 50 367 Tm
(IRMA R. TAN) Tj
/F1 11 Tf
1 0 0 1 50 351 Tm
(University Registrar) Tj
ET
endstream
endobj
5 0 obj
<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>
endobj
6 0 obj
<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>
endobj
xref
0 7
0000000000 65535 f 
0000000009 00000 n 
0000000058 00000 n 
0000000115 00000 n 
0000000251 00000 n 
0000002568 00000 n 
0000002638 00000 n 
trailer
<< /Size 7 /Root 1 0 R >>
startxref
2713
%%EOF
```

- [ ] **Step 3: Write the failing test**

```php
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
}
```

- [ ] **Step 4: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/extractors/GenericExtractorTest.php`
Expected: FAIL — `GenericExtractor` class not found.

- [ ] **Step 5: Write extractors/generic_extractor.php**

```php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

class GenericExtractor implements ExtractorInterface
{
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

        $rows = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            $row = $this->parseLine($line);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return [
            'status' => empty($rows) ? 'failed' : 'ok',
            'rows' => $rows,
        ];
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
        if (preg_match('/SUMMA\s+CUM\s+LAUDE/i', $text)) {
            return 'SUMMA CUM LAUDE';
        }
        if (preg_match('/MAGNA\s+CUM\s+LAUDE/i', $text)) {
            return 'MAGNA CUM LAUDE';
        }
        if (preg_match('/CUM\s+LAUDE/i', $text)) {
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

- [ ] **Step 6: Run test to verify it passes**

Run: `php vendor/bin/phpunit tests/extractors/GenericExtractorTest.php`
Expected: `OK (7 tests, ...)`

- [ ] **Step 7: Run the full suite**

Run: `php vendor/bin/phpunit`
Expected: all tests pass.

- [ ] **Step 8: Commit**

```bash
git add extractors/ExtractorInterface.php extractors/generic_extractor.php tests/fixtures/extraction-sample.pdf tests/extractors/GenericExtractorTest.php
git commit -m "feat: add PDF extractor interface and generic line-based extractor"
```

---

## Task 3: Extraction review staging core

**Files:**
- Create: `includes/extraction_review.php`
- Test: `tests/includes/ExtractionReviewCoreTest.php`

**Interfaces:**
- Consumes: `ensure_session()` (Phase 1).
- Produces: `derive_fallback_year(string $schoolYear): ?int`, `validate_staged_row(array $row): array{ok: bool, errors: array}`, `staged_row_status(array $row): string` ('VALID'|'INCOMPLETE'), `start_extraction_review(int $uploadId, array $rows): void`, `get_extraction_review(int $uploadId): ?array`, `clear_extraction_review(): void`. Task 4's mutation functions and Task 7/9/10/11's endpoints/pages all depend on these.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/extraction_review.php';

class ExtractionReviewCoreTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testDeriveFallbackYearUsesLastFourDigitYearInRange(): void
    {
        $this->assertSame(2026, derive_fallback_year('2025-2026'));
    }

    public function testDeriveFallbackYearHandlesSingleYear(): void
    {
        $this->assertSame(2025, derive_fallback_year('2025'));
    }

    public function testDeriveFallbackYearReturnsNullWhenNoYearFound(): void
    {
        $this->assertNull(derive_fallback_year('N/A'));
    }

    public function testValidateStagedRowAcceptsCompleteValidRow(): void
    {
        $result = validate_staged_row([
            'graduate_name' => 'Juan Dela Cruz',
            'latin_honor' => 'CUM LAUDE',
            'graduation_date' => '2026-07-02',
            'graduation_year' => 2026,
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['errors']);
    }

    public function testValidateStagedRowAcceptsNullDate(): void
    {
        $result = validate_staged_row([
            'graduate_name' => 'Juan Dela Cruz',
            'latin_honor' => 'CUM LAUDE',
            'graduation_date' => null,
            'graduation_year' => 2026,
        ]);

        $this->assertTrue($result['ok']);
    }

    public function testValidateStagedRowRejectsEmptyName(): void
    {
        $result = validate_staged_row([
            'graduate_name' => '',
            'latin_honor' => 'CUM LAUDE',
            'graduation_date' => null,
            'graduation_year' => 2026,
        ]);

        $this->assertFalse($result['ok']);
        $this->assertContains('GRADUATE NAME IS REQUIRED.', $result['errors']);
    }

    public function testValidateStagedRowRejectsUnrecognizedLatinHonor(): void
    {
        $result = validate_staged_row([
            'graduate_name' => 'Juan Dela Cruz',
            'latin_honor' => 'WITH HONORS',
            'graduation_date' => null,
            'graduation_year' => 2026,
        ]);

        $this->assertFalse($result['ok']);
    }

    public function testValidateStagedRowRejectsInvalidYear(): void
    {
        $result = validate_staged_row([
            'graduate_name' => 'Juan Dela Cruz',
            'latin_honor' => 'CUM LAUDE',
            'graduation_date' => null,
            'graduation_year' => 0,
        ]);

        $this->assertFalse($result['ok']);
    }

    public function testValidateStagedRowRejectsMalformedDate(): void
    {
        $result = validate_staged_row([
            'graduate_name' => 'Juan Dela Cruz',
            'latin_honor' => 'CUM LAUDE',
            'graduation_date' => '2026-13-40',
            'graduation_year' => 2026,
        ]);

        $this->assertFalse($result['ok']);
    }

    public function testStagedRowStatusReflectsValidation(): void
    {
        $valid = ['graduate_name' => 'Juan', 'latin_honor' => 'CUM LAUDE', 'graduation_date' => null, 'graduation_year' => 2026];
        $incomplete = ['graduate_name' => '', 'latin_honor' => '', 'graduation_date' => null, 'graduation_year' => 0];

        $this->assertSame('VALID', staged_row_status($valid));
        $this->assertSame('INCOMPLETE', staged_row_status($incomplete));
    }

    public function testStartGetAndClearExtractionReviewRoundTrip(): void
    {
        $rows = [['graduate_name' => 'Juan', 'latin_honor' => 'CUM LAUDE', 'graduation_date' => null, 'graduation_year' => 2026]];

        start_extraction_review(5, $rows);
        $review = get_extraction_review(5);

        $this->assertNotNull($review);
        $this->assertSame($rows, $review['rows']);

        clear_extraction_review();
        $this->assertNull(get_extraction_review(5));
    }

    public function testGetExtractionReviewReturnsNullForMismatchedUploadId(): void
    {
        start_extraction_review(5, []);

        $this->assertNull(get_extraction_review(999));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/includes/ExtractionReviewCoreTest.php`
Expected: FAIL — `derive_fallback_year()` undefined.

- [ ] **Step 3: Write includes/extraction_review.php**

```php
<?php

function derive_fallback_year(string $schoolYear): ?int
{
    if (preg_match_all('/\d{4}/', $schoolYear, $matches) && !empty($matches[0])) {
        return (int) end($matches[0]);
    }

    return null;
}

function validate_staged_row(array $row): array
{
    $errors = [];

    $name = trim((string) ($row['graduate_name'] ?? ''));
    if ($name === '') {
        $errors[] = 'GRADUATE NAME IS REQUIRED.';
    } elseif (mb_strlen($name) > 255) {
        $errors[] = 'GRADUATE NAME IS TOO LONG.';
    }

    $honor = (string) ($row['latin_honor'] ?? '');
    if (!in_array($honor, ['CUM LAUDE', 'MAGNA CUM LAUDE', 'SUMMA CUM LAUDE'], true)) {
        $errors[] = 'LATIN HONOR MUST BE ONE OF THE THREE RECOGNIZED VALUES.';
    }

    $year = $row['graduation_year'] ?? null;
    if (!is_numeric($year) || (int) $year < 1900 || (int) $year > 2100) {
        $errors[] = 'YEAR GRADUATED MUST BE A VALID 4-DIGIT YEAR.';
    }

    $date = $row['graduation_date'] ?? null;
    if ($date !== null && $date !== '') {
        $dt = DateTime::createFromFormat('Y-m-d', $date);
        if (!$dt || $dt->format('Y-m-d') !== $date) {
            $errors[] = 'DATE OF GRADUATION MUST BE A VALID DATE.';
        }
    }

    return ['ok' => empty($errors), 'errors' => $errors];
}

function staged_row_status(array $row): string
{
    return validate_staged_row($row)['ok'] ? 'VALID' : 'INCOMPLETE';
}

function start_extraction_review(int $uploadId, array $rows): void
{
    ensure_session();
    $_SESSION['extraction_review'] = ['upload_id' => $uploadId, 'rows' => $rows];
}

function get_extraction_review(int $uploadId): ?array
{
    ensure_session();
    $review = $_SESSION['extraction_review'] ?? null;
    if ($review === null || (int) $review['upload_id'] !== $uploadId) {
        return null;
    }

    return $review;
}

function clear_extraction_review(): void
{
    ensure_session();
    unset($_SESSION['extraction_review']);
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php vendor/bin/phpunit tests/includes/ExtractionReviewCoreTest.php`
Expected: `OK (13 tests, ...)`

- [ ] **Step 5: Commit**

```bash
git add includes/extraction_review.php tests/includes/ExtractionReviewCoreTest.php
git commit -m "feat: add extraction review staging core and row validation"
```

---

## Task 4: Staged row mutation (set/remove)

**Files:**
- Modify: `includes/extraction_review.php`
- Test: `tests/includes/ExtractionReviewMutationTest.php`

**Interfaces:**
- Consumes: `start_extraction_review()`, `get_extraction_review()` (Task 3).
- Produces: `set_staged_row(int $uploadId, ?int $rowIndex, array $data): array{ok: bool, error?: string}` (null/omitted `$rowIndex` appends a new row; a valid existing index updates that row), `remove_staged_row(int $uploadId, int $rowIndex): array{ok: bool, error?: string}`. Task 9/10's `api/update_graduate.php`/`api/delete_graduate.php` call these directly.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/extraction_review.php';

class ExtractionReviewMutationTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
        start_extraction_review(1, [
            ['graduate_name' => 'Juan Dela Cruz', 'latin_honor' => 'CUM LAUDE', 'graduation_date' => null, 'graduation_year' => 2026],
            ['graduate_name' => 'Maria Santos', 'latin_honor' => 'MAGNA CUM LAUDE', 'graduation_date' => null, 'graduation_year' => 2026],
        ]);
    }

    public function testSetStagedRowUpdatesExistingRowByIndex(): void
    {
        $result = set_staged_row(1, 0, [
            'graduate_name' => 'Juan D. Cruz',
            'latin_honor' => 'SUMMA CUM LAUDE',
            'graduation_date' => '2026-07-02',
            'graduation_year' => 2026,
        ]);

        $this->assertTrue($result['ok']);
        $rows = get_extraction_review(1)['rows'];
        $this->assertCount(2, $rows);
        $this->assertSame('Juan D. Cruz', $rows[0]['graduate_name']);
        $this->assertSame('SUMMA CUM LAUDE', $rows[0]['latin_honor']);
    }

    public function testSetStagedRowAppendsNewRowWhenIndexIsNull(): void
    {
        $result = set_staged_row(1, null, [
            'graduate_name' => 'Pedro Reyes',
            'latin_honor' => 'CUM LAUDE',
            'graduation_date' => null,
            'graduation_year' => 2026,
        ]);

        $this->assertTrue($result['ok']);
        $rows = get_extraction_review(1)['rows'];
        $this->assertCount(3, $rows);
        $this->assertSame('Pedro Reyes', $rows[2]['graduate_name']);
    }

    public function testSetStagedRowFailsWithoutActiveReview(): void
    {
        clear_extraction_review();

        $result = set_staged_row(1, null, ['graduate_name' => 'X', 'latin_honor' => 'CUM LAUDE', 'graduation_date' => null, 'graduation_year' => 2026]);

        $this->assertFalse($result['ok']);
    }

    public function testRemoveStagedRowDeletesByIndex(): void
    {
        $result = remove_staged_row(1, 0);

        $this->assertTrue($result['ok']);
        $rows = get_extraction_review(1)['rows'];
        $this->assertCount(1, $rows);
        $this->assertSame('Maria Santos', $rows[0]['graduate_name']);
    }

    public function testRemoveStagedRowReindexesRemainingRows(): void
    {
        remove_staged_row(1, 0);
        $rows = get_extraction_review(1)['rows'];

        // The remaining row must be at index 0, not 1, after removal.
        $this->assertArrayHasKey(0, $rows);
        $this->assertArrayNotHasKey(1, $rows);
    }

    public function testRemoveStagedRowFailsForUnknownIndex(): void
    {
        $result = remove_staged_row(1, 99);

        $this->assertFalse($result['ok']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/includes/ExtractionReviewMutationTest.php`
Expected: FAIL — `set_staged_row()` undefined.

- [ ] **Step 3: Append to includes/extraction_review.php**

```php

function set_staged_row(int $uploadId, ?int $rowIndex, array $data): array
{
    $review = get_extraction_review($uploadId);
    if ($review === null) {
        return ['ok' => false, 'error' => 'NO ACTIVE REVIEW SESSION FOR THIS UPLOAD.'];
    }

    $row = [
        'graduate_name' => trim((string) ($data['graduate_name'] ?? '')),
        'latin_honor' => (string) ($data['latin_honor'] ?? ''),
        'graduation_date' => (($data['graduation_date'] ?? '') !== '') ? $data['graduation_date'] : null,
        'graduation_year' => (int) ($data['graduation_year'] ?? 0),
    ];

    $rows = $review['rows'];
    if ($rowIndex !== null && array_key_exists($rowIndex, $rows)) {
        $rows[$rowIndex] = $row;
    } else {
        $rows[] = $row;
    }

    $_SESSION['extraction_review']['rows'] = $rows;

    return ['ok' => true];
}

function remove_staged_row(int $uploadId, int $rowIndex): array
{
    $review = get_extraction_review($uploadId);
    if ($review === null) {
        return ['ok' => false, 'error' => 'NO ACTIVE REVIEW SESSION FOR THIS UPLOAD.'];
    }

    $rows = $review['rows'];
    if (!array_key_exists($rowIndex, $rows)) {
        return ['ok' => false, 'error' => 'RECORD NOT FOUND.'];
    }

    unset($rows[$rowIndex]);
    $_SESSION['extraction_review']['rows'] = array_values($rows);

    return ['ok' => true];
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php vendor/bin/phpunit tests/includes/ExtractionReviewMutationTest.php`
Expected: `OK (6 tests, ...)`

- [ ] **Step 5: Commit**

```bash
git add includes/extraction_review.php tests/includes/ExtractionReviewMutationTest.php
git commit -m "feat: add staged row update/append/remove operations"
```

---

## Task 5: update_upload_extraction_status()

**Files:**
- Modify: `includes/uploads.php`
- Test: `tests/includes/UpdateExtractionStatusTest.php`

**Interfaces:**
- Consumes: `Tests\TestDb` (Phase 1).
- Produces: `update_upload_extraction_status(PDO $pdo, int $uploadId, string $status, int $recordsExtracted): void`. Task 7's `api/extract_pdf.php` and Task 6's `save_staged_records()` both call this.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;
use Tests\TestDb;

require_once __DIR__ . '/../../includes/uploads.php';

class UpdateExtractionStatusTest extends TestCase
{
    protected function setUp(): void
    {
        TestDb::resetSchema();
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO schools (school_name) VALUES ('ABC University')");
        $pdo->exec("INSERT INTO users (name, username, email, password, role) VALUES ('Admin', 'admin', 'a@a.com', 'x', 'administrator')");
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number)
             VALUES (1, '2025', 'a.pdf', 'ABC_UNIVERSITY_2025_1.pdf', '/x/a.pdf', 10, SHA2('a',256), 1, 1)"
        );
    }

    public function testUpdatesStatusAndRecordCount(): void
    {
        $pdo = TestDb::pdo();

        update_upload_extraction_status($pdo, 1, 'extracted', 12);

        $row = $pdo->query('SELECT extraction_status, records_extracted FROM latin_honor_uploads WHERE id = 1')->fetch();
        $this->assertSame('extracted', $row['extraction_status']);
        $this->assertSame(12, (int) $row['records_extracted']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/includes/UpdateExtractionStatusTest.php`
Expected: FAIL — `update_upload_extraction_status()` undefined.

- [ ] **Step 3: Append to includes/uploads.php**

```php

function update_upload_extraction_status(PDO $pdo, int $uploadId, string $status, int $recordsExtracted): void
{
    $stmt = $pdo->prepare(
        'UPDATE latin_honor_uploads SET extraction_status = :status, records_extracted = :records WHERE id = :id'
    );
    $stmt->execute([
        'status' => $status,
        'records' => $recordsExtracted,
        'id' => $uploadId,
    ]);
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php vendor/bin/phpunit tests/includes/UpdateExtractionStatusTest.php`
Expected: `OK (1 test, ...)`

- [ ] **Step 5: Commit**

```bash
git add includes/uploads.php tests/includes/UpdateExtractionStatusTest.php
git commit -m "feat: add extraction-status update query"
```

---

## Task 6: Transactional save (save_staged_records)

**Files:**
- Modify: `includes/extraction_review.php`
- Test: `tests/includes/SaveStagedRecordsTest.php`

**Interfaces:**
- Consumes: `Tests\TestDb`, `staged_row_status()` (Task 3), `update_upload_extraction_status()` (Task 5).
- Produces: `save_staged_records(PDO $pdo, int $uploadId, int $schoolId, array $rows): array{status: 'success'|'error', message: string}`. Task 11's `api/save_records.php` is a thin wrapper around this.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;
use Tests\TestDb;

require_once __DIR__ . '/../../includes/extraction_review.php';
require_once __DIR__ . '/../../includes/uploads.php';

class SaveStagedRecordsTest extends TestCase
{
    protected function setUp(): void
    {
        TestDb::resetSchema();
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO schools (school_name) VALUES ('ABC University')");
        $pdo->exec("INSERT INTO users (name, username, email, password, role) VALUES ('Admin', 'admin', 'a@a.com', 'x', 'administrator')");
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number)
             VALUES (1, '2025', 'a.pdf', 'ABC_UNIVERSITY_2025_1.pdf', '/x/a.pdf', 10, SHA2('a',256), 1, 1)"
        );
    }

    private function validRows(): array
    {
        return [
            ['graduate_name' => 'Juan Dela Cruz', 'latin_honor' => 'CUM LAUDE', 'graduation_date' => '2025-06-15', 'graduation_year' => 2025],
            ['graduate_name' => 'Maria Santos', 'latin_honor' => 'MAGNA CUM LAUDE', 'graduation_date' => null, 'graduation_year' => 2025],
        ];
    }

    public function testSavesAllRowsAndMarksUploadReviewed(): void
    {
        $pdo = TestDb::pdo();

        $result = save_staged_records($pdo, 1, 1, $this->validRows());

        $this->assertSame('success', $result['status']);

        $count = (int) $pdo->query('SELECT COUNT(*) FROM latin_honor_graduates WHERE upload_id = 1')->fetchColumn();
        $this->assertSame(2, $count);

        $upload = $pdo->query('SELECT extraction_status, records_extracted FROM latin_honor_uploads WHERE id = 1')->fetch();
        $this->assertSame('reviewed', $upload['extraction_status']);
        $this->assertSame(2, (int) $upload['records_extracted']);
    }

    public function testRejectsEmptyRowsWithoutTouchingTheDatabase(): void
    {
        $pdo = TestDb::pdo();

        $result = save_staged_records($pdo, 1, 1, []);

        $this->assertSame('error', $result['status']);
        $count = (int) $pdo->query('SELECT COUNT(*) FROM latin_honor_graduates')->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function testBlocksOnIncompleteRowNamingItsPosition(): void
    {
        $pdo = TestDb::pdo();
        $rows = $this->validRows();
        $rows[] = ['graduate_name' => '', 'latin_honor' => '', 'graduation_date' => null, 'graduation_year' => 0];

        $result = save_staged_records($pdo, 1, 1, $rows);

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('3', $result['message']);

        $count = (int) $pdo->query('SELECT COUNT(*) FROM latin_honor_graduates')->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function testRollsBackAllRowsOnDatabaseFailureAndLeavesUploadUnchanged(): void
    {
        $pdo = TestDb::pdo();

        // A nonexistent upload_id violates the FOREIGN KEY constraint on every
        // row's insert — proving that if the batch fails at the DB layer,
        // nothing is left partially inserted.
        $result = save_staged_records($pdo, 999999, 1, $this->validRows());

        $this->assertSame('error', $result['status']);

        $count = (int) $pdo->query('SELECT COUNT(*) FROM latin_honor_graduates')->fetchColumn();
        $this->assertSame(0, $count);

        $upload = $pdo->query('SELECT extraction_status FROM latin_honor_uploads WHERE id = 1')->fetch();
        $this->assertSame('pending', $upload['extraction_status']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/includes/SaveStagedRecordsTest.php`
Expected: FAIL — `save_staged_records()` undefined.

- [ ] **Step 3: Append to includes/extraction_review.php**

```php

function save_staged_records(PDO $pdo, int $uploadId, int $schoolId, array $rows): array
{
    if (empty($rows)) {
        return ['status' => 'error', 'message' => 'THERE ARE NO RECORDS TO SAVE.'];
    }

    $incomplete = [];
    foreach ($rows as $index => $row) {
        if (staged_row_status($row) !== 'VALID') {
            $incomplete[] = $index + 1;
        }
    }

    if (!empty($incomplete)) {
        return [
            'status' => 'error',
            'message' => 'RECORD(S) ' . implode(', ', $incomplete) . ' ARE INCOMPLETE. PLEASE FIX THEM BEFORE SAVING.',
        ];
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'INSERT INTO latin_honor_graduates (upload_id, school_id, graduate_name, latin_honor, graduation_date, graduation_year)
             VALUES (:upload_id, :school_id, :graduate_name, :latin_honor, :graduation_date, :graduation_year)'
        );

        foreach ($rows as $row) {
            $stmt->execute([
                'upload_id' => $uploadId,
                'school_id' => $schoolId,
                'graduate_name' => $row['graduate_name'],
                'latin_honor' => $row['latin_honor'],
                'graduation_date' => $row['graduation_date'],
                'graduation_year' => $row['graduation_year'],
            ]);
        }

        update_upload_extraction_status($pdo, $uploadId, 'reviewed', count($rows));

        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        error_log('save_staged_records failed: ' . $e->getMessage());
        return ['status' => 'error', 'message' => 'THE RECORDS COULD NOT BE SAVED. PLEASE TRY AGAIN.'];
    }

    return ['status' => 'success', 'message' => count($rows) . ' RECORD(S) SAVED SUCCESSFULLY.'];
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php vendor/bin/phpunit tests/includes/SaveStagedRecordsTest.php`
Expected: `OK (4 tests, ...)`

- [ ] **Step 5: Run the full suite**

Run: `php vendor/bin/phpunit`
Expected: all tests pass.

- [ ] **Step 6: Commit**

```bash
git add includes/extraction_review.php tests/includes/SaveStagedRecordsTest.php
git commit -m "feat: add transactional save of staged extraction records"
```

---

## Task 7: api/extract_pdf.php + manual verification

**Files:**
- Create: `api/extract_pdf.php`

**Interfaces:**
- Consumes: `require_role()`, `csrf_verify()` (Phase 1), `get_upload_by_id()`, `update_upload_extraction_status()` (Task 5), `derive_fallback_year()`, `start_extraction_review()` (Task 3), `GenericExtractor` (Task 2).

- [ ] **Step 1: Write api/extract_pdf.php**

```php
<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/uploads.php';
require_once __DIR__ . '/../includes/extraction_review.php';
require_once __DIR__ . '/../extractors/ExtractorInterface.php';
require_once __DIR__ . '/../extractors/generic_extractor.php';

require_role('administrator');

header('Content-Type: application/json');

if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'YOUR SESSION EXPIRED. PLEASE TRY AGAIN.']);
    exit;
}

$uploadId = (int) ($_POST['upload_id'] ?? 0);
$pdo = get_pdo();
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
    $extractor = new GenericExtractor();
    $result = $extractor->extract($upload['file_path']);
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
```

- [ ] **Step 2: Manual verification — reject already-reviewed uploads**

With a real session (reuse the curl login-scripting approach from Phase 2's Task 9/13/14, or verify in the browser once Task 12/13 wire up the UI), and an upload whose `extraction_status` is `reviewed`, POST to this endpoint and confirm the JSON response is `{"status":"error","message":"THIS UPLOAD HAS ALREADY BEEN REVIEWED AND SAVED."}`.

- [ ] **Step 3: Manual verification — real extraction against both test PDFs**

Using an authenticated session:
1. Upload `tests/fixtures/extraction-sample.pdf` (Task 2's synthetic PDF) via the existing upload flow, then POST to `api/extract_pdf.php` with that upload's id. Confirm the JSON response has `"extraction_status":"extracted"`, and directly query the dev database's session-adjacent state is not checkable via SQL (it's in-session) — instead confirm via `admin/review.php` once Task 8 exists, or note this verification is completed in Task 13's end-to-end pass if it's more natural there.
2. If you have it available, also try this against a genuinely scanned/image PDF (or any PDF with no extractable Latin Honor text) and confirm `"extraction_status":"failed"`.

Document what you observed in your report; a full walk-through with the actual review page is Task 13's job.

- [ ] **Step 4: Commit**

```bash
git add api/extract_pdf.php
git commit -m "feat: add extract_pdf.php API endpoint"
```

---

## Task 8: admin/review.php

**Files:**
- Create: `admin/review.php`

**Interfaces:**
- Consumes: `require_role()`, `csrf_token()`, `csrf_verify()` (Phase 1), `get_upload_by_id()` (Phase 2), `get_extraction_review()`, `staged_row_status()`, `clear_extraction_review()` (Task 3), `format_date_display()`, `flash_set()`, `redirect()` (Phase 1).

- [ ] **Step 1: Write admin/review.php**

```php
<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/uploads.php';
require_once __DIR__ . '/../includes/extraction_review.php';

require_role('administrator');

$uploadId = (int) ($_GET['upload_id'] ?? 0);
$pdo = get_pdo();
$upload = $uploadId > 0 ? get_upload_by_id($pdo, $uploadId) : null;

if ($upload === null) {
    flash_set('error', 'UPLOAD RECORD NOT FOUND.');
    redirect(APP_BASE_URL . '/admin/upload.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['cancel'])) {
    if (csrf_verify($_POST['csrf_token'] ?? null)) {
        clear_extraction_review();
        flash_set('info', 'REVIEW CANCELLED. NO RECORDS WERE SAVED.');
    }
    redirect(APP_BASE_URL . '/admin/view_upload.php?id=' . $uploadId);
}

$review = get_extraction_review($uploadId);

if ($review === null) {
    flash_set('error', 'NO ACTIVE REVIEW FOR THIS UPLOAD. PLEASE EXTRACT RECORDS AGAIN.');
    redirect(APP_BASE_URL . '/admin/view_upload.php?id=' . $uploadId);
}

$rows = $review['rows'];
$currentUser = current_user();
$currentPage = 'upload';
$csrfToken = csrf_token();

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main class="lg:ml-64 min-h-screen bg-surface-950">
    <div class="p-6 lg:p-8">
        <h1 class="text-xl font-semibold text-white mb-2">REVIEW EXTRACTED RECORDS</h1>
        <p class="text-sm text-gray-500 mb-6"><?= e_upper($upload['school_name']) ?> — <?= e($upload['school_year']) ?></p>

        <?php if ($upload['extraction_status'] === 'failed'): ?>
            <div class="mb-6 rounded-lg border border-yellow-500/30 bg-yellow-500/10 text-yellow-400 text-sm px-4 py-3">
                TEXT COULD NOT BE RELIABLY EXTRACTED FROM THIS PDF. PLEASE ADD RECORDS MANUALLY BELOW.
            </div>
        <?php endif; ?>

        <div class="bg-surface-900 border border-white/10 rounded-xl overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead>
                    <tr class="text-xs text-gray-500 border-b border-white/10">
                        <th class="px-4 py-3">NO.</th>
                        <th class="px-4 py-3">GRADUATE NAME</th>
                        <th class="px-4 py-3">LATIN HONOR</th>
                        <th class="px-4 py-3">DATE OF GRADUATION</th>
                        <th class="px-4 py-3">YEAR</th>
                        <th class="px-4 py-3">STATUS</th>
                        <th class="px-4 py-3">ACTION</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="7" class="px-4 py-6 text-center text-gray-500">NO RECORDS YET. CLICK ADD RECORD BELOW.</td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $index => $row): ?>
                            <?php $status = staged_row_status($row); ?>
                            <tr class="border-b border-white/5 hover:bg-white/5">
                                <td class="px-4 py-3"><?= $index + 1 ?></td>
                                <td class="px-4 py-3"><?= e_upper($row['graduate_name']) ?></td>
                                <td class="px-4 py-3"><?= e($row['latin_honor']) ?></td>
                                <td class="px-4 py-3"><?= e(format_date_display($row['graduation_date'])) ?></td>
                                <td class="px-4 py-3"><?= e((string) $row['graduation_year']) ?></td>
                                <td class="px-4 py-3">
                                    <span class="<?= $status === 'VALID' ? 'text-green-400' : 'text-yellow-400' ?>"><?= e($status) ?></span>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <button type="button" class="edit-row-btn text-accent-400 hover:underline mr-3"
                                        data-row-index="<?= $index ?>"
                                        data-name="<?= e($row['graduate_name']) ?>"
                                        data-honor="<?= e($row['latin_honor']) ?>"
                                        data-date="<?= e((string) $row['graduation_date']) ?>"
                                        data-year="<?= e((string) $row['graduation_year']) ?>">EDIT</button>
                                    <button type="button" class="remove-row-btn text-red-400 hover:underline"
                                        data-row-index="<?= $index ?>"
                                        data-name="<?= e($row['graduate_name']) ?>">REMOVE</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="flex items-center justify-between mt-4">
            <p class="text-sm text-gray-400">TOTAL RECORDS: <?= count($rows) ?></p>
            <button type="button" id="add-record-btn" class="rounded-lg border border-white/10 text-gray-300 hover:bg-white/5 text-sm px-4 py-2">ADD RECORD</button>
        </div>

        <div class="flex items-center gap-3 mt-6">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="cancel" value="1">
                <button type="submit" class="rounded-lg border border-white/10 text-gray-300 hover:bg-white/5 text-sm px-4 py-2">CANCEL</button>
            </form>
            <button type="button" id="save-records-btn" class="rounded-lg bg-accent-600 hover:bg-accent-500 text-white text-sm font-medium px-4 py-2">SAVE RECORDS</button>
        </div>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
<script>
window.HGE_REVIEW = {
    uploadId: <?= (int) $uploadId ?>,
    csrfToken: <?= json_encode($csrfToken) ?>,
    apiBase: <?= json_encode(APP_BASE_URL) ?>,
    viewUploadUrl: <?= json_encode(APP_BASE_URL . '/admin/view_upload.php?id=' . $uploadId) ?>
};
</script>
<script src="<?= e(APP_BASE_URL) ?>/assets/js/review.js"></script>
```

- [ ] **Step 2: Commit**

```bash
git add admin/review.php
git commit -m "feat: add extraction review page"
```

(No PHPUnit test — pure page rendering from already-tested helpers; verified in Task 13's end-to-end browser pass.)

---

## Task 9: api/update_graduate.php

**Files:**
- Create: `api/update_graduate.php`

**Interfaces:**
- Consumes: `require_role()`, `csrf_verify()` (Phase 1), `validate_staged_row()` (Task 3), `set_staged_row()` (Task 4).

- [ ] **Step 1: Write api/update_graduate.php**

```php
<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/extraction_review.php';

require_role('administrator');

header('Content-Type: application/json');

if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'YOUR SESSION EXPIRED. PLEASE TRY AGAIN.']);
    exit;
}

$uploadId = (int) ($_POST['upload_id'] ?? 0);
$rowIndex = isset($_POST['row_index']) && $_POST['row_index'] !== '' ? (int) $_POST['row_index'] : null;

$data = [
    'graduate_name' => trim((string) ($_POST['graduate_name'] ?? '')),
    'latin_honor' => (string) ($_POST['latin_honor'] ?? ''),
    'graduation_date' => (string) ($_POST['graduation_date'] ?? ''),
    'graduation_year' => (string) ($_POST['graduation_year'] ?? ''),
];

$validation = validate_staged_row([
    'graduate_name' => $data['graduate_name'],
    'latin_honor' => $data['latin_honor'],
    'graduation_date' => $data['graduation_date'] !== '' ? $data['graduation_date'] : null,
    'graduation_year' => $data['graduation_year'],
]);

if (!$validation['ok']) {
    echo json_encode(['status' => 'error', 'message' => implode(' ', $validation['errors'])]);
    exit;
}

$result = set_staged_row($uploadId, $rowIndex, $data);

if (!$result['ok']) {
    echo json_encode(['status' => 'error', 'message' => $result['error']]);
    exit;
}

echo json_encode(['status' => 'success', 'message' => $rowIndex === null ? 'RECORD ADDED.' : 'RECORD UPDATED.']);
```

- [ ] **Step 2: Commit**

```bash
git add api/update_graduate.php
git commit -m "feat: add update_graduate.php API endpoint"
```

(No PHPUnit test — thin wrapper over already-tested `validate_staged_row()`/`set_staged_row()`, matching the established convention for `api/*.php` files in this project. Verified in Task 13's end-to-end pass.)

---

## Task 10: api/delete_graduate.php

**Files:**
- Create: `api/delete_graduate.php`

**Interfaces:**
- Consumes: `require_role()`, `csrf_verify()` (Phase 1), `remove_staged_row()` (Task 4).

- [ ] **Step 1: Write api/delete_graduate.php**

```php
<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/extraction_review.php';

require_role('administrator');

header('Content-Type: application/json');

if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'YOUR SESSION EXPIRED. PLEASE TRY AGAIN.']);
    exit;
}

$uploadId = (int) ($_POST['upload_id'] ?? 0);
$rowIndex = (int) ($_POST['row_index'] ?? -1);

$result = remove_staged_row($uploadId, $rowIndex);

if (!$result['ok']) {
    echo json_encode(['status' => 'error', 'message' => $result['error']]);
    exit;
}

echo json_encode(['status' => 'success', 'message' => 'RECORD REMOVED.']);
```

- [ ] **Step 2: Commit**

```bash
git add api/delete_graduate.php
git commit -m "feat: add delete_graduate.php API endpoint"
```

---

## Task 11: api/save_records.php

**Files:**
- Create: `api/save_records.php`

**Interfaces:**
- Consumes: `require_role()`, `csrf_verify()` (Phase 1), `get_upload_by_id()` (Phase 2), `get_extraction_review()`, `clear_extraction_review()`, `save_staged_records()` (Task 3/6).

- [ ] **Step 1: Write api/save_records.php**

```php
<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/uploads.php';
require_once __DIR__ . '/../includes/extraction_review.php';

require_role('administrator');

header('Content-Type: application/json');

if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'YOUR SESSION EXPIRED. PLEASE TRY AGAIN.']);
    exit;
}

$uploadId = (int) ($_POST['upload_id'] ?? 0);
$pdo = get_pdo();
$upload = $uploadId > 0 ? get_upload_by_id($pdo, $uploadId) : null;

if ($upload === null) {
    echo json_encode(['status' => 'error', 'message' => 'UPLOAD RECORD NOT FOUND.']);
    exit;
}

$review = get_extraction_review($uploadId);
if ($review === null) {
    echo json_encode(['status' => 'error', 'message' => 'NO ACTIVE REVIEW FOR THIS UPLOAD.']);
    exit;
}

$result = save_staged_records($pdo, $uploadId, $upload['school_id'], $review['rows']);

if ($result['status'] === 'success') {
    clear_extraction_review();
}

echo json_encode($result);
```

- [ ] **Step 2: Commit**

```bash
git add api/save_records.php
git commit -m "feat: add save_records.php API endpoint"
```

---

## Task 12: assets/js/review.js

**Files:**
- Create: `assets/js/review.js`
- Modify: `admin/review.php:1-2` (add the script tag — already present from Task 8; skip if already there)

**Interfaces:**
- Consumes: `showToast()`, `showModal()` (Phase 2), `window.HGE_REVIEW` config object (Task 8), the JSON contracts from `api/update_graduate.php`, `api/delete_graduate.php`, `api/save_records.php` (Tasks 9-11).

**Security note:** `escapeHtml()` below is a local copy of the exact helper Phase 2's `upload.js` uses to fix its stored-XSS finding — required here for the same reason: `graduate_name` values originate from uploaded PDF content (or admin-typed text) and are interpolated into `modal.js`'s `innerHTML` sink. Every dynamic value inserted into `bodyHtml` in this file must pass through it.

- [ ] **Step 1: Write assets/js/review.js**

```js
document.addEventListener('DOMContentLoaded', function () {
    var config = window.HGE_REVIEW;
    if (!config) {
        return;
    }

    function escapeHtml(value) {
        var div = document.createElement('div');
        div.textContent = value;
        return div.innerHTML;
    }

    function postJson(url, data) {
        var formData = new FormData();
        Object.keys(data).forEach(function (key) {
            formData.set(key, data[key]);
        });
        return fetch(url, { method: 'POST', body: formData }).then(function (r) {
            return r.json();
        });
    }

    function reload() {
        window.location.reload();
    }

    function openEditModal(rowIndex, defaults) {
        var isNew = rowIndex === null;
        var honors = ['CUM LAUDE', 'MAGNA CUM LAUDE', 'SUMMA CUM LAUDE'];
        var optionsHtml = honors.map(function (opt) {
            var selected = opt === defaults.honor ? ' selected' : '';
            return '<option value="' + opt + '"' + selected + '>' + opt + '</option>';
        }).join('');

        var bodyHtml =
            '<div class="space-y-3">' +
            '<div><label class="block text-xs text-gray-400 mb-1">GRADUATE NAME</label>' +
            '<input id="modal-graduate-name" type="text" value="' + escapeHtml(defaults.name || '') + '" class="w-full rounded-lg bg-surface-800 border border-white/10 px-3 py-2 text-sm"></div>' +
            '<div><label class="block text-xs text-gray-400 mb-1">LATIN HONOR</label>' +
            '<select id="modal-latin-honor" class="w-full rounded-lg bg-surface-800 border border-white/10 px-3 py-2 text-sm">' + optionsHtml + '</select></div>' +
            '<div><label class="block text-xs text-gray-400 mb-1">DATE OF GRADUATION</label>' +
            '<input id="modal-graduation-date" type="date" value="' + escapeHtml(defaults.date || '') + '" class="w-full rounded-lg bg-surface-800 border border-white/10 px-3 py-2 text-sm"></div>' +
            '<div><label class="block text-xs text-gray-400 mb-1">YEAR GRADUATED</label>' +
            '<input id="modal-graduation-year" type="text" value="' + escapeHtml(defaults.year || '') + '" class="w-full rounded-lg bg-surface-800 border border-white/10 px-3 py-2 text-sm"></div>' +
            '</div>';

        showModal({
            title: isNew ? 'ADD RECORD' : 'EDIT RECORD',
            bodyHtml: bodyHtml,
            buttons: [
                { label: 'CANCEL', className: 'rounded-lg px-4 py-2 text-sm text-gray-300 hover:bg-white/5' },
                {
                    label: 'SAVE',
                    className: 'rounded-lg px-4 py-2 text-sm bg-accent-600 hover:bg-accent-500 text-white',
                    onClick: function (modalClose) {
                        var payload = {
                            csrf_token: config.csrfToken,
                            upload_id: config.uploadId,
                            graduate_name: document.getElementById('modal-graduate-name').value,
                            latin_honor: document.getElementById('modal-latin-honor').value,
                            graduation_date: document.getElementById('modal-graduation-date').value,
                            graduation_year: document.getElementById('modal-graduation-year').value,
                        };
                        if (rowIndex !== null) {
                            payload.row_index = rowIndex;
                        }
                        postJson(config.apiBase + '/api/update_graduate.php', payload)
                            .then(function (data) {
                                if (data.status === 'success') {
                                    showToast('success', data.message);
                                    modalClose();
                                    reload();
                                } else {
                                    showToast('error', data.message);
                                }
                            })
                            .catch(function () {
                                showToast('error', 'THE RECORD COULD NOT BE SAVED. PLEASE TRY AGAIN.');
                            });
                    },
                },
            ],
        });
    }

    document.querySelectorAll('.edit-row-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            openEditModal(parseInt(btn.dataset.rowIndex, 10), {
                name: btn.dataset.name,
                honor: btn.dataset.honor,
                date: btn.dataset.date,
                year: btn.dataset.year,
            });
        });
    });

    var addBtn = document.getElementById('add-record-btn');
    if (addBtn) {
        addBtn.addEventListener('click', function () {
            openEditModal(null, {});
        });
    }

    document.querySelectorAll('.remove-row-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var rowIndex = parseInt(btn.dataset.rowIndex, 10);
            showModal({
                title: 'REMOVE THIS GRADUATE RECORD?',
                bodyHtml: escapeHtml(btn.dataset.name),
                buttons: [
                    { label: 'CANCEL', className: 'rounded-lg px-4 py-2 text-sm text-gray-300 hover:bg-white/5' },
                    {
                        label: 'REMOVE RECORD',
                        className: 'rounded-lg px-4 py-2 text-sm bg-red-600 hover:bg-red-500 text-white',
                        onClick: function (close) {
                            close();
                            postJson(config.apiBase + '/api/delete_graduate.php', {
                                csrf_token: config.csrfToken,
                                upload_id: config.uploadId,
                                row_index: rowIndex,
                            })
                                .then(function (data) {
                                    if (data.status === 'success') {
                                        showToast('success', data.message);
                                        reload();
                                    } else {
                                        showToast('error', data.message);
                                    }
                                })
                                .catch(function () {
                                    showToast('error', 'THE RECORD COULD NOT BE REMOVED. PLEASE TRY AGAIN.');
                                });
                        },
                    },
                ],
            });
        });
    });

    var saveBtn = document.getElementById('save-records-btn');
    if (saveBtn) {
        saveBtn.addEventListener('click', function () {
            postJson(config.apiBase + '/api/save_records.php', {
                csrf_token: config.csrfToken,
                upload_id: config.uploadId,
            })
                .then(function (data) {
                    if (data.status === 'success') {
                        showToast('success', data.message);
                        window.location.href = config.viewUploadUrl;
                    } else {
                        showToast('error', data.message);
                    }
                })
                .catch(function () {
                    showToast('error', 'RECORDS COULD NOT BE SAVED. PLEASE TRY AGAIN.');
                });
        });
    }
});
```

- [ ] **Step 2: Rebuild Tailwind**

Run: `npm run build:css`
Expected: classes referenced only in this new JS file (already-used ones like `bg-red-600`/`hover:bg-red-500` may be new — check) get compiled in (recall: `tailwind.config.js`'s content glob already includes `./assets/js/**/*.js` since Phase 2's Task 11).

- [ ] **Step 3: Run the full PHPUnit suite**

Run: `php vendor/bin/phpunit`
Expected: all tests pass (no PHP changed in this task).

- [ ] **Step 4: Commit**

```bash
git add assets/js/review.js assets/css/app.css
git commit -m "feat: add review page JS (edit/remove/add modals, save, escaping)"
```

---

## Task 13: admin/view_upload.php EXTRACT RECORDS wiring

**Files:**
- Modify: `admin/view_upload.php`
- Create: `assets/js/view_upload.js`

**Interfaces:**
- Consumes: `csrf_token()` (Phase 1), `showToast()` (Phase 2), `api/extract_pdf.php`'s JSON contract (Task 7).

- [ ] **Step 1: Modify admin/view_upload.php**

Current (the full file, for context — you're changing the `<?php ... ?>` header block to compute a CSRF token, and the RECORDS section's markup):

```php
<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/uploads.php';

require_login();

$uploadId = (int) ($_GET['id'] ?? 0);

try {
    $upload = $uploadId > 0 ? get_upload_by_id(get_pdo(), $uploadId) : null;
} catch (\Throwable $e) {
    error_log('Upload lookup failed: ' . $e->getMessage());
    $upload = null;
}

$currentUser = current_user();
$currentPage = 'upload';
```

New:

```php
<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/uploads.php';

require_login();

$uploadId = (int) ($_GET['id'] ?? 0);

try {
    $upload = $uploadId > 0 ? get_upload_by_id(get_pdo(), $uploadId) : null;
} catch (\Throwable $e) {
    error_log('Upload lookup failed: ' . $e->getMessage());
    $upload = null;
}

$currentUser = current_user();
$currentPage = 'upload';
$csrfToken = csrf_token();
```

Current RECORDS block:

```php
                <div>
                    <p class="text-xs text-gray-500">RECORDS</p>
                    <p class="text-sm text-white"><?= (int) $upload['records_extracted'] ?> RECORDS — <?= e_upper($upload['extraction_status']) ?></p>
                </div>
```

New:

```php
                <div>
                    <p class="text-xs text-gray-500">RECORDS</p>
                    <p class="text-sm text-white"><?= (int) $upload['records_extracted'] ?> RECORDS — <?= e_upper($upload['extraction_status']) ?></p>
                    <?php if (user_has_role($currentUser, 'administrator') && $upload['extraction_status'] !== 'reviewed'): ?>
                        <?php if ($upload['extraction_status'] === 'pending'): ?>
                            <button type="button" id="extract-records-btn" data-upload-id="<?= (int) $upload['id'] ?>"
                                class="mt-2 inline-block rounded-lg bg-accent-600 hover:bg-accent-500 text-white text-sm font-medium px-4 py-2 transition-colors">EXTRACT RECORDS</button>
                        <?php else: ?>
                            <a href="<?= e(APP_BASE_URL) ?>/admin/review.php?upload_id=<?= (int) $upload['id'] ?>"
                                class="mt-2 inline-block rounded-lg bg-accent-600 hover:bg-accent-500 text-white text-sm font-medium px-4 py-2 transition-colors">CONTINUE REVIEW</a>
                            <button type="button" id="extract-records-btn" data-upload-id="<?= (int) $upload['id'] ?>"
                                class="mt-2 ml-2 inline-block rounded-lg border border-white/10 text-gray-300 hover:bg-white/5 text-sm px-4 py-2 transition-colors">RE-EXTRACT RECORDS</button>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
```

Immediately after the existing `<?php include __DIR__ . '/../includes/footer.php'; ?>` line at the end of the file, add:

```php
<?php if (user_has_role($currentUser, 'administrator') && $upload !== null && $upload['extraction_status'] !== 'reviewed'): ?>
<script>
window.HGE_VIEW_UPLOAD = {
    csrfToken: <?= json_encode($csrfToken) ?>,
    apiBase: <?= json_encode(APP_BASE_URL) ?>
};
</script>
<script src="<?= e(APP_BASE_URL) ?>/assets/js/view_upload.js"></script>
<?php endif; ?>
```

- [ ] **Step 2: Write assets/js/view_upload.js**

```js
document.addEventListener('DOMContentLoaded', function () {
    var config = window.HGE_VIEW_UPLOAD;
    var btn = document.getElementById('extract-records-btn');
    if (!config || !btn) {
        return;
    }

    btn.addEventListener('click', function () {
        var formData = new FormData();
        formData.set('csrf_token', config.csrfToken);
        formData.set('upload_id', btn.dataset.uploadId);

        fetch(config.apiBase + '/api/extract_pdf.php', {
            method: 'POST',
            body: formData,
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                if (data.status === 'success') {
                    window.location.href = data.redirect_url;
                } else {
                    showToast('error', data.message);
                }
            })
            .catch(function () {
                showToast('error', 'EXTRACTION COULD NOT BE STARTED. PLEASE TRY AGAIN.');
            });
    });
});
```

- [ ] **Step 3: Rebuild Tailwind**

Run: `npm run build:css`

- [ ] **Step 4: Run the full PHPUnit suite**

Run: `php vendor/bin/phpunit`
Expected: all tests pass.

- [ ] **Step 5: Commit**

```bash
git add admin/view_upload.php assets/js/view_upload.js assets/css/app.css
git commit -m "feat: wire EXTRACT RECORDS / CONTINUE REVIEW into view_upload.php"
```

---

## Task 14: End-to-end manual verification

**Files:** none (verification only)

- [ ] **Step 1: Reset dev DB and reseed admin (clean starting state)**

```bash
mysql -u root -e "DROP DATABASE IF EXISTS hge_masterlist"
mysql -u root -e "SOURCE database/schema.sql"
ADMIN_USERNAME=admin ADMIN_PASSWORD=ChangeMe123! php database/seed_admin.php
```

- [ ] **Step 2: Successful-extraction path (synthetic text PDF)**

In the browser, logged in as the seeded administrator:
1. Upload `tests/fixtures/extraction-sample.pdf` for a new school (e.g. "UP TACLOBAN", school year "2026") via `admin/upload.php`.
2. On the resulting `admin/view_upload.php`, click EXTRACT RECORDS.
3. Confirm you land on `admin/review.php` showing 9 rows, all STATUS `VALID`, matching the extractor's validated output (names, honors including SUMMA/MAGNA CUM LAUDE, dates — one row with a blank DATE OF GRADUATION but a populated YEAR from the fallback).
4. Click EDIT on one row, change the LATIN HONOR dropdown, save — confirm the toast and the table reflects the change after reload.
5. Click REMOVE on another row, confirm the "REMOVE THIS GRADUATE RECORD?" modal shows the correct name, click REMOVE RECORD — confirm the row disappears and TOTAL RECORDS decrements.
6. Click ADD RECORD, fill in a new graduate, save — confirm it appears with STATUS `VALID`.
7. Click SAVE RECORDS — confirm success toast and redirect to `admin/view_upload.php`, now showing `records_extracted` matching the final row count and `extraction_status` = `REVIEWED`.
8. Query the database directly to confirm: `SELECT graduate_name, latin_honor, graduation_date, graduation_year FROM latin_honor_graduates WHERE upload_id = <that id>;` — row count and values match what was in the review table at save time.
9. Visit `admin/dashboard.php` and confirm TOTAL GRADUATES reflects the new rows.

- [ ] **Step 3: Failed-extraction path (real scanned PDF)**

1. Upload a genuinely scanned/image-based PDF (the real sample used during brainstorming, or any other scanned PDF) for a different school/year.
2. Click EXTRACT RECORDS — confirm you land on `admin/review.php` with the yellow "TEXT COULD NOT BE RELIABLY EXTRACTED FROM THIS PDF" banner and an empty table.
3. Use ADD RECORD to manually enter 1-2 graduates, then SAVE RECORDS — confirm this works identically to the successful-extraction path's save.

- [ ] **Step 4: Guard checks**

1. Try clicking CONTINUE REVIEW / RE-EXTRACT RECORDS on an already-`REVIEWED` upload's `view_upload.php` — confirm neither button/link is shown (the RECORDS section shows no action).
2. Manually POST to `api/extract_pdf.php` for that same reviewed upload (e.g. via curl with a valid session/CSRF) and confirm the JSON response is the "already been reviewed" error, not a fresh extraction.

- [ ] **Step 5: Clean up test data**

Remove the schools/uploads/graduates/files created during this verification pass (same pattern as Phase 2's manual verification tasks): delete the relevant rows from `latin_honor_graduates`, `latin_honor_uploads`, `schools`, and the corresponding files under `storage/pdfs/`.

- [ ] **Step 6: Final full-suite check**

Run: `php vendor/bin/phpunit`
Expected: all tests still pass.

---

## Phase 3 exit criteria

- `php vendor/bin/phpunit` passes in full (Phases 1+2+3 tests).
- An administrator can extract a text-based PDF's graduates, see them correctly parsed and normalized in the review screen, edit/remove/add rows, and save them to `latin_honor_graduates` — verified against a real-world-modeled PDF layout.
- A scanned/image-based or malformed PDF is detected and routed to a manual-entry path instead of silently creating bad records — verified against a real scanned PDF.
- No graduate record ever reaches the database without passing through the review/validation step.
- Phase 4 (Look-up + Verification) is the next plan, written after this phase is reviewed and confirmed working — it will be the first consumer of the `latin_honor_graduates` rows this phase produces.
