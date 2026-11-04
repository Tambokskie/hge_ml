<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;
use Tests\TestDb;

require_once __DIR__ . '/../../includes/graduates.php';

/** The Excel-style column filters / sort / value lists of admin/lookup.php. */
class GraduateColumnFilterTest extends TestCase
{
    protected function setUp(): void
    {
        TestDb::resetSchema();
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO schools (school_name, campus) VALUES ('ABC University', NULL), ('XYZ College', 'Main')");
        $pdo->exec("INSERT INTO users (name, username, email, password, role) VALUES ('Admin', 'admin', 'a@a.com', 'x', 'administrator')");
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number)
             VALUES (1, '2026', 'a.pdf', 'A_2026_1.PDF', '/x/a.pdf', 10, SHA2('a',256), 1, 1),
                    (2, '2026', 'x.pdf', 'X_2026_1.PDF', '/x/x.pdf', 10, SHA2('x',256), 1, 1)"
        );
        $this->graduate(1, 1, 'ANA REYES', 'BSIT', 'CUM LAUDE', '2025-07-05', 2025);
        $this->graduate(2, 1, 'BEN CRUZ', 'BSIT', 'MAGNA CUM LAUDE', '2025-07-05', 2025);
        $this->graduate(3, 2, 'CARL DIAZ', 'BSCS', 'CUM LAUDE', '2025-08-01', 2025);
        $this->graduate(4, 2, 'DINA LOPEZ', null, 'CUM LAUDE', null, 2025);
        // ANA: filed + approved, CARL: filed only
        $pdo->exec("INSERT INTO graduate_applications (graduate_id, applied_by, application_date, approved_at, approved_by) VALUES (1, 1, '2026-03-09', '2026-03-10', 1)");
        $pdo->exec("INSERT INTO graduate_applications (graduate_id, applied_by, application_date) VALUES (3, 1, '2026-03-11')");
    }

    private function graduate(int $id, int $school, string $name, ?string $course, string $honor, ?string $date, int $year): void
    {
        TestDb::pdo()->prepare(
            'INSERT INTO latin_honor_graduates (id, upload_id, school_id, graduate_name, course, latin_honor, graduation_date, graduation_year)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$id, $school, $school, $name, $course ?? '', $honor, $date, $year]);
    }

    private function names(array $filters): array
    {
        $result = search_graduates(TestDb::pdo(), $filters, 1);

        return array_column($result['rows'], 'graduate_name');
    }

    public function testParseKeepsOnlyKnownColumnsAndStringValues(): void
    {
        $parsed = parse_graduate_column_filters('{"school":["A","B",null,5],"bogus":["x"],"honor":[]}');
        $this->assertSame(['school' => ['A', 'B', null]], $parsed);
        $this->assertSame([], parse_graduate_column_filters('not json'));
        $this->assertSame([], parse_graduate_column_filters(null));
    }

    public function testParseSortFallsBackForUnknownColumn(): void
    {
        $this->assertSame(['', 'asc'], parse_graduate_sort('bogus', 'desc'));
        $this->assertSame(['honor', 'desc'], parse_graduate_sort('honor', 'desc'));
        $this->assertSame(['honor', 'asc'], parse_graduate_sort('honor', 'sideways'));
    }

    public function testFilterByValuesOfOneColumnAndAcrossColumns(): void
    {
        $this->assertSame(['ANA REYES', 'BEN CRUZ'], $this->names(['columns' => ['course' => ['BSIT']]]));
        $this->assertSame(['ANA REYES', 'CARL DIAZ'], $this->names(['columns' => ['honor' => ['CUM LAUDE'], 'status' => ['APPROVED', 'PROCESSING']]]));
        $this->assertSame(['ANA REYES'], $this->names(['columns' => ['honor' => ['CUM LAUDE'], 'course' => ['BSIT']]]));
    }

    public function testBlanksAndSchoolDisplayName(): void
    {
        $this->assertSame(['DINA LOPEZ'], $this->names(['columns' => ['course' => [null]]]));
        $this->assertSame(['BEN CRUZ', 'DINA LOPEZ'], $this->names(['columns' => ['course' => [null, 'BSIT'], 'honor' => ['MAGNA CUM LAUDE', 'CUM LAUDE'], 'graduated' => ['2025-07-05', null], 'name' => ['BEN CRUZ', 'DINA LOPEZ']]]));
        $this->assertSame(['CARL DIAZ', 'DINA LOPEZ'], $this->names(['columns' => ['school' => ['XYZ College-Main']]]));
    }

    public function testDatesFilterByActualDate(): void
    {
        $this->assertSame(['ANA REYES', 'BEN CRUZ'], $this->names(['columns' => ['graduated' => ['2025-07-05']]]));
        $this->assertSame(['ANA REYES'], $this->names(['columns' => ['approved' => ['2026-03-10']]]));
        $this->assertSame(['CARL DIAZ'], $this->names(['columns' => ['applied' => ['2026-03-11']]]));
    }

    public function testPaginationCountMatchesColumnFilter(): void
    {
        $result = search_graduates(TestDb::pdo(), ['columns' => ['school' => ['ABC University']]], 1);
        $this->assertSame(2, $result['pagination']['total_rows']);
    }

    public function testSortDescendingByColumn(): void
    {
        $this->assertSame(['DINA LOPEZ', 'CARL DIAZ', 'BEN CRUZ', 'ANA REYES'], $this->names(['sort' => 'name', 'sort_dir' => 'desc']));
        $this->assertSame(['CARL DIAZ', 'ANA REYES', 'BEN CRUZ', 'DINA LOPEZ'], $this->names(['sort' => 'graduated', 'sort_dir' => 'desc']));
    }

    public function testColumnValuesIgnoreOwnFilterButHonorOthers(): void
    {
        $pdo = TestDb::pdo();
        // the honor filter does not shrink the honor list itself, the course filter does
        $result = get_graduate_column_values($pdo, ['columns' => ['honor' => ['CUM LAUDE'], 'course' => ['BSIT']]], 'honor');
        $this->assertSame([['value' => 'CUM LAUDE', 'count' => 1], ['value' => 'MAGNA CUM LAUDE', 'count' => 1]], $result['values']);
        $this->assertSame(0, $result['blank_count']);
        $this->assertFalse($result['truncated']);
    }

    public function testColumnValuesBlanksSearchAndLimit(): void
    {
        $pdo = TestDb::pdo();
        $courses = get_graduate_column_values($pdo, [], 'course');
        $this->assertSame(1, $courses['blank_count']);
        $this->assertSame(['BSCS', 'BSIT'], array_column($courses['values'], 'value'));

        $searched = get_graduate_column_values($pdo, [], 'course', 'IT');
        $this->assertSame(['BSIT'], array_column($searched['values'], 'value'));
        $this->assertSame(0, $searched['blank_count']);

        $dates = get_graduate_column_values($pdo, [], 'graduated', '', 1);
        $this->assertTrue($dates['truncated']);
        $this->assertSame([['value' => '2025-07-05', 'count' => 2]], $dates['values']);

        $status = get_graduate_column_values($pdo, [], 'status');
        $this->assertSame(['APPROVED', 'PROCESSING'], array_column($status['values'], 'value'));
        $this->assertSame(2, $status['blank_count']);
    }

    public function testColumnValuesRespectNeedsActionStatus(): void
    {
        $result = get_graduate_column_values(TestDb::pdo(), ['status' => 'pending'], 'name');
        $this->assertSame(['CARL DIAZ'], array_column($result['values'], 'value'));
    }
}
