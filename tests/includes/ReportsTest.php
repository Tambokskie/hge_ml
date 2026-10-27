<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;
use Tests\TestDb;

require_once __DIR__ . '/../../includes/reports.php';
require_once __DIR__ . '/../../includes/functions.php';

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

    public function testMonthlyTrendPlacesGraduatesInTheirGraduationMonthAndApplicationsInTheMonthTheyWereFiled(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec(
            "INSERT INTO latin_honor_graduates (upload_id, school_id, graduate_name, latin_honor, graduation_year, graduation_date)
             VALUES (1, 1, 'A ONE', 'CUM LAUDE', 2025, '2025-03-28'),
                    (1, 1, 'B TWO', 'CUM LAUDE', 2025, '2025-03-30'),
                    (1, 1, 'C THREE', 'CUM LAUDE', 2025, '2025-07-01'),
                    (2, 2, 'D FOUR', 'CUM LAUDE', 2024, '2024-12-15'),
                    (2, 2, 'OUTSIDE', 'CUM LAUDE', 2019, '2019-05-05')"
        );
        $pdo->exec("INSERT INTO graduate_applications (graduate_id, applied_by, application_date) VALUES (2, 1, '2025-08-01')");

        $monthly = get_filing_trend_monthly($pdo, 2024, 2025);
        $months = $monthly['months'];

        $this->assertCount(24, $months);
        $this->assertSame(['year' => 2024, 'month' => 1, 'on_record' => 0, 'processing' => 0, 'approved' => 0], $months[0]);
        $this->assertSame(['year' => 2025, 'month' => 12, 'on_record' => 0, 'processing' => 0, 'approved' => 0], $months[23]);
        $this->assertSame(['year' => 2024, 'month' => 12, 'on_record' => 1, 'processing' => 0, 'approved' => 0], $months[11]);
        // graduate 2 graduated in March but filed in August: the application belongs to August
        $this->assertSame(['year' => 2025, 'month' => 3, 'on_record' => 2, 'processing' => 0, 'approved' => 0], $months[14]);
        $this->assertSame(['year' => 2025, 'month' => 7, 'on_record' => 1, 'processing' => 0, 'approved' => 0], $months[18]);
        $this->assertSame(['year' => 2025, 'month' => 8, 'on_record' => 0, 'processing' => 1, 'approved' => 0], $months[19]);
        $this->assertSame(['on_record' => 0], $monthly['undated']);
    }

    public function testMonthlyTrendReportsGraduatesWithoutADateSeparatelyAndOnRecordTotalsMatchTheYearly(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec(
            "INSERT INTO latin_honor_graduates (upload_id, school_id, graduate_name, latin_honor, graduation_year, graduation_date)
             VALUES (1, 1, 'A ONE', 'CUM LAUDE', 2025, '2025-03-28'),
                    (1, 1, 'B TWO', 'CUM LAUDE', 2025, NULL),
                    (1, 1, 'C THREE', 'CUM LAUDE', 2025, NULL)"
        );
        // an undated graduate can still have a filing month: it is charted by that month
        $pdo->exec("INSERT INTO graduate_applications (graduate_id, applied_by, application_date) VALUES (1, 1, '2025-08-01'), (2, 1, '2025-09-10')");

        $monthly = get_filing_trend_monthly($pdo, 2025, 2025);

        $this->assertSame(['on_record' => 2], $monthly['undated']);
        // 3 graduates inserted above for 2025 (1 dated, 2 undated).
        $this->assertSame(
            3,
            array_sum(array_column($monthly['months'], 'on_record')) + $monthly['undated']['on_record']
        );
        $this->assertSame(1, $monthly['months'][7]['processing']);
        $this->assertSame(1, $monthly['months'][8]['processing']);
    }

    public function testMonthlyTrendCountsApplicationsByFilingMonthWhateverTheGraduationYear(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec(
            "INSERT INTO latin_honor_graduates (upload_id, school_id, graduate_name, latin_honor, graduation_year, graduation_date)
             VALUES (1, 1, 'A ONE', 'CUM LAUDE', 2023, '2023-04-10'),
                    (1, 1, 'B TWO', 'CUM LAUDE', 2025, '2025-04-10'),
                    (1, 1, 'C THREE', 'CUM LAUDE', 2025, '2025-04-10'),
                    (1, 1, 'D FOUR', 'CUM LAUDE', 2025, '2025-04-10')"
        );
        $pdo->exec(
            "INSERT INTO graduate_applications (graduate_id, applied_by, application_date)
             VALUES (1, 1, '2025-06-05'), (2, 1, '2025-06-20'), (3, 1, '2026-01-02'), (4, 1, '2024-12-31')"
        );

        $months = get_filing_trend_monthly($pdo, 2025, 2025)['months'];

        // June 2025 holds both applications filed then (one by a 2023 graduate, one by a 2025 graduate)
        $this->assertSame(2, $months[5]['processing']);
        $this->assertSame(0, $months[5]['on_record']);
        // filed outside the charted window: not plotted
        $this->assertSame(2, array_sum(array_column($months, 'processing')));
        // April 2025 still shows the graduates, with no applications in it
        $this->assertSame(['year' => 2025, 'month' => 4, 'on_record' => 3, 'processing' => 0, 'approved' => 0], $months[3]);
    }

    private function seedRangeData(): void
    {
        $pdo = TestDb::pdo();
        // ABC UNIVERSITY: 2023 x1, 2024 x2, 2025 x1 | XYZ COLLEGE: 2024 x1, 2026 x1
        $pdo->exec(
            "INSERT INTO latin_honor_graduates (upload_id, school_id, graduate_name, latin_honor, graduation_year)
             VALUES (1, 1, 'ABC 2023', 'CUM LAUDE', 2023),
                    (1, 1, 'ABC 2024 A', 'MAGNA CUM LAUDE', 2024),
                    (1, 1, 'ABC 2024 B', 'CUM LAUDE', 2024),
                    (1, 1, 'ABC 2025', 'SUMMA CUM LAUDE', 2025),
                    (2, 2, 'XYZ 2024', 'CUM LAUDE', 2024),
                    (2, 2, 'XYZ 2026', 'CUM LAUDE', 2026)"
        );
        // applications: ABC 2024 A (id 2), XYZ 2026 (id 6)
        $pdo->exec("INSERT INTO graduate_applications (graduate_id, applied_by, application_date) VALUES (2, 1, '2025-01-01'), (6, 1, '2026-01-01')");
    }

    public function testFilingReportAggregatesAcrossTheWholeRange(): void
    {
        $this->seedRangeData();

        $report = get_application_filing_report(TestDb::pdo(), 2023, 2026);

        $this->assertSame(6, $report['total_on_record']);
        $this->assertSame(2, $report['total_applied']);
        $this->assertSame(33.3, $report['filing_rate']);
        $this->assertSame(['ABC UNIVERSITY', 'XYZ COLLEGE'], array_column($report['schools'], 'school_name'));
        $this->assertSame([4, 2], array_column($report['schools'], 'on_record'));
        $this->assertSame([1, 1], array_column($report['schools'], 'applied'));
    }

    public function testFilingReportRangeExcludesYearsOutsideIt(): void
    {
        $this->seedRangeData();

        $report = get_application_filing_report(TestDb::pdo(), 2024, 2025);

        $this->assertSame(4, $report['total_on_record']);
        $this->assertSame(1, $report['total_applied']);
    }

    public function testFilingReportSingleYearRangeMatchesOldSingleYearBehavior(): void
    {
        $this->seedRangeData();
        $pdo = TestDb::pdo();

        $this->assertSame(get_application_filing_report($pdo, 2024), get_application_filing_report($pdo, 2024, 2024));
        $this->assertSame(3, get_application_filing_report($pdo, 2024)['total_on_record']);
        $this->assertSame(1, get_application_filing_report($pdo, 2026, 2026)['total_on_record']);
    }

    public function testTierBreakdownRespectsRange(): void
    {
        $this->seedRangeData();
        $pdo = TestDb::pdo();

        $this->assertSame(
            ['CUM LAUDE' => 4, 'MAGNA CUM LAUDE' => 1, 'SUMMA CUM LAUDE' => 1],
            get_honor_tier_breakdown_for_year($pdo, 2023, 2026)
        );
        $this->assertSame(
            ['CUM LAUDE' => 0, 'MAGNA CUM LAUDE' => 0, 'SUMMA CUM LAUDE' => 1],
            get_honor_tier_breakdown_for_year($pdo, 2025, 2025)
        );
    }

    public function testFiledInYearFigureSplitsIntoThisYearsGraduatesAndOtherGraduationYears(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec(
            "INSERT INTO latin_honor_graduates (upload_id, school_id, graduate_name, latin_honor, graduation_year)
             VALUES (1, 1, 'GRAD 2026 A', 'CUM LAUDE', 2026),
                    (1, 1, 'GRAD 2026 B', 'CUM LAUDE', 2026),
                    (1, 1, 'GRAD 2025 A', 'CUM LAUDE', 2025),
                    (1, 1, 'GRAD 2025 B', 'CUM LAUDE', 2025),
                    (1, 1, 'GRAD 2025 C', 'CUM LAUDE', 2025),
                    (2, 2, 'GRAD 2024', 'CUM LAUDE', 2024)"
        );
        $pdo->exec(
            "INSERT INTO graduate_applications (graduate_id, applied_by, application_date, approved_at)
             VALUES (1, 1, '2026-02-01', '2026-02-02'),
                    (2, 1, '2026-03-01', NULL),
                    (3, 1, '2026-04-01', '2026-04-05'),
                    (4, 1, '2026-05-01', NULL),
                    (5, 1, '2025-11-01', '2025-11-02'),
                    (6, 1, '2026-06-01', NULL)"
        );

        $filedIn2026 = get_applied_breakdown_by_filed_year($pdo, 2026);
        $graduatesOf2026 = get_application_filing_report($pdo, 2026);

        // 5 applications were filed in 2026, but only 2 belong to 2026's graduates: the other 3 are
        // graduates of 2025 and 2024, which is why the "filed in" note can exceed the main figure.
        $this->assertSame(5, $filedIn2026['total_applied']);
        $this->assertSame(2, $graduatesOf2026['total_applied']);
        $this->assertSame(3, $filedIn2026['other_years_applied']);
        $this->assertSame(2, $filedIn2026['total_approved']);
        $this->assertSame(1, $filedIn2026['other_years_approved']);
        $this->assertSame(2, $filedIn2026['other_years_processing']);
        $this->assertSame(3, $filedIn2026['total_processing']);
        // here every 2026 graduate filed in 2026, so the two scopes differ by exactly the other years
        $this->assertSame($filedIn2026['total_applied'], $graduatesOf2026['total_applied'] + $filedIn2026['other_years_applied']);

        // HGE APPLICATIONS RECEIVED (top card) and APPLICATION RECEIVED (overview) are the same figure
        $this->assertSame(get_applied_count_by_year($pdo, 2026), $filedIn2026['total_applied']);
        $this->assertSame(get_applied_count_by_year($pdo, 2025), get_applied_breakdown_by_filed_year($pdo, 2025)['total_applied']);
        // received = approved + processing, for the part from other years as well
        $this->assertSame($filedIn2026['total_applied'], $filedIn2026['total_approved'] + $filedIn2026['total_processing']);
        $this->assertSame($filedIn2026['other_years_applied'], $filedIn2026['other_years_approved'] + $filedIn2026['other_years_processing']);

        $none = get_applied_breakdown_by_filed_year($pdo, 1999);
        $this->assertSame(0, $none['total_applied']);
        $this->assertSame(0, $none['other_years_applied']);
        $this->assertSame(0, $none['other_years_processing']);
    }
}
