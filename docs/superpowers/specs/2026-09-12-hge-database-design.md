# HGE Database — Honor Graduate Eligibility Latin Honor Database

Design spec. Status: approved by user on 2026-09-12, pending final spec review sign-off.

## 1. Purpose

A centralized web application where schools' uploaded PDF lists of Latin Honor
graduates are stored, parsed into structured records, reviewed/corrected by a
human before being committed, and later searched to verify whether a given
applicant appears in the Latin Honor record for a school/year — with the
original PDF always retrievable as the source of truth.

Terminology used consistently throughout the app: **HGE** (Honor Graduate
Eligibility), **Application** (this system), **PDF** (an uploaded school list),
**Latin Honor** (CUM LAUDE / MAGNA CUM LAUDE / SUMMA CUM LAUDE), **Applicant**
(a graduate holding a Latin Honor, applying for HGE — never the uploader).

## 2. Delivery phases

Each phase is implemented, reviewed, and confirmed working before the next
starts. This spec covers the full system; each phase gets its own
implementation plan via the writing-plans skill.

1. **Foundation** — DB schema (all tables), config, session-based auth with
   roles, dashboard shell wired to real (possibly zero) counts.
2. **Schools + Upload/Storage** — school CRUD, PDF upload with drag-and-drop,
   validation, safe storage, duplicate detection.
3. **Extraction + Review** — smalot/pdfparser integration behind an extractor
   interface, generic extractor, review/edit/remove staging table, confirm-to-save.
4. **Look-up + Verification** — search/filter by school/year/name, results
   table, found/not-found verification views, source-PDF viewing, graduate
   detail modal.
5. **Admin + Hardening** — user management, activity log, CSRF/session
   hardening pass, full end-to-end workflow test (section 46 of the original
   request).

## 3. Environment (confirmed in this session)

- PHP 8.2.12 (XAMPP), extensions available: PDO, pdo_mysql, mbstring, zip,
  fileinfo. No `gd` — not needed (no image manipulation, PDFs only).
- MariaDB 10.4.32, reachable via `root` with no password (XAMPP default) —
  fine for local dev; production deployment would need a real DB user, out of
  scope for this local build.
- Composer: not installed, but internet access confirmed working — Composer
  will be installed and `smalot/pdfparser` pulled for real.
- Node/npm available — used once, at build time, to compile Tailwind CSS to
  a static `assets/css/app.css`. No Node needed at request time.
- Project root: `C:\xampp\htdocs\hge_masterlist`, served at
  `http://localhost/hge_masterlist/`.

## 4. Folder structure

```
hge_masterlist/
├── assets/
│   ├── css/            (compiled app.css + Tailwind source input)
│   ├── js/             (upload.js, lookup.js, modals.js, toast.js, ...)
│   └── images/
├── config/
│   └── database.php     (PDO connection, from env-style constants)
├── includes/
│   ├── auth.php         (require_login(), require_role(), csrf helpers)
│   ├── header.php
│   ├── sidebar.php
│   ├── footer.php
│   └── functions.php     (shared helpers: formatting, pagination, flash msgs)
├── admin/
│   ├── dashboard.php
│   ├── lookup.php
│   ├── upload.php
│   ├── review.php        (extraction review/confirm screen)
│   ├── graduates.php     (browse/manage saved graduate records)
│   ├── schools.php
│   └── users.php
├── api/
│   ├── search_graduates.php
│   ├── upload_pdf.php
│   ├── extract_pdf.php
│   ├── save_records.php
│   ├── update_graduate.php
│   ├── delete_graduate.php
│   └── serve_pdf.php     (secure streamed PDF access, no direct file URLs)
├── extractors/
│   ├── ExtractorInterface.php
│   └── generic_extractor.php
├── storage/
│   └── pdfs/              (.htaccess deny-all; only reachable via serve_pdf.php)
├── database/
│   └── schema.sql
├── vendor/                 (composer: smalot/pdfparser)
├── composer.json
├── package.json             (tailwindcss devDependency only)
├── login.php
├── logout.php
└── index.php                (redirects to dashboard or login)
```

## 5. Database schema

Tables (see section 17 of the original request for the baseline; additions
noted below):

