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
}
