# HGE Database — Phase 1: Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stand up the database schema, PDO connection layer, session-based authentication with roles, and a dashboard shell wired to real (zero-or-more) MySQL counts — the foundation every later phase builds on.

**Architecture:** Plain procedural PHP (no framework/router), PDO with prepared statements throughout, session-based auth with server-side role checks on every protected file, Tailwind compiled once to a static CSS file. PHPUnit covers pure logic and DB-integration logic against a dedicated `hge_masterlist_test` database; full-page rendering is verified manually in the last task.

**Tech Stack:** PHP 8.2.12, MariaDB 10.4.32 (PDO/pdo_mysql), PHPUnit ^10.5, Tailwind CLI (via npm, build-time only), vanilla JS.

**Spec:** [docs/superpowers/specs/2026-09-12-hge-database-design.md](../specs/2026-09-12-hge-database-design.md)

## Global Constraints

- Project root: `C:\xampp\htdocs\hge_masterlist`, served at `http://localhost/hge_masterlist/`.
- All SQL via PDO prepared statements — no string-concatenated queries.
- All dynamic output escaped at render time (`htmlspecialchars`, `ENT_QUOTES`, `UTF-8`).
- Role/auth checks happen server-side in every protected file, not only hidden in the UI.
- Major on-screen labels/values use UPPERCASE display (per spec section 31), while stored values keep their original casing.
- No framework/router: plain PHP files per the spec's folder structure.
- Styling: Tailwind compiled to a static `assets/css/app.css` at build time — never the Play CDN.
- Never expose raw PHP/MySQL errors to the browser; log server-side, show a friendly UPPERCASE message instead.
- DB credentials come from environment variables with XAMPP-local defaults (`root` / no password / `127.0.0.1`), never hardcoded as the only option.

---

## Task 1: Composer + PHPUnit scaffold

**Files:**
- Create: `composer.json`
- Create: `phpunit.xml`
- Create: `tests/SmokeTest.php`
- Create: `.gitignore`

**Interfaces:**
- Produces: a working `php vendor/bin/phpunit` command usable by every later task.

- [ ] **Step 1: Write composer.json**

```json
{
    "name": "hge/masterlist",
    "description": "Honor Graduate Eligibility Latin Honor Database",
    "require": {
        "php": ">=8.1",
        "ext-pdo": "*",
        "ext-mbstring": "*"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.5"
    },
    "autoload-dev": {
        "psr-4": {
            "Tests\\": "tests/"
        }
    }
}
```

- [ ] **Step 2: Install dependencies**

Run: `composer install`
Expected: `vendor/` created, `composer.lock` written, no errors.

- [ ] **Step 3: Write phpunit.xml**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="tests/bootstrap.php" colors="true">
    <testsuites>
        <testsuite name="Foundation">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

- [ ] **Step 4: Write a placeholder bootstrap (real one lands in Task 2)**

```php
<?php
require __DIR__ . '/../vendor/autoload.php';
```

Save as `tests/bootstrap.php`.

- [ ] **Step 5: Write the smoke test**

```php
<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

class SmokeTest extends TestCase
{
    public function testPhpUnitRuns(): void
    {
        $this->assertTrue(true);
    }
}
```

- [ ] **Step 6: Run it**

Run: `php vendor/bin/phpunit`
Expected: `OK (1 test, 1 assertion)`

- [ ] **Step 7: Write .gitignore**

```
/vendor/
.phpunit.cache/
```

- [ ] **Step 8: Commit**

```bash
git add composer.json composer.lock phpunit.xml tests/bootstrap.php tests/SmokeTest.php .gitignore
git commit -m "chore: scaffold composer + phpunit"
```

---

## Task 2: Database schema + test-DB harness

**Files:**
- Create: `database/schema.sql`
- Create: `tests/TestDb.php`
- Modify: `tests/bootstrap.php`
- Test: `tests/SchemaTest.php`

**Interfaces:**
- Produces: `Tests\TestDb::pdo(): PDO` (connected to `hge_masterlist_test`), `Tests\TestDb::resetSchema(): void` (drops + recreates all tables from `database/schema.sql`), `Tests\TestDb::truncateAll(): void`. Every later test that touches the database uses these.
- Produces: `database/schema.sql`, which task 3 onward assume defines exactly these 5 tables: `users`, `schools`, `latin_honor_uploads`, `latin_honor_graduates`, `activity_log`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

class SchemaTest extends TestCase
{
    protected function setUp(): void
    {
        TestDb::resetSchema();
    }

    public function testAllTablesExist(): void
    {
        $pdo = TestDb::pdo();
        $tables = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);

