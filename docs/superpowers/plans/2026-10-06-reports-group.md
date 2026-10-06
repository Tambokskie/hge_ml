# Reports Group Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a new `admin/reports.php` page with two year-scoped report sections (Application Filing Report, School Ranking Report) plus a tier-breakdown metric, each exportable to `.xlsx`, visible to both `administrator` and `user` roles.

**Architecture:** Three new pure aggregation functions in `includes/reports.php` back both the page's initial PHP render and two small JSON AJAX endpoints (for year-switch reloads, mirroring the dashboard's existing year-dropdown pattern). Two new `.xlsx` builder functions in `includes/xlsx_writer.php` reuse that file's already-generic sheet/zip helpers unchanged. Two export endpoints follow `api/download_template.php`'s exact existing pattern.

**Tech Stack:** PHP 8.2 (no framework), MySQL/MariaDB, vanilla JS + Tailwind, the project's hand-rolled XLSX writer (no Composer package).

**Spec:** `docs/superpowers/specs/2026-10-06-reports-group-design.md`

## Global Constraints

- `admin/reports.php` and every new `api/*.php` endpoint in this plan use `require_login()` only — never `require_role('administrator')`. Both roles must see identical data.
- No schema changes. Every figure comes from `schools`, `latin_honor_graduates`, and `graduate_applications` as they exist today.
- Each report section takes exactly one graduation year — no year ranges.
- `.xlsx` export only — no PDF, no combined workbook. Each report section gets its own independent export endpoint and download button.
- `includes/*.php` files never `require` each other (established project convention) — `includes/reports.php` returns raw `school_name`/`campus` per row; any caller that needs a formatted display name (`format_school_display_name()`, in `includes/functions.php`) does that formatting itself, not `includes/reports.php` or `includes/xlsx_writer.php`.
- A school with zero Latin Honor graduates in the requested year is excluded from both reports' per-school rows (`INNER JOIN`, not `LEFT JOIN`, from `schools` to `latin_honor_graduates`) — not shown as an all-zero row.
- `filing_rate` is always computed in PHP (`round($applied / $onRecord * 100, 1)`), never in SQL, and only ever divides by an `on_record` value already guaranteed `> 0` for included rows — never a division-by-zero.

## Review Focus

- A year with zero Latin Honor graduates system-wide, selected via the page's year dropdown (not just the default year) — must render an empty-state message, not a PHP error or a blank broken table. Pinned by `get_application_filing_report()`/`get_school_ranking_by_honor_count()`'s own "no data" tests in Tasks 1 and 3, and exercised live in Task 8 by actually selecting such a year in the browser.
- A school with graduates in the requested year but zero applications filed — `filing_rate` must read `0`, not a PHP warning or a blank cell. Pinned by a dedicated test in Task 1.
- Two schools tied on Latin Honor graduate count in the ranking report — must produce a stable, repeatable order (alphabetical tiebreak), not an order that varies between page loads. Pinned by a dedicated test in Task 3.
- A `user`-role (non-administrator) account opening `admin/reports.php` directly, and hitting every new `api/*.php` endpoint directly — must succeed, not redirect to a 403/login. Exercised live in Task 8 using a real `user`-role session, not just an administrator session (every other live-verification step in this project's history has defaulted to an admin session; this plan's access model is different and that difference must actually be exercised, not assumed).
- The generated `.xlsx` files — opened only as raw bytes via the existing `xlsx_read_first_sheet()` reader in the Task 4 tests, never actually opened in a real spreadsheet application anywhere in this plan. If Excel/LibreOffice is available during Task 8's live verification, open at least one downloaded file there too, as a check beyond what the round-trip test alone can catch (e.g. a workbook Excel itself refuses to open despite reading back fine through this project's own reader).

---

### Task 1: `get_application_filing_report()`

**Files:**
- Modify: `includes/reports.php` (new file, created by this task)
- Test: `tests/includes/ReportsTest.php` (new file, created by this task)

**Interfaces:**
- Produces: `get_application_filing_report(PDO $pdo, int $year): array{total_on_record: int, total_applied: int, filing_rate: float, schools: array<int, array{school_name: string, campus: ?string, on_record: int, applied: int, filing_rate: float}>}`

- [ ] **Step 1: Write the failing tests**

Create `tests/includes/ReportsTest.php`:

```php
<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;
use Tests\TestDb;

require_once __DIR__ . '/../../includes/reports.php';

class ReportsTest extends TestCase
{
    protected function setUp(): void
    {
        TestDb::resetSchema();
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO users (name, username, email, password, role) VALUES ('Admin', 'admin', 'a@a.com', 'x', 'administrator')");
        $pdo->exec("INSERT INTO schools (school_name, campus) VALUES ('ABC UNIVERSITY', 'MAIN'), ('XYZ COLLEGE', NULL)");
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number, extraction_status, records_extracted)
             VALUES (1, '2025', 'a.pdf', 'a.pdf', '/x/a.pdf', 10, SHA2('a',256), 1, 1, 'reviewed', 3),
                    (2, '2025', 'b.pdf', 'b.pdf', '/x/b.pdf', 10, SHA2('b',256), 1, 1, 'reviewed', 1)"
        );
    }

    public function testGetApplicationFilingReportComputesPerSchoolAndSystemTotals(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec(
            "INSERT INTO latin_honor_graduates (upload_id, school_id, graduate_name, latin_honor, graduation_year)
             VALUES (1, 1, 'JUAN DELA CRUZ', 'CUM LAUDE', 2025),
                    (1, 1, 'MARIA SANTOS', 'MAGNA CUM LAUDE', 2025),
                    (2, 2, 'PEDRO REYES', 'CUM LAUDE', 2025)"
        );
        $pdo->exec("INSERT INTO graduate_applications (graduate_id, applied_by, application_date) VALUES (1, 1, '2025-07-01')");

        $report = get_application_filing_report($pdo, 2025);

        $this->assertSame(3, $report['total_on_record']);
        $this->assertSame(1, $report['total_applied']);
        $this->assertSame(33.3, $report['filing_rate']);
        $this->assertCount(2, $report['schools']);

        $abc = $report['schools'][0];
        $this->assertSame('ABC UNIVERSITY', $abc['school_name']);
        $this->assertSame(2, $abc['on_record']);
        $this->assertSame(1, $abc['applied']);
        $this->assertSame(50.0, $abc['filing_rate']);
    }

    public function testGetApplicationFilingReportHandlesZeroAppliedWithoutDivisionByZero(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec(
            "INSERT INTO latin_honor_graduates (upload_id, school_id, graduate_name, latin_honor, graduation_year)
             VALUES (1, 1, 'JUAN DELA CRUZ', 'CUM LAUDE', 2025)"
        );

        $report = get_application_filing_report($pdo, 2025);

        $this->assertSame(0, $report['schools'][0]['applied']);
        $this->assertSame(0.0, $report['schools'][0]['filing_rate']);
        $this->assertSame(0.0, $report['filing_rate']);
    }

    public function testGetApplicationFilingReportReturnsEmptyForYearWithNoData(): void
    {
        $report = get_application_filing_report(TestDb::pdo(), 1999);

        $this->assertSame(0, $report['total_on_record']);
        $this->assertSame(0, $report['total_applied']);
        $this->assertSame(0.0, $report['filing_rate']);
        $this->assertSame([], $report['schools']);
    }

    public function testGetApplicationFilingReportExcludesSchoolsWithNoGraduatesThatYear(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec(
            "INSERT INTO latin_honor_graduates (upload_id, school_id, graduate_name, latin_honor, graduation_year)
             VALUES (1, 1, 'JUAN DELA CRUZ', 'CUM LAUDE', 2024)"
        );

        $report = get_application_filing_report($pdo, 2025);

        $this->assertSame([], $report['schools']);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/includes/ReportsTest.php`
