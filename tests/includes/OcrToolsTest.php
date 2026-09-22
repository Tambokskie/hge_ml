<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/ocr_tools.php';

class OcrToolsTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('GHOSTSCRIPT_PATH');
        putenv('TESSERACT_PATH');
    }

    public function testResolveGhostscriptPathHonorsEnvOverride(): void
    {
        putenv('GHOSTSCRIPT_PATH=C:\custom\gswin64c.exe');

        $this->assertSame('C:\custom\gswin64c.exe', resolve_ghostscript_path());
    }

    public function testResolveTesseractPathHonorsEnvOverride(): void
    {
        putenv('TESSERACT_PATH=C:\custom\tesseract.exe');

        $this->assertSame('C:\custom\tesseract.exe', resolve_tesseract_path());
    }

    public function testIsOcrToolWorkingReturnsFalseForMissingExecutable(): void
    {
        $this->assertFalse(is_ocr_tool_working('C:\definitely\not\a\real\path.exe'));
    }

    public function testIsOcrToolWorkingReturnsTrueForARealExecutableThatSupportsVersionFlag(): void
    {
        // PHP's own CLI binary responds to --version with exit code 0, giving
        // us a real, always-present executable to exercise the "working"
        // path without depending on Ghostscript/Tesseract being installed.
        $this->assertTrue(is_ocr_tool_working(PHP_BINARY));
    }

    public function testCheckOcrToolsInstalledReportsBothMissingWhenPathsAreInvalid(): void
    {
        putenv('GHOSTSCRIPT_PATH=C:\definitely\not\real.exe');
        putenv('TESSERACT_PATH=C:\definitely\not\real.exe');

        $status = check_ocr_tools_installed();

        $this->assertFalse($status['ghostscript']);
        $this->assertFalse($status['tesseract']);
    }

    public function testCheckOcrToolsInstalledReportsBothPresentWhenPathsResolveToWorkingExecutables(): void
    {
        putenv('GHOSTSCRIPT_PATH=' . PHP_BINARY);
        putenv('TESSERACT_PATH=' . PHP_BINARY);

        $status = check_ocr_tools_installed();

        $this->assertTrue($status['ghostscript']);
        $this->assertTrue($status['tesseract']);
    }
}