        $this->assertEqualsCanonicalizing(
            ['users', 'schools', 'latin_honor_uploads', 'latin_honor_graduates', 'activity_log'],
            $tables
        );
    }

    public function testGraduateMustReferenceExistingUpload(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO schools (school_name) VALUES ('TEST UNIVERSITY')");

        $this->expectException(\PDOException::class);
        $pdo->exec(
            "INSERT INTO latin_honor_graduates (upload_id, school_id, graduate_name, latin_honor, graduation_year)
             VALUES (999, 1, 'JUAN DELA CRUZ', 'CUM LAUDE', 2025)"
        );
    }

    public function testGraduateNameHasFulltextIndex(): void
    {
        $pdo = TestDb::pdo();
        $indexes = $pdo->query("SHOW INDEX FROM latin_honor_graduates WHERE Key_name = 'ft_graduate_name'")->fetchAll();

        $this->assertNotEmpty($indexes);
        $this->assertSame('FULLTEXT', $indexes[0]['Index_type']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/SchemaTest.php`
Expected: FAIL — `Tests\TestDb` not found (doesn't exist yet).

- [ ] **Step 3: Write database/schema.sql**

```sql
CREATE DATABASE IF NOT EXISTS hge_masterlist CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE hge_masterlist;

CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  username VARCHAR(50) NOT NULL UNIQUE,
  email VARCHAR(150) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  role ENUM('administrator','user') NOT NULL DEFAULT 'user',
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE schools (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_name VARCHAR(255) NOT NULL,
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_school_name (school_name)
) ENGINE=InnoDB;

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
  extraction_status ENUM('pending','extracted','reviewed','failed') NOT NULL DEFAULT 'pending',
  records_extracted INT UNSIGNED NOT NULL DEFAULT 0,
  uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (school_id) REFERENCES schools(id),
  FOREIGN KEY (uploaded_by) REFERENCES users(id),
  KEY idx_school_id (school_id),
  KEY idx_file_hash (file_hash)
) ENGINE=InnoDB;

CREATE TABLE latin_honor_graduates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  upload_id INT UNSIGNED NOT NULL,
  school_id INT UNSIGNED NOT NULL,
  graduate_name VARCHAR(255) NOT NULL,
  latin_honor ENUM('CUM LAUDE','MAGNA CUM LAUDE','SUMMA CUM LAUDE') NOT NULL,
  graduation_date DATE NULL,
  graduation_year YEAR NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (upload_id) REFERENCES latin_honor_uploads(id),
  FOREIGN KEY (school_id) REFERENCES schools(id),
  KEY idx_school_id (school_id),
  KEY idx_graduation_year (graduation_year),
  KEY idx_latin_honor (latin_honor),
  KEY idx_upload_id (upload_id),
  FULLTEXT KEY ft_graduate_name (graduate_name)
) ENGINE=InnoDB;

CREATE TABLE activity_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  action ENUM('login','logout','pdf_upload','pdf_delete','record_created','record_updated','record_removed','search') NOT NULL,
  details TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;
```

- [ ] **Step 4: Write tests/TestDb.php**

```php
<?php

namespace Tests;

use PDO;

