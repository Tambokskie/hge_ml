<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;
use Tests\TestDb;

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/graduates.php';
require_once __DIR__ . '/../../includes/duplicates.php';

class StagedDuplicateCheckTest extends TestCase
{
    private function row(string $name, string $course = 'BSA', string $honor = 'CUM LAUDE', int $year = 2025): array
    {
        return ['graduate_name' => $name, 'course' => $course, 'latin_honor' => $honor, 'graduation_year' => $year, 'graduation_date' => null];
    }

    private function saved(string $school = 'XYZ-MAIN'): array
    {
        return ['graduate_name' => 'DELA CRUZ, JUAN', 'course' => 'BSA', 'latin_honor' => 'CUM LAUDE', 'graduation_year' => 2024, 'school' => $school, 'original_filename' => 'old.pdf', 'uploaded_at' => '2025-01-02 03:04:05'];
    }

    public function testNoDuplicatesGivesNoGroups(): void
    {
        $this->assertSame([], find_staged_duplicate_groups([$this->row('DELA CRUZ, JUAN'), $this->row('REYES, ANA')], []));
    }

    public function testTheSameNameTwiceInTheReviewIsOneGroupWhateverTheCourse(): void
    {
        $rows = [$this->row('DELA CRUZ, JUAN', 'BSA'), $this->row('REYES, ANA'), $this->row('Dela Cruz Juan.', 'Bachelor of Science in Accountancy', 'MAGNA CUM LAUDE', 2024)];

        $groups = find_staged_duplicate_groups($rows, []);

        $this->assertCount(1, $groups);
        $this->assertSame('DELA CRUZ, JUAN', $groups[0]['name']);
        $this->assertSame([], $groups[0]['saved']);
        $this->assertSame([[0, 1], [2, 3]], array_map(static fn (array $s): array => [$s['row_index'], $s['row_number']], $groups[0]['staged']));
        $this->assertSame('MAGNA CUM LAUDE', $groups[0]['staged'][1]['latin_honor'], 'honor, course and year do not have to match');
    }

    public function testADifferentNameIsNeverADuplicateEvenWithTheSameCourse(): void
    {
        $this->assertSame([], find_staged_duplicate_groups([$this->row('DELA CRUZ, JUAN', 'BSA'), $this->row('DELA CRUZ, JOSE', 'BSA')], []));
    }

    public function testASavedCopyMakesAGroupEvenWhenTheRowAppearsOnce(): void
    {
        $key = duplicate_group_key($this->row('Dela Cruz, Juan'));

        $groups = find_staged_duplicate_groups([$this->row('Dela Cruz, Juan'), $this->row('REYES, ANA')], [$key => [$this->saved('XYZ-MAIN'), $this->saved('LMN-HINUNANGAN')]]);

        $this->assertCount(1, $groups);
        $this->assertSame(['XYZ-MAIN', 'LMN-HINUNANGAN'], array_column($groups[0]['saved'], 'school'));
        $this->assertCount(1, $groups[0]['staged']);
    }

    public function testBlankNamesAreNeverGrouped(): void
    {
        $this->assertSame([], find_staged_duplicate_groups([$this->row(''), $this->row('  '), $this->row('...')], []));
    }

    public function testTheLookupFindsSavedCopiesAtAnySchoolCourseYearAndHonorAndPutsTheAppliedOneFirst(): void
    {
        TestDb::resetSchema();
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO users (name, username, email, password, role) VALUES ('Admin', 'admin', 'a@a.com', 'x', 'administrator')");
        $pdo->exec("INSERT INTO schools (school_name, campus) VALUES ('ABC UNIVERSITY', 'MAIN'), ('XYZ COLLEGE', 'HINUNANGAN')");
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number, extraction_status, records_extracted)
             VALUES (1, '2026', 'under_review.pdf', 'r.pdf', '/x/r.pdf', 10, SHA2('a',256), 1, 1, 'draft', 0),
                    (2, '2024', 'other.pdf', 'o.pdf', '/x/o.pdf', 10, SHA2('b',256), 1, 1, 'reviewed', 3)"
        );
        $pdo->exec(
            "INSERT INTO latin_honor_graduates (upload_id, school_id, graduate_name, course, latin_honor, graduation_year) VALUES
             (2, 2, 'MENDOZA, DUSTIN JUN M.', 'BSIT', 'MAGNA CUM LAUDE', 2024),
             (2, 2, 'MENDOZA, DUSTIN JUN M.', 'Bachelor of Science in Accountancy', 'CUM LAUDE', 2024),
             (1, 1, 'MENDOZA, DUSTIN JUN M.', 'BSIT', 'CUM LAUDE', 2026)"
        );
        $appliedId = (int) $pdo->query("SELECT id FROM latin_honor_graduates WHERE course LIKE 'Bachelor%'")->fetchColumn();
        $pdo->exec("INSERT INTO graduate_applications (graduate_id, applied_by, application_date) VALUES ({$appliedId}, 1, '2026-06-03')");

        $found = find_saved_duplicates_by_name($pdo, 1);

        $key = duplicate_group_key($this->row('Mendoza, Dustin Jun M.'));
        $this->assertSame([$key], array_keys($found));
        $this->assertCount(2, $found[$key], 'both saved copies of the other upload; the upload under review is excluded');
        $this->assertSame([true, false], array_column($found[$key], 'has_applied'), 'the applied copy prevails, so it is listed first');
        $this->assertStringContainsString('XYZ COLLEGE', $found[$key][0]['school']);

        $groups = find_staged_duplicate_groups([$this->row('Mendoza, Dustin Jun M.', 'BSIT', 'MAGNA CUM LAUDE', 2026)], $found);
        $this->assertCount(1, $groups);
        $this->assertTrue($groups[0]['saved'][0]['has_applied']);
    }

    public function testTheLookupIsEmptyWhenNothingElseIsSaved(): void
    {
        TestDb::resetSchema();

        $this->assertSame([], find_saved_duplicates_by_name(TestDb::pdo(), 1));
    }
}
