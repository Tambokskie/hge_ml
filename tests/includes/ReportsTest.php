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
}
