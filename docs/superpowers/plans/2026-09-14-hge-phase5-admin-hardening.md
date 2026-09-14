# Phase 5 (Admin + Hardening) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close out the original 5-phase HGE Database build with user management, an activity log viewer, and a focused set of security/correctness hardening fixes deferred from Phases 1-4, then prove the whole system works end-to-end in one scripted walkthrough.

**Architecture:** Two new admin subsystems (`admin/users.php`, `admin/activity_log.php`) follow the two established page patterns from this project: user management reuses the "page + modal + `postJson` + full reload" pattern from Phase 3's `admin/review.php`/`review.js` (infrequent, low-volume actions — a reload is fine); the activity log reuses the "AJAX + server-rendered initial state + `history.pushState`" pattern from Phase 4's `admin/lookup.php`/`lookup.js` (a paginated, filterable table). The six hardening fixes are small, targeted edits to already-shipped files. A new shared `assets/js/utils.js` holds one canonical `escapeHtml()`, ending three phases of that function being redefined per-file — both new JS files in this phase consume it, and `upload.js`'s unsafe copy is replaced by it.

**Tech Stack:** PHP 8.2 (PDO, prepared statements), MariaDB, vanilla JS (`fetch`, `history.pushState`), Tailwind (compiled, no new build step).

**Spec:** `docs/superpowers/specs/2026-09-12-hge-database-design.md` (section 2 for the original phase breakdown, section 14 for the Phase 5 addendum with every decision made — read both before starting).

## Global Constraints

- PHP >= 8.1, all queries via PDO prepared statements, all output escaped at render time with `e()`/`e_upper()` from `includes/functions.php`.
- `admin/users.php`, `admin/activity_log.php`, and their API endpoints all call `require_role('administrator')` — these two subsystems are administrator-only, unlike Phase 4's role-agnostic look-up page.
- **Self-lockout guard, server-enforced:** the currently-authenticated administrator can never disable their own account or change their own role away from `administrator`, via any path — the UI omits those controls for the logged-in user's own row, but `api/update_user.php` independently rejects any request that would do so, so the guard cannot be bypassed by calling the API directly.
- **Activity log retention:** `log_activity()` (added Phase 4, `includes/functions.php`) purges any row older than 6 months every time it inserts a new one. This is a real data-loss decision, not cosmetic — entries older than 6 months are gone, not hidden.
- Two new `activity_log.action` ENUM values are added this phase: `user_created`, `user_updated`. Both `database/schema.sql` (the single source of truth for fresh/test databases) and the already-existing live dev database need this change — the live database needs an explicit `ALTER TABLE` since schema.sql is only ever read for fresh `CREATE TABLE`s.
- No FULLTEXT search, no email-based password reset flow (this app has no email infrastructure anywhere in its design), no Schools admin CRUD, no project-wide global exception handler, no pagination added to `admin/review.php` — all explicitly out of scope for this phase per spec section 14.
- Thin `api/*.php` wrappers and `admin/*.php` views get no PHPUnit tests, matching the convention established in every prior phase (verified instead by manual/browser testing) — only `includes/*.php` data-layer functions get PHPUnit tests, against a real MySQL test database via `Tests\TestDb`.

---

### Task 1: Hardening — `includes/auth.php` (CSRF rotation, cookie clearing, timing-safe login)

**Files:**
- Modify: `includes/auth.php` (three functions: `attempt_login()`, `login_user()`, `logout_user()`)
- Test: `tests/includes/AuthLoginTest.php` (append one test method)

**Interfaces:**
- No signature changes to any of the three functions — `attempt_login(PDO $pdo, string $username, string $password): ?array`, `login_user(array $user): void`, `logout_user(): void` all keep their existing contracts. Only internal behavior changes.

- [ ] **Step 1: Write the failing test**

Append to `tests/includes/AuthLoginTest.php` (inside the `AuthLoginTest` class, after `testLogoutUserClearsCurrentUser`):

```php
    public function testLoginUserRotatesCsrfToken(): void
    {
        $oldToken = csrf_token();
        $user = attempt_login(TestDb::pdo(), 'admin', 'secret123');
        login_user($user);

        $newToken = csrf_token();
        $this->assertNotSame($oldToken, $newToken);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/includes/AuthLoginTest.php`
Expected: FAIL — `testLoginUserRotatesCsrfToken` fails because `login_user()` does not yet touch the CSRF token, so `$oldToken` and `$newToken` are identical.

- [ ] **Step 3: Implement all three fixes**

Replace `attempt_login()` in `includes/auth.php`:

```php
function attempt_login(PDO $pdo, string $username, string $password): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, name, username, email, password, role, status FROM users WHERE username = :username LIMIT 1'
    );
    $stmt->execute(['username' => $username]);
    $user = $stmt->fetch();

    // A fixed, pre-generated bcrypt hash with no matching plaintext password.
    // Used only to keep password_verify()'s timing identical whether or not
    // the username exists, closing a username-enumeration side channel.
    $dummyHash = '$2y$10$237rIx3i.u4rgcupmeT9IeWgfVojp/ALex2FJywvBWhCDVYo0GCo.';
    $hashToVerify = ($user && $user['status'] === 'active') ? $user['password'] : $dummyHash;
    $passwordValid = password_verify($password, $hashToVerify);

    if (!$user || $user['status'] !== 'active' || !$passwordValid) {
        return null;
    }

    unset($user['password']);
    return $user;
}
```

Replace `login_user()`:

```php
function login_user(array $user): void
{
    ensure_session();
    session_regenerate_id(true);
    unset($_SESSION['csrf_token']);
    $_SESSION['user'] = [
        'id' => $user['id'],
        'name' => $user['name'],
        'username' => $user['username'],
        'role' => $user['role'],
    ];
}
```

Replace `logout_user()`:

```php
function logout_user(): void
{
    ensure_session();
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }

    session_destroy();
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/includes/AuthLoginTest.php`
Expected: PASS, all 6 tests green (5 existing + the new one).

- [ ] **Step 5: Run the full suite**

Run: `vendor/bin/phpunit`
Expected: PASS — no regressions. (The timing-safety change to `attempt_login()` and the cookie-clearing line in `logout_user()` are not independently unit-tested: wall-clock timing assertions are flaky and out of place in this project's test style, and PHP's CLI test runner has no real HTTP response to inspect a `Set-Cookie` header against. Both are verified by code review of the diff — `password_verify()` is now called unconditionally on every path — and by the browser walkthrough in Task 12.)

- [ ] **Step 6: Commit**

```bash
git add includes/auth.php tests/includes/AuthLoginTest.php
git commit -m "fix: rotate CSRF token at login, clear session cookie at logout, close login timing side-channel"
```

---

### Task 2: Hardening — shared `escapeHtml()`, rollback guard, `.gitignore`

