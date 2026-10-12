<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;
use Tests\TestDb;

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/uploads.php';
require_once __DIR__ . '/../../includes/schools.php';
require_once __DIR__ . '/../../includes/users.php';
require_once __DIR__ . '/../../includes/ocr_keywords.php';
require_once __DIR__ . '/../../includes/recycle_bin_items.php';

class RecycleBinItemsTest extends TestCase
{
    /** @var string[] files this test created (removed again in tearDown) */
    private array $files = [];

    protected function setUp(): void
    {
        TestDb::resetSchema();
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO users (name, username, email, password, role) VALUES ('Admin', 'admin', 'a@a.com', '" . password_hash('admin-pass', PASSWORD_DEFAULT) . "', 'administrator')");
        $pdo->exec("INSERT INTO schools (school_name, campus, address) VALUES ('ABC UNIVERSITY', 'MAIN', '1 Main St')");
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        foreach (glob(recycle_bin_dir() . '/*_hge_bin_test_*') ?: [] as $leftover) {
            @unlink($leftover);
        }
    }

    private function makeFile(string $content = 'pdf-bytes'): string
    {
        $path = sys_get_temp_dir() . '/hge_bin_test_' . bin2hex(random_bytes(4)) . '.pdf';
        file_put_contents($path, $content);
        $this->files[] = $path;

        return $path;
    }

    private function insertUpload(string $filePath, int $number = 1, int $records = 0): int
    {
        $pdo = TestDb::pdo();
        $pdo->prepare(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number, records_extracted)
             VALUES (1, '2025', 'list.pdf', :stored, :path, 10, SHA2(:path, 256), 1, :num, :records)"
        )->execute(['stored' => basename($filePath), 'path' => $filePath, 'num' => $number, 'records' => $records]);