Expected: FAIL — `includes/reports.php` doesn't exist yet, so `get_application_filing_report` is an undefined function.

- [ ] **Step 3: Write the implementation**

Create `includes/reports.php`:

```php
<?php

/**
 * Per-school, year-scoped breakdown of Latin Honor graduates on record
 * versus how many have filed for Honor Graduate Eligibility (see
 * includes/graduates.php:file_application() / the graduate_applications
 * table). A school with zero Latin Honor graduates in $year is excluded
 * entirely (INNER JOIN, not LEFT JOIN) rather than shown as an all-zero
 * row. filing_rate is always computed here in PHP, never in SQL, and only
 * ever divides by an on_record value already confirmed > 0.
 */
function get_application_filing_report(PDO $pdo, int $year): array
{
    $stmt = $pdo->prepare(
        'SELECT s.school_name, s.campus,
                COUNT(g.id) AS on_record,
                COUNT(ga.id) AS applied
         FROM schools s
         JOIN latin_honor_graduates g ON g.school_id = s.id AND g.graduation_year = :year
         LEFT JOIN graduate_applications ga ON ga.graduate_id = g.id
         GROUP BY s.id, s.school_name, s.campus
         ORDER BY s.school_name'
    );
    $stmt->execute(['year' => $year]);

    $schools = [];
    $totalOnRecord = 0;
    $totalApplied = 0;

    foreach ($stmt->fetchAll() as $row) {
        $onRecord = (int) $row['on_record'];
        $applied = (int) $row['applied'];
        $totalOnRecord += $onRecord;
        $totalApplied += $applied;

        $schools[] = [
            'school_name' => $row['school_name'],
            'campus' => $row['campus'],
            'on_record' => $onRecord,
            'applied' => $applied,
            'filing_rate' => $onRecord > 0 ? round($applied / $onRecord * 100, 1) : 0.0,
        ];
    }

    return [
        'total_on_record' => $totalOnRecord,
        'total_applied' => $totalApplied,
        'filing_rate' => $totalOnRecord > 0 ? round($totalApplied / $totalOnRecord * 100, 1) : 0.0,
        'schools' => $schools,
    ];
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/includes/ReportsTest.php`
Expected: PASS (all 4 tests).

- [ ] **Step 5: Commit**

```bash
git add includes/reports.php tests/includes/ReportsTest.php
git commit -m "feat: add get_application_filing_report() for the Reports Group"
```

---

### Task 2: `get_honor_tier_breakdown_for_year()`

**Files:**
- Modify: `includes/reports.php`
- Test: `tests/includes/ReportsTest.php`

**Interfaces:**
- Produces: `get_honor_tier_breakdown_for_year(PDO $pdo, int $year): array{'CUM LAUDE': int, 'MAGNA CUM LAUDE': int, 'SUMMA CUM LAUDE': int}`

- [ ] **Step 1: Write the failing tests**

Add to `tests/includes/ReportsTest.php` (inside the existing class, reusing the same `setUp()` fixture — schools/uploads 1 and 2 already exist):

```php
    public function testGetHonorTierBreakdownForYearCountsOnlyThatYear(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec(
            "INSERT INTO latin_honor_graduates (upload_id, school_id, graduate_name, latin_honor, graduation_year)
             VALUES (1, 1, 'JUAN DELA CRUZ', 'CUM LAUDE', 2025),
                    (1, 1, 'MARIA SANTOS', 'CUM LAUDE', 2025),
                    (1, 1, 'PEDRO REYES', 'SUMMA CUM LAUDE', 2025),
                    (2, 2, 'ANA LOPEZ', 'MAGNA CUM LAUDE', 2024)"
        );

        $breakdown = get_honor_tier_breakdown_for_year($pdo, 2025);

        $this->assertSame([
            'CUM LAUDE' => 2,
            'MAGNA CUM LAUDE' => 0,
            'SUMMA CUM LAUDE' => 1,
        ], $breakdown);
    }

    public function testGetHonorTierBreakdownForYearReturnsZeroedDefaultsWhenNoData(): void
    {
        $breakdown = get_honor_tier_breakdown_for_year(TestDb::pdo(), 1999);

        $this->assertSame([
            'CUM LAUDE' => 0,
            'MAGNA CUM LAUDE' => 0,
            'SUMMA CUM LAUDE' => 0,
        ], $breakdown);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/includes/ReportsTest.php --filter TierBreakdown`
Expected: FAIL — `get_honor_tier_breakdown_for_year` is an undefined function.

- [ ] **Step 3: Write the implementation**

Add to `includes/reports.php`, after `get_application_filing_report()`:

```php
/**
 * Year-scoped sibling of includes/functions.php's get_honor_type_breakdown()
 * — same three-key shape and zero-filled defaults, but counts only one
 * graduation year rather than every graduate on file. Kept separate from
 * the dashboard's version rather than adding a parameter to it, since the
 * dashboard's call site has no year to pass and changing that function's
 * signature would ripple into admin/dashboard.php for no reason.
 */
function get_honor_tier_breakdown_for_year(PDO $pdo, int $year): array
{
    $counts = [
        'CUM LAUDE' => 0,
        'MAGNA CUM LAUDE' => 0,
        'SUMMA CUM LAUDE' => 0,
    ];

    $stmt = $pdo->prepare('SELECT latin_honor, COUNT(*) AS total FROM latin_honor_graduates WHERE graduation_year = :year GROUP BY latin_honor');
    $stmt->execute(['year' => $year]);
    foreach ($stmt->fetchAll() as $row) {
        $counts[$row['latin_honor']] = (int) $row['total'];
    }

    return $counts;
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/includes/ReportsTest.php`
Expected: PASS (all 6 tests so far).

