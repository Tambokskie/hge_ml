# Resumable, Page-Chunked AI Extraction — Design

## Problem

Some school PDFs fail to extract fully via the AI (Gemini) path today. The
exact failure mode hasn't been pinned down (timeout vs. truncated AI output
vs. a size/payload issue), but all three plausible causes share one fix:
sending Gemini a smaller slice of the document per call instead of the
whole PDF in one shot.

Today, `GeminiExtractor::extract()` sends the entire PDF as one inline-data
attachment in one API call, inside one PHP request bounded by a 120s
execution-time limit (`api/extract_pdf.php`). There is no way to extract
part of a document and continue later — extraction is all-or-nothing per
request.

## Goals

- Break AI extraction of a PDF into page-range chunks, each sent as its own
  Gemini call.
- Let an admin manually continue extraction via a "Continue Extraction"
  button — no automatic looping across chunks.
- Make it clear which pages have been successfully extracted so far.
- Keep this invisible for documents that already work today: a document
  short enough to fit in one chunk behaves exactly as it does now, in one
  click.

## Non-goals

- Chunking `GenericExtractor` (text-layer) or `OcrExtractor` (OCR). Both are
  out of scope — `GenericExtractor` is fast and rarely the bottleneck,
  `OcrExtractor` is a separate problem if it ever needs the same treatment.
  This design touches only the AI (Gemini) path.
- Falling a failed chunk back to text/OCR extraction automatically. A failed
  chunk is simply marked not-yet-extracted; the admin retries it later via
  "Continue Extraction", or abandons chunked progress entirely via the
  existing "RE-EXTRACT RECORDS" (whole-document restart).
- Auto-advancing through all chunks without admin interaction. Each chunk
  requires an explicit click.
- A per-page/per-chunk status checklist in the UI. A single progress summary
  line is enough.
- Admin-configurable chunk size. `CHUNK_SIZE` is a hardcoded constant (see
  Open Questions).

## Why chunking fixes all three plausible failure modes

- **Timeout**: a smaller per-call PDF means a faster Gemini response, far
  less likely to approach the 120s execution-time ceiling.
- **Truncated output**: fewer pages means fewer rows for the model to
  generate in one response, far less likely to hit an output-length limit
  mid-array.
- **Payload/size rejection**: a smaller `inline_data` attachment is less
  likely to trip any size-based rejection on Gemini's side.

Because the exact cause isn't diagnosed, the design treats all three as
equally plausible and fixes all three by construction, rather than picking
one to specifically target.

## Core mechanism

AI extraction is **always** processed in page chunks — there is no separate
"whole document" code path to keep in sync. A document with `total_pages <=
CHUNK_SIZE` simply finishes in one chunk, i.e. one click, identical to
today's behavior and today's UX. Only a document that needs more than one
chunk ever surfaces a "Continue Extraction" button.

"RE-EXTRACT RECORDS" (the existing full-restart action) folds into the same
mechanism: it resets chunk progress to page 1 and clears the existing draft,
then proceeds exactly like a fresh upload — chunk by chunk, needing further
"Continue Extraction" clicks for anything beyond the first chunk.

### Chunk lifecycle

1. Admin triggers extraction (first time, or "Continue Extraction", or
   "RE-EXTRACT RECORDS").
2. If this is the first attempt for this upload (or a reset), count the
   PDF's total pages via Ghostscript and store it.
3. Compute the next unextracted page range:
   `[extracted_through_page + 1, min(extracted_through_page + CHUNK_SIZE,
   total_pages)]`.
4. Slice that range into a standalone temp PDF via Ghostscript
   (`-sDEVICE=pdfwrite -dFirstPage=.. -dLastPage=..`).
5. Pass the temp PDF's path to the **existing, unmodified**
   `GeminiExtractor::extract()` — it already just takes a file path, so it
   needs no changes to support chunking. Existing per-key retry/rotation
   logic (`callGeminiApi()`) applies unchanged, now scoped to one chunk's
   call instead of the whole document.