        return (int) $pdo->lastInsertId();
    }

    private function binRows(): array
    {
        return TestDb::pdo()->query('SELECT * FROM recycle_bin_items ORDER BY id')->fetchAll();
    }

    // ---- uploads ----

    public function testDeletingAnUploadMovesItsRowDraftAndFileToTheBin(): void
    {
        $pdo = TestDb::pdo();
        $path = $this->makeFile('the-original-pdf');
        $id = $this->insertUpload($path);
        $pdo->exec("INSERT INTO extraction_review_drafts (upload_id, rows_json, saved_by) VALUES ({$id}, '[]', 1)");

        $result = delete_upload($pdo, $id, 1);

        $this->assertTrue($result['ok']);
        $this->assertNull(get_upload_by_id($pdo, $id));
        $this->assertFileDoesNotExist($path, 'the file leaves its old place');
        $rows = $this->binRows();
        $this->assertCount(1, $rows);
        $this->assertSame(['upload', $id, 'list.pdf', 1], [$rows[0]['item_type'], (int) $rows[0]['original_id'], $rows[0]['label'], (int) $rows[0]['deleted_by']]);
        $this->assertStringContainsString('ABC UNIVERSITY', $rows[0]['details']);
        $this->assertSame('the-original-pdf', file_get_contents(recycle_bin_dir() . '/' . $rows[0]['bin_file']), 'the file is kept, not deleted');
        $this->assertNotNull(json_decode($rows[0]['payload'], true)['draft']);
    }

    public function testGraduatesStillAttachedToADeletedUploadAreBinnedToo(): void
    {
        $pdo = TestDb::pdo();
        $id = $this->insertUpload($this->makeFile());
        $pdo->exec("INSERT INTO latin_honor_graduates (upload_id, school_id, graduate_name, course, latin_honor, graduation_year) VALUES ({$id}, 1, 'REYES, ANA', 'BSA', 'CUM LAUDE', 2025)");

        $this->assertTrue(delete_upload($pdo, $id, 1)['ok']);

        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM latin_honor_graduates')->fetchColumn());
        $this->assertSame('REYES, ANA', $pdo->query('SELECT graduate_name FROM graduate_recycle_bin')->fetchColumn());
    }

    public function testRestoringAnUploadPutsTheRowDraftAndFileBack(): void
    {
        $pdo = TestDb::pdo();
        $path = $this->makeFile('keep-me');
        $id = $this->insertUpload($path);
        $pdo->exec("INSERT INTO extraction_review_drafts (upload_id, rows_json, saved_by) VALUES ({$id}, '[1]', 1)");
        delete_upload($pdo, $id, 1);
        $binId = (int) $this->binRows()[0]['id'];

        $result = restore_recycle_item($pdo, $binId, 1);

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertSame('list.pdf', get_upload_by_id($pdo, $id)['original_filename'], 'same id, same data');
        $this->assertSame('keep-me', file_get_contents($path));
        $this->assertSame('[1]', $pdo->query("SELECT rows_json FROM extraction_review_drafts WHERE upload_id = {$id}")->fetchColumn());
        $this->assertSame([], $this->binRows());
    }

    public function testAnUploadIsNotRestoredOverAnotherThatNowUsesItsNumberOrItsFilePath(): void
    {
        $pdo = TestDb::pdo();
        $path = $this->makeFile();
        $id = $this->insertUpload($path);
        delete_upload($pdo, $id, 1);
        $binId = (int) $this->binRows()[0]['id'];

        $this->insertUpload($this->makeFile(), 1);
        $clash = restore_recycle_item($pdo, $binId, 1);
        $this->assertFalse($clash['ok']);
        $this->assertStringContainsString('UPLOAD NUMBER', $clash['error']);

        $pdo->exec('DELETE FROM latin_honor_uploads');
        file_put_contents($path, 'someone else is here now');
        $occupied = restore_recycle_item($pdo, $binId, 1);
        $this->assertFalse($occupied['ok']);
        $this->assertStringContainsString('FILE', $occupied['error']);
        $this->assertCount(1, $this->binRows(), 'a refused restore keeps the item in the bin');
    }

    public function testPurgingAnUploadDeletesItsBinnedFileForGood(): void
    {
        $pdo = TestDb::pdo();
        $id = $this->insertUpload($this->makeFile());
        delete_upload($pdo, $id, 1);
        $row = $this->binRows()[0];
        $binned = recycle_bin_dir() . '/' . $row['bin_file'];
        $this->assertFileExists($binned);

        $purged = purge_recycle_item($pdo, (int) $row['id']);

        $this->assertSame(['type' => 'upload', 'label' => 'list.pdf'], $purged);
        $this->assertFileDoesNotExist($binned);
        $this->assertSame([], $this->binRows());
        $this->assertNull(purge_recycle_item($pdo, (int) $row['id']));
    }

    // ---- schools ----

    public function testRemovingASchoolBinsItAndRestoreBringsItBackUnderTheSameId(): void
    {
        $pdo = TestDb::pdo();

        $result = remove_school($pdo, 1, 1, 'admin-pass');

        $this->assertSame('success', $result['status']);
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM schools')->fetchColumn());
        $row = $this->binRows()[0];
        $this->assertSame(['school', 1, '1 Main St'], [$row['item_type'], (int) $row['original_id'], $row['details']]);

        $this->assertTrue(restore_recycle_item($pdo, (int) $row['id'], 1)['ok']);
        $this->assertSame(['ABC UNIVERSITY', 'MAIN', '1 Main St'], array_values($pdo->query('SELECT school_name, campus, address FROM schools WHERE id = 1')->fetch(\PDO::FETCH_NUM)));
    }

    public function testASchoolIsNotRestoredWhenTheSameNameAndCampusWasAddedAgain(): void
    {
        $pdo = TestDb::pdo();
        remove_school($pdo, 1, 1, 'admin-pass');
        $pdo->exec("INSERT INTO schools (school_name, campus) VALUES ('ABC UNIVERSITY', 'MAIN')");

        $result = restore_recycle_item($pdo, (int) $this->binRows()[0]['id'], 1);

        $this->assertFalse($result['ok']);
        $this->assertCount(1, $this->binRows());
    }

    // ---- users ----

    public function testRemovingAUserBinsItAndRestoreReattachesTheActivityLog(): void
    {
        $pdo = TestDb::pdo();
        $created = create_user($pdo, ['name' => 'Ana', 'username' => 'ana', 'email' => 'ana@a.com', 'password' => 'secret123']);
        $pdo->exec("INSERT INTO activity_log (user_id, action, details) VALUES ({$created['id']}, 'login', 'x'), ({$created['id']}, 'logout', 'y')");

        $result = remove_user($pdo, $created['id'], 1, 'admin-pass');

        $this->assertSame('success', $result['status']);
        $this->assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM users WHERE username = 'ana'")->fetchColumn());
        $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM activity_log WHERE user_id IS NULL')->fetchColumn(), 'the log is kept, anonymized');
        $row = $this->binRows()[0];
        $this->assertSame(['user', 'Ana'], [$row['item_type'], $row['label']]);

        $this->assertTrue(restore_recycle_item($pdo, (int) $row['id'], 1)['ok']);
        $this->assertSame('ana', $pdo->query("SELECT username FROM users WHERE id = {$created['id']}")->fetchColumn());
        $this->assertSame(2, (int) $pdo->query("SELECT COUNT(*) FROM activity_log WHERE user_id = {$created['id']}")->fetchColumn(), 'their log entries point to them again');
        $this->assertTrue(password_verify('secret123', $pdo->query("SELECT password FROM users WHERE id = {$created['id']}")->fetchColumn()), 'same password');
    }

    public function testAUserIsNotRestoredWhenTheUsernameIsTakenAgain(): void
    {
        $pdo = TestDb::pdo();
        $created = create_user($pdo, ['name' => 'Ana', 'username' => 'ana', 'email' => 'ana@a.com', 'password' => 'secret123']);
        remove_user($pdo, $created['id'], 1, 'admin-pass');
        create_user($pdo, ['name' => 'Another Ana', 'username' => 'ana', 'email' => 'ana2@a.com', 'password' => 'secret123']);

        $result = restore_recycle_item($pdo, (int) $this->binRows()[0]['id'], 1);

        $this->assertFalse($result['ok']);
    }

    // ---- keywords ----

    public function testRemovingAKeywordBinsItAndRestoreBringsItBack(): void
    {
        $pdo = TestDb::pdo();
        $added = add_ocr_course_keyword($pdo, 'BSMT', 1);

        $result = remove_ocr_course_keyword($pdo, $added['id'], 1);

        $this->assertSame('success', $result['status']);
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM ocr_course_keywords')->fetchColumn());
        $row = $this->binRows()[0];
        $this->assertSame(['ocr_keyword', 'BSMT'], [$row['item_type'], $row['label']]);

        $this->assertTrue(restore_recycle_item($pdo, (int) $row['id'], 1)['ok']);
        $this->assertSame('BSMT', $pdo->query("SELECT keyword FROM ocr_course_keywords WHERE id = {$added['id']}")->fetchColumn());
    }

    // ---- the combined list ----

    public function testTheCombinedListMixesRecordsAndItemsAndFiltersByTypeAndName(): void
    {
        $pdo = TestDb::pdo();
        $uploadId = $this->insertUpload($this->makeFile());
        $pdo->exec("INSERT INTO latin_honor_graduates (upload_id, school_id, graduate_name, course, latin_honor, graduation_year) VALUES ({$uploadId}, 1, 'REYES, ANA', 'BSA', 'CUM LAUDE', 2025)");
        $gradId = (int) $pdo->lastInsertId();
        $pdo->exec("UPDATE latin_honor_uploads SET records_extracted = 1 WHERE id = {$uploadId}");
        require_once __DIR__ . '/../../includes/graduates.php';
        delete_graduate($pdo, $gradId, 1, 'DUPLICATE', 'manual');
        $added = add_ocr_course_keyword($pdo, 'BSMT', 1);
        remove_ocr_course_keyword($pdo, $added['id'], 1);

        $all = get_recycle_bin_combined($pdo, [], 1);
        $this->assertSame(2, $all['pagination']['total_rows']);
        $this->assertEqualsCanonicalizing(['record', 'ocr_keyword'], array_column($all['rows'], 'kind'));
        $this->assertNull($all['notice']);

        $this->assertSame(['record'], array_column(get_recycle_bin_combined($pdo, ['type' => 'record'], 1)['rows'], 'kind'));
        $this->assertSame(['ocr_keyword'], array_column(get_recycle_bin_combined($pdo, ['type' => 'ocr_keyword'], 1)['rows'], 'kind'));
        $this->assertSame([], get_recycle_bin_combined($pdo, ['type' => 'school'], 1)['rows']);
        $this->assertSame(['REYES, ANA'], array_column(get_recycle_bin_combined($pdo, ['name' => 'reyes'], 1)['rows'], 'title'));
        $this->assertSame(['record'], array_column(get_recycle_bin_combined($pdo, ['via' => 'manual', 'type' => 'record'], 1)['rows'], 'kind'));
    }

    // ---- when the table has not been created yet ----

    public function testWithoutTheBinTableNothingIsRemovedAndTheMessageSaysWhatToRun(): void
    {
        $pdo = TestDb::pdo();
        $path = $this->makeFile('stay-put');
        $uploadId = $this->insertUpload($path);
        $pdo->exec("INSERT INTO schools (school_name, campus) VALUES ('EMPTY COLLEGE', '')");
        $pdo->exec('DROP TABLE recycle_bin_items');

        $school = remove_school($pdo, 2, 1, 'admin-pass');
        $upload = delete_upload($pdo, $uploadId, 1);

        $this->assertSame('error', $school['status']);
        $this->assertStringContainsString('add_recycle_bin_items.sql', $school['message']);
        $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM schools')->fetchColumn());
        $this->assertFalse($upload['ok']);
        $this->assertStringContainsString('add_recycle_bin_items.sql', $upload['error']);
        $this->assertNotNull(get_upload_by_id($pdo, $uploadId));
        $this->assertSame('stay-put', file_get_contents($path), 'the file is back where it was');
    }

    public function testWithoutTheBinTableTheListStillShowsGraduates(): void
    {
        $pdo = TestDb::pdo();
        $pdo->exec('DROP TABLE recycle_bin_items');

        $result = get_recycle_bin_combined($pdo, [], 1);

        $this->assertStringContainsString('add_recycle_bin_items.sql', (string) $result['notice']);
        $this->assertSame([], $result['rows']);
    }
}
