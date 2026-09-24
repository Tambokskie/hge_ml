<?php

namespace Tests\Extractors;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../extractors/ExtractorInterface.php';
require_once __DIR__ . '/../../extractors/TemplateExtractor.php';
require_once __DIR__ . '/../../includes/xlsx_writer.php';

class TemplateExtractorTest extends TestCase
{
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->tempFiles = [];
    }

    private function filledTemplateFile(): string
    {
        $rows = [
            1 => [1 => ['value' => 'VISAYAS STATE UNIVERSITY', 'style' => 1]],
            2 => [1 => ['value' => 'MAIN CAMPUS', 'style' => 1]],
            3 => [1 => ['value' => '24/07/2025', 'style' => 1]],
            4 => [1 => ['value' => '2025', 'style' => 1]],
            6 => [
                1 => ['value' => 'SEQUENCE', 'style' => 1], 2 => ['value' => 'LAST NAME', 'style' => 1],
                3 => ['value' => 'GIVEN NAME', 'style' => 1], 4 => ['value' => 'MIDDLE NAME', 'style' => 1],
                5 => ['value' => 'LATIN HONOR', 'style' => 1], 6 => ['value' => 'COURSE', 'style' => 1],
            ],
            7 => [
                1 => ['value' => '1', 'type' => 'n'], 2 => ['value' => 'Dela Cruz'], 3 => ['value' => 'Juan'],
                4 => ['value' => ''], 5 => ['value' => 'CUM LAUDE'], 6 => ['value' => 'BS Computer Science'],
            ],
        ];

        $bytes = xlsx_zip_parts([
            '[Content_Types].xml' => xlsx_content_types_xml(),
            '_rels/.rels' => xlsx_package_rels_xml(),
            'xl/workbook.xml' => xlsx_workbook_xml('TEMPLATE'),
            'xl/_rels/workbook.xml.rels' => xlsx_workbook_rels_xml(),
            'xl/styles.xml' => xlsx_styles_xml(),
            'xl/worksheets/sheet1.xml' => xlsx_build_sheet_xml($rows),
        ]);

        $path = sys_get_temp_dir() . '/hge_template_extractor_test_' . uniqid() . '.xlsx';
        file_put_contents($path, $bytes);
        $this->tempFiles[] = $path;

        return $path;
    }

    public function testExtractsRowsFromAStoredTemplateFile(): void
    {
        $result = (new \TemplateExtractor())->extract($this->filledTemplateFile());

        $this->assertSame('ok', $result['status']);
        $this->assertCount(1, $result['rows']);
        $this->assertSame('DELA CRUZ, JUAN', $result['rows'][0]['graduate_name']);
        $this->assertSame('CUM LAUDE', $result['rows'][0]['latin_honor']);
        $this->assertSame('2025-07-24', $result['rows'][0]['graduation_date']);
    }

    public function testFailedStatusForACorruptedFile(): void
    {
        $path = sys_get_temp_dir() . '/hge_template_extractor_bad_' . uniqid() . '.xlsx';
        file_put_contents($path, 'not a real xlsx file');
        $this->tempFiles[] = $path;

        $result = (new \TemplateExtractor())->extract($path);

        $this->assertSame('failed', $result['status']);
        $this->assertSame([], $result['rows']);
    }
}