- **users**: id, name, username, email, password, role (`administrator`|`user`),
  status (`active`|`disabled`), created_at, updated_at.
- **schools**: id, school_name, status (`active`|`disabled`), created_at, updated_at.
- **latin_honor_uploads**: id, school_id (FK), school_year, original_filename,
  stored_filename, file_path, file_size, file_hash (sha256, for duplicate
  detection), uploaded_by (FK users), **extraction_status** enum
  (`pending`,`extracted`,`reviewed`,`failed`) — *addition*: gives the
  review-before-save workflow a durable state instead of only living in a PHP
  session between steps, records_extracted, uploaded_at, updated_at,
  **upload_number** (*Phase 2 addition*, see section 11) — INT UNSIGNED, 1 for
  a school+year's first upload, incrementing per subsequent "UPLOAD ANYWAY"
  duplicate upload; UNIQUE on (school_id, school_year, upload_number) so
  numbering is race-safe and query-able.
- **latin_honor_graduates**: id, upload_id (FK), school_id (FK), graduate_name,
  latin_honor enum(`CUM LAUDE`,`MAGNA CUM LAUDE`,`SUMMA CUM LAUDE`),
  graduation_date, graduation_year, created_at, updated_at.
- **activity_log** (*addition*, section 40): id, user_id (FK, nullable for
  system events), action (`login`,`logout`,`pdf_upload`,`pdf_delete`,
  `record_created`,`record_updated`,`record_removed`,`search`), details (text),
  created_at.

Indexes: `graduate_name` (FULLTEXT), `school_id`, `graduation_year`,
`latin_honor`, `upload_id` on latin_honor_graduates; `file_hash` on uploads for
duplicate lookups.

Relationship: `schools` 1—* `latin_honor_uploads` 1—* `latin_honor_graduates`.
Every graduate row traces to exactly one upload (and through it, one school +
year + original PDF).

## 6. Auth & security

- Session-based auth. Passwords via `password_hash`/`password_verify`.
  Session ID regenerated on login. `logout.php` destroys the session fully.
- Roles: `administrator` (full CRUD, upload, manage schools/users, view PDFs),
  `user` (search, view graduate details, view PDF if permitted). Every
  protected `admin/*.php` and `api/*.php` file calls `require_role()` at the
  top — authorization is enforced server-side, not just hidden in the UI.
- CSRF token stored in session, required on every state-changing POST/AJAX
  request, verified server-side.
- All queries via PDO prepared statements. All output escaped with
  `htmlspecialchars()` at render time.
- Uploaded PDFs stored under `storage/pdfs/` with a generated safe filename
  (not the original name); the directory is denied direct web access
  (`.htaccess`) and files are only ever served through `api/serve_pdf.php`,
  which checks the requester's role before streaming the file.
- Upload validation: MIME/type sniff (not just extension), max size
  (configurable constant), original filename sanitized and stored separately
  from the generated stored filename.

## 7. PDF extraction workflow

1. Upload PDF → validate → compute file hash → check for duplicate
   (school + year + filename/hash) → warn with VIEW EXISTING / CANCEL /
   UPLOAD ANYWAY if a match is found → store file → insert
   `latin_honor_uploads` row (`extraction_status = pending`).
2. `api/extract_pdf.php` runs `extractors/generic_extractor.php` (via
   `ExtractorInterface`, so a school-specific extractor can be swapped in per
   school later without touching calling code) against the stored PDF.
3. If extraction yields too few usable rows (e.g. scanned/image-based PDF),
   status becomes `failed` and the UI shows "TEXT COULD NOT BE RELIABLY
   EXTRACTED FROM THIS PDF" with a manual-entry path instead of silently
   creating bad records.
4. Otherwise, extracted rows are held (not yet in `latin_honor_graduates`) and
   shown on the review screen: edit inline, remove a row, see a running total.
5. Only on explicit "SAVE RECORDS" are rows inserted into
   `latin_honor_graduates` and `extraction_status` set to `reviewed`,
   `records_extracted` updated.

## 8. Look-up & verification

