<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;
use Tests\TestDb;

require_once __DIR__ . '/../../includes/duplicates.php';
require_once __DIR__ . '/../../includes/bulk_hge.php';

class BulkHgeApplyTest extends TestCase
{
    protected function setUp(): void
    {
        TestDb::resetSchema();
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO users (name, username, email, password, role) VALUES ('Admin', 'admin', 'a@a.com', 'x', 'administrator'), ('Other', 'other', 'o@a.com', 'x', 'administrator')");
        $pdo->exec("INSERT INTO schools (school_name, campus) VALUES ('ABC UNIVERSITY', 'MAIN')");
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number, extraction_status, records_extracted)
             VALUES (1, '2025', 'a.pdf', 'a.pdf', '/x/a.pdf', 10, SHA2('a',256), 1, 1, 'reviewed', 3)"
        );
        foreach (['ONE, A.', 'TWO, B.', 'THREE, C.'] as $name) {
            $pdo->prepare("INSERT INTO latin_honor_graduates (upload_id, school_id, graduate_name, course, latin_honor, graduation_year) VALUES (1, 1, ?, 'BSA', 'CUM LAUDE', 2025)")->execute([$name]);
        }
    }

    private function applications(): array
    {
        return TestDb::pdo()->query('SELECT graduate_id, applied_by, application_date, approved_at, approved_by FROM graduate_applications ORDER BY graduate_id')->fetchAll();
    }

    public function testFilesEveryPairWithItsDateAndTheAdmin(): void
    {
        $result = bulk_hge_apply(TestDb::pdo(), [
            ['graduate_id' => 1, 'date' => '2024-04-02'],
            ['graduate_id' => 3, 'date' => '2025-01-15'],
        ], 2);

        $this->assertSame(['ok' => true, 'filed' => 2, 'approved' => 0, 'skipped' => 0], $result);
        $this->assertSame([
            ['graduate_id' => 1, 'applied_by' => 2, 'application_date' => '2024-04-02'],
            ['graduate_id' => 3, 'applied_by' => 2, 'application_date' => '2025-01-15'],
        ], array_map(static fn (array $r): array => array_map('intval', array_slice($r, 0, 2)) + ['application_date' => $r['application_date']], $this->applications()));
    }

    public function testAnAlreadyFiledGraduateIsApprovedAndKeepsItsOriginalFilingDate(): void
    {
        TestDb::pdo()->exec("INSERT INTO graduate_applications (graduate_id, applied_by, application_date) VALUES (2, 1, '2023-03-03')");

        $result = bulk_hge_apply(TestDb::pdo(), [
            ['graduate_id' => 1, 'date' => '2024-04-02'],
            ['graduate_id' => 2, 'date' => '2024-04-02'],
        ], 2);

        $this->assertSame(['ok' => true, 'filed' => 1, 'approved' => 1, 'skipped' => 0], $result);
        $rows = $this->applications();
        $this->assertCount(2, $rows);
        $this->assertSame('2023-03-03', $rows[1]['application_date']);
        $this->assertSame('1', (string) $rows[1]['applied_by']);
        $this->assertSame('2024-04-02', $rows[1]['approved_at']);
        $this->assertSame('2', (string) $rows[1]['approved_by']);
    }

    public function testAVanishedGraduateIsSkippedNotFatal(): void
    {
        $result = bulk_hge_apply(TestDb::pdo(), [
            ['graduate_id' => 999, 'date' => '2024-04-02'],
            ['graduate_id' => 1, 'date' => '2024-04-02'],
        ], 1);

        $this->assertSame(['ok' => true, 'filed' => 1, 'approved' => 0, 'skipped' => 1], $result);
    }

    public function testRunningTheSameBatchTwiceApprovesTheSecondTimeRatherThanSkipping(): void
    {
        $pairs = [['graduate_id' => 1, 'date' => '2024-04-02'], ['graduate_id' => 2, 'date' => '2024-04-03']];

        $first = bulk_hge_apply(TestDb::pdo(), $pairs, 1);
        $second = bulk_hge_apply(TestDb::pdo(), $pairs, 1);

        $this->assertSame(2, $first['filed']);
        $this->assertSame(['ok' => true, 'filed' => 0, 'approved' => 2, 'skipped' => 0], $second);
        $this->assertCount(2, $this->applications());
    }

    public function testAnUnexpectedDatabaseErrorRollsEverythingBack(): void
    {
        // Mocked so the failure does not depend on the server's SQL mode
        // (an invalid date only errors under STRICT_TRANS_TABLES).
        $statement = $this->createMock(\PDOStatement::class);
        $statement->method('execute')->willReturnCallback(function (): bool {
            static $calls = 0;
            if (++$calls === 2) {
                throw new \PDOException('connection lost');
            }

            return true;
        });

        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturn($statement);
        $pdo->expects($this->once())->method('beginTransaction')->willReturn(true);
        $pdo->expects($this->never())->method('commit');
        $pdo->expects($this->once())->method('rollBack')->willReturn(true);

        $result = bulk_hge_apply($pdo, [
            ['graduate_id' => 1, 'date' => '2024-04-02'],
            ['graduate_id' => 2, 'date' => '2024-04-03'],
        ], 1);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('NOTHING WAS CHANGED', $result['error']);
    }

    public function testEmptyBatchFilesNothing(): void
    {
        $this->assertSame(['ok' => true, 'filed' => 0, 'approved' => 0, 'skipped' => 0], bulk_hge_apply(TestDb::pdo(), [], 1));
    }
}
