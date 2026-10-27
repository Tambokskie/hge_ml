<?php

/**
 * Per-school, year-scoped breakdown of Latin Honor graduates on record
 * versus how many have filed for Honor Graduate Eligibility (see
 * includes/graduates.php:file_application() / the graduate_applications
 * table) across the graduation years $fromYear..$toYear inclusive (a single
 * year when $toYear is omitted). A school with zero Latin Honor graduates in
 * the range is excluded entirely (INNER JOIN, not LEFT JOIN) rather than shown as an all-zero
 * row. filing_rate/approval_rate are always computed here in PHP, never in
 * SQL. filing_rate divides by on_record (already confirmed > 0); approval_rate
 * divides by applied (approved / applied * 100 — the share of FILED
 * applications that were approved, not the share of on-record graduates).
 * `approved` (COUNT of non-NULL approved_at, see includes/graduates.php:
 * approve_application()) is always <= `applied` — it is the applied count's
 * own subset, not a separate population — so `applied - approved` is exactly
 * "filed but still processing" wherever that's needed (e.g. the dashboard's
 * sunburst donut and TREND chart).
 */
function get_application_filing_report(PDO $pdo, int $fromYear, ?int $toYear = null): array
{
    $toYear = $toYear ?? $fromYear;

    $stmt = $pdo->prepare(
        'SELECT s.id AS school_id, s.school_name, s.campus,
                COUNT(g.id) AS on_record,
                COUNT(ga.id) AS applied,
                COUNT(ga.approved_at) AS approved
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
    $totalApproved = 0;

    foreach ($stmt->fetchAll() as $row) {
        $onRecord = (int) $row['on_record'];
        $applied = (int) $row['applied'];
        $approved = (int) $row['approved'];
        $totalOnRecord += $onRecord;
        $totalApplied += $applied;
        $totalApproved += $approved;

        $schools[] = [
            'school_id' => (int) $row['school_id'],
            'school_name' => $row['school_name'],
            'campus' => $row['campus'],
            'on_record' => $onRecord,
            'applied' => $applied,
            'approved' => $approved,
            'filing_rate' => $onRecord > 0 ? round($applied / $onRecord * 100, 1) : 0.0,
            'approval_rate' => $applied > 0 ? round($approved / $applied * 100, 1) : 0.0,
        ];
    }

    return [
        'total_on_record' => $totalOnRecord,
        'total_applied' => $totalApplied,
        'total_approved' => $totalApproved,
        'filing_rate' => $totalOnRecord > 0 ? round($totalApplied / $totalOnRecord * 100, 1) : 0.0,
        'approval_rate' => $totalApplied > 0 ? round($totalApproved / $totalApplied * 100, 1) : 0.0,
        'schools' => $schools,
    ];
}

/**
 * Applications RECEIVED in a calendar year: every graduate_applications row whose application_date
 * falls in $year, whatever year the applicant graduated. This is the definition behind the
 * dashboard's "HGE APPLICATIONS RECEIVED" card, the filing overview's "APPLICATION RECEIVED" line
 * (the same number), "APPROVED HGE" and the PROCESSING / APPROVED lines: approved = those of the
 * received applications that have an approval date, processing = the rest.
 *
 * Each figure also comes with the part that belongs to graduates of OTHER years (`other_years_*`),
 * so the dashboard can show received = $year's own graduates + other years, for received, approved
 * and processing alike.
 *
 * Not the same population as get_application_filing_report(), which is scoped by the graduation
 * year (ON RECORD, the filing rate and the per-school table only make sense for one graduating
 * class).
 */
function get_applied_breakdown_by_filed_year(PDO $pdo, int $year): array
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS applied, COUNT(a.approved_at) AS approved,
                COALESCE(SUM(g.graduation_year <> :year_other), 0) AS other_applied,
                COALESCE(SUM(g.graduation_year <> :year_other_approved AND a.approved_at IS NOT NULL), 0) AS other_approved
         FROM graduate_applications a
         JOIN latin_honor_graduates g ON g.id = a.graduate_id
         WHERE YEAR(a.application_date) = :year'
    );
    $stmt->execute(['year' => $year, 'year_other' => $year, 'year_other_approved' => $year]);
    $row = $stmt->fetch();

    $applied = (int) $row['applied'];
    $approved = (int) $row['approved'];
    $otherApplied = (int) $row['other_applied'];
    $otherApproved = (int) $row['other_approved'];

    return [
        'total_applied' => $applied,
        'total_approved' => $approved,
        'total_processing' => $applied - $approved,
        'other_years_applied' => $otherApplied,
        'other_years_approved' => $otherApproved,
        'other_years_processing' => $otherApplied - $otherApproved,
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
 * One point per calendar month from January of $fromYear to December of
 * $toYear (oldest first, empty months zero-filled). Each series is placed by
 * its own date field, on purpose:
 *
 *  - on_record: a graduate is placed in the month of its graduation_date,
 *    within its graduation_year. Graduates saved without a graduation_date
 *    have no month — they are returned separately as 'undated' rather than
 *    dropped.
 *  - processing: an application still awaiting approval, placed in the month
 *    it was ACTUALLY FILED (graduate_applications.application_date) — a live
 *    "still processing" snapshot grouped by filing month, so an application
 *    that has since been approved drops out of its filing month's count here
 *    (it now shows up in `approved` instead; the two series are mutually
 *    exclusive at any point in time, filed/graduation month notwithstanding).
 *  - approved: placed in the month it was actually approved
 *    (graduate_applications.approved_at), independent of when it was filed.
 *
 * None of these sum to any yearly total, and a month's applications can
 * exceed its graduates — this answers "what happened in that month", not
 * "what year does this belong to".
 *
 * @return array{months: array<int, array{year: int, month: int, on_record: int, processing: int, approved: int}>, undated: array{on_record: int}}
 */
function get_filing_trend_monthly(PDO $pdo, int $fromYear, int $toYear): array
{
    $stmt = $pdo->prepare(
        'SELECT graduation_year AS year, MONTH(graduation_date) AS month, COUNT(*) AS on_record
         FROM latin_honor_graduates
         WHERE graduation_year BETWEEN :start_year AND :end_year
         GROUP BY graduation_year, MONTH(graduation_date)'
    );
    $stmt->execute(['start_year' => $fromYear, 'end_year' => $toYear]);

    $onRecord = [];
    $undated = ['on_record' => 0];
    foreach ($stmt->fetchAll() as $row) {
        if ($row['month'] === null) {
            $undated['on_record'] += (int) $row['on_record'];
            continue;
        }
        $onRecord[(int) $row['year'] . '-' . (int) $row['month']] = (int) $row['on_record'];
    }

    $stmt = $pdo->prepare(
        'SELECT YEAR(application_date) AS year, MONTH(application_date) AS month, COUNT(*) AS processing
         FROM graduate_applications
         WHERE approved_at IS NULL AND application_date BETWEEN :start_date AND :end_date
         GROUP BY YEAR(application_date), MONTH(application_date)'
    );
    $stmt->execute(['start_date' => sprintf('%04d-01-01', $fromYear), 'end_date' => sprintf('%04d-12-31', $toYear)]);

    $processing = [];
    foreach ($stmt->fetchAll() as $row) {
        $processing[(int) $row['year'] . '-' . (int) $row['month']] = (int) $row['processing'];
    }

    $stmt = $pdo->prepare(
        'SELECT YEAR(approved_at) AS year, MONTH(approved_at) AS month, COUNT(*) AS approved
         FROM graduate_applications
         WHERE approved_at BETWEEN :start_date AND :end_date
         GROUP BY YEAR(approved_at), MONTH(approved_at)'
    );
    $stmt->execute(['start_date' => sprintf('%04d-01-01', $fromYear), 'end_date' => sprintf('%04d-12-31', $toYear)]);

    $approved = [];
    foreach ($stmt->fetchAll() as $row) {
        $approved[(int) $row['year'] . '-' . (int) $row['month']] = (int) $row['approved'];
    }

    $months = [];
    for ($year = $fromYear; $year <= $toYear; $year++) {
        for ($month = 1; $month <= 12; $month++) {
            $months[] = [
                'year' => $year,
                'month' => $month,
                'on_record' => $onRecord[$year . '-' . $month] ?? 0,
                'processing' => $processing[$year . '-' . $month] ?? 0,
                'approved' => $approved[$year . '-' . $month] ?? 0,
            ];
        }
    }

    return ['months' => $months, 'undated' => $undated];
}
