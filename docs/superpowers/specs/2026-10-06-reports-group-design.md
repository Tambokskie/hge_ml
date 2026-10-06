# Reports Group — Design

## Problem

There is currently no single place to see cross-school, year-scoped
aggregate figures for Latin Honor graduates: how many are on record versus
how many have actually filed for Honor Graduate Eligibility, which schools
produce the most Latin Honor graduates in a given year, and the tier mix
(CUM LAUDE / MAGNA CUM LAUDE / SUMMA CUM LAUDE) for a given year. Today this
data only exists implicitly, spread across `admin/lookup.php` (per-graduate
search) and `admin/school_records.php` (per-upload status) — neither gives
a year-scoped, cross-school summary.

## Goals

- One `admin/reports.php` page with two report sections, each independently
  filterable by a single graduation year.
- **Application Filing Report**: for a selected year, system-wide ON
  RECORD / APPLIED / FILING RATE % totals, a Latin Honor tier breakdown for
  that same year, and a per-school breakdown table (SCHOOL / ON RECORD /
  APPLIED / FILING RATE %).
- **School Ranking Report**: for a selected year (its own, independent year
  filter), schools ranked by Latin Honor graduate count, descending.
- Each report section downloads as its own `.xlsx` file.
- Viewable by both `administrator` and `user` roles — gated by
  `require_login()` only, matching the access level already approved
  (not `require_role('administrator')`, and not removing the login
  requirement entirely — every other page under `admin/` requires at least
  a login, and this one follows that same baseline).

## Non-goals

- No year-range/multi-year filtering — each section takes exactly one
  graduation year at a time, per the approved design.
- No PDF export or print-specific styling — `.xlsx` only, per the approved
  design.
- No combined single-workbook export — each report section gets its own
  independent "DOWNLOAD XLSX" button and file, per the approved design.
- No changes to the database schema — every figure here is derivable from
  `latin_honor_graduates`, `graduate_applications`, and `schools` as they
  exist today.

## Data model and queries

No schema changes. Three new read-only aggregation functions in a new
`includes/reports.php` (new file, following this project's one-file-per-
concern convention — mirrors how `includes/schools.php`, `includes/
graduates.php`, etc. are organized):

### `get_application_filing_report(PDO $pdo, int $year): array`

Returns `['total_on_record' => int, 'total_applied' => int, 'filing_rate'
=> float, 'schools' => array<...>]`. The `schools` array is one row per
school that has at least one Latin Honor graduate in `$year` — a school
with zero graduates that year is excluded entirely (an `INNER JOIN` from
`schools` to `latin_honor_graduates`, not a `LEFT JOIN`), since an all-zero
row adds no information and the report already covers "which schools
produced graduates this year" implicitly by omission. Each school row is
`['school_name', 'campus', 'on_record' => int, 'applied' => int,
'filing_rate' => float]`, sorted alphabetically by
`format_school_display_name()`'s result (not by count — that ordering
belongs to the ranking report, not this one). `filing_rate` is computed in
PHP as `round($applied / $onRecord * 100, 1)` (never in SQL, to avoid
engine-specific rounding/float behavior), and is always safe to compute
since `on_record` is guaranteed `> 0` for every included row.

Query shape (one query for the per-school rows, system totals computed by
summing them in PHP rather than a second query):

```sql
SELECT s.id, s.school_name, s.campus,
       COUNT(g.id) AS on_record,
       COUNT(ga.id) AS applied
FROM schools s
JOIN latin_honor_graduates g ON g.school_id = s.id AND g.graduation_year = :year
LEFT JOIN graduate_applications ga ON ga.graduate_id = g.id
GROUP BY s.id, s.school_name, s.campus
ORDER BY s.school_name
```

### `get_honor_tier_breakdown_for_year(PDO $pdo, int $year): array`

Returns the same shape as the existing `get_honor_type_breakdown(PDO
$pdo): array` in `includes/functions.php` (`['CUM LAUDE' => int, 'MAGNA CUM
LAUDE' => int, 'SUMMA CUM LAUDE' => int]`), but scoped to one graduation
year — system-wide, not per-school. This is a close sibling of the existing
function (same three-key shape, same zero-filled defaults for a tier with
no graduates that year), just with a `WHERE graduation_year = :year` added
— it lives in the new `includes/reports.php` rather than modifying the
existing dashboard function, since the dashboard's version intentionally
has no year parameter today and changing its signature would ripple into
`admin/dashboard.php`.

### `get_school_ranking_by_honor_count(PDO $pdo, int $year): array`

