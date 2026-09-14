# Schools Admin CRUD Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the Schools admin CRUD page deferred since Phase 2 — an administrator can list, create, rename, and enable/disable schools, seeing each school's upload and graduate counts.

**Architecture:** Follows `admin/users.php`'s exact page+modal shape from Phase 5: a data layer in `includes/schools.php`, thin `api/*.php` JSON wrappers, a server-rendered page, and a page-specific JS file using the project's shared `escapeHtml()`. The two new data-layer functions (`create_school()`, `rename_school()`) are deliberately separate from the existing `find_or_create_school()` — that function's "reuse on name collision" behavior is correct for the upload form's inline autocomplete, but wrong for an explicit CREATE SCHOOL button, which must error on a duplicate name instead.

**Tech Stack:** PHP 8.2 (PDO, prepared statements), MariaDB, vanilla JS (`fetch`), Tailwind (compiled, no new build step).

**Spec:** `docs/superpowers/specs/2026-09-12-hge-database-design.md` (section 15, the Schools admin CRUD addendum — read it before starting).

## Global Constraints

- PHP >= 8.1, all queries via PDO prepared statements, all output escaped at render time with `e()`/`e_upper()` from `includes/functions.php`.
- `admin/schools.php` and both new `api/*.php` endpoints call `require_role('administrator')` — this subsystem is administrator-only, matching `admin/users.php` and `admin/activity_log.php` from Phase 5.
- No delete — only rename and `active`/`disabled` status toggle. Schools with real uploaded data must never be removable.
- No self-lockout equivalent — schools aren't a login identity, so `api/update_school.php` needs no "can't disable your own X" guard the way `api/update_user.php` does.
- `api/update_school.php` must apply its existence check and wrap its mutations in a transaction from the start — Phase 5's `api/update_user.php` needed a final-review fix round to add both; this plan builds them in from the first commit.
- Two new `activity_log.action` ENUM values this task adds: `school_created`, `school_updated`. Both `database/schema.sql` (the source of truth for fresh/test databases) and the already-existing live dev database need this — the live database needs an explicit `ALTER TABLE`, exactly as Phase 5's `user_created`/`user_updated` extension did.
- `assets/js/schools.js` must NOT define its own `escapeHtml()` — it uses the global one from `assets/js/utils.js` (added in Phase 5, loaded by `includes/footer.php` before every page-specific script). Redefining it locally would reintroduce the exact per-file drift pattern that caused two real XSS bugs earlier in this project.
- Thin `api/*.php` wrappers and `admin/*.php` views get no PHPUnit tests, matching every prior phase's convention — verified by manual/browser testing instead. Only `includes/schools.php`'s new functions get PHPUnit tests, against a real MySQL test database via `Tests\TestDb`.

---

### Task 1: `includes/schools.php` — new data-layer functions

**Files:**
- Modify: `includes/schools.php` (append four new functions after the existing `get_active_schools_list()`)
- Test: `tests/includes/SchoolsTest.php` (append new test methods to the existing file)

**Interfaces:**
- Produces: `get_all_schools_with_counts(PDO $pdo): array` — every school regardless of status, each row `['id', 'school_name', 'status', 'upload_count', 'graduate_count']`, ordered by name.
- Produces: `create_school(PDO $pdo, string $name): array` — returns `['status' => 'success', 'message' => string, 'id' => int]` or `['status' => 'error', 'message' => string]`. Errors on a duplicate name (does NOT reuse the existing row — this is the key difference from `find_or_create_school()`, which stays unchanged and is not used by this feature).
- Produces: `rename_school(PDO $pdo, int $id, string $newName): array` — same return shape as `create_school()` minus the `id` key; errors on a duplicate name.
- Produces: `set_school_status(PDO $pdo, int $id, string $status): array` — `$status` must be `'active'` or `'disabled'`.
- Consumed by: Task 2 (`api/create_school.php`, `api/update_school.php`) and Task 3 (`admin/schools.php`).
- Note for the reviewer: none of these four functions enforce anything about existing uploads/graduates when disabling or renaming a school — the spec (section 15) is explicit that no cascading effect is needed, since both `latin_honor_uploads` and `latin_honor_graduates` reference schools by `school_id`, never by name.

