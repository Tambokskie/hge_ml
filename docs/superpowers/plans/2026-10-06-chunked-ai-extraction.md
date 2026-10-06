# Resumable, Page-Chunked AI Extraction Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let large PDFs' AI extraction proceed in page chunks across multiple "Continue Extraction" clicks instead of requiring one Gemini call to process the whole document, while leaving small/typical documents (the common case) behaving exactly as they do today.

**Architecture:** `api/extract_pdf.php`'s AI branch always computes the next unextracted page range via a new pure helper, slices it from the source PDF with Ghostscript (already a required dependency), and sends only that slice to the existing, unmodified `GeminiExtractor`. A document that fits in one chunk takes the exact code path it takes today (full fallback chain to text/OCR included); a document needing more than one chunk gets a distinct, simpler per-chunk success/failure response and persists progress durably in `latin_honor_uploads` + `extraction_review_drafts` so it survives across requests and sessions.

**Tech Stack:** PHP 8.2 (no framework), MySQL/MariaDB, Ghostscript (`gswin64c.exe`, already required for OCR), PHPUnit 10, vanilla JS + Tailwind for the admin UI.

**Spec:** `docs/superpowers/specs/2026-10-06-chunked-ai-extraction-design.md`

## Global Constraints

- Chunking applies to the AI (Gemini) path only. `GenericExtractor` and `OcrExtractor` are untouched, and `GeminiExtractor` itself needs zero code changes — it already just takes a file path.
- No new `extraction_status` enum value. Reuse `draft` for "has at least one successfully-extracted chunk."
- `CHUNK_SIZE` is a hardcoded constant, `AI_EXTRACTION_CHUNK_PAGE_SIZE = 15`, not admin-configurable.
- A failed chunk leaves `extracted_through_page` untouched and does **not** fall back to text/OCR extraction for that page range — the admin retries via "Continue Extraction" later.
- "Resume Draft Review" must be hidden (UI) **and** blocked (server-side redirect in `admin/review.php`) whenever `total_pages !== null && extracted_through_page < total_pages`.
- `total_pages` stays `NULL` for anything not chunked — every pre-existing upload, and every document that finishes in exactly one chunk. Gating logic must check `total_pages !== null` before ever comparing the two counters.
- No per-chunk/per-page status checklist UI — one progress summary line only.
- This project has no migration framework. `database/schema.sql` is updated for fresh installs; the live `hge_masterlist` database needs a manual `ALTER TABLE` (documented in Task 1, not scripted).

## Review Focus

