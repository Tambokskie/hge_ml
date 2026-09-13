# HGE Database — Phase 2: Schools + Upload/Storage Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an administrator upload a school's Latin Honor PDF list through a drag-and-drop form, with the school created on the fly if needed, the file safely stored and protected from direct web access, duplicate school+year uploads caught and confirmable, and the result visible on the dashboard.

**Architecture:** Three new focused include files (`includes/pdf_files.php` for file-naming/validation, `includes/schools.php` for school lookup/creation, `includes/uploads.php` for upload queries and the upload orchestration core) sit behind two thin HTTP endpoints (`api/upload_pdf.php`, `api/serve_pdf.php`) and two admin pages (`admin/upload.php`, `admin/view_upload.php`). Two new plain-JS components (`toast.js`, `modal.js`) become globally available via `includes/footer.php`, matching Phase 1's pattern of thin pages + testable core functions.

**Tech Stack:** PHP 8.2.12, MariaDB 10.4.32 (PDO), PHPUnit ^10.5, vanilla JS (Fetch API, FormData), Tailwind (already compiled in Phase 1).

**Spec:** [docs/superpowers/specs/2026-09-12-hge-database-design.md](../specs/2026-09-12-hge-database-design.md) (section 11 is the Phase 2 addendum this plan implements)

## Global Constraints

