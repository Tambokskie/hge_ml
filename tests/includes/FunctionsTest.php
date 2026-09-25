<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/functions.php';

class FunctionsTest extends TestCase
{
    public function testEEscapesHtml(): void
    {
        $this->assertSame('&lt;b&gt;hi&lt;/b&gt;', e('<b>hi</b>'));
        $this->assertSame('', e(null));
    }

    public function testEUpperEscapesAndUppercases(): void
    {
        $this->assertSame('JUAN DELA CRUZ', e_upper('juan dela cruz'));
    }

    public function testFormatDateDisplayFormatsKnownDate(): void
    {
        $this->assertSame('JUNE 15, 2025', format_date_display('2025-06-15'));
    }

    public function testFormatDateDisplayHandlesMissingOrInvalidDate(): void
    {
        $this->assertSame('—', format_date_display(null));
        $this->assertSame('—', format_date_display('not-a-date'));
    }

    public function testDaysSinceComputesWholeDaysElapsed(): void
    {
        $this->assertSame(0, days_since(date('Y-m-d H:i:s')));
        $this->assertSame(3, days_since(date('Y-m-d H:i:s', strtotime('-3 days'))));
    }

    public function testDaysSinceReturnsZeroForInvalidDatetime(): void
    {
        $this->assertSame(0, days_since('not-a-date'));
    }

    public function testUploadStatusPillReturnsKnownLabelsAndClasses(): void
    {
        $reviewed = upload_status_pill('reviewed');
        $this->assertSame('REVIEWED', $reviewed['label']);
        $this->assertStringContainsString('green', $reviewed['class']);

        $pending = upload_status_pill('pending');
        $this->assertSame('PENDING EXTRACTION', $pending['label']);
    }

    public function testUploadStatusPillFallsBackForUnknownStatus(): void
    {
        $pill = upload_status_pill('something_else');
        $this->assertSame('SOMETHING_ELSE', $pill['label']);
    }

    /** @dataProvider aiQuotaGaugeTierCases */
    public function testAiQuotaGaugeTierMatchesPercentThresholds(int $percent, string $expectedLabel, string $colorFragment): void
    {
        $tier = ai_quota_gauge_tier($percent);

        $this->assertSame($expectedLabel, $tier['label']);
        $this->assertStringContainsString($colorFragment, $tier['bar_class']);
    }

    public static function aiQuotaGaugeTierCases(): array
    {
        return [
            'zero is green' => [0, 'AVAILABLE', 'green'],
            'just under half is still green' => [49, 'AVAILABLE', 'green'],
            'half is yellow' => [50, 'MODERATE USAGE', 'yellow'],
            'just under three-quarters is still yellow' => [74, 'MODERATE USAGE', 'yellow'],
            'three-quarters is orange' => [75, 'NEARING LIMIT', 'orange'],
            'just under full is still orange' => [99, 'NEARING LIMIT', 'orange'],
            'full is red' => [100, 'LIMIT REACHED', 'red'],
        ];
    }

    public function testFormatSchoolDisplayNameAppendsCampusWhenPresent(): void
    {
        $this->assertSame('ABC UNIVERSITY-MAIN CAMPUS', format_school_display_name('ABC UNIVERSITY', 'MAIN CAMPUS'));
    }

    public function testFormatSchoolDisplayNameOmitsDashWhenNoCampus(): void
    {
        $this->assertSame('ABC UNIVERSITY', format_school_display_name('ABC UNIVERSITY', ''));
        $this->assertSame('ABC UNIVERSITY', format_school_display_name('ABC UNIVERSITY', null));
        $this->assertSame('ABC UNIVERSITY', format_school_display_name('ABC UNIVERSITY', '   '));
    }

    public function testPendingUploadAgeClassIsEmptyWhenRecordsAlreadyExtracted(): void
    {
        $upload = ['records_extracted' => 5, 'uploaded_at' => date('Y-m-d H:i:s', strtotime('-10 days'))];
        $this->assertSame('', pending_upload_age_class($upload));
    }

    public function testPendingUploadAgeClassIsEmptyUnderThreeDays(): void
    {
        $upload = ['records_extracted' => 0, 'uploaded_at' => date('Y-m-d H:i:s', strtotime('-2 days'))];
        $this->assertSame('', pending_upload_age_class($upload));
    }

    public function testPendingUploadAgeClassIsWarningBetweenThreeAndSixDays(): void
    {
        $threeDays = ['records_extracted' => 0, 'uploaded_at' => date('Y-m-d H:i:s', strtotime('-3 days'))];
        $sixDays = ['records_extracted' => 0, 'uploaded_at' => date('Y-m-d H:i:s', strtotime('-6 days'))];

        $this->assertStringContainsString('border-orange-400', pending_upload_age_class($threeDays));
        $this->assertStringContainsString('border-orange-400', pending_upload_age_class($sixDays));
        $this->assertStringNotContainsString('border-red-400', pending_upload_age_class($threeDays));
    }