Server-side filtered, paginated search (`api/search_graduates.php`) by school
(dynamic dropdown from `schools`), graduation year (dynamic dropdown from
distinct years present in `latin_honor_graduates`), and graduate name
(FULLTEXT/LIKE, matches full/partial/first/last name). Results table with a
VIEW action opening a details modal that shows the graduate's record plus its
source upload (filename, upload date) and a "VIEW SOURCE PDF" button hitting
`serve_pdf.php`. No match renders a professional empty-state ("NO HONOR
GRADUATE RECORD FOUND"), never a blank table.

## 9. UI system

Dark dashboard per the reference image: near-black background, slightly
lighter cards, violet/purple accent, rounded corners, subtle borders (no heavy
shadows), fixed sidebar on desktop collapsing to an off-canvas hamburger menu
on mobile. Tailwind compiled to a static CSS file (see Environment). Reusable
partials: sidebar, header, stat card, table shell, modal shell, toast
notification, pagination — built once in `includes/` and `assets/js/`, reused
across pages rather than duplicated per page.

## 10. Explicitly out of scope for this build

- Multi-tenant/production deployment hardening beyond what's listed in
  section 6 (e.g. this uses the local XAMPP MySQL root account — a real
  deployment would need its own DB credentials).
- Chart/analytics beyond the one suggested optional chart (Latin Honors by
  year) — added only if it doesn't crowd the dashboard, per the "do not
  overdesign" directive.
- School-specific extractor implementations beyond the generic one — the
  interface supports adding them later; none are written now since no
  school-specific sample beyond the one PDF provided exists yet.

## 11. Phase 2 addendum — Schools + Upload/Storage (decided 2026-09-13)

Phase 1 is merged. These decisions were made when brainstorming Phase 2 and
refine (not replace) sections 4-10 above.

**School creation — inline, not a dedicated admin page.** The upload form's
SCHOOL NAME field is free text with an HTML `<datalist>` populated
server-side from existing active schools (no separate autocomplete
endpoint needed at this scale). On submit, `find_or_create_school()`
attempts an INSERT; the `schools.school_name` UNIQUE constraint (inherited
`utf8mb4_unicode_ci` collation, case-insensitive) catches near-duplicate
casing automatically — a caught duplicate-key error means "look up and
reuse the existing row" rather than an error. A dedicated Schools admin
page (spec section 29's full CRUD table) is deferred past Phase 2; nothing
in Phase 2 blocks adding it later.

**Filename convention.** `stored_filename` =
`{SCHOOL_NAME_SANITIZED}_{SCHOOL_YEAR}_{UPLOAD_NUMBER}.pdf`, uppercase,
non-alphanumeric characters in the school name replaced with underscores
(e.g. `ABC_UNIVERSITY_2025_1.PDF`). `original_filename` (the uploader's own
filename) is preserved unmodified for display, per section 6. This
supersedes section 30's simpler filename example, which didn't anticipate
multiple uploads per school+year.

**Duplicate flow.** The trigger is `(school_id, school_year)` match only —
not `file_hash` comparison, even though `file_hash` (sha256) is still
computed and stored on every upload per section 5/6, kept for future
integrity/dedup use rather than as this phase's trigger. Upload is AJAX
(`fetch` to `api/upload_pdf.php`, `multipart/form-data`). If an upload
already exists for that `(school_id, school_year)`, the API responds
`{"status":"duplicate", ...}`
instead of erroring, carrying the existing upload's metadata. The frontend
shows a modal: VIEW EXISTING (opens `admin/view_upload.php?id=`), CANCEL
(dismiss), UPLOAD ANYWAY (resubmits with a `confirm_duplicate=1` flag,
`upload_number` increments by 1). `admin/view_upload.php` is new — a single
page showing one upload's metadata + VIEW PDF button; it's the target for
both this modal's VIEW EXISTING and the dashboard's VIEW action, and gets
a "records" section that's a placeholder ("0 records — extraction not yet
run") until Phase 3 populates it.

**New reusable UI components** (spec section 25/26 require these; Phase 1
didn't need them yet): `assets/js/toast.js` (success/error/warning
notifications for the AJAX upload flow) and a modal shell
(`assets/js/modal.js` + Tailwind classes) for the duplicate-warning dialog
— both plain JS, no libraries, matching Phase 1's approach.

**Storage protection.** `storage/pdfs/` gets the same deny-all `.htaccess`
pattern Phase 1 used for `includes/`/`config/`/`database/` (see Phase 1's
final-review fix). Files are reachable only through `api/serve_pdf.php`.

**Dashboard tie-in.** The "LATEST UPLOAD" table (section 5) ships now since
real upload rows will exist: last 5 uploads, VIEW (→ `view_upload.php`) and
VIEW PDF (→ `serve_pdf.php`) actions working. No VIEW RECORDS action yet —
added when Phase 3 makes it meaningful.

**Schema migration approach.** No production data exists yet, so
`database/schema.sql` is edited directly to add `upload_number` (rather
than introducing a formal migrations system), and both the dev
(`hge_masterlist`) and test (`hge_masterlist_test`) databases get dropped
and recreated from the updated file.

**Max upload size:** 20 MB, enforced both by a PHP-side check and (for
defense in depth) documented as a recommended `upload_max_filesize`/
`post_max_size` php.ini floor in the phase's setup notes.

**Out of scope for Phase 2** (unchanged from section 10's spirit): the
Schools admin CRUD page, anything about PDF *content* (extraction is
Phase 3), and any change to `admin/graduates.php`/`admin/users.php`.

## 12. Phase 3 addendum — Extraction + Review (decided 2026-09-13)

Phase 2 is merged. These decisions refine (not replace) section 7's
extraction workflow, validated against two real test PDFs during
brainstorming: the user's actual sample (`List of Honor Graduates_Second
Semester AY 2025-2026.pdf`, from UP Tacloban) and a synthetic PDF built to
the same real-world layout for testing the successful-extraction path.

**Key finding that shaped this design:** the user's real sample turned out
to be a *scanned/image-based* PDF — confirmed via `smalot/pdfparser`
extracting 0 characters, and via raw byte inspection showing `/DCTDecode`
(JPEG) image streams and zero `/Font` objects. This is exactly the
"scanned/image-based PDF" case section 12 of the original request
anticipated, and it validates the failure-detection rule below using real
data. A second, synthetic, text-based PDF (built to the same visual
structure: letterhead, a `Name of Honor Graduates | GWA | Degree | Honors
Received | Date of Graduation` table, signature block) was used to
develop and verify the successful-extraction heuristic.

**Extractor interface.** `extractors/ExtractorInterface.php` declares one
method: `extract(string $filePath): array`, returning
`['status' => 'ok'|'failed', 'rows' => array]`, where each row is
`['graduate_name' => string, 'latin_honor' => string, 'graduation_date' => ?string]`
(`graduation_date` in `Y-m-d` or `null`). `extractors/generic_extractor.php`
implements it.

**Generic extractor algorithm** (validated against both test PDFs):
1. `smalot/pdfparser`'s `Parser::parseFile()->getText()` gets the raw text.
   `getText()` preserves one output line per drawn text line, confirmed by
   direct testing — this is what makes per-line regex matching viable
   rather than needing layout/coordinate analysis.
2. For each line, check for a Latin Honor phrase case- and
   whitespace-insensitively (`/SUMMA\s+CUM\s+LAUDE/i`, then
   `/MAGNA\s+CUM\s+LAUDE/i`, then `/CUM\s+LAUDE/i` — checked in that order
   since "cum laude" is a substring of the other two). A line with no match
   is prose/header/signature and is skipped entirely — this alone correctly
   separates the letterhead, salutation, and closing signature from data
   rows with no separate header-row detection needed, confirmed against
   both test PDFs (9/9 correct rows, 0 false positives on ~15 lines of
   prose in the synthetic test).
3. For a matching line, split on runs of 2+ whitespace characters; the
   first resulting column is the graduate name, trimmed. This exploits the
   fact that PDF-extracted tabular text typically has single spaces within
   a name but wider gaps between columns — confirmed against the synthetic
   sample including names with internal punctuation (`O'Brien-Smith, Jose
   Miguel`).
4. Search the full line for a date in one of several common formats (`02
   July 2026`, `2 Jul 2026`, `July 2, 2026`, `2026-07-02`, `07/02/2026`),
   parse via `strtotime()`, store as `Y-m-d`. No match found → `null`.
5. **Zero matched rows → `status: 'failed'`.** No separate raw-character-count
   threshold is needed: this single rule correctly covers both a genuinely
   scanned/image PDF (confirmed: 0 characters extracted from the real
   sample) and a text-based PDF in an unrecognized format. `failed` status
   surfaces "TEXT COULD NOT BE RELIABLY EXTRACTED FROM THIS PDF" and routes
   to the same manual-entry path (ADD RECORD, below) rather than silently
   creating bad records.

**Missing-date fallback.** When a row has no parseable date,
`graduation_year` defaults to the *last* 4-digit year found in the
upload's own `school_year` field (e.g. `"2025-2026"` → `2026`) — fully
editable by the admin afterward via EDIT. `graduation_date` stays `null`
in that case (the schema already allows this).

**Staging: PHP session, one active review.** Extracted (unconfirmed) rows
live in `$_SESSION['extraction_review'] = ['upload_id' => int, 'rows' =>
array]` — keyed array, each row identified by its array index during the
review — rather than a new database table. This matches how the app
already uses sessions (CSRF tokens, flash messages) and fits the
single-administrator, one-review-at-a-time usage pattern. Rows are only
written to `latin_honor_graduates` on explicit SAVE RECORDS.

**Workflow:**
1. `admin/view_upload.php` (from Phase 2) gains an "EXTRACT RECORDS" button,
   shown when `extraction_status` is `pending` or `failed` (re-extraction is
   allowed any time before `reviewed`, replacing the session staging fresh).
2. `api/extract_pdf.php` runs the extractor, stores rows into
   `$_SESSION['extraction_review']`, sets `extraction_status` to `extracted`
   or `failed` and updates `records_extracted`, then the page navigates to
   `admin/review.php?upload_id=`.
3. `admin/review.php` renders the original request's section 13 table (NO. / GRADUATE NAME
   / LATIN HONOR / DATE OF GRADUATION / YEAR / STATUS / ACTION) from the
   session staging, with a running `TOTAL RECORDS: N` count.
4. **EDIT** opens a modal (GRADUATE NAME, LATIN HONOR as a dropdown of
   exactly the 3 enum values, DATE OF GRADUATION, YEAR GRADUATED) that
   updates one staged row via `api/update_graduate.php`.
5. **REMOVE** shows a confirmation modal ("REMOVE THIS GRADUATE RECORD?"
   with the graduate's name) before deleting one staged row via
   `api/delete_graduate.php`.
6. **ADD RECORD is always available** (not only on extraction failure, per
   the brainstorming decision) — the same modal as EDIT, blank, appends a
   new staged row. This single affordance satisfies both the spec's
   required manual-entry fallback for failed extraction and lets an admin
   add a name the extractor missed on an otherwise-successful run, without
   re-uploading.
7. Each staged row's **STATUS** is `VALID` (graduate_name non-empty,
   latin_honor is one of the 3 enum values, graduation_year is a valid
   4-digit year) or `INCOMPLETE` (any of those missing/invalid — the
   realistic case for a freshly-added blank row via ADD RECORD; a
   machine-extracted row is `VALID` by construction, since the extractor
   only ever produces rows that already passed the honor-phrase match).
8. **SAVE RECORDS** validates every staged row per spec section 39 and
   **blocks with a clear message naming the incomplete row(s)** rather than
   silently skipping them, then inserts all rows into
   `latin_honor_graduates` in one transaction, sets `extraction_status =
   'reviewed'` and `records_extracted` to the final count, clears the
   session staging, and redirects to `admin/view_upload.php` with a success
   toast.
9. **CANCEL** discards the session staging only — `extraction_status` is
   left as whatever the extraction run set it to (`extracted`/`failed`), so
   the admin can re-trigger EXTRACT RECORDS later.

**api/update_graduate.php and api/delete_graduate.php operate on the
session staging only in this phase** — not on already-saved
`latin_honor_graduates` rows. This matches spec sections 14/15's literal
framing ("Update/Remove *Extracted* Record"). Editing or removing an
already-saved graduate record is out of scope for Phase 3 (see below).

**Composer note:** `packagist.org` had one transient connectivity timeout
during brainstorming (unrelated to this project — `getcomposer.org` and
`github.com` both worked fine throughout, and `repo.packagist.org` was
reachable again minutes later). No action needed, just noted in case
`composer require smalot/pdfparser` needs a retry during implementation.

**Out of scope for Phase 3:** browsing/managing already-*saved* graduate
records (`admin/graduates.php`, listed in the folder structure but not
built this phase — that's Phase 4/5 territory), school-specific extractor
implementations (still just the generic one, per section 10 — no second
real-world PDF format sample exists to justify one yet), and the
Look-up/Verification UI (Phase 4, which will be the first real *consumer*
of the `latin_honor_graduates` rows this phase produces).

## 13. Phase 4 addendum — Look-up + Verification (decided 2026-09-13)

Phase 3 is merged. These decisions refine (not replace) section 8's
look-up & verification design.

**Access.** `admin/lookup.php` and `api/search_graduates.php` both call
`require_login()` only, not `require_role('administrator')` — both
`administrator` and `user` roles can search and view results, matching
section 6's "user: search, view graduate details, view PDF if permitted"
and matching `api/serve_pdf.php`'s existing role-agnostic
`require_login()` check (Phase 2). No new permission tier is introduced.

**Filters — exactly the three section 8 names, no additions.** SCHOOL
(`<select>` from active schools), GRADUATION YEAR (`<select>` from
`SELECT DISTINCT graduation_year FROM latin_honor_graduates ORDER BY
graduation_year DESC`), GRADUATE NAME (partial, case-insensitive `LIKE
%term%` — not FULLTEXT; the table is small enough that LIKE is simpler
and sufficient, FULLTEXT is a later upgrade if search performance ever
demands it). All three are optional and combined with AND when present.

**Default (no-filter) state.** Unlike the dashboard's "recent uploads"
table, `lookup.php` does not dump the full `latin_honor_graduates` table
on first load — it shows an instructional empty state ("Search by name,
school, or year to find honor graduate records") until at least one
filter is submitted. This is a look-up tool, not a browse tool; keeps the
page fast regardless of how large the graduates table grows.

**Interaction model.** Filter bar submits via `fetch` to
`api/search_graduates.php` (JSON), no full page reload, mirroring the
upload page's AJAX pattern. Applied filters and the current page number
are reflected in the URL query string (`?name=&school_id=&year=&page=`)
so a search is shareable/back-button-safe; `lookup.php`'s initial
server-side render honors any of those query params already present on
load, so a direct link into a filtered search works even before JS runs.
Results use the existing `paginate()` helper from `includes/functions.php`
at the same page size as the recent-uploads table.

**Results → details modal.** Table columns: GRADUATE NAME, SCHOOL, LATIN
HONOR, GRADUATION YEAR, VIEW action. VIEW opens a `modal.js` modal (the
same reusable component from Phases 2-3) showing the full graduate
record plus its source upload's `original_filename` and `uploaded_at`,
and a "VIEW SOURCE PDF" link opening `api/serve_pdf.php?id=` (the upload's
id — `serve_pdf.php` reads `$_GET['id']`, per Phase 2) in a new tab. Zero matches render the spec's literal empty state — "NO HONOR
GRADUATE RECORD FOUND" — styled as a real empty-state panel, never a
blank table.

**Duplicate uploads produce duplicate-looking rows, by design.** Because
Phase 2's UPLOAD ANYWAY flow allows more than one `reviewed` upload for
the same `(school_id, school_year)`, the same graduate name can
legitimately appear more than once in results if two uploads both
contain it. This phase does not deduplicate or supersede — every saved
`latin_honor_graduates` row is real, reviewed data, and each result row
is traceable to its own `upload_id`/source PDF via the details modal.
Any future de-duplication UX is deferred, not designed here.

**Activity logging.** `activity_log.action` has carried a `search` enum
value since Phase 1's schema (section 5), unused until now. Each search
request logs one `activity_log` row (`user_id` = searcher, `details` =
a short summary of the filters used, e.g. `school_id=3, year=2025,
name=cruz`) so Phase 5's audit log has real data to show.

**Out of scope for Phase 4:** editing or deleting already-*saved*
`latin_honor_graduates` rows (`admin/graduates.php`'s browse/manage
capability stays deferred, as noted in section 12 — look-up is
read-only), FULLTEXT search, and any de-duplication of graduate rows
across multiple uploads for the same school+year (see above).
