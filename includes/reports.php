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