- [ ] **Step 5: Commit**

```bash
git add includes/reports.php tests/includes/ReportsTest.php
git commit -m "feat: add get_honor_tier_breakdown_for_year() for the Reports Group"
```

---

### Task 3: `get_school_ranking_by_honor_count()`

**Files:**
- Modify: `includes/reports.php`
- Test: `tests/includes/ReportsTest.php`

**Interfaces:**
- Produces: `get_school_ranking_by_honor_count(PDO $pdo, int $year): array<int, array{school_name: string, campus: ?string, honor_count: int}>`

- [ ] **Step 1: Write the failing tests**

Add to `tests/includes/ReportsTest.php`:

```php
    public function testGetSchoolRankingByHonorCountOrdersDescending(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec(
            "INSERT INTO latin_honor_graduates (upload_id, school_id, graduate_name, latin_honor, graduation_year)
             VALUES (1, 1, 'JUAN DELA CRUZ', 'CUM LAUDE', 2025),
                    (1, 1, 'MARIA SANTOS', 'CUM LAUDE', 2025),
                    (2, 2, 'PEDRO REYES', 'CUM LAUDE', 2025)"
        );

        $ranking = get_school_ranking_by_honor_count($pdo, 2025);

        $this->assertCount(2, $ranking);
        $this->assertSame('ABC UNIVERSITY', $ranking[0]['school_name']);
        $this->assertSame(2, $ranking[0]['honor_count']);
        $this->assertSame('XYZ COLLEGE', $ranking[1]['school_name']);
        $this->assertSame(1, $ranking[1]['honor_count']);
    }

    public function testGetSchoolRankingByHonorCountTieBreaksBySchoolNameAscending(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec(
            "INSERT INTO latin_honor_graduates (upload_id, school_id, graduate_name, latin_honor, graduation_year)
             VALUES (1, 1, 'JUAN DELA CRUZ', 'CUM LAUDE', 2025),
                    (2, 2, 'PEDRO REYES', 'CUM LAUDE', 2025)"
        );

        $ranking = get_school_ranking_by_honor_count($pdo, 2025);

        $this->assertSame('ABC UNIVERSITY', $ranking[0]['school_name']);
        $this->assertSame('XYZ COLLEGE', $ranking[1]['school_name']);
    }

    public function testGetSchoolRankingByHonorCountExcludesSchoolsWithNoGraduatesThatYear(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec(
            "INSERT INTO latin_honor_graduates (upload_id, school_id, graduate_name, latin_honor, graduation_year)
             VALUES (1, 1, 'JUAN DELA CRUZ', 'CUM LAUDE', 2024)"
        );

        $ranking = get_school_ranking_by_honor_count($pdo, 2025);

        $this->assertSame([], $ranking);
    }

    public function testGetSchoolRankingByHonorCountReturnsEmptyForYearWithNoData(): void
    {
        $this->assertSame([], get_school_ranking_by_honor_count(TestDb::pdo(), 1999));
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/includes/ReportsTest.php --filter Ranking`
Expected: FAIL — `get_school_ranking_by_honor_count` is an undefined function.

- [ ] **Step 3: Write the implementation**

Add to `includes/reports.php`, after `get_honor_tier_breakdown_for_year()`:

```php
/**
 * Schools ranked by Latin Honor graduate count for one graduation year,
 * descending, with an alphabetical tiebreak so two schools tied on count
 * always render in the same order rather than one that varies between
 * page loads. Same zero-graduates-that-year exclusion as
 * get_application_filing_report() (INNER JOIN, not LEFT JOIN).
 */
function get_school_ranking_by_honor_count(PDO $pdo, int $year): array
{
    $stmt = $pdo->prepare(
        'SELECT s.school_name, s.campus, COUNT(g.id) AS honor_count
         FROM schools s
         JOIN latin_honor_graduates g ON g.school_id = s.id AND g.graduation_year = :year
         GROUP BY s.id, s.school_name, s.campus
         ORDER BY honor_count DESC, s.school_name ASC'
    );
    $stmt->execute(['year' => $year]);

    return array_map(static function (array $row): array {
        return [
            'school_name' => $row['school_name'],
            'campus' => $row['campus'],
            'honor_count' => (int) $row['honor_count'],
        ];
    }, $stmt->fetchAll());
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/includes/ReportsTest.php`
Expected: PASS (all 10 tests).

- [ ] **Step 5: Commit**

```bash
git add includes/reports.php tests/includes/ReportsTest.php
git commit -m "feat: add get_school_ranking_by_honor_count() for the Reports Group"
```

---

### Task 4: XLSX builder functions

**Files:**
- Modify: `includes/xlsx_writer.php`
- Test: `tests/includes/XlsxReadWriteTest.php`

**Interfaces:**
- Consumes: `xlsx_build_sheet_xml()`, `xlsx_zip_parts()`, `xlsx_content_types_xml()`, `xlsx_package_rels_xml()`, `xlsx_workbook_xml()`, `xlsx_workbook_rels_xml()`, `xlsx_styles_xml()` (all pre-existing, unchanged, already in this file).
- Produces:
  - `build_application_filing_report_xlsx(array $report, int $year): string` — `$report` is the same shape `get_application_filing_report()` returns, except each school row additionally carries a `school_display` string key (pre-formatted by the caller — see Global Constraints on `includes/*.php` not cross-requiring each other).
  - `build_school_ranking_report_xlsx(array $rows, int $year): string` — `$rows` is the same shape `get_school_ranking_by_honor_count()` returns, except each row additionally carries a `school_display` string key.

- [ ] **Step 1: Write the failing tests**

Add to `tests/includes/XlsxReadWriteTest.php` (inside the existing `XlsxReadWriteTest` class, using its existing `writeTempFile()` helper):