6. On success: append the chunk's rows into the upload's durable draft
   (`extraction_review_drafts`), advance `extracted_through_page` to the
   chunk's last page, set `extraction_status = 'draft'`.
7. On failure: `extracted_through_page` is left untouched. The next
   "Continue Extraction" click retries the same range.
8. Delete the temp sliced PDF after the attempt, success or failure.

## Data model

Two new columns on `latin_honor_uploads`:

```sql
ALTER TABLE latin_honor_uploads
  ADD COLUMN total_pages INT UNSIGNED NULL AFTER records_extracted,
  ADD COLUMN extracted_through_page INT UNSIGNED NOT NULL DEFAULT 0 AFTER total_pages;
```

- `total_pages` stays `NULL` until the first chunk attempt. A `NULL` value
  means "not a chunked-extraction upload" — critically, this also covers
  every upload that existed before this feature shipped (template uploads,
  already-`reviewed`/`extracted`/`failed` uploads from before today). The UI
  must never show "Continue Extraction" based on `extracted_through_page <
  total_pages` alone — it must first check `total_pages !== null`.
- `extracted_through_page` defaults to `0`, safe for every existing row.
- No new `extraction_status` enum value. The existing `draft` status is
  reused: after the first successful chunk, this upload has durably-saved
  rows in `extraction_review_drafts` that aren't yet finalized — exactly
  what `draft` already means today for a manually-saved in-progress review.
  This reuse isn't just convenient, it's necessary: a multi-click (possibly
  multi-session) flow can't rely on `$_SESSION`-only staging the way
  today's single-shot "extracted" status implicitly does, so every
  successful chunk's rows are written straight to the durable draft table.

This project has no migration framework (`database/schema.sql` is the
canonical fresh-install schema; no `migrations/` directory, no prior
`ALTER TABLE` anywhere in the repo). Implementation must update
`schema.sql` **and** run the `ALTER TABLE` above by hand against the live
`hge_masterlist` database — a one-time manual step, consistent with how
this project has handled every prior schema change.

### Status/action gating (admin/view_upload.php)

While `total_pages !== null && extracted_through_page < total_pages`:
- Show the progress summary and "Continue Extraction" button.
- **Hide "Resume Draft Review"** — the admin should not review a partial
  set. (Corrected during design review: an earlier draft of this design had
  both actions available side by side; the approved behavior blocks review
  until extraction is fully complete.)

Once `extracted_through_page >= total_pages` (or `total_pages` is `null`,
i.e. a pre-existing/unchunked draft): behave exactly as today — "Resume
Draft Review" only.

`admin/review.php` enforces this server-side too, not just via a hidden
button: if `total_pages !== null && extracted_through_page < total_pages`,
redirect back to `view_upload.php` with a flash message telling the admin
to finish extraction first.

## Components

- **`includes/pdf_pages.php`** (new) — Ghostscript-backed helpers, using
  the already-required `resolve_ghostscript_path()` from
  `includes/ocr_tools.php`:
  - `count_pdf_pages(string $filePath): ?int` — via Ghostscript's
    `pdfpagecount` PostScript operator (requires
    `--permit-file-read=<path>` under Ghostscript's default `-dSAFER`
    sandboxing — confirmed working in a feasibility spike against a real
    fixture during design).
  - `extract_pdf_page_range(string $filePath, int $first, int $last):
    ?string` — via `-sDEVICE=pdfwrite -dFirstPage= -dLastPage=`, writes to
    a temp file and returns its path; caller is responsible for deleting
    it. Also confirmed working in the same spike (no extra permission
    flags needed — same pattern `OcrExtractor` already uses for
    rasterization).
- **`includes/extraction_review.php`** — add:
  - `next_chunk_range(int $extractedThroughPage, int $totalPages, int
    $chunkSize): ?array{first: int, last: int}` — pure function, returns
    `null` when nothing remains. Directly unit-testable with no I/O.
  - `append_extraction_draft_rows(PDO $pdo, int $uploadId, int $savedBy,
    array $newRows): array` — reads the existing draft via
    `get_extraction_draft()`, merges in `$newRows`, calls the existing
    `save_extraction_draft()` unchanged.
