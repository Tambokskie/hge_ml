# Phase 4 (Look-up + Verification) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the graduate look-up/verification page — search by school, graduation year, and/or graduate name; a paginated results table; a details modal with a "VIEW SOURCE PDF" link; and the two required empty states — as the first real consumer of the `latin_honor_graduates` rows Phase 3 produces.

**Architecture:** A generic paginated search function (`search_graduates()`) sits behind a thin JSON API (`api/search_graduates.php`) and is also called directly by the page (`admin/lookup.php`) for its initial server-render, so a bookmarked/shared search URL works even before JavaScript runs. `assets/js/lookup.js` takes over after load: it intercepts the filter form, re-fetches on filter/page change without a full reload, keeps the URL in sync via `history.pushState`, and opens a details modal (reusing `modal.js`) entirely from data already present on each row — no second network round trip per VIEW click.

**Tech Stack:** PHP 8.2 (PDO, prepared statements), MariaDB, vanilla JS (`fetch`, `history.pushState`), Tailwind (compiled, no new build step needed — `tailwind.config.js`'s content glob already covers `./**/*.php` and `./assets/js/**/*.js`).

**Spec:** `docs/superpowers/specs/2026-09-12-hge-database-design.md` (section 8 for the base look-up/verification design, section 13 for the Phase 4 addendum with all decisions made — read both before starting).

## Global Constraints

- PHP >= 8.1, all queries via PDO prepared statements (`config/database.php`'s `get_pdo()`), all output escaped at render time with `e()`/`e_upper()` from `includes/functions.php`.
- `admin/lookup.php` and `api/search_graduates.php` call `require_login()` only — never `require_role('administrator')`. Both `administrator` and `user` roles must be able to search and view results (spec section 13).
- No schema changes. `database/schema.sql` is not touched — `activity_log.action` already includes `'search'` in its ENUM (Phase 1).
- Filters are SCHOOL (`school_id`), GRADUATION YEAR (`graduation_year`), and GRADUATE NAME (partial, case-insensitive `LIKE` match) — exactly these three, combined with AND when more than one is present. No FULLTEXT, no additional filters (spec section 13).
- `api/search_graduates.php` is a **GET** endpoint with no CSRF check — it has no state-changing side effect visible to the user (the `activity_log` write is an internal audit trail, not user-facing state), and the spec explicitly requires the current filters/page to be shareable via URL query string, which only a GET request supports.
- Every honor-graduate row a search returns must stay traceable to its own `upload_id` — never deduplicate or collapse rows that came from two different uploads of the same school+year (spec section 13's "duplicate uploads produce duplicate-looking rows, by design").
- `api/serve_pdf.php` already exists and is role-agnostic (`require_login()` only) — this phase only links to it (`?id=<upload_id>`), it does not modify it.

---

### Task 1: Look-up data helpers — active schools list, distinct years, activity logging

**Files:**
- Modify: `includes/schools.php` (append after `get_active_school_names()`, currently lines 37-41)
- Modify: `includes/functions.php` (append after `sidebar_nav_class()`, currently lines 119-126)
- Test: `tests/includes/SchoolsTest.php` (append one test method)
- Test: `tests/includes/GraduateLookupHelpersTest.php` (new)

**Interfaces:**
- Produces: `get_active_schools_list(PDO $pdo): array` — rows of `['id' => ..., 'school_name' => ...]`, active schools only, alphabetical.
- Produces: `get_distinct_graduation_years(PDO $pdo): array` — `int[]`, descending, from `latin_honor_graduates.graduation_year`.
- Produces: `log_activity(PDO $pdo, ?int $userId, string $action, ?string $details = null): void` — inserts one `activity_log` row.
- Consumed by: Task 2 (none directly), Task 3 and Task 4 (all three functions).

- [ ] **Step 1: Write the failing tests**

Append to `tests/includes/SchoolsTest.php` (inside the `SchoolsTest` class, after `testGetActiveSchoolNamesReturnsOnlyActiveSchoolsSortedAlphabetically`):

```php
    public function testGetActiveSchoolsListReturnsIdAndNameForActiveSchoolsOnly(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO schools (school_name, status) VALUES ('XYZ College', 'active')");
        $pdo->exec("INSERT INTO schools (school_name, status) VALUES ('ABC University', 'active')");
        $pdo->exec("INSERT INTO schools (school_name, status) VALUES ('Disabled School', 'disabled')");

        $schools = get_active_schools_list($pdo);

        $this->assertCount(2, $schools);
        $this->assertSame('ABC University', $schools[0]['school_name']);
        $this->assertSame('XYZ College', $schools[1]['school_name']);
        $this->assertArrayHasKey('id', $schools[0]);
    }
```

Create `tests/includes/GraduateLookupHelpersTest.php`:

```php
<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;
use Tests\TestDb;

require_once __DIR__ . '/../../includes/functions.php';

class GraduateLookupHelpersTest extends TestCase
{
    protected function setUp(): void
    {
        TestDb::resetSchema();
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO schools (school_name) VALUES ('ABC University')");
        $pdo->exec("INSERT INTO users (name, username, email, password, role) VALUES ('Admin', 'admin', 'a@a.com', 'x', 'administrator')");
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number)
             VALUES (1, '2025', 'a.pdf', 'ABC_UNIVERSITY_2025_1.PDF', '/x/a.pdf', 10, SHA2('a',256), 1, 1)"
        );
    }

    public function testGetDistinctGraduationYearsReturnsDescendingIntegers(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO latin_honor_graduates (upload_id, school_id, graduate_name, latin_honor, graduation_date, graduation_year) VALUES (1, 1, 'A', 'CUM LAUDE', '2023-06-01', 2023)");
        $pdo->exec("INSERT INTO latin_honor_graduates (upload_id, school_id, graduate_name, latin_honor, graduation_date, graduation_year) VALUES (1, 1, 'B', 'CUM LAUDE', '2025-06-01', 2025)");
        $pdo->exec("INSERT INTO latin_honor_graduates (upload_id, school_id, graduate_name, latin_honor, graduation_date, graduation_year) VALUES (1, 1, 'C', 'CUM LAUDE', '2023-06-02', 2023)");

        $years = get_distinct_graduation_years($pdo);

        $this->assertSame([2025, 2023], $years);
    }

    public function testGetDistinctGraduationYearsReturnsEmptyArrayWhenNoGraduates(): void
    {
        $this->assertSame([], get_distinct_graduation_years(TestDb::pdo()));
    }

    public function testLogActivityInsertsRowWithUserIdActionAndDetails(): void
    {
        $pdo = TestDb::pdo();

        log_activity($pdo, 1, 'search', 'name=cruz');

        $row = $pdo->query('SELECT user_id, action, details FROM activity_log ORDER BY id DESC LIMIT 1')->fetch();
        $this->assertSame(1, (int) $row['user_id']);
        $this->assertSame('search', $row['action']);
        $this->assertSame('name=cruz', $row['details']);
    }

    public function testLogActivityAllowsNullUserId(): void
    {
        $pdo = TestDb::pdo();

        log_activity($pdo, null, 'search', null);

        $row = $pdo->query('SELECT user_id, details FROM activity_log ORDER BY id DESC LIMIT 1')->fetch();
        $this->assertNull($row['user_id']);
        $this->assertNull($row['details']);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/includes/SchoolsTest.php tests/includes/GraduateLookupHelpersTest.php`
Expected: FAIL — `get_active_schools_list`, `get_distinct_graduation_years`, and `log_activity` are undefined.

- [ ] **Step 3: Implement**

Append to `includes/schools.php`:

```php
function get_active_schools_list(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT id, school_name FROM schools WHERE status = 'active' ORDER BY school_name ASC");
    return $stmt->fetchAll();
}
```

Append to `includes/functions.php`:

```php
function get_distinct_graduation_years(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT DISTINCT graduation_year FROM latin_honor_graduates ORDER BY graduation_year DESC');
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function log_activity(PDO $pdo, ?int $userId, string $action, ?string $details = null): void
{
    $stmt = $pdo->prepare('INSERT INTO activity_log (user_id, action, details) VALUES (:user_id, :action, :details)');
    $stmt->execute([
        'user_id' => $userId,
        'action' => $action,
        'details' => $details,
    ]);
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/includes/SchoolsTest.php tests/includes/GraduateLookupHelpersTest.php`
Expected: PASS, all tests green.

- [ ] **Step 5: Commit**

```bash
git add includes/schools.php includes/functions.php tests/includes/SchoolsTest.php tests/includes/GraduateLookupHelpersTest.php
git commit -m "feat: add active-schools list, distinct-years, and activity-log helpers"
```

---

### Task 2: Core graduate search function

**Files:**
- Create: `includes/graduates.php`
- Test: `tests/includes/GraduateSearchTest.php` (new)

**Interfaces:**
- Consumes: `paginate(int $totalRows, int $page, int $perPage = 25): array` from `includes/functions.php` (already exists — do not redefine it).
- Produces: `search_graduates(PDO $pdo, array $filters, int $page, int $perPage = 25): array` — `$filters` keys are `school_id` (`?int`), `graduation_year` (`?int`), `name` (`?string`, partial match). Returns `['rows' => array, 'pagination' => array]`, where each row is `['id', 'graduate_name', 'latin_honor', 'graduation_date', 'graduation_year', 'school_name', 'upload_id', 'original_filename', 'uploaded_at']` and `pagination` is exactly `paginate()`'s return shape.
- Produces: `build_search_log_details(array $filters): string` — same `$filters` shape, returns a comma-separated summary (e.g. `"school_id=3, year=2025, name=cruz"`) including only the keys that are present/non-empty; returns `''` when no filters are set.
- Note for the reviewer: `search_graduates()` is a generic paginated primitive and **will** run a query and return all rows when `$filters` is empty — the "don't query when zero filters are set" policy is deliberately Task 3/4's responsibility (the API and page layer), not this function's. Do not flag the pagination test below (which calls it with `[]`) as a spec violation.

- [ ] **Step 1: Write the failing tests**

Create `tests/includes/GraduateSearchTest.php`:

```php
<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;
use Tests\TestDb;

require_once __DIR__ . '/../../includes/graduates.php';

class GraduateSearchTest extends TestCase
{
    protected function setUp(): void
    {
        TestDb::resetSchema();
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO schools (school_name) VALUES ('ABC University'), ('XYZ College')");
        $pdo->exec("INSERT INTO users (name, username, email, password, role) VALUES ('Admin', 'admin', 'a@a.com', 'x', 'administrator')");
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number)
             VALUES (1, '2025', 'abc-2025.pdf', 'ABC_UNIVERSITY_2025_1.PDF', '/x/abc-2025.pdf', 10, SHA2('abc2025',256), 1, 1)"
        );
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number)
             VALUES (2, '2024', 'xyz-2024.pdf', 'XYZ_COLLEGE_2024_1.PDF', '/x/xyz-2024.pdf', 10, SHA2('xyz2024',256), 1, 1)"
        );
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number)
             VALUES (1, '2025', 'abc-2025-corrected.pdf', 'ABC_UNIVERSITY_2025_2.PDF', '/x/abc-2025-2.pdf', 10, SHA2('abc2025b',256), 1, 2)"
        );
    }

    private function insertGraduate(int $uploadId, int $schoolId, string $name, string $honor, ?string $date, int $year): void
    {
        $stmt = TestDb::pdo()->prepare(
            'INSERT INTO latin_honor_graduates (upload_id, school_id, graduate_name, latin_honor, graduation_date, graduation_year)
             VALUES (:upload_id, :school_id, :name, :honor, :date, :year)'
        );
        $stmt->execute([
            'upload_id' => $uploadId,
            'school_id' => $schoolId,
            'name' => $name,
            'honor' => $honor,
            'date' => $date,
            'year' => $year,
        ]);
    }

    public function testSearchByNameMatchesPartialCaseInsensitive(): void
    {
        $this->insertGraduate(1, 1, 'Juan Dela Cruz', 'CUM LAUDE', '2025-06-15', 2025);
        $this->insertGraduate(2, 2, 'Maria Santos', 'MAGNA CUM LAUDE', '2024-06-15', 2024);

        $result = search_graduates(TestDb::pdo(), ['name' => 'dela cruz'], 1);

        $this->assertCount(1, $result['rows']);
        $this->assertSame('Juan Dela Cruz', $result['rows'][0]['graduate_name']);
        $this->assertSame(1, $result['pagination']['total_rows']);
    }

    public function testCombinesSchoolAndYearFiltersWithAnd(): void
    {
        $this->insertGraduate(1, 1, 'Juan Dela Cruz', 'CUM LAUDE', '2025-06-15', 2025);
        $this->insertGraduate(2, 2, 'Juan Dela Cruz', 'CUM LAUDE', '2024-06-15', 2024);

        $result = search_graduates(TestDb::pdo(), ['school_id' => 1, 'graduation_year' => 2025], 1);

        $this->assertCount(1, $result['rows']);
        $this->assertSame('ABC University', $result['rows'][0]['school_name']);
        $this->assertSame('2025', (string) $result['rows'][0]['graduation_year']);
    }

    public function testSameNameFromTwoUploadsOfSameSchoolYearBothAppear(): void
    {
        $this->insertGraduate(1, 1, 'Juan Dela Cruz', 'CUM LAUDE', '2025-06-15', 2025);
        $this->insertGraduate(3, 1, 'Juan Dela Cruz', 'MAGNA CUM LAUDE', '2025-06-16', 2025);

        $result = search_graduates(TestDb::pdo(), ['name' => 'Juan Dela Cruz'], 1);

        $this->assertCount(2, $result['rows']);
        $uploadIds = array_map('intval', array_column($result['rows'], 'upload_id'));
        sort($uploadIds);
        $this->assertSame([1, 3], $uploadIds);
    }

    public function testPaginatesResultsAcrossPages(): void
    {
        foreach (['Ana Reyes', 'Ben Cruz', 'Carla Diaz'] as $name) {
            $this->insertGraduate(1, 1, $name, 'CUM LAUDE', '2025-06-15', 2025);
        }

        $page1 = search_graduates(TestDb::pdo(), [], 1, 2);
        $page2 = search_graduates(TestDb::pdo(), [], 2, 2);

        $this->assertCount(2, $page1['rows']);
        $this->assertCount(1, $page2['rows']);
        $this->assertSame(3, $page1['pagination']['total_rows']);
        $this->assertSame(2, $page1['pagination']['total_pages']);
    }

    public function testReturnsEmptyRowsForNoMatches(): void
    {
        $result = search_graduates(TestDb::pdo(), ['name' => 'Nobody Here'], 1);

        $this->assertSame([], $result['rows']);
        $this->assertSame(0, $result['pagination']['total_rows']);
    }

    public function testResultRowsIncludeUploadTraceabilityFields(): void
    {
        $this->insertGraduate(1, 1, 'Juan Dela Cruz', 'CUM LAUDE', '2025-06-15', 2025);

        $result = search_graduates(TestDb::pdo(), ['name' => 'Juan'], 1);

        $row = $result['rows'][0];
        $this->assertSame(1, (int) $row['upload_id']);
        $this->assertSame('abc-2025.pdf', $row['original_filename']);
        $this->assertArrayHasKey('uploaded_at', $row);
    }

    public function testBuildSearchLogDetailsIncludesOnlyPresentFilters(): void
    {
        $this->assertSame('school_id=3, year=2025, name=cruz', build_search_log_details(['school_id' => 3, 'graduation_year' => 2025, 'name' => 'cruz']));
        $this->assertSame('name=cruz', build_search_log_details(['name' => 'cruz']));
        $this->assertSame('', build_search_log_details([]));
        $this->assertSame('', build_search_log_details(['school_id' => null, 'graduation_year' => null, 'name' => '']));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/includes/GraduateSearchTest.php`
Expected: FAIL — `search_graduates` and `build_search_log_details` are undefined.

- [ ] **Step 3: Implement**

Create `includes/graduates.php`:

```php
<?php

function search_graduates(PDO $pdo, array $filters, int $page, int $perPage = 25): array
{
    $conditions = [];
    $params = [];

    if (!empty($filters['school_id'])) {
        $conditions[] = 'g.school_id = :school_id';
        $params['school_id'] = (int) $filters['school_id'];
    }

    if (!empty($filters['graduation_year'])) {
        $conditions[] = 'g.graduation_year = :graduation_year';
        $params['graduation_year'] = (int) $filters['graduation_year'];
    }

    $name = trim((string) ($filters['name'] ?? ''));
    if ($name !== '') {
        $conditions[] = 'g.graduate_name LIKE :name';
        $params['name'] = '%' . $name . '%';
    }

    $whereSql = empty($conditions) ? '' : ('WHERE ' . implode(' AND ', $conditions));

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM latin_honor_graduates g {$whereSql}");
    $countStmt->execute($params);
    $totalRows = (int) $countStmt->fetchColumn();

    $pagination = paginate($totalRows, $page, $perPage);

    $sql = "SELECT g.id, g.graduate_name, g.latin_honor, g.graduation_date, g.graduation_year,
                   s.school_name, u.id AS upload_id, u.original_filename, u.uploaded_at
            FROM latin_honor_graduates g
            JOIN schools s ON s.id = g.school_id
            JOIN latin_honor_uploads u ON u.id = g.upload_id
            {$whereSql}
            ORDER BY g.graduate_name ASC
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

function build_search_log_details(array $filters): string
{
    $parts = [];

    if (!empty($filters['school_id'])) {
        $parts[] = 'school_id=' . (int) $filters['school_id'];
    }

    if (!empty($filters['graduation_year'])) {
        $parts[] = 'year=' . (int) $filters['graduation_year'];
    }

    $name = trim((string) ($filters['name'] ?? ''));
    if ($name !== '') {
        $parts[] = 'name=' . $name;
    }

    return implode(', ', $parts);
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/includes/GraduateSearchTest.php`
Expected: PASS, all 7 tests green.

- [ ] **Step 5: Commit**

```bash
git add includes/graduates.php tests/includes/GraduateSearchTest.php
git commit -m "feat: add search_graduates() paginated search primitive"
```

---

### Task 3: `api/search_graduates.php` — JSON search endpoint

**Files:**
- Create: `api/search_graduates.php`

**Interfaces:**
- Consumes: `search_graduates()` and `build_search_log_details()` (Task 2), `log_activity()` (Task 1), `require_login()`/`current_user()` (`includes/auth.php`), `get_pdo()` (`config/database.php`).
- Produces (JSON, `Content-Type: application/json`):
  - No filters: `{"status":"success","has_filters":false,"rows":[],"pagination":null}`
  - With filters: `{"status":"success","has_filters":true,"rows":[...],"pagination":{...}}` — `rows`/`pagination` shaped exactly as `search_graduates()` returns them.
  - On any `\Throwable`: HTTP 500, `{"status":"error","message":"THE SEARCH COULD NOT BE COMPLETED. PLEASE TRY AGAIN."}` (matches the outer-try/catch pattern used by every other `api/*.php` file in this project).

There is no PHPUnit test for this file — it is a thin wrapper and its logic is fully covered by Task 1/2's unit tests. Verify it manually (Step 2 below) and again as part of Task 6's end-to-end pass.

- [ ] **Step 1: Implement**

Create `api/search_graduates.php`:

```php
<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/graduates.php';

require_login();

header('Content-Type: application/json');

$schoolId = (isset($_GET['school_id']) && $_GET['school_id'] !== '') ? (int) $_GET['school_id'] : null;
$year = (isset($_GET['year']) && $_GET['year'] !== '') ? (int) $_GET['year'] : null;
$name = trim((string) ($_GET['name'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));

$filters = [
    'school_id' => $schoolId,
    'graduation_year' => $year,
    'name' => $name,
];

$hasFilters = $schoolId !== null || $year !== null || $name !== '';

try {
    if (!$hasFilters) {
        echo json_encode(['status' => 'success', 'has_filters' => false, 'rows' => [], 'pagination' => null]);
        exit;
    }

    $pdo = get_pdo();
    $result = search_graduates($pdo, $filters, $page);

    $currentUser = current_user();
    log_activity($pdo, $currentUser['id'] ?? null, 'search', build_search_log_details($filters));

    echo json_encode([
        'status' => 'success',
        'has_filters' => true,
        'rows' => $result['rows'],
        'pagination' => $result['pagination'],
    ]);
} catch (\Throwable $e) {
    error_log('Graduate search failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'THE SEARCH COULD NOT BE COMPLETED. PLEASE TRY AGAIN.']);
}
```

- [ ] **Step 2: Manually verify the endpoint**

Start the built-in PHP server from the project root (adjust if a server is already running for this worktree):

```bash
php -S 127.0.0.1:8899 -t .
```

With a logged-in session cookie (log in via the browser first, or use the browser-based check in Task 6), hit:
- `http://127.0.0.1:8899/api/search_graduates.php` → expect `{"status":"success","has_filters":false,"rows":[],"pagination":null}`
- `http://127.0.0.1:8899/api/search_graduates.php?name=test` → expect `has_filters:true` and a `pagination` object (rows may be empty if no data exists yet).

Stop the server after checking.

- [ ] **Step 3: Commit**

```bash
git add api/search_graduates.php
git commit -m "feat: add api/search_graduates.php JSON search endpoint"
```

---

### Task 4: `admin/lookup.php` — look-up page

**Files:**
- Modify: `admin/lookup.php` (currently does not exist — the folder-structure spec and `includes/sidebar.php:10` already reference it; this task creates it)

**Interfaces:**
- Consumes: `get_active_schools_list()`, `search_graduates()`, `build_search_log_details()`, `log_activity()` (Tasks 1-2), `require_login()`/`current_user()`/`csrf_token()` (`includes/auth.php`), `get_pdo()`, `e()`/`e_upper()`/`format_date_display()`/`paginate()` (`includes/functions.php`), `includes/header.php`/`includes/sidebar.php`/`includes/footer.php` (existing partials, unmodified).
- Produces: the `window.HGE_LOOKUP = { apiBase: ... }` config object and the DOM contract Task 5's JS depends on:
  - `#lookup-filter-form` (a `<form method="get">`) containing `#filter-school` (`<select name="school_id">`), `#filter-year` (`<select name="year">`), `#filter-name` (`<input name="name">`).
  - `#lookup-results` — the container Task 5 replaces wholesale on every search/page change.
  - Inside it when rows exist: `#lookup-results-tbody` rows, each with a `.view-graduate-btn` carrying `data-name`, `data-school`, `data-honor`, `data-date` (already display-formatted, e.g. `JUNE 15, 2025` or `—`), `data-year`, `data-upload-id`, `data-filename`, `data-uploaded-at` (already display-formatted).
  - Pagination buttons use class `.lookup-page-btn` with `data-page`.

There is no PHPUnit test for this file (it is a view, like `admin/dashboard.php` and `admin/view_upload.php`, neither of which has one). Verify it as part of Task 6's end-to-end browser pass.

- [ ] **Step 1: Implement**

Create `admin/lookup.php`:

```php
<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/schools.php';
require_once __DIR__ . '/../includes/graduates.php';

require_login();

$pdo = get_pdo();
$schools = get_active_schools_list($pdo);
$years = get_distinct_graduation_years($pdo);

$schoolId = (isset($_GET['school_id']) && $_GET['school_id'] !== '') ? (int) $_GET['school_id'] : null;
$year = (isset($_GET['year']) && $_GET['year'] !== '') ? (int) $_GET['year'] : null;
$name = trim((string) ($_GET['name'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));

$filters = ['school_id' => $schoolId, 'graduation_year' => $year, 'name' => $name];
$hasFilters = $schoolId !== null || $year !== null || $name !== '';

$searchResult = null;
if ($hasFilters) {
    try {
        $searchResult = search_graduates($pdo, $filters, $page);
        $currentUser = current_user();
        log_activity($pdo, $currentUser['id'] ?? null, 'search', build_search_log_details($filters));
    } catch (\Throwable $e) {
        error_log('Graduate search failed: ' . $e->getMessage());
        $searchResult = ['rows' => [], 'pagination' => paginate(0, 1)];
    }
}

$currentUser = current_user();
$currentPage = 'lookup';

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main class="lg:ml-64 min-h-screen bg-surface-950">
    <div class="p-6 lg:p-8">
        <h1 class="text-xl font-semibold text-white mb-2">HONOR GRADUATE LOOK-UP</h1>
        <p class="text-sm text-gray-500 mb-6">SEARCH BY SCHOOL, GRADUATION YEAR, OR GRADUATE NAME TO VERIFY A HONOR GRADUATE RECORD.</p>

        <form id="lookup-filter-form" method="get" class="grid grid-cols-1 sm:grid-cols-4 gap-3 mb-6">
            <div>
                <label class="block text-xs text-gray-400 mb-1">SCHOOL</label>
                <select name="school_id" id="filter-school" class="w-full rounded-lg bg-surface-800 border border-white/10 px-3 py-2 text-sm">
                    <option value="">ALL SCHOOLS</option>
                    <?php foreach ($schools as $school): ?>
                        <option value="<?= (int) $school['id'] ?>" <?= $schoolId === (int) $school['id'] ? 'selected' : '' ?>><?= e_upper($school['school_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-400 mb-1">GRADUATION YEAR</label>
                <select name="year" id="filter-year" class="w-full rounded-lg bg-surface-800 border border-white/10 px-3 py-2 text-sm">
                    <option value="">ALL YEARS</option>
                    <?php foreach ($years as $y): ?>
                        <option value="<?= (int) $y ?>" <?= $year === (int) $y ? 'selected' : '' ?>><?= (int) $y ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="sm:col-span-2">
                <label class="block text-xs text-gray-400 mb-1">GRADUATE NAME</label>
                <input type="text" name="name" id="filter-name" value="<?= e($name) ?>" placeholder="SEARCH BY NAME..." class="w-full rounded-lg bg-surface-800 border border-white/10 px-3 py-2 text-sm placeholder:text-gray-600">
            </div>
            <div class="sm:col-span-4">
                <button type="submit" class="rounded-lg bg-accent-600 hover:bg-accent-500 text-white text-sm font-medium px-4 py-2">SEARCH</button>
            </div>
        </form>

        <div id="lookup-results">
            <?php if (!$hasFilters): ?>
                <div class="rounded-xl border border-white/10 bg-surface-900 p-10 text-center text-gray-400">
                    <p class="text-sm">SEARCH BY NAME, SCHOOL, OR YEAR TO FIND HONOR GRADUATE RECORDS.</p>
                </div>
            <?php elseif (empty($searchResult['rows'])): ?>
                <div class="rounded-xl border border-white/10 bg-surface-900 p-10 text-center text-gray-400">
                    <p class="text-sm font-medium text-gray-300">NO HONOR GRADUATE RECORD FOUND.</p>
                </div>
            <?php else: ?>
                <div class="bg-surface-900 border border-white/10 rounded-xl overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead>
                            <tr class="text-xs text-gray-500 border-b border-white/10">
                                <th class="px-4 py-3">GRADUATE NAME</th>
                                <th class="px-4 py-3">SCHOOL</th>
                                <th class="px-4 py-3">LATIN HONOR</th>
                                <th class="px-4 py-3">GRADUATION YEAR</th>
                                <th class="px-4 py-3">ACTION</th>
                            </tr>
                        </thead>
                        <tbody id="lookup-results-tbody">
                            <?php foreach ($searchResult['rows'] as $row): ?>
                                <tr class="border-b border-white/5 hover:bg-white/5">
                                    <td class="px-4 py-3"><?= e_upper($row['graduate_name']) ?></td>
                                    <td class="px-4 py-3"><?= e_upper($row['school_name']) ?></td>
                                    <td class="px-4 py-3"><?= e($row['latin_honor']) ?></td>
                                    <td class="px-4 py-3"><?= e((string) $row['graduation_year']) ?></td>
                                    <td class="px-4 py-3">
                                        <button type="button" class="view-graduate-btn text-accent-400 hover:underline"
                                            data-name="<?= e($row['graduate_name']) ?>"
                                            data-school="<?= e($row['school_name']) ?>"
                                            data-honor="<?= e($row['latin_honor']) ?>"
                                            data-date="<?= e(format_date_display($row['graduation_date'])) ?>"
                                            data-year="<?= e((string) $row['graduation_year']) ?>"
                                            data-upload-id="<?= (int) $row['upload_id'] ?>"
                                            data-filename="<?= e($row['original_filename']) ?>"
                                            data-uploaded-at="<?= e_upper(date('F j, Y', strtotime($row['uploaded_at']))) ?>">VIEW</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div id="lookup-pagination" class="flex items-center justify-between mt-4 text-sm text-gray-400">
                    <span>PAGE <?= (int) $searchResult['pagination']['page'] ?> OF <?= (int) $searchResult['pagination']['total_pages'] ?> (<?= (int) $searchResult['pagination']['total_rows'] ?> RECORDS)</span>
                    <div class="flex gap-2">
                        <?php if ($searchResult['pagination']['page'] > 1): ?>
                            <button type="button" class="lookup-page-btn rounded-lg border border-white/10 px-3 py-1.5 hover:bg-white/5" data-page="<?= (int) $searchResult['pagination']['page'] - 1 ?>">PREV</button>
                        <?php endif; ?>
                        <?php if ($searchResult['pagination']['page'] < $searchResult['pagination']['total_pages']): ?>
                            <button type="button" class="lookup-page-btn rounded-lg border border-white/10 px-3 py-1.5 hover:bg-white/5" data-page="<?= (int) $searchResult['pagination']['page'] + 1 ?>">NEXT</button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
<script>
window.HGE_LOOKUP = {
    apiBase: <?= json_encode(APP_BASE_URL) ?>
};
</script>
<script src="<?= e(APP_BASE_URL) ?>/assets/js/lookup.js"></script>
```

- [ ] **Step 2: Commit**

```bash
git add admin/lookup.php
git commit -m "feat: add admin/lookup.php look-up page with server-rendered initial state"
```

---

### Task 5: `assets/js/lookup.js` — AJAX filtering, pagination, and details modal

**Files:**
- Create: `assets/js/lookup.js`

**Interfaces:**
- Consumes: `window.HGE_LOOKUP` (Task 4), `showModal()` (`assets/js/modal.js`), `showToast()` (`assets/js/toast.js`), the DOM contract from Task 4 (`#lookup-filter-form`, `#filter-school`/`#filter-year`/`#filter-name`, `#lookup-results`, `.view-graduate-btn`, `.lookup-page-btn`), and `api/search_graduates.php`'s JSON contract (Task 3).
- No exports — this is a page-specific script loaded only by `admin/lookup.php`, same as `assets/js/review.js` and `assets/js/view_upload.js`.

- [ ] **Step 1: Implement**

Create `assets/js/lookup.js`:

```js
document.addEventListener('DOMContentLoaded', function () {
    var config = window.HGE_LOOKUP;
    var form = document.getElementById('lookup-filter-form');
    var resultsContainer = document.getElementById('lookup-results');
    if (!config || !form || !resultsContainer) {
        return;
    }

    var MONTHS = ['JANUARY', 'FEBRUARY', 'MARCH', 'APRIL', 'MAY', 'JUNE', 'JULY', 'AUGUST', 'SEPTEMBER', 'OCTOBER', 'NOVEMBER', 'DECEMBER'];

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function formatDisplayDate(dateStr) {
        if (!dateStr) {
            return '—';
        }
        var parts = String(dateStr).split(' ')[0].split('-');
        if (parts.length !== 3) {
            return '—';
        }
        var year = parseInt(parts[0], 10);
        var month = MONTHS[parseInt(parts[1], 10) - 1];
        var day = parseInt(parts[2], 10);
        if (!month || !year || !day) {
            return '—';
        }
        return month + ' ' + day + ', ' + year;
    }

    function currentParams(page) {
        return {
            school_id: document.getElementById('filter-school').value,
            year: document.getElementById('filter-year').value,
            name: document.getElementById('filter-name').value,
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
        if (!data.has_filters) {
            resultsContainer.innerHTML =
                '<div class="rounded-xl border border-white/10 bg-surface-900 p-10 text-center text-gray-400">' +
                '<p class="text-sm">SEARCH BY NAME, SCHOOL, OR YEAR TO FIND HONOR GRADUATE RECORDS.</p></div>';
            return;
        }

        if (data.rows.length === 0) {
            resultsContainer.innerHTML =
                '<div class="rounded-xl border border-white/10 bg-surface-900 p-10 text-center text-gray-400">' +
                '<p class="text-sm font-medium text-gray-300">NO HONOR GRADUATE RECORD FOUND.</p></div>';
            return;
        }

        var rowsHtml = data.rows.map(function (row) {
            var displayDate = formatDisplayDate(row.graduation_date);
            var displayUploadedAt = formatDisplayDate(row.uploaded_at);
            return '<tr class="border-b border-white/5 hover:bg-white/5">' +
                '<td class="px-4 py-3">' + escapeHtml(String(row.graduate_name).toUpperCase()) + '</td>' +
                '<td class="px-4 py-3">' + escapeHtml(String(row.school_name).toUpperCase()) + '</td>' +
                '<td class="px-4 py-3">' + escapeHtml(row.latin_honor) + '</td>' +
                '<td class="px-4 py-3">' + escapeHtml(String(row.graduation_year)) + '</td>' +
                '<td class="px-4 py-3">' +
                '<button type="button" class="view-graduate-btn text-accent-400 hover:underline" ' +
                'data-name="' + escapeHtml(row.graduate_name) + '" ' +
                'data-school="' + escapeHtml(row.school_name) + '" ' +
                'data-honor="' + escapeHtml(row.latin_honor) + '" ' +
                'data-date="' + escapeHtml(displayDate) + '" ' +
                'data-year="' + escapeHtml(String(row.graduation_year)) + '" ' +
                'data-upload-id="' + escapeHtml(String(row.upload_id)) + '" ' +
                'data-filename="' + escapeHtml(row.original_filename) + '" ' +
                'data-uploaded-at="' + escapeHtml(displayUploadedAt) + '">VIEW</button>' +
                '</td></tr>';
        }).join('');

        var pagination = data.pagination;
        var paginationHtml =
            '<div id="lookup-pagination" class="flex items-center justify-between mt-4 text-sm text-gray-400">' +
            '<span>PAGE ' + pagination.page + ' OF ' + pagination.total_pages + ' (' + pagination.total_rows + ' RECORDS)</span>' +
            '<div class="flex gap-2">' +
            (pagination.page > 1 ? '<button type="button" class="lookup-page-btn rounded-lg border border-white/10 px-3 py-1.5 hover:bg-white/5" data-page="' + (pagination.page - 1) + '">PREV</button>' : '') +
            (pagination.page < pagination.total_pages ? '<button type="button" class="lookup-page-btn rounded-lg border border-white/10 px-3 py-1.5 hover:bg-white/5" data-page="' + (pagination.page + 1) + '">NEXT</button>' : '') +
            '</div></div>';

        resultsContainer.innerHTML =
            '<div class="bg-surface-900 border border-white/10 rounded-xl overflow-x-auto"><table class="w-full text-sm text-left">' +
            '<thead><tr class="text-xs text-gray-500 border-b border-white/10">' +
            '<th class="px-4 py-3">GRADUATE NAME</th><th class="px-4 py-3">SCHOOL</th><th class="px-4 py-3">LATIN HONOR</th>' +
            '<th class="px-4 py-3">GRADUATION YEAR</th><th class="px-4 py-3">ACTION</th></tr></thead>' +
            '<tbody id="lookup-results-tbody">' + rowsHtml + '</tbody></table></div>' + paginationHtml;
    }

    function performSearch(params, updateHistory) {
        var query = buildQueryString(params);

        if (updateHistory) {
            var newUrl = window.location.pathname + (query ? '?' + query : '');
            window.history.pushState(params, '', newUrl);
        }

        fetch(config.apiBase + '/api/search_graduates.php' + (query ? '?' + query : ''))
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
                showToast('error', 'THE SEARCH COULD NOT BE COMPLETED. PLEASE TRY AGAIN.');
            });
    }

    function openDetailsModal(data) {
        var bodyHtml =
            '<div class="space-y-2">' +
            '<div><span class="text-xs text-gray-500">GRADUATE NAME</span><p class="text-white">' + escapeHtml(data.name) + '</p></div>' +
            '<div><span class="text-xs text-gray-500">SCHOOL</span><p class="text-white">' + escapeHtml(data.school) + '</p></div>' +
            '<div><span class="text-xs text-gray-500">LATIN HONOR</span><p class="text-white">' + escapeHtml(data.honor) + '</p></div>' +
            '<div><span class="text-xs text-gray-500">DATE OF GRADUATION</span><p class="text-white">' + escapeHtml(data.date) + '</p></div>' +
            '<div><span class="text-xs text-gray-500">SOURCE PDF</span><p class="text-white">' + escapeHtml(data.filename) + ' (UPLOADED ' + escapeHtml(data.uploadedAt) + ')</p></div>' +
            '</div>';

        showModal({
            title: 'HONOR GRADUATE RECORD',
            bodyHtml: bodyHtml,
            buttons: [
                { label: 'CLOSE', className: 'rounded-lg px-4 py-2 text-sm text-gray-300 hover:bg-white/5' },
                {
                    label: 'VIEW SOURCE PDF',
                    className: 'rounded-lg px-4 py-2 text-sm bg-accent-600 hover:bg-accent-500 text-white',
                    onClick: function (close) {
                        window.open(config.apiBase + '/api/serve_pdf.php?id=' + encodeURIComponent(data.uploadId), '_blank');
                        close();
                    },
                },
            ],
        });
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        performSearch(currentParams(1), true);
    });

    resultsContainer.addEventListener('click', function (event) {
        var viewBtn = event.target.closest('.view-graduate-btn');
        if (viewBtn) {
            openDetailsModal({
                name: viewBtn.dataset.name,
                school: viewBtn.dataset.school,
                honor: viewBtn.dataset.honor,
                date: viewBtn.dataset.date,
                filename: viewBtn.dataset.filename,
                uploadedAt: viewBtn.dataset.uploadedAt,
                uploadId: viewBtn.dataset.uploadId,
            });
            return;
        }

        var pageBtn = event.target.closest('.lookup-page-btn');
        if (pageBtn) {
            performSearch(currentParams(parseInt(pageBtn.dataset.page, 10)), true);
        }
    });

    window.addEventListener('popstate', function () {
        var urlParams = new URLSearchParams(window.location.search);
        var params = {
            school_id: urlParams.get('school_id') || '',
            year: urlParams.get('year') || '',
            name: urlParams.get('name') || '',
            page: urlParams.get('page') || 1,
        };
        document.getElementById('filter-school').value = params.school_id;
        document.getElementById('filter-year').value = params.year;
        document.getElementById('filter-name').value = params.name;
        performSearch(params, false);
    });
});
```

- [ ] **Step 2: Commit**

```bash
git add assets/js/lookup.js
git commit -m "feat: add lookup.js for AJAX search, pagination, and details modal"
```

---

### Task 6: End-to-end manual verification

**Files:** none (verification only — no code changes expected unless this step surfaces a bug, in which case fix it in the file it belongs to and re-run this task).

- [ ] **Step 1: Run the full automated suite**

```bash
vendor/bin/phpunit
```

Expected: all tests pass (the pre-Phase-4 suite plus every test added in Tasks 1-2).

- [ ] **Step 2: Seed browser-testable data and start a server**

Create a throwaway seed script (not committed — delete it in Step 6) at the project root:

```php
<?php
// seed_lookup_demo.php — throwaway, delete after manual verification.
require_once __DIR__ . '/config/database.php';
$pdo = new PDO('mysql:host=127.0.0.1;dbname=hge_masterlist;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$pdo->exec("INSERT INTO schools (school_name) VALUES ('Test University')");
$schoolId = (int) $pdo->lastInsertId();

$pdo->exec("INSERT INTO users (name, username, email, password, role) VALUES ('Test User', 'testuser', 'tu@example.com', '" . password_hash('testpass123', PASSWORD_DEFAULT) . "', 'user')");

$stmt = $pdo->prepare(
    "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number)
     VALUES (:school_id, '2025', 'seed-1.pdf', 'TEST_UNIVERSITY_2025_1.PDF', '/tmp/seed-1.pdf', 10, SHA2('seed1', 256), 1, 1)"
);
$stmt->execute(['school_id' => $schoolId]);
$uploadId1 = (int) $pdo->lastInsertId();

$stmt = $pdo->prepare(
    "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number)
     VALUES (:school_id, '2025', 'seed-2-corrected.pdf', 'TEST_UNIVERSITY_2025_2.PDF', '/tmp/seed-2.pdf', 10, SHA2('seed2', 256), 1, 2)"
);
$stmt->execute(['school_id' => $schoolId]);
$uploadId2 = (int) $pdo->lastInsertId();

$insertGraduate = $pdo->prepare(
    "INSERT INTO latin_honor_graduates (upload_id, school_id, graduate_name, latin_honor, graduation_date, graduation_year)
     VALUES (:upload_id, :school_id, :name, 'CUM LAUDE', '2025-06-15', 2025)"
);
for ($i = 1; $i <= 26; $i++) {
    $insertGraduate->execute(['upload_id' => $uploadId1, 'school_id' => $schoolId, 'name' => "Seed Graduate {$i}"]);
}
// Same name in both uploads for the same school+year — proves no dedup (spec section 13).
$insertGraduate->execute(['upload_id' => $uploadId2, 'school_id' => $schoolId, 'name' => 'Seed Graduate 1']);

echo "Seeded. School ID: {$schoolId}, uploads: {$uploadId1}, {$uploadId2}\n";
```

Run it, then create a small prepend file so the built-in server serves the app at the site root (mirroring the `auto_prepend_file` trick used for manual verification in Phases 2-3, where `APP_BASE_URL` needs to resolve to `''` instead of the XAMPP-subfolder default):

```bash
php seed_lookup_demo.php
```

```php
<?php
// prepend_root.php — throwaway, delete after manual verification.
define('APP_BASE_URL', '');
```

```bash
php -S 127.0.0.1:8899 -t . -d auto_prepend_file=prepend_root.php
```

- [ ] **Step 3: Browser walkthrough (administrator)**

Using the Browser tool, log in as the seeded administrator, navigate to `/admin/lookup.php`, and confirm:
- The sidebar's HONOR GRADUATE LOOK-UP link is highlighted as the active nav item.
- On first load (no filters), the instructional empty state renders — no table, no query executed.
- Searching by GRADUATE NAME (partial, e.g. a substring of a seeded name) returns matching rows; searching by SCHOOL alone and by GRADUATION YEAR alone each work; combining two filters narrows results (AND, not OR).
- The seeded Test University name that appears in two uploads for the same year shows as two separate rows, each with a working VIEW SOURCE PDF for its own upload.
- Searching a name with no matches shows "NO HONOR GRADUATE RECORD FOUND."
- Pagination PREV/NEXT buttons work and the URL's `page` query param updates; using the browser Back button returns to the prior page/filters without a full navigation error.
- Clicking VIEW opens the details modal with correct data and a working VIEW SOURCE PDF button (opens the PDF in a new tab via `api/serve_pdf.php`).
- Reloading the browser on a filtered URL (e.g. `?name=cruz&page=2`) reproduces the same results via the server-rendered initial state (confirms the no-JS-required initial render).

- [ ] **Step 4: Browser walkthrough (user role)**

Log out, log in as the seeded `user`-role account, repeat a subset of Step 3 (at least one search + VIEW + VIEW SOURCE PDF) to confirm the page and API do not block non-administrator access.

- [ ] **Step 5: Confirm activity logging**

Query the database to confirm `activity_log` rows were written for the searches performed above:

```bash
mysql -u root hge_masterlist -e "SELECT user_id, action, details FROM activity_log WHERE action = 'search' ORDER BY id DESC LIMIT 5;"
```

Expected: one row per search performed in Steps 3-4, with `details` reflecting the filters used (no row for the initial no-filter page load).

- [ ] **Step 6: Stop the server and clean up seed data**

Stop the PHP built-in server. If the seeded `Test University` data was only for this manual check and would otherwise pollute the dev database, remove it:

```bash
mysql -u root hge_masterlist -e "
DELETE FROM latin_honor_graduates WHERE school_id = (SELECT id FROM schools WHERE school_name = 'Test University');
DELETE FROM latin_honor_uploads WHERE school_id = (SELECT id FROM schools WHERE school_name = 'Test University');
DELETE FROM schools WHERE school_name = 'Test University';
DELETE FROM users WHERE username = 'testuser';
"
```

Delete the two throwaway scripts created in Step 2 — they must never be committed:

```bash
rm -f seed_lookup_demo.php prepend_root.php
```

No commit for this task unless Step 1 or the browser walkthrough surfaced a real bug — in that case, fix it in-place, re-run the relevant PHPUnit tests, and commit the fix with a message describing what was wrong.
