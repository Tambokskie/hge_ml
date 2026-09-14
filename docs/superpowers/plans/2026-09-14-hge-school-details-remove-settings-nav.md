# School Details + Remove + Settings Nav Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add ADDRESS/CAMPUS fields to schools (with composite name+campus uniqueness), a guarded hard-delete (REMOVE) gated on zero uploads/graduates and admin password re-verification, a confirm-before-save review step on the school create/edit modals, and a collapsible SETTINGS sidebar submenu grouping the three admin-only pages.

**Architecture:** Extends the already-merged Schools admin CRUD (`admin/schools.php`, `includes/schools.php`, `api/create_school.php`/`api/update_school.php`) in place, following the exact thin-API-wrapper + data-layer-function pattern already established in this project. One new function (`remove_school()`), one new endpoint (`api/remove_school.php`), and a JS-only review-modal step layered onto the existing `showModal()` primitive — no new UI framework or client-side state library.

**Tech Stack:** PHP 8.2 (PDO, prepared statements only), MySQL/MariaDB, vanilla JS (no build step), Tailwind CSS (via existing classes/patterns), PHPUnit 10.

**Spec:** `docs/superpowers/specs/2026-09-12-hge-database-design.md`, section 16 (this addendum) — read alongside section 15, which this extends.

## Global Constraints

- No bulk operations: checkboxes are single-select only (checking one row unchecks any other, enforced in JS); REMOVE always acts on exactly one school.
- REMOVE must never destroy a school that has any uploads or graduates — `remove_school()` checks both counts and blocks (returns an error, deletes nothing) if either is greater than zero, before ever attempting a DELETE.
- ADDRESS is required (non-blank after trim) on create and edit; CAMPUS is optional (blank allowed, stored as `''`, never `NULL`).
- Uniqueness is composite: `schools.school_name` + `schools.campus` together, not `school_name` alone. Two schools may share a name if campus differs.
- REMOVE requires the acting admin to re-enter their own current password. Verify it with a fresh `SELECT password FROM users WHERE id = :id` + `password_verify()` — never from session data (`current_user()`'s array has no password field — `attempt_login()` strips it before it reaches the session), and never by reusing `attempt_login()` itself (that function looks up by username, for the unauthenticated login form; this is a re-authentication of an already-authenticated admin, by id).
- CREATE SCHOOL and EDIT SCHOOL both show a review-confirmation modal (entered fields, read-only, CONFIRM/GO BACK) between form submission and the actual API call. Reuse the existing `showModal()` helper (`assets/js/modal.js`) — no new modal primitive.
- Every new/modified admin API endpoint: `require_role('administrator')` first, `csrf_verify($_POST['csrf_token'] ?? null)` returning HTTP 400 JSON before any DB work, delegate to the data layer, generic 500 JSON on any `\Throwable`.
- A new `activity_log.action` ENUM value needs three steps, all required: the `database/schema.sql` CREATE TABLE edit (only affects a fresh test DB via `TestDb::resetSchema()`), a live `ALTER TABLE ... MODIFY COLUMN action ENUM(...)` against the already-existing dev `hge_masterlist` database (performed manually in Task 5), and an entry in `admin/activity_log.php`'s `$actionTypes` filter array (the exact step the previous addendum's final review caught as missed).
- `php` is not on PATH in this environment — every test run uses the full path `C:/xampp/php/php.exe`.
- All user-facing strings stay UPPERCASE, matching every existing message in this project.
- All DB access uses PDO prepared statements — never string-interpolated SQL in application code (raw SQL is fine only inside test setup, matching existing test files).

---

### Task 1: Schema + data layer (`schools.php`, `remove_school()`, tests)

**Files:**
- Modify: `database/schema.sql`
- Modify: `includes/schools.php`
- Modify: `tests/includes/SchoolsTest.php`

**Interfaces:**
- Consumes: nothing new from other tasks (this is the foundation task).
- Produces (for Tasks 2-5 to consume):
  - `create_school(PDO $pdo, string $name, string $address = '', string $campus = ''): array` — extended signature; defaults keep any not-yet-updated caller from a fatal `ArgumentCountError` between this task's commit and Task 2's (it will instead get a normal `SCHOOL ADDRESS IS REQUIRED.` validation error, which is a safe, non-crashing intermediate state).
  - `rename_school(PDO $pdo, int $id, string $newName, string $address = '', string $campus = ''): array` — same default-value reasoning.
  - `remove_school(PDO $pdo, int $id, int $requestingUserId, string $password): array` — new. Returns `['status' => 'success', 'message' => 'SCHOOL REMOVED.', 'school_name' => string]` on success (the name is returned because the row is gone by the time the caller wants to log it), or `['status' => 'error', 'message' => string]` on any failure path (wrong password, not found, has data).
  - `get_all_schools_with_counts(PDO $pdo): array` — each row now also carries `address` and `campus` string keys, in addition to the existing `id`, `school_name`, `status`, `upload_count`, `graduate_count`.

- [ ] **Step 1: Edit `database/schema.sql`**

Change the `schools` table (currently lines 16-23) to:

```sql
CREATE TABLE schools (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_name VARCHAR(255) NOT NULL,
  address VARCHAR(500) NOT NULL DEFAULT '',
  campus VARCHAR(255) NOT NULL DEFAULT '',
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_school_name_campus (school_name, campus)
) ENGINE=InnoDB;
```