- [ ] **Step 1: Write the failing tests**

Append to `tests/includes/SchoolsTest.php` (inside the `SchoolsTest` class, after the existing `testGetActiveSchoolsListReturnsIdAndNameForActiveSchoolsOnly` test from Phase 4):

```php
    public function testGetAllSchoolsWithCountsReturnsAllRegardlessOfStatus(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO schools (school_name, status) VALUES ('Active School', 'active')");
        $pdo->exec("INSERT INTO schools (school_name, status) VALUES ('Disabled School', 'disabled')");

        $schools = get_all_schools_with_counts($pdo);

        $this->assertCount(2, $schools);
        $names = array_column($schools, 'school_name');
        $this->assertContains('Active School', $names);
        $this->assertContains('Disabled School', $names);
    }

    public function testGetAllSchoolsWithCountsIncludesUploadAndGraduateCounts(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO schools (school_name) VALUES ('ABC University')");
        $pdo->exec("INSERT INTO users (name, username, email, password, role) VALUES ('Admin', 'admin', 'a@a.com', 'x', 'administrator')");
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number)
             VALUES (1, '2025', 'a.pdf', 'ABC_UNIVERSITY_2025_1.PDF', '/x/a.pdf', 10, SHA2('a',256), 1, 1)"
        );
        $pdo->exec(
            "INSERT INTO latin_honor_graduates (upload_id, school_id, graduate_name, latin_honor, graduation_date, graduation_year)
             VALUES (1, 1, 'Juan Dela Cruz', 'CUM LAUDE', '2025-06-15', 2025)"
        );
        $pdo->exec(
            "INSERT INTO latin_honor_graduates (upload_id, school_id, graduate_name, latin_honor, graduation_date, graduation_year)
             VALUES (1, 1, 'Maria Santos', 'MAGNA CUM LAUDE', '2025-06-15', 2025)"
        );

        $schools = get_all_schools_with_counts($pdo);

        $this->assertSame(1, (int) $schools[0]['upload_count']);
        $this->assertSame(2, (int) $schools[0]['graduate_count']);
    }

    public function testCreateSchoolInsertsNewSchool(): void
    {
        $pdo = TestDb::pdo();

        $result = create_school($pdo, 'New School');

        $this->assertSame('success', $result['status']);
        $this->assertArrayHasKey('id', $result);
        $count = (int) $pdo->query("SELECT COUNT(*) FROM schools WHERE school_name = 'New School'")->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testCreateSchoolRejectsEmptyName(): void
    {
        $result = create_school(TestDb::pdo(), '   ');
        $this->assertSame('error', $result['status']);
    }

    public function testCreateSchoolRejectsDuplicateName(): void
    {
        $pdo = TestDb::pdo();
        create_school($pdo, 'ABC University');

        $result = create_school($pdo, 'ABC University');

        $this->assertSame('error', $result['status']);
        $count = (int) $pdo->query("SELECT COUNT(*) FROM schools WHERE school_name = 'ABC University'")->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testRenameSchoolChangesName(): void
    {
        $pdo = TestDb::pdo();
        $created = create_school($pdo, 'Old Name');

        $result = rename_school($pdo, $created['id'], 'New Name');

        $this->assertSame('success', $result['status']);
        $name = $pdo->query("SELECT school_name FROM schools WHERE id = {$created['id']}")->fetchColumn();
        $this->assertSame('New Name', $name);
    }

    public function testRenameSchoolRejectsDuplicateName(): void
    {
        $pdo = TestDb::pdo();
        create_school($pdo, 'ABC University');
        $created = create_school($pdo, 'XYZ College');

        $result = rename_school($pdo, $created['id'], 'ABC University');

        $this->assertSame('error', $result['status']);
        $name = $pdo->query("SELECT school_name FROM schools WHERE id = {$created['id']}")->fetchColumn();
        $this->assertSame('XYZ College', $name);
    }

    public function testSetSchoolStatusTogglesStatus(): void
    {
        $pdo = TestDb::pdo();
        $created = create_school($pdo, 'ABC University');

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
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/includes/SchoolsTest.php`
Expected: FAIL — `get_all_schools_with_counts`, `create_school`, `rename_school`, and `set_school_status` are undefined.