Returns one row per school with at least one Latin Honor graduate in
`$year` (same `INNER JOIN`-excludes-zero-schools reasoning as above):
`['school_name', 'campus', 'honor_count' => int]`, ordered by
`honor_count DESC`, then `school_name ASC` as a tiebreaker (so a tie
doesn't produce visually random ordering between page loads).

```sql
SELECT s.id, s.school_name, s.campus, COUNT(g.id) AS honor_count
FROM schools s
JOIN latin_honor_graduates g ON g.school_id = s.id AND g.graduation_year = :year
GROUP BY s.id, s.school_name, s.campus
ORDER BY honor_count DESC, s.school_name ASC
```

All three functions use `format_school_display_name($row['school_name'],
$row['campus'])` (existing helper in `includes/functions.php`) wherever a
school name is rendered or exported, matching every other page in the app.

## Page: `admin/reports.php`

`require_login()` only (no `require_role()`). New sidebar entry "REPORTS"
in `includes/sidebar.php`, placed between the existing "GRADUATE RECORDS"
and "ADMINISTRATION" groups, using the same `sidebar_nav_class($currentPage,
'reports')` pattern every other nav entry already uses.

Each section's year filter is a plain `<select>` populated from the
existing `get_distinct_graduation_years(PDO $pdo): array` (already used
elsewhere, e.g. the dashboard's year dropdown), defaulting to the most
recent year with data. Switching a section's year reloads that section's
data — given the existing codebase's established AJAX pattern (e.g.
`admin/school_records.php`'s filter reloads, the dashboard's year-dropdown
from this session's earlier work), this should be a `fetch()`-based partial
reload scoped to just that section, not a full page reload — each section
gets its own small `api/get_*_report.php` JSON endpoint for this, separate
from the `.xlsx` export endpoints.

Section 1 layout: year `<select>` → summary strip (ON RECORD / APPLIED /
FILING RATE %, three stat-style numbers) → tier-breakdown row (three more
numbers: CUM LAUDE / MAGNA CUM LAUDE / SUMMA CUM LAUDE) → per-school table
→ "DOWNLOAD XLSX" button.

Section 2 layout: its own year `<select>` → ranked table (RANK / SCHOOL /
LATIN HONOR GRADUATES) → "DOWNLOAD XLSX" button.

## XLSX export

Two new endpoints, both `GET`, both `require_login()` (matching the page's
own access level, not tightened to admin-only), following
`api/download_template.php`'s exact existing pattern (build bytes in PHP,
set `Content-Type:
application/vnd.openxmlformats-officedocument.spreadsheetml.sheet` +
`Content-Disposition: attachment` + `Content-Length`, echo the bytes):

- `api/export_application_filing_report.php?year=YYYY`
- `api/export_school_ranking_report.php?year=YYYY`

Both call a new builder function each in `includes/xlsx_writer.php` (same
file the existing `build_latin_honor_template_xlsx()` lives in — this is
infrastructure reuse, not a new writer): `build_application_filing_report_xlsx(array $report, int $year): string` and
`build_school_ranking_report_xlsx(array $rows, int $year): string`. Both
reuse the existing, already-generic `xlsx_build_sheet_xml()` /
`xlsx_zip_parts()` / `xlsx_content_types_xml()` / `xlsx_package_rels_xml()`
/ `xlsx_workbook_xml()` / `xlsx_workbook_rels_xml()` / `xlsx_styles_xml()`
helpers completely unchanged — only the row data and sheet name passed in
differ from the existing template builder. Header rows use the existing
style index `1` (bold, already defined in `xlsx_styles_xml()`); data rows
use the default style (no `style` key, style `0`). No new styles need to be
added to `xlsx_styles_xml()`.

## Error handling

- An invalid or missing `year` on either page section or export endpoint:
  default to the most recent year with data (same fallback
  `get_distinct_graduation_years()`'s consumers already use elsewhere),
  never a hard error — a report page should always render something
  rather than bounce the admin to an error state for a bad query string.
- A year with genuinely zero Latin Honor graduates system-wide: both
  report functions return their respective "empty" shapes (`schools: []`,
  totals all `0`, `filing_rate: 0` to avoid a division-by-zero — only
  possible when `total_on_record` is also `0`, so there's nothing to
  divide), and the page renders an empty-state message in place of the
  table, matching the existing empty-state pattern used elsewhere (e.g.
  `admin/lookup.php`'s "NO HONOR GRADUATE RECORD FOUND").

## Testing

- `get_application_filing_report()`, `get_honor_tier_breakdown_for_year()`,
  and `get_school_ranking_by_honor_count()` are plain `PDO`-taking
  functions — directly unit-testable against `TestDb`, following the exact
  pattern already used for `get_dashboard_stats()` /
  `get_honor_type_breakdown()` in `tests/includes/FlashAndStatsTest.php`.
  Cover: a school with some applied and some not (correct `filing_rate`
  rounding), a school with zero applied (`filing_rate: 0`, not a
  division-by-zero), a year with no data at all (empty results, no
  errors), and the ranking function's tie-breaking by school name.
- The two new `xlsx_writer.php` builder functions are pure (take data,
  return bytes) and get the exact same test treatment
  `build_latin_honor_template_xlsx()` already has in
  `tests/includes/XlsxReadWriteTest.php`
  (`testGeneratedTemplateRoundTripsThroughReader()`, confirmed during
  design): write the returned bytes to a temp file, read it back with the
  existing `xlsx_read_first_sheet()` (`includes/xlsx_reader.php`), and
  assert the round-tripped rows match what was written — no new testing
  approach needed, just the established round-trip pattern applied to two
  more builder functions.
- `admin/reports.php`, the two new `api/get_*_report.php` JSON endpoints,
  and the two export endpoints follow this project's established
  convention of no direct test coverage for thin orchestration
  scripts/endpoints (confirmed repeatedly elsewhere in this codebase,
  e.g. `api/extract_pdf.php`) — verified live instead, during
  implementation.
