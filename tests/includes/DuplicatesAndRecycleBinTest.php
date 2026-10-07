<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;
use Tests\TestDb;

require_once __DIR__ . '/../../includes/graduates.php';
require_once __DIR__ . '/../../includes/duplicates.php';
require_once __DIR__ . '/../../includes/recycle_bin.php';
require_once __DIR__ . '/../../includes/uploads.php';
require_once __DIR__ . '/../../includes/extraction_review.php';

class DuplicatesAndRecycleBinTest extends TestCase
{
    protected function setUp(): void
    {
        TestDb::resetSchema();
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO users (name, username, email, password, role) VALUES ('Admin', 'admin', 'a@a.com', 'x', 'administrator')");
        $pdo->exec("INSERT INTO schools (school_name, campus) VALUES ('ABC UNIVERSITY', 'MAIN'), ('XYZ COLLEGE', NULL)");
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number, extraction_status, records_extracted)
             VALUES (1, '2025', 'a.pdf', 'a.pdf', '/x/a.pdf', 10, SHA2('a',256), 1, 1, 'reviewed', 2),
                    (1, '2025', 'a2.pdf', 'a2.pdf', '/x/a2.pdf', 10, SHA2('a2',256), 1, 2, 'reviewed', 1),
                    (2, '2025', 'b.pdf', 'b.pdf', '/x/b.pdf', 10, SHA2('b',256), 1, 1, 'reviewed', 1)"
        );
    }

    private function addGraduate(int $uploadId, int $schoolId, string $name, string $course = 'BSA', string $honor = 'CUM LAUDE', int $year = 2025): int
    {
        $pdo = TestDb::pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO latin_honor_graduates (upload_id, school_id, graduate_name, course, latin_honor, graduation_year)
             VALUES (:u, :s, :n, :c, :h, :y)'
        );
        $stmt->execute(['u' => $uploadId, 's' => $schoolId, 'n' => $name, 'c' => $course, 'h' => $honor, 'y' => $year]);

        return (int) $pdo->lastInsertId();
    }

    public function testExactDuplicatesAreGrouped(): void
    {
        $a = $this->addGraduate(1, 1, 'COQUILLA, KIM L.');
        $b = $this->addGraduate(2, 1, 'COQUILLA, KIM L.');

        $groups = find_duplicate_groups(TestDb::pdo());

        $this->assertCount(1, $groups);
        $this->assertSame([$a, $b], array_map(static fn ($m) => (int) $m['id'], $groups[0]['members']));
    }

    public function testNormalizationIgnoresCaseSpacingAndPunctuation(): void
    {
        $this->addGraduate(1, 1, 'Coquilla,  Kim L.');
        $this->addGraduate(2, 1, 'COQUILLA KIM L');

        $this->assertCount(1, find_duplicate_groups(TestDb::pdo()));
    }

    public function testDifferentCourseHonorYearOrSchoolAreNotDuplicates(): void
    {
        $this->addGraduate(1, 1, 'SAME NAME', 'BSA');
        $this->addGraduate(1, 1, 'SAME NAME', 'BSIT');
        $this->addGraduate(1, 1, 'SAME NAME', 'BSA', 'MAGNA CUM LAUDE');
        $this->addGraduate(1, 1, 'SAME NAME', 'BSA', 'CUM LAUDE', 2024);
        $this->addGraduate(3, 2, 'SAME NAME', 'BSA');

        $this->assertSame([], find_duplicate_groups(TestDb::pdo()));
    }

    public function testKeeperIsCopyWithFiledApplicationElseOldest(): void
    {
        $pdo = TestDb::pdo();
        $first = $this->addGraduate(1, 1, 'DUP PERSON');
        $second = $this->addGraduate(2, 1, 'DUP PERSON');
        $pdo->exec("INSERT INTO graduate_applications (graduate_id, applied_by, application_date) VALUES ({$second}, 1, '2025-07-01')");

        $members = find_duplicate_groups($pdo)[0]['members'];

        $this->assertSame($second, (int) $members[0]['id']);
        $this->assertSame($first, (int) $members[1]['id']);
    }

    public function testCountDuplicatesForUploadOnlyCountsGroupsTouchingThatUpload(): void
    {
        $this->addGraduate(1, 1, 'ONE');
        $this->addGraduate(1, 1, 'ONE');
        $this->addGraduate(2, 1, 'TWO');
        $this->addGraduate(2, 1, 'TWO');
        $this->addGraduate(1, 1, 'UNIQUE');

        $this->assertSame(1, count_duplicates_for_upload(TestDb::pdo(), 1));
        $this->assertSame(1, count_duplicates_for_upload(TestDb::pdo(), 2));
    }

    public function testFlagDuplicatesForRowsReturnsOnlyDuplicateIds(): void
    {
        $dupA = $this->addGraduate(1, 1, 'DUP');
        $dupB = $this->addGraduate(2, 1, 'DUP');
        $solo = $this->addGraduate(1, 1, 'SOLO');

        $flagged = flag_duplicates_for_rows(TestDb::pdo(), [$dupA, $dupB, $solo]);

        $this->assertEqualsCanonicalizing([$dupA, $dupB], $flagged);
        $this->assertSame([], flag_duplicates_for_rows(TestDb::pdo(), []));
    }

    public function testDeleteGraduateMovesRowToRecycleBinAndDecrementsCount(): void
    {
        $pdo = TestDb::pdo();
        $id = $this->addGraduate(1, 1, 'TO REMOVE');

        $result = delete_graduate($pdo, $id, 1, 'DUPLICATE ENTRY', 'duplicate_cleanup');

        $this->assertTrue($result['ok']);
        $this->assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM latin_honor_graduates WHERE id = {$id}")->fetchColumn());
        $bin = $pdo->query('SELECT * FROM graduate_recycle_bin')->fetchAll();
        $this->assertCount(1, $bin);
        $this->assertSame($id, (int) $bin[0]['original_id']);
        $this->assertSame('TO REMOVE', $bin[0]['graduate_name']);
        $this->assertSame('DUPLICATE ENTRY', $bin[0]['deleted_reason']);
        $this->assertSame('duplicate_cleanup', $bin[0]['deleted_via']);
        $this->assertSame(1, (int) $pdo->query('SELECT records_extracted FROM latin_honor_uploads WHERE id = 1')->fetchColumn());
    }

    public function testRestoreRoundTripsUnderOriginalIdAndRestoresCount(): void
    {
        $pdo = TestDb::pdo();
        $id = $this->addGraduate(1, 1, 'COMES BACK', 'BSBA', 'MAGNA CUM LAUDE');
        delete_graduate($pdo, $id, 1, 'MISTAKE');
        $binId = (int) $pdo->query('SELECT id FROM graduate_recycle_bin')->fetchColumn();

        $result = restore_graduate($pdo, $binId);

        $this->assertTrue($result['ok']);
        $row = get_graduate_by_id($pdo, $id);
        $this->assertSame('COMES BACK', $row['graduate_name']);
        $this->assertSame('MAGNA CUM LAUDE', $row['latin_honor']);
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM graduate_recycle_bin')->fetchColumn());
        $this->assertSame(2, (int) $pdo->query('SELECT records_extracted FROM latin_honor_uploads WHERE id = 1')->fetchColumn());
    }

    public function testRestoreFailsGracefullyWhenOriginalUploadIsGone(): void
    {
        $pdo = TestDb::pdo();
        $id = $this->addGraduate(2, 1, 'ORPHAN');
        delete_graduate($pdo, $id, 1, 'x');
        $pdo->exec('UPDATE latin_honor_uploads SET records_extracted = 0 WHERE id = 2');
        $pdo->exec('DELETE FROM latin_honor_uploads WHERE id = 2');
        $binId = (int) $pdo->query('SELECT id FROM graduate_recycle_bin')->fetchColumn();

        $result = restore_graduate($pdo, $binId);

        $this->assertFalse($result['ok']);
        $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM graduate_recycle_bin')->fetchColumn());
    }

    public function testRestoreUnknownItemFails(): void
    {
        $this->assertFalse(restore_graduate(TestDb::pdo(), 999)['ok']);
    }

    public function testPurgeRemovesOnlyTheBinRow(): void
    {
        $pdo = TestDb::pdo();
        $keep = $this->addGraduate(1, 1, 'STAYS');
        $gone = $this->addGraduate(1, 1, 'PURGED');
        delete_graduate($pdo, $gone, 1, 'x');
        $binId = (int) $pdo->query('SELECT id FROM graduate_recycle_bin')->fetchColumn();

        $this->assertSame('PURGED', purge_recycle_bin_item($pdo, $binId));
        $this->assertNull(purge_recycle_bin_item($pdo, $binId));
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM graduate_recycle_bin')->fetchColumn());
        $this->assertNotNull(get_graduate_by_id($pdo, $keep));
    }

    public function testGraduateWithFiledApplicationIsNotArchivedOrDeleted(): void
    {
        $pdo = TestDb::pdo();
        $id = $this->addGraduate(1, 1, 'APPLIED');
        $pdo->exec("INSERT INTO graduate_applications (graduate_id, applied_by, application_date) VALUES ({$id}, 1, '2025-07-01')");

        $result = delete_graduate($pdo, $id, 1, 'x');

        $this->assertFalse($result['ok']);
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM graduate_recycle_bin')->fetchColumn());
        $this->assertNotNull(get_graduate_by_id($pdo, $id));
    }

    public function testReReviewRemovalArchivesDroppedRows(): void
    {
        $pdo = TestDb::pdo();
        $keep = $this->addGraduate(1, 1, 'KEEP ME');
        $drop = $this->addGraduate(1, 1, 'DROP ME');

        $rows = [[
            'id' => $keep, 'graduate_name' => 'KEEP ME', 'course' => 'BSA', 'latin_honor' => 'CUM LAUDE',
            'graduation_date' => null, 'graduation_year' => 2025, 'source' => null,
        ]];
        $result = save_staged_records($pdo, 1, 1, $rows, 1);

        $this->assertSame('success', $result['status']);
        $bin = $pdo->query('SELECT original_id, deleted_via FROM graduate_recycle_bin')->fetchAll();
        $this->assertCount(1, $bin);
        $this->assertSame($drop, (int) $bin[0]['original_id']);
        $this->assertSame('review_edit', $bin[0]['deleted_via']);
    }

    public function testRemoveDuplicateGraduatesArchivesSelectedCopiesAndKeepsOne(): void
    {
        $pdo = TestDb::pdo();
        $keep = $this->addGraduate(1, 1, 'DUP PERSON');
        $extra = $this->addGraduate(2, 1, 'DUP PERSON');

        $result = remove_duplicate_graduates($pdo, [$extra], 1, 'DUPLICATE ENTRY');

        $this->assertSame(1, $result['removed']);
        $this->assertSame([], $result['skipped']);
        $this->assertNotNull(get_graduate_by_id($pdo, $keep));
        $this->assertNull(get_graduate_by_id($pdo, $extra));
        $this->assertSame('duplicate_cleanup', $pdo->query('SELECT deleted_via FROM graduate_recycle_bin')->fetchColumn());
    }

    public function testRemoveDuplicateGraduatesRefusesToRemoveEveryCopy(): void
    {
        $pdo = TestDb::pdo();
        $a = $this->addGraduate(1, 1, 'DUP PERSON');
        $b = $this->addGraduate(2, 1, 'DUP PERSON');

        $result = remove_duplicate_graduates($pdo, [$a, $b], 1, 'x');

        $this->assertSame(0, $result['removed']);
        $this->assertNotEmpty($result['skipped']);
        $this->assertNotNull(get_graduate_by_id($pdo, $a));
        $this->assertNotNull(get_graduate_by_id($pdo, $b));
    }

    public function testRemoveDuplicateGraduatesIgnoresRecordsThatAreNotDuplicates(): void
    {
        $pdo = TestDb::pdo();
        $solo = $this->addGraduate(1, 1, 'SOLO');

        $result = remove_duplicate_graduates($pdo, [$solo], 1, 'x');

        $this->assertSame(0, $result['removed']);
        $this->assertNotNull(get_graduate_by_id($pdo, $solo));
    }

    public function testRemoveDuplicateGraduatesSkipsCopyWithFiledApplication(): void
    {
        $pdo = TestDb::pdo();
        $this->addGraduate(1, 1, 'DUP PERSON');
        $applied = $this->addGraduate(2, 1, 'DUP PERSON');
        $this->addGraduate(2, 1, 'DUP PERSON');
        $pdo->exec("INSERT INTO graduate_applications (graduate_id, applied_by, application_date) VALUES ({$applied}, 1, '2025-07-01')");

        $result = remove_duplicate_graduates($pdo, [$applied], 1, 'x');

        $this->assertSame(0, $result['removed']);
        $this->assertNotNull(get_graduate_by_id($pdo, $applied));
    }

    public function testGetRecycleBinItemsFiltersByNameAndPaginates(): void
    {
        $pdo = TestDb::pdo();
        foreach (['ALPHA ONE', 'ALPHA TWO', 'BRAVO'] as $n) {
            $id = $this->addGraduate(1, 1, $n);
            delete_graduate($pdo, $id, 1, 'x');
        }

        $all = get_recycle_bin_items($pdo, [], 1, 2);
        $this->assertSame(3, $all['pagination']['total_rows']);
        $this->assertCount(2, $all['rows']);

        $alpha = get_recycle_bin_items($pdo, ['name' => 'ALPHA'], 1);
        $this->assertCount(2, $alpha['rows']);
    }
}
