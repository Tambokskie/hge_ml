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

    public function testNoDuplicatesGivesNoGroups(): void
    {
        $rows = [$this->row('DELA CRUZ, JUAN'), $this->row('REYES, ANA')];

        $this->assertSame([], find_staged_duplicate_groups($rows, []));
    }

    public function testTheSameNameTwiceInTheReviewIsOneGroupWithBothRows(): void
    {
        $rows = [$this->row('DELA CRUZ, JUAN'), $this->row('REYES, ANA'), $this->row('Dela Cruz Juan.', 'BSIT', 'MAGNA CUM LAUDE')];

        $groups = find_staged_duplicate_groups($rows, []);

        $this->assertCount(1, $groups);
        $this->assertSame('DELA CRUZ, JUAN', $groups[0]['name']);
        $this->assertNull($groups[0]['saved']);
        $this->assertSame([[0, 1], [2, 3]], array_map(static fn (array $s): array => [$s['row_index'], $s['row_number']], $groups[0]['staged']));
        $this->assertSame('BSIT', $groups[0]['staged'][1]['course']);
    }

    public function testANameAlreadySavedForTheSchoolIsFlaggedEvenWhenItAppearsOnce(): void
    {
        $saved = ['DELA CRUZ JUAN' => ['graduate_name' => 'Dela Cruz, Juan', 'course' => 'BSA', 'latin_honor' => 'CUM LAUDE', 'upload_id' => 7, 'original_filename' => 'old.pdf', 'uploaded_at' => '2025-01-02 03:04:05']];

        $groups = find_staged_duplicate_groups([$this->row('DELA CRUZ, JUAN'), $this->row('REYES, ANA')], $saved);

        $this->assertCount(1, $groups);
        $this->assertSame('old.pdf', $groups[0]['saved']['original_filename']);
        $this->assertCount(1, $groups[0]['staged']);
    }

    public function testBlankNamesAreNeverGrouped(): void
    {
        $this->assertSame([], find_staged_duplicate_groups([$this->row(''), $this->row('  '), $this->row('...')], []));
    }

    public function testFindPossibleDuplicateGraduatesFeedsTheCheckFromTheDatabase(): void
    {
        TestDb::resetSchema();
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO users (name, username, email, password, role) VALUES ('Admin', 'admin', 'a@a.com', 'x', 'administrator')");
        $pdo->exec("INSERT INTO schools (school_name, campus) VALUES ('ABC UNIVERSITY', 'MAIN')");
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number, extraction_status, records_extracted)
             VALUES (1, '2025', 'old.pdf', 'old.pdf', '/x/old.pdf', 10, SHA2('a',256), 1, 1, 'reviewed', 1),
                    (1, '2025', 'new.pdf', 'new.pdf', '/x/new.pdf', 10, SHA2('b',256), 1, 2, 'extracted', 0)"
        );
        $pdo->exec("INSERT INTO latin_honor_graduates (upload_id, school_id, graduate_name, course, latin_honor, graduation_year) VALUES (1, 1, 'DELA CRUZ, JUAN', 'BSA', 'CUM LAUDE', 2025)");

        $saved = find_possible_duplicate_graduates($pdo, 1, '2025', 2);
        $groups = find_staged_duplicate_groups([$this->row('Dela Cruz, Juan'), $this->row('REYES, ANA')], $saved);

        $this->assertCount(1, $groups);
        $this->assertSame('old.pdf', $groups[0]['saved']['original_filename']);
    }
}
