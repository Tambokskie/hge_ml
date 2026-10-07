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
         ORDER BY s.school_name, s.campus'
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
 * Most recent latin_honor_graduates.updated_at for a graduation year.
 * updated_at carries ON UPDATE CURRENT_TIMESTAMP, so this reflects the
 * latest save OR correction for that year, not just the original upload.
 * Null when the year has no graduates on record.
 */
function get_latest_update_timestamp_for_year(PDO $pdo, int $year): ?string
{
    $stmt = $pdo->prepare('SELECT MAX(updated_at) FROM latin_honor_graduates WHERE graduation_year = :year');
    $stmt->execute(['year' => $year]);
    $result = $stmt->fetchColumn();

    return $result === false || $result === null ? null : $result;
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