- [ ] **Step 3: Implement**

Append to `includes/schools.php`:

```php
function get_all_schools_with_counts(PDO $pdo): array
{
    $sql = "SELECT s.id, s.school_name, s.status,
                   (SELECT COUNT(*) FROM latin_honor_uploads u WHERE u.school_id = s.id) AS upload_count,
                   (SELECT COUNT(*) FROM latin_honor_graduates g WHERE g.school_id = s.id) AS graduate_count
            FROM schools s
            ORDER BY s.school_name ASC";
    $stmt = $pdo->query($sql);
    return $stmt->fetchAll();
}

function create_school(PDO $pdo, string $name): array
{
    $name = trim($name);

    if ($name === '') {
        return ['status' => 'error', 'message' => 'SCHOOL NAME IS REQUIRED.'];
    }

    if (mb_strlen($name) > 255) {
        return ['status' => 'error', 'message' => 'SCHOOL NAME IS TOO LONG.'];
    }

    try {
        $stmt = $pdo->prepare('INSERT INTO schools (school_name) VALUES (:name)');
        $stmt->execute(['name' => $name]);
    } catch (\PDOException $e) {
        if ($e->getCode() === '23000') {
            return ['status' => 'error', 'message' => 'A SCHOOL WITH THIS NAME ALREADY EXISTS.'];
        }
        throw $e;
    }

    return ['status' => 'success', 'message' => 'SCHOOL CREATED SUCCESSFULLY.', 'id' => (int) $pdo->lastInsertId()];
}

function rename_school(PDO $pdo, int $id, string $newName): array
{
    $newName = trim($newName);

    if ($newName === '') {
        return ['status' => 'error', 'message' => 'SCHOOL NAME IS REQUIRED.'];
    }

    if (mb_strlen($newName) > 255) {
        return ['status' => 'error', 'message' => 'SCHOOL NAME IS TOO LONG.'];
    }

    try {
        $stmt = $pdo->prepare('UPDATE schools SET school_name = :name WHERE id = :id');
        $stmt->execute(['name' => $newName, 'id' => $id]);
    } catch (\PDOException $e) {
        if ($e->getCode() === '23000') {
            return ['status' => 'error', 'message' => 'A SCHOOL WITH THIS NAME ALREADY EXISTS.'];
        }
        throw $e;
    }

    return ['status' => 'success', 'message' => 'SCHOOL RENAMED.'];
}

function set_school_status(PDO $pdo, int $id, string $status): array
{
    if (!in_array($status, ['active', 'disabled'], true)) {
        return ['status' => 'error', 'message' => 'INVALID STATUS.'];
    }

    $stmt = $pdo->prepare('UPDATE schools SET status = :status WHERE id = :id');
    $stmt->execute(['status' => $status, 'id' => $id]);

    return ['status' => 'success', 'message' => 'STATUS UPDATED.'];
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/includes/SchoolsTest.php`
Expected: PASS, all 14 tests green (5 existing tests from Phases 2/4 plus the 9 new ones added in Step 1).

- [ ] **Step 5: Run the full suite**

Run: `vendor/bin/phpunit`
Expected: PASS, no regressions.

- [ ] **Step 6: Commit**

```bash
git add includes/schools.php tests/includes/SchoolsTest.php
git commit -m "feat: add school CRUD data layer (create_school, rename_school, set_school_status, get_all_schools_with_counts)"
```

---

### Task 2: `api/create_school.php` + `api/update_school.php`, and the `activity_log` schema extension

**Files:**
- Modify: `database/schema.sql` (extend `activity_log.action` ENUM)
- Create: `api/create_school.php`
- Create: `api/update_school.php`

**Interfaces:**
- Consumes: `create_school()`, `rename_school()`, `set_school_status()` (Task 1), `require_role()`/`csrf_verify()`/`current_user()` (`includes/auth.php`), `log_activity()` (`includes/functions.php`, Phase 4/5), `get_pdo()` (`config/database.php`).
- Produces (JSON, `Content-Type: application/json`, both endpoints):
  - `api/create_school.php`: same shape `create_school()` returns, plus HTTP 400 on CSRF failure and HTTP 500 + generic message on any `\Throwable`.
  - `api/update_school.php`: `{"status":"success","message":"SCHOOL UPDATED SUCCESSFULLY."}` when at least one of `name`/`status` was submitted and applied, `{"status":"error","message":"..."}` otherwise (including `SCHOOL NOT FOUND.` for a nonexistent `school_id`), same 400/500 handling.

