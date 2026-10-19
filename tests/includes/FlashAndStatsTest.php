<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;
use Tests\TestDb;

require_once __DIR__ . '/../../includes/functions.php';

class FlashAndStatsTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
        TestDb::resetSchema();
    }

    public function testFlashSetThenGetReturnsAndClearsMessage(): void
    {
        flash_set('success', 'SAVED.');
        $this->assertSame(['type' => 'success', 'message' => 'SAVED.'], flash_get());
        $this->assertNull(flash_get());
    }

    public function testDashboardStatsAreZeroOnEmptyDatabase(): void
    {
        $stats = get_dashboard_stats(TestDb::pdo());

        $this->assertSame(0, $stats['total_graduates']);
        $this->assertSame(0, $stats['total_schools']);
        $this->assertSame(0, $stats['total_active_schools']);
        $this->assertSame(0, $stats['total_uploads']);
        $this->assertSame(0, $stats['applied_this_year']);
        $this->assertNull($stats['current_year']);
        $this->assertSame(0, $stats['current_year_records']);
    }

    public function testDashboardStatsReflectSeededData(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO users (name, username, email, password, role) VALUES ('Admin', 'admin', 'a@a.com', 'x', 'administrator')");
        $pdo->exec("INSERT INTO schools (school_name) VALUES ('ABC UNIVERSITY'), ('XYZ COLLEGE')");
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, extraction_status, records_extracted)
             VALUES (1, '2025', 'list.pdf', 'stored.pdf', '/storage/pdfs/stored.pdf', 100, SHA2('x',256), 1, 'reviewed', 2)"
        );
        $pdo->exec(
            "INSERT INTO latin_honor_graduates (upload_id, school_id, graduate_name, latin_honor, graduation_year)
             VALUES (1, 1, 'JUAN DELA CRUZ', 'CUM LAUDE', 2025), (1, 1, 'MARIA SANTOS', 'MAGNA CUM LAUDE', 2024)"
        );

        $stats = get_dashboard_stats($pdo);

        $this->assertSame(2, $stats['total_graduates']);
        $this->assertSame(1, $stats['total_schools']);
        $this->assertSame(2, $stats['total_active_schools']);
        $this->assertSame(1, $stats['total_uploads']);
        $this->assertSame(0, $stats['applied_this_year']);
        $this->assertSame(2025, $stats['current_year']);
        $this->assertSame(1, $stats['current_year_records']);
    }

    public function testGetGraduateCountByYearReturnsCountForTheRequestedYearOnly(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO users (name, username, email, password, role) VALUES ('Admin', 'admin', 'a@a.com', 'x', 'administrator')");
        $pdo->exec("INSERT INTO schools (school_name) VALUES ('ABC UNIVERSITY')");
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, extraction_status, records_extracted)
             VALUES (1, '2025', 'list.pdf', 'stored.pdf', '/storage/pdfs/stored.pdf', 100, SHA2('x',256), 1, 'reviewed', 3)"
        );
        $pdo->exec(
            "INSERT INTO latin_honor_graduates (upload_id, school_id, graduate_name, latin_honor, graduation_year)
             VALUES (1, 1, 'JUAN DELA CRUZ', 'CUM LAUDE', 2025), (1, 1, 'MARIA SANTOS', 'MAGNA CUM LAUDE', 2025), (1, 1, 'PEDRO REYES', 'CUM LAUDE', 2024)"
        );

        $this->assertSame(2, get_graduate_count_by_year($pdo, 2025));
        $this->assertSame(1, get_graduate_count_by_year($pdo, 2024));
    }

    public function testGetGraduateCountByYearReturnsZeroForAYearWithNoRecords(): void
    {
        $this->assertSame(0, get_graduate_count_by_year(TestDb::pdo(), 1999));
    }

    public function testDashboardStatsExcludesDisabledSchoolsFromActiveCount(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO schools (school_name, status) VALUES ('ACTIVE SCHOOL', 'active'), ('DISABLED SCHOOL', 'disabled')");

        $stats = get_dashboard_stats($pdo);

        $this->assertSame(1, $stats['total_active_schools']);
    }

    public function testHonorTypeBreakdownIsZeroedOnEmptyDatabase(): void
    {
        $breakdown = get_honor_type_breakdown(TestDb::pdo());

        $this->assertSame([
            'CUM LAUDE' => 0,
            'MAGNA CUM LAUDE' => 0,
            'SUMMA CUM LAUDE' => 0,
        ], $breakdown);
    }

    public function testHonorTypeBreakdownReflectsSeededData(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO users (name, username, email, password, role) VALUES ('Admin', 'admin', 'a@a.com', 'x', 'administrator')");
        $pdo->exec("INSERT INTO schools (school_name) VALUES ('ABC UNIVERSITY')");
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, extraction_status, records_extracted)
             VALUES (1, '2025', 'list.pdf', 'stored.pdf', '/storage/pdfs/stored.pdf', 100, SHA2('x',256), 1, 'reviewed', 3)"
        );
        $pdo->exec(
            "INSERT INTO latin_honor_graduates (upload_id, school_id, graduate_name, latin_honor, graduation_year)
             VALUES (1, 1, 'JUAN DELA CRUZ', 'CUM LAUDE', 2025), (1, 1, 'MARIA SANTOS', 'CUM LAUDE', 2025), (1, 1, 'PEDRO REYES', 'SUMMA CUM LAUDE', 2025)"
        );

        $breakdown = get_honor_type_breakdown($pdo);

        $this->assertSame([
            'CUM LAUDE' => 2,
            'MAGNA CUM LAUDE' => 0,
            'SUMMA CUM LAUDE' => 1,
        ], $breakdown);
    }

    public function testUploadActivityByMonthCoversRequestedWindowWithZeroFill(): void
    {
        $series = get_upload_activity_by_month(TestDb::pdo(), 6);

        $this->assertCount(6, $series);
        foreach ($series as $month) {
            $this->assertSame(0, $month['count']);
            $this->assertMatchesRegularExpression('/^[A-Z]{3}$/', $month['label']);
        }
        $this->assertSame(strtoupper(date('M')), $series[5]['label']);
    }

    public function testUploadActivityByMonthCountsUploadsInCurrentMonth(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO users (name, username, email, password, role) VALUES ('Admin', 'admin', 'a@a.com', 'x', 'administrator')");
        $pdo->exec("INSERT INTO schools (school_name) VALUES ('ABC UNIVERSITY')");
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, extraction_status, records_extracted, uploaded_at)
             VALUES (1, '2025', 'list.pdf', 'stored.pdf', '/storage/pdfs/stored.pdf', 100, SHA2('x',256), 1, 'reviewed', 3, NOW())"
        );

        $series = get_upload_activity_by_month($pdo, 6);

        $this->assertSame(1, $series[5]['count']);
    }
    public function testApplicationsAreCountedPerYearFiledAndTheCurrentYearIsAlwaysOffered(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO users (name, username, email, password, role) VALUES ('Admin', 'admin', 'a@a.com', 'x', 'administrator')");
        $pdo->exec("INSERT INTO schools (school_name) VALUES ('ABC UNIVERSITY')");
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, extraction_status, records_extracted)
             VALUES (1, '2025', 'list.pdf', 'stored.pdf', '/storage/pdfs/stored.pdf', 100, SHA2('x',256), 1, 'reviewed', 3)"
        );
        $pdo->exec(
            "INSERT INTO latin_honor_graduates (upload_id, school_id, graduate_name, latin_honor, graduation_year)
             VALUES (1, 1, 'A ONE', 'CUM LAUDE', 2025), (1, 1, 'B TWO', 'CUM LAUDE', 2025), (1, 1, 'C THREE', 'CUM LAUDE', 2025)"
        );
        $pdo->exec(
            "INSERT INTO graduate_applications (graduate_id, applied_by, application_date)
             VALUES (1, 1, '2024-03-01'), (2, 1, '2024-12-31'), (3, 1, '2023-05-05')"
        );

        $this->assertSame(2, get_applied_count_by_year($pdo, 2024));
        $this->assertSame(1, get_applied_count_by_year($pdo, 2023));
        $this->assertSame(0, get_applied_count_by_year($pdo, 2030));

        $years = get_application_years($pdo);
        $this->assertSame((int) date('Y'), $years[0]);
        $this->assertContains(2024, $years);
        $this->assertContains(2023, $years);
        $this->assertSame($years, array_values(array_unique($years)));
    }
}