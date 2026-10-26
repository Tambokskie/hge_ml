<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;
use Tests\TestDb;

require_once __DIR__ . '/../../includes/graduates.php';
require_once __DIR__ . '/../../includes/duplicates.php';
require_once __DIR__ . '/../../includes/bulk_hge.php';

/** The date applied / date approved may not be in a year before the graduate's graduation year. */
class ApplicationDateYearRuleTest extends TestCase
{
    protected function setUp(): void
    {
        TestDb::resetSchema();
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO schools (school_name) VALUES ('ABC University')");
        $pdo->exec("INSERT INTO users (name, username, email, password, role) VALUES ('Admin', 'admin', 'a@a.com', 'x', 'administrator')");
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number)
             VALUES (1, '2026', 'abc-2026.pdf', 'ABC_UNIVERSITY_2026_1.PDF', '/x/abc-2026.pdf', 10, SHA2('abc2026',256), 1, 1)"
        );
    }

    private function graduate(string $name, ?string $date, int $year): int
    {
        TestDb::pdo()->prepare(
            "INSERT INTO latin_honor_graduates (upload_id, school_id, graduate_name, course, latin_honor, graduation_date, graduation_year)
             VALUES (1, 1, :name, 'BSIT', 'CUM LAUDE', :date, :year)"
        )->execute(['name' => $name, 'date' => $date, 'year' => $year]);

        return (int) TestDb::pdo()->lastInsertId();
    }

    private function application(int $graduateId, string $applied, ?string $approved): void
    {
        TestDb::pdo()->prepare(
            'INSERT INTO graduate_applications (graduate_id, applied_by, application_date, approved_at, approved_by) VALUES (?, 1, ?, ?, ?)'
        )->execute([$graduateId, $applied, $approved, $approved === null ? null : 1]);
    }

    public function testTheRuleOnlyRejectsAYearBeforeTheGraduationYear(): void
    {
        $this->assertNull(application_date_year_error(2026, '2026-01-01', 'DATE OF APPLICATION'));
        $this->assertNull(application_date_year_error(2026, '2027-03-05', 'DATE OF APPLICATION'));
        $message = application_date_year_error(2026, '2025-12-31', 'DATE OF APPLICATION');
        $this->assertStringContainsString('DATE OF APPLICATION (2025) IS INVALID', (string) $message);
        $this->assertStringContainsString('(2026)', (string) $message);
    }

    public function testTheGraduationDatesYearWinsOverAMismatchedStoredYear(): void
    {
        $this->assertSame(2025, graduate_effective_graduation_year('2025-06-04', 2026));
        $this->assertSame(2026, graduate_effective_graduation_year(null, 2026));
    }

    public function testFilingWithAYearBeforeGraduationIsRefusedAndNothingIsSaved(): void
    {
        $id = $this->graduate('Juan Dela Cruz', '2026-06-30', 2026);

        $result = file_application(TestDb::pdo(), $id, 1, '2022-10-18');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('INVALID', $result['error']);
        $this->assertSame('0', (string) TestDb::pdo()->query('SELECT COUNT(*) FROM graduate_applications')->fetchColumn());
    }

    public function testFilingInTheGraduationYearOrLaterIsAccepted(): void
    {
        $id = $this->graduate('Juan Dela Cruz', '2026-06-30', 2026);

        $this->assertTrue(file_application(TestDb::pdo(), $id, 1, '2026-01-02')['ok']);
    }

    public function testApprovingWithAYearBeforeGraduationIsRefusedAndTheFilingIsKept(): void
    {
        $id = $this->graduate('Maria Santos', null, 2026);
        $this->application($id, '2026-07-01', null);

        $result = approve_application(TestDb::pdo(), $id, 1, '2024-05-05');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('DATE OF APPROVAL (2024) IS INVALID', $result['error']);
        $this->assertNull(TestDb::pdo()->query('SELECT approved_at FROM graduate_applications')->fetchColumn() ?: null);
        $this->assertTrue(approve_application(TestDb::pdo(), $id, 1, '2026-08-01')['ok']);
    }

    public function testTheAuditFindsOnlyApplicationsWithAYearBeforeGraduation(): void
    {
        $bad = $this->graduate('Bad Applied', '2026-06-30', 2026);
        $badApproved = $this->graduate('Bad Approved', null, 2026);
        $good = $this->graduate('Good One', '2026-06-30', 2026);
        $none = $this->graduate('Never Applied', '2026-06-30', 2026);
        $this->application($bad, '2022-10-18', '2022-10-18');
        $this->application($badApproved, '2026-02-01', '2025-03-03');
        $this->application($good, '2026-01-01', '2026-02-02');

        $found = find_invalid_application_dates(TestDb::pdo());

        $this->assertSame([$bad, $badApproved], array_map(static fn (array $r): int => (int) $r['graduate_id'], $found));
        $this->assertNotContains($good, array_column($found, 'graduate_id'));
        $this->assertNotContains($none, array_column($found, 'graduate_id'));
    }

    public function testTheCleanupRemovesTheInvalidApplicationsAndLeavesTheRest(): void
    {
        $bad = $this->graduate('Bad Applied', '2026-06-30', 2026);
        $good = $this->graduate('Good One', '2026-06-30', 2026);
        $this->application($bad, '2022-10-18', '2022-10-18');
        $this->application($good, '2026-01-01', '2026-02-02');

        $removed = remove_invalid_application_dates(TestDb::pdo());

        $this->assertCount(1, $removed);
        $this->assertSame($bad, (int) $removed[0]['graduate_id']);
        $left = TestDb::pdo()->query('SELECT graduate_id FROM graduate_applications')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertEquals([$good], $left);
        $this->assertSame([], find_invalid_application_dates(TestDb::pdo()));
        $this->assertSame([], remove_invalid_application_dates(TestDb::pdo()));
    }

    public function testTheBulkUpdateLeavesOutADateBeforeTheGraduationYearAndFilesTheRest(): void
    {
        $early = $this->graduate('Early Date', '2026-06-30', 2026);
        $fine = $this->graduate('Fine Date', '2026-06-30', 2026);

        $result = bulk_hge_insert_pairs(TestDb::pdo(), [
            ['graduate_id' => $early, 'date' => '2024-01-10'],
            ['graduate_id' => $fine, 'date' => '2026-09-01'],
        ], 1);

        $this->assertSame(1, $result['invalid_date']);
        $this->assertSame(1, $result['filed']);
        $ids = TestDb::pdo()->query('SELECT graduate_id FROM graduate_applications')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertEquals([$fine], $ids);
    }
    public function testThePreviewReportsAMatchedRowWithAnEarlyDateInsteadOfFilingIt(): void
    {
        $early = $this->graduate('LOPEZ, MARK', '2026-06-30', 2026);
        $fine = $this->graduate('REYES, ANA', '2026-06-30', 2026);
        $person = static fn (string $last, string $first, string $date, int $row): array => [
            'row' => $row, 'date' => $date, 'last' => $last, 'first' => $first, 'mi' => '', 'middle' => '', 'ext' => '',
        ];

        $preview = bulk_hge_build_preview(TestDb::pdo(), [
            $person('Lopez', 'Mark', '2024-04-02', 5),
            $person('Reyes', 'Ana', '2026-09-01', 6),
        ]);

        $this->assertEquals([['graduate_id' => $fine, 'date' => '2026-09-01']], $preview['to_file']);
        $this->assertSame(1, $preview['counts']['invalid_date']);
        $this->assertSame(1, $preview['counts']['invalid']);
        $this->assertSame(1, $preview['counts']['to_file']);
        $this->assertSame(5, $preview['date_errors'][0]['row']);
        $this->assertStringContainsString('IS INVALID', $preview['date_errors'][0]['message']);
        $this->assertNotContains($early, array_column($preview['to_file'], 'graduate_id'));
    }
}
