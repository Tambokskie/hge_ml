<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;
use Tests\TestDb;

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/uploads.php';

/** Transferring an upload (and its saved records and file) to any other school. */
class TransferUploadToSchoolTest extends TestCase
{
    protected function setUp(): void
    {
        TestDb::resetSchema();
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO users (name, username, email, password, role) VALUES ('Admin', 'admin', 'a@a.com', 'x', 'administrator')");
        // 1 the WRONG school the list was filed under, 2 the right one, 3 the right school's other campus, 4 a disabled school
        $pdo->exec(
            "INSERT INTO schools (school_name, campus, status) VALUES
                ('Wrong College', 'MAIN', 'active'),
                ('Right University', 'MAIN', 'active'),
                ('Right University', 'NORTH', 'active'),
                ('Closed School', 'MAIN', 'disabled')"
        );
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number, extraction_status, records_extracted)
             VALUES (1, '2026', 'list.pdf', 'list.pdf', '/x/list.pdf', 10, SHA2('a',256), 1, 1, 'reviewed', 2)"
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

    private function realFile(string $name): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $name;
        file_put_contents($path, '%PDF-test');
        TestDb::pdo()->prepare('UPDATE latin_honor_uploads SET stored_filename = ?, file_path = ? WHERE id = 1')->execute([$name, $path]);

