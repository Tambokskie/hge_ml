<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;
use Tests\TestDb;

require_once __DIR__ . '/../../includes/graduates.php';

class GraduateSearchTest extends TestCase
{
    protected function setUp(): void
    {
        TestDb::resetSchema();
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO schools (school_name) VALUES ('ABC University'), ('XYZ College')");
        $pdo->exec("INSERT INTO users (name, username, email, password, role) VALUES ('Admin', 'admin', 'a@a.com', 'x', 'administrator')");
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number)
             VALUES (1, '2025', 'abc-2025.pdf', 'ABC_UNIVERSITY_2025_1.PDF', '/x/abc-2025.pdf', 10, SHA2('abc2025',256), 1, 1)"
        );
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number)
             VALUES (2, '2024', 'xyz-2024.pdf', 'XYZ_COLLEGE_2024_1.PDF', '/x/xyz-2024.pdf', 10, SHA2('xyz2024',256), 1, 1)"
        );
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number)
             VALUES (1, '2025', 'abc-2025-corrected.pdf', 'ABC_UNIVERSITY_2025_2.PDF', '/x/abc-2025-2.pdf', 10, SHA2('abc2025b',256), 1, 2)"
        );
    }

    private function insertGraduate(int $uploadId, int $schoolId, string $name, string $honor, ?string $date, int $year): void
    {
        $stmt = TestDb::pdo()->prepare(
            'INSERT INTO latin_honor_graduates (upload_id, school_id, graduate_name, latin_honor, graduation_date, graduation_year)
             VALUES (:upload_id, :school_id, :name, :honor, :date, :year)'
        );
        $stmt->execute([
            'upload_id' => $uploadId,
            'school_id' => $schoolId,
            'name' => $name,
            'honor' => $honor,
            'date' => $date,
            'year' => $year,
        ]);
    }

    public function testSearchByNameMatchesPartialCaseInsensitive(): void
    {
        $this->insertGraduate(1, 1, 'Juan Dela Cruz', 'CUM LAUDE', '2025-06-15', 2025);
        $this->insertGraduate(2, 2, 'Maria Santos', 'MAGNA CUM LAUDE', '2024-06-15', 2024);

        $result = search_graduates(TestDb::pdo(), ['name' => 'dela cruz'], 1);

        $this->assertCount(1, $result['rows']);
        $this->assertSame('Juan Dela Cruz', $result['rows'][0]['graduate_name']);
        $this->assertSame(1, $result['pagination']['total_rows']);
    }

    public function testCombinesSchoolAndYearFiltersWithAnd(): void
    {
        $this->insertGraduate(1, 1, 'Juan Dela Cruz', 'CUM LAUDE', '2025-06-15', 2025);
        $this->insertGraduate(2, 2, 'Juan Dela Cruz', 'CUM LAUDE', '2024-06-15', 2024);

        $result = search_graduates(TestDb::pdo(), ['school_id' => 1, 'graduation_year' => 2025], 1);

        $this->assertCount(1, $result['rows']);
        $this->assertSame('ABC University', $result['rows'][0]['school_name']);
        $this->assertSame('2025', (string) $result['rows'][0]['graduation_year']);
    }

    public function testSameNameFromTwoUploadsOfSameSchoolYearBothAppear(): void
    {
        $this->insertGraduate(1, 1, 'Juan Dela Cruz', 'CUM LAUDE', '2025-06-15', 2025);
        $this->insertGraduate(3, 1, 'Juan Dela Cruz', 'MAGNA CUM LAUDE', '2025-06-16', 2025);

        $result = search_graduates(TestDb::pdo(), ['name' => 'Juan Dela Cruz'], 1);

        $this->assertCount(2, $result['rows']);
        $uploadIds = array_map('intval', array_column($result['rows'], 'upload_id'));
        sort($uploadIds);
        $this->assertSame([1, 3], $uploadIds);
    }

    public function testPaginatesResultsAcrossPages(): void
    {
        foreach (['Ana Reyes', 'Ben Cruz', 'Carla Diaz'] as $name) {
            $this->insertGraduate(1, 1, $name, 'CUM LAUDE', '2025-06-15', 2025);
        }

        $page1 = search_graduates(TestDb::pdo(), [], 1, 2);
        $page2 = search_graduates(TestDb::pdo(), [], 2, 2);

        $this->assertCount(2, $page1['rows']);
        $this->assertCount(1, $page2['rows']);
        $this->assertSame(3, $page1['pagination']['total_rows']);
        $this->assertSame(2, $page1['pagination']['total_pages']);
    }

    public function testReturnsEmptyRowsForNoMatches(): void
    {
        $result = search_graduates(TestDb::pdo(), ['name' => 'Nobody Here'], 1);

        $this->assertSame([], $result['rows']);
        $this->assertSame(0, $result['pagination']['total_rows']);
    }

    public function testResultRowsIncludeUploadTraceabilityFields(): void
    {
        $this->insertGraduate(1, 1, 'Juan Dela Cruz', 'CUM LAUDE', '2025-06-15', 2025);

        $result = search_graduates(TestDb::pdo(), ['name' => 'Juan'], 1);

        $row = $result['rows'][0];
        $this->assertSame(1, (int) $row['upload_id']);
        $this->assertSame('abc-2025.pdf', $row['original_filename']);
        $this->assertArrayHasKey('uploaded_at', $row);
    }

    public function testBuildSearchLogDetailsIncludesOnlyPresentFilters(): void
    {
        $this->assertSame('school_id=3, year=2025, name=cruz', build_search_log_details(['school_id' => 3, 'graduation_year' => 2025, 'name' => 'cruz']));
        $this->assertSame('name=cruz', build_search_log_details(['name' => 'cruz']));
        $this->assertSame('', build_search_log_details([]));
        $this->assertSame('', build_search_log_details(['school_id' => null, 'graduation_year' => null, 'name' => '']));
    }

    public function testUnderscoreInNameSearchIsLiteralNotWildcard(): void
    {
        $this->insertGraduate(1, 1, 'A_B Test', 'CUM LAUDE', '2025-06-15', 2025);
        $this->insertGraduate(1, 1, 'AXB Test', 'CUM LAUDE', '2025-06-15', 2025);

        $result = search_graduates(TestDb::pdo(), ['name' => 'A_B'], 1);

        $this->assertCount(1, $result['rows']);
        $this->assertSame('A_B Test', $result['rows'][0]['graduate_name']);
    }
}
