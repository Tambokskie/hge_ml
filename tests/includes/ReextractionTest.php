<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;
use Tests\TestDb;

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/uploads.php';
require_once __DIR__ . '/../../includes/extraction_review.php';

/** Re-extracting an upload whose records are already saved must never harm a record that has a filed application. */
class ReextractionTest extends TestCase
{
    private const UPLOAD = 1;

    protected function setUp(): void
    {
        TestDb::resetSchema();
        $_SESSION = [];
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO users (name, username, email, password, role) VALUES ('Admin', 'admin', 'a@a.com', 'x', 'administrator')");
        $pdo->exec("INSERT INTO schools (school_name, campus) VALUES ('LNU', 'MAIN')");
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number, extraction_status, records_extracted)
             VALUES (1, '2026', 'lnu.pdf', 'lnu.pdf', '/x/lnu.pdf', 10, SHA2('a',256), 1, 1, 'reviewed', 3)"
        );
    }

    private function saved(string $name, string $course = 'BSA', bool $applied = false, string $honor = 'CUM LAUDE'): int
    {
        $pdo = TestDb::pdo();
        $pdo->prepare("INSERT INTO latin_honor_graduates (upload_id, school_id, graduate_name, course, latin_honor, graduation_date, graduation_year, source) VALUES (1, 1, ?, ?, ?, '2026-04-01', 2026, 'ocr')")
            ->execute([$name, $course, $honor]);
        $id = (int) $pdo->lastInsertId();
        if ($applied) {
            $pdo->exec("INSERT INTO graduate_applications (graduate_id, applied_by, application_date) VALUES ({$id}, 1, '2026-06-03')");
        }

        return $id;
    }

    private function fresh(string $name, string $course = 'BSA'): array
    {
        return ['graduate_name' => $name, 'course' => $course, 'latin_honor' => 'CUM LAUDE', 'graduation_date' => '2026-05-01', 'graduation_year' => 2026, 'source' => 'ai'];
    }

    private function row(string $table, int $id): array
    {
        return TestDb::pdo()->query("SELECT * FROM {$table} WHERE id = {$id}")->fetch() ?: [];
    }

    // ---- merge_reextracted_rows ----

    public function testAnUploadWithNoSavedRecordsIsNotChanged(): void
    {
        TestDb::pdo()->exec('DELETE FROM latin_honor_graduates');
        $new = [$this->fresh('REYES, ANA')];

        $this->assertSame(['rows' => $new, 'kept' => 0, 'skipped' => 0], merge_reextracted_rows(TestDb::pdo(), self::UPLOAD, $new));
    }

    public function testTaggedRecordsComeFirstLockedWithTheirOwnDataAndId(): void
    {
        $tagged = $this->saved('TAGGED, PERSON', 'BSIT', true, 'MAGNA CUM LAUDE');
        $this->saved('PLAIN, PERSON');

        $merged = merge_reextracted_rows(TestDb::pdo(), self::UPLOAD, [$this->fresh('REYES, ANA')]);

        $this->assertSame(1, $merged['kept']);
        $first = $merged['rows'][0];
        $this->assertSame([$tagged, 'TAGGED, PERSON', 'BSIT', 'MAGNA CUM LAUDE', true, '2026-06-03', 'ocr'], [$first['id'], $first['graduate_name'], $first['course'], $first['latin_honor'], $first['locked'], $first['applied_on'], $first['source']], 'exactly as saved, not as re-extracted');
        $this->assertSame('REYES, ANA', $merged['rows'][1]['graduate_name']);
        $this->assertArrayNotHasKey('id', $merged['rows'][1]);
    }

    public function testANewRowThatDuplicatesATaggedRecordIsLeftOutBecauseTheTaggedOnePrevails(): void
    {
        $this->saved('Tagged, Person', 'BSA', true);

        $merged = merge_reextracted_rows(TestDb::pdo(), self::UPLOAD, [$this->fresh('TAGGED  PERSON', 'Bachelor of Science in Accountancy'), $this->fresh('OTHER, PERSON')]);

        $this->assertSame(1, $merged['skipped']);
        $this->assertSame(['Tagged, Person', 'OTHER, PERSON'], array_column($merged['rows'], 'graduate_name'), 'only the saved copy of the tagged person remains');
    }

    public function testNewRowsTakeOverTheIdOfAMatchingUntaggedRecordSoSavingUpdatesItInPlace(): void
    {
        $a = $this->saved('SAME, NAME');
        $b = $this->saved('SAME, NAME');

        $merged = merge_reextracted_rows(TestDb::pdo(), self::UPLOAD, [$this->fresh('Same Name'), $this->fresh('SAME, NAME'), $this->fresh('SAME, NAME'), $this->fresh('NEW, PERSON')]);

        $this->assertSame([$a, $b, null, null], array_map(static fn (array $r) => $r['id'] ?? null, $merged['rows']), 'two saved copies, two reuses; the rest are new');
    }

    public function testALaterBatchDoesNotAddTheLockedRowsAgainOrReuseIdsTakenByEarlierBatches(): void
    {
        $this->saved('TAGGED, PERSON', 'BSA', true);
        $plain = $this->saved('PLAIN, PERSON');

        $merged = merge_reextracted_rows(TestDb::pdo(), self::UPLOAD, [$this->fresh('PLAIN, PERSON'), $this->fresh('TAGGED, PERSON')], [['graduate_name' => 'PLAIN, PERSON', 'id' => $plain]], false);

        $this->assertSame(['PLAIN, PERSON'], array_column($merged['rows'], 'graduate_name'), 'no locked rows, the tagged duplicate is dropped');
        $this->assertArrayNotHasKey('id', $merged['rows'][0], 'that id was already used by an earlier batch');
    }

    // ---- locked rows in the review ----

    private function stageLockedReview(): array
    {
        $tagged = $this->saved('TAGGED, PERSON', 'BSIT', true);
        $plain = $this->saved('PLAIN, PERSON');
        start_extraction_review(self::UPLOAD, merge_reextracted_rows(TestDb::pdo(), self::UPLOAD, [$this->fresh('PLAIN, PERSON'), $this->fresh('NEW, PERSON')])['rows']);

        return [$tagged, $plain];
    }

    public function testALockedRowCannotBeEditedOrRemoved(): void
    {
        $this->stageLockedReview();
        $before = get_extraction_review(self::UPLOAD)['rows'];

        $edit = set_staged_row(self::UPLOAD, 0, ['graduate_name' => 'CHANGED', 'latin_honor' => 'CUM LAUDE', 'graduation_year' => 2026]);
        $remove = remove_staged_row(self::UPLOAD, 0);
        $bulk = remove_staged_rows(self::UPLOAD, [0, 1]);

        foreach ([$edit, $remove, $bulk] as $result) {
            $this->assertFalse($result['ok']);
            $this->assertSame(STAGED_ROW_LOCKED_MESSAGE, $result['error']);
        }
        $this->assertSame($before, get_extraction_review(self::UPLOAD)['rows'], 'nothing in the review changed');
    }

    public function testTheMassDateUpdateLeavesLockedRowsAlone(): void
    {
        $this->stageLockedReview();

        $result = mass_update_staged_graduation_date(self::UPLOAD, '2030-01-01');

        $this->assertSame(['ok' => true, 'updated' => 2], $result);
        $rows = get_extraction_review(self::UPLOAD)['rows'];
        $this->assertSame('2026-04-01', $rows[0]['graduation_date'], 'the tagged record keeps its own date');
        $this->assertSame('2030-01-01', $rows[1]['graduation_date']);
    }

    public function testTheMassDateUpdateWithOnlyLockedRowsHasNothingToUpdate(): void
    {
        $this->saved('TAGGED, PERSON', 'BSA', true);
        start_extraction_review(self::UPLOAD, merge_reextracted_rows(TestDb::pdo(), self::UPLOAD, [])['rows']);

        $this->assertFalse(mass_update_staged_graduation_date(self::UPLOAD, '2030-01-01')['ok']);
    }

    // ---- saving the re-extraction ----

    public function testSavingAReextractionKeepsTaggedRecordsAndReplacesTheRest(): void
    {
        $pdo = TestDb::pdo();
        $tagged = $this->saved('TAGGED, PERSON', 'BSIT', true);
        $plain = $this->saved('PLAIN, PERSON', 'OLD COURSE');
        $gone = $this->saved('GONE, PERSON');

        $rows = merge_reextracted_rows($pdo, self::UPLOAD, [$this->fresh('Plain, Person', 'NEW COURSE'), $this->fresh('TAGGED, PERSON', 'WRONG'), $this->fresh('BRAND NEW, PERSON')])['rows'];
        // Even if a locked row's data were tampered with in the staged copy, saving must not write it.
        $rows[0]['course'] = 'TAMPERED';
        $rows[0]['graduate_name'] = 'TAMPERED NAME';

        $result = save_staged_records($pdo, self::UPLOAD, 1, $rows, 1);

        $this->assertSame('success', $result['status'], $result['message'] ?? '');
        $t = $this->row('latin_honor_graduates', $tagged);
        $this->assertSame(['TAGGED, PERSON', 'BSIT'], [$t['graduate_name'], $t['course']], 'the tagged record is untouched');
        $this->assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM graduate_applications WHERE graduate_id = {$tagged}")->fetchColumn(), 'and still has its application');
        $p = $this->row('latin_honor_graduates', $plain);
        $this->assertSame(['Plain, Person', 'NEW COURSE', 'ai'], [$p['graduate_name'], $p['course'], $p['source']], 'a matching untagged record is updated in place');
        $this->assertSame([], $this->row('latin_honor_graduates', $gone), 'an untagged record the new extraction did not reproduce is removed...');
        $this->assertSame('GONE, PERSON', $pdo->query('SELECT graduate_name FROM graduate_recycle_bin')->fetchColumn(), '...into the recycle bin, so it can be restored');
        $this->assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM latin_honor_graduates WHERE graduate_name = 'BRAND NEW, PERSON'")->fetchColumn());
        $this->assertSame(['reviewed', 3], [$pdo->query('SELECT extraction_status FROM latin_honor_uploads WHERE id = 1')->fetchColumn(), (int) $pdo->query('SELECT records_extracted FROM latin_honor_uploads WHERE id = 1')->fetchColumn()]);
        $this->assertSame(1, $result['removed_count']);
    }

    public function testWithoutCarryingTheTaggedRecordsASaveStillFailsSafelyAndChangesNothing(): void
    {
        // This is what a naive re-extraction used to do: stage only the new rows.
        $pdo = TestDb::pdo();
        $tagged = $this->saved('TAGGED, PERSON', 'BSA', true);

        $result = save_staged_records($pdo, self::UPLOAD, 1, [$this->fresh('SOMEONE, ELSE')], 1);

        $this->assertSame('error', $result['status']);
        $this->assertNotSame([], $this->row('latin_honor_graduates', $tagged));
        $this->assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM latin_honor_graduates WHERE graduate_name = 'SOMEONE, ELSE'")->fetchColumn(), 'the failed save rolled back completely');
    }

    public function testADraftKeepsTheRealNumberOfSavedRecordsSoTheUploadCannotBeRemoved(): void
    {
        $this->saved('A, PERSON');
        $this->saved('B, PERSON');

        save_extraction_draft(TestDb::pdo(), self::UPLOAD, 1, [$this->fresh('C, PERSON')]);

        $upload = TestDb::pdo()->query('SELECT extraction_status, records_extracted FROM latin_honor_uploads WHERE id = 1')->fetch();
        $this->assertSame(['draft', 2], [$upload['extraction_status'], (int) $upload['records_extracted']]);
    }

    public function testAFirstTimeDraftStillCountsZero(): void
    {
        save_extraction_draft(TestDb::pdo(), self::UPLOAD, 1, [$this->fresh('C, PERSON')]);

        $this->assertSame(0, (int) TestDb::pdo()->query('SELECT records_extracted FROM latin_honor_uploads WHERE id = 1')->fetchColumn());
    }

    public function testALockedRowSurvivesTheDraftRoundTrip(): void
    {
        $tagged = $this->saved('TAGGED, PERSON', 'BSA', true);
        $rows = merge_reextracted_rows(TestDb::pdo(), self::UPLOAD, [$this->fresh('NEW, PERSON')])['rows'];

        save_extraction_draft(TestDb::pdo(), self::UPLOAD, 1, $rows);
        $back = get_extraction_draft(TestDb::pdo(), self::UPLOAD)['rows'];

        $this->assertSame([$tagged, true, '2026-06-03'], [$back[0]['id'], $back[0]['locked'], $back[0]['applied_on']]);
    }
}
