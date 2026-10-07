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
         ORDER BY honor_count DESC, s.school_name ASC, s.campus ASC'
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