- Project root: `C:\xampp\htdocs\hge_masterlist`, served at `http://localhost/hge_masterlist/`.
- All SQL via PDO prepared statements.
- All dynamic output escaped at render time (`e()`/`e_upper()`).
- UPPERCASE display for major on-screen labels/values.
- Role/auth checks server-side: `require_role('administrator')` on the upload endpoint/page, `require_login()` on view/serve (any authenticated role).
- CSRF token required and verified on the upload POST.
- Never expose raw PHP/MySQL errors to the browser.
- Max PDF upload size: 20 MB (20971520 bytes), enforced server-side regardless of client-reported size.
- `stored_filename` format: `{SCHOOL_NAME_SANITIZED}_{SCHOOL_YEAR}_{UPLOAD_NUMBER}.pdf` (school name uppercased, non-alphanumeric runs collapsed to a single underscore); `original_filename` (uploader's own name) is preserved unmodified for display.
- Duplicate trigger is `(school_id, school_year)` match only, not file hash comparison (file_hash is still computed and stored, just not the trigger).
- School creation is inline (typing a new name creates it) — no dedicated Schools admin page in this phase.
- `storage/pdfs/` gets the same deny-all `.htaccess` pattern already used for `includes/`/`config/`/`database/` in Phase 1.
- No production data exists yet: schema changes are made directly to `database/schema.sql`, and both `hge_masterlist` and `hge_masterlist_test` are dropped and recreated from it (not migrated in place).

---

## Task 1: Schema migration — upload_number column

**Files:**
- Modify: `database/schema.sql` (the `latin_honor_uploads` table definition)
- Modify: `tests/SchemaTest.php` (add one test)

**Interfaces:**
- Produces: `latin_honor_uploads.upload_number` (INT UNSIGNED NOT NULL DEFAULT 1) with `UNIQUE KEY uq_school_year_uploadnum (school_id, school_year, upload_number)`. Every later task's INSERT into this table sets this column explicitly.

- [ ] **Step 1: Write the failing test**

Append to `tests/SchemaTest.php` (inside the `SchemaTest` class, after the existing test methods):

```php
    public function testUploadNumberColumnAndUniqueConstraintExist(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO schools (school_name) VALUES ('TEST UNIVERSITY')");
        $pdo->exec(
            "INSERT INTO users (name, username, email, password, role) VALUES ('Admin', 'admin', 'a@a.com', 'x', 'administrator')"
        );
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number)
             VALUES (1, '2025', 'list.pdf', 'TEST_UNIVERSITY_2025_1.pdf', '/storage/pdfs/x.pdf', 100, SHA2('a', 256), 1, 1)"
        );

        $this->expectException(\PDOException::class);
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number)
             VALUES (1, '2025', 'list2.pdf', 'TEST_UNIVERSITY_2025_1.pdf', '/storage/pdfs/y.pdf', 100, SHA2('b', 256), 1, 1)"
        );
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/SchemaTest.php`
Expected: FAIL — no exception thrown (column doesn't exist yet, or the duplicate insert succeeds since there's no unique constraint on it).

- [ ] **Step 3: Modify database/schema.sql**

In the `latin_honor_uploads` table definition, add the `upload_number` column after `uploaded_by` and the new unique key after the existing `KEY idx_file_hash` line:

```sql
CREATE TABLE latin_honor_uploads (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id INT UNSIGNED NOT NULL,
  school_year VARCHAR(9) NOT NULL,
  original_filename VARCHAR(255) NOT NULL,
  stored_filename VARCHAR(255) NOT NULL,
  file_path VARCHAR(500) NOT NULL,
  file_size INT UNSIGNED NOT NULL,
  file_hash CHAR(64) NOT NULL,
  uploaded_by INT UNSIGNED NOT NULL,
  upload_number INT UNSIGNED NOT NULL DEFAULT 1,
  extraction_status ENUM('pending','extracted','reviewed','failed') NOT NULL DEFAULT 'pending',
  records_extracted INT UNSIGNED NOT NULL DEFAULT 0,
  uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (school_id) REFERENCES schools(id),
  FOREIGN KEY (uploaded_by) REFERENCES users(id),
  KEY idx_school_id (school_id),
  KEY idx_file_hash (file_hash),
  UNIQUE KEY uq_school_year_uploadnum (school_id, school_year, upload_number)
) ENGINE=InnoDB;
```

(The `DEFAULT 1` matters: Phase 1's `tests/includes/FlashAndStatsTest.php` inserts a `latin_honor_uploads` row without specifying `upload_number` — the default keeps that test passing unmodified.)

- [ ] **Step 4: Run the new test to verify it passes**

Run: `php vendor/bin/phpunit tests/SchemaTest.php`
Expected: `OK (4 tests, ...)`

- [ ] **Step 5: Run the FULL suite to catch regressions**

Run: `php vendor/bin/phpunit`
Expected: all ~34 tests from Phase 1 still pass (this specifically verifies the `DEFAULT 1` didn't break `FlashAndStatsTest`'s existing insert).

- [ ] **Step 6: Reset the dev database and re-seed the administrator**

```bash
mysql -u root -e "DROP DATABASE IF EXISTS hge_masterlist" 2>&1 || "/c/xampp/mysql/bin/mysql.exe" -u root -e "DROP DATABASE IF EXISTS hge_masterlist"
```

Then run the schema and reseed (adjust the mysql client path if needed for this environment):

```bash
"/c/xampp/mysql/bin/mysql.exe" -u root -e "SOURCE database/schema.sql"
ADMIN_USERNAME=admin ADMIN_PASSWORD=ChangeMe123! php database/seed_admin.php
```

Expected: no errors; `Administrator account 'admin' created/updated.` printed.

- [ ] **Step 7: Commit**

```bash
git add database/schema.sql tests/SchemaTest.php
git commit -m "feat: add upload_number column for per-school-year upload sequencing"
```

---

## Task 2: Filename helpers

**Files:**
- Create: `includes/pdf_files.php`
- Test: `tests/includes/FilenameHelpersTest.php`

**Interfaces:**
- Produces: `sanitize_for_filename(string $value): string`, `build_stored_filename(string $schoolName, string $schoolYear, int $uploadNumber): string`. Task 8's `handle_upload_request()` calls `build_stored_filename()`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/pdf_files.php';

class FilenameHelpersTest extends TestCase
{
    public function testSanitizeForFilenameUppercasesAndCollapsesSymbols(): void
    {
        $this->assertSame('ABC_UNIVERSITY', sanitize_for_filename('ABC University'));
        $this->assertSame('ST_JOSEPH_S_COLLEGE', sanitize_for_filename("  St. Joseph's College!!  "));
    }

    public function testSanitizeForFilenameTrimsLeadingAndTrailingUnderscores(): void
    {
        $this->assertSame('EASTERN_VISAYAS_STATE_UNIVERSITY', sanitize_for_filename('---Eastern Visayas State University---'));
    }

    public function testBuildStoredFilenameProducesExpectedPattern(): void
    {
        $this->assertSame('ABC_UNIVERSITY_2025_1.pdf', build_stored_filename('ABC University', '2025', 1));
    }

    public function testBuildStoredFilenameIncludesUploadNumber(): void
    {
        $this->assertSame('ABC_UNIVERSITY_2025_3.pdf', build_stored_filename('ABC University', '2025', 3));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/includes/FilenameHelpersTest.php`
Expected: FAIL — `sanitize_for_filename()` undefined.

- [ ] **Step 3: Write includes/pdf_files.php**

```php
<?php

function sanitize_for_filename(string $value): string
{
    $upper = mb_strtoupper(trim($value));
    $sanitized = preg_replace('/[^A-Z0-9]+/', '_', $upper);
    return trim($sanitized, '_');
}

function build_stored_filename(string $schoolName, string $schoolYear, int $uploadNumber): string
{
    $schoolPart = sanitize_for_filename($schoolName);
    $yearPart = sanitize_for_filename($schoolYear);

    return "{$schoolPart}_{$yearPart}_{$uploadNumber}.pdf";
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php vendor/bin/phpunit tests/includes/FilenameHelpersTest.php`
Expected: `OK (4 tests, ...)`

- [ ] **Step 5: Commit**

```bash
git add includes/pdf_files.php tests/includes/FilenameHelpersTest.php
git commit -m "feat: add filename sanitization and stored-filename builder"
```

---

## Task 3: PDF content validation + test fixtures

**Files:**
- Modify: `includes/pdf_files.php`
- Create: `tests/fixtures/sample.pdf`
- Create: `tests/fixtures/not-a-pdf.txt`
- Test: `tests/includes/PdfValidationTest.php`

**Interfaces:**
- Produces: `validate_pdf_file_content(string $path, int $size, int $maxBytes): array` (keys: `ok` bool, `error` ?string) and `validate_pdf_upload(array $file, int $maxBytes): array` (same return shape; wraps `$_FILES`-specific checks around `validate_pdf_file_content()`). Task 8's `handle_upload_request()` calls `validate_pdf_file_content()` directly (not `validate_pdf_upload()` — see Task 8's note on why). Task 9's `api/upload_pdf.php` performs its own `is_uploaded_file()` check before calling `handle_upload_request()`.

- [ ] **Step 1: Create the fixture files**

Create `tests/fixtures/sample.pdf` with exactly this content (a minimal but real PDF — valid enough for `finfo`'s MIME sniffing to detect `application/pdf` via the `%PDF-` header signature):

```
%PDF-1.4
1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj
2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj
3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj
trailer<</Size 4/Root 1 0 R>>
%%EOF
```

Create `tests/fixtures/not-a-pdf.txt` with exactly this content:

```
this is definitely not a pdf file
```

- [ ] **Step 2: Write the failing test**

```php
<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/pdf_files.php';

class PdfValidationTest extends TestCase
{
    private string $samplePdf;
    private string $notAPdf;

    protected function setUp(): void
    {
        $this->samplePdf = __DIR__ . '/../fixtures/sample.pdf';
        $this->notAPdf = __DIR__ . '/../fixtures/not-a-pdf.txt';
    }

    public function testValidatePdfFileContentAcceptsRealPdf(): void
    {
        $result = validate_pdf_file_content($this->samplePdf, filesize($this->samplePdf), 20971520);
        $this->assertTrue($result['ok']);
        $this->assertNull($result['error']);
    }

    public function testValidatePdfFileContentRejectsNonPdfMimeType(): void
    {
        $result = validate_pdf_file_content($this->notAPdf, filesize($this->notAPdf), 20971520);
        $this->assertFalse($result['ok']);
        $this->assertSame('ONLY PDF FILES ARE ACCEPTED.', $result['error']);
    }

    public function testValidatePdfFileContentRejectsOversizedFile(): void
    {
        $result = validate_pdf_file_content($this->samplePdf, 999, 500);
        $this->assertFalse($result['ok']);
        $this->assertSame('THE FILE EXCEEDS THE MAXIMUM ALLOWED SIZE.', $result['error']);
    }

    public function testValidatePdfUploadRejectsPhpUploadError(): void
    {
        $file = ['error' => UPLOAD_ERR_INI_SIZE, 'tmp_name' => $this->samplePdf];
        $result = validate_pdf_upload($file, 20971520);
        $this->assertFalse($result['ok']);
        $this->assertSame('THE FILE COULD NOT BE UPLOADED. PLEASE TRY AGAIN.', $result['error']);
    }

    public function testValidatePdfUploadRejectsNonUploadedFile(): void
    {
        // A real file on disk that was never submitted via HTTP — is_uploaded_file() must reject it.
        $file = ['error' => UPLOAD_ERR_OK, 'tmp_name' => $this->samplePdf];
        $result = validate_pdf_upload($file, 20971520);
        $this->assertFalse($result['ok']);
        $this->assertSame('THE FILE COULD NOT BE UPLOADED. PLEASE TRY AGAIN.', $result['error']);
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/includes/PdfValidationTest.php`
Expected: FAIL — `validate_pdf_file_content()` undefined.

- [ ] **Step 4: Append to includes/pdf_files.php**

```php

function validate_pdf_file_content(string $path, int $size, int $maxBytes): array
{
    if ($size > $maxBytes) {
        return ['ok' => false, 'error' => 'THE FILE EXCEEDS THE MAXIMUM ALLOWED SIZE.'];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $path);
    finfo_close($finfo);

    if ($mime !== 'application/pdf') {
        return ['ok' => false, 'error' => 'ONLY PDF FILES ARE ACCEPTED.'];
    }

    return ['ok' => true, 'error' => null];
}

function validate_pdf_upload(array $file, int $maxBytes): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'THE FILE COULD NOT BE UPLOADED. PLEASE TRY AGAIN.'];
    }

    $tmpName = $file['tmp_name'] ?? '';
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        return ['ok' => false, 'error' => 'THE FILE COULD NOT BE UPLOADED. PLEASE TRY AGAIN.'];
    }

    $size = filesize($tmpName);
    if ($size === false) {
        return ['ok' => false, 'error' => 'THE FILE COULD NOT BE UPLOADED. PLEASE TRY AGAIN.'];
    }

    return validate_pdf_file_content($tmpName, $size, $maxBytes);
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php vendor/bin/phpunit tests/includes/PdfValidationTest.php`
Expected: `OK (5 tests, ...)`

- [ ] **Step 6: Commit**

```bash
git add includes/pdf_files.php tests/includes/PdfValidationTest.php tests/fixtures/sample.pdf tests/fixtures/not-a-pdf.txt
git commit -m "feat: add PDF content validation (size + MIME sniffing)"
```

---

## Task 4: School lookup/creation

**Files:**
- Create: `includes/schools.php`
- Test: `tests/includes/SchoolsTest.php`

**Interfaces:**
- Consumes: `Tests\TestDb` (from Phase 1).
- Produces: `find_or_create_school(PDO $pdo, string $schoolName): array` (keys: `id`, `school_name`), `get_active_school_names(PDO $pdo): array` (list of strings). Task 8's `handle_upload_request()` calls `find_or_create_school()`; Task 12's `admin/upload.php` calls `get_active_school_names()`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;
use Tests\TestDb;

require_once __DIR__ . '/../../includes/schools.php';

class SchoolsTest extends TestCase
{
    protected function setUp(): void
    {
        TestDb::resetSchema();
    }

    public function testFindOrCreateSchoolCreatesNewSchool(): void
    {
        $pdo = TestDb::pdo();
        $school = find_or_create_school($pdo, 'ABC University');

        $this->assertSame('ABC University', $school['school_name']);
        $count = (int) $pdo->query("SELECT COUNT(*) FROM schools WHERE school_name = 'ABC University'")->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testFindOrCreateSchoolReusesExistingExactMatch(): void
    {
        $pdo = TestDb::pdo();
        $first = find_or_create_school($pdo, 'ABC University');
        $second = find_or_create_school($pdo, 'ABC University');

        $this->assertSame($first['id'], $second['id']);
        $count = (int) $pdo->query("SELECT COUNT(*) FROM schools")->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testFindOrCreateSchoolReusesExistingCaseInsensitiveMatch(): void
    {
        $pdo = TestDb::pdo();
        $first = find_or_create_school($pdo, 'ABC University');
        $second = find_or_create_school($pdo, 'abc university');

        $this->assertSame($first['id'], $second['id']);
        $count = (int) $pdo->query("SELECT COUNT(*) FROM schools")->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testGetActiveSchoolNamesReturnsOnlyActiveSchoolsSortedAlphabetically(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO schools (school_name, status) VALUES ('XYZ College', 'active')");
        $pdo->exec("INSERT INTO schools (school_name, status) VALUES ('ABC University', 'active')");
        $pdo->exec("INSERT INTO schools (school_name, status) VALUES ('Disabled School', 'disabled')");

        $names = get_active_school_names($pdo);

        $this->assertSame(['ABC University', 'XYZ College'], $names);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/includes/SchoolsTest.php`
Expected: FAIL — `find_or_create_school()` undefined.

- [ ] **Step 3: Write includes/schools.php**

```php
<?php

function find_or_create_school(PDO $pdo, string $schoolName): array
{
    $name = trim($schoolName);

    try {
        $stmt = $pdo->prepare('INSERT INTO schools (school_name) VALUES (:name)');
        $stmt->execute(['name' => $name]);

        return [
            'id' => (int) $pdo->lastInsertId(),
            'school_name' => $name,
        ];
    } catch (PDOException $e) {
        if ($e->getCode() !== '23000') {
            throw $e;
        }
    }

    $stmt = $pdo->prepare('SELECT id, school_name FROM schools WHERE school_name = :name LIMIT 1');
    $stmt->execute(['name' => $name]);
    $row = $stmt->fetch();

    if ($row === false) {
        $stmt = $pdo->prepare('SELECT id, school_name FROM schools WHERE LOWER(school_name) = LOWER(:name) LIMIT 1');
        $stmt->execute(['name' => $name]);
        $row = $stmt->fetch();
    }

    return [
        'id' => (int) $row['id'],
        'school_name' => $row['school_name'],
    ];
}

function get_active_school_names(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT school_name FROM schools WHERE status = 'active' ORDER BY school_name ASC");
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php vendor/bin/phpunit tests/includes/SchoolsTest.php`
Expected: `OK (4 tests, ...)`

- [ ] **Step 5: Commit**

```bash
git add includes/schools.php tests/includes/SchoolsTest.php
git commit -m "feat: add school find-or-create and active-school listing"
```

---

## Task 5: Upload duplicate-check + numbering queries

**Files:**
- Create: `includes/uploads.php`
- Test: `tests/includes/UploadDuplicateAndNumberingTest.php`

**Interfaces:**
- Consumes: `Tests\TestDb`.
- Produces: `find_existing_upload(PDO $pdo, int $schoolId, string $schoolYear): ?array`, `next_upload_number(PDO $pdo, int $schoolId, string $schoolYear): int`. Task 8's `handle_upload_request()` calls both.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;
use Tests\TestDb;

require_once __DIR__ . '/../../includes/uploads.php';

class UploadDuplicateAndNumberingTest extends TestCase
{
    protected function setUp(): void
    {
        TestDb::resetSchema();
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO schools (school_name) VALUES ('ABC University')");
        $pdo->exec("INSERT INTO users (name, username, email, password, role) VALUES ('Admin', 'admin', 'a@a.com', 'x', 'administrator')");
    }

    public function testFindExistingUploadReturnsNullWhenNoneExists(): void
    {
        $this->assertNull(find_existing_upload(TestDb::pdo(), 1, '2025'));
    }

    public function testFindExistingUploadReturnsMostRecentMatch(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number)
             VALUES (1, '2025', 'a.pdf', 'ABC_UNIVERSITY_2025_1.pdf', '/x/a.pdf', 10, SHA2('a',256), 1, 1)"
        );

        $existing = find_existing_upload($pdo, 1, '2025');

        $this->assertNotNull($existing);
        $this->assertSame('ABC University', $existing['school_name']);
        $this->assertSame('a.pdf', $existing['original_filename']);
    }

    public function testNextUploadNumberIsOneWhenNoneExistYet(): void
    {
        $this->assertSame(1, next_upload_number(TestDb::pdo(), 1, '2025'));
    }

    public function testNextUploadNumberIncrementsPastExistingMax(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number)
             VALUES (1, '2025', 'a.pdf', 'ABC_UNIVERSITY_2025_1.pdf', '/x/a.pdf', 10, SHA2('a',256), 1, 1)"
        );

        $this->assertSame(2, next_upload_number($pdo, 1, '2025'));
    }

    public function testNextUploadNumberIsIndependentPerSchoolYear(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number)
             VALUES (1, '2024', 'a.pdf', 'ABC_UNIVERSITY_2024_1.pdf', '/x/a.pdf', 10, SHA2('a',256), 1, 1)"
        );

        $this->assertSame(1, next_upload_number($pdo, 1, '2025'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/includes/UploadDuplicateAndNumberingTest.php`
Expected: FAIL — `find_existing_upload()` undefined.

- [ ] **Step 3: Write includes/uploads.php**

```php
<?php

function find_existing_upload(PDO $pdo, int $schoolId, string $schoolYear): ?array
{
    $stmt = $pdo->prepare(
        'SELECT u.id, u.school_id, u.school_year, u.original_filename, u.stored_filename,
                u.uploaded_at, u.records_extracted, s.school_name
         FROM latin_honor_uploads u
         JOIN schools s ON s.id = u.school_id
         WHERE u.school_id = :school_id AND u.school_year = :school_year
         ORDER BY u.upload_number DESC
         LIMIT 1'
    );
    $stmt->execute(['school_id' => $schoolId, 'school_year' => $schoolYear]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

function next_upload_number(PDO $pdo, int $schoolId, string $schoolYear): int
{
    $stmt = $pdo->prepare(
        'SELECT COALESCE(MAX(upload_number), 0) FROM latin_honor_uploads WHERE school_id = :school_id AND school_year = :school_year'
    );
    $stmt->execute(['school_id' => $schoolId, 'school_year' => $schoolYear]);

    return ((int) $stmt->fetchColumn()) + 1;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php vendor/bin/phpunit tests/includes/UploadDuplicateAndNumberingTest.php`
Expected: `OK (5 tests, ...)`

- [ ] **Step 5: Commit**

```bash
git add includes/uploads.php tests/includes/UploadDuplicateAndNumberingTest.php
git commit -m "feat: add duplicate-upload detection and upload-number sequencing"
```

---

## Task 6: Upload record creation + storage directory protection

**Files:**
- Modify: `includes/uploads.php`
- Create: `storage/pdfs/.htaccess`
- Create: `storage/pdfs/.gitkeep`
- Modify: `.gitignore`
- Test: `tests/includes/CreateUploadRecordTest.php`

**Interfaces:**
- Consumes: `Tests\TestDb`.
- Produces: `create_upload_record(PDO $pdo, array $data): array` (keys: `id`, `school_id`, `school_year`, `original_filename`, `stored_filename`, `file_path`, `file_size`, `file_hash`, `uploaded_by`, `upload_number`, `extraction_status`, `records_extracted`). Task 8's `handle_upload_request()` calls this with a `$data` array containing exactly those keys except `id`/`extraction_status`/`records_extracted` (which `create_upload_record()` fills in).
- Produces: the `storage/pdfs/` directory existing on disk with deny-all web access. Task 8's tests use a separate temp directory (not this real one — see Task 8), but the real app (Task 9 onward) writes here.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;
use Tests\TestDb;

require_once __DIR__ . '/../../includes/uploads.php';

class CreateUploadRecordTest extends TestCase
{
    protected function setUp(): void
    {
        TestDb::resetSchema();
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO schools (school_name) VALUES ('ABC University')");
        $pdo->exec("INSERT INTO users (name, username, email, password, role) VALUES ('Admin', 'admin', 'a@a.com', 'x', 'administrator')");
    }

    public function testCreateUploadRecordInsertsAndReturnsFullRow(): void
    {
        $pdo = TestDb::pdo();

        $upload = create_upload_record($pdo, [
            'school_id' => 1,
            'school_year' => '2025',
            'original_filename' => 'Honor List.pdf',
            'stored_filename' => 'ABC_UNIVERSITY_2025_1.pdf',
            'file_path' => '/storage/pdfs/ABC_UNIVERSITY_2025_1.pdf',
            'file_size' => 12345,
            'file_hash' => hash('sha256', 'test-content'),
            'uploaded_by' => 1,
            'upload_number' => 1,
        ]);

        $this->assertGreaterThan(0, $upload['id']);
        $this->assertSame(1, $upload['school_id']);
        $this->assertSame('2025', $upload['school_year']);
        $this->assertSame('Honor List.pdf', $upload['original_filename']);
        $this->assertSame('ABC_UNIVERSITY_2025_1.pdf', $upload['stored_filename']);
        $this->assertSame(1, $upload['upload_number']);
        $this->assertSame('pending', $upload['extraction_status']);
        $this->assertSame(0, $upload['records_extracted']);

        $count = (int) $pdo->query('SELECT COUNT(*) FROM latin_honor_uploads')->fetchColumn();
        $this->assertSame(1, $count);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/includes/CreateUploadRecordTest.php`
Expected: FAIL — `create_upload_record()` undefined.

- [ ] **Step 3: Append to includes/uploads.php**

```php

function create_upload_record(PDO $pdo, array $data): array
{
    $stmt = $pdo->prepare(
        'INSERT INTO latin_honor_uploads
            (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number)
         VALUES
            (:school_id, :school_year, :original_filename, :stored_filename, :file_path, :file_size, :file_hash, :uploaded_by, :upload_number)'
    );
    $stmt->execute([
        'school_id' => $data['school_id'],
        'school_year' => $data['school_year'],
        'original_filename' => $data['original_filename'],
        'stored_filename' => $data['stored_filename'],
        'file_path' => $data['file_path'],
        'file_size' => $data['file_size'],
        'file_hash' => $data['file_hash'],
        'uploaded_by' => $data['uploaded_by'],
        'upload_number' => $data['upload_number'],
    ]);

    return [
        'id' => (int) $pdo->lastInsertId(),
        'school_id' => $data['school_id'],
        'school_year' => $data['school_year'],
        'original_filename' => $data['original_filename'],
        'stored_filename' => $data['stored_filename'],
        'file_path' => $data['file_path'],
        'file_size' => $data['file_size'],
        'file_hash' => $data['file_hash'],
        'uploaded_by' => $data['uploaded_by'],
        'upload_number' => $data['upload_number'],
        'extraction_status' => 'pending',
        'records_extracted' => 0,
    ];
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php vendor/bin/phpunit tests/includes/CreateUploadRecordTest.php`
Expected: `OK (1 test, ...)`

- [ ] **Step 5: Create storage/pdfs/.htaccess**

```apache
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order deny,allow
    Deny from all
</IfModule>
```

- [ ] **Step 6: Create storage/pdfs/.gitkeep**

Empty file (zero bytes) — keeps the directory present in git even though its PDF contents are ignored.

- [ ] **Step 7: Update .gitignore**

Add these lines to the existing `.gitignore`:

```
/storage/pdfs/*
!/storage/pdfs/.htaccess
!/storage/pdfs/.gitkeep
```

- [ ] **Step 8: Verify the directory and protection files exist**

Run: `ls -la storage/pdfs/`
Expected: `.htaccess` and `.gitkeep` present.

- [ ] **Step 9: Commit**

```bash
git add includes/uploads.php tests/includes/CreateUploadRecordTest.php storage/pdfs/.htaccess storage/pdfs/.gitkeep .gitignore
git commit -m "feat: add upload record creation and protect storage/pdfs from direct access"
```

---

## Task 7: Recent/single upload queries

**Files:**
- Modify: `includes/uploads.php`
- Test: `tests/includes/UploadQueriesTest.php`

**Interfaces:**
- Consumes: `Tests\TestDb`.
- Produces: `get_recent_uploads(PDO $pdo, int $limit = 5): array` (list of rows with `id`, `school_year`, `original_filename`, `stored_filename`, `uploaded_at`, `records_extracted`, `school_name`), `get_upload_by_id(PDO $pdo, int $id): ?array` (same shape plus `school_id`, `file_path`, `file_size`, `extraction_status`). Task 10's `api/serve_pdf.php`, Task 13's `admin/view_upload.php`, and Task 14's dashboard modification all call these.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;
use Tests\TestDb;

require_once __DIR__ . '/../../includes/uploads.php';

class UploadQueriesTest extends TestCase
{
    protected function setUp(): void
    {
        TestDb::resetSchema();
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO schools (school_name) VALUES ('ABC University'), ('XYZ College')");
        $pdo->exec("INSERT INTO users (name, username, email, password, role) VALUES ('Admin', 'admin', 'a@a.com', 'x', 'administrator')");
    }

    public function testGetRecentUploadsReturnsMostRecentFirstWithSchoolName(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number, uploaded_at)
             VALUES (1, '2024', 'a.pdf', 'ABC_UNIVERSITY_2024_1.pdf', '/x/a.pdf', 10, SHA2('a',256), 1, 1, '2024-01-01 00:00:00')"
        );
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number, uploaded_at)
             VALUES (2, '2025', 'b.pdf', 'XYZ_COLLEGE_2025_1.pdf', '/x/b.pdf', 10, SHA2('b',256), 1, 1, '2025-01-01 00:00:00')"
        );

        $recent = get_recent_uploads($pdo, 5);

        $this->assertCount(2, $recent);
        $this->assertSame('XYZ College', $recent[0]['school_name']);
        $this->assertSame('ABC University', $recent[1]['school_name']);
    }

    public function testGetRecentUploadsRespectsLimit(): void
    {
        $pdo = TestDb::pdo();
        for ($i = 1; $i <= 3; $i++) {
            $pdo->exec(
                "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number)
                 VALUES (1, '202{$i}', 'a{$i}.pdf', 'ABC_UNIVERSITY_202{$i}_1.pdf', '/x/a{$i}.pdf', 10, SHA2('a{$i}',256), 1, 1)"
            );
        }

        $this->assertCount(2, get_recent_uploads($pdo, 2));
    }

    public function testGetUploadByIdReturnsFullRow(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number)
             VALUES (1, '2025', 'a.pdf', 'ABC_UNIVERSITY_2025_1.pdf', '/x/a.pdf', 10, SHA2('a',256), 1, 1)"
        );
        $id = (int) $pdo->lastInsertId();

        $upload = get_upload_by_id($pdo, $id);

        $this->assertNotNull($upload);
        $this->assertSame('ABC University', $upload['school_name']);
        $this->assertSame('/x/a.pdf', $upload['file_path']);
        $this->assertSame('pending', $upload['extraction_status']);
    }

    public function testGetUploadByIdReturnsNullForUnknownId(): void
    {
        $this->assertNull(get_upload_by_id(TestDb::pdo(), 999));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/includes/UploadQueriesTest.php`
Expected: FAIL — `get_recent_uploads()` undefined.

- [ ] **Step 3: Append to includes/uploads.php**

```php

function get_recent_uploads(PDO $pdo, int $limit = 5): array
{
    $stmt = $pdo->prepare(
        'SELECT u.id, u.school_year, u.original_filename, u.stored_filename, u.uploaded_at, u.records_extracted, s.school_name
         FROM latin_honor_uploads u
         JOIN schools s ON s.id = u.school_id
         ORDER BY u.uploaded_at DESC, u.id DESC
         LIMIT :limit'
    );
    $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

function get_upload_by_id(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT u.id, u.school_id, u.school_year, u.original_filename, u.stored_filename, u.file_path,
                u.file_size, u.uploaded_at, u.extraction_status, u.records_extracted, s.school_name
         FROM latin_honor_uploads u
         JOIN schools s ON s.id = u.school_id
         WHERE u.id = :id
         LIMIT 1'
    );
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php vendor/bin/phpunit tests/includes/UploadQueriesTest.php`
Expected: `OK (4 tests, ...)`

- [ ] **Step 5: Commit**

```bash
git add includes/uploads.php tests/includes/UploadQueriesTest.php
git commit -m "feat: add recent-uploads and single-upload lookup queries"
```

---

## Task 8: Upload orchestration core

**Files:**
- Modify: `includes/uploads.php`
- Test: `tests/includes/HandleUploadRequestTest.php`

**Interfaces:**
- Consumes: `find_or_create_school()` (Task 4), `find_existing_upload()`, `next_upload_number()` (Task 5), `create_upload_record()` (Task 6), `build_stored_filename()` (Task 2), `validate_pdf_file_content()` (Task 3).
- Produces: `handle_upload_request(PDO $pdo, array $post, array $files, int $uploadedBy, string $storageDir, int $maxBytes = 20971520): array` — return shape is one of:
  - `['status' => 'error', 'message' => string]`
  - `['status' => 'duplicate', 'message' => string, 'existing_upload' => array]`
  - `['status' => 'success', 'message' => string, 'upload' => array]`

  Task 9's `api/upload_pdf.php` calls this and `json_encode()`s the result directly.

**Important design note:** this function uses `copy()`, not `move_uploaded_file()`, to place the file into `$storageDir`. `move_uploaded_file()` requires `is_uploaded_file()` to pass, which only real HTTP-uploaded files satisfy — that would make this function untestable with fixture files. The real HTTP-upload security check (`is_uploaded_file()`) happens in Task 9's thin `api/upload_pdf.php` wrapper *before* this function is ever called, so using `copy()` here is safe: by the time this function runs, the caller has already verified the source path came from a genuine upload.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;
use Tests\TestDb;

require_once __DIR__ . '/../../includes/pdf_files.php';
require_once __DIR__ . '/../../includes/schools.php';
require_once __DIR__ . '/../../includes/uploads.php';

class HandleUploadRequestTest extends TestCase
{
    private string $storageDir;
    private string $samplePdf;

    protected function setUp(): void
    {
        TestDb::resetSchema();
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO users (name, username, email, password, role) VALUES ('Admin', 'admin', 'a@a.com', 'x', 'administrator')");

        $this->samplePdf = __DIR__ . '/../fixtures/sample.pdf';
        $this->storageDir = sys_get_temp_dir() . '/hge_test_storage_' . uniqid();
        mkdir($this->storageDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storageDir . '/*') as $file) {
            unlink($file);
        }
        rmdir($this->storageDir);
    }

    private function fileField(): array
    {
        return [
            'name' => 'Honor List.pdf',
            'type' => 'application/pdf',
            'tmp_name' => $this->samplePdf,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($this->samplePdf),
        ];
    }

    public function testSuccessfulUploadCreatesSchoolAndUploadRecord(): void
    {
        $pdo = TestDb::pdo();

        $result = handle_upload_request(
            $pdo,
            ['school_name' => 'ABC University', 'school_year' => '2025'],
            ['pdf_file' => $this->fileField()],
            1,
            $this->storageDir
        );

        $this->assertSame('success', $result['status']);
        $this->assertSame(1, $result['upload']['upload_number']);
        $this->assertFileExists($this->storageDir . '/ABC_UNIVERSITY_2025_1.pdf');

        $schoolCount = (int) $pdo->query("SELECT COUNT(*) FROM schools WHERE school_name = 'ABC University'")->fetchColumn();
        $this->assertSame(1, $schoolCount);
    }

    public function testMissingSchoolNameReturnsError(): void
    {
        $result = handle_upload_request(
            TestDb::pdo(),
            ['school_name' => '', 'school_year' => '2025'],
            ['pdf_file' => $this->fileField()],
            1,
            $this->storageDir
        );

        $this->assertSame('error', $result['status']);
    }

    public function testOversizedFileReturnsError(): void
    {
        $result = handle_upload_request(
            TestDb::pdo(),
            ['school_name' => 'ABC University', 'school_year' => '2025'],
            ['pdf_file' => $this->fileField()],
            1,
            $this->storageDir,
            10 // maxBytes — the tiny fixture is still bigger than 10 bytes
        );

        $this->assertSame('error', $result['status']);
        $this->assertSame('THE FILE EXCEEDS THE MAXIMUM ALLOWED SIZE.', $result['message']);
    }

    public function testDuplicateSchoolYearReturnsDuplicateStatusWithoutConfirm(): void
    {
        $pdo = TestDb::pdo();
        handle_upload_request(
            $pdo,
            ['school_name' => 'ABC University', 'school_year' => '2025'],
            ['pdf_file' => $this->fileField()],
            1,
            $this->storageDir
        );

        $result = handle_upload_request(
            $pdo,
            ['school_name' => 'ABC University', 'school_year' => '2025'],
            ['pdf_file' => $this->fileField()],
            1,
            $this->storageDir
        );

        $this->assertSame('duplicate', $result['status']);
        $this->assertSame(1, $result['existing_upload']['id']);
    }

    public function testConfirmDuplicateProceedsAndIncrementsUploadNumber(): void
    {
        $pdo = TestDb::pdo();
        handle_upload_request(
            $pdo,
            ['school_name' => 'ABC University', 'school_year' => '2025'],
            ['pdf_file' => $this->fileField()],
            1,
            $this->storageDir
        );

        $result = handle_upload_request(
            $pdo,
            ['school_name' => 'ABC University', 'school_year' => '2025', 'confirm_duplicate' => '1'],
            ['pdf_file' => $this->fileField()],
            1,
            $this->storageDir
        );

        $this->assertSame('success', $result['status']);
        $this->assertSame(2, $result['upload']['upload_number']);
        $this->assertFileExists($this->storageDir . '/ABC_UNIVERSITY_2025_2.pdf');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/includes/HandleUploadRequestTest.php`
Expected: FAIL — `handle_upload_request()` undefined.

- [ ] **Step 3: Append to includes/uploads.php**

```php

function handle_upload_request(PDO $pdo, array $post, array $files, int $uploadedBy, string $storageDir, int $maxBytes = 20971520): array
{
    $schoolName = trim((string) ($post['school_name'] ?? ''));
    $schoolYear = trim((string) ($post['school_year'] ?? ''));
    $confirmDuplicate = !empty($post['confirm_duplicate']);

    if ($schoolName === '' || $schoolYear === '') {
        return ['status' => 'error', 'message' => 'SCHOOL NAME AND SCHOOL YEAR ARE REQUIRED.'];
    }

    $file = $files['pdf_file'] ?? null;
    if (!is_array($file) || ($file['tmp_name'] ?? '') === '') {
        return ['status' => 'error', 'message' => 'PLEASE SELECT A PDF FILE TO UPLOAD.'];
    }

    $size = filesize($file['tmp_name']);
    $validation = validate_pdf_file_content($file['tmp_name'], $size === false ? PHP_INT_MAX : $size, $maxBytes);
    if (!$validation['ok']) {
        return ['status' => 'error', 'message' => $validation['error']];
    }

    $school = find_or_create_school($pdo, $schoolName);

    $existing = find_existing_upload($pdo, $school['id'], $schoolYear);
    if ($existing !== null && !$confirmDuplicate) {
        return [
            'status' => 'duplicate',
            'message' => 'A LATIN HONOR LIST FOR THIS SCHOOL AND SCHOOL YEAR ALREADY EXISTS.',
            'existing_upload' => $existing,
        ];
    }

    $uploadNumber = next_upload_number($pdo, $school['id'], $schoolYear);
    $storedFilename = build_stored_filename($schoolName, $schoolYear, $uploadNumber);
    $destination = rtrim($storageDir, '/\\') . DIRECTORY_SEPARATOR . $storedFilename;

    if (!copy($file['tmp_name'], $destination)) {
        return ['status' => 'error', 'message' => 'THE FILE COULD NOT BE SAVED. PLEASE TRY AGAIN.'];
    }

    $upload = create_upload_record($pdo, [
        'school_id' => $school['id'],
        'school_year' => $schoolYear,
        'original_filename' => (string) ($file['name'] ?? $storedFilename),
        'stored_filename' => $storedFilename,
        'file_path' => $destination,
        'file_size' => filesize($destination),
        'file_hash' => hash_file('sha256', $destination),
        'uploaded_by' => $uploadedBy,
        'upload_number' => $uploadNumber,
    ]);

    return [
        'status' => 'success',
        'message' => 'LATIN HONOR LIST UPLOADED SUCCESSFULLY.',
        'upload' => $upload,
    ];
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php vendor/bin/phpunit tests/includes/HandleUploadRequestTest.php`
Expected: `OK (5 tests, ...)`

- [ ] **Step 5: Run the full suite**

Run: `php vendor/bin/phpunit`
Expected: all tests (Phase 1 + Phase 2 so far) pass.

- [ ] **Step 6: Commit**

```bash
git add includes/uploads.php tests/includes/HandleUploadRequestTest.php
git commit -m "feat: add upload orchestration (validate, find-or-create school, duplicate check, store, record)"
```

---

## Task 9: api/upload_pdf.php + manual verification

**Files:**
- Create: `api/upload_pdf.php`

**Interfaces:**
- Consumes: `require_role()` (Phase 1 `includes/auth.php`), `csrf_verify()` (Phase 1), `handle_upload_request()` (Task 8).

- [ ] **Step 1: Write api/upload_pdf.php**

```php
<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pdf_files.php';
require_once __DIR__ . '/../includes/schools.php';
require_once __DIR__ . '/../includes/uploads.php';

require_role('administrator');

header('Content-Type: application/json');

if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'YOUR SESSION EXPIRED. PLEASE TRY AGAIN.']);
    exit;
}

$file = $_FILES['pdf_file'] ?? null;
if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
    echo json_encode(['status' => 'error', 'message' => 'PLEASE SELECT A VALID PDF FILE TO UPLOAD.']);
    exit;
}

$currentUser = current_user();
$result = handle_upload_request(get_pdo(), $_POST, $_FILES, (int) $currentUser['id'], __DIR__ . '/../storage/pdfs');

echo json_encode($result);
```

- [ ] **Step 2: Manual verification — reject non-CLI, non-multipart, and unauthenticated requests**

With Apache/XAMPP running (or the PHP built-in server, see Task 12's note on `-d upload_max_filesize`), and no session cookie sent:

```bash
curl -s -o /dev/null -w "%{http_code}\n" -X POST http://127.0.0.1:8899/api/upload_pdf.php
```

Expected: `302` (redirected to login by `require_role()`'s `require_login()` path) — or if using a role-agnostic quick check, confirm it does NOT return a raw PHP error or 200 with upload behavior.

- [ ] **Step 3: Manual verification — successful upload via curl with a real session**

This requires a logged-in session cookie. Use a cookie jar:

```bash
curl -s -c cookies.txt -b cookies.txt -d "csrf_token=x" http://127.0.0.1:8899/login.php > /dev/null
# (Real login needs the CSRF token from the login page's hidden field — do this step in the browser instead if curl scripting the login is too fragile; the important check is that a session-authenticated multipart POST to upload_pdf.php with a small real PDF returns {"status":"success",...})
```

Document the actual result you observed (status code and JSON body) in this task's report — if scripting the full login via curl proves too fragile, note that and defer full-flow verification to Task 12's browser-based check, which exercises this same endpoint through the real UI.

- [ ] **Step 4: Commit**

```bash
git add api/upload_pdf.php
git commit -m "feat: add upload_pdf.php API endpoint"
```

---

## Task 10: api/serve_pdf.php + manual verification

**Files:**
- Create: `api/serve_pdf.php`

**Interfaces:**
- Consumes: `require_login()` (Phase 1), `get_upload_by_id()` (Task 7).

- [ ] **Step 1: Write api/serve_pdf.php**

```php
<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/uploads.php';

require_login();

$id = (int) ($_GET['id'] ?? 0);
$upload = $id > 0 ? get_upload_by_id(get_pdo(), $id) : null;

if ($upload === null || !is_file($upload['file_path'])) {
    http_response_code(404);
    die('THE REQUESTED FILE COULD NOT BE FOUND.');
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . basename($upload['stored_filename']) . '"');
header('Content-Length: ' . filesize($upload['file_path']));
readfile($upload['file_path']);
```

- [ ] **Step 2: Manual verification — unauthenticated access redirects**

```bash
curl -sI http://127.0.0.1:8899/api/serve_pdf.php?id=1
```

Expected: `302` redirect to `/login.php` (from `require_login()`), not a raw file or error.

- [ ] **Step 3: Manual verification — unknown id returns friendly 404**

With an authenticated session (see Task 12 for full browser-based login), request `api/serve_pdf.php?id=999999`.
Expected: HTTP 404, body `THE REQUESTED FILE COULD NOT BE FOUND.` — not a raw PHP warning about `is_file()` on a null path.

- [ ] **Step 4: Commit**

```bash
git add api/serve_pdf.php
git commit -m "feat: add serve_pdf.php secure PDF streaming endpoint"
```

---

## Task 11: Reusable toast + modal JS components

**Files:**
- Create: `assets/js/toast.js`
- Create: `assets/js/modal.js`
- Modify: `includes/footer.php`
- Modify: `tailwind.config.js`

**Interfaces:**
- Produces: global `showToast(type, message)` function (`type` one of `'success'|'error'|'warning'|'info'`) and global `showModal({title, bodyHtml, buttons})` function returning a `close()` callback (`buttons` is an array of `{label, className, onClick}`; `onClick` receives `close` as its argument and is responsible for calling it if the modal should close). Task 12's `assets/js/upload.js` calls both.

- [ ] **Step 1: Write assets/js/toast.js**

```js
function showToast(type, message) {
    var container = document.getElementById('toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toast-container';
        container.className = 'fixed top-4 right-4 z-50 flex flex-col gap-2 w-full max-w-sm';
        document.body.appendChild(container);
    }

    var colors = {
        success: 'border-green-500/30 bg-green-500/10 text-green-400',
        error: 'border-red-500/30 bg-red-500/10 text-red-400',
        warning: 'border-yellow-500/30 bg-yellow-500/10 text-yellow-400',
        info: 'border-blue-500/30 bg-blue-500/10 text-blue-400',
    };
    var colorClass = colors[type] || colors.info;

    var toast = document.createElement('div');
    toast.className = 'rounded-lg border px-4 py-3 text-sm shadow-lg ' + colorClass;
    toast.textContent = message;
    container.appendChild(toast);

    setTimeout(function () {
        toast.remove();
    }, 5000);
}
```

- [ ] **Step 2: Write assets/js/modal.js**

```js
function showModal(config) {
    var overlay = document.createElement('div');
    overlay.className = 'fixed inset-0 z-40 flex items-center justify-center bg-black/60 px-4';

    var box = document.createElement('div');
    box.className = 'w-full max-w-md rounded-xl border border-white/10 bg-surface-900 p-6';

    var heading = document.createElement('h2');
    heading.className = 'text-base font-semibold text-white mb-3';
    heading.textContent = config.title;
    box.appendChild(heading);

    var body = document.createElement('div');
    body.className = 'text-sm text-gray-300 mb-5';
    body.innerHTML = config.bodyHtml;
    box.appendChild(body);

    var buttonRow = document.createElement('div');
    buttonRow.className = 'flex justify-end gap-2';

    function close() {
        overlay.remove();
        document.removeEventListener('keydown', onKeydown);
    }

    function onKeydown(event) {
        if (event.key === 'Escape') {
            close();
        }
    }

    (config.buttons || []).forEach(function (btn) {
        var el = document.createElement('button');
        el.type = 'button';
        el.className = btn.className || 'rounded-lg px-4 py-2 text-sm text-gray-300 hover:bg-white/5';
        el.textContent = btn.label;
        el.addEventListener('click', function () {
            if (btn.onClick) {
                btn.onClick(close);
            } else {
                close();
            }
        });
        buttonRow.appendChild(el);
    });

    box.appendChild(buttonRow);
    overlay.appendChild(box);
    document.body.appendChild(overlay);
    document.addEventListener('keydown', onKeydown);

    return close;
}
```

- [ ] **Step 3: Modify tailwind.config.js's content glob**

`toast.js` and `modal.js` embed Tailwind utility classes directly as JS string literals (e.g. `bg-black/60`, `text-gray-300`, `border-green-500/30`). Tailwind's build only generates CSS for classes it finds by scanning the files listed in `content` — which currently only matches `.php` files. Without this change, any class that appears *only* inside these two JS files (not already present verbatim in some `.php` file) would silently be missing from `assets/css/app.css`, and the modal/toast would render unstyled.

Current `tailwind.config.js`:

```js
module.exports = {
  content: ['./**/*.php'],
```

New:

```js
module.exports = {
  content: ['./**/*.php', './assets/js/**/*.js'],
```

(Leave the rest of the file — the `theme.extend.colors` block and `plugins: []` — unchanged.)

- [ ] **Step 4: Modify includes/footer.php**

Current content:

```php
<script src="<?= e(APP_BASE_URL) ?>/assets/js/sidebar.js"></script>
</body>
</html>
```

New content:

```php
<script src="<?= e(APP_BASE_URL) ?>/assets/js/sidebar.js"></script>
<script src="<?= e(APP_BASE_URL) ?>/assets/js/toast.js"></script>
<script src="<?= e(APP_BASE_URL) ?>/assets/js/modal.js"></script>
</body>
</html>
```

- [ ] **Step 5: Rebuild Tailwind**

Run: `npm run build:css`
Expected: `assets/css/app.css` grows to include the classes referenced only in `toast.js`/`modal.js` (e.g. run `grep -c "bg-black/60" assets/css/app.css` — expect `1`, not `0`, confirming Step 3's content-glob change actually picked up the JS files).

- [ ] **Step 6: Commit**

```bash
git add assets/js/toast.js assets/js/modal.js includes/footer.php tailwind.config.js assets/css/app.css
git commit -m "feat: add reusable toast and modal JS components"
```

(No PHPUnit test — this is pure client-side JS with no PHP surface; it's exercised by Task 12's browser verification.)

---

## Task 12: admin/upload.php + assets/js/upload.js + manual verification

**Files:**
- Create: `admin/upload.php`
- Create: `assets/js/upload.js`

**Interfaces:**
- Consumes: `require_role()`, `csrf_token()` (Phase 1 `includes/auth.php`), `get_active_school_names()` (Task 4), `e()`/`e_upper()` (Phase 1), `showToast()`/`showModal()` (Task 11), the JSON contract from `api/upload_pdf.php` (Task 9): `{status: 'success'|'duplicate'|'error', message, existing_upload?, upload?}`.

- [ ] **Step 1: Write admin/upload.php**

```php
<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/schools.php';

require_role('administrator');

$pdo = get_pdo();
$schoolNames = get_active_school_names($pdo);
$currentUser = current_user();
$currentPage = 'upload';
$csrfToken = csrf_token();

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main class="lg:ml-64 min-h-screen bg-surface-950">
    <div class="p-6 lg:p-8 max-w-2xl">
        <h1 class="text-xl font-semibold text-white mb-6">UPLOAD LATIN HONOR LIST</h1>

        <form id="upload-form" action="<?= e(APP_BASE_URL) ?>/api/upload_pdf.php" data-view-upload-url="<?= e(APP_BASE_URL) ?>/admin/view_upload.php" method="post" enctype="multipart/form-data" class="space-y-5">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

            <div>
                <label class="block text-xs text-gray-400 mb-1" for="school_name">SCHOOL NAME</label>
                <input list="school-names" id="school_name" name="school_name" required
                       class="w-full rounded-lg bg-surface-800 border border-white/10 px-3 py-2 text-sm focus:outline-none focus:border-accent-500">
                <datalist id="school-names">
                    <?php foreach ($schoolNames as $name): ?>
                        <option value="<?= e($name) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>

            <div>
                <label class="block text-xs text-gray-400 mb-1" for="school_year">SCHOOL YEAR</label>
                <input type="text" id="school_year" name="school_year" required placeholder="e.g. 2025"
                       class="w-full rounded-lg bg-surface-800 border border-white/10 px-3 py-2 text-sm focus:outline-none focus:border-accent-500">
            </div>

            <div>
                <label class="block text-xs text-gray-400 mb-1">PDF FILE</label>
                <div id="dropzone" class="cursor-pointer rounded-xl border-2 border-dashed border-white/15 px-6 py-10 text-center text-sm text-gray-400 hover:border-accent-500 transition-colors">
                    <p>DRAG &amp; DROP PDF HERE</p>
                    <p class="text-xs mt-1">OR CLICK TO BROWSE</p>
                    <p class="text-xs text-gray-600 mt-2">PDF ONLY, UP TO 20 MB</p>
                </div>
                <input type="file" id="pdf_file" name="pdf_file" accept="application/pdf" required class="hidden">
                <p id="file-info" class="text-xs text-gray-400 mt-2"></p>
            </div>

            <button type="submit" class="w-full rounded-lg bg-accent-600 hover:bg-accent-500 text-white text-sm font-medium py-2 transition-colors">UPLOAD</button>
        </form>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
<script src="<?= e(APP_BASE_URL) ?>/assets/js/upload.js"></script>
```

- [ ] **Step 2: Write assets/js/upload.js**

```js
document.addEventListener('DOMContentLoaded', function () {
    var dropzone = document.getElementById('dropzone');
    var fileInput = document.getElementById('pdf_file');
    var fileInfo = document.getElementById('file-info');
    var form = document.getElementById('upload-form');

    if (!dropzone || !fileInput || !form) {
        return;
    }

    function showFileInfo(file) {
        if (!file) {
            fileInfo.textContent = '';
            return;
        }
        var sizeKb = Math.round(file.size / 1024);
        fileInfo.textContent = file.name + ' (' + sizeKb + ' KB)';
    }

    dropzone.addEventListener('click', function () {
        fileInput.click();
    });

    dropzone.addEventListener('dragover', function (event) {
        event.preventDefault();
        dropzone.classList.add('border-accent-500');
    });

    dropzone.addEventListener('dragleave', function () {
        dropzone.classList.remove('border-accent-500');
    });

    dropzone.addEventListener('drop', function (event) {
        event.preventDefault();
        dropzone.classList.remove('border-accent-500');
        if (event.dataTransfer.files.length > 0) {
            fileInput.files = event.dataTransfer.files;
            showFileInfo(fileInput.files[0]);
        }
    });

    fileInput.addEventListener('change', function () {
        showFileInfo(fileInput.files[0]);
    });

    function submitForm(extraFields) {
        var formData = new FormData(form);
        if (extraFields) {
            Object.keys(extraFields).forEach(function (key) {
                formData.set(key, extraFields[key]);
            });
        }

        fetch(form.action, {
            method: 'POST',
            body: formData,
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                if (data.status === 'success') {
                    showToast('success', data.message);
                    form.reset();
                    showFileInfo(null);
                } else if (data.status === 'duplicate') {
                    showModal({
                        title: 'POSSIBLE DUPLICATE UPLOAD',
                        bodyHtml: data.message + '<br><br>Existing file: ' + data.existing_upload.original_filename,
                        buttons: [
                            {
                                label: 'CANCEL',
                                className: 'rounded-lg px-4 py-2 text-sm text-gray-300 hover:bg-white/5',
                            },
                            {
                                label: 'VIEW EXISTING',
                                className: 'rounded-lg px-4 py-2 text-sm text-gray-300 hover:bg-white/5',
                                onClick: function (close) {
                                    close();
                                    window.location.href = form.dataset.viewUploadUrl + '?id=' + data.existing_upload.id;
                                },
                            },
                            {
                                label: 'UPLOAD ANYWAY',
                                className: 'rounded-lg px-4 py-2 text-sm bg-accent-600 hover:bg-accent-500 text-white',
                                onClick: function (close) {
                                    close();
                                    submitForm({ confirm_duplicate: '1' });
                                },
                            },
                        ],
                    });
                } else {
                    showToast('error', data.message);
                }
            })
            .catch(function () {
                showToast('error', 'THE UPLOAD COULD NOT BE COMPLETED. PLEASE TRY AGAIN.');
            });
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        submitForm(null);
    });
});
```

- [ ] **Step 3: Rebuild Tailwind**

Run: `npm run build:css`

- [ ] **Step 4: Run the full PHPUnit suite**

Run: `php vendor/bin/phpunit`
Expected: all tests pass (no PHP logic changed in this task, but confirms nothing regressed).

- [ ] **Step 5: Commit**

```bash
git add admin/upload.php assets/js/upload.js assets/css/app.css
git commit -m "feat: add upload page with drag-and-drop and duplicate-warning flow"
```

- [ ] **Step 6: Manual end-to-end browser verification**

With Apache/XAMPP running (or the PHP built-in server pattern from Phase 1 — `php -d auto_prepend_file=... -S 127.0.0.1:8899 -t .` with a prepend file setting `APP_BASE_URL` to an empty string, plus `-d upload_max_filesize=25M -d post_max_size=26M` so the 20 MB limit isn't hit by PHP's own defaults first), and logged in as the seeded administrator:

1. Visit `admin/upload.php` → SCHOOL NAME field shows a browser-native autocomplete dropdown (empty on a fresh DB, since no schools exist yet).
2. Type a brand-new school name (e.g. "TEST UNIVERSITY"), enter a school year (e.g. "2025"), drag `tests/fixtures/sample.pdf` onto the dropzone (or click to browse and select it) → filename + size shown under the dropzone.
3. Click UPLOAD → success toast appears ("LATIN HONOR LIST UPLOADED SUCCESSFULLY."), form resets.
4. Repeat the exact same school name + year with the same file → a modal appears: "POSSIBLE DUPLICATE UPLOAD" with CANCEL / VIEW EXISTING / UPLOAD ANYWAY buttons.
5. Click CANCEL → modal closes, nothing else happens.
6. Repeat step 4, this time click UPLOAD ANYWAY → success toast appears, and (verify via a quick `SELECT stored_filename FROM latin_honor_uploads` or the dashboard/view page in Task 13/14) the second file is stored as `..._2.pdf`.
7. Try uploading a non-PDF file (rename `tests/fixtures/not-a-pdf.txt` to `.pdf` locally, or just attempt to select the `.txt` file directly — the `accept="application/pdf"` attribute may filter it from the OS picker, so also test via drag-and-drop which bypasses that filter) → error toast: "ONLY PDF FILES ARE ACCEPTED."
8. Resize to mobile width → confirm the form and dropzone stack single-column and remain usable (Tailwind's default block layout already does this; just confirm nothing overflows horizontally).

Document what you observed for each of the 8 checks in this task's report.

---

## Task 13: admin/view_upload.php

**Files:**
- Create: `admin/view_upload.php`

**Interfaces:**
- Consumes: `require_login()` (Phase 1), `get_upload_by_id()` (Task 7), `e()`/`e_upper()` (Phase 1).

- [ ] **Step 1: Write admin/view_upload.php**

```php
<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/uploads.php';

require_login();

$uploadId = (int) ($_GET['id'] ?? 0);
$upload = $uploadId > 0 ? get_upload_by_id(get_pdo(), $uploadId) : null;

$currentUser = current_user();
$currentPage = 'upload';

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main class="lg:ml-64 min-h-screen bg-surface-950">
    <div class="p-6 lg:p-8 max-w-2xl">
        <h1 class="text-xl font-semibold text-white mb-6">UPLOAD DETAILS</h1>

        <?php if ($upload === null): ?>
            <div class="rounded-xl border border-white/10 bg-surface-900 p-6 text-center text-gray-400">
                <p class="text-sm">NO UPLOAD RECORD FOUND.</p>
            </div>
        <?php else: ?>
            <div class="rounded-xl border border-white/10 bg-surface-900 p-6 space-y-4">
                <div>
                    <p class="text-xs text-gray-500">SCHOOL</p>
                    <p class="text-sm text-white"><?= e_upper($upload['school_name']) ?></p>
                </div>
                <div>
                    <p class="text-xs text-gray-500">SCHOOL YEAR</p>
                    <p class="text-sm text-white"><?= e($upload['school_year']) ?></p>
                </div>
                <div>
                    <p class="text-xs text-gray-500">FILE NAME</p>
                    <p class="text-sm text-white"><?= e($upload['original_filename']) ?></p>
                </div>
                <div>
                    <p class="text-xs text-gray-500">DATE UPLOADED</p>
                    <p class="text-sm text-white"><?= e_upper(date('F j, Y', strtotime($upload['uploaded_at']))) ?></p>
                </div>
                <div>
                    <p class="text-xs text-gray-500">RECORDS</p>
                    <p class="text-sm text-white"><?= (int) $upload['records_extracted'] ?> RECORDS — <?= e_upper($upload['extraction_status']) ?></p>
                </div>
                <a href="<?= e(APP_BASE_URL) ?>/api/serve_pdf.php?id=<?= (int) $upload['id'] ?>" target="_blank"
                   class="inline-block rounded-lg bg-accent-600 hover:bg-accent-500 text-white text-sm font-medium px-4 py-2 transition-colors">VIEW PDF</a>
            </div>
        <?php endif; ?>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
```

- [ ] **Step 2: Manual verification**

With a real upload created (from Task 12's verification), visit `admin/view_upload.php?id=<that upload's id>` in the browser: confirm SCHOOL/SCHOOL YEAR/FILE NAME/DATE UPLOADED/RECORDS render correctly, and clicking VIEW PDF opens the actual PDF (via `api/serve_pdf.php`) in a new tab. Then visit `admin/view_upload.php?id=999999`: confirm "NO UPLOAD RECORD FOUND." renders instead of a blank page or PHP error.

- [ ] **Step 3: Commit**

```bash
git add admin/view_upload.php
git commit -m "feat: add upload details view page"
```

---

## Task 14: Dashboard LATEST UPLOAD table

**Files:**
- Modify: `admin/dashboard.php:1-14` (require + variable setup) and append a new section after the stats grid, before `</main>`.

**Interfaces:**
- Consumes: `get_recent_uploads()` (Task 7).

- [ ] **Step 1: Modify admin/dashboard.php's requires and variables**

Current (lines 1-11):

```php
<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();

$stats = get_dashboard_stats(get_pdo());
$currentUser = current_user();
$currentPage = 'dashboard';
```

New:

```php
<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/uploads.php';

require_login();

$pdo = get_pdo();
$stats = get_dashboard_stats($pdo);
$recentUploads = get_recent_uploads($pdo, 5);
$currentUser = current_user();
$currentPage = 'dashboard';
```

- [ ] **Step 2: Insert the LATEST UPLOAD section**

Current (lines 38-40, the end of the stats grid and `</main>`):

```php
        </div>
    </div>
</main>
```

New:

```php
        </div>

        <div class="mt-8">
            <h2 class="text-sm font-semibold text-gray-400 mb-3 tracking-wide">LATEST UPLOAD</h2>
            <div class="bg-surface-900 border border-white/10 rounded-xl overflow-x-auto">
                <?php if (empty($recentUploads)): ?>
                    <p class="text-sm text-gray-500 p-6 text-center">NO UPLOADS YET.</p>
                <?php else: ?>
                    <table class="w-full text-sm text-left">
                        <thead>
                            <tr class="text-xs text-gray-500 border-b border-white/10">
                                <th class="px-4 py-3">SCHOOL</th>
                                <th class="px-4 py-3">YEAR</th>
                                <th class="px-4 py-3">FILE NAME</th>
                                <th class="px-4 py-3">DATE UPLOADED</th>
                                <th class="px-4 py-3">RECORDS EXTRACTED</th>
                                <th class="px-4 py-3">ACTION</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentUploads as $upload): ?>
                                <tr class="border-b border-white/5 hover:bg-white/5">
                                    <td class="px-4 py-3"><?= e_upper($upload['school_name']) ?></td>
                                    <td class="px-4 py-3"><?= e($upload['school_year']) ?></td>
                                    <td class="px-4 py-3"><?= e($upload['original_filename']) ?></td>
                                    <td class="px-4 py-3"><?= e_upper(date('F j, Y', strtotime($upload['uploaded_at']))) ?></td>
                                    <td class="px-4 py-3"><?= (int) $upload['records_extracted'] ?> RECORDS</td>
                                    <td class="px-4 py-3">
                                        <a href="<?= e(APP_BASE_URL) ?>/admin/view_upload.php?id=<?= (int) $upload['id'] ?>" class="text-accent-400 hover:underline">VIEW</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>
```

- [ ] **Step 3: Run the full PHPUnit suite**

Run: `php vendor/bin/phpunit`
Expected: all tests pass.

- [ ] **Step 4: Manual verification**

Visit `admin/dashboard.php` in the browser: confirm the LATEST UPLOAD table shows the upload(s) created during Task 12/13's manual testing, most recent first, and clicking VIEW navigates to `admin/view_upload.php?id=...` correctly. Also confirm TOTAL SCHOOLS and TOTAL PDF LISTS stat cards now show non-zero counts reflecting the real data (no code change needed for this — `get_dashboard_stats()` already queries live data).

- [ ] **Step 5: Commit**

```bash
git add admin/dashboard.php
git commit -m "feat: add LATEST UPLOAD table to dashboard"
```

---

## Phase 2 exit criteria

- `php vendor/bin/phpunit` passes in full (Phase 1 + Phase 2 tests).
- An administrator can upload a PDF for a brand-new school (created inline), see it succeed, attempt the same school+year again and get a duplicate warning, choose UPLOAD ANYWAY and see `upload_number` increment, and view both the PDF and its metadata afterward.
- `storage/pdfs/*.pdf` files are not directly downloadable by URL — only through `api/serve_pdf.php`.
- The dashboard's TOTAL SCHOOLS, TOTAL PDF LISTS, and LATEST UPLOAD all reflect real data with no manual DB manipulation.
- Phase 3 (Extraction + Review) is the next plan, written after this phase is reviewed and confirmed working.