class TestDb
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = new PDO('mysql:host=127.0.0.1;charset=utf8mb4', 'root', '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            self::$pdo->exec('CREATE DATABASE IF NOT EXISTS hge_masterlist_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            self::$pdo->exec('USE hge_masterlist_test');
        }

        return self::$pdo;
    }

    public static function resetSchema(): void
    {
        $pdo = self::pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (['activity_log', 'latin_honor_graduates', 'latin_honor_uploads', 'schools', 'users'] as $table) {
            $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

        $sql = file_get_contents(__DIR__ . '/../database/schema.sql');
        foreach (explode(';', $sql) as $statement) {
            $statement = trim($statement);
            if ($statement === '' || stripos($statement, 'CREATE TABLE') !== 0) {
                continue;
            }
            $pdo->exec($statement);
        }
    }

    public static function truncateAll(): void
    {
        $pdo = self::pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (['activity_log', 'latin_honor_graduates', 'latin_honor_uploads', 'schools', 'users'] as $table) {
            $pdo->exec("TRUNCATE TABLE `{$table}`");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php vendor/bin/phpunit tests/SchemaTest.php`
Expected: `OK (3 tests, ...)`

- [ ] **Step 6: Create the local dev database too**

Run: `"/c/xampp/mysql/bin/mysql.exe" -u root -e "SOURCE database/schema.sql"` (or run the same statement from the XAMPP shell/phpMyAdmin)
Expected: no errors; `SHOW DATABASES` now lists `hge_masterlist`.

- [ ] **Step 7: Commit**

```bash
git add database/schema.sql tests/TestDb.php tests/SchemaTest.php
git commit -m "feat: add database schema and test-db harness"
```

---

## Task 3: config/database.php — PDO connector

**Files:**
- Create: `config/database.php`
- Test: `tests/config/DatabaseTest.php`

**Interfaces:**
- Consumes: `Tests\TestDb::resetSchema()` (Task 2).
- Produces: `APP_BASE_URL` constant, `get_db_config(): array`, `connect_pdo(array $config): PDO`, `get_pdo(): PDO` — every later PHP file that touches the database requires this file first and calls `get_pdo()`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Config;

use PHPUnit\Framework\TestCase;
use PDO;

require_once __DIR__ . '/../../config/database.php';

class DatabaseTest extends TestCase
{
    public function testDefaultConfigPointsAtXamppLocal(): void
    {
        putenv('DB_HOST');
        putenv('DB_NAME');
        putenv('DB_USER');
        putenv('DB_PASS');

        $config = get_db_config();

        $this->assertSame('127.0.0.1', $config['host']);
        $this->assertSame('hge_masterlist', $config['name']);
        $this->assertSame('root', $config['user']);
        $this->assertSame('', $config['pass']);
    }

    public function testConfigHonorsEnvironmentOverrides(): void
    {
        putenv('DB_NAME=hge_masterlist_test');
        $config = get_db_config();
        $this->assertSame('hge_masterlist_test', $config['name']);
        putenv('DB_NAME');
    }

    public function testConnectPdoConnectsWithGoodConfig(): void
    {
        $pdo = connect_pdo([
            'host' => '127.0.0.1',
            'name' => 'hge_masterlist_test',
            'user' => 'root',
            'pass' => '',
        ]);

        $this->assertInstanceOf(PDO::class, $pdo);
        $this->assertSame('1', $pdo->query('SELECT 1')->fetchColumn());
    }

    public function testConnectPdoThrowsOnUnknownDatabase(): void
    {
        $this->expectException(\PDOException::class);
        connect_pdo([
            'host' => '127.0.0.1',
            'name' => 'this_database_does_not_exist',
            'user' => 'root',
            'pass' => '',
        ]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/config/DatabaseTest.php`
Expected: FAIL — `get_db_config` undefined.

- [ ] **Step 3: Write config/database.php**

```php
<?php

if (!defined('APP_BASE_URL')) {
    define('APP_BASE_URL', getenv('APP_BASE_URL') ?: '/hge_masterlist');
}

function get_db_config(): array
{
    return [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'name' => getenv('DB_NAME') ?: 'hge_masterlist',
        'user' => getenv('DB_USER') ?: 'root',
        'pass' => getenv('DB_PASS') ?: '',
    ];
}

function connect_pdo(array $config): PDO
{
    $dsn = "mysql:host={$config['host']};dbname={$config['name']};charset=utf8mb4";

    return new PDO($dsn, $config['user'], $config['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function get_pdo(): PDO
{
    try {
        return connect_pdo(get_db_config());
    } catch (PDOException $e) {
        error_log('Database connection failed: ' . $e->getMessage());
        http_response_code(500);
        die('THE APPLICATION COULD NOT CONNECT TO THE DATABASE. PLEASE TRY AGAIN LATER.');
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php vendor/bin/phpunit tests/config/DatabaseTest.php`
Expected: `OK (4 tests, ...)`

- [ ] **Step 5: Commit**

```bash
git add config/database.php tests/config/DatabaseTest.php
git commit -m "feat: add PDO connector with env-configurable credentials"
```

---

## Task 4: includes/functions.php — display & pagination helpers

**Files:**
- Create: `includes/functions.php`
- Test: `tests/includes/FunctionsTest.php`

**Interfaces:**
- Produces: `e(?string): string`, `e_upper(?string): string`, `format_date_display(?string $ymd): string`, `paginate(int $totalRows, int $page, int $perPage = 25): array`, `redirect(string $path): void`. Later tasks (auth, dashboard, views) call all of these.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/functions.php';

class FunctionsTest extends TestCase
{
    public function testEEscapesHtml(): void
    {
        $this->assertSame('&lt;b&gt;hi&lt;/b&gt;', e('<b>hi</b>'));
        $this->assertSame('', e(null));
    }

    public function testEUpperEscapesAndUppercases(): void
    {
        $this->assertSame('JUAN DELA CRUZ', e_upper('juan dela cruz'));
    }

    public function testFormatDateDisplayFormatsKnownDate(): void
    {
        $this->assertSame('JUNE 15, 2025', format_date_display('2025-06-15'));
    }

    public function testFormatDateDisplayHandlesMissingOrInvalidDate(): void
    {
        $this->assertSame('—', format_date_display(null));
        $this->assertSame('—', format_date_display('not-a-date'));
    }

    public function testPaginateClampsPageWithinRange(): void
    {
        $result = paginate(totalRows: 5, page: 99, perPage: 25);
        $this->assertSame(1, $result['page']);
        $this->assertSame(1, $result['total_pages']);
        $this->assertSame(0, $result['offset']);
    }

    public function testPaginateComputesOffsetForMiddlePage(): void
    {
        $result = paginate(totalRows: 120, page: 3, perPage: 25);
        $this->assertSame(3, $result['page']);
        $this->assertSame(5, $result['total_pages']);
        $this->assertSame(50, $result['offset']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/includes/FunctionsTest.php`
Expected: FAIL — `e()` undefined.

- [ ] **Step 3: Write includes/functions.php**

```php
<?php

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function e_upper(?string $value): string
{
    return e(mb_strtoupper((string) $value));
}

function format_date_display(?string $date): string
{
    if (empty($date)) {
        return '—';
    }

    $dt = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dt) {
        return '—';
    }

    return mb_strtoupper($dt->format('F j, Y'));
}

function paginate(int $totalRows, int $page, int $perPage = 25): array
{
    $perPage = max(1, $perPage);
    $totalPages = max(1, (int) ceil($totalRows / $perPage));
    $page = max(1, min($page, $totalPages));
    $offset = ($page - 1) * $perPage;

    return [
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => $totalPages,
        'total_rows' => $totalRows,
        'offset' => $offset,
    ];
}

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php vendor/bin/phpunit tests/includes/FunctionsTest.php`
Expected: `OK (6 tests, ...)`

- [ ] **Step 5: Commit**

```bash
git add includes/functions.php tests/includes/FunctionsTest.php
git commit -m "feat: add display and pagination helper functions"
```

---

## Task 5: includes/functions.php — flash messages + dashboard stats

**Files:**
- Modify: `includes/functions.php`
- Test: `tests/includes/FlashAndStatsTest.php`

**Interfaces:**
- Consumes: `Tests\TestDb` (Task 2).
- Produces: `flash_set(string $type, string $message): void`, `flash_get(): ?array`, `get_dashboard_stats(PDO $pdo): array` (keys: `total_graduates`, `total_schools`, `total_uploads`, `current_year`, `current_year_records`). Task 12's dashboard page calls `get_dashboard_stats()` and Phase 2+ pages call `flash_set()`/`flash_get()`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;
use Tests\TestDb;

require_once __DIR__ . '/../../includes/functions.php';

class FlashAndStatsTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
        TestDb::resetSchema();
    }

    public function testFlashSetThenGetReturnsAndClearsMessage(): void
    {
        flash_set('success', 'SAVED.');
        $this->assertSame(['type' => 'success', 'message' => 'SAVED.'], flash_get());
        $this->assertNull(flash_get());
    }

    public function testDashboardStatsAreZeroOnEmptyDatabase(): void
    {
        $stats = get_dashboard_stats(TestDb::pdo());

        $this->assertSame(0, $stats['total_graduates']);
        $this->assertSame(0, $stats['total_schools']);
        $this->assertSame(0, $stats['total_uploads']);
        $this->assertNull($stats['current_year']);
        $this->assertSame(0, $stats['current_year_records']);
    }

    public function testDashboardStatsReflectSeededData(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO users (name, username, email, password, role) VALUES ('Admin', 'admin', 'a@a.com', 'x', 'administrator')");
        $pdo->exec("INSERT INTO schools (school_name) VALUES ('ABC UNIVERSITY'), ('XYZ COLLEGE')");
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, extraction_status, records_extracted)
             VALUES (1, '2025', 'list.pdf', 'stored.pdf', '/storage/pdfs/stored.pdf', 100, SHA2('x',256), 1, 'reviewed', 2)"
        );
        $pdo->exec(
            "INSERT INTO latin_honor_graduates (upload_id, school_id, graduate_name, latin_honor, graduation_year)
             VALUES (1, 1, 'JUAN DELA CRUZ', 'CUM LAUDE', 2025), (1, 1, 'MARIA SANTOS', 'MAGNA CUM LAUDE', 2024)"
        );

        $stats = get_dashboard_stats($pdo);

        $this->assertSame(2, $stats['total_graduates']);
        $this->assertSame(1, $stats['total_schools']);
        $this->assertSame(1, $stats['total_uploads']);
        $this->assertSame(2025, $stats['current_year']);
        $this->assertSame(1, $stats['current_year_records']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/includes/FlashAndStatsTest.php`
Expected: FAIL — `flash_set()` undefined.

- [ ] **Step 3: Append to includes/functions.php**

```php

function ensure_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function flash_set(string $type, string $message): void
{
    ensure_session();
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function flash_get(): ?array
{
    ensure_session();
    if (empty($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function get_dashboard_stats(PDO $pdo): array
{
    $totalGraduates = (int) $pdo->query('SELECT COUNT(*) FROM latin_honor_graduates')->fetchColumn();
    $totalSchools = (int) $pdo->query('SELECT COUNT(DISTINCT school_id) FROM latin_honor_uploads')->fetchColumn();
    $totalUploads = (int) $pdo->query('SELECT COUNT(*) FROM latin_honor_uploads')->fetchColumn();
    $currentYear = $pdo->query('SELECT MAX(graduation_year) FROM latin_honor_graduates')->fetchColumn();

    $currentYearRecords = 0;
    if ($currentYear !== null) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM latin_honor_graduates WHERE graduation_year = :year');
        $stmt->execute(['year' => $currentYear]);
        $currentYearRecords = (int) $stmt->fetchColumn();
    }

    return [
        'total_graduates' => $totalGraduates,
        'total_schools' => $totalSchools,
        'total_uploads' => $totalUploads,
        'current_year' => $currentYear !== null ? (int) $currentYear : null,
        'current_year_records' => $currentYearRecords,
    ];
}
```

Note: `ensure_session()` is defined here (not in `includes/auth.php`) since both flash messages and auth need it — Task 6 reuses this exact function.

- [ ] **Step 4: Run test to verify it passes**

Run: `php vendor/bin/phpunit tests/includes/FlashAndStatsTest.php`
Expected: `OK (3 tests, ...)`

- [ ] **Step 5: Commit**

```bash
git add includes/functions.php tests/includes/FlashAndStatsTest.php
git commit -m "feat: add flash messaging and dashboard stats query"
```

---

## Task 6: includes/auth.php — CSRF + role logic (pure/session parts)

**Files:**
- Create: `includes/auth.php`
- Test: `tests/includes/AuthCsrfAndRolesTest.php`

**Interfaces:**
- Consumes: `ensure_session()` (Task 5).
- Produces: `csrf_token(): string`, `csrf_verify(?string $token): bool`, `user_has_role(?array $user, string $role): bool`, `current_user(): ?array`. Task 7 builds `attempt_login`/`login_user`/`logout_user` in the same file; Task 12's pages call all of these.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

class AuthCsrfAndRolesTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testCsrfTokenIsStableAcrossCalls(): void
    {
        $first = csrf_token();
        $second = csrf_token();
        $this->assertSame($first, $second);
        $this->assertGreaterThanOrEqual(32, strlen($first));
    }

    public function testCsrfVerifyAcceptsMatchingTokenAndRejectsOthers(): void
    {
        $token = csrf_token();
        $this->assertTrue(csrf_verify($token));
        $this->assertFalse(csrf_verify('wrong-token'));
        $this->assertFalse(csrf_verify(null));
    }

    public function testUserHasRoleMatchesExactRole(): void
    {
        $user = ['role' => 'user'];
        $this->assertTrue(user_has_role($user, 'user'));
        $this->assertFalse(user_has_role($user, 'administrator'));
    }

    public function testAdministratorSatisfiesAnyRoleCheck(): void
    {
        $admin = ['role' => 'administrator'];
        $this->assertTrue(user_has_role($admin, 'user'));
        $this->assertTrue(user_has_role($admin, 'administrator'));
    }

    public function testUserHasRoleRejectsNullUser(): void
    {
        $this->assertFalse(user_has_role(null, 'user'));
    }

    public function testCurrentUserReturnsNullWhenNotLoggedIn(): void
    {
        $this->assertNull(current_user());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/includes/AuthCsrfAndRolesTest.php`
Expected: FAIL — `csrf_token()` undefined.

- [ ] **Step 3: Write includes/auth.php**

```php
<?php

function csrf_token(): string
{
    ensure_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_verify(?string $token): bool
{
    ensure_session();
    return is_string($token) && isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function user_has_role(?array $user, string $role): bool
{
    if ($user === null) {
        return false;
    }
    return $user['role'] === $role || $user['role'] === 'administrator';
}

function current_user(): ?array
{
    ensure_session();
    return $_SESSION['user'] ?? null;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php vendor/bin/phpunit tests/includes/AuthCsrfAndRolesTest.php`
Expected: `OK (6 tests, ...)`

- [ ] **Step 5: Commit**

```bash
git add includes/auth.php tests/includes/AuthCsrfAndRolesTest.php
git commit -m "feat: add CSRF tokens and role-check logic"
```

---

## Task 7: includes/auth.php — login/logout + require_login/require_role

**Files:**
- Modify: `includes/auth.php`
- Test: `tests/includes/AuthLoginTest.php`

**Interfaces:**
- Consumes: `Tests\TestDb` (Task 2), `current_user()`/`user_has_role()` (Task 6), `redirect()` (Task 4).
- Produces: `attempt_login(PDO $pdo, string $username, string $password): ?array`, `login_user(array $user): void`, `logout_user(): void`, `require_login(): void`, `require_role(string $role): void`. Task 12's `login.php`/`logout.php`/`admin/dashboard.php` call all of these.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;
use Tests\TestDb;

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

class AuthLoginTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
        TestDb::resetSchema();
        $pdo = TestDb::pdo();
        $pdo->exec(
            "INSERT INTO users (name, username, email, password, role, status)
             VALUES ('System Administrator', 'admin', 'admin@hge.local', '" . password_hash('secret123', PASSWORD_DEFAULT) . "', 'administrator', 'active')"
        );
        $pdo->exec(
            "INSERT INTO users (name, username, email, password, role, status)
             VALUES ('Disabled User', 'disableduser', 'disabled@hge.local', '" . password_hash('secret123', PASSWORD_DEFAULT) . "', 'user', 'disabled')"
        );
    }

    public function testAttemptLoginSucceedsWithCorrectCredentials(): void
    {
        $user = attempt_login(TestDb::pdo(), 'admin', 'secret123');

        $this->assertNotNull($user);
        $this->assertSame('admin', $user['username']);
        $this->assertSame('administrator', $user['role']);
        $this->assertArrayNotHasKey('password', $user);
    }

    public function testAttemptLoginFailsWithWrongPassword(): void
    {
        $this->assertNull(attempt_login(TestDb::pdo(), 'admin', 'wrong-password'));
    }

    public function testAttemptLoginFailsForUnknownUsername(): void
    {
        $this->assertNull(attempt_login(TestDb::pdo(), 'nobody', 'secret123'));
    }

    public function testAttemptLoginFailsForDisabledAccount(): void
    {
        $this->assertNull(attempt_login(TestDb::pdo(), 'disableduser', 'secret123'));
    }

    public function testLoginUserThenCurrentUserReturnsSessionData(): void
    {
        $user = attempt_login(TestDb::pdo(), 'admin', 'secret123');
        login_user($user);

        $current = current_user();
        $this->assertSame('admin', $current['username']);
        $this->assertSame('administrator', $current['role']);
    }

    public function testLogoutUserClearsCurrentUser(): void
    {
        $user = attempt_login(TestDb::pdo(), 'admin', 'secret123');
        login_user($user);
        logout_user();

        $this->assertNull(current_user());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/includes/AuthLoginTest.php`
Expected: FAIL — `attempt_login()` undefined.

- [ ] **Step 3: Append to includes/auth.php**

```php

function attempt_login(PDO $pdo, string $username, string $password): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, name, username, email, password, role, status FROM users WHERE username = :username LIMIT 1'
    );
    $stmt->execute(['username' => $username]);
    $user = $stmt->fetch();

    if (!$user || $user['status'] !== 'active') {
        return null;
    }

    if (!password_verify($password, $user['password'])) {
        return null;
    }

    unset($user['password']);
    return $user;
}

function login_user(array $user): void
{
    ensure_session();
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id' => $user['id'],
        'name' => $user['name'],
        'username' => $user['username'],
        'role' => $user['role'],
    ];
}

function logout_user(): void
{
    ensure_session();
    $_SESSION = [];
    session_destroy();
}

function require_login(): void
{
    if (current_user() === null) {
        redirect(APP_BASE_URL . '/login.php');
    }
}

function require_role(string $role): void
{
    $user = current_user();
    if ($user === null) {
        redirect(APP_BASE_URL . '/login.php');
    }
    if (!user_has_role($user, $role)) {
        http_response_code(403);
        die('YOU DO NOT HAVE PERMISSION TO ACCESS THIS PAGE.');
    }
}
```

Note: `require_login()`/`require_role()` call `header()`/`exit()`/`die()` and are intentionally left uncovered by PHPUnit (headers can't be sent in the CLI test runner) — Task 12's manual verification checks these directly in the browser.

- [ ] **Step 4: Run test to verify it passes**

Run: `php vendor/bin/phpunit tests/includes/AuthLoginTest.php`
Expected: `OK (6 tests, ...)`

- [ ] **Step 5: Commit**

```bash
git add includes/auth.php tests/includes/AuthLoginTest.php
git commit -m "feat: add login/logout and route-guard functions"
```

---

## Task 8: Administrator seeding script

**Files:**
- Modify: `includes/functions.php` (add `create_or_update_admin`)
- Create: `database/seed_admin.php`
- Test: `tests/includes/SeedAdminTest.php`

**Interfaces:**
- Consumes: `Tests\TestDb` (Task 2), `attempt_login()` (Task 7).
- Produces: `create_or_update_admin(PDO $pdo, string $username, string $email, string $password): void`. `database/seed_admin.php` is the CLI entry point used once to create the first administrator account.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;
use Tests\TestDb;

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

class SeedAdminTest extends TestCase
{
    protected function setUp(): void
    {
        TestDb::resetSchema();
    }

    public function testCreateAdminInsertsLoginableAdministrator(): void
    {
        $pdo = TestDb::pdo();
        create_or_update_admin($pdo, 'admin', 'admin@hge.local', 'secret123');

        $user = attempt_login($pdo, 'admin', 'secret123');
        $this->assertNotNull($user);
        $this->assertSame('administrator', $user['role']);
    }

    public function testCreateAdminIsIdempotentAndUpdatesPassword(): void
    {
        $pdo = TestDb::pdo();
        create_or_update_admin($pdo, 'admin', 'admin@hge.local', 'first-password');
        create_or_update_admin($pdo, 'admin', 'admin@hge.local', 'second-password');

        $this->assertNull(attempt_login($pdo, 'admin', 'first-password'));
        $this->assertNotNull(attempt_login($pdo, 'admin', 'second-password'));

        $count = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE username = 'admin'")->fetchColumn();
        $this->assertSame(1, $count);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/includes/SeedAdminTest.php`
Expected: FAIL — `create_or_update_admin()` undefined.

- [ ] **Step 3: Append to includes/functions.php**

```php

function create_or_update_admin(PDO $pdo, string $username, string $email, string $password): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO users (name, username, email, password, role, status)
         VALUES (:name, :username, :email, :password, :role, :status)
         ON DUPLICATE KEY UPDATE password = VALUES(password), role = VALUES(role), status = VALUES(status)'
    );

    $stmt->execute([
        'name' => 'System Administrator',
        'username' => $username,
        'email' => $email,
        'password' => password_hash($password, PASSWORD_DEFAULT),
        'role' => 'administrator',
        'status' => 'active',
    ]);
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php vendor/bin/phpunit tests/includes/SeedAdminTest.php`
Expected: `OK (2 tests, ...)`

- [ ] **Step 5: Write database/seed_admin.php**

```php
<?php

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';

$username = getenv('ADMIN_USERNAME') ?: 'admin';
$password = getenv('ADMIN_PASSWORD') ?: null;

if (!$password) {
    fwrite(STDERR, "Set the ADMIN_PASSWORD environment variable before running this script.\n");
    exit(1);
}

create_or_update_admin(get_pdo(), $username, $username . '@hge.local', $password);

echo "Administrator account '{$username}' created/updated.\n";
```

- [ ] **Step 6: Run it against the real dev database**

Run (bash): `ADMIN_USERNAME=admin ADMIN_PASSWORD=ChangeMe123! php database/seed_admin.php`
Expected: `Administrator account 'admin' created/updated.`

- [ ] **Step 7: Commit**

```bash
git add includes/functions.php database/seed_admin.php tests/includes/SeedAdminTest.php
git commit -m "feat: add administrator seeding script"
```

---

## Task 9: includes/functions.php — sidebar nav helper

**Files:**
- Modify: `includes/functions.php`
- Test: `tests/includes/SidebarNavTest.php`

**Interfaces:**
- Produces: `sidebar_nav_class(string $currentPage, string $navPage): string`. Task 11's `includes/sidebar.php` calls this for every nav link.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/functions.php';

class SidebarNavTest extends TestCase
{
    public function testActiveNavItemGetsAccentClasses(): void
    {
        $this->assertStringContainsString('bg-accent-600/20', sidebar_nav_class('dashboard', 'dashboard'));
    }

    public function testInactiveNavItemGetsMutedClasses(): void
    {
        $class = sidebar_nav_class('dashboard', 'upload');
        $this->assertStringContainsString('text-gray-400', $class);
        $this->assertStringNotContainsString('bg-accent-600/20', $class);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/includes/SidebarNavTest.php`
Expected: FAIL — `sidebar_nav_class()` undefined.

- [ ] **Step 3: Append to includes/functions.php**

```php

function sidebar_nav_class(string $currentPage, string $navPage): string
{
    if ($currentPage === $navPage) {
        return 'bg-accent-600/20 text-accent-300 border-l-2 border-accent-500';
    }

    return 'text-gray-400 hover:bg-white/5 hover:text-white border-l-2 border-transparent';
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php vendor/bin/phpunit tests/includes/SidebarNavTest.php`
Expected: `OK (2 tests, ...)`

- [ ] **Step 5: Commit**

```bash
git add includes/functions.php tests/includes/SidebarNavTest.php
git commit -m "feat: add sidebar active-state class helper"
```

---

## Task 10: Tailwind build pipeline

**Files:**
- Create: `package.json`
- Create: `tailwind.config.js`
- Create: `assets/css/input.css`
- Generate: `assets/css/app.css`

**Interfaces:**
- Produces: `assets/css/app.css`, referenced by every page's `<link>` tag starting in Task 11/12.

- [ ] **Step 1: Write package.json**

```json
{
    "name": "hge-masterlist-assets",
    "private": true,
    "devDependencies": {
        "tailwindcss": "^3.4.14"
    },
    "scripts": {
        "build:css": "tailwindcss -i ./assets/css/input.css -o ./assets/css/app.css --minify"
    }
}
```

- [ ] **Step 2: Install Tailwind**

Run: `npm install`
Expected: `node_modules/` created, no errors.

- [ ] **Step 3: Write tailwind.config.js**

```js
module.exports = {
  content: ['./**/*.php'],
  theme: {
    extend: {
      colors: {
        surface: {
          950: '#0b0b0f',
          900: '#121218',
          800: '#1a1a22',
        },
        accent: {
          300: '#c4b5fd',
          500: '#8b5cf6',
          600: '#7c3aed',
        },
      },
    },
  },
  plugins: [],
};
```

- [ ] **Step 4: Write assets/css/input.css**

```css
@tailwind base;
@tailwind components;
@tailwind utilities;
```

- [ ] **Step 5: Build the CSS**

Run: `npm run build:css`
Expected: `assets/css/app.css` created; `grep -c "bg-accent-600" assets/css/app.css` (or equivalent) shows the class is present once PHP files referencing it exist (Task 11 must run before this fully "sees" every class — rerun this build after Task 11/12 add their markup).

- [ ] **Step 6: Add node_modules to .gitignore**

Append to `.gitignore`:
```
/node_modules/
```

- [ ] **Step 7: Commit**

```bash
git add package.json package-lock.json tailwind.config.js assets/css/input.css .gitignore
git commit -m "chore: add tailwind build pipeline"
```

(`assets/css/app.css` is committed later, in Task 12, after the markup that determines its final class set exists — rebuilding is a one-line `npm run build:css`.)

---

## Task 11: Sidebar/header/footer partials + mobile toggle script

**Files:**
- Create: `includes/header.php`
- Create: `includes/sidebar.php`
- Create: `includes/footer.php`
- Create: `assets/js/sidebar.js`

**Interfaces:**
- Consumes: `e()`, `e_upper()` (Task 4), `sidebar_nav_class()` (Task 9), `APP_BASE_URL` (Task 3), `$currentUser` / `$currentPage` variables set by the including page (Task 12 sets these).
- Produces: the dashboard chrome every `admin/*.php` page in later phases includes via `include __DIR__ . '/../includes/header.php';` etc.

- [ ] **Step 1: Write includes/header.php**

```php
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HGE DATABASE</title>
    <link rel="stylesheet" href="<?= e(APP_BASE_URL) ?>/assets/css/app.css">
</head>
<body class="bg-surface-950 text-gray-100">
<header class="lg:ml-64 flex items-center justify-between gap-4 border-b border-white/10 bg-surface-900/60 px-4 py-3 sticky top-0 z-20 backdrop-blur">
    <button id="sidebar-toggle" class="lg:hidden text-gray-400 hover:text-white" aria-label="Open menu" aria-expanded="false">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
    </button>
    <div class="flex-1 max-w-md">
        <label for="header-search" class="sr-only">Search graduates</label>
        <input id="header-search" type="search" placeholder="SEARCH GRADUATES..." class="w-full rounded-lg bg-surface-800 border border-white/10 px-3 py-1.5 text-sm placeholder:text-gray-600 focus:outline-none focus:border-accent-500">
    </div>
    <div class="flex items-center gap-3 text-sm text-gray-400">
        <span><?= e_upper($currentUser['name'] ?? '') ?></span>
        <span class="hidden sm:inline text-xs uppercase text-gray-600">(<?= e($currentUser['role'] ?? '') ?>)</span>
    </div>
</header>
```

- [ ] **Step 2: Write includes/sidebar.php**

```php
<aside id="sidebar" class="fixed inset-y-0 left-0 z-30 w-64 -translate-x-full lg:translate-x-0 transition-transform bg-surface-900 border-r border-white/10 flex flex-col">
    <div class="px-5 py-5 border-b border-white/10">
        <p class="text-sm font-semibold tracking-wide text-white">HGE DATABASE</p>
        <p class="text-[11px] text-gray-500 mt-0.5">HONOR GRADUATE ELIGIBILITY</p>
    </div>
    <nav class="flex-1 px-3 py-4 space-y-1 text-sm">
        <a href="<?= e(APP_BASE_URL) ?>/admin/dashboard.php" class="flex items-center gap-3 rounded-lg px-3 py-2 <?= sidebar_nav_class($currentPage, 'dashboard') ?>">
            DASHBOARD
        </a>
        <a href="<?= e(APP_BASE_URL) ?>/admin/lookup.php" class="flex items-center gap-3 rounded-lg px-3 py-2 <?= sidebar_nav_class($currentPage, 'lookup') ?>">
            HONOR GRADUATE LOOK-UP
        </a>
        <a href="<?= e(APP_BASE_URL) ?>/admin/upload.php" class="flex items-center gap-3 rounded-lg px-3 py-2 <?= sidebar_nav_class($currentPage, 'upload') ?>">
            UPLOAD LATIN HONOR LIST
        </a>
    </nav>
    <div class="px-3 py-4 border-t border-white/10">
        <a href="<?= e(APP_BASE_URL) ?>/logout.php" class="flex items-center gap-3 rounded-lg px-3 py-2 text-gray-400 hover:bg-white/5 hover:text-white text-sm">
            LOGOUT
        </a>
    </div>
</aside>
<div id="sidebar-overlay" class="fixed inset-0 bg-black/50 z-20 hidden lg:hidden"></div>
```

Note: `lookup.php` and `upload.php` don't exist until Phases 4 and 2 respectively — Task 12's manual verification confirms these links 404 for now, which is expected, not a bug.

- [ ] **Step 3: Write includes/footer.php**

```php
<script src="<?= e(APP_BASE_URL) ?>/assets/js/sidebar.js"></script>
</body>
</html>
```

- [ ] **Step 4: Write assets/js/sidebar.js**

```js
document.addEventListener('DOMContentLoaded', function () {
    var toggle = document.getElementById('sidebar-toggle');
    var sidebar = document.getElementById('sidebar');
    var overlay = document.getElementById('sidebar-overlay');

    if (!toggle || !sidebar || !overlay) {
        return;
    }

    function openSidebar() {
        sidebar.classList.remove('-translate-x-full');
        overlay.classList.remove('hidden');
        toggle.setAttribute('aria-expanded', 'true');
    }

    function closeSidebar() {
        sidebar.classList.add('-translate-x-full');
        overlay.classList.add('hidden');
        toggle.setAttribute('aria-expanded', 'false');
    }

    toggle.addEventListener('click', function () {
        var isOpen = toggle.getAttribute('aria-expanded') === 'true';
        if (isOpen) {
            closeSidebar();
        } else {
            openSidebar();
        }
    });

    overlay.addEventListener('click', closeSidebar);
});
```

- [ ] **Step 5: Commit**

```bash
git add includes/header.php includes/sidebar.php includes/footer.php assets/js/sidebar.js
git commit -m "feat: add dashboard shell partials and mobile sidebar toggle"
```

(No automated test — this is presentation wiring verified visually in Task 12.)

---

## Task 12: login.php, logout.php, index.php, admin/dashboard.php + end-to-end verification

**Files:**
- Create: `login.php`
- Create: `logout.php`
- Create: `index.php`
- Create: `admin/dashboard.php`
- Modify: `assets/css/app.css` (rebuild)

**Interfaces:**
- Consumes: everything from Tasks 3–11 (`get_pdo`, `attempt_login`, `login_user`, `logout_user`, `current_user`, `require_login`, `csrf_token`, `csrf_verify`, `get_dashboard_stats`, `e`, `e_upper`, header/sidebar/footer partials).
- Produces: the first fully working page flow — this is what a human tests in the browser.

- [ ] **Step 1: Write login.php**

```php
<?php

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

if (current_user() !== null) {
    redirect(APP_BASE_URL . '/admin/dashboard.php');
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'YOUR SESSION EXPIRED. PLEASE TRY AGAIN.';
    } else {
        $user = attempt_login(get_pdo(), trim($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''));

        if ($user === null) {
            $error = 'INVALID USERNAME OR PASSWORD.';
        } else {
            login_user($user);
            redirect(APP_BASE_URL . '/admin/dashboard.php');
        }
    }
}

$csrfToken = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HGE Database — Login</title>
    <link rel="stylesheet" href="<?= e(APP_BASE_URL) ?>/assets/css/app.css">
</head>
<body class="bg-surface-950 text-gray-100 min-h-screen flex items-center justify-center px-4">
    <div class="w-full max-w-sm bg-surface-900 border border-white/10 rounded-xl p-8">
        <h1 class="text-lg font-semibold tracking-wide text-center mb-1">HGE DATABASE</h1>
        <p class="text-xs text-gray-500 text-center mb-6">HONOR GRADUATE ELIGIBILITY</p>

        <?php if ($error): ?>
            <div class="mb-4 rounded-lg border border-red-500/30 bg-red-500/10 text-red-400 text-sm px-3 py-2">
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <form method="post" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <div>
                <label class="block text-xs text-gray-400 mb-1" for="username">USERNAME</label>
                <input class="w-full rounded-lg bg-surface-800 border border-white/10 px-3 py-2 text-sm focus:outline-none focus:border-accent-500" type="text" id="username" name="username" required autofocus>
            </div>
            <div>
                <label class="block text-xs text-gray-400 mb-1" for="password">PASSWORD</label>
                <input class="w-full rounded-lg bg-surface-800 border border-white/10 px-3 py-2 text-sm focus:outline-none focus:border-accent-500" type="password" id="password" name="password" required>
            </div>
            <button class="w-full rounded-lg bg-accent-600 hover:bg-accent-500 text-white text-sm font-medium py-2 transition-colors" type="submit">SIGN IN</button>
        </form>
    </div>
</body>
</html>
```

- [ ] **Step 2: Write logout.php**

```php
<?php

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

logout_user();
redirect(APP_BASE_URL . '/login.php');
```

- [ ] **Step 3: Write index.php**

```php
<?php

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

redirect(current_user() !== null ? APP_BASE_URL . '/admin/dashboard.php' : APP_BASE_URL . '/login.php');
```

- [ ] **Step 4: Write admin/dashboard.php**

```php
<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();

$stats = get_dashboard_stats(get_pdo());
$currentUser = current_user();
$currentPage = 'dashboard';

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main class="lg:ml-64 min-h-screen bg-surface-950">
    <div class="p-6 lg:p-8">
        <h1 class="text-xl font-semibold text-white mb-6">DASHBOARD</h1>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="bg-surface-900 border border-white/10 rounded-xl p-5">
                <p class="text-xs text-gray-500 mb-2">TOTAL GRADUATES</p>
                <p class="text-2xl font-semibold text-white"><?= number_format($stats['total_graduates']) ?></p>
            </div>
            <div class="bg-surface-900 border border-white/10 rounded-xl p-5">
                <p class="text-xs text-gray-500 mb-2">TOTAL SCHOOLS</p>
                <p class="text-2xl font-semibold text-white"><?= number_format($stats['total_schools']) ?></p>
            </div>
            <div class="bg-surface-900 border border-white/10 rounded-xl p-5">
                <p class="text-xs text-gray-500 mb-2">TOTAL PDF LISTS</p>
                <p class="text-2xl font-semibold text-white"><?= number_format($stats['total_uploads']) ?></p>
            </div>
            <div class="bg-surface-900 border border-white/10 rounded-xl p-5">
                <p class="text-xs text-gray-500 mb-2">CURRENT YEAR RECORDS</p>
                <p class="text-2xl font-semibold text-white"><?= number_format($stats['current_year_records']) ?></p>
                <p class="text-xs text-gray-600 mt-1"><?= $stats['current_year'] ? e((string) $stats['current_year']) : 'NO DATA YET' ?></p>
            </div>
        </div>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
```

- [ ] **Step 5: Rebuild Tailwind now that all markup exists**

Run: `npm run build:css`
Expected: `assets/css/app.css` regenerated including every class referenced above (e.g. `bg-accent-600`, `border-accent-500`).

- [ ] **Step 6: Confirm the full PHPUnit suite still passes**

Run: `php vendor/bin/phpunit`
Expected: all tests from Tasks 1–9 pass, `OK (...)`.

- [ ] **Step 7: Manual end-to-end verification in the browser**

With Apache and MySQL running in XAMPP and the admin account seeded (Task 8):

1. Visit `http://localhost/hge_masterlist/admin/dashboard.php` while logged out → redirected to `login.php`. ✅ confirms `require_login()`.
2. Log in with the seeded admin credentials → redirected to the dashboard, sidebar shows HGE DATABASE / DASHBOARD highlighted in violet, header shows the admin's name and role. ✅ confirms `attempt_login`/`login_user`/session.
3. Dashboard shows all four stat cards as `0` (fresh database) with "NO DATA YET" under Current Year Records. ✅ confirms `get_dashboard_stats()` on empty data.
4. Resize the browser to a narrow/mobile width → sidebar is hidden, hamburger button visible; clicking it slides the sidebar in with a dark overlay; clicking the overlay closes it. ✅ confirms the mobile sidebar toggle.
5. Click "HONOR GRADUATE LOOK-UP" or "UPLOAD LATIN HONOR LIST" in the sidebar → expected 404 (these pages arrive in Phases 2 and 4). This is expected, not a defect.
6. Click "LOGOUT" → session destroyed, redirected to `login.php`; visiting `admin/dashboard.php` again redirects back to login. ✅ confirms `logout_user()`.
7. Try submitting the login form with a stale/blank CSRF token (e.g., open dev tools, clear the hidden input's value, submit) → "YOUR SESSION EXPIRED. PLEASE TRY AGAIN." is shown, not a raw PHP error. ✅ confirms `csrf_verify()`.

- [ ] **Step 8: Commit**

```bash
git add login.php logout.php index.php admin/dashboard.php assets/css/app.css
git commit -m "feat: wire login, logout, and dashboard pages together"
```

---

## Phase 1 exit criteria

- `php vendor/bin/phpunit` passes in full.
- A human can log in as the seeded administrator, see a real (zero) dashboard, use the mobile sidebar, and log out — all against the real `hge_masterlist` MySQL database, no mocked data.
- Every protected page rejects unauthenticated access server-side.
- Phase 2 (Schools + Upload/Storage) is the next plan, written after this phase is reviewed and confirmed working.