```php
    public function testApplicationFilingReportXlsxRoundTripsThroughReader(): void
    {
        $report = [
            'total_on_record' => 3,
            'total_applied' => 1,
            'filing_rate' => 33.3,
            'schools' => [
                ['school_display' => 'ABC UNIVERSITY-MAIN', 'on_record' => 2, 'applied' => 1, 'filing_rate' => 50.0],
                ['school_display' => 'XYZ COLLEGE', 'on_record' => 1, 'applied' => 0, 'filing_rate' => 0.0],
            ],
        ];

        $bytes = build_application_filing_report_xlsx($report, 2025);
        $path = $this->writeTempFile($bytes);

        $result = xlsx_read_first_sheet($path);

        $this->assertTrue($result['ok']);
        $rows = $result['rows'];
        $this->assertSame('3', $rows[3][2]);
        $this->assertSame('1', $rows[4][2]);
        $this->assertSame('ABC UNIVERSITY-MAIN', $rows[8][1]);
        $this->assertSame('2', $rows[8][2]);
        $this->assertSame('XYZ COLLEGE', $rows[9][1]);
        $this->assertSame('0', $rows[9][2]);
    }

    public function testSchoolRankingReportXlsxRoundTripsThroughReader(): void
    {
        $rows = [
            ['school_display' => 'ABC UNIVERSITY-MAIN', 'honor_count' => 2],
            ['school_display' => 'XYZ COLLEGE', 'honor_count' => 1],
        ];

        $bytes = build_school_ranking_report_xlsx($rows, 2025);
        $path = $this->writeTempFile($bytes);

        $result = xlsx_read_first_sheet($path);

        $this->assertTrue($result['ok']);
        $resultRows = $result['rows'];
        $this->assertSame('1', $resultRows[3][1]);
        $this->assertSame('ABC UNIVERSITY-MAIN', $resultRows[3][2]);
        $this->assertSame('2', $resultRows[3][3]);
        $this->assertSame('2', $resultRows[4][1]);
        $this->assertSame('XYZ COLLEGE', $resultRows[4][2]);
        $this->assertSame('1', $resultRows[4][3]);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/includes/XlsxReadWriteTest.php --filter "FilingReport|RankingReport"`
Expected: FAIL — `build_application_filing_report_xlsx` and `build_school_ranking_report_xlsx` are undefined functions.

- [ ] **Step 3: Write the implementation**

Add to `includes/xlsx_writer.php`, after `build_latin_honor_template_xlsx()`:

```php
/**
 * @param array{total_on_record: int, total_applied: int, filing_rate: float,
 *     schools: array<int, array{school_display: string, on_record: int, applied: int, filing_rate: float}>} $report
 *     Same shape get_application_filing_report() (includes/reports.php) returns,
 *     with each school row additionally carrying a pre-formatted
 *     'school_display' string — this file does not call
 *     format_school_display_name() (includes/functions.php) itself, since
 *     includes/*.php files in this project don't require each other; the
 *     caller (the export endpoint) does that formatting first.
 */
function build_application_filing_report_xlsx(array $report, int $year): string
{
    $rows = [
        1 => [
            1 => ['value' => 'APPLICATION FILING REPORT — ' . $year, 'style' => 1],
        ],
        3 => [
            1 => ['value' => 'TOTAL ON RECORD', 'style' => 1],
            2 => ['value' => (string) $report['total_on_record'], 'type' => 'n'],
        ],
        4 => [
            1 => ['value' => 'TOTAL APPLIED', 'style' => 1],
            2 => ['value' => (string) $report['total_applied'], 'type' => 'n'],
        ],
        5 => [
            1 => ['value' => 'FILING RATE (%)', 'style' => 1],
            2 => ['value' => (string) $report['filing_rate'], 'type' => 'n'],
        ],
        7 => [
            1 => ['value' => 'SCHOOL', 'style' => 1],
            2 => ['value' => 'ON RECORD', 'style' => 1],
            3 => ['value' => 'APPLIED', 'style' => 1],
            4 => ['value' => 'FILING RATE (%)', 'style' => 1],
        ],
    ];

    $rowNum = 8;
    foreach ($report['schools'] as $school) {
        $rows[$rowNum] = [
            1 => ['value' => $school['school_display']],
            2 => ['value' => (string) $school['on_record'], 'type' => 'n'],
            3 => ['value' => (string) $school['applied'], 'type' => 'n'],
            4 => ['value' => (string) $school['filing_rate'], 'type' => 'n'],
        ];
        $rowNum++;
    }

    $sheetXml = xlsx_build_sheet_xml($rows, [1 => 40, 2 => 14, 3 => 12, 4 => 16]);

    return xlsx_zip_parts([
        '[Content_Types].xml' => xlsx_content_types_xml(),
        '_rels/.rels' => xlsx_package_rels_xml(),
        'xl/workbook.xml' => xlsx_workbook_xml('APPLICATION FILING REPORT'),
        'xl/_rels/workbook.xml.rels' => xlsx_workbook_rels_xml(),
        'xl/styles.xml' => xlsx_styles_xml(),
        'xl/worksheets/sheet1.xml' => $sheetXml,
    ]);
}

/**
 * @param array<int, array{school_display: string, honor_count: int}> $rows
 *     Same shape get_school_ranking_by_honor_count() (includes/reports.php)
 *     returns, with each row additionally carrying a pre-formatted
 *     'school_display' string (see build_application_filing_report_xlsx()'s
 *     docblock for why this file doesn't format it itself).
 */
function build_school_ranking_report_xlsx(array $rows, int $year): string
{
    $sheetRows = [
        1 => [
            1 => ['value' => 'SCHOOL RANKING REPORT — ' . $year, 'style' => 1],
        ],
        3 => [
            1 => ['value' => 'RANK', 'style' => 1],
            2 => ['value' => 'SCHOOL', 'style' => 1],
            3 => ['value' => 'LATIN HONOR GRADUATES', 'style' => 1],
        ],
    ];

    $rowNum = 4;
    $rank = 1;
    foreach ($rows as $row) {
        $sheetRows[$rowNum] = [
            1 => ['value' => (string) $rank, 'type' => 'n'],
            2 => ['value' => $row['school_display']],
            3 => ['value' => (string) $row['honor_count'], 'type' => 'n'],
        ];
        $rowNum++;
        $rank++;
    }

    $sheetXml = xlsx_build_sheet_xml($sheetRows, [1 => 10, 2 => 40, 3 => 22]);

    return xlsx_zip_parts([
        '[Content_Types].xml' => xlsx_content_types_xml(),
        '_rels/.rels' => xlsx_package_rels_xml(),
        'xl/workbook.xml' => xlsx_workbook_xml('SCHOOL RANKING REPORT'),
        'xl/_rels/workbook.xml.rels' => xlsx_workbook_rels_xml(),
        'xl/styles.xml' => xlsx_styles_xml(),
        'xl/worksheets/sheet1.xml' => $sheetXml,
    ]);
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/includes/XlsxReadWriteTest.php`
Expected: PASS (all tests in the file, including the two new ones).

- [ ] **Step 5: Commit**