    public function testPendingUploadAgeClassIsCriticalPastSixDays(): void
    {
        $upload = ['records_extracted' => 0, 'uploaded_at' => date('Y-m-d H:i:s', strtotime('-7 days'))];

        $class = pending_upload_age_class($upload);
        $this->assertStringContainsString('border-red-400', $class);
        $this->assertStringContainsString('motion-safe:animate-pending-critical-pulse', $class);
    }

    public function testPendingUploadAgeClassHandlesMissingUploadedAt(): void
    {
        $this->assertSame('', pending_upload_age_class(['records_extracted' => 0, 'uploaded_at' => '']));
    }

    public function testPaginateClampsPageWithinRange(): void
    {
        $result = paginate(totalRows: 5, page: 99, perPage: 25);
        $this->assertSame(1, $result['page']);
        $this->assertSame(1, $result['total_pages']);
        $this->assertSame(0, $result['offset']);
    }

    public function testPaginateComputesOffsetForMiddlePage(): void
    {
        $result = paginate(totalRows: 120, page: 3, perPage: 25);
        $this->assertSame(3, $result['page']);
        $this->assertSame(5, $result['total_pages']);
        $this->assertSame(50, $result['offset']);
    }

    public function testValidateRemovalReasonAcceptsPlainText(): void
    {
        $result = validate_removal_reason('Duplicate entry');
        $this->assertTrue($result['ok']);
    }

    public function testValidateRemovalReasonRejectsEmptyString(): void
    {
        $this->assertFalse(validate_removal_reason('')['ok']);
        $this->assertFalse(validate_removal_reason('   ')['ok']);
    }

    public function testValidateRemovalReasonRejectsNotApplicablePlaceholder(): void
    {
        $this->assertFalse(validate_removal_reason('N/A')['ok']);
        $this->assertFalse(validate_removal_reason('n/a')['ok']);
    }

    public function testValidateRemovalReasonRejectsBareDash(): void
    {
        $this->assertFalse(validate_removal_reason('-')['ok']);
    }

    public function testValidateRemovalReasonRejectsSpecialCharacters(): void
    {
        $this->assertFalse(validate_removal_reason('Duplicate!!!')['ok']);
        $this->assertFalse(validate_removal_reason('@#$%')['ok']);
    }

    public function testValidateRemovalReasonRejectsDigitsOnly(): void
    {
        $this->assertFalse(validate_removal_reason('12345')['ok']);
    }

    public function testSplitGraduateNameHandlesCommaFormat(): void
    {
        $this->assertSame(
            ['last_name' => 'Dela Cruz', 'given_name' => 'Juan', 'middle_name' => 'Santos'],
            split_graduate_name('Dela Cruz, Juan Santos')
        );
    }

    public function testSplitGraduateNameHandlesCommaFormatWithNoMiddleName(): void
    {
        $this->assertSame(
            ['last_name' => 'Reyes', 'given_name' => 'Maria', 'middle_name' => ''],
            split_graduate_name('Reyes, Maria')
        );
    }

    public function testSplitGraduateNameGuessesSurnameBoundaryWithoutComma(): void
    {
        // No comma: first word is assumed to be the surname, last word the
        // middle name — a best-effort guess a human reviews, not a
        // guarantee (see the function's own docblock).
        $this->assertSame(
            ['last_name' => 'ABAJON', 'given_name' => 'JEANY MAE', 'middle_name' => 'LUCANAS'],
            split_graduate_name('ABAJON JEANY MAE LUCANAS')
        );
    }

    public function testSplitGraduateNameKeepsMultiWordSurnamePrefixTogether(): void
    {
        // "DELA" alone is a recognized surname-prefix word, so it must stay
        // glued to "CRUZ" rather than being treated as the whole surname.
        $this->assertSame(
            ['last_name' => 'DELA CRUZ', 'given_name' => 'JUAN', 'middle_name' => 'SANTOS'],
            split_graduate_name('DELA CRUZ JUAN SANTOS')
        );
    }

    public function testSplitGraduateNameHandlesSingleWordRemainder(): void
    {
        $this->assertSame(
            ['last_name' => 'SANTOS', 'given_name' => 'PEDRO', 'middle_name' => ''],
            split_graduate_name('SANTOS PEDRO')
        );
    }

    public function testSplitGraduateNameHandlesEmptyInput(): void
    {
        $this->assertSame(
            ['last_name' => '', 'given_name' => '', 'middle_name' => ''],
            split_graduate_name('')
        );
    }

    public function testCombineGraduateNameIsTheInverseOfSplit(): void
    {
        $this->assertSame('Dela Cruz, Juan Santos', combine_graduate_name('Dela Cruz', 'Juan', 'Santos'));
    }

    public function testCombineGraduateNameOmitsCommaWhenMiddleNameIsBlank(): void
    {
        $this->assertSame('Reyes, Maria', combine_graduate_name('Reyes', 'Maria', ''));
    }

    public function testCombineGraduateNameHandlesBlankLastName(): void
    {
        $this->assertSame('Maria', combine_graduate_name('', 'Maria', ''));
    }
}
