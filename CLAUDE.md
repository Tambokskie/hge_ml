# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

HGE Database — Honor Graduate Eligibility Latin Honor Database. A plain-PHP (no framework) web app where schools' PDF lists of Latin Honor graduates are uploaded, OCR/text-extracted into structured records, reviewed/corrected by a human, saved, and later searched to verify whether an applicant holds a Latin Honor for a given school/year. The original PDF is always retrievable as the source of truth.

Design spec and phase plans (useful for "why" context, not just "what"): `docs/superpowers/specs/` and `docs/superpowers/plans/`.

Terminology used consistently in code/UI: **HGE**, **Application** (this system), **PDF** (an uploaded school list), **Latin Honor** (`CUM LAUDE` / `MAGNA CUM LAUDE` / `SUMMA CUM LAUDE`), **Applicant** (a graduate holding a Latin Honor — never the uploader).

## Commands

```
composer install                 # PHP deps (smalot/pdfparser, tesseract_ocr, phpunit)
npm install                      # Tailwind
npm run build:css                # compile assets/css/input.css -> assets/css/app.css (minified)

vendor/bin/phpunit                                    # run full test suite
vendor/bin/phpunit tests/includes/FunctionsTest.php   # single file
vendor/bin/phpunit --filter testMethodName            # single test
```

Tests need a real MySQL/MariaDB server reachable at `127.0.0.1` as `root` with no password (`tests/TestDb.php` creates/tears down a separate `hge_masterlist_test` database from `database/schema.sql` — it does not touch `hge_masterlist`). There is no PHP lint/static-analysis command configured.

The app itself is served by XAMPP/Apache at `http://localhost/hge_masterlist/` — there is no dev server to start.

## Architecture