- **`includes/uploads.php`** — a way to persist `total_pages` /
  `extracted_through_page` alongside the existing
  `update_upload_extraction_status()`, and a reset path for "RE-EXTRACT
  RECORDS" (zero `extracted_through_page`, clear the draft, re-count
  `total_pages` — handles a PDF that was replaced/re-uploaded since the
  last attempt).
- **`api/extract_pdf.php`** — AI branch only. A new request flag (e.g.
  `continue=1`) distinguishes "process the next chunk" from a fresh start;
  "RE-EXTRACT RECORDS" resets the cursor first, then proceeds through the
  same chunk-processing code. The existing per-chunk retry logic
  (quota/503/transport handling in `GeminiExtractor`) is unchanged; what
  changes is that it now runs against a sliced temp PDF instead of the
  whole file, and a failure response is chunk-scoped (not the
  document-wide `ocr_confirm_required` prompt, which doesn't fit a single
  failed chunk — see Non-goals).
- **`admin/view_upload.php`** + its JS — a progress summary line (e.g.
  "PAGES 1–45 OF 120 EXTRACTED · 311 RECORDS SO FAR"), a "Continue
  Extraction" button reusing the existing extraction-progress-polling
  UI/modal unchanged (just targeting the next chunk instead of the whole
  document), and the gating described above.
- **`database/schema.sql`** — the two new columns, plus the live-DB
  `ALTER TABLE` noted above.

## Error handling

- **Chunk failure** (AI call fails for the current range after existing
  retry/key-rotation logic exhausts itself): `extracted_through_page` stays
  put; respond with a chunk-scoped failure message distinct from the
  existing whole-document `ocr_confirm_required` prompt.
- **Quota exhausted mid-chunking**: identical to today's quota handling,
  scoped to one chunk. The admin sees the existing quota-aware messaging
  and retries "Continue Extraction" once quota resets.
- **RE-EXTRACT RECORDS**: resets `extracted_through_page` to `0`, clears
  the existing draft, re-counts `total_pages`, then behaves exactly like a
  fresh chunked extraction from page 1.
- **Direct navigation to `admin/review.php`** while extraction is
  incomplete: redirected back to `view_upload.php` with a flash message
  (server-side enforcement, not just a hidden button).
- **Ghostscript page-count/slice failure** (corrupt PDF, Ghostscript
  error): treated as a hard extraction failure for that attempt, same
  shape as today's catch-all "THE FILE COULD NOT BE PROCESSED" — no
  partial/corrupt chunk is ever appended to the draft.
- **Template (`.xlsx`) uploads**: entirely unaffected — chunking applies
  only to the PDF/AI branch.

## Testing

- `next_chunk_range()` — pure function, direct unit tests covering: first
  chunk, a middle chunk, the final partial chunk, and the "nothing left"
  case.
- Ghostscript helpers (`count_pdf_pages()`, `extract_pdf_page_range()`) —
  direct unit tests against a real small PDF fixture, following the
  existing `GHOSTSCRIPT_PATH`-aware pattern already used by
  `OcrExtractorTest.php`.
- `append_extraction_draft_rows()` — `TestDb`-backed test alongside the
  existing draft-related tests in `includes/extraction_review.php`'s test
  coverage.
- `api/extract_pdf.php` itself stays without direct test coverage,
  consistent with today (it has none now either — it's thin orchestration
  glue over tested helpers). Verified live/manually, as established
  practice in this project.

## Open questions for implementation

- **`CHUNK_SIZE` default**: proposed as **15 pages** per chunk — a
  deliberately round, conservative number balancing per-call speed/output
  size against the ~20-requests/key/day Gemini free-tier quota (a 300-page
  document would take ~20 "Continue Extraction" clicks at this size). This
  number has not been validated against a real failing document, since the
  failure mode itself isn't yet diagnosed. It should be easy to tune as a
  single constant if 15 turns out too small/large in practice, without
  further design changes.
