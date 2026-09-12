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
  session between steps, records_extracted, uploaded_at, updated_at.
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