**No framework, no router, no autoloader for app code.** Every page under `admin/*.php` and every endpoint under `api/*.php` is a standalone script that begins by manually `require_once`-ing exactly the `config/` and `includes/*.php` files it needs (order matters: `config/database.php`, then `includes/functions.php`, then `includes/auth.php`, then feature-specific includes). Only `vendor/` (Composer deps and the `Tests\` namespace) is autoloaded. When adding a new admin page or API endpoint, follow the require pattern of a sibling file rather than inventing a bootstrap file.

**`includes/*.php` are function libraries, not classes** (except the extractors). Each file groups plain functions around one concern: `auth.php` (session, CSRF, login, role checks), `functions.php` (view helpers: `e()`/`e_upper()` escaping, `asset_url()` cache-busting via `filemtime`, pagination, flash messages, dashboard stats), `uploads.php`, `schools.php`, `graduates.php`, `users.php`, `activity.php`, `extraction_review.php`, `pdf_files.php`. All DB access takes an explicit `PDO $pdo` parameter — nothing reaches for a global connection inside these functions (call `get_pdo()` once in the page/endpoint and pass it down).

**Auth/session model**: `require_login()` / `require_role('administrator')` (see `includes/auth.php`) gate every admin page and API endpoint. Roles are `administrator` and `user`; `user_has_role()` treats `administrator` as satisfying any role check. Every state-changing API endpoint verifies `csrf_verify($_POST['csrf_token'])` before doing anything else — copy this check when adding a new endpoint. `attempt_login()` deliberately runs `password_verify()` against a fixed dummy bcrypt hash when the user doesn't exist or is disabled, to avoid a timing side-channel; don't "simplify" that away. For especially sensitive actions (e.g. `api/backup_database.php`), a valid session + CSRF token isn't enough: the endpoint re-collects username/password from the request body and re-runs `attempt_login()` fresh ("step-up" re-authentication) before proceeding — follow that pattern for any new endpoint that exports/destroys data at that level of sensitivity. Idle-session timeout is enforced server-side in `require_login()` against `SESSION_IDLE_TIMEOUT` (`config/database.php`, default 14400s/4h, `getenv()`-overridable) and mirrored client-side: `includes/footer.php` emits the same timeout value to `assets/js/idle_timeout.js`, which pings `api/keep_alive.php` on user activity to reset `$_SESSION['last_activity']` before it expires, and otherwise redirects to `login.php?timeout=1`.

**Extraction pipeline** (the core domain logic):
1. `admin/upload.php` → `api/upload_pdf.php` → `handle_upload_request()` in `includes/uploads.php`: validates the PDF, finds/creates the school, detects duplicate school+school_year uploads (requires `confirm_duplicate` to overwrite), builds a collision-safe stored filename, copies the file into `storage/pdfs/` (denied to direct web access via its `.htaccess`), and inserts a `latin_honor_uploads` row.
2. `api/extract_pdf.php` runs `GenericExtractor` (text-layer via `smalot/pdfparser`) first; if it yields zero rows, it falls back to `OcrExtractor` (Ghostscript rasterizes pages to PNG, then `thiagoalessio/tesseract_ocr` OCRs each page — paths configurable via `GHOSTSCRIPT_PATH`/`TESSERACT_PATH` env vars, defaulting to hardcoded Windows install paths). Both extractors implement `ExtractorInterface::extract()` and share row-parsing logic via the `ParsesHonorRows` trait.
3. `ParsesHonorRows::parseRows()` is line-oriented text parsing (no ML/NLP): it locates the honor phrase (`CUM LAUDE` family) on a line, then works backwards/forwards from that position to pull name, course, GWA, and date, handling both name-first and "course-first" (anchored on `BACHELOR`) layouts. A line with no honor phrase is tried as a **ditto row** (`parseDittoLine()`) — many Philippine school lists repeat `"- do -"` in the Honor Received/Date columns instead of retyping the same value; since real OCR renders that mark wildly inconsistently ("do", "odo", or unrecognizable noise), it's detected by anchoring on the course keyword instead of the ditto text itself, then `resolveDittoMarks()` backfills honor/date from the nearest earlier row in a second pass over the whole document. Extracted rows are staged into `$_SESSION['extraction_review']` (see `includes/extraction_review.php`), never written to `latin_honor_graduates` directly.
4. `admin/review.php` lets a human edit/remove staged rows (`set_staged_row()`, `remove_staged_row()`); `api/save_records.php` → `save_staged_records()` validates every row (`validate_staged_row()`) and inserts them all in one transaction, only then flipping `latin_honor_uploads.extraction_status` to `reviewed`.

When changing parsing behavior, extend `ParsesHonorRows` (shared by both extractors) rather than duplicating logic in one extractor, and check `tests/extractors/GenericExtractorTest.php` / `OcrExtractorTest.php` plus the PDF fixtures in `tests/fixtures/` (including `course-first-sample.pdf`, `course-first-scanned.pdf`, and `ditto-sample-ocr-output.txt` — verbatim real Tesseract output of a ditto-mark honor list, used as-is rather than re-OCR'd on every test run) for the layouts already covered.

**Data model** (`database/schema.sql`): `users` → `schools` → `latin_honor_uploads` (one PDF, unique per `school_id`+`school_year`+`upload_number`, tracks `extraction_status`: `pending`→`extracted`/`failed`→`reviewed`) → `latin_honor_graduates` (one row per applicant, FULLTEXT-indexed on `graduate_name`) → `graduate_applications` (records that a searched applicant's Latin Honor was actually filed, via `api/file_application.php`/`includes/graduates.php:file_application()`, one `application_date` per graduate). `activity_log` records auditable actions (`login`, `pdf_upload`, `pdf_delete`, `record_created/updated/removed`, `search`, `user_created/updated/removed`, `school_created/updated/removed`, `application_filed`, `db_backup`) and self-prunes rows older than 6 months on every write (`log_activity()`).

**Frontend**: server-rendered PHP views (all-caps UI copy throughout — labels, buttons, error messages) styled with Tailwind compiled to a single static `assets/css/app.css` (rebuild with `npm run build:css` after editing `assets/css/input.css` or changing Tailwind classes in PHP — content scanning covers `./**/*.php` and `./assets/js/**/*.js`). Tailwind's `dark:` variant is used throughout for a dark theme, toggled client-side by `assets/js/theme.js` (flips a `dark` class on `<html>`, persisted to `localStorage['hge_theme']` — no server round-trip). Per-page vanilla JS in `assets/js/` (one file per admin page, e.g. `upload.js`, `review.js`, `lookup.js`) talks to the `api/*.php` endpoints via `fetch`, posting `csrf_token` from the page. `includes/header.php`/`sidebar.php`/`footer.php` are shared layout partials included by every `admin/*.php` page; `asset_url()` appends `?v=<filemtime>` so edits bust the browser cache without a build step.

**Config via environment**: `config/database.php` reads `DB_HOST`/`DB_NAME`/`DB_USER`/`DB_PASS`/`APP_BASE_URL` via `getenv()`, defaulting to XAMPP-local values (`127.0.0.1` / `hge_masterlist` / `root` / empty / `/hge_masterlist`). OCR tool paths follow the same `getenv()`-with-Windows-default pattern in `extractors/ocr_extractor.php` and `includes/ocr_tools.php` (`GHOSTSCRIPT_PATH`/`TESSERACT_PATH`); `api/backup_database.php` reads `MYSQLDUMP_PATH` the same way, defaulting to the XAMPP-bundled `mysqldump.exe`.

**OCR tool bootstrap**: `includes/ocr_tools.php` can check (`check_ocr_tools_installed()`) and silently install Ghostscript/Tesseract when missing, driven from the dashboard via `api/install_ocr_tools.php`. The Ghostscript installer is downloaded from a pinned GitHub release URL and its SHA-256 is verified against a hardcoded constant before running `/S` (silent install); Tesseract is installed via `winget`. Don't change the pinned installer URL/hash without also updating the other.
