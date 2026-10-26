<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;
use Tests\TestDb;

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/duplicates.php';
require_once __DIR__ . '/../../includes/bulk_hge.php';

class BulkHgeMatchTest extends TestCase
{
    protected function setUp(): void
    {
        TestDb::resetSchema();
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO users (name, username, email, password, role) VALUES ('Admin', 'admin', 'a@a.com', 'x', 'administrator')");
        $pdo->exec("INSERT INTO schools (school_name, campus) VALUES ('ABC UNIVERSITY', 'MAIN'), ('XYZ COLLEGE', 'MAIN')");
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number, extraction_status, records_extracted)
             VALUES (1, '2025', 'a.pdf', 'a.pdf', '/x/a.pdf', 10, SHA2('a',256), 1, 1, 'reviewed', 2),
                    (1, '2025', 'a2.pdf', 'a2.pdf', '/x/a2.pdf', 10, SHA2('a2',256), 1, 2, 'reviewed', 1),
                    (2, '2025', 'b.pdf', 'b.pdf', '/x/b.pdf', 10, SHA2('b',256), 1, 1, 'reviewed', 1)"
        );
    }

    private function addGraduate(string $name, int $uploadId = 1, int $schoolId = 1, string $course = 'BSA'): int
    {
        $pdo = TestDb::pdo();
        $stmt = $pdo->prepare(
            "INSERT INTO latin_honor_graduates (upload_id, school_id, graduate_name, course, latin_honor, graduation_year)
             VALUES (:u, :s, :n, :c, 'CUM LAUDE', 2020)"
        );
        $stmt->execute(['u' => $uploadId, 's' => $schoolId, 'n' => $name, 'c' => $course]);

        return (int) $pdo->lastInsertId();
    }

    private function fileApplication(int $graduateId, string $date = '2025-01-01'): void
    {
        TestDb::pdo()->prepare('INSERT INTO graduate_applications (graduate_id, applied_by, application_date) VALUES (?, 1, ?)')
            ->execute([$graduateId, $date]);
    }

    private function fileAndApproveApplication(int $graduateId, string $date = '2025-01-01'): void
    {
        TestDb::pdo()->prepare('INSERT INTO graduate_applications (graduate_id, applied_by, application_date, approved_at, approved_by) VALUES (?, 1, ?, ?, 1)')
            ->execute([$graduateId, $date, $date]);
    }

    private function person(string $last, string $first, string $mi = '', string $middle = '', string $ext = '', string $date = '2024-04-02', int $row = 2): array
    {
        return compact('row', 'date', 'last', 'first', 'mi', 'middle', 'ext');
    }

    private function match(array $person): array
    {
        return bulk_hge_match_person($person, bulk_hge_load_graduate_index(TestDb::pdo()));
    }

    public function testMatchesCommaAndNoCommaNameShapes(): void
    {
        $a = $this->addGraduate('SANDAGAN, NILVEN A.');
        $b = $this->addGraduate('Charisse Ella M. Montañez', 2);

        $this->assertSame(['confident', $a], [$this->match($this->person('Sandagan', 'Nilven', 'A', 'Arozado'))['status'], $this->match($this->person('Sandagan', 'Nilven', 'A', 'Arozado'))['graduate_id']]);
        $this->assertSame($b, $this->match($this->person('MONTAÑEZ', 'CHARISSE ELLA', 'M', 'Mantua'))['graduate_id']);
    }

    public function testMiddleNameMayBeAnInitialOrTheFullWord(): void
    {
        $initial = $this->addGraduate('CRUZ, JUAN D.');
        $full = $this->addGraduate('CRUZ, MARIA SANTOS', 2);

        $this->assertSame($initial, $this->match($this->person('Cruz', 'Juan', 'D', 'Dela'))['graduate_id']);
        $this->assertSame($initial, $this->match($this->person('Cruz', 'Juan', 'D'))['graduate_id']);
        $this->assertSame($full, $this->match($this->person('Cruz', 'Maria', 'S', 'Santos'))['graduate_id']);
        $this->assertSame($full, $this->match($this->person('Cruz', 'Maria', '', 'Santos'))['graduate_id']);
    }

    public function testWrongMiddleInitialDoesNotMatch(): void
    {
        $this->addGraduate('CRUZ, JUAN D.');

        $this->assertSame('not_found', $this->match($this->person('Cruz', 'Juan', 'X', 'Xavier'))['status']);
    }

    public function testMissingNameTokenOrDifferentPersonIsNotFound(): void
    {
        $this->addGraduate('CRUZ, JUAN D.');

        $this->assertSame('not_found', $this->match($this->person('Cruz', 'Pedro'))['status']);
        $this->assertSame('not_found', $this->match($this->person('Reyes', 'Juan'))['status']);
        $this->assertSame('not_found', $this->match($this->person('Cruz', 'Juan Carlos', 'D'))['status']);
    }

    public function testHonorificAndStrayGwaTextInTheSavedNameAreIgnored(): void
    {
        $id = $this->addGraduate('Ms. REYES, ANA 1.56');

        $this->assertSame($id, $this->match($this->person('Reyes', 'Ana'))['graduate_id']);
    }

    public function testNeitherSideHavingAMiddleNameIsConfident(): void
    {
        $id = $this->addGraduate('REYES, ANA');

        $this->assertSame(['confident', $id], [$this->match($this->person('Reyes', 'Ana'))['status'], $this->match($this->person('Reyes', 'Ana'))['graduate_id']]);
    }

    public function testSavedRecordWithoutMiddleNameButFileHasOneIsLoose(): void
    {
        $id = $this->addGraduate('REYES, ANA');

        $match = $this->match($this->person('Reyes', 'Ana', 'B', 'Bautista'));

        $this->assertSame('loose', $match['status']);
        $this->assertSame($id, $match['graduate_id']);
    }

    public function testFileWithoutMiddleNameDoesNotMatchSavedRecordWithExtraTokens(): void
    {
        $this->addGraduate('REYES, ANA BAUTISTA');

        $this->assertSame('not_found', $this->match($this->person('Reyes', 'Ana'))['status']);
    }

    public function testDifferentPeopleSharingTheNameAreAmbiguous(): void
    {
        $this->addGraduate('REYES, ANA B.', 1, 1);
        $this->addGraduate('REYES, ANA B.', 3, 2, 'BSIT');

        $match = $this->match($this->person('Reyes', 'Ana', 'B', 'Bautista'));

        $this->assertSame('ambiguous', $match['status']);
        $this->assertNull($match['graduate_id']);
        $this->assertCount(2, $match['candidates']);
    }

    public function testExactDuplicateCopiesCollapseToTheOldestKeeper(): void
    {
        $first = $this->addGraduate('REYES, ANA B.', 1);
        $this->addGraduate('REYES, ANA B.', 2);

        $match = $this->match($this->person('Reyes', 'Ana', 'B', 'Bautista'));

        $this->assertSame('confident', $match['status']);
        $this->assertSame($first, $match['graduate_id']);
    }

    public function testDuplicateKeeperPrefersTheCopyWithAFiledApplication(): void
    {
        $this->addGraduate('REYES, ANA B.', 1);
        $second = $this->addGraduate('REYES, ANA B.', 2);
        $this->fileApplication($second);

        $match = $this->match($this->person('Reyes', 'Ana', 'B', 'Bautista'));

        $this->assertSame($second, $match['graduate_id']);
    }

    public function testExtensionBreaksATieBetweenSeniorAndJunior(): void
    {
        $this->addGraduate('CRUZ, JUAN SR.', 1, 1, 'BSA');
        $junior = $this->addGraduate('CRUZ, JUAN JR.', 3, 2, 'BSIT');

        $this->assertSame($junior, $this->match($this->person('Cruz', 'Juan', '', '', 'JR'))['graduate_id']);
    }

    // ---- preview

    public function testPreviewSortsPeopleIntoEveryBucket(): void
    {
        $toFile = $this->addGraduate('SANDAGAN, NILVEN A.');
        // Filed but not yet approved: still included in to_file — this
        // batch's date will approve it (see bulk_hge_insert_pairs()'s
        // upsert), it is not skipped.
        $toApprove = $this->addGraduate('CRUZ, JUAN D.');
        $this->fileApplication($toApprove);
        // Already approved: nothing left to do, excluded from to_file.
        $alreadyApproved = $this->addGraduate('DIZON, PEDRO E.');
        $this->fileAndApproveApplication($alreadyApproved);
        $this->addGraduate('REYES, ANA');                 // loose
        $this->addGraduate('LOPEZ, MARK T.', 1, 1, 'BSA'); // ambiguous
        $this->addGraduate('LOPEZ, MARK T.', 3, 2, 'BSIT');

        $people = [
            $this->person('Sandagan', 'Nilven', 'A', 'Arozado', '', '2024-04-02', 2),
            $this->person('Cruz', 'Juan', 'D', 'Dela', '', '2024-05-01', 3),
            $this->person('Dizon', 'Pedro', 'E', 'Enriquez', '', '2024-05-05', 7),
            $this->person('Reyes', 'Ana', 'B', 'Bautista', '', '2024-06-01', 4),
            $this->person('Lopez', 'Mark', 'T', 'Tan', '', '2024-07-01', 5),
            $this->person('Nobody', 'Here', '', '', '', '2024-08-01', 6),
        ];

        $preview = bulk_hge_build_preview(TestDb::pdo(), $people, [['row' => 9, 'message' => 'X', 'name' => 'Y']]);

        $this->assertSame([
            ['graduate_id' => $toFile, 'date' => '2024-04-02'],
            ['graduate_id' => $toApprove, 'date' => '2024-05-01'],
        ], $preview['to_file']);
        $this->assertSame([
            'people' => 6, 'to_file' => 2, 'already_approved' => 1, 'review' => 2, 'not_found' => 1, 'invalid' => 1, 'invalid_date' => 0,
        ], $preview['counts']);
        $this->assertCount(2, $preview['review']);
        $this->assertStringContainsString('LOOSE', $preview['review'][0]['reason']);
        $this->assertStringContainsString('AMBIGUOUS', $preview['review'][1]['reason']);
        $this->assertSame(['REYES, ANA'], $preview['review'][0]['saved_names']);
        $this->assertSame([['name' => 'REYES, ANA', 'school' => 'ABC UNIVERSITY-MAIN', 'year' => '2020']], $preview['review'][0]['saved']);
        $this->assertSame(
            [['LOPEZ, MARK T.', 'ABC UNIVERSITY-MAIN'], ['LOPEZ, MARK T.', 'XYZ COLLEGE-MAIN']],
            array_map(static fn (array $s): array => [$s['name'], $s['school']], $preview['review'][1]['saved'])
        );
    }

    public function testPreviewSendsASavedRecordClaimedByTwoPeopleToReview(): void
    {
        $this->addGraduate('CRUZ, JUAN D.');

        $people = [
            $this->person('Cruz', 'Juan', 'D', 'Dela', '', '2024-04-02', 2),
            $this->person('Cruz', 'Juan', 'D', 'Dizon', '', '2024-04-03', 3),
        ];

        $preview = bulk_hge_build_preview(TestDb::pdo(), $people);

        $this->assertSame([], $preview['to_file']);
        $this->assertSame(2, $preview['counts']['review']);
        $this->assertStringContainsString('CONFLICT', $preview['review'][0]['reason']);
    }

    public function testPreviewNeverWritesAnything(): void
    {
        $this->addGraduate('SANDAGAN, NILVEN A.');

        bulk_hge_build_preview(TestDb::pdo(), [$this->person('Sandagan', 'Nilven', 'A', 'Arozado')]);

        $this->assertSame('0', (string) TestDb::pdo()->query('SELECT COUNT(*) FROM graduate_applications')->fetchColumn());
    }

    public function testEmptyDatabaseMatchesNothing(): void
    {
        $preview = bulk_hge_build_preview(TestDb::pdo(), [$this->person('Cruz', 'Juan')]);

        $this->assertSame(1, $preview['counts']['not_found']);
        $this->assertSame([], $preview['to_file']);
    }
}
