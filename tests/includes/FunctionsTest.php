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

    /** @dataProvider aiAvailabilityTierCases */
    public function testAiAvailabilityTierMatchesAvailablePercentThresholds(int $usedPercent, string $expectedLabel, string $colorFragment, int $expectedAvailablePercent): void
    {
        $tier = ai_availability_tier($usedPercent);

        $this->assertSame($expectedLabel, $tier['label']);
        $this->assertStringContainsString($colorFragment, $tier['bar_class']);
        $this->assertSame($expectedAvailablePercent, $tier['available_percent']);
    }

    public static function aiAvailabilityTierCases(): array
    {
        return [
            // usedPercent, expectedLabel, colorFragment, availablePercent
            'no usage at all is green' => [0, 'AVAILABLE', 'green', 100],
            'exactly 40% used (60% available) is still green' => [40, 'AVAILABLE', 'green', 60],
            'just over 40% used (just under 60% available) is yellow' => [41, 'MODERATE USAGE', 'yellow', 59],
            'exactly 60% used (40% available) is still yellow' => [60, 'MODERATE USAGE', 'yellow', 40],
            'just over 60% used (just under 40% available) is red' => [61, 'LOW AVAILABILITY', 'red', 39],
            'fully used (0% available) is red' => [100, 'LOW AVAILABILITY', 'red', 0],
        ];
    }

    public function testAiAvailabilityTierNeverGoesNegativeIfUsedPercentExceedsOneHundred(): void
    {
        // getUsageStats() caps at 100, but this guards the formula itself
        // against ever reporting a negative available percent.
        $tier = ai_availability_tier(150);

        $this->assertSame('LOW AVAILABILITY', $tier['label']);
        $this->assertSame(0, $tier['available_percent']);
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

    public function testNormalizeNameForMatchingUppercasesAndTrims(): void
    {
        $this->assertSame('JUAN DELA CRUZ', normalize_name_for_matching('  juan dela cruz  '));
    }

    public function testNormalizeNameForMatchingStripsPunctuation(): void
    {
        $this->assertSame('DELA CRUZ JUAN M', normalize_name_for_matching('Dela Cruz, Juan M.'));
    }

    public function testNormalizeNameForMatchingCollapsesRepeatedWhitespace(): void
    {
        $this->assertSame('JUAN DELA CRUZ', normalize_name_for_matching("Juan   Dela\tCruz"));
    }

    public function testNormalizeNameForMatchingTreatsDifferentPunctuationTheSame(): void
    {
        $this->assertSame(
            normalize_name_for_matching('Dela Cruz, Juan'),
            normalize_name_for_matching('Dela Cruz Juan')
        );
    }

    public function testNormalizeNameForMatchingReturnsEmptyStringForBlankInput(): void
    {
        $this->assertSame('', normalize_name_for_matching('   '));
    }

}