```bash
git add includes/xlsx_writer.php tests/includes/XlsxReadWriteTest.php
git commit -m "feat: add XLSX builders for the Application Filing and School Ranking reports"
```

---

### Task 5: JSON AJAX endpoints for year-switch reloads

**Files:**
- Create: `api/get_application_filing_report.php`
- Create: `api/get_school_ranking_report.php`

**Interfaces:**
- Consumes: `get_application_filing_report()`, `get_honor_tier_breakdown_for_year()`, `get_school_ranking_by_honor_count()` (Tasks 1–3), `get_distinct_graduation_years()` (existing, `includes/functions.php`), `format_school_display_name()` (existing, `includes/functions.php`).
- Produces: JSON responses consumed by `assets/js/reports.js` in Task 7.

No automated tests for this task — matches this project's established convention that thin API orchestration endpoints have no direct test coverage (e.g. `api/get_dashboard_year_records.php`, which this task's endpoints are directly modeled on, has none either; the logic they call is already tested in Tasks 1–3). Verified live in Task 8.

- [ ] **Step 1: Create the Application Filing Report JSON endpoint**

Create `api/get_application_filing_report.php`:

```php
<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/reports.php';

require_login();

header('Content-Type: application/json');

try {
    $pdo = get_pdo();

    // An invalid or missing year (a malformed/tampered query string — the
    // page's own <select> always sends a real value) falls back to the
    // most recent year with data rather than hard-erroring, per the design
    // spec: a report page should always render something.
    $year = (int) ($_GET['year'] ?? 0);
    if ($year <= 0) {
        $years = get_distinct_graduation_years($pdo);
        $year = $years[0] ?? (int) date('Y');
    }

    $report = get_application_filing_report($pdo, $year);
    $tierBreakdown = get_honor_tier_breakdown_for_year($pdo, $year);

    $report['schools'] = array_map(static function (array $school): array {
        $school['school_display'] = format_school_display_name($school['school_name'], $school['campus']);
        return $school;
    }, $report['schools']);

    echo json_encode([
        'status' => 'success',
        'year' => $year,
        'report' => $report,
        'tier_breakdown' => $tierBreakdown,
    ]);
} catch (\Throwable $e) {
    error_log('get_application_filing_report (api) failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'THE REPORT COULD NOT BE LOADED. PLEASE TRY AGAIN.']);
}
```

- [ ] **Step 2: Create the School Ranking Report JSON endpoint**

Create `api/get_school_ranking_report.php`:

```php
<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/reports.php';

require_login();

header('Content-Type: application/json');

try {
    $pdo = get_pdo();

    // Same invalid/missing-year fallback as get_application_filing_report.php
    // — defaults to the most recent year with data rather than hard-erroring.
    $year = (int) ($_GET['year'] ?? 0);
    if ($year <= 0) {
        $years = get_distinct_graduation_years($pdo);
        $year = $years[0] ?? (int) date('Y');
    }

    $rows = get_school_ranking_by_honor_count($pdo, $year);

    $rows = array_map(static function (array $row): array {
        $row['school_display'] = format_school_display_name($row['school_name'], $row['campus']);
        return $row;
    }, $rows);

    echo json_encode(['status' => 'success', 'year' => $year, 'rows' => $rows]);
} catch (\Throwable $e) {
    error_log('get_school_ranking_report (api) failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'THE REPORT COULD NOT BE LOADED. PLEASE TRY AGAIN.']);
}
```

- [ ] **Step 3: Check both files for syntax errors**

Run: `php -l api/get_application_filing_report.php`
Expected: `No syntax errors detected`

Run: `php -l api/get_school_ranking_report.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add api/get_application_filing_report.php api/get_school_ranking_report.php
git commit -m "feat: add JSON endpoints for Reports Group year-switch reloads"
```

---

### Task 6: XLSX export endpoints

**Files:**
- Create: `api/export_application_filing_report.php`
- Create: `api/export_school_ranking_report.php`

**Interfaces:**
- Consumes: `get_application_filing_report()`, `get_school_ranking_by_honor_count()` (Tasks 1, 3), `get_distinct_graduation_years()` (existing, `includes/functions.php`), `format_school_display_name()` (existing), `build_application_filing_report_xlsx()`, `build_school_ranking_report_xlsx()` (Task 4).

No automated tests — matches `api/download_template.php`'s own lack of direct test coverage (the logic it calls, `build_latin_honor_template_xlsx()`, is tested; the endpoint itself isn't). Verified live in Task 8.

- [ ] **Step 1: Create the Application Filing Report export endpoint**

Create `api/export_application_filing_report.php`:

```php
<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/reports.php';
require_once __DIR__ . '/../includes/xlsx_writer.php';

require_login();

try {
    $pdo = get_pdo();

    // Same invalid/missing-year fallback as api/get_application_filing_report.php
    // — defaults to the most recent year with data rather than hard-erroring.
    $year = (int) ($_GET['year'] ?? 0);
    if ($year <= 0) {
        $years = get_distinct_graduation_years($pdo);
        $year = $years[0] ?? (int) date('Y');
    }

    $report = get_application_filing_report($pdo, $year);
    $report['schools'] = array_map(static function (array $school): array {
        $school['school_display'] = format_school_display_name($school['school_name'], $school['campus']);
        return $school;
    }, $report['schools']);

    $bytes = build_application_filing_report_xlsx($report, $year);
    $filename = 'APPLICATION_FILING_REPORT_' . $year . '.xlsx';

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
} catch (\Throwable $e) {
    error_log('Application filing report export failed: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain');
    die('THE REPORT COULD NOT BE GENERATED. PLEASE TRY AGAIN.');
}
```

- [ ] **Step 2: Create the School Ranking Report export endpoint**

Create `api/export_school_ranking_report.php`:

```php
<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/reports.php';
require_once __DIR__ . '/../includes/xlsx_writer.php';

require_login();

try {
    $pdo = get_pdo();

    // Same invalid/missing-year fallback as api/get_school_ranking_report.php
    // — defaults to the most recent year with data rather than hard-erroring.
    $year = (int) ($_GET['year'] ?? 0);
    if ($year <= 0) {
        $years = get_distinct_graduation_years($pdo);
        $year = $years[0] ?? (int) date('Y');
    }

    $rows = get_school_ranking_by_honor_count($pdo, $year);
    $rows = array_map(static function (array $row): array {
        $row['school_display'] = format_school_display_name($row['school_name'], $row['campus']);
        return $row;
    }, $rows);

    $bytes = build_school_ranking_report_xlsx($rows, $year);
    $filename = 'SCHOOL_RANKING_REPORT_' . $year . '.xlsx';

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
} catch (\Throwable $e) {
    error_log('School ranking report export failed: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain');
    die('THE REPORT COULD NOT BE GENERATED. PLEASE TRY AGAIN.');
}
```