- A pre-existing upload from before this feature (`total_pages = NULL`, `extracted_through_page = 0` by column default) must never show "Continue Extraction" and must behave exactly as it does today — pinned by a `upload_needs_more_extraction()` unit test with `total_pages: null`.
- A document that fits in exactly one chunk (`total_pages <= 15`) must behave byte-for-byte like today: `extraction_status` becomes `extracted` (not `draft`), the full existing text/OCR fallback chain still applies on AI failure, and the response redirects straight to `review.php` — verified live in Task 9 since `api/extract_pdf.php` has no direct test coverage today either.
- Clicking "RE-EXTRACT RECORDS" partway through a multi-chunk extraction must fully reset `total_pages`/`extracted_through_page` and delete the existing draft, not mix stale chunk rows with a fresh attempt. `reset_upload_chunk_progress()` (the column reset) is unit-tested in isolation in Task 5; `delete_extraction_draft()` already has its own pre-existing test coverage. The two being called *together* on a RE-EXTRACT click is `extract_pdf.php`'s own orchestration logic, which — like the rest of that file — has no direct test coverage by this project's established convention, so that combination is verified live in Task 9, Step 8.
- Direct navigation to `admin/review.php?upload_id=X` while extraction is incomplete must redirect back with a flash message, not silently open a partial row set for "final" review — the underlying `upload_needs_more_extraction()` check is unit-tested in Task 5; the redirect wiring itself is verified live in Task 9 (matching `review.php`'s existing no-test-coverage convention).
- **Known limitation, not fixed by this plan:** a chunk whose AI response is valid but genuinely contains zero graduate rows (e.g. a page range that's entirely a signature block) is indistinguishable from a true AI failure, because `GeminiExtractor::extract()` already collapses both cases to `status: 'failed'` today — a pre-existing conflation in `GeminiExtractor`'s contract, which this plan does not touch (see Global Constraints: `GeminiExtractor` needs zero changes). Such a chunk will appear stuck as "retry via Continue Extraction" with no automatic resolution. Flagged here rather than silently shipped; worth a follow-up if it turns out to matter in practice.

---

### Task 1: Schema — chunk-progress columns on `latin_honor_uploads`

**Files:**
- Modify: `database/schema.sql` (the `latin_honor_uploads` table definition)
- Test: `tests/SchemaTest.php`

**Interfaces:**
- Produces: two new columns other tasks read/write via plain SQL — `latin_honor_uploads.total_pages` (`INT UNSIGNED NULL`), `latin_honor_uploads.extracted_through_page` (`INT UNSIGNED NOT NULL DEFAULT 0`).

- [ ] **Step 1: Write the failing test**

Add to `tests/SchemaTest.php`:

```php
    public function testUploadsTableHasChunkProgressColumnsWithSafeDefaults(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO schools (school_name) VALUES ('TEST UNIVERSITY')");
        $pdo->exec("INSERT INTO users (name, username, email, password, role) VALUES ('Admin', 'admin', 'a@a.com', 'x', 'administrator')");
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number)
             VALUES (1, '2025', 'list.pdf', 'TEST_UNIVERSITY_2025_1.pdf', '/storage/pdfs/x.pdf', 100, SHA2('a', 256), 1, 1)"
        );

        $row = $pdo->query('SELECT total_pages, extracted_through_page FROM latin_honor_uploads WHERE id = 1')->fetch();

        $this->assertNull($row['total_pages']);
        $this->assertSame(0, (int) $row['extracted_through_page']);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/SchemaTest.php --filter testUploadsTableHasChunkProgressColumnsWithSafeDefaults`
Expected: FAIL — `Unknown column 'total_pages'` (or similar SQL error), since the columns don't exist yet.

- [ ] **Step 3: Add the columns to the schema**

In `database/schema.sql`, inside the `latin_honor_uploads` table definition, add the two new columns right after `records_extracted INT UNSIGNED NOT NULL DEFAULT 0,`:

```sql
  records_extracted INT UNSIGNED NOT NULL DEFAULT 0,
  total_pages INT UNSIGNED NULL,
  extracted_through_page INT UNSIGNED NOT NULL DEFAULT 0,
  uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
```

(i.e. insert the two new lines between the existing `records_extracted` and `uploaded_at` lines — everything else in the table stays unchanged.)

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/SchemaTest.php`
Expected: PASS (all tests in the file, including the new one — `TestDb::resetSchema()` rebuilds the test database from `schema.sql` fresh, so this is enough for the test suite).

- [ ] **Step 5: Record the live-database migration step (manual, one-time)**

This project has no migration framework — `schema.sql` only affects a fresh install or the test database. The live `hge_masterlist` database needs this run once, by hand, against it:

```sql
ALTER TABLE latin_honor_uploads
  ADD COLUMN total_pages INT UNSIGNED NULL AFTER records_extracted,
  ADD COLUMN extracted_through_page INT UNSIGNED NOT NULL DEFAULT 0 AFTER total_pages;
```

Run this via phpMyAdmin or the MySQL CLI against the live `hge_masterlist` database before any chunked-extraction code from later tasks reaches that environment. Do not run this against the test database — `TestDb::resetSchema()` already creates it fresh from the updated `schema.sql`.

- [ ] **Step 6: Commit**

```bash
git add database/schema.sql tests/SchemaTest.php
git commit -m "feat: add chunk-progress columns to latin_honor_uploads"
```

---

### Task 2: Ghostscript page-count and page-range-slice helpers

**Files:**
- Create: `includes/pdf_pages.php`
- Test: `tests/includes/PdfPagesTest.php`

**Interfaces:**
- Consumes: `resolve_ghostscript_path(): string` from `includes/ocr_tools.php`.
- Produces: `count_pdf_pages(string $filePath): ?int`, `extract_pdf_page_range(string $filePath, int $first, int $last): ?string` (returns a temp file path the caller must delete, or `null` on failure).

- [ ] **Step 1: Write the failing tests**

Create `tests/includes/PdfPagesTest.php`:

```php
<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/ocr_tools.php';
require_once __DIR__ . '/../../includes/pdf_pages.php';

class PdfPagesTest extends TestCase
{
    private string $singlePage;
    private string $twoPage;

    protected function setUp(): void
    {
        $tools = check_ocr_tools_installed();
        if (!$tools['ghostscript']) {
            $this->markTestSkipped('Ghostscript binary not found — skipping PDF page tests on this machine.');
        }

        $this->singlePage = __DIR__ . '/../fixtures/sample.pdf';

        // Two real fixtures merged into one 2-page PDF via Ghostscript itself
        // (confirmed working during design) — avoids checking in a dedicated
        // multi-page binary fixture just for this test.
        $this->twoPage = sys_get_temp_dir() . '/hge_pdf_pages_test_' . uniqid() . '.pdf';
        $ghostscriptPath = resolve_ghostscript_path();
        $cmd = escapeshellarg($ghostscriptPath)
            . ' -q -dNOPAUSE -dBATCH -dSAFER -sDEVICE=pdfwrite'
            . ' -sOutputFile=' . escapeshellarg($this->twoPage)
            . ' ' . escapeshellarg(__DIR__ . '/../fixtures/sample.pdf')
            . ' ' . escapeshellarg(__DIR__ . '/../fixtures/extraction-sample.pdf');
        exec($cmd . ' 2>&1', $output, $returnCode);
        if ($returnCode !== 0 || !is_file($this->twoPage)) {
            $this->fail('Could not build the 2-page test fixture via Ghostscript: ' . implode(' ', $output));
        }
    }

    protected function tearDown(): void
    {
        if (is_file($this->twoPage)) {
            @unlink($this->twoPage);
        }
    }

    public function testCountPdfPagesReturnsOneForASinglePageDocument(): void
    {
        $this->assertSame(1, count_pdf_pages($this->singlePage));
    }

    public function testCountPdfPagesReturnsTwoForATwoPageDocument(): void
    {
        $this->assertSame(2, count_pdf_pages($this->twoPage));
    }

    public function testCountPdfPagesReturnsNullForANonexistentFile(): void
    {
        $this->assertNull(count_pdf_pages(sys_get_temp_dir() . '/hge_does_not_exist_' . uniqid() . '.pdf'));
    }

    public function testExtractPdfPageRangeProducesAOnePageSlice(): void
    {
        $slicePath = extract_pdf_page_range($this->twoPage, 1, 1);

        $this->assertNotNull($slicePath);
        $this->assertSame(1, count_pdf_pages($slicePath));

        @unlink($slicePath);
    }

    public function testExtractPdfPageRangeProducesTheSecondPageWhenAskedForIt(): void
    {
        $slicePath = extract_pdf_page_range($this->twoPage, 2, 2);

        $this->assertNotNull($slicePath);
        $this->assertSame(1, count_pdf_pages($slicePath));

        @unlink($slicePath);
    }

    public function testExtractPdfPageRangeReturnsNullForANonexistentFile(): void
    {
        $this->assertNull(extract_pdf_page_range(sys_get_temp_dir() . '/hge_does_not_exist_' . uniqid() . '.pdf', 1, 1));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/includes/PdfPagesTest.php`
Expected: FAIL — `includes/pdf_pages.php` doesn't exist yet, so `count_pdf_pages`/`extract_pdf_page_range` are undefined functions (fatal error).

- [ ] **Step 3: Implement the helpers**

Create `includes/pdf_pages.php`:

```php
<?php

require_once __DIR__ . '/ocr_tools.php';

/**
 * Total page count of a PDF via Ghostscript's own pdfpagecount PostScript
 * operator — avoids adding a second PDF-parsing dependency just for a page
 * count when Ghostscript is already required for OCR rasterization
 * (includes/ocr_tools.php). Ghostscript's default -dSAFER sandboxing blocks
 * the raw PostScript `file` operator used here unless the exact path is
 * explicitly permitted via --permit-file-read.
 */
function count_pdf_pages(string $filePath): ?int
{
    if (!is_file($filePath)) {
        return null;
    }

    $ghostscriptPath = resolve_ghostscript_path();
    $normalizedPath = str_replace('\\', '/', $filePath);
    $psCommand = '(' . $normalizedPath . ') (r) file runpdfbegin pdfpagecount = quit';

    $cmd = escapeshellarg($ghostscriptPath)
        . ' -q -dNODISPLAY'
        . ' ' . escapeshellarg('--permit-file-read=' . $normalizedPath)
        . ' -c ' . escapeshellarg($psCommand);

    exec($cmd . ' 2>&1', $output, $returnCode);

    if ($returnCode !== 0 || empty($output)) {
        error_log('count_pdf_pages: Ghostscript failed (exit ' . $returnCode . '): ' . implode(' ', $output));
        return null;
    }

    $count = (int) trim((string) end($output));
    return $count > 0 ? $count : null;
}

/**
 * Slices pages $first..$last (1-indexed, inclusive) of $filePath into a new,
 * standalone temp PDF and returns its path. The caller is responsible for
 * deleting it once done. Returns null on any Ghostscript failure.
 */
function extract_pdf_page_range(string $filePath, int $first, int $last): ?string
{
    if (!is_file($filePath)) {
        return null;
    }

    $ghostscriptPath = resolve_ghostscript_path();
    $outputPath = sys_get_temp_dir() . '/hge_chunk_' . uniqid() . '.pdf';

    $cmd = escapeshellarg($ghostscriptPath)
        . ' -q -dNOPAUSE -dBATCH -dSAFER -sDEVICE=pdfwrite'
        . ' -dFirstPage=' . $first . ' -dLastPage=' . $last
        . ' -sOutputFile=' . escapeshellarg($outputPath)
        . ' ' . escapeshellarg($filePath);

    exec($cmd . ' 2>&1', $output, $returnCode);

    if ($returnCode !== 0 || !is_file($outputPath)) {
        error_log('extract_pdf_page_range: Ghostscript failed (exit ' . $returnCode . '): ' . implode(' ', $output));
        return null;
    }

    return $outputPath;
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/includes/PdfPagesTest.php`
Expected: PASS (all 7 tests). If Ghostscript isn't installed on the current machine, the whole class reports SKIPPED instead — not a failure.

- [ ] **Step 5: Commit**

```bash
git add includes/pdf_pages.php tests/includes/PdfPagesTest.php
git commit -m "feat: add Ghostscript-backed PDF page-count and page-range-slice helpers"
```

---

### Task 3: Pure chunk-range calculation

**Files:**
- Modify: `includes/extraction_review.php`
- Test: `tests/includes/ExtractionReviewCoreTest.php`

**Interfaces:**
- Produces: `next_chunk_range(int $extractedThroughPage, int $totalPages, int $chunkSize): ?array{first: int, last: int}` — returns `null` once every page has been covered.

- [ ] **Step 1: Write the failing tests**

Add to `tests/includes/ExtractionReviewCoreTest.php` (inside the existing `ExtractionReviewCoreTest` class, alongside `testDeriveFallbackYear...`):

```php
    public function testNextChunkRangeReturnsTheFirstChunkFromZero(): void
    {
        $this->assertSame(['first' => 1, 'last' => 15], next_chunk_range(0, 120, 15));
    }

    public function testNextChunkRangeReturnsAMiddleChunk(): void
    {
        $this->assertSame(['first' => 46, 'last' => 60], next_chunk_range(45, 120, 15));
    }

    public function testNextChunkRangeReturnsAFinalPartialChunkShorterThanChunkSize(): void
    {
        $this->assertSame(['first' => 106, 'last' => 120], next_chunk_range(105, 120, 20));
    }

    public function testNextChunkRangeReturnsNullWhenNothingRemains(): void
    {
        $this->assertNull(next_chunk_range(120, 120, 15));
    }

    public function testNextChunkRangeCoversTheWholeDocumentWhenItFitsInOneChunk(): void
    {
        $this->assertSame(['first' => 1, 'last' => 5], next_chunk_range(0, 5, 15));
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/includes/ExtractionReviewCoreTest.php --filter NextChunkRange`
Expected: FAIL — `next_chunk_range` is an undefined function.

- [ ] **Step 3: Implement the function**

Add to `includes/extraction_review.php`, after `derive_fallback_year()`:

```php
/**
 * Computes the next page range to extract for the chunked AI extraction
 * flow (api/extract_pdf.php), given how many pages have already been
 * successfully extracted, the document's total page count, and the
 * configured chunk size. Returns null once every page is covered — the
 * signal that nothing remains to extract.
 */
function next_chunk_range(int $extractedThroughPage, int $totalPages, int $chunkSize): ?array
{
    if ($extractedThroughPage >= $totalPages) {
        return null;
    }

    $first = $extractedThroughPage + 1;
    $last = min($extractedThroughPage + $chunkSize, $totalPages);

    return ['first' => $first, 'last' => $last];
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/includes/ExtractionReviewCoreTest.php`
Expected: PASS (all tests in the file).

- [ ] **Step 5: Commit**

```bash
git add includes/extraction_review.php tests/includes/ExtractionReviewCoreTest.php
git commit -m "feat: add next_chunk_range() for chunked AI extraction"
```

---

### Task 4: Append rows onto an existing extraction draft

**Files:**
- Modify: `includes/extraction_review.php`
- Test: `tests/includes/ExtractionDraftTest.php`

**Interfaces:**
- Consumes: `get_extraction_draft(PDO $pdo, int $uploadId): ?array`, `save_extraction_draft(PDO $pdo, int $uploadId, int $savedBy, array $rows): array` (both already exist, unchanged).
- Produces: `append_extraction_draft_rows(PDO $pdo, int $uploadId, int $savedBy, array $newRows): array` — same return shape as `save_extraction_draft()`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/includes/ExtractionDraftTest.php` (inside the existing `ExtractionDraftTest` class):

```php
    public function testAppendExtractionDraftRowsCreatesADraftWhenNoneExists(): void
    {
        $pdo = TestDb::pdo();
        $newRows = [
            ['graduate_name' => 'Juan Dela Cruz', 'course' => '', 'latin_honor' => 'CUM LAUDE', 'graduation_date' => null, 'graduation_year' => 2025],
        ];

        $result = append_extraction_draft_rows($pdo, 1, 1, $newRows);

        $this->assertSame('success', $result['status']);
        $draft = get_extraction_draft($pdo, 1);
        $this->assertSame($newRows, $draft['rows']);
    }

    public function testAppendExtractionDraftRowsAddsToAnExistingDraftWithoutDroppingPriorRows(): void
    {
        $pdo = TestDb::pdo();
        $firstChunkRows = [
            ['graduate_name' => 'Juan Dela Cruz', 'course' => '', 'latin_honor' => 'CUM LAUDE', 'graduation_date' => null, 'graduation_year' => 2025],
        ];
        save_extraction_draft($pdo, 1, 1, $firstChunkRows);

        $secondChunkRows = [
            ['graduate_name' => 'Maria Santos', 'course' => '', 'latin_honor' => 'MAGNA CUM LAUDE', 'graduation_date' => null, 'graduation_year' => 2025],
        ];
        append_extraction_draft_rows($pdo, 1, 1, $secondChunkRows);

        $draft = get_extraction_draft($pdo, 1);
        $this->assertCount(2, $draft['rows']);
        $this->assertSame('Juan Dela Cruz', $draft['rows'][0]['graduate_name']);
        $this->assertSame('Maria Santos', $draft['rows'][1]['graduate_name']);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/includes/ExtractionDraftTest.php --filter AppendExtractionDraftRows`
Expected: FAIL — `append_extraction_draft_rows` is an undefined function.

- [ ] **Step 3: Implement the function**

Add to `includes/extraction_review.php`, right after `save_extraction_draft()`:

```php
/**
 * Appends newly-extracted rows onto an upload's existing draft (if any),
 * reusing save_extraction_draft() for the actual persistence. Used by the
 * chunked AI extraction flow (api/extract_pdf.php), where each
 * successfully-extracted page range adds its rows to what prior chunks
 * already staged, rather than replacing them.
 */
function append_extraction_draft_rows(PDO $pdo, int $uploadId, int $savedBy, array $newRows): array
{
    $existing = get_extraction_draft($pdo, $uploadId);
    $rows = $existing !== null ? $existing['rows'] : [];

    return save_extraction_draft($pdo, $uploadId, $savedBy, array_merge($rows, $newRows));
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/includes/ExtractionDraftTest.php`
Expected: PASS (all tests in the file).

- [ ] **Step 5: Commit**

```bash
git add includes/extraction_review.php tests/includes/ExtractionDraftTest.php
git commit -m "feat: add append_extraction_draft_rows() for chunked AI extraction"
```

---

### Task 5: Persist and reset chunk progress on `latin_honor_uploads`

**Files:**
- Modify: `includes/uploads.php` (`get_upload_by_id()`'s SELECT list, plus three new functions)
- Test: `tests/includes/UpdateExtractionStatusTest.php`

**Interfaces:**
- Consumes: Task 1's `total_pages`/`extracted_through_page` columns.
- Produces:
  - `update_upload_chunk_progress(PDO $pdo, int $uploadId, int $totalPages, int $extractedThroughPage): void`
  - `reset_upload_chunk_progress(PDO $pdo, int $uploadId): void` (resets the two columns only — does **not** touch the draft table; callers that also need the draft cleared call `delete_extraction_draft()` separately, matching this codebase's existing convention of `includes/*.php` files never cross-requiring each other)
  - `upload_needs_more_extraction(array $upload): bool`
  - `get_upload_by_id()`'s returned array gains two new keys: `total_pages` (`int|null`), `extracted_through_page` (`int`).

- [ ] **Step 1: Write the failing tests**

Add to `tests/includes/UpdateExtractionStatusTest.php` (inside the existing `UpdateExtractionStatusTest` class):

```php
    public function testUpdateUploadChunkProgressPersistsTotalPagesAndCursor(): void
    {
        $pdo = TestDb::pdo();

        update_upload_chunk_progress($pdo, 1, 120, 45);

        $row = $pdo->query('SELECT total_pages, extracted_through_page FROM latin_honor_uploads WHERE id = 1')->fetch();
        $this->assertSame(120, (int) $row['total_pages']);
        $this->assertSame(45, (int) $row['extracted_through_page']);
    }

    public function testResetUploadChunkProgressClearsTotalPagesAndCursor(): void
    {
        $pdo = TestDb::pdo();
        update_upload_chunk_progress($pdo, 1, 120, 45);

        reset_upload_chunk_progress($pdo, 1);

        $row = $pdo->query('SELECT total_pages, extracted_through_page FROM latin_honor_uploads WHERE id = 1')->fetch();
        $this->assertNull($row['total_pages']);
        $this->assertSame(0, (int) $row['extracted_through_page']);
    }

    public function testUploadNeedsMoreExtractionIsFalseWhenTotalPagesIsNull(): void
    {
        $this->assertFalse(upload_needs_more_extraction(['total_pages' => null, 'extracted_through_page' => 0]));
    }

    public function testUploadNeedsMoreExtractionIsTrueWhenPagesRemain(): void
    {
        $this->assertTrue(upload_needs_more_extraction(['total_pages' => 120, 'extracted_through_page' => 45]));
    }

    public function testUploadNeedsMoreExtractionIsFalseWhenFullyExtracted(): void
    {
        $this->assertFalse(upload_needs_more_extraction(['total_pages' => 120, 'extracted_through_page' => 120]));
    }

    public function testGetUploadByIdIncludesChunkProgressColumns(): void
    {
        $pdo = TestDb::pdo();
        update_upload_chunk_progress($pdo, 1, 120, 45);

        $upload = get_upload_by_id($pdo, 1);

        $this->assertSame(120, $upload['total_pages']);
        $this->assertSame(45, $upload['extracted_through_page']);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/includes/UpdateExtractionStatusTest.php --filter "ChunkProgress|UploadNeedsMoreExtraction|IncludesChunkProgress"`
Expected: FAIL — the three new functions are undefined, and `get_upload_by_id()` doesn't return the new keys yet.

- [ ] **Step 3: Implement the functions and extend the SELECT**

In `includes/uploads.php`, modify `get_upload_by_id()`'s query (currently starting at line 163) to add the two new columns to the SELECT list:

```php
function get_upload_by_id(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT u.id, u.school_id, u.school_year, u.original_filename, u.stored_filename, u.file_path,
                u.file_size, u.uploaded_at, u.extraction_status, u.records_extracted,
                u.total_pages, u.extracted_through_page, s.school_name, s.campus,
                usr.name AS uploaded_by_name
         FROM latin_honor_uploads u
         JOIN schools s ON s.id = u.school_id
         LEFT JOIN users usr ON usr.id = u.uploaded_by
         WHERE u.id = :id
         LIMIT 1'
    );
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}
```

Then add three new functions right after `update_upload_extraction_status()` (at the end of the file):

```php
/**
 * Called after a chunk of AI extraction succeeds — records the document's
 * total page count (set once, on the first chunk of a fresh attempt) and
 * how far extraction has progressed so far.
 */
function update_upload_chunk_progress(PDO $pdo, int $uploadId, int $totalPages, int $extractedThroughPage): void
{
    $stmt = $pdo->prepare(
        'UPDATE latin_honor_uploads SET total_pages = :total_pages, extracted_through_page = :extracted_through_page WHERE id = :id'
    );
    $stmt->execute([
        'total_pages' => $totalPages,
        'extracted_through_page' => $extractedThroughPage,
        'id' => $uploadId,
    ]);
}

/**
 * Called at the start of a fresh AI extraction attempt (first-time, or
 * RE-EXTRACT RECORDS) — clears any chunk progress from a prior attempt, so
 * the next chunk computed is page 1 of a freshly-counted document rather
 * than continuing a stale cursor. Does not touch extraction_review_drafts;
 * callers that also need the existing draft cleared (RE-EXTRACT RECORDS)
 * call delete_extraction_draft() separately, since includes/*.php files in
 * this codebase don't cross-require each other.
 */
function reset_upload_chunk_progress(PDO $pdo, int $uploadId): void
{
    $pdo->prepare('UPDATE latin_honor_uploads SET total_pages = NULL, extracted_through_page = 0 WHERE id = :id')
        ->execute(['id' => $uploadId]);
}

/**
 * True when a chunked AI extraction is in progress but not yet covering
 * every page. admin/view_upload.php uses this to show "Continue
 * Extraction" instead of "Resume Draft Review"; admin/review.php uses it to
 * refuse opening a review until extraction finishes. total_pages is NULL
 * for every upload that isn't mid-chunked-extraction — a pre-existing
 * upload, or one that finished in a single chunk — so this is always false
 * for those, regardless of extracted_through_page's value.
 */
function upload_needs_more_extraction(array $upload): bool
{
    return $upload['total_pages'] !== null && (int) $upload['extracted_through_page'] < (int) $upload['total_pages'];
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/includes/UpdateExtractionStatusTest.php`
Expected: PASS (all tests in the file).

- [ ] **Step 5: Run the full includes test suite to check for regressions**

Run: `vendor/bin/phpunit tests/includes/`
Expected: PASS (no other test references `get_upload_by_id()`'s exact key set in a way the two new keys would break — confirm this is actually true by reading the output).

- [ ] **Step 6: Commit**

```bash
git add includes/uploads.php tests/includes/UpdateExtractionStatusTest.php
git commit -m "feat: persist and reset chunk extraction progress on latin_honor_uploads"
```

---

### Task 6: Wire chunking into `api/extract_pdf.php`'s AI branch

**Files:**
- Modify: `api/extract_pdf.php`

**Interfaces:**
- Consumes: `count_pdf_pages()`, `extract_pdf_page_range()` (Task 2); `next_chunk_range()`, `append_extraction_draft_rows()`, `delete_extraction_draft()` (Tasks 3–4, `delete_extraction_draft()` already existed); `update_upload_chunk_progress()`, `reset_upload_chunk_progress()` (Task 5); `current_user()` (already required via `includes/auth.php`).
- Produces: for a genuine multi-chunk partial success, a JSON response `{"status":"success","extraction_status":"draft","redirect_url":...}` where `redirect_url` points to `view_upload.php` (more chunks remain) or `review.php` (this was the last chunk) — same shape the existing success response already uses, so `assets/js/view_upload.js` needs no new response-branch. For a chunk failure, `{"status":"error","message":...}` — identical shape to every other failure response already handled by the existing generic `else` branch in `view_upload.js`.

This task has no new automated test — `api/extract_pdf.php` has no direct test coverage today (confirmed during design: only `tests/extractors/GeminiExtractorTest.php` exists, nothing exercises this script directly), and the pure logic it now depends on (`next_chunk_range()`, the Ghostscript helpers, `append_extraction_draft_rows()`) is already covered by Tasks 2–4. This task is implementation + the live verification in Task 9.

- [ ] **Step 1: Add the new require and the chunk-size constant**

In `api/extract_pdf.php`, add one line to the existing require block (after the `extraction_progress.php` require, before the extractor requires):

```php
require_once __DIR__ . '/../includes/extraction_progress.php';
require_once __DIR__ . '/../includes/pdf_pages.php';
require_once __DIR__ . '/../extractors/ExtractorInterface.php';
```

Add the chunk-size constant right after the requires, before `require_role('administrator');`:

```php
// Chunk size for AI extraction — see
// docs/superpowers/specs/2026-10-06-chunked-ai-extraction-design.md. A
// document with total_pages <= this fits in one chunk and behaves exactly
// as before chunking existed; a longer document needs one
// "Continue Extraction" click per additional chunk.
const AI_EXTRACTION_CHUNK_PAGE_SIZE = 15;

require_role('administrator');
```

- [ ] **Step 2: Replace the AI (non-OCR) branch**

Locate the `else` branch that currently reads (inside the `if ($useOcr) { ... } else { ... }` block):

```php
            } else {
                // AI extraction is the primary path: it reads the PDF directly
                // and understands layout (wrapped cells, borderless columns,
                // narrative letters) without a bespoke handler per layout.
                // resolve_gemini_api_keys() prefers a key saved via Settings ->
                // AI Extraction (admin/users.php) over the env-var default.
                $geminiExtractor = new GeminiExtractor(resolve_gemini_api_keys($pdo));
                write_extraction_progress($uploadId, 15, 'SENDING DOCUMENT TO AI...');
                $result = $geminiExtractor->extract($upload['file_path']);

                if ($result['status'] === 'failed' && !GeminiExtractor::isQuotaExhausted() && empty($result['unreachable'])) {
                    // Today's estimated AI usage still has room and Google was
                    // actually reachable, so this wasn't a quota or connectivity
                    // problem — most likely a transient overload or a one-off
                    // unparseable response. Give AI a single extra try before
                    // conceding to the OCR fallback (bounded, not a loop, so a
                    // persistently failing document can't blow past PHP's
                    // execution time limit).
                    write_extraction_progress($uploadId, 25, 'RETRYING AI EXTRACTION...');
                    $result = $geminiExtractor->extract($upload['file_path']);
                }

                $aiQuotaExhausted = GeminiExtractor::isQuotaExhausted();
                $aiUnreachable = !empty($result['unreachable']);

                if ($result['status'] === 'failed') {
                    // GenericExtractor is a fast, free text-layer read, not
                    // OCR — try it before asking the admin anything.
                    write_extraction_progress($uploadId, 40, 'READING DOCUMENT TEXT...');
                    $result = (new GenericExtractor($extraCourseKeywords))->extract($upload['file_path']);
                }

                if ($result['status'] === 'failed') {
                    // AI failed and this PDF has no text layer either — actual
                    // (Tesseract) OCR is the only remaining option, and it's
                    // slower and less accurate than AI/text-layer extraction.
                    // Ask before running it instead of silently falling back.
                    $reason = $aiQuotaExhausted
                        ? "TODAY'S FREE AI EXTRACTION QUOTA HAS BEEN USED UP."
                        : ($aiUnreachable
                            ? 'THE AI EXTRACTION SERVICE COULD NOT BE REACHED.'
                            : 'AI EXTRACTION WAS UNABLE TO PROCESS THIS DOCUMENT.');

                    clear_extraction_progress($uploadId);
                    echo json_encode([
                        'status' => 'ocr_confirm_required',
                        'message' => $reason . ' THIS DOCUMENT HAS NO SELECTABLE TEXT, SO OPTICAL CHARACTER RECOGNITION (OCR) IS NEEDED TO READ IT INSTEAD. '
                            . 'OCR RESULTS CAN BE LESS ACCURATE AND WILL NEED CAREFUL REVIEW. PROCEED WITH OCR EXTRACTION?',
                    ]);
                    exit;
                }
            }
```

Replace that entire `else { ... }` block with:

```php
            } else {
                // AI extraction always runs in page chunks (see
                // docs/superpowers/specs/2026-10-06-chunked-ai-extraction-design.md):
                // a document short enough to fit in one chunk is extracted in
                // a single call exactly as before chunking existed, including
                // the full existing text/OCR fallback chain below; a longer
                // document is extracted one chunk per "Continue Extraction"
                // click, with no fallback to text/OCR for an individual
                // chunk's failure.
                $isContinuing = !empty($_POST['continue']);
                if (!$isContinuing) {
                    reset_upload_chunk_progress($pdo, $uploadId);
                    delete_extraction_draft($pdo, $uploadId);
                    $upload['total_pages'] = null;
                    $upload['extracted_through_page'] = 0;
                }

                $totalPages = $upload['total_pages'];
                if ($totalPages === null) {
                    $totalPages = count_pdf_pages($upload['file_path']);
                    if ($totalPages === null) {
                        clear_extraction_progress($uploadId);
                        echo json_encode(['status' => 'error', 'message' => 'THE FILE COULD NOT BE PROCESSED.']);
                        exit;
                    }
                }

                $chunk = next_chunk_range((int) $upload['extracted_through_page'], $totalPages, AI_EXTRACTION_CHUNK_PAGE_SIZE);
                if ($chunk === null) {
                    clear_extraction_progress($uploadId);
                    echo json_encode(['status' => 'error', 'message' => 'ALL PAGES HAVE ALREADY BEEN EXTRACTED FOR THIS UPLOAD.']);
                    exit;
                }

                $isWholeDocumentChunk = $chunk['first'] === 1 && $chunk['last'] === $totalPages;

                if ($isWholeDocumentChunk) {
                    // Fits in one chunk — no need to slice a copy at all,
                    // send the real stored file exactly as before chunking
                    // existed.
                    $chunkFilePath = $upload['file_path'];
                } else {
                    $chunkFilePath = extract_pdf_page_range($upload['file_path'], $chunk['first'], $chunk['last']);
                    if ($chunkFilePath === null) {
                        clear_extraction_progress($uploadId);
                        echo json_encode(['status' => 'error', 'message' => 'THE FILE COULD NOT BE PROCESSED.']);
                        exit;
                    }
                }

                // resolve_gemini_api_keys() prefers a key saved via Settings ->
                // AI Extraction (admin/users.php) over the env-var default.
                $geminiExtractor = new GeminiExtractor(resolve_gemini_api_keys($pdo));
                write_extraction_progress($uploadId, 15, 'SENDING PAGES ' . $chunk['first'] . '-' . $chunk['last'] . ' TO AI...');
                $result = $geminiExtractor->extract($chunkFilePath);

                if ($result['status'] === 'failed' && !GeminiExtractor::isQuotaExhausted() && empty($result['unreachable'])) {
                    // Today's estimated AI usage still has room and Google was
                    // actually reachable, so this wasn't a quota or connectivity
                    // problem — most likely a transient overload or a one-off
                    // unparseable response. Give AI a single extra try before
                    // conceding (bounded, not a loop, so a persistently failing
                    // chunk can't blow past PHP's execution time limit).
                    write_extraction_progress($uploadId, 25, 'RETRYING AI EXTRACTION...');
                    $result = $geminiExtractor->extract($chunkFilePath);
                }

                $aiQuotaExhausted = GeminiExtractor::isQuotaExhausted();
                $aiUnreachable = !empty($result['unreachable']);

                if (!$isWholeDocumentChunk) {
                    // A genuine partial chunk never falls back to text/OCR for
                    // just its own page range — either it succeeded, or the
                    // admin retries this same range later via Continue
                    // Extraction. See the spec's "AI path only" / "mark
                    // failed, retry later" decisions.
                    @unlink($chunkFilePath);

                    if ($result['status'] === 'failed') {
                        clear_extraction_progress($uploadId);
                        $reason = $aiQuotaExhausted
                            ? "TODAY'S FREE AI EXTRACTION QUOTA HAS BEEN USED UP."
                            : ($aiUnreachable
                                ? 'THE AI EXTRACTION SERVICE COULD NOT BE REACHED.'
                                : 'AI EXTRACTION WAS UNABLE TO PROCESS THESE PAGES.');
                        echo json_encode([
                            'status' => 'error',
                            'message' => $reason . ' PAGES ' . $chunk['first'] . '-' . $chunk['last'] . ' WERE NOT EXTRACTED. YOU CAN TRY CONTINUE EXTRACTION AGAIN.',
                        ]);
                        exit;
                    }

                    $fallbackYear = derive_fallback_year($upload['school_year']);
                    $rows = array_map(function (array $row) use ($fallbackYear) {
                        $row['graduation_year'] = $row['graduation_date'] !== null
                            ? (int) date('Y', strtotime($row['graduation_date']))
                            : $fallbackYear;
                        return $row;
                    }, $result['rows']);

                    append_extraction_draft_rows($pdo, $uploadId, (int) current_user()['id'], $rows);
                    update_upload_chunk_progress($pdo, $uploadId, $totalPages, $chunk['last']);
                    clear_extraction_progress($uploadId);

                    $hasMoreChunks = $chunk['last'] < $totalPages;
                    echo json_encode([
                        'status' => 'success',
                        'extraction_status' => 'draft',
                        'redirect_url' => $hasMoreChunks
                            ? APP_BASE_URL . '/admin/view_upload.php?id=' . $uploadId
                            : APP_BASE_URL . '/admin/review.php?upload_id=' . $uploadId,
                    ]);
                    exit;
                }

                // Whole document fit in one chunk: behaves exactly as before
                // chunking existed, including the existing text/OCR fallback
                // chain.
                if ($result['status'] === 'failed') {
                    // GenericExtractor is a fast, free text-layer read, not
                    // OCR — try it before asking the admin anything.
                    write_extraction_progress($uploadId, 40, 'READING DOCUMENT TEXT...');
                    $result = (new GenericExtractor($extraCourseKeywords))->extract($upload['file_path']);
                }

                if ($result['status'] === 'failed') {
                    // AI failed and this PDF has no text layer either — actual
                    // (Tesseract) OCR is the only remaining option, and it's
                    // slower and less accurate than AI/text-layer extraction.
                    // Ask before running it instead of silently falling back.
                    $reason = $aiQuotaExhausted
                        ? "TODAY'S FREE AI EXTRACTION QUOTA HAS BEEN USED UP."
                        : ($aiUnreachable
                            ? 'THE AI EXTRACTION SERVICE COULD NOT BE REACHED.'
                            : 'AI EXTRACTION WAS UNABLE TO PROCESS THIS DOCUMENT.');

                    clear_extraction_progress($uploadId);
                    echo json_encode([
                        'status' => 'ocr_confirm_required',
                        'message' => $reason . ' THIS DOCUMENT HAS NO SELECTABLE TEXT, SO OPTICAL CHARACTER RECOGNITION (OCR) IS NEEDED TO READ IT INSTEAD. '
                            . 'OCR RESULTS CAN BE LESS ACCURATE AND WILL NEED CAREFUL REVIEW. PROCEED WITH OCR EXTRACTION?',
                    ]);
                    exit;
                }
            }
```

Everything below this `if ($useOcr) { ... } else { ... }` block (the `if ($result['status'] === 'failed') { ... OCR tools missing / real OCR ... }` block, and the final "FINALIZING" success response) stays completely unchanged — it's still reached by: the `useOcr`-direct-text branch, and the whole-document-chunk AI branch (whether it succeeded directly, via the GenericExtractor fallback, or via real OCR).

- [ ] **Step 3: Check the file for syntax errors**

Run: `php -l api/extract_pdf.php`
Expected: `No syntax errors detected in api/extract_pdf.php`

- [ ] **Step 4: Commit**

```bash
git add api/extract_pdf.php
git commit -m "feat: chunk AI extraction into page ranges with resumable progress"
```

---

### Task 7: Guard `admin/review.php` against opening an incomplete extraction

**Files:**
- Modify: `admin/review.php`

**Interfaces:**
- Consumes: `upload_needs_more_extraction(array $upload): bool` (Task 5), `flash_set()` and `redirect()` (already used elsewhere in this file).

No new automated test — `admin/review.php` has no direct test coverage today (same convention as `api/extract_pdf.php`), and the underlying `upload_needs_more_extraction()` logic is already pinned by Task 5's tests. Verified live in Task 9.

- [ ] **Step 1: Add the guard**

In `admin/review.php`, right after the existing `$upload === null` check:

```php
$uploadId = (int) ($_GET['upload_id'] ?? 0);
$pdo = get_pdo();
$upload = $uploadId > 0 ? get_upload_by_id($pdo, $uploadId) : null;

if ($upload === null) {
    flash_set('error', 'UPLOAD RECORD NOT FOUND.');
    redirect(APP_BASE_URL . '/admin/upload.php');
}
```

add a new block immediately after it:

```php
if ($upload === null) {
    flash_set('error', 'UPLOAD RECORD NOT FOUND.');
    redirect(APP_BASE_URL . '/admin/upload.php');
}

if (upload_needs_more_extraction($upload)) {
    flash_set('error', 'FINISH EXTRACTING ALL PAGES BEFORE REVIEWING THIS UPLOAD.');
    redirect(APP_BASE_URL . '/admin/view_upload.php?id=' . $uploadId);
}
```

(`includes/uploads.php`, which defines `upload_needs_more_extraction()`, is already required at the top of `admin/review.php`.)

- [ ] **Step 2: Check the file for syntax errors**

Run: `php -l admin/review.php`
Expected: `No syntax errors detected in admin/review.php`

- [ ] **Step 3: Commit**

```bash
git add admin/review.php
git commit -m "feat: block opening review for an upload still mid-chunked-extraction"
```

---

### Task 8: "Continue Extraction" UI on `admin/view_upload.php`

**Files:**
- Modify: `admin/view_upload.php`
- Modify: `assets/js/view_upload.js`

**Interfaces:**
- Consumes: `upload_needs_more_extraction(array $upload): bool` (Task 5), `$upload['total_pages']`/`$upload['extracted_through_page']` (now present via Task 5's `get_upload_by_id()` change).
- Produces: a `#continue-extraction-btn` element and its click handler, posting `continue=1` to `api/extract_pdf.php`.

No new automated test — this is markup/JS wiring on a page with no existing test coverage (same convention as `view_upload.js` itself today). Verified live in Task 9.

- [ ] **Step 1: Compute the gating flag**

In `admin/view_upload.php`, inside the existing `if ($upload !== null) { ... }` block (right after `$isTemplateUpload = ...;`), add:

```php
    $isTemplateUpload = $fileExtension === 'XLSX';
    $needsMoreExtraction = upload_needs_more_extraction($upload);
```

- [ ] **Step 2: Add the progress summary to the RECORDS field**

Replace the existing RECORDS field body:

```php
                                <p class="text-sm text-gray-900 dark:text-white">
                                    <?php if ($status === 'draft'): ?>
                                        <?= (int) $draftRecordCount ?> IN DRAFT
                                    <?php else: ?>
                                        <?= (int) $upload['records_extracted'] ?> EXTRACTED
                                    <?php endif; ?>
                                </p>
```

with:

```php
                                <p class="text-sm text-gray-900 dark:text-white">
                                    <?php if ($status === 'draft'): ?>
                                        <?= (int) $draftRecordCount ?> IN DRAFT
                                        <?php if ($needsMoreExtraction): ?>
                                            <span class="block text-xs text-gray-500 dark:text-gray-500 mt-0.5">PAGES <?= (int) $upload['extracted_through_page'] ?> OF <?= (int) $upload['total_pages'] ?> EXTRACTED</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?= (int) $upload['records_extracted'] ?> EXTRACTED
                                    <?php endif; ?>
                                </p>
```

- [ ] **Step 3: Add the "Continue Extraction" button to the primary action row**

Replace the existing primary action row:

```php
                    <div class="flex flex-wrap items-center gap-3">
                        <?php if ($canManageExtraction): ?>
                            <?php if ($status === 'pending'): ?>
                                <button type="button" id="extract-records-btn" data-upload-id="<?= (int) $upload['id'] ?>"
                                    class="inline-flex items-center gap-1.5 rounded-xl bg-csc-navy-600 hover:bg-csc-navy-500 text-white text-sm font-semibold px-4 py-2.5 transition-colors">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>
                                    EXTRACT RECORDS
                                </button>
                            <?php else: ?>
                                <a href="<?= e(APP_BASE_URL) ?>/admin/review.php?upload_id=<?= (int) $upload['id'] ?>"
                                    class="inline-flex items-center gap-1.5 rounded-xl bg-csc-navy-600 hover:bg-csc-navy-500 text-white text-sm font-semibold px-4 py-2.5 transition-colors">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>
                                    <?= $status === 'draft' ? 'RESUME DRAFT REVIEW' : 'CONTINUE REVIEW' ?>
                                </a>
                                <button type="button" id="extract-records-btn" data-upload-id="<?= (int) $upload['id'] ?>" data-should-confirm="true"
                                    class="inline-flex items-center gap-1.5 rounded-xl border border-csc-navy-500/40 text-csc-navy-600 dark:text-csc-navy-300 hover:bg-csc-navy-500/10 text-sm font-semibold px-4 py-2.5 transition-colors">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>
                                    RE-EXTRACT RECORDS
                                </button>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
```

with:

```php
                    <div class="flex flex-wrap items-center gap-3">
                        <?php if ($canManageExtraction): ?>
                            <?php if ($status === 'pending'): ?>
                                <button type="button" id="extract-records-btn" data-upload-id="<?= (int) $upload['id'] ?>"
                                    class="inline-flex items-center gap-1.5 rounded-xl bg-csc-navy-600 hover:bg-csc-navy-500 text-white text-sm font-semibold px-4 py-2.5 transition-colors">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>
                                    EXTRACT RECORDS
                                </button>
                            <?php elseif ($needsMoreExtraction): ?>
                                <button type="button" id="continue-extraction-btn" data-upload-id="<?= (int) $upload['id'] ?>"
                                    class="inline-flex items-center gap-1.5 rounded-xl bg-csc-navy-600 hover:bg-csc-navy-500 text-white text-sm font-semibold px-4 py-2.5 transition-colors">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>
                                    CONTINUE EXTRACTION
                                </button>
                                <button type="button" id="extract-records-btn" data-upload-id="<?= (int) $upload['id'] ?>" data-should-confirm="true"
                                    class="inline-flex items-center gap-1.5 rounded-xl border border-csc-navy-500/40 text-csc-navy-600 dark:text-csc-navy-300 hover:bg-csc-navy-500/10 text-sm font-semibold px-4 py-2.5 transition-colors">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>
                                    RE-EXTRACT RECORDS
                                </button>
                            <?php else: ?>
                                <a href="<?= e(APP_BASE_URL) ?>/admin/review.php?upload_id=<?= (int) $upload['id'] ?>"
                                    class="inline-flex items-center gap-1.5 rounded-xl bg-csc-navy-600 hover:bg-csc-navy-500 text-white text-sm font-semibold px-4 py-2.5 transition-colors">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>
                                    <?= $status === 'draft' ? 'RESUME DRAFT REVIEW' : 'CONTINUE REVIEW' ?>
                                </a>
                                <button type="button" id="extract-records-btn" data-upload-id="<?= (int) $upload['id'] ?>" data-should-confirm="true"
                                    class="inline-flex items-center gap-1.5 rounded-xl border border-csc-navy-500/40 text-csc-navy-600 dark:text-csc-navy-300 hover:bg-csc-navy-500/10 text-sm font-semibold px-4 py-2.5 transition-colors">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>
                                    RE-EXTRACT RECORDS
                                </button>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
```

(The `EDIT RECORDS` quick-action link stays gated on `$status === 'reviewed'`, and `RE-EXTRACT RECORDS`'s quick-actions-card entry stays gated on `$status !== 'pending'` — both already correctly exclude/include the new mid-chunk state without any change, since `$needsMoreExtraction` implies `$status === 'draft'`, not `'reviewed'`, and is not `'pending'`.)

- [ ] **Step 4: Wire the button in JS**

In `assets/js/view_upload.js`, change the `extract()` function's signature and body (currently starting at line 79) from:

```javascript
    function extract(useOcr) {
        setBusy('EXTRACTING...');
        var extractionModal = showExtractionProgressModal('EXTRACTING DATA', 'PLEASE WAIT WHILE WE GATHER THE LATEST INFORMATION.');
        var stopProgressPolling = startExtractionProgressPolling(config.apiBase + '/api/extraction_progress.php', btn.dataset.uploadId, extractionModal.update);

        var formData = new FormData();
        formData.set('csrf_token', config.csrfToken);
        formData.set('upload_id', btn.dataset.uploadId);
        if (useOcr) {
            formData.set('use_ocr', '1');
        }
```

to:

```javascript
    function extract(useOcr, isContinuing) {
        var uploadId = isContinuing ? continueBtn.dataset.uploadId : btn.dataset.uploadId;
        setBusy('EXTRACTING...');
        var extractionModal = showExtractionProgressModal('EXTRACTING DATA', 'PLEASE WAIT WHILE WE GATHER THE LATEST INFORMATION.');
        var stopProgressPolling = startExtractionProgressPolling(config.apiBase + '/api/extraction_progress.php', uploadId, extractionModal.update);

        var formData = new FormData();
        formData.set('csrf_token', config.csrfToken);
        formData.set('upload_id', uploadId);
        if (useOcr) {
            formData.set('use_ocr', '1');
        }
        if (isContinuing) {
            formData.set('continue', '1');
        }
```

Then declare `continueBtn` alongside the existing `btn`/`quickReextractBtn` declarations (currently lines 58–62):

```javascript
    var btn = document.getElementById('extract-records-btn');
    var quickReextractBtn = document.getElementById('quick-reextract-btn');
    if (!config || !btn) {
        return;
    }
```

to:

```javascript
    var btn = document.getElementById('extract-records-btn');
    var quickReextractBtn = document.getElementById('quick-reextract-btn');
    var continueBtn = document.getElementById('continue-extraction-btn');
    if (!config || (!btn && !continueBtn)) {
        return;
    }
```

`setBusy()`/`clearBusy()` currently read/write `btn.disabled`/`btn.textContent` unconditionally — on the page state where `continueBtn` exists, `btn` (the `RE-EXTRACT RECORDS` button in that state) also exists alongside it, so this is safe without further changes.

Finally, wire the click handler at the bottom of the file (after the existing `quickReextractBtn` block, currently ending at line 180):

```javascript
    if (quickReextractBtn) {
        quickReextractBtn.addEventListener('click', function () {
            handleExtractClick(quickReextractBtn.dataset.shouldConfirm);
        });
    }

    if (continueBtn) {
        continueBtn.addEventListener('click', function () {
            extract(false, true);
        });
    }
});
```

- [ ] **Step 5: Check both files for syntax errors**

Run: `php -l admin/view_upload.php`
Expected: `No syntax errors detected in admin/view_upload.php`

Run: `node --check assets/js/view_upload.js` (or open it in an editor and confirm no syntax errors — this project has no JS linter configured)
Expected: no errors.

- [ ] **Step 6: Rebuild CSS**

The new button reuses existing Tailwind classes already present elsewhere on this page (`bg-csc-navy-600`, etc.) — no new utility class strings were introduced, so `npm run build:css` is not required for this task. Skip.

- [ ] **Step 7: Commit**

```bash
git add admin/view_upload.php assets/js/view_upload.js
git commit -m "feat: add Continue Extraction button and progress summary to view_upload.php"
```

---

### Task 9: Full test suite, live verification, and final review

**Files:** none (verification only)

- [ ] **Step 1: Back up the Gemini quota cache file**

```bash
cp storage/cache/gemini_quota_status.json storage/cache/gemini_quota_status.json.bak 2>/dev/null || true
```

- [ ] **Step 2: Run the full PHPUnit suite**

Run: `vendor/bin/phpunit`
Expected: all tests pass except the pre-existing, unrelated flake `testIsQuotaExhaustedIsFalseWhenNoStatusFileExists` (known from prior sessions on this project — report it as pre-existing if it appears, not a regression from this work).

- [ ] **Step 3: Restore the Gemini quota cache file**

```bash
cp storage/cache/gemini_quota_status.json.bak storage/cache/gemini_quota_status.json 2>/dev/null && rm storage/cache/gemini_quota_status.json.bak || true
```

- [ ] **Step 4: Apply the live-database migration**

If not already done (Task 1, Step 5), run the `ALTER TABLE` from Task 1 against the live `hge_masterlist` database now — later steps in this task need the real columns to exist.

- [ ] **Step 5: Live-verify the single-chunk (small document) path is unchanged**

Using an existing small PDF upload (or a fresh test upload) with few enough pages to fit in one chunk (`<= 15`), click "EXTRACT RECORDS". Confirm: it redirects straight to `review.php` as before, `extraction_status` becomes `extracted` (check via `admin/school_records.php` or a direct query), and `total_pages`/`extracted_through_page` stay `NULL`/`0` in the database (query `SELECT total_pages, extracted_through_page FROM latin_honor_uploads WHERE id = <id>`).

- [ ] **Step 6: Live-verify the multi-chunk path**

Upload (or reuse) a PDF with more than 15 pages. Click "EXTRACT RECORDS". Confirm: the response lands back on `view_upload.php` (not `review.php`), the RECORDS field shows "PAGES 15 OF `<N>` EXTRACTED" (or similar, matching actual page counts), and the "CONTINUE EXTRACTION" button is visible while "RESUME DRAFT REVIEW" is not. Click "CONTINUE EXTRACTION" repeatedly until `extracted_through_page` reaches `total_pages`; confirm the button disappears and "RESUME DRAFT REVIEW" appears in its place, and that `admin/review.php?upload_id=<id>` now opens and shows the accumulated rows from every chunk (not just the last one).

- [ ] **Step 7: Live-verify navigating directly to review.php mid-chunk is blocked**

While a multi-chunk upload still has pages remaining, navigate directly to `admin/review.php?upload_id=<id>` by URL. Confirm it redirects to `view_upload.php` with a flash message, rather than opening the partial review.

- [ ] **Step 8: Live-verify RE-EXTRACT RECORDS resets a mid-chunk upload**

On a multi-chunk upload partway through extraction, click "RE-EXTRACT RECORDS" (confirm the warning modal, since this discards the in-progress draft). Confirm `total_pages`/`extracted_through_page` reset and the old draft's rows are gone (query `extraction_review_drafts` for that `upload_id` — it should either not exist or reflect only the new attempt's first chunk after re-extracting), and that extraction proceeds chunk-by-chunk again from page 1.

- [ ] **Step 9: Clean up any scratch test uploads**

Delete any upload records created purely for this verification (via `admin/school_records.php`'s delete action, which only allows deleting an upload with zero `records_extracted` — reset/delete the draft first if needed) so the live database isn't left with scratch data.

- [ ] **Step 10: Final branch review**

This is the natural point for a fresh-context review of the whole branch's diff against `docs/superpowers/specs/2026-10-06-chunked-ai-extraction-design.md` and this plan — see the chosen execution method's own review step (subagent-driven runs one per task plus a final whole-branch review automatically; native execution should request a review explicitly here, e.g. via `/code-review`).
