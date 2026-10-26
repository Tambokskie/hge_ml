<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;
use Tests\TestDb;

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/duplicates.php';
require_once __DIR__ . '/../../includes/bulk_hge.php';
require_once __DIR__ . '/../../includes/bulk_hge_review.php';

class BulkHgeReviewTest extends TestCase
{
    protected function setUp(): void
    {
        TestDb::resetSchema();
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO users (name, username, email, password, role) VALUES ('Admin', 'admin', 'a@a.com', 'x', 'administrator'), ('Other', 'other', 'o@a.com', 'x', 'administrator')");
        $pdo->exec("INSERT INTO schools (school_name, campus) VALUES ('ABC UNIVERSITY', 'MAIN'), ('XYZ COLLEGE', 'MAIN')");
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number, extraction_status, records_extracted)
             VALUES (1, '2025', 'a.pdf', 'a.pdf', '/x/a.pdf', 10, SHA2('a',256), 1, 1, 'reviewed', 2),
                    (2, '2025', 'b.pdf', 'b.pdf', '/x/b.pdf', 10, SHA2('b',256), 1, 1, 'reviewed', 1)"
        );
    }

    private function addGraduate(string $name, int $uploadId = 1, int $schoolId = 1): int
    {
        $pdo = TestDb::pdo();
        $pdo->prepare(
            "INSERT INTO latin_honor_graduates (upload_id, school_id, graduate_name, course, latin_honor, graduation_year)
             VALUES (?, ?, ?, 'BSA', 'CUM LAUDE', 2020)"
        )->execute([$uploadId, $schoolId, $name]);

        return (int) $pdo->lastInsertId();
    }

    private function person(string $last, string $first, string $mi = '', string $middle = '', string $date = '2024-04-02', int $row = 2): array
    {
        return ['row' => $row, 'date' => $date, 'last' => $last, 'first' => $first, 'mi' => $mi, 'middle' => $middle, 'ext' => ''];
    }

    /** A review list the way the preview builds it: Lopez is ambiguous (two schools), Reyes is a loose match. */
    private function previewReview(string $lopezDate = '2024-05-09'): array
    {
        $this->addGraduate('LOPEZ, MARK T.', 1, 1);
        $this->addGraduate('LOPEZ, MARK T.', 2, 2);
        $this->addGraduate('REYES, ANA');

        $preview = bulk_hge_build_preview(TestDb::pdo(), [
            $this->person('Lopez', 'Mark', 'T', 'Tan', $lopezDate, 5),
            $this->person('Reyes', 'Ana', 'B', 'Bautista', '2024-06-01', 6),
        ]);
        $this->assertCount(2, $preview['review']);

        return $preview['review'];
    }

    private function applications(): array
    {
        return TestDb::pdo()->query('SELECT graduate_id, applied_by, application_date FROM graduate_applications ORDER BY graduate_id')->fetchAll();
    }

    public function testSavingKeepsEachUncertainApplicantAsAPendingItem(): void
    {
        $counts = bulk_hge_review_save(TestDb::pdo(), $this->previewReview(), 1, 'file.xls');

        $this->assertSame(['added' => 2, 'updated' => 0, 'skipped' => 0], $counts);
        $this->assertSame(2, bulk_hge_review_pending_count(TestDb::pdo()));
        $this->assertSame([], $this->applications(), 'saving for review must not file anything');
    }

    public function testListShowsTheCurrentPossibleRecordsWithSchoolAndYear(): void
    {
        bulk_hge_review_save(TestDb::pdo(), $this->previewReview(), 1, 'file.xls');

        $list = bulk_hge_review_list(TestDb::pdo());

        $this->assertSame(2, $list['total']);
        $lopez = $list['items'][0];
        $this->assertSame('Lopez, Mark Tan', $lopez['name']);
        $this->assertSame('2024-05-09', $lopez['date']);
        $this->assertSame('AMBIGUOUS', $lopez['reason_label']);
        $this->assertSame('file.xls', $lopez['source_filename']);
        $this->assertSame(
            [['LOPEZ, MARK T.', 'ABC UNIVERSITY-MAIN', '2020', null], ['LOPEZ, MARK T.', 'XYZ COLLEGE-MAIN', '2020', null]],
            array_map(static fn (array $c): array => [$c['name'], $c['school'], $c['year'], $c['applied_on']], $lopez['candidates'])
        );
    }

    public function testReUploadingTheSameFileDoesNotCreateDuplicateItems(): void
    {
        $review = $this->previewReview();
        bulk_hge_review_save(TestDb::pdo(), $review, 1, 'file.xls');

        $again = bulk_hge_review_save(TestDb::pdo(), $review, 1, 'file2.xls');

        $this->assertSame(['added' => 0, 'updated' => 2, 'skipped' => 0], $again);
        $this->assertSame(2, bulk_hge_review_pending_count(TestDb::pdo()));
    }

    public function testAnItemKeepsTheEarlierDateWhenUploadedAgainWithALaterOne(): void
    {
        bulk_hge_review_save(TestDb::pdo(), $this->previewReview('2024-05-09'), 1, 'a.xls');
        $later = $this->previewReview('2025-01-01');
        bulk_hge_review_save(TestDb::pdo(), $later, 1, 'b.xls');

        $this->assertSame('2024-05-09', bulk_hge_review_list(TestDb::pdo())['items'][0]['date']);
    }

    public function testFilingAnItemFilesTheChosenRecordWithTheFileDateAndClosesTheItem(): void
    {
        bulk_hge_review_save(TestDb::pdo(), $this->previewReview(), 1, 'file.xls');
        $item = bulk_hge_review_list(TestDb::pdo())['items'][0];
        $chosen = $item['candidates'][1]['id'];

        $result = bulk_hge_review_file(TestDb::pdo(), $item['id'], $chosen, 2);

        $this->assertTrue($result['ok']);
        $rows = $this->applications();
        $this->assertCount(1, $rows);
        $this->assertSame($chosen, (int) $rows[0]['graduate_id']);
        $this->assertSame(2, (int) $rows[0]['applied_by']);
        $this->assertSame('2024-05-09', $rows[0]['application_date']);
        $this->assertSame(1, bulk_hge_review_pending_count(TestDb::pdo()));
        $row = TestDb::pdo()->query('SELECT status, resolved_by, resolved_graduate_id FROM bulk_hge_review_items WHERE id = ' . $item['id'])->fetch();
        $this->assertSame(['filed', 2, $chosen], [$row['status'], (int) $row['resolved_by'], (int) $row['resolved_graduate_id']]);
    }

    public function testACandidateThatIsNotOneOfThePossibleMatchesIsRefused(): void
    {
        bulk_hge_review_save(TestDb::pdo(), $this->previewReview(), 1, 'file.xls');
        $outsider = $this->addGraduate('SOMEONE, ELSE');
        $item = bulk_hge_review_list(TestDb::pdo())['items'][0];

        $result = bulk_hge_review_file(TestDb::pdo(), $item['id'], $outsider, 1);

        $this->assertFalse($result['ok']);
        $this->assertSame([], $this->applications());
        $this->assertSame(2, bulk_hge_review_pending_count(TestDb::pdo()));
    }

    public function testFilingAnAlreadyFiledGraduateIsRefusedAndTheItemStaysPending(): void
    {
        bulk_hge_review_save(TestDb::pdo(), $this->previewReview(), 1, 'file.xls');
        $item = bulk_hge_review_list(TestDb::pdo())['items'][0];
        $chosen = $item['candidates'][0]['id'];
        TestDb::pdo()->exec("INSERT INTO graduate_applications (graduate_id, applied_by, application_date) VALUES ({$chosen}, 1, '2023-03-03')");

        $result = bulk_hge_review_file(TestDb::pdo(), $item['id'], $chosen, 1);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('ALREADY', $result['error']);
        $this->assertSame('2023-03-03', $this->applications()[0]['application_date']);
        $this->assertSame(2, bulk_hge_review_pending_count(TestDb::pdo()));
        $shown = bulk_hge_review_list(TestDb::pdo())['items'][0]['candidates'][0];
        $this->assertSame('2023-03-03', $shown['applied_on'], 'the list flags a record that was filed since');
    }

    public function testApprovingAnAlreadyFiledCandidateUsesTheFileDateAndKeepsTheFilingDate(): void
    {
        bulk_hge_review_save(TestDb::pdo(), $this->previewReview('2024-05-09'), 1, 'file.xls');
        $item = bulk_hge_review_list(TestDb::pdo())['items'][0];
        $chosen = $item['candidates'][0]['id'];
        TestDb::pdo()->exec("INSERT INTO graduate_applications (graduate_id, applied_by, application_date) VALUES ({$chosen}, 1, '2024-05-08')");

        $shown = bulk_hge_review_list(TestDb::pdo())['items'][0]['candidates'][0];
        $this->assertFalse($shown['approved']);

        $result = bulk_hge_review_approve(TestDb::pdo(), $item['id'], $chosen, 2);

        $this->assertTrue($result['ok']);
        $row = TestDb::pdo()->query("SELECT application_date, approved_at, approved_by FROM graduate_applications WHERE graduate_id = {$chosen}")->fetch();
        $this->assertSame(['2024-05-08', '2024-05-09', 2], [$row['application_date'], $row['approved_at'], (int) $row['approved_by']]);
        $this->assertSame(1, bulk_hge_review_pending_count(TestDb::pdo()));
        $status = TestDb::pdo()->query('SELECT status, resolved_graduate_id FROM bulk_hge_review_items WHERE id = ' . $item['id'])->fetch();
        $this->assertSame(['filed', $chosen], [$status['status'], (int) $status['resolved_graduate_id']]);
    }

    public function testApprovingIsRefusedWhenThereIsNoApplicationOrItIsAlreadyApproved(): void
    {
        bulk_hge_review_save(TestDb::pdo(), $this->previewReview('2024-05-09'), 1, 'file.xls');
        $item = bulk_hge_review_list(TestDb::pdo())['items'][0];
        $unfiled = $item['candidates'][0]['id'];
        $approved = $item['candidates'][1]['id'];
        TestDb::pdo()->exec("INSERT INTO graduate_applications (graduate_id, applied_by, application_date, approved_at, approved_by) VALUES ({$approved}, 1, '2024-01-01', '2024-01-02', 1)");

        $this->assertFalse(bulk_hge_review_approve(TestDb::pdo(), $item['id'], $unfiled, 1)['ok']);
        $this->assertFalse(bulk_hge_review_approve(TestDb::pdo(), $item['id'], $approved, 1)['ok']);

        $this->assertSame('2024-01-02', TestDb::pdo()->query("SELECT approved_at FROM graduate_applications WHERE graduate_id = {$approved}")->fetchColumn());
        $this->assertSame(2, bulk_hge_review_pending_count(TestDb::pdo()));
    }

    public function testDismissingClosesTheItemWithoutFilingAndOnlyOnce(): void
    {
        bulk_hge_review_save(TestDb::pdo(), $this->previewReview(), 1, 'file.xls');
        $item = bulk_hge_review_list(TestDb::pdo())['items'][0];

        $this->assertTrue(bulk_hge_review_dismiss(TestDb::pdo(), $item['id'], 2)['ok']);
        $this->assertFalse(bulk_hge_review_dismiss(TestDb::pdo(), $item['id'], 2)['ok']);
        $this->assertFalse(bulk_hge_review_file(TestDb::pdo(), $item['id'], $item['candidates'][0]['id'], 2)['ok']);

        $this->assertSame([], $this->applications());
        $this->assertSame(1, bulk_hge_review_pending_count(TestDb::pdo()));
    }

    public function testAResolvedPersonIsNotRaisedAgainByALaterUpload(): void
    {
        $review = $this->previewReview();
        bulk_hge_review_save(TestDb::pdo(), $review, 1, 'file.xls');
        $item = bulk_hge_review_list(TestDb::pdo())['items'][0];
        bulk_hge_review_dismiss(TestDb::pdo(), $item['id'], 1);

        $again = bulk_hge_review_save(TestDb::pdo(), $review, 1, 'file2.xls');

        $this->assertSame(['added' => 0, 'updated' => 1, 'skipped' => 1], $again);
        $this->assertSame(1, bulk_hge_review_pending_count(TestDb::pdo()));
    }

    public function testAGraduateDeletedSinceIsDroppedFromTheItsCandidates(): void
    {
        bulk_hge_review_save(TestDb::pdo(), $this->previewReview(), 1, 'file.xls');
        $item = bulk_hge_review_list(TestDb::pdo())['items'][0];
        TestDb::pdo()->exec('DELETE FROM latin_honor_graduates WHERE id = ' . $item['candidates'][0]['id']);

        $after = bulk_hge_review_list(TestDb::pdo())['items'][0];

        $this->assertCount(1, $after['candidates']);
    }

    /** A hand-built review entry (the shape bulk_hge_build_preview() produces). */
    private function claimed(string $middle, array $candidateIds, string $date = '2025-07-30', int $row = 2): array
    {
        return [
            'status' => 'claimed',
            'person' => $this->person('Gambol', 'Ma. April Rose', 'F', $middle, $date, $row),
            'candidate_ids' => $candidateIds,
        ];
    }

    private function applyOn(int $graduateId, string $date, bool $approved = true): void
    {
        $approvedAt = $approved ? "'{$date}'" : 'NULL';
        $approvedBy = $approved ? '1' : 'NULL';
        TestDb::pdo()->exec("INSERT INTO graduate_applications (graduate_id, applied_by, application_date, approved_at, approved_by) VALUES ({$graduateId}, 1, '{$date}', {$approvedAt}, {$approvedBy})");
    }

    public function testAnItemStaysWhenTheRecordIsFiledForThatDateButNotYetApproved(): void
    {
        $gradId = $this->addGraduate('Ma. April Rose F. Gambol');
        $this->applyOn($gradId, '2025-07-30', false);
        TestDb::pdo()->prepare('INSERT INTO bulk_hge_review_items (person_key, last_name, first_name, application_date, reason, candidate_ids, created_by) VALUES (?, ?, ?, ?, ?, ?, 1)')
            ->execute([str_repeat('b', 40), 'GAMBOL', 'MA.', '2025-07-30', 'loose', json_encode([$gradId])]);

        $list = bulk_hge_review_list(TestDb::pdo());
        $this->assertSame(1, $list['total'], 'it still waits so an administrator can approve it');
        $this->assertFalse($list['items'][0]['candidates'][0]['approved']);
    }

    public function testTheSamePersonTypedTwiceClosesTheOtherItemOnceOneIsFiled(): void
    {
        $gradId = $this->addGraduate('Ma. April Rose F. Gambol');
        $review = [$this->claimed('FLORIONO', [$gradId], '2025-07-30', 3135), $this->claimed('FLORINO', [$gradId], '2025-07-30', 3139)];
        bulk_hge_review_save(TestDb::pdo(), $review, 1, 'file.xls');
        $items = bulk_hge_review_list(TestDb::pdo())['items'];
        $this->assertCount(2, $items, 'both spellings wait while nothing is filed');

        $this->assertTrue(bulk_hge_review_file(TestDb::pdo(), $items[0]['id'], $gradId, 1)['ok']);

        $list = bulk_hge_review_list(TestDb::pdo());
        $this->assertSame(0, $list['total']);
        $this->assertSame([], $list['items']);
        $row = TestDb::pdo()->query('SELECT status, resolved_by, resolved_graduate_id FROM bulk_hge_review_items WHERE id = ' . $items[1]['id'])->fetch();
        $this->assertSame(['dismissed', null, null], [$row['status'], $row['resolved_by'], $row['resolved_graduate_id']]);
        $this->assertCount(1, $this->applications(), 'closing the other item files nothing more');
    }

    public function testAnItemStaysWhenTheRecordWasFiledForADifferentDate(): void
    {
        $gradId = $this->addGraduate('Ma. April Rose F. Gambol');
        $this->applyOn($gradId, '2023-03-03');
        TestDb::pdo()->prepare('INSERT INTO bulk_hge_review_items (person_key, last_name, first_name, application_date, reason, candidate_ids, created_by) VALUES (?, ?, ?, ?, ?, ?, 1)')
            ->execute([str_repeat('a', 40), 'GAMBOL', 'MA.', '2025-07-30', 'claimed', json_encode([$gradId])]);

        $this->assertSame(1, bulk_hge_review_list(TestDb::pdo())['total']);
    }

    public function testAnItemClosesWhenAnyPossibleRecordIsAlreadyFiledForThatDate(): void
    {
        // Same name on two campuses: one is already filed for the very date in the
        // file, so someone already acted on this applicant - the unfiled twin must
        // not keep asking for action.
        $filed = $this->addGraduate('LOPEZ, MARK T.', 1, 1);
        $twin = $this->addGraduate('LOPEZ, MARK T.', 2, 2);
        bulk_hge_review_save(TestDb::pdo(), [['status' => 'ambiguous', 'person' => $this->person('Lopez', 'Mark', 'T', 'Tan', '2025-07-30'), 'candidate_ids' => [$filed, $twin]]], 1, 'file.xls');
        $this->assertSame(1, bulk_hge_review_list(TestDb::pdo())['total'], 'waits while nothing is filed');

        $this->applyOn($filed, '2025-07-30');

        $this->assertSame(0, bulk_hge_review_list(TestDb::pdo())['total']);
        $this->assertSame(1, count($this->applications()), 'closing it files nothing for the twin');
    }

    public function testAnItemStaysWhenNoPossibleRecordIsFiledForThatDate(): void
    {
        $a = $this->addGraduate('LOPEZ, MARK T.', 1, 1);
        $b = $this->addGraduate('LOPEZ, MARK T.', 2, 2);
        $this->applyOn($a, '2023-03-03'); // a different date says nothing about this row
        bulk_hge_review_save(TestDb::pdo(), [['status' => 'ambiguous', 'person' => $this->person('Lopez', 'Mark', 'T', 'Tan', '2025-07-30'), 'candidate_ids' => [$a, $b]]], 1, 'file.xls');

        $this->assertSame(1, bulk_hge_review_list(TestDb::pdo())['total']);
    }

    public function testADeletedRecordDoesNotKeepAnItemOpen(): void
    {
        $filed = $this->addGraduate('LOPEZ, MARK T.', 1, 1);
        $gone = $this->addGraduate('LOPEZ, MARK T.', 2, 2);
        bulk_hge_review_save(TestDb::pdo(), [['status' => 'ambiguous', 'person' => $this->person('Lopez', 'Mark', 'T', 'Tan', '2025-07-30'), 'candidate_ids' => [$filed, $gone]]], 1, 'file.xls');
        $this->applyOn($filed, '2025-07-30');
        TestDb::pdo()->exec("DELETE FROM latin_honor_graduates WHERE id = {$gone}");

        $this->assertSame(0, bulk_hge_review_list(TestDb::pdo())['total']);
    }

    public function testClassifyTellsNewWaitingResolvedAndSettledApplicantsApart(): void
    {
        $a = $this->addGraduate('LOPEZ, MARK T.', 1, 1);
        $b = $this->addGraduate('LOPEZ, MARK T.', 2, 2);
        $entry = fn (string $first, array $ids, string $date = '2025-07-30'): array => [
            'status' => 'ambiguous', 'person' => $this->person('Lopez', $first, 'T', 'Tan', $date), 'candidate_ids' => $ids,
        ];
        // Dated differently from the application filed below, so these two are not settled by it.
        $new = $entry('Mark', [$a, $b], '2025-08-15');
        $waiting = $entry('Marco', [$a, $b], '2025-08-15');
        $filedBefore = $entry('Marcus', [$a, $b]);
        $dismissedBefore = $entry('Marcel', [$a, $b]);
        bulk_hge_review_save(TestDb::pdo(), [$waiting, $filedBefore, $dismissedBefore], 1, 'old.xls');
        $items = bulk_hge_review_list(TestDb::pdo())['items'];
        $this->assertTrue(bulk_hge_review_file(TestDb::pdo(), $items[1]['id'], $a, 1)['ok']);
        $this->assertTrue(bulk_hge_review_dismiss(TestDb::pdo(), $items[2]['id'], 1)['ok']);
        $settled = $entry('Markus', [$a], '2025-07-30');
        $settledViaTwin = $entry('Markos', [$a, $b], '2025-07-30'); // $a is filed for this date, $b is not

        $verdicts = bulk_hge_review_classify(TestDb::pdo(), [$new, $waiting, $filedBefore, $dismissedBefore, $settled, $settledViaTwin]);

        $this->assertSame(['open', 'open', 'resolved', 'resolved', 'settled', 'settled'], array_values($verdicts));
    }

    public function testConfirmingAPreviewOfOnlyHandledApplicantsSavesNothing(): void
    {
        $grad = $this->addGraduate('Ma. April Rose F. Gambol');
        $entry = ['status' => 'claimed', 'person' => $this->person('Gambol', 'Ma. April Rose', 'F', 'Florino', '2025-07-30'), 'candidate_ids' => [$grad]];
        bulk_hge_review_save(TestDb::pdo(), [$entry], 1, 'old.xls');
        bulk_hge_review_dismiss(TestDb::pdo(), bulk_hge_review_list(TestDb::pdo())['items'][0]['id'], 1);

        $verdicts = bulk_hge_review_classify(TestDb::pdo(), [$entry]);
        $counts = bulk_hge_review_save(TestDb::pdo(), [$entry], 1, 'again.xls');

        $this->assertSame(['resolved'], array_values($verdicts));
        $this->assertSame(['added' => 0, 'updated' => 0, 'skipped' => 1], $counts);
        $this->assertSame(0, bulk_hge_review_pending_count(TestDb::pdo()));
    }

    public function testSavingSkipsAnApplicantWhoseRecordIsAlreadyFiledForThatDate(): void
    {
        $gradId = $this->addGraduate('Ma. April Rose F. Gambol');
        $this->applyOn($gradId, '2025-07-30');

        $counts = bulk_hge_review_save(TestDb::pdo(), [$this->claimed('FLORINO', [$gradId])], 1, 'file.xls');

        $this->assertSame(['added' => 0, 'updated' => 0, 'skipped' => 1], $counts);
        $this->assertSame(0, bulk_hge_review_pending_count(TestDb::pdo()));
    }

    public function testConfirmingFilesTheMatchedAndSavesTheUncertainTogether(): void
    {
        $confident = $this->addGraduate('SANDAGAN, NILVEN A.');
        $review = $this->previewReview();

        $result = bulk_hge_apply_with_review(TestDb::pdo(), [['graduate_id' => $confident, 'date' => '2024-04-02']], $review, 1, 'file.xls');

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['filed']);
        $this->assertSame(['added' => 2, 'updated' => 0, 'skipped' => 0], $result['review']);
        $this->assertCount(1, $this->applications());
        $this->assertSame(2, bulk_hge_review_pending_count(TestDb::pdo()));
    }

    public function testIfSavingTheReviewFailsNothingIsFiled(): void
    {
        $confident = $this->addGraduate('SANDAGAN, NILVEN A.');
        $review = $this->previewReview();
        TestDb::pdo()->exec('DROP TABLE bulk_hge_review_items');

        $result = bulk_hge_apply_with_review(TestDb::pdo(), [['graduate_id' => $confident, 'date' => '2024-04-02']], $review, 1, 'file.xls');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('add_bulk_hge_review.sql', $result['error']);
        $this->assertSame([], $this->applications());
    }
}