Note: `address` gets `DEFAULT ''` even though the application layer treats it as required. This is deliberate — `find_or_create_school()` (unchanged, used by the upload form's inline autocomplete) inserts only `school_name` and must keep working unmodified; `create_school()`/`rename_school()` enforce "required" at the PHP validation layer for the admin-driven flows this addendum adds. Existing tests (`tests/SchemaTest.php` and others) also `INSERT INTO schools (school_name) VALUES (...)` directly and must keep passing unmodified.

Change the `activity_log` table's `action` ENUM (currently line 69) to add `school_removed`:

```sql
  action ENUM('login','logout','pdf_upload','pdf_delete','record_created','record_updated','record_removed','search','user_created','user_updated','school_created','school_updated','school_removed') NOT NULL,
```

- [ ] **Step 2: Write the new/updated tests in `tests/includes/SchoolsTest.php`**

Replace the existing `testCreateSchoolInsertsNewSchool` through `testSetSchoolStatusRejectsInvalidStatus` methods (everything from `create_school`/`rename_school` onward — `set_school_status` tests are untouched, keep them as-is) with:

```php
    public function testCreateSchoolInsertsNewSchool(): void
    {
        $pdo = TestDb::pdo();

        $result = create_school($pdo, 'New School', '123 Main St', 'Main Campus');

        $this->assertSame('success', $result['status']);
        $this->assertArrayHasKey('id', $result);
        $count = (int) $pdo->query("SELECT COUNT(*) FROM schools WHERE school_name = 'New School'")->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testCreateSchoolRejectsEmptyName(): void
    {
        $result = create_school(TestDb::pdo(), '   ', '123 Main St', '');
        $this->assertSame('error', $result['status']);
    }

    public function testCreateSchoolRejectsEmptyAddress(): void
    {
        $result = create_school(TestDb::pdo(), 'ABC University', '   ', '');
        $this->assertSame('error', $result['status']);
    }

    public function testCreateSchoolAcceptsEmptyCampus(): void
    {
        $result = create_school(TestDb::pdo(), 'ABC University', '123 Main St', '');
        $this->assertSame('success', $result['status']);
    }

    public function testCreateSchoolRejectsDuplicateNameAndCampus(): void
    {
        $pdo = TestDb::pdo();
        create_school($pdo, 'ABC University', '123 Main St', 'Main Campus');

        $result = create_school($pdo, 'ABC University', '456 Other St', 'Main Campus');

        $this->assertSame('error', $result['status']);
        $count = (int) $pdo->query("SELECT COUNT(*) FROM schools WHERE school_name = 'ABC University'")->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testCreateSchoolAllowsSameNameDifferentCampus(): void
    {
        $pdo = TestDb::pdo();
        $first = create_school($pdo, 'ABC University', '123 Main St', 'Main Campus');
        $second = create_school($pdo, 'ABC University', '456 Other St', 'Tanauan Campus');

        $this->assertSame('success', $first['status']);
        $this->assertSame('success', $second['status']);
        $count = (int) $pdo->query("SELECT COUNT(*) FROM schools WHERE school_name = 'ABC University'")->fetchColumn();
        $this->assertSame(2, $count);
    }

    public function testRenameSchoolChangesName(): void
    {
        $pdo = TestDb::pdo();
        $created = create_school($pdo, 'Old Name', '123 Main St', '');

        $result = rename_school($pdo, $created['id'], 'New Name', '123 Main St', '');

        $this->assertSame('success', $result['status']);
        $name = $pdo->query("SELECT school_name FROM schools WHERE id = {$created['id']}")->fetchColumn();
        $this->assertSame('New Name', $name);
    }

    public function testRenameSchoolRejectsEmptyAddress(): void
    {
        $pdo = TestDb::pdo();
        $created = create_school($pdo, 'ABC University', '123 Main St', '');

        $result = rename_school($pdo, $created['id'], 'ABC University', '   ', '');

        $this->assertSame('error', $result['status']);
    }

    public function testRenameSchoolRejectsDuplicateNameAndCampus(): void
    {
        $pdo = TestDb::pdo();
        create_school($pdo, 'ABC University', '123 Main St', 'Main Campus');
        $created = create_school($pdo, 'XYZ College', '456 Other St', '');

        $result = rename_school($pdo, $created['id'], 'ABC University', '456 Other St', 'Main Campus');

        $this->assertSame('error', $result['status']);
        $name = $pdo->query("SELECT school_name FROM schools WHERE id = {$created['id']}")->fetchColumn();
        $this->assertSame('XYZ College', $name);
    }

    public function testRenameSchoolAllowsSameNameDifferentCampus(): void
    {
        $pdo = TestDb::pdo();
        create_school($pdo, 'ABC University', '123 Main St', 'Main Campus');
        $created = create_school($pdo, 'XYZ College', '456 Other St', '');

        $result = rename_school($pdo, $created['id'], 'ABC University', '456 Other St', 'Tanauan Campus');

        $this->assertSame('success', $result['status']);
    }

    public function testSetSchoolStatusTogglesStatus(): void
    {
        $pdo = TestDb::pdo();
        $created = create_school($pdo, 'ABC University', '123 Main St', '');

        $result = set_school_status($pdo, $created['id'], 'disabled');

        $this->assertSame('success', $result['status']);
        $status = $pdo->query("SELECT status FROM schools WHERE id = {$created['id']}")->fetchColumn();
        $this->assertSame('disabled', $status);
    }

    public function testSetSchoolStatusRejectsInvalidStatus(): void
    {
        $result = set_school_status(TestDb::pdo(), 1, 'archived');
        $this->assertSame('error', $result['status']);
    }

    public function testRemoveSchoolBlocksWhenUploadsExist(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO users (name, username, email, password, role) VALUES ('Admin', 'admin', 'a@a.com', '" . password_hash('secret123', PASSWORD_DEFAULT) . "', 'administrator')");
        $school = create_school($pdo, 'ABC University', '123 Main St', '');
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number)
             VALUES ({$school['id']}, '2025', 'a.pdf', 'ABC_2025_1.pdf', '/x/a.pdf', 10, SHA2('a', 256), 1, 1)"
        );

        $result = remove_school($pdo, $school['id'], 1, 'secret123');

        $this->assertSame('error', $result['status']);
        $count = (int) $pdo->query("SELECT COUNT(*) FROM schools WHERE id = {$school['id']}")->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testRemoveSchoolBlocksWhenGraduatesExist(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO users (name, username, email, password, role) VALUES ('Admin', 'admin', 'a@a.com', '" . password_hash('secret123', PASSWORD_DEFAULT) . "', 'administrator')");
        $school = create_school($pdo, 'ABC University', '123 Main St', '');
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number)
             VALUES ({$school['id']}, '2025', 'a.pdf', 'ABC_2025_1.pdf', '/x/a.pdf', 10, SHA2('a', 256), 1, 1)"
        );
        $pdo->exec(
            "INSERT INTO latin_honor_graduates (upload_id, school_id, graduate_name, latin_honor, graduation_date, graduation_year)
             VALUES (1, {$school['id']}, 'Juan Dela Cruz', 'CUM LAUDE', '2025-06-15', 2025)"
        );

        $result = remove_school($pdo, $school['id'], 1, 'secret123');

        $this->assertSame('error', $result['status']);
    }

    public function testRemoveSchoolSucceedsWhenEmpty(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO users (name, username, email, password, role) VALUES ('Admin', 'admin', 'a@a.com', '" . password_hash('secret123', PASSWORD_DEFAULT) . "', 'administrator')");
        $school = create_school($pdo, 'ABC University', '123 Main St', '');

        $result = remove_school($pdo, $school['id'], 1, 'secret123');

        $this->assertSame('success', $result['status']);
        $this->assertSame('ABC University', $result['school_name']);
        $count = (int) $pdo->query("SELECT COUNT(*) FROM schools WHERE id = {$school['id']}")->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function testRemoveSchoolRejectsWrongPassword(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO users (name, username, email, password, role) VALUES ('Admin', 'admin', 'a@a.com', '" . password_hash('secret123', PASSWORD_DEFAULT) . "', 'administrator')");
        $school = create_school($pdo, 'ABC University', '123 Main St', '');

        $result = remove_school($pdo, $school['id'], 1, 'wrong-password');

        $this->assertSame('error', $result['status']);
        $count = (int) $pdo->query("SELECT COUNT(*) FROM schools WHERE id = {$school['id']}")->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testRemoveSchoolRejectsNonexistentSchool(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO users (name, username, email, password, role) VALUES ('Admin', 'admin', 'a@a.com', '" . password_hash('secret123', PASSWORD_DEFAULT) . "', 'administrator')");

        $result = remove_school($pdo, 999, 1, 'secret123');

        $this->assertSame('error', $result['status']);
    }
```

Leave `testFindOrCreateSchoolCreatesNewSchool` through `testGetAllSchoolsWithCountsIncludesUploadAndGraduateCounts` (the existing tests before `testCreateSchoolInsertsNewSchool`) completely unchanged — they exercise `find_or_create_school()` and `get_active_school*()`, which are not touched by this task.

- [ ] **Step 3: Run the test file to confirm it fails**

Run: `"/c/xampp/php/php.exe" vendor/bin/phpunit tests/includes/SchoolsTest.php`
Expected: FAIL — `create_school()`/`rename_school()` don't accept the new arguments yet, and `remove_school()` doesn't exist yet.

- [ ] **Step 4: Implement the data-layer changes in `includes/schools.php`**

Replace `create_school()` and `rename_school()` (leave `find_or_create_school()`, `get_active_school_names()`, `get_active_schools_list()`, and `set_school_status()` untouched) with:

```php
function get_all_schools_with_counts(PDO $pdo): array
{
    $sql = "SELECT s.id, s.school_name, s.address, s.campus, s.status,
                   (SELECT COUNT(*) FROM latin_honor_uploads u WHERE u.school_id = s.id) AS upload_count,
                   (SELECT COUNT(*) FROM latin_honor_graduates g WHERE g.school_id = s.id) AS graduate_count
            FROM schools s
            ORDER BY s.school_name ASC";
    $stmt = $pdo->query($sql);
    return $stmt->fetchAll();
}

function create_school(PDO $pdo, string $name, string $address = '', string $campus = ''): array
{
    $name = trim($name);
    $address = trim($address);
    $campus = trim($campus);

    if ($name === '') {
        return ['status' => 'error', 'message' => 'SCHOOL NAME IS REQUIRED.'];
    }

    if (mb_strlen($name) > 255) {
        return ['status' => 'error', 'message' => 'SCHOOL NAME IS TOO LONG.'];
    }

    if ($address === '') {
        return ['status' => 'error', 'message' => 'SCHOOL ADDRESS IS REQUIRED.'];
    }

    if (mb_strlen($address) > 500) {
        return ['status' => 'error', 'message' => 'SCHOOL ADDRESS IS TOO LONG.'];
    }

    if (mb_strlen($campus) > 255) {
        return ['status' => 'error', 'message' => 'CAMPUS IS TOO LONG.'];
    }

    try {
        $stmt = $pdo->prepare('INSERT INTO schools (school_name, address, campus) VALUES (:name, :address, :campus)');
        $stmt->execute(['name' => $name, 'address' => $address, 'campus' => $campus]);
    } catch (\PDOException $e) {
        if ($e->getCode() === '23000') {
            return ['status' => 'error', 'message' => 'A SCHOOL WITH THIS NAME AND CAMPUS ALREADY EXISTS.'];
        }
        throw $e;
    }

    return ['status' => 'success', 'message' => 'SCHOOL CREATED SUCCESSFULLY.', 'id' => (int) $pdo->lastInsertId()];
}

function rename_school(PDO $pdo, int $id, string $newName, string $address = '', string $campus = ''): array
{
    $newName = trim($newName);
    $address = trim($address);
    $campus = trim($campus);

    if ($newName === '') {
        return ['status' => 'error', 'message' => 'SCHOOL NAME IS REQUIRED.'];
    }

    if (mb_strlen($newName) > 255) {
        return ['status' => 'error', 'message' => 'SCHOOL NAME IS TOO LONG.'];
    }

    if ($address === '') {
        return ['status' => 'error', 'message' => 'SCHOOL ADDRESS IS REQUIRED.'];
    }

    if (mb_strlen($address) > 500) {
        return ['status' => 'error', 'message' => 'SCHOOL ADDRESS IS TOO LONG.'];
    }

    if (mb_strlen($campus) > 255) {
        return ['status' => 'error', 'message' => 'CAMPUS IS TOO LONG.'];
    }

    try {
        $stmt = $pdo->prepare('UPDATE schools SET school_name = :name, address = :address, campus = :campus WHERE id = :id');
        $stmt->execute(['name' => $newName, 'address' => $address, 'campus' => $campus, 'id' => $id]);
    } catch (\PDOException $e) {
        if ($e->getCode() === '23000') {
            return ['status' => 'error', 'message' => 'A SCHOOL WITH THIS NAME AND CAMPUS ALREADY EXISTS.'];
        }
        throw $e;
    }

    return ['status' => 'success', 'message' => 'SCHOOL RENAMED.'];
}
```

Append the new function after `set_school_status()`:

```php
function remove_school(PDO $pdo, int $id, int $requestingUserId, string $password): array
{
    $stmt = $pdo->prepare('SELECT password FROM users WHERE id = :id');
    $stmt->execute(['id' => $requestingUserId]);
    $hash = $stmt->fetchColumn();

    if ($hash === false || !password_verify($password, $hash)) {
        return ['status' => 'error', 'message' => 'INCORRECT PASSWORD.'];
    }

    $existsStmt = $pdo->prepare('SELECT school_name FROM schools WHERE id = :id');
    $existsStmt->execute(['id' => $id]);
    $schoolName = $existsStmt->fetchColumn();
    if ($schoolName === false) {
        return ['status' => 'error', 'message' => 'SCHOOL NOT FOUND.'];
    }

    $countStmt = $pdo->prepare(
        'SELECT
            (SELECT COUNT(*) FROM latin_honor_uploads WHERE school_id = :id1) AS upload_count,
            (SELECT COUNT(*) FROM latin_honor_graduates WHERE school_id = :id2) AS graduate_count'
    );
    $countStmt->execute(['id1' => $id, 'id2' => $id]);
    $counts = $countStmt->fetch();
    $uploadCount = (int) $counts['upload_count'];
    $graduateCount = (int) $counts['graduate_count'];

    if ($uploadCount > 0 || $graduateCount > 0) {
        return ['status' => 'error', 'message' => 'CANNOT REMOVE — THIS SCHOOL HAS ' . $uploadCount . ' UPLOAD(S) AND ' . $graduateCount . ' GRADUATE RECORD(S).'];
    }

    $deleteStmt = $pdo->prepare('DELETE FROM schools WHERE id = :id');
    $deleteStmt->execute(['id' => $id]);

    return ['status' => 'success', 'message' => 'SCHOOL REMOVED.', 'school_name' => $schoolName];
}
```

- [ ] **Step 5: Run the full suite to verify everything passes**

Run: `"/c/xampp/php/php.exe" vendor/bin/phpunit`
Expected: OK, all tests passing (baseline 148 + new tests from this task).

- [ ] **Step 6: Commit**

```bash
git add database/schema.sql includes/schools.php tests/includes/SchoolsTest.php
git commit -m "feat: add address/campus fields and remove_school() to schools data layer"
```

---

### Task 2: API layer (`create_school.php`, `update_school.php`, new `remove_school.php`)

**Files:**
- Modify: `api/create_school.php`
- Modify: `api/update_school.php`
- Create: `api/remove_school.php`

**Interfaces:**
- Consumes: `create_school()`, `rename_school()`, `remove_school()` exactly as defined in Task 1.
- Produces: no new interfaces for later tasks — Task 4's JS calls these three endpoints by URL and POST field names only (`name`, `address`, `campus`, `status`, `school_id`, `password`, `csrf_token`), documented here for Task 4 to match exactly.

- [ ] **Step 1: Update `api/create_school.php`**

Replace the body (everything inside the `try` block) so it reads and passes through `address`/`campus`:

```php
<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/schools.php';

require_role('administrator');

header('Content-Type: application/json');

if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'YOUR SESSION EXPIRED. PLEASE TRY AGAIN.']);
    exit;
}

try {
    $pdo = get_pdo();
    $name = trim((string) ($_POST['name'] ?? ''));
    $address = trim((string) ($_POST['address'] ?? ''));
    $campus = trim((string) ($_POST['campus'] ?? ''));

    $result = create_school($pdo, $name, $address, $campus);

    if ($result['status'] === 'success') {
        try {
            $currentUser = current_user();
            log_activity($pdo, $currentUser['id'] ?? null, 'school_created', 'school_name=' . $name . ', address=' . $address . ', campus=' . $campus);
        } catch (\Throwable $logError) {
            error_log('Activity log failed: ' . $logError->getMessage());
        }
    }

    echo json_encode($result);
} catch (\Throwable $e) {
    error_log('Create school failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'THE SCHOOL COULD NOT BE CREATED. PLEASE TRY AGAIN.']);
}
```

- [ ] **Step 2: Update `api/update_school.php`**

Replace the whole file — extends the `name`-present branch to also read/require `address` and pass all three to `rename_school()`:

```php
<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/schools.php';

require_role('administrator');

header('Content-Type: application/json');

if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'YOUR SESSION EXPIRED. PLEASE TRY AGAIN.']);
    exit;
}

$schoolId = (int) ($_POST['school_id'] ?? 0);
$currentUser = current_user();

if ($schoolId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'SCHOOL NOT FOUND.']);
    exit;
}

$nameSubmitted = isset($_POST['name']);
$name = $nameSubmitted ? trim((string) $_POST['name']) : null;
$address = $nameSubmitted ? trim((string) ($_POST['address'] ?? '')) : null;
$campus = $nameSubmitted ? trim((string) ($_POST['campus'] ?? '')) : null;
$status = isset($_POST['status']) && $_POST['status'] !== '' ? (string) $_POST['status'] : null;

if ($nameSubmitted && $name === '') {
    echo json_encode(['status' => 'error', 'message' => 'SCHOOL NAME IS REQUIRED.']);
    exit;
}

try {
    $pdo = get_pdo();

    $existsStmt = $pdo->prepare('SELECT 1 FROM schools WHERE id = :id');
    $existsStmt->execute(['id' => $schoolId]);
    if ($existsStmt->fetchColumn() === false) {
        echo json_encode(['status' => 'error', 'message' => 'SCHOOL NOT FOUND.']);
        exit;
    }

    $pdo->beginTransaction();
    $details = [];

    if ($name !== null) {
        $result = rename_school($pdo, $schoolId, $name, $address, $campus);
        if ($result['status'] !== 'success') {
            $pdo->rollBack();
            echo json_encode($result);
            exit;
        }
        $details[] = 'name=' . $name . ', address=' . $address . ', campus=' . $campus;
    }

    if ($status !== null) {
        $result = set_school_status($pdo, $schoolId, $status);
        if ($result['status'] !== 'success') {
            $pdo->rollBack();
            echo json_encode($result);
            exit;
        }
        $details[] = 'status=' . $status;
    }

    if (empty($details)) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'NO CHANGES WERE SUBMITTED.']);
        exit;
    }

    $pdo->commit();

    try {
        log_activity($pdo, $currentUser['id'] ?? null, 'school_updated', 'school_id=' . $schoolId . ', ' . implode(', ', $details));
    } catch (\Throwable $logError) {
        error_log('Activity log failed: ' . $logError->getMessage());
    }

    echo json_encode(['status' => 'success', 'message' => 'SCHOOL UPDATED SUCCESSFULLY.']);
} catch (\Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Update school failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'THE SCHOOL COULD NOT BE UPDATED. PLEASE TRY AGAIN.']);
}
```

- [ ] **Step 3: Create `api/remove_school.php`**

```php
<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/schools.php';

require_role('administrator');

header('Content-Type: application/json');

if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'YOUR SESSION EXPIRED. PLEASE TRY AGAIN.']);
    exit;
}

$schoolId = (int) ($_POST['school_id'] ?? 0);
$password = (string) ($_POST['password'] ?? '');
$currentUser = current_user();

if ($schoolId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'SCHOOL NOT FOUND.']);
    exit;
}

try {
    $pdo = get_pdo();
    $result = remove_school($pdo, $schoolId, (int) ($currentUser['id'] ?? 0), $password);

    if ($result['status'] === 'success') {
        try {
            log_activity($pdo, $currentUser['id'] ?? null, 'school_removed', 'school_id=' . $schoolId . ', school_name=' . $result['school_name']);
        } catch (\Throwable $logError) {
            error_log('Activity log failed: ' . $logError->getMessage());
        }
    }

    echo json_encode($result);
} catch (\Throwable $e) {
    error_log('Remove school failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'THE SCHOOL COULD NOT BE REMOVED. PLEASE TRY AGAIN.']);
}
```

- [ ] **Step 4: Run the full suite**

Run: `"/c/xampp/php/php.exe" vendor/bin/phpunit`
Expected: OK, same count as Task 1 (this task adds no PHPUnit tests — thin API wrappers are verified manually in this project's established convention, same as Task 2 of the previous Schools CRUD addendum; full coverage happens in Task 5's manual walkthrough).

- [ ] **Step 5: Commit**

```bash
git add api/create_school.php api/update_school.php api/remove_school.php
git commit -m "feat: extend school API endpoints for address/campus, add remove_school.php"
```

---

### Task 3: `admin/schools.php` page + `includes/sidebar.php` SETTINGS submenu

**Files:**
- Modify: `admin/schools.php`
- Modify: `includes/sidebar.php`
- Modify: `admin/activity_log.php`

**Interfaces:**
- Consumes: `get_all_schools_with_counts()` from Task 1 (now returns `address`/`campus` per row).
- Produces (for Task 4 to consume — exact DOM contract):
  - `#create-school-btn` — unchanged id, now sits in a toolbar row under the `<h1>`.
  - `#remove-school-btn` — new, starts with the `disabled` attribute present.
  - `.school-checkbox` — new, one per row, `data-school-id` and `data-name` attributes.
  - `.edit-school-btn` — unchanged class, now carries two new data attributes: `data-address`, `data-campus` (alongside the existing `data-school-id`, `data-name`, `data-status`).
  - `window.HGE_SCHOOLS = { csrfToken, apiBase }` — unchanged shape.

- [ ] **Step 1: Replace `admin/schools.php`**

```php
<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/schools.php';

require_role('administrator');

$pdo = get_pdo();
$schools = get_all_schools_with_counts($pdo);
$currentUser = current_user();
$currentPage = 'schools';
$csrfToken = csrf_token();

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main class="lg:ml-64 min-h-screen bg-surface-950">
    <div class="p-6 lg:p-8">
        <h1 class="text-xl font-semibold text-white mb-4">SCHOOL MANAGEMENT</h1>
        <div class="flex items-center gap-3 mb-6">
            <button type="button" id="create-school-btn" class="rounded-lg bg-accent-600 hover:bg-accent-500 text-white text-sm font-medium px-4 py-2">CREATE SCHOOL</button>
            <button type="button" id="remove-school-btn" disabled class="rounded-lg bg-red-600 hover:bg-red-500 disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:bg-red-600 text-white text-sm font-medium px-4 py-2">REMOVE</button>
        </div>

        <div class="bg-surface-900 border border-white/10 rounded-xl overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead>
                    <tr class="text-xs text-gray-500 border-b border-white/10">
                        <th class="px-4 py-3 w-10"></th>
                        <th class="px-4 py-3">SCHOOL NAME</th>
                        <th class="px-4 py-3">CAMPUS</th>
                        <th class="px-4 py-3">ADDRESS</th>
                        <th class="px-4 py-3">STATUS</th>
                        <th class="px-4 py-3">UPLOADS</th>
                        <th class="px-4 py-3">GRADUATES</th>
                        <th class="px-4 py-3">ACTION</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($schools as $school): ?>
                        <tr class="border-b border-white/5 hover:bg-white/5">
                            <td class="px-4 py-3">
                                <input type="checkbox" class="school-checkbox"
                                    data-school-id="<?= (int) $school['id'] ?>"
                                    data-name="<?= e($school['school_name']) ?>">
                            </td>
                            <td class="px-4 py-3"><?= e_upper($school['school_name']) ?></td>
                            <td class="px-4 py-3"><?= $school['campus'] !== '' ? e_upper($school['campus']) : '—' ?></td>
                            <td class="px-4 py-3"><?= e_upper($school['address']) ?></td>
                            <td class="px-4 py-3">
                                <span class="<?= $school['status'] === 'active' ? 'text-green-400' : 'text-red-400' ?>"><?= e_upper($school['status']) ?></span>
                            </td>
                            <td class="px-4 py-3"><?= (int) $school['upload_count'] ?></td>
                            <td class="px-4 py-3"><?= (int) $school['graduate_count'] ?></td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <button type="button" class="edit-school-btn text-accent-400 hover:underline"
                                    data-school-id="<?= (int) $school['id'] ?>"
                                    data-name="<?= e($school['school_name']) ?>"
                                    data-address="<?= e($school['address']) ?>"
                                    data-campus="<?= e($school['campus']) ?>"
                                    data-status="<?= e($school['status']) ?>">EDIT</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
<script>
window.HGE_SCHOOLS = {
    csrfToken: <?= json_encode($csrfToken) ?>,
    apiBase: <?= json_encode(APP_BASE_URL) ?>
};
</script>
<script src="<?= e(APP_BASE_URL) ?>/assets/js/schools.js"></script>
```

- [ ] **Step 2: Replace the administrator-only block in `includes/sidebar.php`**

Replace lines 16-26 (the `<?php if (user_has_role(...)): ?>` block through its `<?php endif; ?>`) with:

```php
        <?php if (user_has_role($currentUser ?? null, 'administrator')): ?>
        <details <?= in_array($currentPage, ['users', 'activity_log', 'schools'], true) ? 'open' : '' ?>>
            <summary class="flex items-center gap-3 rounded-lg px-3 py-2 text-gray-400 hover:bg-white/5 hover:text-white cursor-pointer">
                SETTINGS
            </summary>
            <div class="ml-3 mt-1 space-y-1">
                <a href="<?= e(APP_BASE_URL) ?>/admin/users.php" class="flex items-center gap-3 rounded-lg px-3 py-2 <?= sidebar_nav_class($currentPage, 'users') ?>">
                    USER MANAGEMENT
                </a>
                <a href="<?= e(APP_BASE_URL) ?>/admin/activity_log.php" class="flex items-center gap-3 rounded-lg px-3 py-2 <?= sidebar_nav_class($currentPage, 'activity_log') ?>">
                    ACTIVITY LOG
                </a>
                <a href="<?= e(APP_BASE_URL) ?>/admin/schools.php" class="flex items-center gap-3 rounded-lg px-3 py-2 <?= sidebar_nav_class($currentPage, 'schools') ?>">
                    SCHOOL MANAGEMENT
                </a>
            </div>
        </details>
        <?php endif; ?>
```

- [ ] **Step 3: Add `school_removed` to `admin/activity_log.php`'s filter array**

Change line 36 from:

```php
$actionTypes = ['login', 'logout', 'pdf_upload', 'pdf_delete', 'record_created', 'record_updated', 'record_removed', 'search', 'user_created', 'user_updated', 'school_created', 'school_updated'];
```

to:

```php
$actionTypes = ['login', 'logout', 'pdf_upload', 'pdf_delete', 'record_created', 'record_updated', 'record_removed', 'search', 'user_created', 'user_updated', 'school_created', 'school_updated', 'school_removed'];
```

- [ ] **Step 4: Run the full suite**

Run: `"/c/xampp/php/php.exe" vendor/bin/phpunit`
Expected: OK, same count as Task 2 (this task is page/view-only, no PHPUnit coverage — matches this project's established convention for `admin/*.php` pages).

- [ ] **Step 5: Commit**

```bash
git add admin/schools.php includes/sidebar.php admin/activity_log.php
git commit -m "feat: rebuild schools page with checkbox/remove toolbar, add SETTINGS submenu"
```

---

### Task 4: `assets/js/schools.js` — fields, confirm modal, checkbox/remove wiring

**Files:**
- Modify: `assets/js/schools.js`

**Interfaces:**
- Consumes: the exact DOM contract from Task 3 (`#create-school-btn`, `#remove-school-btn`, `.school-checkbox[data-school-id][data-name]`, `.edit-school-btn[data-school-id][data-name][data-address][data-campus][data-status]`, `window.HGE_SCHOOLS`), and the exact API contract from Task 2 (`api/create_school.php` accepts `name`/`address`/`campus`; `api/update_school.php` accepts `school_id`/`name`/`address`/`campus`/`status`; `api/remove_school.php` accepts `school_id`/`password`). Uses the global `showModal()` (`assets/js/modal.js`), `showToast()` (`assets/js/toast.js`), and `escapeHtml()` (`assets/js/utils.js`) — all already loaded by `includes/footer.php` before this file. No local `escapeHtml()` definition (this project has a documented history of two real stored-XSS bugs from exactly that mistake).
- Produces: nothing new for later tasks — this is the last code task before manual verification.

- [ ] **Step 1: Replace `assets/js/schools.js`**

```javascript
document.addEventListener('DOMContentLoaded', function () {
    var config = window.HGE_SCHOOLS;
    if (!config) {
        return;
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

    function openConfirmModal(title, rows, onConfirm, onGoBack) {
        var bodyHtml = '<div class="space-y-2">' +
            rows.map(function (row) {
                return '<div class="flex justify-between gap-4 border-b border-white/5 pb-1">' +
                    '<span class="text-gray-400">' + escapeHtml(row.label) + '</span>' +
                    '<span class="text-white text-right">' + escapeHtml(row.value) + '</span></div>';
            }).join('') +
            '</div>';

        showModal({
            title: title,
            bodyHtml: bodyHtml,
            buttons: [
                {
                    label: 'GO BACK',
                    className: 'rounded-lg px-4 py-2 text-sm text-gray-300 hover:bg-white/5',
                    onClick: function (modalClose) {
                        modalClose();
                        onGoBack();
                    },
                },
                {
                    label: 'CONFIRM',
                    className: 'rounded-lg px-4 py-2 text-sm bg-accent-600 hover:bg-accent-500 text-white',
                    onClick: onConfirm,
                },
            ],
        });
    }

    function openCreateModal(prefill) {
        prefill = prefill || { name: '', address: '', campus: '' };
        var bodyHtml =
            '<div class="space-y-3">' +
            '<div><label class="block text-xs text-gray-400 mb-1">SCHOOL NAME</label>' +
            '<input id="modal-name" type="text" value="' + escapeHtml(prefill.name) + '" class="w-full rounded-lg bg-surface-800 border border-white/10 px-3 py-2 text-sm"></div>' +
            '<div><label class="block text-xs text-gray-400 mb-1">ADDRESS</label>' +
            '<input id="modal-address" type="text" value="' + escapeHtml(prefill.address) + '" class="w-full rounded-lg bg-surface-800 border border-white/10 px-3 py-2 text-sm"></div>' +
            '<div><label class="block text-xs text-gray-400 mb-1">CAMPUS (OPTIONAL)</label>' +
            '<input id="modal-campus" type="text" value="' + escapeHtml(prefill.campus) + '" class="w-full rounded-lg bg-surface-800 border border-white/10 px-3 py-2 text-sm"></div>' +
            '</div>';

        showModal({
            title: 'CREATE SCHOOL',
            bodyHtml: bodyHtml,
            buttons: [
                { label: 'CANCEL', className: 'rounded-lg px-4 py-2 text-sm text-gray-300 hover:bg-white/5' },
                {
                    label: 'CREATE',
                    className: 'rounded-lg px-4 py-2 text-sm bg-accent-600 hover:bg-accent-500 text-white',
                    onClick: function (modalClose) {
                        var values = {
                            name: document.getElementById('modal-name').value,
                            address: document.getElementById('modal-address').value,
                            campus: document.getElementById('modal-campus').value,
                        };
                        modalClose();
                        openConfirmModal(
                            'CONFIRM NEW SCHOOL',
                            [
                                { label: 'SCHOOL NAME', value: values.name },
                                { label: 'ADDRESS', value: values.address },
                                { label: 'CAMPUS', value: values.campus !== '' ? values.campus : '—' },
                            ],
                            function (confirmModalClose) {
                                postJson(config.apiBase + '/api/create_school.php', {
                                    csrf_token: config.csrfToken,
                                    name: values.name,
                                    address: values.address,
                                    campus: values.campus,
                                })
                                    .then(function (data) {
                                        if (data.status === 'success') {
                                            showToast('success', data.message);
                                            confirmModalClose();
                                            reload();
                                        } else {
                                            showToast('error', data.message);
                                        }
                                    })
                                    .catch(function () {
                                        showToast('error', 'THE SCHOOL COULD NOT BE CREATED. PLEASE TRY AGAIN.');
                                    });
                            },
                            function () {
                                openCreateModal(values);
                            }
                        );
                    },
                },
            ],
        });
    }

    function openEditModal(defaults) {
        var bodyHtml =
            '<div class="space-y-3">' +
            '<div><label class="block text-xs text-gray-400 mb-1">SCHOOL NAME</label>' +
            '<input id="modal-name" type="text" value="' + escapeHtml(defaults.name) + '" class="w-full rounded-lg bg-surface-800 border border-white/10 px-3 py-2 text-sm"></div>' +
            '<div><label class="block text-xs text-gray-400 mb-1">ADDRESS</label>' +
            '<input id="modal-address" type="text" value="' + escapeHtml(defaults.address) + '" class="w-full rounded-lg bg-surface-800 border border-white/10 px-3 py-2 text-sm"></div>' +
            '<div><label class="block text-xs text-gray-400 mb-1">CAMPUS (OPTIONAL)</label>' +
            '<input id="modal-campus" type="text" value="' + escapeHtml(defaults.campus) + '" class="w-full rounded-lg bg-surface-800 border border-white/10 px-3 py-2 text-sm"></div>' +
            '<div><label class="block text-xs text-gray-400 mb-1">STATUS</label>' +
            '<select id="modal-status" class="w-full rounded-lg bg-surface-800 border border-white/10 px-3 py-2 text-sm">' +
            '<option value="active"' + (defaults.status === 'active' ? ' selected' : '') + '>ACTIVE</option>' +
            '<option value="disabled"' + (defaults.status === 'disabled' ? ' selected' : '') + '>DISABLED</option></select></div>' +
            '</div>';

        showModal({
            title: 'EDIT SCHOOL',
            bodyHtml: bodyHtml,
            buttons: [
                { label: 'CANCEL', className: 'rounded-lg px-4 py-2 text-sm text-gray-300 hover:bg-white/5' },
                {
                    label: 'SAVE',
                    className: 'rounded-lg px-4 py-2 text-sm bg-accent-600 hover:bg-accent-500 text-white',
                    onClick: function (modalClose) {
                        var values = {
                            schoolId: defaults.schoolId,
                            name: document.getElementById('modal-name').value,
                            address: document.getElementById('modal-address').value,
                            campus: document.getElementById('modal-campus').value,
                            status: document.getElementById('modal-status').value,
                        };
                        modalClose();
                        openConfirmModal(
                            'CONFIRM SCHOOL CHANGES',
                            [
                                { label: 'SCHOOL NAME', value: values.name },
                                { label: 'ADDRESS', value: values.address },
                                { label: 'CAMPUS', value: values.campus !== '' ? values.campus : '—' },
                                { label: 'STATUS', value: values.status.toUpperCase() },
                            ],
                            function (confirmModalClose) {
                                postJson(config.apiBase + '/api/update_school.php', {
                                    csrf_token: config.csrfToken,
                                    school_id: values.schoolId,
                                    name: values.name,
                                    address: values.address,
                                    campus: values.campus,
                                    status: values.status,
                                })
                                    .then(function (data) {
                                        if (data.status === 'success') {
                                            showToast('success', data.message);
                                            confirmModalClose();
                                            reload();
                                        } else {
                                            showToast('error', data.message);
                                        }
                                    })
                                    .catch(function () {
                                        showToast('error', 'THE SCHOOL COULD NOT BE UPDATED. PLEASE TRY AGAIN.');
                                    });
                            },
                            function () {
                                openEditModal(values);
                            }
                        );
                    },
                },
            ],
        });
    }

    function openRemoveConfirmModal(target) {
        var bodyHtml =
            '<div class="space-y-3">' +
            '<p class="text-sm text-gray-300">PERMANENTLY REMOVE <span class="text-white font-medium">' + escapeHtml(target.name) + '</span>? THIS CANNOT BE UNDONE.</p>' +
            '<div><label class="block text-xs text-gray-400 mb-1">ENTER YOUR PASSWORD TO CONFIRM</label>' +
            '<input id="modal-remove-password" type="password" class="w-full rounded-lg bg-surface-800 border border-white/10 px-3 py-2 text-sm"></div>' +
            '</div>';

        showModal({
            title: 'REMOVE SCHOOL',
            bodyHtml: bodyHtml,
            buttons: [
                { label: 'CANCEL', className: 'rounded-lg px-4 py-2 text-sm text-gray-300 hover:bg-white/5' },
                {
                    label: 'CONFIRM',
                    className: 'rounded-lg px-4 py-2 text-sm bg-red-600 hover:bg-red-500 text-white',
                    onClick: function (modalClose) {
                        var password = document.getElementById('modal-remove-password').value;
                        postJson(config.apiBase + '/api/remove_school.php', {
                            csrf_token: config.csrfToken,
                            school_id: target.schoolId,
                            password: password,
                        })
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
                                showToast('error', 'THE SCHOOL COULD NOT BE REMOVED. PLEASE TRY AGAIN.');
                            });
                    },
                },
            ],
        });
    }

    var createBtn = document.getElementById('create-school-btn');
    if (createBtn) {
        createBtn.addEventListener('click', function () {
            openCreateModal();
        });
    }

    document.querySelectorAll('.edit-school-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            openEditModal({
                schoolId: btn.dataset.schoolId,
                name: btn.dataset.name,
                address: btn.dataset.address,
                campus: btn.dataset.campus,
                status: btn.dataset.status,
            });
        });
    });

    var removeBtn = document.getElementById('remove-school-btn');
    var checkboxes = document.querySelectorAll('.school-checkbox');

    function updateRemoveButtonState() {
        if (!removeBtn) {
            return;
        }
        var checked = document.querySelector('.school-checkbox:checked');
        removeBtn.disabled = !checked;
    }

    checkboxes.forEach(function (cb) {
        cb.addEventListener('change', function () {
            if (cb.checked) {
                checkboxes.forEach(function (other) {
                    if (other !== cb) {
                        other.checked = false;
                    }
                });
            }
            updateRemoveButtonState();
        });
    });

    if (removeBtn) {
        removeBtn.addEventListener('click', function () {
            var checked = document.querySelector('.school-checkbox:checked');
            if (!checked) {
                return;
            }
            openRemoveConfirmModal({
                schoolId: checked.dataset.schoolId,
                name: checked.dataset.name,
            });
        });
    }

    updateRemoveButtonState();
});
```

- [ ] **Step 2: Run the full suite**

Run: `"/c/xampp/php/php.exe" vendor/bin/phpunit`
Expected: OK, same count as Task 3 (JS-only task, no PHPUnit coverage).

- [ ] **Step 3: Commit**

```bash
git add assets/js/schools.js
git commit -m "feat: add schools.js confirm-modal step, checkbox single-select, remove wiring"
```

---

### Task 5: End-to-end manual verification + cleanup

This task has no code changes — it is a scripted manual walkthrough via the Browser tool, mirroring the previous Schools CRUD addendum's own Task 5 pattern exactly (throwaway PHP built-in server, throwaway admin account, full cleanup afterward). Perform it directly, not via a subagent.

**Files:** none (verification only).

- [ ] **Step 1: Start a throwaway server**

Create a throwaway `prepend_root.php` at the repo root:

```php
<?php
// prepend_root.php — throwaway, delete after manual verification.
define('APP_BASE_URL', '');
```

Run (background):

```bash
"/c/xampp/php/php.exe" -S 127.0.0.1:8899 -t . -d auto_prepend_file=prepend_root.php
```

Create a throwaway administrator account via `create_or_update_admin()` (never touch the real seeded `admin` account) — e.g. a one-off PHP snippet or reuse of the existing pattern from the previous addendum's Task 5.

Apply the live `ALTER TABLE` this addendum's new ENUM value needs, against the already-existing dev `hge_masterlist` database (not the test DB, which rebuilds from `schema.sql` automatically):

```sql
ALTER TABLE activity_log MODIFY COLUMN action ENUM('login','logout','pdf_upload','pdf_delete','record_created','record_updated','record_removed','search','user_created','user_updated','school_created','school_updated','school_removed') NOT NULL;
```

Also apply the live schema change for `schools.address`/`schools.campus`/the composite unique key, since the dev database's `schools` table predates this addendum:

```sql
ALTER TABLE schools ADD COLUMN address VARCHAR(500) NOT NULL DEFAULT '' AFTER school_name;
ALTER TABLE schools ADD COLUMN campus VARCHAR(255) NOT NULL DEFAULT '' AFTER address;
ALTER TABLE schools DROP INDEX uq_school_name;
ALTER TABLE schools ADD UNIQUE KEY uq_school_name_campus (school_name, campus);
```

- [ ] **Step 2: Walk through CREATE SCHOOL with confirm step**

Log in as the throwaway admin, navigate to `admin/schools.php`. Confirm the `<h1>` reads "SCHOOL MANAGEMENT" and the toolbar shows CREATE SCHOOL and a disabled REMOVE button.

Click CREATE SCHOOL, fill NAME "Eastern Visayas State University", ADDRESS "Tanauan, Leyte", CAMPUS "Tanauan Campus", click CREATE. Confirm the review modal appears showing all three values read-only. Click CONFIRM. Confirm the school appears in the table with CAMPUS "TANAUAN CAMPUS" and ADDRESS "TANAUAN, LEYTE" columns populated.

Repeat CREATE SCHOOL with the same NAME "Eastern Visayas State University", a different ADDRESS, CAMPUS "Main Campus" — confirm it succeeds (same name, different campus is allowed) and both rows now show in the table with the same SCHOOL NAME but different CAMPUS.

Attempt CREATE SCHOOL again with NAME "Eastern Visayas State University" and CAMPUS "Main Campus" (exact duplicate pair) — confirm it's rejected with "A SCHOOL WITH THIS NAME AND CAMPUS ALREADY EXISTS."

Attempt CREATE SCHOOL with a blank ADDRESS — confirm it's rejected with "SCHOOL ADDRESS IS REQUIRED." before ever reaching the confirm modal step's POST (i.e., the error surfaces from the actual API call after CONFIRM, since validation is server-side).

- [ ] **Step 3: Walk through EDIT with confirm step, checkbox single-select, and REMOVE**

Click EDIT on the "Tanauan Campus" row. Confirm the modal pre-fills NAME/ADDRESS/CAMPUS/STATUS correctly. Change the ADDRESS, click SAVE. Confirm the review modal shows the updated values. Click CONFIRM. Confirm the table reflects the new address.

Check the checkbox on one row — confirm REMOVE becomes enabled. Check a second row's checkbox — confirm the first row's checkbox automatically unchecks (single-select) and REMOVE stays enabled (exactly one still checked). Uncheck the remaining box — confirm REMOVE becomes disabled again.

Upload a real fixture PDF against the "Tanauan Campus" school (via `admin/upload.php`, extract, review, save — same flow used in the previous addendum's Task 5) so it has real uploads/graduates. Check its checkbox, click REMOVE, enter the throwaway admin's correct password, click CONFIRM. Confirm the error "CANNOT REMOVE — THIS SCHOOL HAS 1 UPLOAD(S) AND <N> GRADUATE RECORD(S)." appears via toast, the modal stays open, and the school is still present in the table after a page reload.

Check the checkbox on the empty "Main Campus" school (no uploads/graduates). Click REMOVE, enter an incorrect password, click CONFIRM — confirm "INCORRECT PASSWORD." appears and the school still exists. Click REMOVE again, enter the correct password, click CONFIRM — confirm success and the school disappears from the table after reload.

- [ ] **Step 4: Verify the activity log and SETTINGS submenu**

Navigate to `admin/activity_log.php`. Confirm `SCHOOL_CREATED`, `SCHOOL_UPDATED`, and `SCHOOL_REMOVED` entries all appear with expected `details` text, and confirm `school_removed` is selectable in the ACTION filter dropdown.

Confirm the sidebar shows a single "SETTINGS" entry (not three flat links) that is already expanded (showing USER MANAGEMENT / ACTIVITY LOG / SCHOOL MANAGEMENT) while on any of those three pages, and collapses/expands on click when navigating to an unrelated page like the dashboard.

- [ ] **Step 5: Role check (reuse from previous addendum if a `user`-role throwaway account still doesn't exist)**

Confirm a `user`-role account still gets HTTP 403 on `admin/schools.php` and sees no SETTINGS entry at all in the sidebar (unaffected by this addendum's changes, but worth a quick re-confirmation since the sidebar markup changed).

- [ ] **Step 6: Full cleanup**

Stop the throwaway PHP server, delete `prepend_root.php`, and remove all throwaway/seeded data created during this task (the Eastern Visayas schools, their upload/graduates/stored PDF, the throwaway admin account, and any `activity_log` rows generated) via the MySQL CLI, following the exact same verify-then-delete pattern used in the previous addendum's Task 5 (list rows before deleting, never blind-delete).

- [ ] **Step 7: Update the SDD ledger**

Record a Task 5 entry in the ledger summarizing what was verified and confirming cleanup, matching the format used for every prior task/phase in this project.
