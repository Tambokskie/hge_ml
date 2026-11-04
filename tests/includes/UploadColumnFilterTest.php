<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;
use Tests\TestDb;

require_once __DIR__ . '/../../includes/uploads.php';

/** The Excel-style column filters / sort / value lists of admin/school_records.php. */
class UploadColumnFilterTest extends TestCase
{
    protected function setUp(): void
    {
        TestDb::resetSchema();
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO schools (school_name, campus) VALUES ('ABC University', NULL), ('XYZ College', 'Main')");
        $pdo->exec("INSERT INTO users (name, username, email, password, role) VALUES ('Admin', 'admin', 'a@a.com', 'x', 'administrator')");
        $this->upload(1, '2025', 'a-2025.pdf', 'reviewed', 12, '2026-01-05 09:00:00');
        $this->upload(1, '2026', 'a-2026.pdf', 'pending', 0, '2026-02-10 09:00:00');
        $this->upload(2, '2026', 'x-2026.pdf', 'reviewed', 5, '2026-02-10 15:00:00');
    }

    private function upload(int $school, string $year, string $file, string $status, int $records, string $uploadedAt): void
    {
        TestDb::pdo()->prepare(
            'INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number, extraction_status, records_extracted, uploaded_at)
             VALUES (?, ?, ?, ?, ?, 10, SHA2(?, 256), 1, 1, ?, ?, ?)'
        )->execute([$school, $year, $file, strtoupper($file), '/x/' . $file, $file, $status, $records, $uploadedAt]);
    }

    private function files(array $filters): array
    {
        return array_column(search_uploads(TestDb::pdo(), $filters, 1)['rows'], 'original_filename');
    }

    public function testParsersKeepOnlyKnownColumns(): void
    {
        $this->assertSame(['status' => ['reviewed']], parse_upload_column_filters('{"status":["reviewed"],"bogus":["x"]}'));
        $this->assertSame(['', 'asc'], parse_upload_sort('bogus', 'desc'));
        $this->assertSame(['records', 'desc'], parse_upload_sort('records', 'desc'));
    }

    public function testFiltersByColumnValues(): void
    {
        $this->assertSame(['a-2025.pdf', 'x-2026.pdf'], $this->files(['columns' => ['status' => ['reviewed']], 'sort' => 'file', 'sort_dir' => 'asc']));
        $this->assertSame(['x-2026.pdf'], $this->files(['columns' => ['school' => ['XYZ College-Main']]]));
        $this->assertSame(['a-2025.pdf'], $this->files(['columns' => ['records' => ['12']]]));
        $this->assertSame(['a-2026.pdf', 'x-2026.pdf'], $this->files(['columns' => ['uploaded' => ['2026-02-10']], 'sort' => 'file']));
        $this->assertSame(['a-2025.pdf'], $this->files(['school_year' => '2025', 'columns' => ['school' => ['ABC University']]]));
    }

    public function testDefaultOrderPutsPendingFirstAndSortOverridesIt(): void
    {
        $this->assertSame('a-2026.pdf', $this->files([])[0]);
        $this->assertSame(['a-2025.pdf', 'x-2026.pdf', 'a-2026.pdf'], $this->files(['sort' => 'records', 'sort_dir' => 'desc']));
    }

    public function testCountFollowsColumnFilter(): void
    {
        $result = search_uploads(TestDb::pdo(), ['columns' => ['year' => ['2026']]], 1);
        $this->assertSame(2, $result['pagination']['total_rows']);
    }

    public function testColumnValuesIgnoreOwnFilterButHonorOthers(): void
    {
        $result = get_upload_column_values(TestDb::pdo(), ['columns' => ['status' => ['pending'], 'year' => ['2026']]], 'status');
        $this->assertSame([['value' => 'pending', 'count' => 1], ['value' => 'reviewed', 'count' => 1]], $result['values']);

        $records = get_upload_column_values(TestDb::pdo(), [], 'records');
        $this->assertSame(['0', '5', '12'], array_column($records['values'], 'value'));
        $this->assertSame(0, $records['blank_count']);

        $dates = get_upload_column_values(TestDb::pdo(), [], 'uploaded', '2026-02');
        $this->assertSame([['value' => '2026-02-10', 'count' => 2]], $dates['values']);
    }
}
