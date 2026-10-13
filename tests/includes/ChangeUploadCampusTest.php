<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;
use Tests\TestDb;

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/uploads.php';

/** Moving an upload (and its saved records) to another campus of the same school. */
class ChangeUploadCampusTest extends TestCase
{
    protected function setUp(): void
    {
        TestDb::resetSchema();
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO users (name, username, email, password, role) VALUES ('Admin', 'admin', 'a@a.com', 'x', 'administrator')");
        // 1 MAIN (the upload's campus), 2 NORTH and 3 an unnamed campus of the same school, 4 a disabled campus, 5 another school
        $pdo->exec(
            "INSERT INTO schools (school_name, campus, status) VALUES
                ('Sample State University', 'MAIN', 'active'),
                ('SAMPLE STATE UNIVERSITY ', 'NORTH', 'active'),
                ('Sample State University', '', 'active'),
                ('Sample State University', 'SOUTH', 'disabled'),
                ('Riverside College', 'MAIN', 'active')"
        );
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number, extraction_status, records_extracted)
             VALUES (1, '2026', 'ssu.pdf', 'ssu.pdf', '/x/ssu.pdf', 10, SHA2('a',256), 1, 1, 'reviewed', 2)"
        );
        $pdo->exec(
            "INSERT INTO latin_honor_graduates (upload_id, school_id, graduate_name, course, latin_honor, graduation_date, graduation_year)
             VALUES (1, 1, 'REYES, ANA', 'BSA', 'CUM LAUDE', '2026-04-01', 2026), (1, 1, 'SANTOS, JOSE', 'BSN', 'CUM LAUDE', '2026-04-01', 2026)"
        );
        $pdo->exec("INSERT INTO graduate_applications (graduate_id, applied_by, application_date) VALUES (1, 1, '2026-06-03')");
    }

    private function schoolOf(string $table, int $id): int
    {
        return (int) TestDb::pdo()->query("SELECT school_id FROM {$table} WHERE id = {$id}")->fetchColumn();
    }

    public function testOnlyActiveCampusesOfTheSameSchoolAreOffered(): void
    {
        $offered = get_other_campuses_for_upload(TestDb::pdo(), 1);

        // the unnamed and NORTH campuses (the school name matches whatever the case or spacing); not the disabled
        // SOUTH campus, not the upload's own campus, and not another school
        $this->assertSame([['id' => 3, 'campus' => ''], ['id' => 2, 'campus' => 'NORTH']], $offered);
    }

    public function testAnUploadMovesWithItsRecordsAndKeepsEverythingElse(): void
    {
        $pdo = TestDb::pdo();

        $result = change_upload_campus($pdo, 1, 2);

        $this->assertTrue($result['ok']);
        $this->assertSame('Sample State University-MAIN', $result['from']);
        $this->assertSame('Sample State University-NORTH', $result['to']);
        $this->assertSame(2, $result['records']);
        $this->assertSame(2, $this->schoolOf('latin_honor_uploads', 1));
        $this->assertSame(2, $this->schoolOf('latin_honor_graduates', 1));
        $this->assertSame(2, $this->schoolOf('latin_honor_graduates', 2));
        // the filed application is untouched
        $this->assertSame('2026-06-03', $pdo->query('SELECT application_date FROM graduate_applications WHERE graduate_id = 1')->fetchColumn());
        $this->assertSame('ssu.pdf', $pdo->query('SELECT stored_filename FROM latin_honor_uploads WHERE id = 1')->fetchColumn());
        $this->assertSame('REYES, ANA', $pdo->query('SELECT graduate_name FROM latin_honor_graduates WHERE id = 1')->fetchColumn());
    }

    public function testRecordsOfTheUploadThatAreInTheRecycleBinFollowIt(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec(
            "INSERT INTO graduate_recycle_bin (original_id, upload_id, school_id, graduate_name, course, latin_honor, graduation_year, deleted_by, deleted_reason)
             VALUES (50, 1, 1, 'GONE, PERSON', 'BSA', 'CUM LAUDE', 2026, 1, 'test'), (51, 99, 5, 'OTHER, UPLOAD', 'BSA', 'CUM LAUDE', 2026, 1, 'test')"
        );

        $this->assertTrue(change_upload_campus($pdo, 1, 3)['ok']);

        $this->assertSame(3, (int) $pdo->query("SELECT school_id FROM graduate_recycle_bin WHERE original_id = 50")->fetchColumn());
        $this->assertSame(5, (int) $pdo->query("SELECT school_id FROM graduate_recycle_bin WHERE original_id = 51")->fetchColumn());
    }

    public function testTheUploadNumberIsKeptUnlessTheNewCampusAlreadyUsesIt(): void
    {
        $pdo = TestDb::pdo();
        // NORTH already has upload number 1 for 2026 (and 2), so the moved upload must take the next free number, 3
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number, extraction_status)
             VALUES (2, '2026', 'n1.pdf', 'n1.pdf', '/x/n1.pdf', 10, SHA2('b',256), 1, 1, 'pending'), (2, '2026', 'n2.pdf', 'n2.pdf', '/x/n2.pdf', 10, SHA2('c',256), 1, 2, 'pending')"
        );

        $this->assertTrue(change_upload_campus($pdo, 1, 2)['ok']);
        $this->assertSame(3, (int) $pdo->query('SELECT upload_number FROM latin_honor_uploads WHERE id = 1')->fetchColumn());

        // the unnamed campus has no upload yet, so the upload keeps the number it now has
        $this->assertTrue(change_upload_campus($pdo, 1, 3)['ok']);
        $this->assertSame(3, (int) $pdo->query('SELECT upload_number FROM latin_honor_uploads WHERE id = 1')->fetchColumn());
        $this->assertSame(3, $this->schoolOf('latin_honor_uploads', 1));
    }

    public function testAnUnclashingNumberIsKept(): void
    {
        $pdo = TestDb::pdo();
        $this->assertTrue(change_upload_campus($pdo, 1, 3)['ok']);
        $this->assertSame(1, (int) $pdo->query('SELECT upload_number FROM latin_honor_uploads WHERE id = 1')->fetchColumn());
    }

    /** @return array<string, array{0: int}> */
    public static function unavailableTargets(): array
    {
        return [
            'its own campus' => [1],
            'a disabled campus' => [4],
            'a different school' => [5],
            'a school that does not exist' => [999],
            'no school chosen' => [0],
        ];
    }

    /** @dataProvider unavailableTargets */
    public function testAnUnavailableCampusIsRefusedAndNothingChanges(int $target): void
    {
        $pdo = TestDb::pdo();

        $result = change_upload_campus($pdo, 1, $target);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('NOT AVAILABLE', $result['error']);
        $this->assertSame(1, $this->schoolOf('latin_honor_uploads', 1));
        $this->assertSame(1, $this->schoolOf('latin_honor_graduates', 1));
    }

    public function testAnUnknownUploadIsRefused(): void
    {
        $result = change_upload_campus(TestDb::pdo(), 999, 2);

        $this->assertFalse($result['ok']);
        $this->assertSame('UPLOAD RECORD NOT FOUND.', $result['error']);
    }

    public function testAnUploadWithNoSavedRecordsCanStillBeMoved(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec('DELETE FROM graduate_applications');
        $pdo->exec('DELETE FROM latin_honor_graduates');

        $result = change_upload_campus($pdo, 1, 2);

        $this->assertTrue($result['ok']);
        $this->assertSame(0, $result['records']);
        $this->assertSame(2, $this->schoolOf('latin_honor_uploads', 1));
    }
}
