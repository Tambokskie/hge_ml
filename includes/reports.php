<?php

/**
 * Per-school, year-scoped breakdown of Latin Honor graduates on record
 * versus how many have filed for Honor Graduate Eligibility (see
 * includes/graduates.php:file_application() / the graduate_applications
 * table) across the graduation years $fromYear..$toYear inclusive (a single
 * year when $toYear is omitted). A school with zero Latin Honor graduates in
 * the range is excluded entirely (INNER JOIN, not LEFT JOIN) rather than shown as an all-zero
 * row. filing_rate is always computed here in PHP, never in SQL, and only
 * ever divides by an on_record value already confirmed > 0.
 */
function get_application_filing_report(PDO $pdo, int $fromYear, ?int $toYear = null): array
{
    $toYear = $toYear ?? $fromYear;

    $stmt = $pdo->prepare(
        'SELECT s.school_name, s.campus,
                COUNT(g.id) AS on_record,
                COUNT(ga.id) AS applied
         FROM schools s
         JOIN latin_honor_graduates g ON g.school_id = s.id AND g.graduation_year BETWEEN :from_year AND :to_year
         LEFT JOIN graduate_applications ga ON ga.graduate_id = g.id
         GROUP BY s.id, s.school_name, s.campus
         ORDER BY s.school_name, s.campus'
    );
    $stmt->execute(['from_year' => $fromYear, 'to_year' => $toYear]);

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

/**
 * Year-scoped sibling of includes/functions.php's get_honor_type_breakdown()
 * — same three-key shape and zero-filled defaults, but counts only the
 * graduation years $fromYear..$toYear (one year when $toYear is omitted)
 * rather than every graduate on file. Kept separate from
 * the dashboard's version rather than adding a parameter to it, since the
 * dashboard's call site has no year to pass and changing that function's
 * signature would ripple into admin/dashboard.php for no reason.
 */
function get_honor_tier_breakdown_for_year(PDO $pdo, int $fromYear, ?int $toYear = null): array
{
    $toYear = $toYear ?? $fromYear;

    $counts = [
        'CUM LAUDE' => 0,
        'MAGNA CUM LAUDE' => 0,
        'SUMMA CUM LAUDE' => 0,
    ];

    $stmt = $pdo->prepare('SELECT latin_honor, COUNT(*) AS total FROM latin_honor_graduates WHERE graduation_year BETWEEN :from_year AND :to_year GROUP BY latin_honor');
    $stmt->execute(['from_year' => $fromYear, 'to_year' => $toYear]);
    foreach ($stmt->fetchAll() as $row) {
        $counts[$row['latin_honor']] = (int) $row['total'];
    }

    return $counts;
}

/**
 * Latin Honor graduates on record vs. filed Honor Graduate Eligibility
 * applications for every graduation year from $fromYear to $toYear inclusive
 * (oldest first). A year with no graduates is still returned, as zeros, so
 * the chart always has one point per year.
 *
 * @return array<int, array{year: int, on_record: int, applied: int}>
 */
function get_filing_trend(PDO $pdo, int $fromYear, int $toYear): array
{
    $startYear = $fromYear;
    $endYear = $toYear;

    $stmt = $pdo->prepare(
        'SELECT g.graduation_year AS year, COUNT(g.id) AS on_record, COUNT(ga.id) AS applied
         FROM latin_honor_graduates g
         LEFT JOIN graduate_applications ga ON ga.graduate_id = g.id
         WHERE g.graduation_year BETWEEN :start_year AND :end_year
         GROUP BY g.graduation_year'
    );
    $stmt->execute(['start_year' => $startYear, 'end_year' => $endYear]);

    $byYear = [];
    foreach ($stmt->fetchAll() as $row) {
        $byYear[(int) $row['year']] = ['on_record' => (int) $row['on_record'], 'applied' => (int) $row['applied']];
    }

    $trend = [];
    for ($year = $startYear; $year <= $endYear; $year++) {
        $trend[] = [
            'year' => $year,
            'on_record' => $byYear[$year]['on_record'] ?? 0,
            'applied' => $byYear[$year]['applied'] ?? 0,
        ];
    }

    return $trend;
}

/**
 * Percent change of $current relative to $previous, rounded to one decimal.
 * Null when $previous is 0 — there is no prior-year baseline to compare
 * against, so the caller shows no trend line rather than a misleading 0%
 * or a division by zero.
 */
function compute_percent_change(int $current, int $previous): ?float
{
    if ($previous === 0) {
        return null;
    }

    return round(($current - $previous) / $previous * 100, 1);
}

/**
 * Most recent latin_honor_graduates.updated_at — across all graduates by
 * default (the "DATA UPDATED" line is not part of the year filter), or
 * limited to a graduation-year range when one is given. updated_at carries
 * ON UPDATE CURRENT_TIMESTAMP, so this reflects the latest save OR
 * correction, not just the original upload. Null when there is no data.
 */
function get_latest_update_timestamp_for_year(PDO $pdo, ?int $fromYear = null, ?int $toYear = null): ?string
{
    if ($fromYear === null) {
        $stmt = $pdo->query('SELECT MAX(updated_at) FROM latin_honor_graduates');
    } else {
        $stmt = $pdo->prepare('SELECT MAX(updated_at) FROM latin_honor_graduates WHERE graduation_year BETWEEN :from_year AND :to_year');
        $stmt->execute(['from_year' => $fromYear, 'to_year' => $toYear ?? $fromYear]);
    }
    $result = $stmt->fetchColumn();

    return $result === false || $result === null ? null : $result;
}

/**
 * Turns whatever from/to the browser sent into a valid reporting range:
 * both clamped to the years that exist, and "to" never earlier than "from".
 * Defaults (nothing sent) are the latest year as "to" and the 5-year
 * period ending there as "from" — the report's original behavior. Used by
 * the page, the JSON endpoint and the export so all three always agree.
 *
 * @param array<int, int> $availableYears
 * @return array{0: int, 1: int}
 */
function normalize_report_year_range(array $availableYears, ?int $from, ?int $to): array
{
    if ($availableYears === []) {
        $year = (int) date('Y');

        return [$year, $year];
    }

    $min = min($availableYears);
    $max = max($availableYears);

    $to = ($to === null || $to <= 0) ? $max : max($min, min($max, $to));
    $from = ($from === null || $from <= 0) ? max($min, $to - 4) : max($min, min($max, $from));

    if ($from > $to) {
        $to = $from;
    }

    return [$from, $to];
}

/**
 * Pure (no PDO) — takes the already-fetched $schools array from
 * get_application_filing_report() rather than re-querying. Finds the
 * school with the highest filing_rate and the school with the highest
 * applied count; ties broken by school_name ascending. Both keys are
 * null when $schools is empty.
 */
function get_application_filing_insights(array $schools): array
{
    if ($schools === []) {
        return ['top_filing_rate' => null, 'most_applicants' => null];
    }

    $byFilingRate = $schools;
    usort($byFilingRate, static function (array $a, array $b): int {
        return $b['filing_rate'] <=> $a['filing_rate'] ?: $a['school_name'] <=> $b['school_name'];
    });

    $byApplied = $schools;
    usort($byApplied, static function (array $a, array $b): int {
        return $b['applied'] <=> $a['applied'] ?: $a['school_name'] <=> $b['school_name'];
    });

    return [
        'top_filing_rate' => $byFilingRate[0],
        'most_applicants' => $byApplied[0],
    ];
}