        return $path;
    }

    public function testEveryOtherActiveSchoolIsOfferedAndTheCurrentAndDisabledOnesAreNot(): void
    {
        $ids = array_column(get_transfer_targets_for_upload(TestDb::pdo(), 1), 'id');

        $this->assertSame([2, 3], $ids);
    }

    public function testTheOfferWarnsAboutExistingUploadsForTheSameYear(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number, extraction_status)
             VALUES (2, '2026', 'r1.pdf', 'r1.pdf', '/x/r1.pdf', 10, SHA2('b',256), 1, 1, 'pending'),
                    (3, '2025', 'n.pdf', 'n.pdf', '/x/n.pdf', 10, SHA2('c',256), 1, 1, 'pending')"
        );

        $targets = array_column(get_transfer_targets_for_upload($pdo, 1), null, 'id');

        // school 2 already has upload #1 for 2026: the moved list would become #2
        $this->assertSame(1, $targets[2]['same_year_uploads']);
        $this->assertSame(2, $targets[2]['next_number']);
        // school 3 only has a list for another year: nothing to warn about, the number is kept
        $this->assertSame(0, $targets[3]['same_year_uploads']);
        $this->assertSame(1, $targets[3]['next_number']);
        $this->assertSame('Right University-MAIN', $targets[2]['display']);
    }

    public function testTheUploadItsRecordsAndTheirSchoolMoveAndApplicationsStay(): void
    {
        $pdo = TestDb::pdo();

        $result = transfer_upload_to_school($pdo, 1, 2);

        $this->assertTrue($result['ok']);
        $this->assertSame('Wrong College-MAIN', $result['from']);
        $this->assertSame('Right University-MAIN', $result['to']);
        $this->assertSame(2, $result['records']);
        $this->assertSame(2, $this->schoolOf('latin_honor_uploads', 1));
        // the school shown on the records changes with the upload
        $this->assertSame(2, $this->schoolOf('latin_honor_graduates', 1));
        $this->assertSame(2, $this->schoolOf('latin_honor_graduates', 2));
        $this->assertSame('2026-06-03', $pdo->query('SELECT application_date FROM graduate_applications WHERE graduate_id = 1')->fetchColumn());
        $this->assertSame('REYES, ANA', $pdo->query('SELECT graduate_name FROM latin_honor_graduates WHERE id = 1')->fetchColumn());
        // the look-up/join path now reports the new school for the record
        $this->assertSame(
            'Right University',
            $pdo->query('SELECT s.school_name FROM latin_honor_graduates g JOIN schools s ON s.id = g.school_id WHERE g.id = 1')->fetchColumn()
        );
    }

    public function testRecycleBinRecordsOfTheUploadFollowIt(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec(
            "INSERT INTO graduate_recycle_bin (original_id, upload_id, school_id, graduate_name, course, latin_honor, graduation_year, deleted_by, deleted_reason)
             VALUES (50, 1, 1, 'GONE, PERSON', 'BSA', 'CUM LAUDE', 2026, 1, 'test'), (51, 99, 4, 'OTHER, UPLOAD', 'BSA', 'CUM LAUDE', 2026, 1, 'test')"
        );

        $this->assertTrue(transfer_upload_to_school($pdo, 1, 3)['ok']);

        $this->assertSame(3, (int) $pdo->query('SELECT school_id FROM graduate_recycle_bin WHERE original_id = 50')->fetchColumn());
        $this->assertSame(4, (int) $pdo->query('SELECT school_id FROM graduate_recycle_bin WHERE original_id = 51')->fetchColumn());
    }

    public function testTheStoredFileIsRenamedForTheNewSchool(): void
    {
        $pdo = TestDb::pdo();
        $old = $this->realFile('WRONG_COLLEGE_MAIN_2026_1.pdf');
        $new = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'RIGHT_UNIVERSITY_MAIN_2026_1.pdf';
        @unlink($new);

        $result = transfer_upload_to_school($pdo, 1, 2);

        $this->assertTrue($result['ok']);
        $this->assertSame('RIGHT_UNIVERSITY_MAIN_2026_1.pdf', $result['file']);
        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($new);
        $row = $pdo->query('SELECT stored_filename, file_path, original_filename FROM latin_honor_uploads WHERE id = 1')->fetch();
        $this->assertSame('RIGHT_UNIVERSITY_MAIN_2026_1.pdf', $row['stored_filename']);
        $this->assertSame($new, $row['file_path']);
        $this->assertSame('list.pdf', $row['original_filename']);
        $this->assertSame('%PDF-test', file_get_contents($new));
        unlink($new);
    }

    public function testAnImageUploadKeepsItsExtensionWhenRenamed(): void
    {
        $pdo = TestDb::pdo();
        $old = $this->realFile('WRONG_COLLEGE_MAIN_2026_1.jpg');
        $new = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'RIGHT_UNIVERSITY_MAIN_2026_1.jpg';
        @unlink($new);

        $this->assertTrue(transfer_upload_to_school($pdo, 1, 2)['ok']);

        $this->assertFileExists($new);
        $this->assertFileDoesNotExist($old);
        unlink($new);
    }

    public function testTheUploadNumberChangesOnlyWhenTheNewSchoolAlreadyUsesIt(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number, extraction_status)
             VALUES (2, '2026', 'r1.pdf', 'r1.pdf', '/x/r1.pdf', 10, SHA2('b',256), 1, 1, 'pending')"
        );

        $first = transfer_upload_to_school($pdo, 1, 2);
        $this->assertSame(2, $first['upload_number']);
        $this->assertSame(2, (int) $pdo->query('SELECT upload_number FROM latin_honor_uploads WHERE id = 1')->fetchColumn());

        // school 3 has nothing for 2026: the number it now has is kept
        $second = transfer_upload_to_school($pdo, 1, 3);
        $this->assertSame(2, $second['upload_number']);
        $this->assertSame(2, (int) $pdo->query('SELECT upload_number FROM latin_honor_uploads WHERE id = 1')->fetchColumn());
    }

    public function testATransferIsRefusedWhenAnotherFileAlreadyHasTheNewName(): void
    {
        $pdo = TestDb::pdo();
        $old = $this->realFile('WRONG_COLLEGE_MAIN_2026_1.pdf');
        $taken = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'RIGHT_UNIVERSITY_MAIN_2026_1.pdf';
        file_put_contents($taken, 'someone else');

        $result = transfer_upload_to_school($pdo, 1, 2);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('ALREADY EXISTS', $result['error']);
        $this->assertSame(1, $this->schoolOf('latin_honor_uploads', 1));
        $this->assertSame(1, $this->schoolOf('latin_honor_graduates', 1));
        $this->assertFileExists($old);
        $this->assertSame('someone else', file_get_contents($taken));
        unlink($old);
        unlink($taken);
    }

    /** @return array<string, array{0: int}> */
    public static function unavailableTargets(): array
    {
        return [
            'its own school' => [1],
            'a disabled school' => [4],
            'a school that does not exist' => [999],
            'no school chosen' => [0],
        ];
    }

    /** @dataProvider unavailableTargets */
    public function testAnUnavailableSchoolIsRefusedAndNothingChanges(int $target): void
    {
        $pdo = TestDb::pdo();

        $result = transfer_upload_to_school($pdo, 1, $target);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('NOT AVAILABLE', $result['error']);
        $this->assertSame(1, $this->schoolOf('latin_honor_uploads', 1));
        $this->assertSame(1, $this->schoolOf('latin_honor_graduates', 1));
    }

    public function testAnUploadWithASavedDraftIsRefused(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec("UPDATE latin_honor_uploads SET extraction_status = 'draft' WHERE id = 1");

        $result = transfer_upload_to_school($pdo, 1, 2);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('DRAFT', $result['error']);
        $this->assertSame(1, $this->schoolOf('latin_honor_uploads', 1));
    }

    /** @dataProvider movableStatuses */
    public function testFinishedOrNotYetReviewedUploadsCanMove(string $status): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec("UPDATE latin_honor_uploads SET extraction_status = '{$status}' WHERE id = 1");

        $this->assertTrue(transfer_upload_to_school($pdo, 1, 2)['ok']);
        $this->assertSame(2, $this->schoolOf('latin_honor_uploads', 1));
    }

    /** @return array<string, array{0: string}> */
    public static function movableStatuses(): array
    {
        return ['reviewed' => ['reviewed'], 'extracted' => ['extracted'], 'failed' => ['failed'], 'pending' => ['pending']];
    }

    public function testAnUnknownUploadIsRefused(): void
    {
        $result = transfer_upload_to_school(TestDb::pdo(), 999, 2);

        $this->assertFalse($result['ok']);
        $this->assertSame('UPLOAD RECORD NOT FOUND.', $result['error']);
    }

    public function testAnUploadWithNoSavedRecordsCanStillBeTransferred(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec('DELETE FROM graduate_applications');
        $pdo->exec('DELETE FROM latin_honor_graduates');

        $result = transfer_upload_to_school($pdo, 1, 2);

        $this->assertTrue($result['ok']);
        $this->assertSame(0, $result['records']);
        $this->assertSame(2, $this->schoolOf('latin_honor_uploads', 1));
    }
}