**Files:**
- Create: `assets/js/utils.js`
- Modify: `includes/footer.php` (add a `<script>` tag)
- Modify: `assets/js/upload.js` (remove its local `escapeHtml()`)
- Modify: `includes/extraction_review.php` (guard `save_staged_records()`'s `rollBack()` call)
- Modify: `.gitignore`

**Interfaces:**
- Produces: a global `escapeHtml(value): string` function (no module system in this project — every page-specific script that needs it just calls the global function, same as every page already calls the global `showModal()`/`showToast()`). Consumed by Task 7's `assets/js/users.js` and Task 11's `assets/js/activity_log.js`.

- [ ] **Step 1: Create the shared utility**

Create `assets/js/utils.js`:

```js
function escapeHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}
```

- [ ] **Step 2: Load it before every page-specific script**

In `includes/footer.php`, add a line before the existing three `<script>` tags so it loads first:

```php
<script src="<?= e(APP_BASE_URL) ?>/assets/js/utils.js"></script>
<script src="<?= e(APP_BASE_URL) ?>/assets/js/sidebar.js"></script>
<script src="<?= e(APP_BASE_URL) ?>/assets/js/toast.js"></script>
<script src="<?= e(APP_BASE_URL) ?>/assets/js/modal.js"></script>
```

- [ ] **Step 3: Remove `upload.js`'s unsafe local copy**

In `assets/js/upload.js`, delete this function (it currently sits between `fileInput.addEventListener('change', ...)` and `submitForm`):

```js
    function escapeHtml(value) {
        var div = document.createElement('div');
        div.textContent = value;
        return div.innerHTML;
    }
```

Do not replace it with anything — `upload.js` will now use the global `escapeHtml()` from `utils.js`, which `footer.php` guarantees loads first. No other line in `upload.js` changes; its two existing calls to `escapeHtml(...)` (inside the duplicate-upload modal's `bodyHtml`) now resolve to the safe global implementation instead.

- [ ] **Step 4: Guard the rollback call**

In `includes/extraction_review.php`, inside `save_staged_records()`, replace:

```php
    } catch (\Throwable $e) {
        $pdo->rollBack();
        error_log('save_staged_records failed: ' . $e->getMessage());
        return ['status' => 'error', 'message' => 'THE RECORDS COULD NOT BE SAVED. PLEASE TRY AGAIN.'];
    }
```

with:

```php
    } catch (\Throwable $e) {
        try {
            $pdo->rollBack();
        } catch (\Throwable $rollbackError) {
            error_log('save_staged_records rollback failed: ' . $rollbackError->getMessage());
        }
        error_log('save_staged_records failed: ' . $e->getMessage());
        return ['status' => 'error', 'message' => 'THE RECORDS COULD NOT BE SAVED. PLEASE TRY AGAIN.'];
    }
```

- [ ] **Step 5: Update `.gitignore`**

Add two lines to `.gitignore`:

```
.phpunit.result.cache
composer.phar
```

- [ ] **Step 6: Run the full suite**

Run: `vendor/bin/phpunit`
Expected: PASS, no regressions — in particular `tests/includes/SaveStagedRecordsTest.php::testRollsBackAllRowsOnDatabaseFailureAndLeavesUploadUnchanged` must still pass unchanged, proving the guarded rollback still rolls back correctly on the happy-path-of-failure. (There is no new automated test for the nested-rollback-failure edge case itself — simulating a rollback that itself throws requires mocking PDO, which this project's test style avoids entirely; the defensive nested `try`/`catch` is verified by code review.)

Also run `php -l` on every modified PHP file to confirm no syntax errors:

```bash
php -l includes/extraction_review.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 7: Commit**

```bash
git add assets/js/utils.js includes/footer.php assets/js/upload.js includes/extraction_review.php .gitignore
git commit -m "fix: add shared escapeHtml(), close upload.js's unsafe copy, guard rollback failure, gitignore cleanup"
```

---

### Task 3: Activity log 6-month retention purge

**Files:**
- Modify: `includes/functions.php` (`log_activity()`)
- Test: `tests/includes/GraduateLookupHelpersTest.php` (append one test method)

**Interfaces:**
- No signature change to `log_activity(PDO $pdo, ?int $userId, string $action, ?string $details = null): void` — same contract, additional side effect.

- [ ] **Step 1: Write the failing test**

Append to `tests/includes/GraduateLookupHelpersTest.php` (inside the `GraduateLookupHelpersTest` class, after `testLogActivityAllowsNullUserId`):

```php
    public function testLogActivityPurgesEntriesOlderThanSixMonths(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO activity_log (user_id, action, details, created_at) VALUES (1, 'login', 'old', DATE_SUB(NOW(), INTERVAL 7 MONTH))");
        $pdo->exec("INSERT INTO activity_log (user_id, action, details, created_at) VALUES (1, 'login', 'recent', DATE_SUB(NOW(), INTERVAL 1 MONTH))");

        log_activity($pdo, 1, 'search', 'name=test');

        $count = (int) $pdo->query('SELECT COUNT(*) FROM activity_log')->fetchColumn();
        $this->assertSame(2, $count);

        $oldStillExists = (int) $pdo->query("SELECT COUNT(*) FROM activity_log WHERE details = 'old'")->fetchColumn();
        $this->assertSame(0, $oldStillExists);

        $recentStillExists = (int) $pdo->query("SELECT COUNT(*) FROM activity_log WHERE details = 'recent'")->fetchColumn();
        $this->assertSame(1, $recentStillExists);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter testLogActivityPurgesEntriesOlderThanSixMonths`
Expected: FAIL — the 7-month-old row is still present because `log_activity()` doesn't purge anything yet; `$count` is 3, not 2.

- [ ] **Step 3: Implement**

Replace `log_activity()` in `includes/functions.php`:

```php
function log_activity(PDO $pdo, ?int $userId, string $action, ?string $details = null): void
{
    $stmt = $pdo->prepare('INSERT INTO activity_log (user_id, action, details) VALUES (:user_id, :action, :details)');
    $stmt->execute([
        'user_id' => $userId,
        'action' => $action,
        'details' => $details,
    ]);

    $pdo->exec('DELETE FROM activity_log WHERE created_at < (NOW() - INTERVAL 6 MONTH)');
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/includes/GraduateLookupHelpersTest.php`
Expected: PASS, all 5 tests green (4 existing + the new one).

- [ ] **Step 5: Run the full suite**

Run: `vendor/bin/phpunit`
Expected: PASS, no regressions.

- [ ] **Step 6: Commit**

```bash
git add includes/functions.php tests/includes/GraduateLookupHelpersTest.php
git commit -m "feat: purge activity_log entries older than 6 months on every write"
```

---

### Task 4: `includes/users.php` — user management data layer

**Files:**
- Create: `includes/users.php`
- Test: `tests/includes/UsersTest.php` (new)

**Interfaces:**
- Produces: `get_all_users(PDO $pdo): array` — rows of `['id', 'name', 'username', 'email', 'role', 'status']`, ordered by name, never includes `password`.
- Produces: `create_user(PDO $pdo, array $data): array` — `$data` keys `name`, `username`, `email`, `password`, `role`. Returns `['status' => 'success', 'message' => string, 'id' => int]` or `['status' => 'error', 'message' => string]`.
- Produces: `update_user_role(PDO $pdo, int $id, string $role): array` — `$role` must be `'administrator'` or `'user'`. Returns `['status' => 'success'|'error', 'message' => string]`.
- Produces: `set_user_status(PDO $pdo, int $id, string $status): array` — `$status` must be `'active'` or `'disabled'`. Same return shape.
- Produces: `reset_user_password(PDO $pdo, int $id, string $newPassword): array` — same return shape.
- Consumed by: Task 5 (`api/create_user.php`, `api/update_user.php`) and Task 6 (`admin/users.php`).
- Note for the reviewer: none of these four mutating functions enforce the self-lockout guard — that is deliberately Task 5's responsibility (the API layer, which has the "who is the currently logged-in user" context these generic data-layer functions don't and shouldn't need). Do not flag the absence of a self-check here as a spec violation.

- [ ] **Step 1: Write the failing tests**

Create `tests/includes/UsersTest.php`:

```php
<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;
use Tests\TestDb;

require_once __DIR__ . '/../../includes/users.php';
require_once __DIR__ . '/../../includes/auth.php';

class UsersTest extends TestCase
{
    protected function setUp(): void
    {
        TestDb::resetSchema();
    }

    public function testGetAllUsersReturnsAllOrderedByName(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO users (name, username, email, password, role) VALUES ('Zed Cruz', 'zed', 'zed@a.com', 'x', 'user')");
        $pdo->exec("INSERT INTO users (name, username, email, password, role) VALUES ('Ana Reyes', 'ana', 'ana@a.com', 'x', 'administrator')");

        $users = get_all_users($pdo);

        $this->assertCount(2, $users);
        $this->assertSame('Ana Reyes', $users[0]['name']);
        $this->assertSame('Zed Cruz', $users[1]['name']);
        $this->assertArrayNotHasKey('password', $users[0]);
    }

    public function testCreateUserInsertsNewUser(): void
    {
        $pdo = TestDb::pdo();

        $result = create_user($pdo, [
            'name' => 'Ana Reyes',
            'username' => 'ana',
            'email' => 'ana@a.com',
            'password' => 'secret123',
            'role' => 'user',
        ]);

        $this->assertSame('success', $result['status']);
        $this->assertArrayHasKey('id', $result);

        $count = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE username = 'ana'")->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testCreateUserAllowsLoginAfterCreation(): void
    {
        $pdo = TestDb::pdo();
        create_user($pdo, ['name' => 'Ana Reyes', 'username' => 'ana', 'email' => 'ana@a.com', 'password' => 'secret123', 'role' => 'user']);

        $user = attempt_login($pdo, 'ana', 'secret123');
        $this->assertNotNull($user);
        $this->assertSame('user', $user['role']);
    }

    public function testCreateUserRejectsMissingFields(): void
    {
        $result = create_user(TestDb::pdo(), ['name' => '', 'username' => 'ana', 'email' => 'ana@a.com', 'password' => 'secret123']);
        $this->assertSame('error', $result['status']);
    }

    public function testCreateUserRejectsShortPassword(): void
    {
        $result = create_user(TestDb::pdo(), ['name' => 'Ana', 'username' => 'ana', 'email' => 'ana@a.com', 'password' => 'short']);
        $this->assertSame('error', $result['status']);
    }

    public function testCreateUserRejectsInvalidRole(): void
    {
        $result = create_user(TestDb::pdo(), ['name' => 'Ana', 'username' => 'ana', 'email' => 'ana@a.com', 'password' => 'secret123', 'role' => 'superadmin']);
        $this->assertSame('error', $result['status']);
    }

    public function testCreateUserRejectsDuplicateUsername(): void
    {
        $pdo = TestDb::pdo();
        create_user($pdo, ['name' => 'Ana', 'username' => 'ana', 'email' => 'ana1@a.com', 'password' => 'secret123']);

        $result = create_user($pdo, ['name' => 'Another Ana', 'username' => 'ana', 'email' => 'ana2@a.com', 'password' => 'secret123']);

        $this->assertSame('error', $result['status']);
        $count = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE username = 'ana'")->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testCreateUserRejectsDuplicateEmail(): void
    {
        $pdo = TestDb::pdo();
        create_user($pdo, ['name' => 'Ana', 'username' => 'ana1', 'email' => 'ana@a.com', 'password' => 'secret123']);

        $result = create_user($pdo, ['name' => 'Another', 'username' => 'ana2', 'email' => 'ana@a.com', 'password' => 'secret123']);

        $this->assertSame('error', $result['status']);
    }

    public function testUpdateUserRoleChangesRole(): void
    {
        $pdo = TestDb::pdo();
        $created = create_user($pdo, ['name' => 'Ana', 'username' => 'ana', 'email' => 'ana@a.com', 'password' => 'secret123', 'role' => 'user']);

        $result = update_user_role($pdo, $created['id'], 'administrator');

        $this->assertSame('success', $result['status']);
        $role = $pdo->query("SELECT role FROM users WHERE username = 'ana'")->fetchColumn();
        $this->assertSame('administrator', $role);
    }

    public function testUpdateUserRoleRejectsInvalidRole(): void
    {
        $result = update_user_role(TestDb::pdo(), 1, 'superadmin');
        $this->assertSame('error', $result['status']);
    }

    public function testSetUserStatusTogglesStatus(): void
    {
        $pdo = TestDb::pdo();
        $created = create_user($pdo, ['name' => 'Ana', 'username' => 'ana', 'email' => 'ana@a.com', 'password' => 'secret123']);

        $result = set_user_status($pdo, $created['id'], 'disabled');

        $this->assertSame('success', $result['status']);
        $this->assertNull(attempt_login($pdo, 'ana', 'secret123'));
    }

    public function testSetUserStatusRejectsInvalidStatus(): void
    {
        $result = set_user_status(TestDb::pdo(), 1, 'archived');
        $this->assertSame('error', $result['status']);
    }

    public function testResetUserPasswordChangesPasswordAndAllowsLogin(): void
    {
        $pdo = TestDb::pdo();
        $created = create_user($pdo, ['name' => 'Ana', 'username' => 'ana', 'email' => 'ana@a.com', 'password' => 'old-password']);

        $result = reset_user_password($pdo, $created['id'], 'new-password-123');

        $this->assertSame('success', $result['status']);
        $this->assertNull(attempt_login($pdo, 'ana', 'old-password'));
        $this->assertNotNull(attempt_login($pdo, 'ana', 'new-password-123'));
    }

    public function testResetUserPasswordRejectsShortPassword(): void
    {
        $result = reset_user_password(TestDb::pdo(), 1, 'short');
        $this->assertSame('error', $result['status']);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/includes/UsersTest.php`
Expected: FAIL — none of the five functions exist yet.

- [ ] **Step 3: Implement**

Create `includes/users.php`:

```php
<?php

function get_all_users(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT id, name, username, email, role, status FROM users ORDER BY name ASC');
    return $stmt->fetchAll();
}

function create_user(PDO $pdo, array $data): array
{
    $name = trim((string) ($data['name'] ?? ''));
    $username = trim((string) ($data['username'] ?? ''));
    $email = trim((string) ($data['email'] ?? ''));
    $password = (string) ($data['password'] ?? '');
    $role = (string) ($data['role'] ?? 'user');

    if ($name === '' || $username === '' || $email === '' || $password === '') {
        return ['status' => 'error', 'message' => 'ALL FIELDS ARE REQUIRED.'];
    }

    if (mb_strlen($password) < 8) {
        return ['status' => 'error', 'message' => 'PASSWORD MUST BE AT LEAST 8 CHARACTERS.'];
    }

    if (!in_array($role, ['administrator', 'user'], true)) {
        return ['status' => 'error', 'message' => 'INVALID ROLE.'];
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO users (name, username, email, password, role) VALUES (:name, :username, :email, :password, :role)'
        );
        $stmt->execute([
            'name' => $name,
            'username' => $username,
            'email' => $email,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $role,
        ]);
    } catch (\PDOException $e) {
        if ($e->getCode() === '23000') {
            return ['status' => 'error', 'message' => 'A USER WITH THIS USERNAME OR EMAIL ALREADY EXISTS.'];
        }
        throw $e;
    }

    return ['status' => 'success', 'message' => 'USER CREATED SUCCESSFULLY.', 'id' => (int) $pdo->lastInsertId()];
}

function update_user_role(PDO $pdo, int $id, string $role): array
{
    if (!in_array($role, ['administrator', 'user'], true)) {
        return ['status' => 'error', 'message' => 'INVALID ROLE.'];
    }

    $stmt = $pdo->prepare('UPDATE users SET role = :role WHERE id = :id');
    $stmt->execute(['role' => $role, 'id' => $id]);

    return ['status' => 'success', 'message' => 'ROLE UPDATED.'];
}

function set_user_status(PDO $pdo, int $id, string $status): array
{
    if (!in_array($status, ['active', 'disabled'], true)) {
        return ['status' => 'error', 'message' => 'INVALID STATUS.'];
    }

    $stmt = $pdo->prepare('UPDATE users SET status = :status WHERE id = :id');
    $stmt->execute(['status' => $status, 'id' => $id]);

    return ['status' => 'success', 'message' => 'STATUS UPDATED.'];
}

function reset_user_password(PDO $pdo, int $id, string $newPassword): array
{
    if (mb_strlen($newPassword) < 8) {
        return ['status' => 'error', 'message' => 'PASSWORD MUST BE AT LEAST 8 CHARACTERS.'];
    }

    $stmt = $pdo->prepare('UPDATE users SET password = :password WHERE id = :id');
    $stmt->execute(['password' => password_hash($newPassword, PASSWORD_DEFAULT), 'id' => $id]);

    return ['status' => 'success', 'message' => 'PASSWORD RESET SUCCESSFULLY.'];
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/includes/UsersTest.php`
Expected: PASS, all 13 tests green.

- [ ] **Step 5: Commit**

```bash
git add includes/users.php tests/includes/UsersTest.php
git commit -m "feat: add user management data layer (includes/users.php)"
```

---

### Task 5: `api/create_user.php` + `api/update_user.php`, and the `activity_log` schema extension

**Files:**
- Modify: `database/schema.sql` (extend `activity_log.action` ENUM)
- Create: `api/create_user.php`
- Create: `api/update_user.php`

**Interfaces:**
- Consumes: `create_user()`, `update_user_role()`, `set_user_status()`, `reset_user_password()` (Task 4), `require_role()`/`csrf_verify()`/`current_user()` (`includes/auth.php`), `log_activity()` (Task 3's version, `includes/functions.php`), `get_pdo()` (`config/database.php`).
- Produces (JSON, `Content-Type: application/json`, both endpoints):
  - `api/create_user.php`: same shape `create_user()` returns (`{"status":"success","message":"...","id":N}` or `{"status":"error","message":"..."}`), plus HTTP 400 on CSRF failure and HTTP 500 + generic message on any `\Throwable`.
  - `api/update_user.php`: `{"status":"success","message":"USER UPDATED SUCCESSFULLY."}` when at least one of `role`/`status`/`new_password` was submitted and applied, `{"status":"error","message":"..."}` otherwise (including the two self-lockout rejections), same 400/500 handling.
- **Self-lockout guard lives here**, not in `includes/users.php` (see Task 4's note): `api/update_user.php` compares `$_POST['user_id']` against `current_user()['id']` and rejects a self-demotion or self-disable before touching the database.

- [ ] **Step 1: Extend the `activity_log.action` ENUM**

In `database/schema.sql`, find the `activity_log` table's `action` column:

```sql
  action ENUM('login','logout','pdf_upload','pdf_delete','record_created','record_updated','record_removed','search') NOT NULL,
```

Replace with:

```sql
  action ENUM('login','logout','pdf_upload','pdf_delete','record_created','record_updated','record_removed','search','user_created','user_updated') NOT NULL,
```

This is picked up automatically by `Tests\TestDb::resetSchema()` (it re-reads `database/schema.sql` fresh before every test run) — no separate step needed for the test database. The already-existing live `hge_masterlist` dev database is a separate, already-created table and needs an explicit `ALTER TABLE` — that happens in Task 12, right before the manual browser walkthrough needs the new enum values to work.

- [ ] **Step 2: Implement `api/create_user.php`**

```php
<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/users.php';

require_role('administrator');

header('Content-Type: application/json');

if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'YOUR SESSION EXPIRED. PLEASE TRY AGAIN.']);
    exit;
}

try {
    $pdo = get_pdo();
    $username = trim((string) ($_POST['username'] ?? ''));

    $result = create_user($pdo, [
        'name' => $_POST['name'] ?? '',
        'username' => $username,
        'email' => $_POST['email'] ?? '',
        'password' => $_POST['password'] ?? '',
        'role' => $_POST['role'] ?? 'user',
    ]);

    if ($result['status'] === 'success') {
        try {
            $currentUser = current_user();
            log_activity($pdo, $currentUser['id'] ?? null, 'user_created', 'username=' . $username);
        } catch (\Throwable $logError) {
            error_log('Activity log failed: ' . $logError->getMessage());
        }
    }

    echo json_encode($result);
} catch (\Throwable $e) {
    error_log('Create user failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'THE USER COULD NOT BE CREATED. PLEASE TRY AGAIN.']);
}
```

- [ ] **Step 3: Implement `api/update_user.php`**

```php
<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/users.php';

require_role('administrator');

header('Content-Type: application/json');

if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'YOUR SESSION EXPIRED. PLEASE TRY AGAIN.']);
    exit;
}

$userId = (int) ($_POST['user_id'] ?? 0);
$currentUser = current_user();

if ($userId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'USER NOT FOUND.']);
    exit;
}

$role = isset($_POST['role']) && $_POST['role'] !== '' ? (string) $_POST['role'] : null;
$status = isset($_POST['status']) && $_POST['status'] !== '' ? (string) $_POST['status'] : null;
$newPassword = isset($_POST['new_password']) && $_POST['new_password'] !== '' ? (string) $_POST['new_password'] : null;

$isSelf = $currentUser !== null && (int) $currentUser['id'] === $userId;

if ($isSelf && $role !== null && $role !== 'administrator') {
    echo json_encode(['status' => 'error', 'message' => 'YOU CANNOT CHANGE YOUR OWN ROLE.']);
    exit;
}

if ($isSelf && $status === 'disabled') {
    echo json_encode(['status' => 'error', 'message' => 'YOU CANNOT DISABLE YOUR OWN ACCOUNT.']);
    exit;
}

try {
    $pdo = get_pdo();
    $details = [];

    if ($role !== null) {
        $result = update_user_role($pdo, $userId, $role);
        if ($result['status'] !== 'success') {
            echo json_encode($result);
            exit;
        }
        $details[] = 'role=' . $role;
    }

    if ($status !== null) {
        $result = set_user_status($pdo, $userId, $status);
        if ($result['status'] !== 'success') {
            echo json_encode($result);
            exit;
        }
        $details[] = 'status=' . $status;
    }

    if ($newPassword !== null) {
        $result = reset_user_password($pdo, $userId, $newPassword);
        if ($result['status'] !== 'success') {
            echo json_encode($result);
            exit;
        }
        $details[] = 'password_reset';
    }

    if (empty($details)) {
        echo json_encode(['status' => 'error', 'message' => 'NO CHANGES WERE SUBMITTED.']);
        exit;
    }

    try {
        log_activity($pdo, $currentUser['id'] ?? null, 'user_updated', 'user_id=' . $userId . ', ' . implode(', ', $details));
    } catch (\Throwable $logError) {
        error_log('Activity log failed: ' . $logError->getMessage());
    }

    echo json_encode(['status' => 'success', 'message' => 'USER UPDATED SUCCESSFULLY.']);
} catch (\Throwable $e) {
    error_log('Update user failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'THE USER COULD NOT BE UPDATED. PLEASE TRY AGAIN.']);
}
```

There is no PHPUnit test for either file — both are thin wrappers, per this project's established convention (see Global Constraints). Verify manually (Step 4) and again in Task 12's end-to-end pass.

- [ ] **Step 4: Manually verify both endpoints and the self-lockout guard**

`php` is not on PATH in some environments; use the full path to your PHP binary if `php` alone doesn't resolve (e.g. `C:/xampp/php/php.exe` on this project's Windows/XAMPP setup).

Write a throwaway PHP script (not committed — delete it when done) that fakes an administrator session and requires each endpoint directly with `$_POST` populated, capturing output via `ob_start()`/`ob_get_clean()`, similar to how Phase 4's Task 3 verified `api/search_graduates.php`. Confirm:
- `api/create_user.php` with valid fields returns `{"status":"success",...}` and a new row appears in `users`.
- `api/update_user.php` with `user_id` set to a DIFFERENT user's id and `status=disabled` succeeds.
- `api/update_user.php` with `user_id` set to the FAKED SESSION'S OWN id and `status=disabled` (or `role=user`) returns the self-lockout error message and does NOT change the database row.
- Run `vendor/bin/phpunit` once — must stay green (this task adds no new PHPUnit tests, only manual verification).

Delete the throwaway script before finishing.

- [ ] **Step 5: Commit**

```bash
git add database/schema.sql api/create_user.php api/update_user.php
git commit -m "feat: add create/update user API endpoints with server-enforced self-lockout guard"
```

---

### Task 6: `admin/users.php` — user management page

**Files:**
- Create: `admin/users.php`
- Modify: `includes/sidebar.php` (add an administrator-only nav section)

**Interfaces:**
- Consumes: `get_all_users()` (Task 4), `require_role()`/`current_user()`/`csrf_token()` (`includes/auth.php`), `e()`/`e_upper()` (`includes/functions.php`), `includes/header.php`/`includes/sidebar.php`/`includes/footer.php` (existing partials).
- Produces: `window.HGE_USERS = { csrfToken, apiBase, currentUserId }` and the DOM contract Task 7's JS depends on:
  - `#create-user-btn` — opens the create-user modal.
  - Each row's `.edit-user-btn` carries `data-user-id`, `data-name`, `data-username`, `data-role`, `data-status`, `data-is-self` (`"1"` or `"0"`).

There is no PHPUnit test for this file (it's a view, like `admin/dashboard.php`). Verify it as part of Task 12's browser pass.

- [ ] **Step 1: Implement**

Create `admin/users.php`:

```php
<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/users.php';

require_role('administrator');

$pdo = get_pdo();
$users = get_all_users($pdo);
$currentUser = current_user();
$currentPage = 'users';
$csrfToken = csrf_token();

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main class="lg:ml-64 min-h-screen bg-surface-950">
    <div class="p-6 lg:p-8">
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-xl font-semibold text-white">USER MANAGEMENT</h1>
            <button type="button" id="create-user-btn" class="rounded-lg bg-accent-600 hover:bg-accent-500 text-white text-sm font-medium px-4 py-2">CREATE USER</button>
        </div>

        <div class="bg-surface-900 border border-white/10 rounded-xl overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead>
                    <tr class="text-xs text-gray-500 border-b border-white/10">
                        <th class="px-4 py-3">NAME</th>
                        <th class="px-4 py-3">USERNAME</th>
                        <th class="px-4 py-3">EMAIL</th>
                        <th class="px-4 py-3">ROLE</th>
                        <th class="px-4 py-3">STATUS</th>
                        <th class="px-4 py-3">ACTION</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                        <?php $isSelf = (int) $user['id'] === (int) $currentUser['id']; ?>
                        <tr class="border-b border-white/5 hover:bg-white/5">
                            <td class="px-4 py-3"><?= e_upper($user['name']) ?></td>
                            <td class="px-4 py-3"><?= e($user['username']) ?></td>
                            <td class="px-4 py-3"><?= e($user['email']) ?></td>
                            <td class="px-4 py-3"><?= e_upper($user['role']) ?></td>
                            <td class="px-4 py-3">
                                <span class="<?= $user['status'] === 'active' ? 'text-green-400' : 'text-red-400' ?>"><?= e_upper($user['status']) ?></span>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <button type="button" class="edit-user-btn text-accent-400 hover:underline"
                                    data-user-id="<?= (int) $user['id'] ?>"
                                    data-name="<?= e($user['name']) ?>"
                                    data-username="<?= e($user['username']) ?>"
                                    data-role="<?= e($user['role']) ?>"
                                    data-status="<?= e($user['status']) ?>"
                                    data-is-self="<?= $isSelf ? '1' : '0' ?>">EDIT</button>
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
window.HGE_USERS = {
    csrfToken: <?= json_encode($csrfToken) ?>,
    apiBase: <?= json_encode(APP_BASE_URL) ?>,
    currentUserId: <?= (int) $currentUser['id'] ?>
};
</script>
<script src="<?= e(APP_BASE_URL) ?>/assets/js/users.js"></script>
```

- [ ] **Step 2: Add the administrator-only nav section**

In `includes/sidebar.php`, inside the `<nav>` element, after the existing `UPLOAD LATIN HONOR LIST` link and before the closing `</nav>` tag, add:

```php
        <?php if (user_has_role($currentUser ?? null, 'administrator')): ?>
        <a href="<?= e(APP_BASE_URL) ?>/admin/users.php" class="flex items-center gap-3 rounded-lg px-3 py-2 <?= sidebar_nav_class($currentPage, 'users') ?>">
            USER MANAGEMENT
        </a>
        <?php endif; ?>
```

(Task 10 will add a second link inside this same `<?php if (...): ?>` block — leave room for it, don't close and reopen the conditional.)

Every page that includes `sidebar.php` already sets `$currentUser = current_user();` before the include (confirmed in `admin/dashboard.php`, `admin/lookup.php`, `admin/review.php`, `admin/view_upload.php`, and this task's own `admin/users.php`), and every such page already requires `includes/auth.php` (where `user_has_role()` lives) before including the sidebar — no new `require_once` needed in `sidebar.php` itself.

- [ ] **Step 3: Commit**

```bash
git add admin/users.php includes/sidebar.php
git commit -m "feat: add admin/users.php user management page with admin-only nav link"
```

---

### Task 7: `assets/js/users.js` — create/edit user modals

**Files:**
- Create: `assets/js/users.js`

**Interfaces:**
- Consumes: `window.HGE_USERS` (Task 6), `showModal()`/`showToast()` (existing `modal.js`/`toast.js`), the global `escapeHtml()` (Task 2's `utils.js`), the DOM contract from Task 6 (`#create-user-btn`, `.edit-user-btn`), and the JSON contracts of `api/create_user.php`/`api/update_user.php` (Task 5).
- No exports — page-specific script loaded only by `admin/users.php`, same pattern as `assets/js/review.js`.

- [ ] **Step 1: Implement**

Create `assets/js/users.js`:

```js
document.addEventListener('DOMContentLoaded', function () {
    var config = window.HGE_USERS;
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
            '<div><label class="block text-xs text-gray-400 mb-1">NAME</label>' +
            '<input id="modal-name" type="text" class="w-full rounded-lg bg-surface-800 border border-white/10 px-3 py-2 text-sm"></div>' +
            '<div><label class="block text-xs text-gray-400 mb-1">USERNAME</label>' +
            '<input id="modal-username" type="text" class="w-full rounded-lg bg-surface-800 border border-white/10 px-3 py-2 text-sm"></div>' +
            '<div><label class="block text-xs text-gray-400 mb-1">EMAIL</label>' +
            '<input id="modal-email" type="email" class="w-full rounded-lg bg-surface-800 border border-white/10 px-3 py-2 text-sm"></div>' +
            '<div><label class="block text-xs text-gray-400 mb-1">PASSWORD</label>' +
            '<input id="modal-password" type="password" class="w-full rounded-lg bg-surface-800 border border-white/10 px-3 py-2 text-sm"></div>' +
            '<div><label class="block text-xs text-gray-400 mb-1">ROLE</label>' +
            '<select id="modal-role" class="w-full rounded-lg bg-surface-800 border border-white/10 px-3 py-2 text-sm">' +
            '<option value="user">USER</option><option value="administrator">ADMINISTRATOR</option></select></div>' +
            '</div>';

        showModal({
            title: 'CREATE USER',
            bodyHtml: bodyHtml,
            buttons: [
                { label: 'CANCEL', className: 'rounded-lg px-4 py-2 text-sm text-gray-300 hover:bg-white/5' },
                {
                    label: 'CREATE',
                    className: 'rounded-lg px-4 py-2 text-sm bg-accent-600 hover:bg-accent-500 text-white',
                    onClick: function (modalClose) {
                        postJson(config.apiBase + '/api/create_user.php', {
                            csrf_token: config.csrfToken,
                            name: document.getElementById('modal-name').value,
                            username: document.getElementById('modal-username').value,
                            email: document.getElementById('modal-email').value,
                            password: document.getElementById('modal-password').value,
                            role: document.getElementById('modal-role').value,
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
                                showToast('error', 'THE USER COULD NOT BE CREATED. PLEASE TRY AGAIN.');
                            });
                    },
                },
            ],
        });
    }

    function openEditModal(defaults) {
        var isSelf = defaults.isSelf;
        var roleOptions = ['user', 'administrator'].map(function (opt) {
            var selected = opt === defaults.role ? ' selected' : '';
            var disabled = isSelf ? ' disabled' : '';
            return '<option value="' + opt + '"' + selected + disabled + '>' + opt.toUpperCase() + '</option>';
        }).join('');

        var bodyHtml =
            '<div class="space-y-3">' +
            '<div><label class="block text-xs text-gray-400 mb-1">NAME</label>' +
            '<p class="text-sm text-white">' + escapeHtml(defaults.name) + ' (' + escapeHtml(defaults.username) + ')</p></div>' +
            '<div><label class="block text-xs text-gray-400 mb-1">ROLE</label>' +
            '<select id="modal-role" class="w-full rounded-lg bg-surface-800 border border-white/10 px-3 py-2 text-sm"' + (isSelf ? ' disabled' : '') + '>' + roleOptions + '</select>' +
            (isSelf ? '<p class="text-xs text-gray-500 mt-1">YOU CANNOT CHANGE YOUR OWN ROLE.</p>' : '') +
            '</div>' +
            '<div><label class="block text-xs text-gray-400 mb-1">STATUS</label>' +
            '<select id="modal-status" class="w-full rounded-lg bg-surface-800 border border-white/10 px-3 py-2 text-sm"' + (isSelf ? ' disabled' : '') + '>' +
            '<option value="active"' + (defaults.status === 'active' ? ' selected' : '') + '>ACTIVE</option>' +
            '<option value="disabled"' + (defaults.status === 'disabled' ? ' selected' : '') + '>DISABLED</option></select>' +
            (isSelf ? '<p class="text-xs text-gray-500 mt-1">YOU CANNOT DISABLE YOUR OWN ACCOUNT.</p>' : '') +
            '</div>' +
            '<div><label class="block text-xs text-gray-400 mb-1">RESET PASSWORD (LEAVE BLANK TO KEEP CURRENT)</label>' +
            '<input id="modal-new-password" type="password" class="w-full rounded-lg bg-surface-800 border border-white/10 px-3 py-2 text-sm"></div>' +
            '</div>';

        showModal({
            title: 'EDIT USER',
            bodyHtml: bodyHtml,
            buttons: [
                { label: 'CANCEL', className: 'rounded-lg px-4 py-2 text-sm text-gray-300 hover:bg-white/5' },
                {
                    label: 'SAVE',
                    className: 'rounded-lg px-4 py-2 text-sm bg-accent-600 hover:bg-accent-500 text-white',
                    onClick: function (modalClose) {
                        var payload = {
                            csrf_token: config.csrfToken,
                            user_id: defaults.userId,
                        };
                        if (!isSelf) {
                            payload.role = document.getElementById('modal-role').value;
                            payload.status = document.getElementById('modal-status').value;
                        }
                        var newPassword = document.getElementById('modal-new-password').value;
                        if (newPassword !== '') {
                            payload.new_password = newPassword;
                        }

                        postJson(config.apiBase + '/api/update_user.php', payload)
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
                                showToast('error', 'THE USER COULD NOT BE UPDATED. PLEASE TRY AGAIN.');
                            });
                    },
                },
            ],
        });
    }

    var createBtn = document.getElementById('create-user-btn');
    if (createBtn) {
        createBtn.addEventListener('click', openCreateModal);
    }

    document.querySelectorAll('.edit-user-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            openEditModal({
                userId: btn.dataset.userId,
                name: btn.dataset.name,
                username: btn.dataset.username,
                role: btn.dataset.role,
                status: btn.dataset.status,
                isSelf: btn.dataset.isSelf === '1',
            });
        });
    });
});
```

Note: this file does not define its own `escapeHtml()` — it relies on the global one from `assets/js/utils.js` (Task 2), which `includes/footer.php` guarantees loads before this script.

- [ ] **Step 2: Commit**

```bash
git add assets/js/users.js
git commit -m "feat: add users.js for create/edit user modals"
```

---

### Task 8: `includes/activity.php` — activity log search data layer

**Files:**
- Create: `includes/activity.php`
- Test: `tests/includes/ActivityLogSearchTest.php` (new)

**Interfaces:**
- Consumes: `paginate()` from `includes/functions.php` (already exists — do not redefine it).
- Produces: `search_activity_log(PDO $pdo, array $filters, int $page, int $perPage = 25): array` — `$filters` keys are `user_id` (`?int`), `action` (`?string`), `date_from` (`?string`, `Y-m-d`), `date_to` (`?string`, `Y-m-d`). Returns `['rows' => array, 'pagination' => array]`, where each row is `['id', 'user_id', 'action', 'details', 'created_at', 'user_name', 'username']` (`user_name`/`username` are `NULL` when `user_id` is `NULL` — a system event) and `pagination` is exactly `paginate()`'s return shape.
- Unlike Phase 4's `search_graduates()`, there is **no "don't query with zero filters" policy** here — the activity log is meant to be browsable by default (most-recent-first), not a look-up tool. `search_activity_log([], 1)` legitimately returns the first page of everything, and Task 9/10 will call it unconditionally, with no has-filters gate.

- [ ] **Step 1: Write the failing tests**

Create `tests/includes/ActivityLogSearchTest.php`:

```php
<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;
use Tests\TestDb;

require_once __DIR__ . '/../../includes/activity.php';

class ActivityLogSearchTest extends TestCase
{
    protected function setUp(): void
    {
        TestDb::resetSchema();
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO users (name, username, email, password, role) VALUES ('Ana Reyes', 'ana', 'ana@a.com', 'x', 'administrator')");
        $pdo->exec("INSERT INTO users (name, username, email, password, role) VALUES ('Ben Cruz', 'ben', 'ben@a.com', 'x', 'user')");
    }

    private function insertLogRow(?int $userId, string $action, ?string $details, string $createdAt): void
    {
        $stmt = TestDb::pdo()->prepare(
            'INSERT INTO activity_log (user_id, action, details, created_at) VALUES (:user_id, :action, :details, :created_at)'
        );
        $stmt->execute(['user_id' => $userId, 'action' => $action, 'details' => $details, 'created_at' => $createdAt]);
    }

    public function testFiltersByUserId(): void
    {
        $this->insertLogRow(1, 'login', null, '2026-01-01 10:00:00');
        $this->insertLogRow(2, 'login', null, '2026-01-01 11:00:00');

        $result = search_activity_log(TestDb::pdo(), ['user_id' => 1], 1);

        $this->assertCount(1, $result['rows']);
        $this->assertSame('ana', $result['rows'][0]['username']);
    }

    public function testFiltersByAction(): void
    {
        $this->insertLogRow(1, 'login', null, '2026-01-01 10:00:00');
        $this->insertLogRow(1, 'logout', null, '2026-01-01 11:00:00');

        $result = search_activity_log(TestDb::pdo(), ['action' => 'logout'], 1);

        $this->assertCount(1, $result['rows']);
        $this->assertSame('logout', $result['rows'][0]['action']);
    }

    public function testFiltersByDateRange(): void
    {
        $this->insertLogRow(1, 'login', null, '2026-01-01 10:00:00');
        $this->insertLogRow(1, 'login', null, '2026-03-01 10:00:00');
        $this->insertLogRow(1, 'login', null, '2026-06-01 10:00:00');

        $result = search_activity_log(TestDb::pdo(), ['date_from' => '2026-02-01', 'date_to' => '2026-04-01'], 1);

        $this->assertCount(1, $result['rows']);
        $this->assertSame('2026-03-01 10:00:00', $result['rows'][0]['created_at']);
    }

    public function testCombinesFiltersWithAnd(): void
    {
        $this->insertLogRow(1, 'login', null, '2026-01-01 10:00:00');
        $this->insertLogRow(2, 'login', null, '2026-01-01 11:00:00');
        $this->insertLogRow(1, 'logout', null, '2026-01-01 12:00:00');

        $result = search_activity_log(TestDb::pdo(), ['user_id' => 1, 'action' => 'login'], 1);

        $this->assertCount(1, $result['rows']);
    }

    public function testReturnsRowsMostRecentFirst(): void
    {
        $this->insertLogRow(1, 'login', null, '2026-01-01 10:00:00');
        $this->insertLogRow(1, 'logout', null, '2026-01-02 10:00:00');

        $result = search_activity_log(TestDb::pdo(), [], 1);

        $this->assertSame('logout', $result['rows'][0]['action']);
        $this->assertSame('login', $result['rows'][1]['action']);
    }

    public function testHandlesNullUserIdAsSystemEvent(): void
    {
        $this->insertLogRow(null, 'login', null, '2026-01-01 10:00:00');

        $result = search_activity_log(TestDb::pdo(), [], 1);

        $this->assertNull($result['rows'][0]['user_id']);
        $this->assertNull($result['rows'][0]['user_name']);
    }

    public function testPaginatesResults(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->insertLogRow(1, 'login', null, "2026-01-0{$i} 10:00:00");
        }

        $page1 = search_activity_log(TestDb::pdo(), [], 1, 2);
        $page2 = search_activity_log(TestDb::pdo(), [], 2, 2);

        $this->assertCount(2, $page1['rows']);
        $this->assertCount(1, $page2['rows']);
        $this->assertSame(3, $page1['pagination']['total_rows']);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/includes/ActivityLogSearchTest.php`
Expected: FAIL — `search_activity_log` is undefined.

- [ ] **Step 3: Implement**

Create `includes/activity.php`:

```php
<?php

function search_activity_log(PDO $pdo, array $filters, int $page, int $perPage = 25): array
{
    $conditions = [];
    $params = [];

    if (!empty($filters['user_id'])) {
        $conditions[] = 'a.user_id = :user_id';
        $params['user_id'] = (int) $filters['user_id'];
    }

    if (!empty($filters['action'])) {
        $conditions[] = 'a.action = :action';
        $params['action'] = (string) $filters['action'];
    }

    $dateFrom = trim((string) ($filters['date_from'] ?? ''));
    if ($dateFrom !== '') {
        $conditions[] = 'a.created_at >= :date_from';
        $params['date_from'] = $dateFrom . ' 00:00:00';
    }

    $dateTo = trim((string) ($filters['date_to'] ?? ''));
    if ($dateTo !== '') {
        $conditions[] = 'a.created_at <= :date_to';
        $params['date_to'] = $dateTo . ' 23:59:59';
    }

    $whereSql = empty($conditions) ? '' : ('WHERE ' . implode(' AND ', $conditions));

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM activity_log a {$whereSql}");
    $countStmt->execute($params);
    $totalRows = (int) $countStmt->fetchColumn();

    $pagination = paginate($totalRows, $page, $perPage);

    $sql = "SELECT a.id, a.user_id, a.action, a.details, a.created_at, u.name AS user_name, u.username
            FROM activity_log a
            LEFT JOIN users u ON u.id = a.user_id
            {$whereSql}
            ORDER BY a.created_at DESC
            LIMIT :limit OFFSET :offset";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->bindValue('limit', $pagination['per_page'], PDO::PARAM_INT);
    $stmt->bindValue('offset', $pagination['offset'], PDO::PARAM_INT);
    $stmt->execute();

    return ['rows' => $stmt->fetchAll(), 'pagination' => $pagination];
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/includes/ActivityLogSearchTest.php`
Expected: PASS, all 7 tests green.

- [ ] **Step 5: Commit**

```bash
git add includes/activity.php tests/includes/ActivityLogSearchTest.php
git commit -m "feat: add search_activity_log() paginated search primitive"
```

---

### Task 9: `api/search_activity_log.php` — JSON search endpoint

**Files:**
- Create: `api/search_activity_log.php`

**Interfaces:**
- Consumes: `search_activity_log()` (Task 8), `require_role()` (`includes/auth.php`), `get_pdo()` (`config/database.php`).
- Produces (JSON): `{"status":"success","rows":[...],"pagination":{...}}` always (no has-filters gate, unlike Phase 4's search endpoint — see Task 8's note), or HTTP 500 + `{"status":"error","message":"THE ACTIVITY LOG COULD NOT BE LOADED. PLEASE TRY AGAIN."}` on any `\Throwable`.
- Unlike `api/search_graduates.php`, this endpoint does not call `log_activity()` — viewing the log is not itself one of the logged action types, and adding a new enum value/action for "log viewed" is not requested and would be unused elsewhere.

There is no PHPUnit test for this file (thin wrapper convention). Verify manually and again in Task 12.

- [ ] **Step 1: Implement**

Create `api/search_activity_log.php`:

```php
<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/activity.php';

require_role('administrator');

header('Content-Type: application/json');

$userId = (isset($_GET['user_id']) && (int) $_GET['user_id'] > 0) ? (int) $_GET['user_id'] : null;
$action = trim((string) ($_GET['action'] ?? ''));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));

$filters = [
    'user_id' => $userId,
    'action' => $action !== '' ? $action : null,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
];

try {
    $pdo = get_pdo();
    $result = search_activity_log($pdo, $filters, $page);

    echo json_encode([
        'status' => 'success',
        'rows' => $result['rows'],
        'pagination' => $result['pagination'],
    ]);
} catch (\Throwable $e) {
    error_log('Activity log search failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'THE ACTIVITY LOG COULD NOT BE LOADED. PLEASE TRY AGAIN.']);
}
```

- [ ] **Step 2: Manually verify the endpoint**

`php` is not on PATH in some environments; use the full path to your PHP binary if `php` alone doesn't resolve (e.g. `C:/xampp/php/php.exe` on this project's Windows/XAMPP setup).

Write a throwaway PHP script (not committed — delete it when done) that fakes an administrator session and `require`s the endpoint directly, the same technique used in Task 5's Step 4 and in Phase 4's Task 3:

```php
<?php
session_start();
$_SESSION['user'] = ['id' => 1, 'name' => 'Test', 'username' => 'test', 'role' => 'administrator'];
$_GET = [];
ob_start();
require __DIR__ . '/api/search_activity_log.php';
$output = ob_get_clean();
echo "NO FILTERS: {$output}\n";
```

Run it and confirm the response is `{"status":"success","rows":[...],"pagination":{...}}` even with `$_GET` completely empty — this proves there's no has-filters gate blocking the default view, unlike Phase 4's `api/search_graduates.php`. Delete the throwaway script when done.

- [ ] **Step 3: Commit**

```bash
git add api/search_activity_log.php
git commit -m "feat: add api/search_activity_log.php JSON search endpoint"
```

---

### Task 10: `admin/activity_log.php` — activity log viewer page

**Files:**
- Create: `admin/activity_log.php`
- Modify: `includes/sidebar.php` (add the second admin-only link inside Task 6's conditional block)

**Interfaces:**
- Consumes: `get_all_users()` (Task 4), `search_activity_log()` (Task 8), `require_role()`/`current_user()` (`includes/auth.php`), `e()`/`e_upper()`/`paginate()` (`includes/functions.php`).
- Produces: `window.HGE_ACTIVITY_LOG = { apiBase }` and the DOM contract Task 11's JS depends on:
  - `#activity-filter-form` (`<form method="get">`) containing `#filter-user` (`<select name="user_id">`), `#filter-action` (`<select name="action">`), `#filter-date-from` (`<input name="date_from" type="date">`), `#filter-date-to` (`<input name="date_to" type="date">`).
  - `#activity-results` — the container Task 11 replaces wholesale on every filter/page change.
  - `#activity-results-tbody` inside it, and pagination buttons with class `.activity-page-btn` and `data-page`.

There is no PHPUnit test for this file. Verify it as part of Task 12's browser pass.

- [ ] **Step 1: Implement**

Create `admin/activity_log.php`:

```php
<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/users.php';
require_once __DIR__ . '/../includes/activity.php';

require_role('administrator');

$pdo = get_pdo();
$allUsers = get_all_users($pdo);

$userId = (isset($_GET['user_id']) && (int) $_GET['user_id'] > 0) ? (int) $_GET['user_id'] : null;
$action = trim((string) ($_GET['action'] ?? ''));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));

$filters = [
    'user_id' => $userId,
    'action' => $action !== '' ? $action : null,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
];

$searchResult = null;
$searchFailed = false;
try {
    $searchResult = search_activity_log($pdo, $filters, $page);
} catch (\Throwable $e) {
    error_log('Activity log search failed: ' . $e->getMessage());
    $searchFailed = true;
}

$actionTypes = ['login', 'logout', 'pdf_upload', 'pdf_delete', 'record_created', 'record_updated', 'record_removed', 'search', 'user_created', 'user_updated'];

$currentUser = current_user();
$currentPage = 'activity_log';

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main class="lg:ml-64 min-h-screen bg-surface-950">
    <div class="p-6 lg:p-8">
        <h1 class="text-xl font-semibold text-white mb-2">ACTIVITY LOG</h1>
        <p class="text-sm text-gray-500 mb-6">ENTRIES ARE RETAINED FOR 6 MONTHS. "SEARCH" ENTRIES REFLECT SESSION ACTIVITY, NOT CONFIRMED USER INTENT.</p>

        <form id="activity-filter-form" method="get" class="grid grid-cols-1 sm:grid-cols-5 gap-3 mb-6">
            <div>
                <label class="block text-xs text-gray-400 mb-1">USER</label>
                <select name="user_id" id="filter-user" class="w-full rounded-lg bg-surface-800 border border-white/10 px-3 py-2 text-sm">
                    <option value="">ALL USERS</option>
                    <?php foreach ($allUsers as $u): ?>
                        <option value="<?= (int) $u['id'] ?>" <?= $userId === (int) $u['id'] ? 'selected' : '' ?>><?= e_upper($u['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-400 mb-1">ACTION</label>
                <select name="action" id="filter-action" class="w-full rounded-lg bg-surface-800 border border-white/10 px-3 py-2 text-sm">
                    <option value="">ALL ACTIONS</option>
                    <?php foreach ($actionTypes as $type): ?>
                        <option value="<?= e($type) ?>" <?= $action === $type ? 'selected' : '' ?>><?= e_upper($type) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-400 mb-1">FROM</label>
                <input type="date" name="date_from" id="filter-date-from" value="<?= e($dateFrom) ?>" class="w-full rounded-lg bg-surface-800 border border-white/10 px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-xs text-gray-400 mb-1">TO</label>
                <input type="date" name="date_to" id="filter-date-to" value="<?= e($dateTo) ?>" class="w-full rounded-lg bg-surface-800 border border-white/10 px-3 py-2 text-sm">
            </div>
            <div class="flex items-end">
                <button type="submit" class="w-full rounded-lg bg-accent-600 hover:bg-accent-500 text-white text-sm font-medium px-4 py-2">FILTER</button>
            </div>
        </form>

        <div id="activity-results">
            <?php if ($searchFailed): ?>
                <div class="rounded-xl border border-red-500/30 bg-red-500/10 p-10 text-center text-red-400">
                    <p class="text-sm font-medium">THE ACTIVITY LOG COULD NOT BE LOADED. PLEASE TRY AGAIN.</p>
                </div>
            <?php elseif (empty($searchResult['rows'])): ?>
                <div class="rounded-xl border border-white/10 bg-surface-900 p-10 text-center text-gray-400">
                    <p class="text-sm font-medium text-gray-300">NO ACTIVITY LOG ENTRIES FOUND.</p>
                </div>
            <?php else: ?>
                <div class="bg-surface-900 border border-white/10 rounded-xl overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead>
                            <tr class="text-xs text-gray-500 border-b border-white/10">
                                <th class="px-4 py-3">TIMESTAMP</th>
                                <th class="px-4 py-3">USER</th>
                                <th class="px-4 py-3">ACTION</th>
                                <th class="px-4 py-3">DETAILS</th>
                            </tr>
                        </thead>
                        <tbody id="activity-results-tbody">
                            <?php foreach ($searchResult['rows'] as $row): ?>
                                <tr class="border-b border-white/5 hover:bg-white/5">
                                    <td class="px-4 py-3"><?= e_upper(date('F j, Y g:i A', strtotime($row['created_at']))) ?></td>
                                    <td class="px-4 py-3"><?= $row['user_name'] !== null ? e_upper($row['user_name']) : 'SYSTEM' ?></td>
                                    <td class="px-4 py-3"><?= e_upper($row['action']) ?></td>
                                    <td class="px-4 py-3"><?= ($row['details'] ?? '') !== '' ? e($row['details']) : '—' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div id="activity-pagination" class="flex items-center justify-between mt-4 text-sm text-gray-400">
                    <span>PAGE <?= (int) $searchResult['pagination']['page'] ?> OF <?= (int) $searchResult['pagination']['total_pages'] ?> (<?= (int) $searchResult['pagination']['total_rows'] ?> ENTRIES)</span>
                    <div class="flex gap-2">
                        <?php if ($searchResult['pagination']['page'] > 1): ?>
                            <button type="button" class="activity-page-btn rounded-lg border border-white/10 px-3 py-1.5 hover:bg-white/5" data-page="<?= (int) $searchResult['pagination']['page'] - 1 ?>">PREV</button>
                        <?php endif; ?>
                        <?php if ($searchResult['pagination']['page'] < $searchResult['pagination']['total_pages']): ?>
                            <button type="button" class="activity-page-btn rounded-lg border border-white/10 px-3 py-1.5 hover:bg-white/5" data-page="<?= (int) $searchResult['pagination']['page'] + 1 ?>">NEXT</button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
<script>
window.HGE_ACTIVITY_LOG = {
    apiBase: <?= json_encode(APP_BASE_URL) ?>
};
</script>
<script src="<?= e(APP_BASE_URL) ?>/assets/js/activity_log.js"></script>
```

- [ ] **Step 2: Add the second nav link**

In `includes/sidebar.php`, inside the same `<?php if (user_has_role($currentUser ?? null, 'administrator')): ?>` block Task 6 added (right after the `USER MANAGEMENT` link, still before that block's `<?php endif; ?>`), add:

```php
        <a href="<?= e(APP_BASE_URL) ?>/admin/activity_log.php" class="flex items-center gap-3 rounded-lg px-3 py-2 <?= sidebar_nav_class($currentPage, 'activity_log') ?>">
            ACTIVITY LOG
        </a>
```

- [ ] **Step 3: Commit**

```bash
git add admin/activity_log.php includes/sidebar.php
git commit -m "feat: add admin/activity_log.php viewer page with second admin-only nav link"
```

---

### Task 11: `assets/js/activity_log.js` — AJAX filtering and pagination

**Files:**
- Create: `assets/js/activity_log.js`

**Interfaces:**
- Consumes: `window.HGE_ACTIVITY_LOG` (Task 10), `showToast()` (`toast.js`), the global `escapeHtml()` (Task 2's `utils.js`), the DOM contract from Task 10, and `api/search_activity_log.php`'s JSON contract (Task 9).
- No exports — page-specific script loaded only by `admin/activity_log.php`. Simpler than Phase 4's `lookup.js`: no details modal (DETAILS is shown inline in the table already).

- [ ] **Step 1: Implement**

Create `assets/js/activity_log.js`:

```js
document.addEventListener('DOMContentLoaded', function () {
    var config = window.HGE_ACTIVITY_LOG;
    var form = document.getElementById('activity-filter-form');
    var resultsContainer = document.getElementById('activity-results');
    if (!config || !form || !resultsContainer) {
        return;
    }

    var MONTHS = ['JANUARY', 'FEBRUARY', 'MARCH', 'APRIL', 'MAY', 'JUNE', 'JULY', 'AUGUST', 'SEPTEMBER', 'OCTOBER', 'NOVEMBER', 'DECEMBER'];

    function formatTimestamp(value) {
        var parts = String(value).split(/[- :]/);
        if (parts.length < 5) {
            return String(value);
        }
        var year = parseInt(parts[0], 10);
        var month = MONTHS[parseInt(parts[1], 10) - 1];
        var day = parseInt(parts[2], 10);
        var hour = parseInt(parts[3], 10);
        var minute = parts[4];
        var ampm = hour >= 12 ? 'PM' : 'AM';
        var hour12 = hour % 12 === 0 ? 12 : hour % 12;
        return month + ' ' + day + ', ' + year + ' ' + hour12 + ':' + minute + ' ' + ampm;
    }

    function currentParams(page) {
        return {
            user_id: document.getElementById('filter-user').value,
            action: document.getElementById('filter-action').value,
            date_from: document.getElementById('filter-date-from').value,
            date_to: document.getElementById('filter-date-to').value,
            page: page || 1,
        };
    }

    function buildQueryString(params) {
        var pairs = [];
        Object.keys(params).forEach(function (key) {
            var value = params[key];
            if (value !== null && value !== undefined && value !== '') {
                pairs.push(encodeURIComponent(key) + '=' + encodeURIComponent(value));
            }
        });
        return pairs.join('&');
    }

    function renderResults(data) {
        if (data.rows.length === 0) {
            resultsContainer.innerHTML =
                '<div class="rounded-xl border border-white/10 bg-surface-900 p-10 text-center text-gray-400">' +
                '<p class="text-sm font-medium text-gray-300">NO ACTIVITY LOG ENTRIES FOUND.</p></div>';
            return;
        }

        var rowsHtml = data.rows.map(function (row) {
            var userLabel = row.user_name ? row.user_name.toUpperCase() : 'SYSTEM';
            var details = row.details ? row.details : '—';
            return '<tr class="border-b border-white/5 hover:bg-white/5">' +
                '<td class="px-4 py-3">' + escapeHtml(formatTimestamp(row.created_at)) + '</td>' +
                '<td class="px-4 py-3">' + escapeHtml(userLabel) + '</td>' +
                '<td class="px-4 py-3">' + escapeHtml(String(row.action).toUpperCase()) + '</td>' +
                '<td class="px-4 py-3">' + escapeHtml(details) + '</td>' +
                '</tr>';
        }).join('');

        var pagination = data.pagination;
        var paginationHtml =
            '<div id="activity-pagination" class="flex items-center justify-between mt-4 text-sm text-gray-400">' +
            '<span>PAGE ' + pagination.page + ' OF ' + pagination.total_pages + ' (' + pagination.total_rows + ' ENTRIES)</span>' +
            '<div class="flex gap-2">' +
            (pagination.page > 1 ? '<button type="button" class="activity-page-btn rounded-lg border border-white/10 px-3 py-1.5 hover:bg-white/5" data-page="' + (pagination.page - 1) + '">PREV</button>' : '') +
            (pagination.page < pagination.total_pages ? '<button type="button" class="activity-page-btn rounded-lg border border-white/10 px-3 py-1.5 hover:bg-white/5" data-page="' + (pagination.page + 1) + '">NEXT</button>' : '') +
            '</div></div>';

        resultsContainer.innerHTML =
            '<div class="bg-surface-900 border border-white/10 rounded-xl overflow-x-auto"><table class="w-full text-sm text-left">' +
            '<thead><tr class="text-xs text-gray-500 border-b border-white/10">' +
            '<th class="px-4 py-3">TIMESTAMP</th><th class="px-4 py-3">USER</th><th class="px-4 py-3">ACTION</th><th class="px-4 py-3">DETAILS</th></tr></thead>' +
            '<tbody id="activity-results-tbody">' + rowsHtml + '</tbody></table></div>' + paginationHtml;
    }

    function performSearch(params, updateHistory) {
        var query = buildQueryString(params);

        if (updateHistory) {
            var newUrl = window.location.pathname + (query ? '?' + query : '');
            window.history.pushState(params, '', newUrl);
        }

        fetch(config.apiBase + '/api/search_activity_log.php' + (query ? '?' + query : ''))
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                if (data.status === 'success') {
                    renderResults(data);
                } else {
                    showToast('error', data.message);
                }
            })
            .catch(function () {
                showToast('error', 'THE ACTIVITY LOG COULD NOT BE LOADED. PLEASE TRY AGAIN.');
            });
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        performSearch(currentParams(1), true);
    });

    resultsContainer.addEventListener('click', function (event) {
        var pageBtn = event.target.closest('.activity-page-btn');
        if (pageBtn) {
            performSearch(currentParams(parseInt(pageBtn.dataset.page, 10)), true);
        }
    });

    window.addEventListener('popstate', function () {
        var urlParams = new URLSearchParams(window.location.search);
        var params = {
            user_id: urlParams.get('user_id') || '',
            action: urlParams.get('action') || '',
            date_from: urlParams.get('date_from') || '',
            date_to: urlParams.get('date_to') || '',
            page: urlParams.get('page') || 1,
        };
        document.getElementById('filter-user').value = params.user_id;
        document.getElementById('filter-action').value = params.action;
        document.getElementById('filter-date-from').value = params.date_from;
        document.getElementById('filter-date-to').value = params.date_to;
        performSearch(params, false);
    });
});
```

- [ ] **Step 2: Commit**

```bash
git add assets/js/activity_log.js
git commit -m "feat: add activity_log.js for AJAX filtering and pagination"
```

---

### Task 12: End-to-end manual verification

**Files:** none (verification only — no code changes expected unless this step surfaces a bug, in which case fix it in the file it belongs to and re-run this task).

- [ ] **Step 1: Run the full automated suite**

```bash
vendor/bin/phpunit
```

Expected: all tests pass (the pre-Phase-5 suite plus every test added in Tasks 1, 3, 4, 8).

- [ ] **Step 2: Apply the live database ENUM change and start a server**

The live `hge_masterlist` dev database already has an `activity_log` table created before this phase — Task 5's `schema.sql` edit only affects fresh/test databases. Apply the same change to the live one:

```bash
mysql -u root hge_masterlist -e "ALTER TABLE activity_log MODIFY COLUMN action ENUM('login','logout','pdf_upload','pdf_delete','record_created','record_updated','record_removed','search','user_created','user_updated') NOT NULL;"
```

Start the built-in PHP server with the same `auto_prepend_file` trick used for manual verification in every prior phase (a `prepend_root.php` throwaway file defining `APP_BASE_URL` as `''`, so root-relative links resolve outside the XAMPP subfolder):

```bash
php -S 127.0.0.1:8899 -t . -d auto_prepend_file=prepend_root.php
```

- [ ] **Step 3: Full journey walkthrough, as administrator**

Using the Browser tool, log in as an administrator and walk the complete path this phase's spec addendum describes, confirming each step as you go:
- Upload a PDF (Phase 2), extract records (Phase 3), edit at least one record in the review screen, save.
- Look up the saved graduate (Phase 4's `admin/lookup.php`), open the details modal, click VIEW SOURCE PDF and confirm it opens.
- Navigate to `admin/activity_log.php` (via the new sidebar link) and confirm the `search` entry from the look-up above appears, along with the `pdf_upload`/`record_created`/`login` entries from earlier in this walkthrough. Confirm filtering by ACTION and by a date range narrows the results correctly, and that pagination works if there are enough rows.
- Navigate to `admin/users.php` (via the new sidebar link). Click CREATE USER, create a new `user`-role account. Confirm it appears in the table and in the activity log as a `user_created` entry.
- Click EDIT on the newly created user, change their role to `administrator`, confirm the change persists and produces a `user_updated` activity log entry.
- Click EDIT on your OWN account (the currently logged-in administrator) and confirm the ROLE and STATUS controls are disabled/hidden with the explanatory text, and that attempting to submit a role/status change for yourself via a crafted request to `api/update_user.php` (not just the disabled UI) is rejected — this is the server-side guard, not just a UI nicety.

- [ ] **Step 4: Role-agnostic access still holds, as the new user**

Log out, log in as the `user`-role account created in Step 3. Confirm:
- `admin/users.php` and `admin/activity_log.php` are NOT reachable (403, and their sidebar links are absent).
- `admin/lookup.php` still works exactly as it did for the administrator (Phase 4's role-agnostic access is unaffected by this phase's changes).

- [ ] **Step 5: Confirm the hardening fixes where browser-observable**

- Log out (from either account) and confirm you're redirected to the login page, and that the session cookie is gone (check via the browser's dev tools, or confirm that navigating back to a previously-logged-in page redirects to login rather than showing cached content).
- Log back in and confirm the CSRF token embedded in a subsequent page's form (e.g. reload `admin/lookup.php` isn't POST-based, but `admin/users.php`'s create-user flow uses `window.HGE_USERS.csrfToken`) is present and the create/edit flows work — this proves the rotated token is valid post-login, not just that rotation happened.

- [ ] **Step 6: Stop the server and clean up**

Stop the PHP built-in server, delete `prepend_root.php`, and remove any throwaway test users/data created during this walkthrough that would otherwise pollute the dev database (keep the pre-existing `admin` account and any Phase 2-4 verification data untouched — only remove what Step 3 created in this task).

No commit for this task unless a step surfaced a real bug — in that case, fix it in-place, re-run the relevant PHPUnit tests, and commit the fix with a message describing what was wrong.
