<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;
use Tests\TestDb;

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/graduates.php';
require_once __DIR__ . '/../../includes/uploads.php';
require_once __DIR__ . '/../../includes/extraction_review.php';

/** graduation_year always agrees with graduation_date when there is a date (Look-Up filters and the dashboard count by the year). */
class GraduationYearFollowsDateTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
        TestDb::resetSchema();
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO users (name, username, email, password, role) VALUES ('Admin', 'admin', 'a@a.com', 'x', 'administrator')");
        $pdo->exec("INSERT INTO schools (school_name, campus, status) VALUES ('Samar State University', 'MAIN', 'active')");
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number, extraction_status)
             VALUES (1, '2026', 'list.pdf', 'list.pdf', '/x/list.pdf', 10, SHA2('a',256), 1, 1, 'extracted')"
        );
    }

    /** @return array<string, array{0: ?string, 1: mixed, 2: int}> */
    public static function resolutions(): array
    {
        return [
            'the date wins over a different year' => ['2026-06-03', 2024, 2026],
            'the date wins over a text year' => ['2025-01-31', '2030', 2025],
            'no date: the year as given' => [null, 2024, 2024],
            'empty date: the year as given' => ['', '2023', 2023],
            'an invalid date is ignored' => ['2026-13-40', 2024, 2024],
            'a malformed date is ignored' => ['June 3, 2026', 2024, 2024],
        ];
    }

    /** @dataProvider resolutions */
    public function testResolveGraduationYear(?string $date, $year, int $expected): void
    {
        $this->assertSame($expected, resolve_graduation_year($date, $year));
    }

    public function testEditingAStagedRowTakesTheYearFromTheDate(): void
    {
        start_extraction_review(1, [['graduate_name' => 'Old Name', 'latin_honor' => 'CUM LAUDE', 'graduation_date' => null, 'graduation_year' => 2024]]);

        $result = set_staged_row(1, 0, ['graduate_name' => 'Juan Cruz', 'latin_honor' => 'CUM LAUDE', 'graduation_date' => '2026-06-03', 'graduation_year' => 2024]);

        $this->assertTrue($result['ok']);
        $row = get_extraction_review(1)['rows'][0];
        $this->assertSame('2026-06-03', $row['graduation_date']);
        $this->assertSame(2026, $row['graduation_year']);
    }

    public function testSavingARowWhoseYearDisagreesWithItsDateStoresTheDatesYear(): void
    {
        $pdo = TestDb::pdo();
        $rows = [
            ['graduate_name' => 'Mismatch, One', 'course' => 'BSA', 'latin_honor' => 'CUM LAUDE', 'graduation_date' => '2026-06-03', 'graduation_year' => 2024, 'source' => 'ai'],
            ['graduate_name' => 'Undated, Two', 'course' => 'BSA', 'latin_honor' => 'CUM LAUDE', 'graduation_date' => null, 'graduation_year' => 2025, 'source' => 'ai'],
        ];

        $this->assertSame('success', save_staged_records($pdo, 1, 1, $rows, 1)['status']);

        $saved = $pdo->query('SELECT graduate_name, graduation_date, graduation_year FROM latin_honor_graduates ORDER BY id')->fetchAll();
        $this->assertSame('2026', $saved[0]['graduation_year']);
        $this->assertSame('2025', $saved[1]['graduation_year']);
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM latin_honor_graduates WHERE graduation_date IS NOT NULL AND YEAR(graduation_date) <> graduation_year')->fetchColumn());
    }

    public function testReEditingASavedRecordThroughTheReviewAlsoKeepsThemInStep(): void
    {
        $pdo = TestDb::pdo();
        save_staged_records($pdo, 1, 1, [['graduate_name' => 'Mismatch, One', 'course' => '', 'latin_honor' => 'CUM LAUDE', 'graduation_date' => '2024-06-03', 'graduation_year' => 2024, 'source' => 'ai']], 1);
        $id = (int) $pdo->query('SELECT id FROM latin_honor_graduates')->fetchColumn();

        save_staged_records($pdo, 1, 1, [['id' => $id, 'graduate_name' => 'Mismatch, One', 'course' => '', 'latin_honor' => 'CUM LAUDE', 'graduation_date' => '2026-06-03', 'graduation_year' => 2024, 'source' => 'manual']], 1);

        $this->assertSame('2026', $pdo->query("SELECT graduation_year FROM latin_honor_graduates WHERE id = {$id}")->fetchColumn());
    }

    public function testTheBulkDateUpdateRefusesADateFromAnotherYear(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO latin_honor_graduates (upload_id, school_id, graduate_name, course, latin_honor, graduation_date, graduation_year) VALUES (1, 1, 'No Date', '', 'CUM LAUDE', NULL, 2025)");

        $result = bulk_update_graduation_date($pdo, 1, 2025, '2026-06-15');

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('2025', $result['message']);
        $this->assertNull($pdo->query('SELECT graduation_date FROM latin_honor_graduates')->fetchColumn());

        $this->assertSame('success', bulk_update_graduation_date($pdo, 1, 2025, '2025-06-15')['status']);
        $this->assertSame('2025-06-15', $pdo->query('SELECT graduation_date FROM latin_honor_graduates')->fetchColumn());
    }
}
