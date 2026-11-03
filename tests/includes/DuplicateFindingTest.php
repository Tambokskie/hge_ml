<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;
use Tests\TestDb;

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/graduates.php';
require_once __DIR__ . '/../../includes/uploads.php';
require_once __DIR__ . '/../../includes/extraction_review.php';

/** Confirmed duplicates: the review record is removed and the saved original is tagged with what was found. */
class DuplicateFindingTest extends TestCase
{
    protected function setUp(): void
    {
        TestDb::resetSchema();
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO users (name, username, email, password, role) VALUES ('Admin', 'admin', 'a@a.com', 'x', 'administrator')");
        $pdo->exec("INSERT INTO schools (school_name, campus, status) VALUES ('Eastern University', 'MAIN', 'active'), ('Visayas University', 'NORTH', 'active')");
        // upload 1 holds the ORIGINALS, upload 2 is the list under review
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number, extraction_status)
             VALUES (1, '2024', 'first.pdf', 'first.pdf', '/x/first.pdf', 10, SHA2('a',256), 1, 1, 'reviewed'),
                    (2, '2025', 'second.pdf', 'second.pdf', '/x/second.pdf', 10, SHA2('b',256), 1, 1, 'extracted')"
        );
        $pdo->exec(
            "INSERT INTO latin_honor_graduates (upload_id, school_id, graduate_name, course, latin_honor, graduation_date, graduation_year)
             VALUES (1, 1, 'Dela Cruz, Juan A.', 'BS Accountancy', 'CUM LAUDE', '2024-06-02', 2024),
                    (1, 1, 'Santos, Maria', 'BSN', 'CUM LAUDE', '2024-06-02', 2024)"
        );
    }

    private function row(string $name, string $date = '2025-06-02', string $course = 'BSA', string $honor = 'MAGNA CUM LAUDE'): array
    {
        return ['graduate_name' => $name, 'course' => $course, 'latin_honor' => $honor, 'graduation_date' => $date, 'graduation_year' => (string) (int) substr($date, 0, 4), 'source' => 'manual'];
    }

    private function source(): array
    {
        return ['upload_id' => 2, 'school' => 'Visayas University-NORTH', 'filename' => 'second.pdf'];
    }

    private function findings(): array
    {
        return TestDb::pdo()->query('SELECT * FROM graduate_duplicate_findings ORDER BY id')->fetchAll();
    }

    public function testAConfirmedDuplicateIsStoredAgainstTheFirstSavedRecordOfThatName(): void
    {
        $pdo = TestDb::pdo();

        $result = record_duplicate_findings($pdo, $this->source(), [$this->row('DELA CRUZ JUAN A', '2025-06-02', 'BSA')], 1);

        $this->assertSame(['tagged' => 1, 'untagged' => 0, 'originals' => 1], $result);
        $findings = $this->findings();
        $this->assertCount(1, $findings);
        $this->assertSame(1, (int) $findings[0]['original_graduate_id']);
        $this->assertSame('Visayas University-NORTH', $findings[0]['school']);
        $this->assertSame('BSA', $findings[0]['course']);
        $this->assertSame('2025', $findings[0]['graduation_year']);
        $this->assertSame('2025-06-02', $findings[0]['graduation_date']);
        $this->assertSame('MAGNA CUM LAUDE', $findings[0]['latin_honor']);
        $this->assertSame('second.pdf', $findings[0]['source_filename']);
        $this->assertSame('DELA CRUZ JUAN A', $findings[0]['duplicate_name']);
        $this->assertSame(1, (int) $findings[0]['confirmed_by']);
        // nothing is saved for the duplicate itself: only the original exists in the saved records
        $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM latin_honor_graduates')->fetchColumn());
    }

    public function testARowWithNoSavedOriginalIsNotTagged(): void
    {
        $result = record_duplicate_findings(TestDb::pdo(), $this->source(), [$this->row('Nobody, Known')], 1);

        $this->assertSame(['tagged' => 0, 'untagged' => 1, 'originals' => 0], $result);
        $this->assertSame([], $this->findings());
    }

    public function testSeveralDuplicatesOfOneOriginalAccumulate(): void
    {
        $pdo = TestDb::pdo();
        record_duplicate_findings($pdo, $this->source(), [$this->row('Dela Cruz, Juan A.'), $this->row('DELA CRUZ JUAN A', '2026-01-05')], 1);
        record_duplicate_findings($pdo, ['upload_id' => 3, 'school' => 'Another School', 'filename' => 'third.pdf'], [$this->row('dela cruz juan a')], 1);

        $this->assertCount(3, $this->findings());
        $summary = get_duplicate_findings_summary($pdo, [1, 2]);
        $this->assertSame([1], array_keys($summary));
        $this->assertSame(3, $summary[1]['count']);
        $this->assertStringContainsString('DUPLICATE FOUND 3 TIMES', $summary[1]['tooltip']);
        $this->assertStringContainsString('ANOTHER SCHOOL', $summary[1]['tooltip']);
        $this->assertStringContainsString('THIRD.PDF', $summary[1]['tooltip']);
    }

    public function testTheTooltipNamesSchoolCourseYearAndDateGraduated(): void
    {
        $pdo = TestDb::pdo();
        record_duplicate_findings($pdo, $this->source(), [$this->row('Dela Cruz, Juan A.', '2025-06-02', 'BSA')], 1);

        $tooltip = get_duplicate_findings_summary($pdo, [1])[1]['tooltip'];

        $this->assertStringContainsString('DUPLICATE FOUND 1 TIME.', $tooltip);
        $this->assertStringContainsString('VISAYAS UNIVERSITY-NORTH', $tooltip);
        $this->assertStringContainsString('BSA', $tooltip);
        $this->assertStringContainsString('YEAR GRADUATED 2025', $tooltip);
        $this->assertStringContainsString('DATE GRADUATED 02 JUNE 2025', $tooltip);
    }

    public function testTheDetailsShowTheOriginalAndEveryFindingNewestFirst(): void
    {
        $pdo = TestDb::pdo();
        record_duplicate_findings($pdo, $this->source(), [$this->row('Dela Cruz, Juan A.', '2025-06-02', 'FIRST FOUND')], 1);
        record_duplicate_findings($pdo, $this->source(), [$this->row('Dela Cruz, Juan A.', '2026-02-02', 'SECOND FOUND')], 1);
        $pdo->exec("UPDATE graduate_duplicate_findings SET found_at = '2026-01-01 10:00:00' WHERE course = 'FIRST FOUND'");
        $pdo->exec("UPDATE graduate_duplicate_findings SET found_at = '2026-03-01 10:00:00' WHERE course = 'SECOND FOUND'");

        $details = get_duplicate_finding_details($pdo, 1);

        $this->assertSame('Dela Cruz, Juan A.', $details['name']);
        $this->assertSame('Eastern University-MAIN', $details['original']['school']);
        $this->assertSame('BS Accountancy', $details['original']['course']);
        $this->assertSame('2024-06-02', $details['original']['graduation_date']);
        $this->assertSame('first.pdf', $details['original']['filename']);
        $this->assertSame(['SECOND FOUND', 'FIRST FOUND'], array_column($details['findings'], 'course'));
        $this->assertNull(get_duplicate_finding_details($pdo, 2), 'a record with no finding has nothing to show');
    }

    public function testTheOriginalIsReadLiveAndTheFindingsSurviveARename(): void
    {
        $pdo = TestDb::pdo();
        record_duplicate_findings($pdo, $this->source(), [$this->row('Dela Cruz, Juan A.')], 1);
        $pdo->exec("UPDATE latin_honor_graduates SET course = 'CORRECTED COURSE', school_id = 2 WHERE id = 1");

        $details = get_duplicate_finding_details($pdo, 1);

        $this->assertSame('CORRECTED COURSE', $details['original']['course']);
        $this->assertSame('Visayas University-NORTH', $details['original']['school']);
        $this->assertCount(1, $details['findings']);
    }

    public function testLookUpRowsCarryTheFindingCountAndTooltipOnlyOnTheOriginal(): void
    {
        $pdo = TestDb::pdo();
        record_duplicate_findings($pdo, $this->source(), [$this->row('Dela Cruz, Juan A.')], 1);

        $rows = array_column(search_graduates($pdo, [], 1, 50)['rows'], null, 'id');

        $this->assertSame(1, $rows[1]['duplicate_findings']);
        $this->assertStringContainsString('DUPLICATE FOUND 1 TIME', $rows[1]['duplicate_tooltip']);
        $this->assertSame(0, $rows[2]['duplicate_findings']);
        $this->assertSame('', $rows[2]['duplicate_tooltip']);
    }

    public function testAMissingTableStoresNothingAndTheLookUpStillWorks(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec('DROP TABLE graduate_duplicate_findings');

        $this->assertFalse(duplicate_findings_table_exists($pdo));
        $this->assertSame([], get_duplicate_findings_summary($pdo, [1]));
        $this->assertSame(0, search_graduates($pdo, [], 1, 50)['rows'][0]['duplicate_findings']);
        $this->assertNull(get_duplicate_finding_details($pdo, 1));

        TestDb::resetSchema();
    }

    public function testAFailedWriteStoresNoFindingsAtAll(): void
    {
        $pdo = TestDb::pdo();
        // the second row's name is too long for the column; the first row must not stay behind
        $rows = [$this->row('Dela Cruz, Juan A.'), $this->row('Santos, Maria')];
        $rows[1]['course'] = str_repeat('X', 300);

        $previousMode = $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
        $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES'");
        try {
            record_duplicate_findings($pdo, $this->source(), $rows, 1);
            $this->fail('the oversized course should have failed the write');
        } catch (\Throwable $e) {
            $this->assertSame([], $this->findings());
            $this->assertFalse($pdo->inTransaction());
        } finally {
            $pdo->exec('SET SESSION sql_mode = ' . $pdo->quote((string) $previousMode));
        }
    }
}