There is no PHPUnit test for these two files (thin wrapper convention). Verify manually.

- [ ] **Step 1: Extend the `activity_log.action` ENUM**

In `database/schema.sql`, find the `activity_log` table's `action` column (it currently ends with `'user_created','user_updated'`):

```sql
  action ENUM('login','logout','pdf_upload','pdf_delete','record_created','record_updated','record_removed','search','user_created','user_updated') NOT NULL,
```

Replace with:

```sql
  action ENUM('login','logout','pdf_upload','pdf_delete','record_created','record_updated','record_removed','search','user_created','user_updated','school_created','school_updated') NOT NULL,
```

This is picked up automatically by `Tests\TestDb::resetSchema()` for the test database. The already-existing live `hge_masterlist` dev database needs an explicit `ALTER TABLE` — that happens in Task 5, right before the manual browser walkthrough needs the new enum values to work (same pattern Phase 5 used).

- [ ] **Step 2: Implement `api/create_school.php`**

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

    $result = create_school($pdo, $name);

    if ($result['status'] === 'success') {
        try {
            $currentUser = current_user();
            log_activity($pdo, $currentUser['id'] ?? null, 'school_created', 'school_name=' . $name);
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

- [ ] **Step 3: Implement `api/update_school.php`**

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

$name = isset($_POST['name']) && trim((string) $_POST['name']) !== '' ? trim((string) $_POST['name']) : null;
$status = isset($_POST['status']) && $_POST['status'] !== '' ? (string) $_POST['status'] : null;

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
        $result = rename_school($pdo, $schoolId, $name);
        if ($result['status'] !== 'success') {
            $pdo->rollBack();
            echo json_encode($result);
            exit;
        }
        $details[] = 'name=' . $name;
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

- [ ] **Step 4: Manually verify both endpoints**

`php` is not on PATH in some environments; use the full path to your PHP binary if `php` alone doesn't resolve (e.g. `C:/xampp/php/php.exe` on this project's Windows/XAMPP setup).

Write a throwaway PHP script (not committed — delete it when done) that fakes an administrator session and `require`s each endpoint directly with `$_POST` populated, capturing output via `ob_start()`/`ob_get_clean()`, the same technique used for every other `api/*.php` verification in this project. Confirm:
- `api/create_school.php` with a valid `name` returns `{"status":"success",...}` and a new row appears in `schools`.
- `api/create_school.php` with a duplicate `name` returns an error and does not create a second row.
- `api/update_school.php` renaming and disabling an existing school in one request succeeds and both changes persist.
- `api/update_school.php` with a nonexistent `school_id` returns `SCHOOL NOT FOUND.` and writes no `activity_log` row.
- Run `vendor/bin/phpunit` once — must stay green (this task adds no new PHPUnit tests, only manual verification).

Delete the throwaway script before finishing.

- [ ] **Step 5: Commit**

```bash
git add database/schema.sql api/create_school.php api/update_school.php
git commit -m "feat: add create/update school API endpoints"
```

---

### Task 3: `admin/schools.php` — schools management page

**Files:**
- Create: `admin/schools.php`
- Modify: `includes/sidebar.php` (add a `SCHOOLS` link inside the existing administrator-only nav block)

**Interfaces:**
- Consumes: `get_all_schools_with_counts()` (Task 1), `require_role()`/`current_user()`/`csrf_token()` (`includes/auth.php`), `e()`/`e_upper()` (`includes/functions.php`), `includes/header.php`/`includes/sidebar.php`/`includes/footer.php` (existing partials).
- Produces: `window.HGE_SCHOOLS = { csrfToken, apiBase }` and the DOM contract Task 4's JS depends on:
  - `#create-school-btn` — opens the create-school modal.
  - Each row's `.edit-school-btn` carries `data-school-id`, `data-name`, `data-status`.

There is no PHPUnit test for this file (it's a view, like `admin/users.php`). Verify it as part of Task 5's browser pass.

- [ ] **Step 1: Implement**

Create `admin/schools.php`:

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
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-xl font-semibold text-white">SCHOOLS MANAGEMENT</h1>
            <button type="button" id="create-school-btn" class="rounded-lg bg-accent-600 hover:bg-accent-500 text-white text-sm font-medium px-4 py-2">CREATE SCHOOL</button>
        </div>

        <div class="bg-surface-900 border border-white/10 rounded-xl overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead>
                    <tr class="text-xs text-gray-500 border-b border-white/10">
                        <th class="px-4 py-3">SCHOOL NAME</th>
                        <th class="px-4 py-3">STATUS</th>
                        <th class="px-4 py-3">UPLOADS</th>
                        <th class="px-4 py-3">GRADUATES</th>
                        <th class="px-4 py-3">ACTION</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($schools as $school): ?>
                        <tr class="border-b border-white/5 hover:bg-white/5">
                            <td class="px-4 py-3"><?= e_upper($school['school_name']) ?></td>
                            <td class="px-4 py-3">
                                <span class="<?= $school['status'] === 'active' ? 'text-green-400' : 'text-red-400' ?>"><?= e_upper($school['status']) ?></span>
                            </td>
                            <td class="px-4 py-3"><?= (int) $school['upload_count'] ?></td>
                            <td class="px-4 py-3"><?= (int) $school['graduate_count'] ?></td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <button type="button" class="edit-school-btn text-accent-400 hover:underline"
                                    data-school-id="<?= (int) $school['id'] ?>"
                                    data-name="<?= e($school['school_name']) ?>"
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

- [ ] **Step 2: Add the sidebar link**

In `includes/sidebar.php`, inside the existing `<?php if (user_has_role($currentUser ?? null, 'administrator')): ?>` block (the one already containing `USER MANAGEMENT` and `ACTIVITY LOG`), add a third link right after `ACTIVITY LOG` and before the block's `<?php endif; ?>`:

```php
        <a href="<?= e(APP_BASE_URL) ?>/admin/schools.php" class="flex items-center gap-3 rounded-lg px-3 py-2 <?= sidebar_nav_class($currentPage, 'schools') ?>">
            SCHOOLS
        </a>
```

- [ ] **Step 3: Commit**

```bash
git add admin/schools.php includes/sidebar.php
git commit -m "feat: add admin/schools.php schools management page with sidebar link"
```

---

### Task 4: `assets/js/schools.js` — create/edit school modals

**Files:**
- Create: `assets/js/schools.js`

**Interfaces:**
- Consumes: `window.HGE_SCHOOLS` (Task 3), `showModal()`/`showToast()` (existing `modal.js`/`toast.js`), the global `escapeHtml()` (`assets/js/utils.js`, Phase 5), the DOM contract from Task 3 (`#create-school-btn`, `.edit-school-btn`), and the JSON contracts of `api/create_school.php`/`api/update_school.php` (Task 2).
- No exports — page-specific script loaded only by `admin/schools.php`, same pattern as `assets/js/users.js`.

- [ ] **Step 1: Implement**

Create `assets/js/schools.js`:

```js
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

    function openCreateModal() {
        var bodyHtml =
            '<div class="space-y-3">' +
            '<div><label class="block text-xs text-gray-400 mb-1">SCHOOL NAME</label>' +
            '<input id="modal-name" type="text" class="w-full rounded-lg bg-surface-800 border border-white/10 px-3 py-2 text-sm"></div>' +
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
                        postJson(config.apiBase + '/api/create_school.php', {
                            csrf_token: config.csrfToken,
                            name: document.getElementById('modal-name').value,
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
                                showToast('error', 'THE SCHOOL COULD NOT BE CREATED. PLEASE TRY AGAIN.');
                            });
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
                        postJson(config.apiBase + '/api/update_school.php', {
                            csrf_token: config.csrfToken,
                            school_id: defaults.schoolId,
                            name: document.getElementById('modal-name').value,
                            status: document.getElementById('modal-status').value,
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
                                showToast('error', 'THE SCHOOL COULD NOT BE UPDATED. PLEASE TRY AGAIN.');
                            });
                    },
                },
            ],
        });
    }

    var createBtn = document.getElementById('create-school-btn');
    if (createBtn) {
        createBtn.addEventListener('click', openCreateModal);
    }

    document.querySelectorAll('.edit-school-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            openEditModal({
                schoolId: btn.dataset.schoolId,
                name: btn.dataset.name,
                status: btn.dataset.status,
            });
        });
    });
});
```

Note: the EDIT modal always submits both `name` and `status` together (unlike `users.js`'s conditional field inclusion for role/status, which exists there because that page has an extra self-lockout-disabled-field case this page has no equivalent of) — both fields are always pre-filled and valid, so sending both every save is simpler and correct.

- [ ] **Step 2: Commit**

```bash
git add assets/js/schools.js
git commit -m "feat: add schools.js for create/edit school modals"
```

---

### Task 5: End-to-end manual verification

**Files:** none (verification only — no code changes expected unless this step surfaces a bug, in which case fix it in the file it belongs to and re-run this task).

- [ ] **Step 1: Run the full automated suite**

```bash
vendor/bin/phpunit
```

Expected: all tests pass (the pre-existing suite plus the 9 tests added in Task 1).

- [ ] **Step 2: Apply the live database ENUM change and start a server**

The live `hge_masterlist` dev database already has an `activity_log` table created before this task — Task 2's `schema.sql` edit only affects fresh/test databases. Apply the same change to the live one:

```bash
mysql -u root hge_masterlist -e "ALTER TABLE activity_log MODIFY COLUMN action ENUM('login','logout','pdf_upload','pdf_delete','record_created','record_updated','record_removed','search','user_created','user_updated','school_created','school_updated') NOT NULL;"
```

Start the built-in PHP server with the same `auto_prepend_file` trick used for manual verification in every prior phase (a `prepend_root.php` throwaway file defining `APP_BASE_URL` as `''`):

```bash
php -S 127.0.0.1:8899 -t . -d auto_prepend_file=prepend_root.php
```

- [ ] **Step 3: Full journey walkthrough, as administrator**

Using the Browser tool, log in as an administrator and confirm:
- The `SCHOOLS` sidebar link appears (administrator-only) and opens `admin/schools.php`.
- CREATE SCHOOL creates a new school with `0`/`0` upload/graduate counts, appearing in the table immediately.
- Attempting to CREATE SCHOOL with that same name again shows the duplicate-name error and does not create a second row.
- Upload a PDF for the newly created school (Phase 2's flow), extract and save records (Phase 3's flow), then reload `admin/schools.php` and confirm the UPLOADS and GRADUATES counts updated to reflect the real data.
- EDIT the school: rename it, confirm the new name appears both in this table and in `admin/lookup.php`'s SCHOOL filter dropdown and the already-saved graduates' school name (both trace by `school_id`, so the rename should apply everywhere instantly with no orphaned references).
- EDIT the school again: set it to `disabled`. Confirm it disappears from `admin/upload.php`'s school-name datalist and from `admin/lookup.php`'s SCHOOL filter dropdown, but a graduate-name search on `admin/lookup.php` still finds the already-saved graduates from that school (proving disable only hides it from active-pickers, it doesn't hide the data).
- Navigate to `admin/activity_log.php` and confirm `school_created` and `school_updated` entries appear with the expected `details` text.

- [ ] **Step 4: Confirm role-agnostic boundaries still hold**

Log out, log in as a `user`-role account (or create one via `admin/users.php` if none exists), and confirm `admin/schools.php` returns "YOU DO NOT HAVE PERMISSION TO ACCESS THIS PAGE." (403) and the `SCHOOLS` sidebar link is absent — this subsystem should be exactly as administrator-only as `admin/users.php` and `admin/activity_log.php`.

- [ ] **Step 5: Stop the server and clean up**

Stop the PHP built-in server, delete `prepend_root.php`, and remove any throwaway school/upload/graduate data created during this walkthrough that would otherwise pollute the dev database (keep any pre-existing real data untouched).

No commit for this task unless a step surfaced a real bug — in that case, fix it in-place, re-run the relevant PHPUnit tests, and commit the fix with a message describing what was wrong.