- [ ] **Step 3: Check both files for syntax errors**

Run: `php -l api/export_application_filing_report.php`
Expected: `No syntax errors detected`

Run: `php -l api/export_school_ranking_report.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add api/export_application_filing_report.php api/export_school_ranking_report.php
git commit -m "feat: add XLSX export endpoints for the Reports Group"
```

---

### Task 7: `admin/reports.php` page, sidebar nav entry, and `assets/js/reports.js`

**Files:**
- Create: `admin/reports.php`
- Create: `assets/js/reports.js`
- Modify: `includes/sidebar.php`

**Interfaces:**
- Consumes: `get_application_filing_report()`, `get_honor_tier_breakdown_for_year()`, `get_school_ranking_by_honor_count()` (Tasks 1–3), `get_distinct_graduation_years()` (existing, `includes/functions.php`), `format_school_display_name()` (existing), the two JSON endpoints from Task 5, the two export endpoints from Task 6.

No automated tests — this is markup/JS wiring with no existing test coverage convention anywhere else in this project (matches `admin/dashboard.php`, `admin/view_upload.php`, etc.). Verified live in Task 8.

- [ ] **Step 1: Add the REPORTS sidebar entry**

In `includes/sidebar.php`, insert a new nav entry right after the "HONOR GRAD LOOK-UP" link's closing `</a>` and right before the `<?php if (user_has_role($currentUser ?? null, 'administrator')): ?>` line (so it renders unconditionally for every logged-in user, regardless of role — the existing `if` block below it gates only the admin-only items that follow). The file currently reads:

```php
        <a href="<?= e(APP_BASE_URL) ?>/admin/lookup.php" class="flex items-center gap-3 rounded-lg px-3 py-2 <?= sidebar_nav_class($currentPage, 'lookup') ?>">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607Z" /></svg>
            <span>HONOR GRAD LOOK-UP</span>
        </a>
        <?php if (user_has_role($currentUser ?? null, 'administrator')): ?>
```

Change it to:

```php
        <a href="<?= e(APP_BASE_URL) ?>/admin/lookup.php" class="flex items-center gap-3 rounded-lg px-3 py-2 <?= sidebar_nav_class($currentPage, 'lookup') ?>">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607Z" /></svg>
            <span>HONOR GRAD LOOK-UP</span>
        </a>

        <p class="px-3 pt-4 pb-1 text-[11px] font-semibold tracking-wide text-white/40 dark:text-gray-600">REPORTS</p>
        <a href="<?= e(APP_BASE_URL) ?>/admin/reports.php" class="flex items-center gap-3 rounded-lg px-3 py-2 <?= sidebar_nav_class($currentPage, 'reports') ?>">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" /></svg>
            <span>REPORTS</span>
        </a>

        <?php if (user_has_role($currentUser ?? null, 'administrator')): ?>
```

- [ ] **Step 2: Create `admin/reports.php`**

Create `admin/reports.php`:

```php
<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/reports.php';

require_login();

$pdo = get_pdo();
$currentUser = current_user();
$currentPage = 'reports';
$csrfToken = csrf_token();

$graduationYears = get_distinct_graduation_years($pdo);
$defaultYear = $graduationYears[0] ?? (int) date('Y');

$filingReport = get_application_filing_report($pdo, $defaultYear);
$tierBreakdown = get_honor_tier_breakdown_for_year($pdo, $defaultYear);
$ranking = get_school_ranking_by_honor_count($pdo, $defaultYear);

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main class="lg:ml-64 min-h-screen bg-gray-50 dark:bg-surface-950">
    <div class="p-6 lg:p-8">
        <div class="max-w-6xl mx-auto">
            <div class="mb-6 flex items-start gap-4">
                <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-csc-navy-600/10 text-csc-navy-600 dark:text-csc-navy-300">
                    <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" /></svg>
                </div>
                <div>
                    <h1 class="text-2xl font-bold text-csc-navy-600 dark:text-white">REPORTS</h1>
                    <p class="text-sm text-gray-600 dark:text-gray-500 mt-1">YEAR-SCOPED SUMMARIES OF LATIN HONOR GRADUATES AND HONOR GRADUATE ELIGIBILITY FILINGS.</p>
                </div>
            </div>

            <!-- APPLICATION FILING REPORT -->
            <div class="bg-white dark:bg-surface-900 border border-gray-200 dark:border-white/10 rounded-xl p-6 mb-6 hge-shadow">
                <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                    <h2 class="text-lg font-bold text-csc-navy-600 dark:text-white">APPLICATION FILING REPORT</h2>
                    <div class="flex items-center gap-2">
                        <select id="filing-report-year" class="rounded-xl border border-gray-300 dark:border-white/15 bg-white dark:bg-surface-800 text-sm px-3 py-2">
                            <?php foreach ($graduationYears as $y): ?>
                                <option value="<?= (int) $y ?>" <?= $y === $defaultYear ? 'selected' : '' ?>><?= (int) $y ?></option>
                            <?php endforeach; ?>
                        </select>
                        <a id="filing-report-export" href="<?= e(APP_BASE_URL) ?>/api/export_application_filing_report.php?year=<?= (int) $defaultYear ?>" class="inline-flex items-center gap-1.5 rounded-xl border border-gray-300 dark:border-white/15 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5 text-sm font-medium px-4 py-2.5 transition-colors">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                            DOWNLOAD XLSX
                        </a>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-4">
                    <div class="rounded-xl bg-gray-50 dark:bg-white/5 p-4">
                        <p class="text-xs text-gray-500 dark:text-gray-400">ON RECORD</p>
                        <p id="filing-total-on-record" class="text-2xl font-bold text-csc-navy-600 dark:text-white"><?= (int) $filingReport['total_on_record'] ?></p>
                    </div>
                    <div class="rounded-xl bg-gray-50 dark:bg-white/5 p-4">
                        <p class="text-xs text-gray-500 dark:text-gray-400">APPLIED</p>
                        <p id="filing-total-applied" class="text-2xl font-bold text-csc-navy-600 dark:text-white"><?= (int) $filingReport['total_applied'] ?></p>
                    </div>
                    <div class="rounded-xl bg-gray-50 dark:bg-white/5 p-4">
                        <p class="text-xs text-gray-500 dark:text-gray-400">FILING RATE</p>
                        <p id="filing-rate" class="text-2xl font-bold text-csc-navy-600 dark:text-white"><?= e((string) $filingReport['filing_rate']) ?>%</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-5">
                    <div class="rounded-xl border border-gray-100 dark:border-white/5 p-3 text-center">
                        <p class="text-xs text-gray-500 dark:text-gray-400">CUM LAUDE</p>
                        <p id="tier-cum-laude" class="text-lg font-semibold text-gray-900 dark:text-white"><?= (int) $tierBreakdown['CUM LAUDE'] ?></p>
                    </div>
                    <div class="rounded-xl border border-gray-100 dark:border-white/5 p-3 text-center">
                        <p class="text-xs text-gray-500 dark:text-gray-400">MAGNA CUM LAUDE</p>
                        <p id="tier-magna-cum-laude" class="text-lg font-semibold text-gray-900 dark:text-white"><?= (int) $tierBreakdown['MAGNA CUM LAUDE'] ?></p>
                    </div>
                    <div class="rounded-xl border border-gray-100 dark:border-white/5 p-3 text-center">
                        <p class="text-xs text-gray-500 dark:text-gray-400">SUMMA CUM LAUDE</p>
                        <p id="tier-summa-cum-laude" class="text-lg font-semibold text-gray-900 dark:text-white"><?= (int) $tierBreakdown['SUMMA CUM LAUDE'] ?></p>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="text-xs text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-white/10">
                            <tr>
                                <th class="px-4 py-3">SCHOOL</th>
                                <th class="px-4 py-3">ON RECORD</th>
                                <th class="px-4 py-3">APPLIED</th>
                                <th class="px-4 py-3">FILING RATE</th>
                            </tr>
                        </thead>
                        <tbody id="filing-report-tbody">
                            <?php if (empty($filingReport['schools'])): ?>
                                <tr><td colspan="4" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400">NO HONOR GRADUATE RECORDS FOUND FOR THIS YEAR.</td></tr>
                            <?php else: ?>
                                <?php foreach ($filingReport['schools'] as $school): ?>
                                    <tr class="border-b border-gray-100 dark:border-white/5">
                                        <td class="px-4 py-3"><?= e_upper(format_school_display_name($school['school_name'], $school['campus'])) ?></td>
                                        <td class="px-4 py-3"><?= (int) $school['on_record'] ?></td>
                                        <td class="px-4 py-3"><?= (int) $school['applied'] ?></td>
                                        <td class="px-4 py-3"><?= e((string) $school['filing_rate']) ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- SCHOOL RANKING REPORT -->
            <div class="bg-white dark:bg-surface-900 border border-gray-200 dark:border-white/10 rounded-xl p-6 hge-shadow">
                <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                    <h2 class="text-lg font-bold text-csc-navy-600 dark:text-white">SCHOOL RANKING REPORT</h2>
                    <div class="flex items-center gap-2">
                        <select id="ranking-report-year" class="rounded-xl border border-gray-300 dark:border-white/15 bg-white dark:bg-surface-800 text-sm px-3 py-2">
                            <?php foreach ($graduationYears as $y): ?>
                                <option value="<?= (int) $y ?>" <?= $y === $defaultYear ? 'selected' : '' ?>><?= (int) $y ?></option>
                            <?php endforeach; ?>
                        </select>
                        <a id="ranking-report-export" href="<?= e(APP_BASE_URL) ?>/api/export_school_ranking_report.php?year=<?= (int) $defaultYear ?>" class="inline-flex items-center gap-1.5 rounded-xl border border-gray-300 dark:border-white/15 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5 text-sm font-medium px-4 py-2.5 transition-colors">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                            DOWNLOAD XLSX
                        </a>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="text-xs text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-white/10">
                            <tr>
                                <th class="px-4 py-3">RANK</th>
                                <th class="px-4 py-3">SCHOOL</th>
                                <th class="px-4 py-3">LATIN HONOR GRADUATES</th>
                            </tr>
                        </thead>
                        <tbody id="ranking-report-tbody">
                            <?php if (empty($ranking)): ?>
                                <tr><td colspan="3" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400">NO HONOR GRADUATE RECORDS FOUND FOR THIS YEAR.</td></tr>
                            <?php else: ?>
                                <?php foreach ($ranking as $index => $school): ?>
                                    <tr class="border-b border-gray-100 dark:border-white/5">
                                        <td class="px-4 py-3"><?= (int) ($index + 1) ?></td>
                                        <td class="px-4 py-3"><?= e_upper(format_school_display_name($school['school_name'], $school['campus'])) ?></td>
                                        <td class="px-4 py-3"><?= (int) $school['honor_count'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
<script>
window.HGE_REPORTS = {
    apiBase: <?= json_encode(APP_BASE_URL) ?>,
    csrfToken: <?= json_encode($csrfToken) ?>
};
</script>
<script src="<?= e(asset_url('assets/js/reports.js')) ?>"></script>
```

- [ ] **Step 3: Create `assets/js/reports.js`**

Create `assets/js/reports.js`:

```js
document.addEventListener('DOMContentLoaded', function () {
    var config = window.HGE_REPORTS;
    if (!config) {
        return;
    }

    function escapeHtmlLocal(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    var filingYearSelect = document.getElementById('filing-report-year');
    var filingExportLink = document.getElementById('filing-report-export');
    var filingTbody = document.getElementById('filing-report-tbody');
    var filingTotalOnRecord = document.getElementById('filing-total-on-record');
    var filingTotalApplied = document.getElementById('filing-total-applied');
    var filingRate = document.getElementById('filing-rate');
    var tierCumLaude = document.getElementById('tier-cum-laude');
    var tierMagnaCumLaude = document.getElementById('tier-magna-cum-laude');
    var tierSummaCumLaude = document.getElementById('tier-summa-cum-laude');

    function renderFilingReport(data) {
        filingTotalOnRecord.textContent = data.report.total_on_record;
        filingTotalApplied.textContent = data.report.total_applied;
        filingRate.textContent = data.report.filing_rate + '%';
        tierCumLaude.textContent = data.tier_breakdown['CUM LAUDE'];
        tierMagnaCumLaude.textContent = data.tier_breakdown['MAGNA CUM LAUDE'];
        tierSummaCumLaude.textContent = data.tier_breakdown['SUMMA CUM LAUDE'];

        if (data.report.schools.length === 0) {
            filingTbody.innerHTML = '<tr><td colspan="4" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400">NO HONOR GRADUATE RECORDS FOUND FOR THIS YEAR.</td></tr>';
            return;
        }

        filingTbody.innerHTML = data.report.schools.map(function (school) {
            return '<tr class="border-b border-gray-100 dark:border-white/5">' +
                '<td class="px-4 py-3">' + escapeHtmlLocal(String(school.school_display).toUpperCase()) + '</td>' +
                '<td class="px-4 py-3">' + school.on_record + '</td>' +
                '<td class="px-4 py-3">' + school.applied + '</td>' +
                '<td class="px-4 py-3">' + school.filing_rate + '%</td>' +
                '</tr>';
        }).join('');
    }

    if (filingYearSelect) {
        filingYearSelect.addEventListener('change', function () {
            var year = filingYearSelect.value;
            filingExportLink.href = config.apiBase + '/api/export_application_filing_report.php?year=' + encodeURIComponent(year);

            fetch(config.apiBase + '/api/get_application_filing_report.php?year=' + encodeURIComponent(year))
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (data.status === 'success') {
                        renderFilingReport(data);
                    } else {
                        showToast('error', data.message || 'THE REPORT COULD NOT BE LOADED.');
                    }
                })
                .catch(function () {
                    showToast('error', 'THE REPORT COULD NOT BE LOADED. PLEASE TRY AGAIN.');
                });
        });
    }

    var rankingYearSelect = document.getElementById('ranking-report-year');
    var rankingExportLink = document.getElementById('ranking-report-export');
    var rankingTbody = document.getElementById('ranking-report-tbody');

    function renderRankingReport(data) {
        if (data.rows.length === 0) {
            rankingTbody.innerHTML = '<tr><td colspan="3" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400">NO HONOR GRADUATE RECORDS FOUND FOR THIS YEAR.</td></tr>';
            return;
        }

        rankingTbody.innerHTML = data.rows.map(function (row, index) {
            return '<tr class="border-b border-gray-100 dark:border-white/5">' +
                '<td class="px-4 py-3">' + (index + 1) + '</td>' +
                '<td class="px-4 py-3">' + escapeHtmlLocal(String(row.school_display).toUpperCase()) + '</td>' +
                '<td class="px-4 py-3">' + row.honor_count + '</td>' +
                '</tr>';
        }).join('');
    }

    if (rankingYearSelect) {
        rankingYearSelect.addEventListener('change', function () {
            var year = rankingYearSelect.value;
            rankingExportLink.href = config.apiBase + '/api/export_school_ranking_report.php?year=' + encodeURIComponent(year);

            fetch(config.apiBase + '/api/get_school_ranking_report.php?year=' + encodeURIComponent(year))
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (data.status === 'success') {
                        renderRankingReport(data);
                    } else {
                        showToast('error', data.message || 'THE REPORT COULD NOT BE LOADED.');
                    }
                })
                .catch(function () {
                    showToast('error', 'THE REPORT COULD NOT BE LOADED. PLEASE TRY AGAIN.');
                });
        });
    }
});
```

(`showToast` is a global helper already defined in `assets/js/utils.js`, already loaded on every admin page via `includes/footer.php` — no new require needed.)

- [ ] **Step 4: Check all three files for syntax errors**

Run: `php -l admin/reports.php`
Expected: `No syntax errors detected`

Run: `php -l includes/sidebar.php`
Expected: `No syntax errors detected`

Run: `node --check assets/js/reports.js`
Expected: exit code 0, no output

- [ ] **Step 5: Rebuild CSS**

This page reuses only existing Tailwind utility classes already compiled from other pages (no new arbitrary-value classes introduced). Run `npm run build:css` anyway to be certain, and check `git diff --stat assets/css/app.css` afterward — if it reports no changes, that confirms no rebuild was actually needed; if it does show changes, the rebuild was necessary and the result should be committed alongside the other files in this task.

- [ ] **Step 6: Commit**

```bash
git add admin/reports.php assets/js/reports.js includes/sidebar.php assets/css/app.css
git commit -m "feat: add Reports Group page, nav entry, and year-switch JS"
```

(Include `assets/css/app.css` in the commit only if Step 5 actually changed it.)

---

### Task 8: Full test suite and live verification

**Files:** none (verification only)

- [ ] **Step 1: Back up the Gemini quota cache file**

```bash
cp storage/cache/gemini_quota_status.json storage/cache/gemini_quota_status.json.bak 2>/dev/null || true
```

- [ ] **Step 2: Run the full PHPUnit suite**

Run: `vendor/bin/phpunit`
Expected: all tests pass except the pre-existing, unrelated flake
`testIsQuotaExhaustedIsFalseWhenNoStatusFileExists` (known from this
project's history — report it as pre-existing if it appears, not a
regression from this work).

- [ ] **Step 3: Restore the Gemini quota cache file**

```bash
cp storage/cache/gemini_quota_status.json.bak storage/cache/gemini_quota_status.json 2>/dev/null && rm storage/cache/gemini_quota_status.json.bak || true
```

- [ ] **Step 4: Live-verify as an administrator**

Using a real admin session, open `admin/reports.php`. Confirm: the REPORTS sidebar entry appears and is highlighted as active; both sections render with the most recent graduation year selected by default; the Application Filing Report's three summary numbers, three tier-breakdown numbers, and per-school table are internally consistent with each other (the summary's ON RECORD total equals the sum of the table's ON RECORD column); the School Ranking Report's table is sorted descending by count. Change each section's year dropdown independently and confirm only that section's data updates (the other section's data and year selection stay untouched). Click both "DOWNLOAD XLSX" buttons and confirm a file actually downloads for the currently-selected year in each section (not always the page's original default year — reselect a different year first, then download, to confirm the export link's `href` actually updated).

- [ ] **Step 5: Live-verify as a non-administrator `user`-role account**

Using a real session with `role = 'user'` (not `administrator`) — create a temporary test user if none exists, following the existing pattern in `includes/users.php`/`admin/users.php`, and remove it afterward — confirm `admin/reports.php` and all four new `api/*.php` endpoints (the two JSON endpoints and the two export endpoints) all succeed exactly as they do for an administrator, with no redirect to login and no 403. This is the one access-control assumption in this whole plan that differs from this project's usual admin-only pattern, so it must actually be exercised with a real non-admin session, not inferred from reading the code.

- [ ] **Step 6: Live-verify a year with no data**

Pick (or temporarily construct) a graduation year with zero Latin Honor
graduates on file. Select it in both section's year dropdowns and confirm
both render their "NO HONOR GRADUATE RECORDS FOUND FOR THIS YEAR." empty
state, with the Application Filing Report's summary numbers and tier
breakdown all reading `0` — not a PHP warning, not a blank page, not a
division-by-zero error.

- [ ] **Step 7: Clean up any scratch test data**

Remove the temporary non-administrator test user from Step 5, and any
scratch data created for Step 6, so the live database isn't left with
leftover test artifacts.
