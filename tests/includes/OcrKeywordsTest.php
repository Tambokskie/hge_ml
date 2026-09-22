<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;
use Tests\TestDb;

require_once __DIR__ . '/../../includes/ocr_keywords.php';

class OcrKeywordsTest extends TestCase
{
    protected function setUp(): void
    {
        TestDb::resetSchema();
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO users (name, username, email, password, role) VALUES ('Admin', 'admin', 'a@a.com', 'x', 'administrator')");
    }

    public function testAddOcrCourseKeywordUppercasesAndStores(): void
    {
        $pdo = TestDb::pdo();

        $result = add_ocr_course_keyword($pdo, 'bscrim', 1);

        $this->assertSame('success', $result['status']);
        $this->assertSame('BSCRIM', $result['keyword']);
        $stored = $pdo->query("SELECT keyword FROM ocr_course_keywords")->fetchColumn();
        $this->assertSame('BSCRIM', $stored);
    }

    public function testAddOcrCourseKeywordRejectsEmpty(): void
    {
        $result = add_ocr_course_keyword(TestDb::pdo(), '   ', 1);
        $this->assertSame('error', $result['status']);
    }

    public function testAddOcrCourseKeywordRejectsTooLong(): void
    {
        $result = add_ocr_course_keyword(TestDb::pdo(), str_repeat('A', 51), 1);
        $this->assertSame('error', $result['status']);
    }

    public function testAddOcrCourseKeywordRejectsDisallowedCharacters(): void
    {
        // Regex metacharacters must be rejected here rather than silently
        // preg_quote()'d away — this keeps every stored row a plausible
        // course abbreviation, not an attempt to smuggle a regex fragment.
        $result = add_ocr_course_keyword(TestDb::pdo(), 'BS(IT)', 1);
        $this->assertSame('error', $result['status']);
    }

    public function testAddOcrCourseKeywordAllowsPeriodsHyphensAndSpaces(): void
    {
        $result = add_ocr_course_keyword(TestDb::pdo(), 'b.s. crim-inology', 1);
        $this->assertSame('success', $result['status']);
        $this->assertSame('B.S. CRIM-INOLOGY', $result['keyword']);
    }

    public function testAddOcrCourseKeywordRejectsDuplicate(): void
    {
        $pdo = TestDb::pdo();
        add_ocr_course_keyword($pdo, 'BSCRIM', 1);

        $result = add_ocr_course_keyword($pdo, 'bscrim', 1);

        $this->assertSame('error', $result['status']);
        $count = (int) $pdo->query("SELECT COUNT(*) FROM ocr_course_keywords")->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testGetOcrCourseKeywordsReturnsAlphabeticalOrder(): void
    {
        $pdo = TestDb::pdo();
        add_ocr_course_keyword($pdo, 'ZOOTECH', 1);
        add_ocr_course_keyword($pdo, 'BSCRIM', 1);

        $keywords = get_ocr_course_keywords($pdo);

        $this->assertSame('BSCRIM', $keywords[0]['keyword']);
        $this->assertSame('ZOOTECH', $keywords[1]['keyword']);
    }

    public function testRemoveOcrCourseKeywordDeletesRow(): void
    {
        $pdo = TestDb::pdo();
        $added = add_ocr_course_keyword($pdo, 'BSCRIM', 1);

        $result = remove_ocr_course_keyword($pdo, $added['id']);

        $this->assertSame('success', $result['status']);
        $count = (int) $pdo->query("SELECT COUNT(*) FROM ocr_course_keywords")->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function testRemoveOcrCourseKeywordRejectsUnknownId(): void
    {
        $result = remove_ocr_course_keyword(TestDb::pdo(), 999);
        $this->assertSame('error', $result['status']);
    }
}
