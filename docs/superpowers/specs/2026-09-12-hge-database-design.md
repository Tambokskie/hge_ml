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

## 14. Phase 5 addendum — Admin + Hardening (decided 2026-09-14)

Phase 4 is merged. This is the final phase of the original 5-phase
breakdown (section 2). These decisions refine section 2's "user
management, activity log, CSRF/session hardening pass, full end-to-end
workflow test."

**Schools admin CRUD stays deferred.** Section 11's Phase 2 deferral
("nothing in Phase 2 blocks adding it later") is not picked up here —
Phase 5's own scope is already the full list above; schools remain
manageable only via inline creation on the upload form.

**`admin/users.php` — user management, `require_role('administrator')`.**
A table of all users (NAME, USERNAME, EMAIL, ROLE, STATUS, ACTION).
CREATE USER opens a `modal.js` modal (name/username/email/password/role).
Each row's ACTION supports: change role (`administrator`/`user`), toggle
`active`/`disabled` status, and admin-initiated password reset (the
administrator sets a new password directly for that user — no email
flow, consistent with this app having none). **Self-lockout guard:** an
administrator cannot disable their own account or change their own role
away from `administrator` — enforced both in the UI (the row for the
logged-in user's own account omits those two actions) and server-side in
the API (a request that would do so is rejected), so the guard can't be
bypassed by calling the API directly.

**`admin/activity_log.php` — activity log viewer, `require_role('administrator')`.**
Filters: user (dropdown, all users who have any log rows), action type
(dropdown — the eight `activity_log.action` enum values), and a
date-from/date-to range, defaulting to no range (shows the full
retained window — see retention below). Built the same way as Phase 4's
`admin/lookup.php`: `api/search_activity_log.php` (GET, JSON) plus a
server-rendered initial state on `admin/activity_log.php` itself for
shareable URLs, `history.pushState` for filter/page changes, paginated
via the existing `paginate()` helper. Results table: TIMESTAMP, USER
(or "SYSTEM" for a null `user_id`), ACTION, DETAILS. Per Phase 4's final
review finding that `search` rows are technically forgeable
cross-site (the endpoint that writes them is intentionally GET with no
CSRF check), this page's copy is factual, not accusatory — e.g.
"SEARCH — school_id=3, year=2025" rather than anything implying
confirmed deliberate intent.

**Activity log retention: hard purge at 6 months, opportunistic
trigger.** `log_activity()` (added Phase 4, `includes/functions.php`)
is extended so that every time it inserts a new row, it also deletes
any `activity_log` rows with `created_at` older than 6 months
(`DELETE FROM activity_log WHERE created_at < (NOW() - INTERVAL 6 MONTH)`,
same statement/transaction context as the insert). No cron or scheduled
task exists in this project, and activity logging already happens on
every login/upload/search/etc., so this keeps the table self-bounded
without new infrastructure. This is a genuine data-loss decision, not
cosmetic — entries older than 6 months are gone, not merely hidden from
the default view.

**Hardening fixes (six items; two more — a project-wide global
exception handler and review-table pagination — were considered and
explicitly deferred, not picked up in this phase):**

1. **CSRF token rotated at login.** `login_user()` (`includes/auth.php`)
   additionally clears `$_SESSION['csrf_token']` before/alongside its
   existing `session_regenerate_id(true)` call, so a pre-login CSRF
   token can't remain valid after authenticating. The next
   `csrf_token()` call issues a fresh one.
2. **Session cookie cleared on logout.** `logout_user()`
   (`includes/auth.php`) additionally expires the session cookie
   client-side (via `setcookie()` with the params from
   `session_get_cookie_params()`, matching the standard PHP
   session-cookie-clearing idiom) — `session_destroy()` alone does not
   unset the browser's cookie.
3. **Timing side-channel closed in `attempt_login()`.**
   (`includes/auth.php`) Currently returns immediately when the
   username doesn't exist, skipping `password_verify()` entirely —
   observably faster than the wrong-password path, which lets an
   attacker enumerate valid usernames by response time. Fixed by always
   calling `password_verify()`: against the real user's hash when
   found, against a fixed dummy bcrypt hash (pre-generated once, not
   regenerated per request) when not found, before evaluating whether
   to return null.
4. **`assets/js/upload.js`'s `escapeHtml()` replaced.** Its current
   `div.textContent`/`.innerHTML` round-trip implementation is the same
   pattern already found unsafe (doesn't escape quote characters) and
   fixed in `review.js` and `lookup.js` during Phases 3 and 4 — this
   copy was simply never revisited. Replaced with the same safe
   entity-replacement implementation (`& < > " '`, `&` first) already
   used in those two files.
5. **`.gitignore` gains `.phpunit.result.cache` and `composer.phar`** —
   both have shown up as untracked noise in every phase's `git status`
   so far.
6. **`save_staged_records()`'s rollback call guarded.**
   (`includes/extraction_review.php`) The `catch` block's
   `$pdo->rollBack()` call is wrapped in its own try/catch so that if
   the rollback itself throws, the original exception's error message
   is still what gets logged, rather than an unhandled fatal masking
   it.

**Task 6 (this phase's own end-to-end workflow task, mirroring every
prior phase's final manual-verification task): a scripted browser
walkthrough**, not a new automated test suite. One continuous session
covering the full journey Phases 1-4 built: log in as administrator →
upload a PDF (Phase 2) → extract records (Phase 3) → edit/review →
save → look up the saved graduate (Phase 4) → verify via VIEW SOURCE
PDF → confirm the search appears in the new activity log → create a
`user`-role account via the new `admin/users.php` → log in as that
user → repeat the look-up/verify steps to confirm role-agnostic access
still holds → log back in as administrator and confirm the self-lockout
guard refuses to disable/demote the currently-logged-in account.

**Out of scope for Phase 5:** Schools admin CRUD (see above), a
project-wide global exception/error handler, pagination on
`admin/review.php`'s table, and any email-based password reset flow
(this app has no email infrastructure at any point in the design).

## 15. Schools admin CRUD addendum (decided 2026-09-14)

Phase 5 is merged; all 5 original phases are complete. This is new scope
beyond the original plan, picking up the Schools admin page deferred
since section 11 (Phase 2) and again explicitly out of scope in
section 14 (Phase 5).

**No delete, only rename + enable/disable — matching the no-delete
pattern `admin/users.php` already established.** Schools have exactly
two mutable fields (`school_name`, `status`); a school with real
uploaded honor-graduate data should never be truly removable, and
`status = 'disabled'` already has real, working effect today —
`get_active_school_names()`/`get_active_schools_list()` (used by the
upload form's datalist and `admin/lookup.php`'s SCHOOL filter dropdown)
already filter on `status = 'active'`, so disabling a school simply
stops it appearing in those pickers. No FK/data changes are needed for
rename or disable: `latin_honor_uploads`/`latin_honor_graduates`
reference schools by `school_id`, never by name.

**`includes/schools.php` gains three new functions** (alongside the
existing `find_or_create_school()` and the two `get_active_school*()`
helpers, all unchanged):
- `get_all_schools_with_counts(PDO $pdo): array` — every school
  regardless of status, each row carrying `upload_count` and
  `graduate_count` (cheap correlated-subquery aggregates against
  `latin_honor_uploads`/`latin_honor_graduates`), ordered by name.
- `create_school(PDO $pdo, string $name): array` — a NEW function,
  distinct from `find_or_create_school()`. That existing function's
  "silently reuse the existing row on a name collision" semantic is
  correct for the upload form's inline autocomplete (an uploader
  typing an existing school's name should attach to it, not error),
  but wrong for an explicit CREATE SCHOOL button on this new page —
  here a duplicate name must return a clear error, matching
  `create_user()`'s established error-on-duplicate pattern from
  Phase 5.
- `rename_school(PDO $pdo, int $id, string $newName): array` — same
  duplicate-name validation as `create_school()`.
- `set_school_status(PDO $pdo, int $id, string $status): array` —
  validates `active`/`disabled` only.

No self-lockout equivalent is needed — schools aren't a login identity,
so there is no "can't disable the school you're currently using"
concern the way Phase 5's user management needed one.

**`api/create_school.php` + `api/update_school.php`** — both
`require_role('administrator')`, following the exact thin-wrapper
pattern established since Phase 2: CSRF check, delegate to the data
layer, `try`/`catch(\Throwable)` returning a generic 500 on failure.
`api/update_school.php` combines rename + status into one endpoint
(mirroring `api/update_user.php`'s combined role+status+password
pattern from Phase 5), applied inside a transaction matching that
same file's final-review hardening.

**`admin/schools.php`** follows `admin/users.php`'s exact page+modal
shape: a CREATE SCHOOL button opening a `modal.js` create modal (just a
NAME field), a table (SCHOOL NAME, STATUS, UPLOADS, GRADUATES, ACTION),
and an EDIT modal per row (rename field + status toggle). A new
`assets/js/schools.js` uses the shared `escapeHtml()` from
`assets/js/utils.js` (added in Phase 5) — no new local copy, continuing
that phase's consolidation rather than reintroducing the per-file
redefinition pattern that caused two earlier real XSS bugs in this
project. A `SCHOOLS` link is added to the sidebar's existing
administrator-only nav block (the same `<?php if
(user_has_role($currentUser ?? null, 'administrator')): ?>` block
`USER MANAGEMENT` and `ACTIVITY LOG` already share).

**Activity logging.** Two new `activity_log.action` ENUM values,
`school_created` and `school_updated`, extending the exact audit-trail
pattern Phase 5 established for `user_created`/`user_updated` —
`database/schema.sql` updated, plus the same live-database `ALTER
TABLE ... MODIFY COLUMN action ENUM(...)` step Phase 5 needed (schema.sql
is only read for fresh `CREATE TABLE`s, never retroactively applied to
an already-existing table).

**Out of scope for this addendum:** any warning/confirmation UI when
disabling a school that has existing uploads/graduates (the UPLOADS/
GRADUATES counts already shown in the table give an administrator
enough visibility before deciding — YAGNI beyond that), and any bulk
operations (bulk-disable, CSV import of schools, etc.) — none requested.

## 16. School details + remove + settings nav addendum (decided 2026-09-14)

Section 15 is merged. This addendum, requested the same day, adds
ADDRESS/CAMPUS fields to schools, a scoped hard-delete (REMOVE) that
section 15 explicitly ruled out for the general case, a confirm-before-save
step on create/edit, and a SETTINGS submenu grouping the three admin-only
sidebar links. It does not reopen section 15's core policy — rename/disable
remain the everyday path; REMOVE is a narrow, guarded exception for schools
that were created in error and never used.

**Schema: `schools` gains `address` and `campus`, uniqueness becomes
composite.** Two new columns: `address VARCHAR(500) NOT NULL` and
`campus VARCHAR(255) NOT NULL DEFAULT ''`. CAMPUS is optional input
(blank allowed, stored as `''`, never `NULL`) — deliberately not
nullable, because MySQL treats every `NULL` in a unique index as
distinct from every other `NULL`, which would silently let two
identical blank-campus duplicates through. The existing
`UNIQUE KEY uq_school_name (school_name)` is replaced with
`UNIQUE KEY uq_school_name_campus (school_name, campus)` — two rows
may now share a `school_name` as long as `campus` differs (e.g. "Eastern
Visayas State University" / Main Campus and the same name / Tanauan
Campus are two valid, distinct rows), but the same name+campus pair
may not repeat.

**`create_school()` and `rename_school()` gain `address`/`campus`
parameters.** New signatures:
- `create_school(PDO $pdo, string $name, string $address, string $campus): array`
- `rename_school(PDO $pdo, int $id, string $newName, string $address, string $campus): array`
  — kept as `rename_school()` rather than introducing a new function
  name, since it already owns the "editable text fields" half of a
  school's row (status stays separate, in `set_school_status()`); it now
  updates all three text fields together in one statement.

Both trim all three string inputs. ADDRESS is required — empty after
trimming returns `SCHOOL ADDRESS IS REQUIRED.`, mirroring the existing
`SCHOOL NAME IS REQUIRED.` check. CAMPUS has no required check (empty
string is valid input, not an error). The existing SQLSTATE 23000
duplicate-catch logic is unchanged in shape; it now fires against the
composite key and should return a message identifying both fields, e.g.
`A SCHOOL WITH THIS NAME AND CAMPUS ALREADY EXISTS.`

**New `remove_school()` — a guarded hard delete, not a policy reversal.**

```
remove_school(PDO $pdo, int $id, int $requestingUserId, string $password): array
```

Steps, in order:
1. Verify `$password` against the *requesting admin's own* stored
   password hash. `current_user()` cannot supply it — `attempt_login()`
   already does `unset($user['password'])` before the array reaches the
   session, so the session's user array never carries the hash. This
   step must run its own fresh, minimal query —
   `SELECT password FROM users WHERE id = :id` against
   `$requestingUserId` — then `password_verify($password, $hash)`, the
   same primitive `attempt_login()` uses, just not that whole function
   (which looks up by username, not id, and is shaped for the
   unauthenticated login form). Wrong password → `['status' => 'error',
   'message' => 'INCORRECT PASSWORD.']`, nothing else happens. This is a
   re-authentication check on the acting admin, not a lookup of the
   target school — no username-enumeration concern applies (the caller
   is already an authenticated administrator), so the timing-safe-dummy-hash
   trick `attempt_login()` uses is not needed here.
2. Confirm the school exists (same `SELECT 1 ... WHERE id = :id` shape
   used elsewhere in this project) — not found → `SCHOOL NOT FOUND.`
3. Check `upload_count`/`graduate_count` (reuse the same correlated
   subqueries `get_all_schools_with_counts()` already runs, scoped to
   this one id). Either > 0 → `['status' => 'error', 'message' =>
   'CANNOT REMOVE — THIS SCHOOL HAS ' . $uploads . ' UPLOAD(S) AND ' .
   $graduates . ' GRADUATE RECORD(S).']`. Nothing is deleted. This is
   the section 15 policy holding: real data is never destroyed by this
   feature, only a school that was created and never used can actually
   go away.
4. Otherwise, `DELETE FROM schools WHERE id = :id` and return success.

No transaction is needed — this is a single statement with no other
table mutated in the success path (steps 1-3 are all reads).

**`api/remove_school.php`** — new thin wrapper, same shape as every
other `api/*.php` in this project: `require_role('administrator')`
first, `csrf_verify()` returning HTTP 400 on failure, reads `school_id`
and `password` from `$_POST`, calls `remove_school()` with
`current_user()['id']`, logs `school_removed` (school name + id in
`details`) only on success, generic 500 JSON on any `\Throwable`.

**`admin/schools.php` UI changes:**
- Table gains a leading checkbox column and two new columns, CAMPUS and
  ADDRESS, landing as: checkbox, SCHOOL NAME, CAMPUS, ADDRESS, STATUS,
  UPLOADS, GRADUATES, ACTION (ACTION keeps the existing per-row EDIT
  button — no new per-row button is added).
- The `<h1>` and the sidebar link both change from "SCHOOLS MANAGEMENT"
  to "SCHOOL MANAGEMENT" for consistency with how this addendum refers
  to the page.
- CREATE SCHOOL moves into a new toolbar row directly under the `<h1>`,
  alongside a new REMOVE button. REMOVE is disabled until exactly one
  checkbox is checked; checking a row's box unchecks any other
  (single-select only — checkboxes are the UI control requested, but
  their behavior is exclusive, like radio buttons). No bulk removal.
- CREATE SCHOOL and EDIT open the same two-field-set forms as today
  (now including ADDRESS/CAMPUS) but no longer POST directly. Submitting
  the form opens a second **review modal** — reusing the existing
  `showModal()` helper already used throughout this project, no new UI
  primitive — showing the entered NAME / ADDRESS / CAMPUS / STATUS
  (edit only) as read-only text with CONFIRM and GO BACK buttons. GO
  BACK returns to the editable form with values intact; only CONFIRM
  fires the actual `postJson()` call to `create_school.php` /
  `update_school.php`.
- REMOVE opens a confirmation modal naming the selected school
  ("PERMANENTLY REMOVE <SCHOOL NAME>? THIS CANNOT BE UNDONE.") with a
  password input and CONFIRM/CANCEL buttons. CONFIRM POSTs to
  `api/remove_school.php` with the school id, CSRF token, and the
  entered password; a wrong-password or has-data error from the
  endpoint is shown via the existing `showToast('error', ...)` pattern
  and the modal stays open for another attempt (matching how every
  other modal in this project surfaces a server-side validation error).

**Activity logging — third `activity_log.action` ENUM value,
`school_removed`.** Same three-step pattern this project always needs,
now used a third time (`user_created`/`user_updated` in Phase 5,
`school_created`/`school_updated` in section 15): `database/schema.sql`'s
CREATE TABLE updated, a live `ALTER TABLE ... MODIFY COLUMN action
ENUM(...)` against the already-existing dev database, and a new entry in
`admin/activity_log.php`'s `$actionTypes` filter array — the exact step
section 15's final review caught as missed the first time.

**Sidebar: SETTINGS as a native collapsible submenu.** The existing
`<?php if (user_has_role($currentUser ?? null, 'administrator')):
?>` block's three flat links (USER MANAGEMENT, ACTIVITY LOG, SCHOOL
MANAGEMENT) move inside a `<details>`/`<summary>` element labeled
SETTINGS — no new JavaScript, no new client-side state; native browser
disclosure behavior styled with Tailwind, consistent with this
project's pattern of keeping static navigation server-rendered. The
`<details>` carries the `open` attribute whenever `$currentPage` is
`users`, `activity_log`, or `schools`, so navigating directly to any of
the three pages shows the submenu already expanded with its link
highlighted, rather than landing on a collapsed menu that hides where
you are.

**Out of scope for this addendum:** editing address/campus without
going through the same review-modal confirmation step (there is no
"quick edit" path); removing a school that has ever had data, at any
point, even after that data is later deleted through some other means
(no such means exists elsewhere in this project, so this is purely
theoretical); bulk removal (explicitly ruled out this round, unlike
section 15 where it simply hadn't come up); campus/address search or
filtering anywhere else in the app (`admin/lookup.php`'s SCHOOL filter,
the upload form's datalist) — those continue to key off `school_name`
alone, unchanged by this addendum.
