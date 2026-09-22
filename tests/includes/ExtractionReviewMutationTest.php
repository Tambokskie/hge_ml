<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/extraction_review.php';

class ExtractionReviewMutationTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
        start_extraction_review(1, [
            ['graduate_name' => 'Juan Dela Cruz', 'latin_honor' => 'CUM LAUDE', 'graduation_date' => null, 'graduation_year' => 2026],
            ['graduate_name' => 'Maria Santos', 'latin_honor' => 'MAGNA CUM LAUDE', 'graduation_date' => null, 'graduation_year' => 2026],
        ]);
    }

    public function testSetStagedRowUpdatesExistingRowByIndex(): void
    {
        $result = set_staged_row(1, 0, [
            'graduate_name' => 'Juan D. Cruz',
            'latin_honor' => 'SUMMA CUM LAUDE',
            'graduation_date' => '2026-07-02',
            'graduation_year' => 2026,
        ]);

        $this->assertTrue($result['ok']);
        $rows = get_extraction_review(1)['rows'];
        $this->assertCount(2, $rows);
        $this->assertSame('Juan D. Cruz', $rows[0]['graduate_name']);
        $this->assertSame('SUMMA CUM LAUDE', $rows[0]['latin_honor']);
    }

    public function testSetStagedRowStoresTrimmedCourse(): void
    {
        $result = set_staged_row(1, 0, [
            'graduate_name' => 'Juan D. Cruz',
            'course' => '  BS Computer Science  ',
            'latin_honor' => 'CUM LAUDE',
            'graduation_date' => null,
            'graduation_year' => 2026,
        ]);

        $this->assertTrue($result['ok']);
        $rows = get_extraction_review(1)['rows'];
        $this->assertSame('BS Computer Science', $rows[0]['course']);
    }

    public function testSetStagedRowDefaultsCourseToEmptyStringWhenOmitted(): void
    {
        $result = set_staged_row(1, 0, [
            'graduate_name' => 'Juan D. Cruz',
            'latin_honor' => 'CUM LAUDE',
            'graduation_date' => null,
            'graduation_year' => 2026,
        ]);

        $this->assertTrue($result['ok']);
        $rows = get_extraction_review(1)['rows'];
        $this->assertSame('', $rows[0]['course']);
    }

    public function testSetStagedRowAppendsNewRowWhenIndexIsNull(): void
    {
        $result = set_staged_row(1, null, [
            'graduate_name' => 'Pedro Reyes',
            'latin_honor' => 'CUM LAUDE',
            'graduation_date' => null,
            'graduation_year' => 2026,
        ]);

        $this->assertTrue($result['ok']);
        $rows = get_extraction_review(1)['rows'];
        $this->assertCount(3, $rows);
        $this->assertSame('Pedro Reyes', $rows[2]['graduate_name']);
    }

    public function testSetStagedRowFailsWithoutActiveReview(): void
    {
        clear_extraction_review();

        $result = set_staged_row(1, null, ['graduate_name' => 'X', 'latin_honor' => 'CUM LAUDE', 'graduation_date' => null, 'graduation_year' => 2026]);

        $this->assertFalse($result['ok']);
    }

    public function testRemoveStagedRowDeletesByIndex(): void
    {
        $result = remove_staged_row(1, 0);

        $this->assertTrue($result['ok']);
        $rows = get_extraction_review(1)['rows'];
        $this->assertCount(1, $rows);
        $this->assertSame('Maria Santos', $rows[0]['graduate_name']);
    }

    public function testRemoveStagedRowReindexesRemainingRows(): void
    {
        remove_staged_row(1, 0);
        $rows = get_extraction_review(1)['rows'];

        // The remaining row must be at index 0, not 1, after removal.
        $this->assertArrayHasKey(0, $rows);
        $this->assertArrayNotHasKey(1, $rows);
    }

    public function testRemoveStagedRowFailsForUnknownIndex(): void
    {
        $result = remove_staged_row(1, 99);

        $this->assertFalse($result['ok']);
    }

    public function testMassUpdateStagedGraduationDateSetsDateAndYearOnEveryRow(): void
    {
        $result = mass_update_staged_graduation_date(1, '2026-07-02');

        $this->assertTrue($result['ok']);
        $this->assertSame(2, $result['updated']);

        $rows = get_extraction_review(1)['rows'];
        foreach ($rows as $row) {
            $this->assertSame('2026-07-02', $row['graduation_date']);
            $this->assertSame(2026, $row['graduation_year']);
        }
    }

    public function testMassUpdateStagedGraduationDateLeavesNamesAndHonorsUntouched(): void
    {
        mass_update_staged_graduation_date(1, '2026-07-02');

        $rows = get_extraction_review(1)['rows'];
        $this->assertSame('Juan Dela Cruz', $rows[0]['graduate_name']);
        $this->assertSame('MAGNA CUM LAUDE', $rows[1]['latin_honor']);
    }

    public function testMassUpdateStagedGraduationDateRejectsInvalidDate(): void
    {
        $result = mass_update_staged_graduation_date(1, '2026-13-40');

        $this->assertFalse($result['ok']);
        $rows = get_extraction_review(1)['rows'];
        $this->assertNull($rows[0]['graduation_date']);
    }

    public function testMassUpdateStagedGraduationDateFailsWithoutActiveReview(): void
    {
        clear_extraction_review();

        $result = mass_update_staged_graduation_date(1, '2026-07-02');

        $this->assertFalse($result['ok']);
    }

    public function testMassUpdateStagedGraduationDateFailsWhenNoRowsStaged(): void
    {
        start_extraction_review(2, []);

        $result = mass_update_staged_graduation_date(2, '2026-07-02');

        $this->assertFalse($result['ok']);
    }
}
