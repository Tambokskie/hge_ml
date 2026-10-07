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
