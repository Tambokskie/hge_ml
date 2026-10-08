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

    public function testGetFilingTrendReturnsOneOldestFirstPointPerYearWithZeroFill(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec(
            "INSERT INTO latin_honor_graduates (upload_id, school_id, graduate_name, latin_honor, graduation_year)
             VALUES (1, 1, 'A ONE', 'CUM LAUDE', 2025),
                    (1, 1, 'B TWO', 'CUM LAUDE', 2025),
                    (2, 2, 'C THREE', 'CUM LAUDE', 2023),
                    (2, 2, 'OUTSIDE WINDOW', 'CUM LAUDE', 2019)"
        );
        $pdo->exec("INSERT INTO graduate_applications (graduate_id, applied_by, application_date) VALUES (1, 1, '2025-07-01')");

        $trend = get_filing_trend($pdo, 2021, 2025);

        $this->assertSame([2021, 2022, 2023, 2024, 2025], array_column($trend, 'year'));
        $this->assertSame([0, 0, 1, 0, 2], array_column($trend, 'on_record'));
        $this->assertSame([0, 0, 0, 0, 1], array_column($trend, 'applied'));
    }

    public function testMonthlyTrendPlacesGraduatesInTheirGraduationMonthAndZeroFills(): void
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
        $this->assertSame(['year' => 2024, 'month' => 1, 'on_record' => 0, 'applied' => 0], $months[0]);
        $this->assertSame(['year' => 2025, 'month' => 12, 'on_record' => 0, 'applied' => 0], $months[23]);
        $this->assertSame(['year' => 2024, 'month' => 12, 'on_record' => 1, 'applied' => 0], $months[11]);
        $this->assertSame(['year' => 2025, 'month' => 3, 'on_record' => 2, 'applied' => 1], $months[14]);
        $this->assertSame(['year' => 2025, 'month' => 7, 'on_record' => 1, 'applied' => 0], $months[18]);
        $this->assertSame(['on_record' => 0, 'applied' => 0], $monthly['undated']);
    }

    public function testMonthlyTrendReportsGraduatesWithoutADateSeparatelyAndTotalsMatchTheYearly(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec(
            "INSERT INTO latin_honor_graduates (upload_id, school_id, graduate_name, latin_honor, graduation_year, graduation_date)
             VALUES (1, 1, 'A ONE', 'CUM LAUDE', 2025, '2025-03-28'),
                    (1, 1, 'B TWO', 'CUM LAUDE', 2025, NULL),
                    (1, 1, 'C THREE', 'CUM LAUDE', 2025, NULL)"
        );
        $pdo->exec("INSERT INTO graduate_applications (graduate_id, applied_by, application_date) VALUES (1, 1, '2025-08-01'), (2, 1, '2025-08-01')");

        $monthly = get_filing_trend_monthly($pdo, 2025, 2025);
        $yearly = get_filing_trend($pdo, 2025, 2025);

        $this->assertSame(['on_record' => 2, 'applied' => 1], $monthly['undated']);
        $this->assertSame(
            $yearly[0]['on_record'],
            array_sum(array_column($monthly['months'], 'on_record')) + $monthly['undated']['on_record']
        );
        $this->assertSame(
            $yearly[0]['applied'],
            array_sum(array_column($monthly['months'], 'applied')) + $monthly['undated']['applied']
        );
    }

    public function testGetFilingTrendWithNoDataIsAllZeros(): void
    {
        $trend = get_filing_trend(TestDb::pdo(), 2023, 2025);

        $this->assertCount(3, $trend);
        $this->assertSame([0, 0, 0], array_column($trend, 'on_record'));
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

    public function testFilingTrendCoversExactlyTheRange(): void
    {
        $this->seedRangeData();
        $pdo = TestDb::pdo();

        $trend = get_filing_trend($pdo, 2024, 2026);

        $this->assertSame([2024, 2025, 2026], array_column($trend, 'year'));
        $this->assertSame([3, 1, 1], array_column($trend, 'on_record'));
        $this->assertSame([1, 0, 1], array_column($trend, 'applied'));
        $this->assertSame([2026], array_column(get_filing_trend($pdo, 2026, 2026), 'year'));
    }

    public function testRangeTotalsEqualTheSumOfTheTrendPoints(): void
    {
        $this->seedRangeData();
        $pdo = TestDb::pdo();

        $report = get_application_filing_report($pdo, 2023, 2026);
        $trend = get_filing_trend($pdo, 2023, 2026);

        $this->assertSame($report['total_on_record'], array_sum(array_column($trend, 'on_record')));
        $this->assertSame($report['total_applied'], array_sum(array_column($trend, 'applied')));
    }

    public function testLatestUpdateTimestampWithoutRangeIsGlobalAndWithRangeIsScoped(): void
    {
        $this->seedRangeData();
        $pdo = TestDb::pdo();
        $pdo->exec("UPDATE latin_honor_graduates SET updated_at = '2025-01-01 00:00:00'");
        $pdo->exec("UPDATE latin_honor_graduates SET updated_at = '2026-05-05 05:05:05' WHERE graduation_year = 2026");

        $this->assertSame('2026-05-05 05:05:05', get_latest_update_timestamp_for_year($pdo));
        $this->assertSame('2025-01-01 00:00:00', get_latest_update_timestamp_for_year($pdo, 2023, 2025));
    }

    public function testNormalizeRangeDefaultsToLatestFiveYearPeriod(): void
    {
        $this->assertSame([2022, 2026], normalize_report_year_range([2020, 2023, 2026], null, null));
        $this->assertSame([2023, 2026], normalize_report_year_range([2023, 2024, 2026], null, null));
    }

    public function testNormalizeRangeKeepsAValidRange(): void
    {
        $this->assertSame([2024, 2026], normalize_report_year_range([2020, 2026], 2024, 2026));
        $this->assertSame([2025, 2025], normalize_report_year_range([2020, 2026], 2025, 2025));
    }

    public function testNormalizeRangeNeverReturnsToBeforeFrom(): void
    {
        [$from, $to] = normalize_report_year_range([2020, 2026], 2026, 2024);

        $this->assertSame(2026, $from);
        $this->assertSame(2026, $to);
    }

    public function testNormalizeRangeClampsToAvailableYears(): void
    {
        $this->assertSame([2020, 2026], normalize_report_year_range([2020, 2026], 1990, 2099));
        $currentYear = (int) date('Y');
        $this->assertSame([$currentYear, $currentYear], normalize_report_year_range([], 1, 2));
    }

    public function testComputePercentChangeOnIncrease(): void
    {
        $this->assertSame(20.0, compute_percent_change(120, 100));
    }

    public function testComputePercentChangeOnDecrease(): void
    {
        $this->assertSame(-20.0, compute_percent_change(80, 100));
    }

    public function testComputePercentChangeReturnsNullWhenPreviousIsZero(): void
    {
        $this->assertNull(compute_percent_change(50, 0));
    }

    public function testComputePercentChangeHandlesDropToZero(): void
    {
        $this->assertSame(-100.0, compute_percent_change(0, 50));
    }

    public function testGetApplicationFilingInsightsPicksTopFilingRateAndMostApplicants(): void
    {
        $schools = [
            ['school_name' => 'ABC UNIVERSITY', 'campus' => 'MAIN', 'on_record' => 100, 'applied' => 5, 'filing_rate' => 5.0],
            ['school_name' => 'XYZ COLLEGE', 'campus' => null, 'on_record' => 20, 'applied' => 9, 'filing_rate' => 45.0],
        ];

        $insights = get_application_filing_insights($schools);

        $this->assertSame('XYZ COLLEGE', $insights['top_filing_rate']['school_name']);
        $this->assertSame(45.0, $insights['top_filing_rate']['filing_rate']);
        $this->assertSame('XYZ COLLEGE', $insights['most_applicants']['school_name']);
        $this->assertSame(9, $insights['most_applicants']['applied']);
    }

    public function testGetApplicationFilingInsightsReturnsNullForEmptySchools(): void
    {
        $insights = get_application_filing_insights([]);

        $this->assertNull($insights['top_filing_rate']);
        $this->assertNull($insights['most_applicants']);
    }

    public function testGetApplicationFilingInsightsTieBreaksBySchoolNameAscending(): void
    {
        $schools = [
            ['school_name' => 'XYZ COLLEGE', 'campus' => null, 'on_record' => 10, 'applied' => 5, 'filing_rate' => 50.0],
            ['school_name' => 'ABC UNIVERSITY', 'campus' => 'MAIN', 'on_record' => 10, 'applied' => 5, 'filing_rate' => 50.0],
        ];

        $insights = get_application_filing_insights($schools);

        $this->assertSame('ABC UNIVERSITY', $insights['top_filing_rate']['school_name']);
        $this->assertSame('ABC UNIVERSITY', $insights['most_applicants']['school_name']);
    }

    public function testGetLatestUpdateTimestampForYearReturnsMostRecentUpdate(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec(
            "INSERT INTO latin_honor_graduates (upload_id, school_id, graduate_name, latin_honor, graduation_year)
             VALUES (1, 1, 'JUAN DELA CRUZ', 'CUM LAUDE', 2025),
                    (1, 1, 'MARIA SANTOS', 'MAGNA CUM LAUDE', 2025)"
        );
        $pdo->exec("UPDATE latin_honor_graduates SET updated_at = '2025-06-01 10:00:00' WHERE graduate_name = 'JUAN DELA CRUZ'");
        $pdo->exec("UPDATE latin_honor_graduates SET updated_at = '2025-07-15 14:30:00' WHERE graduate_name = 'MARIA SANTOS'");

        $timestamp = get_latest_update_timestamp_for_year($pdo, 2025);

        $this->assertSame('2025-07-15 14:30:00', $timestamp);
    }

    public function testGetLatestUpdateTimestampForYearReturnsNullWhenNoData(): void
    {
        $this->assertNull(get_latest_update_timestamp_for_year(TestDb::pdo(), 1999));
    }
}
