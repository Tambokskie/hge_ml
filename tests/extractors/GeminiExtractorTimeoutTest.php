<?php

namespace Tests\Extractors;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../extractors/GeminiExtractor.php';

class GeminiExtractorTimeoutTest extends TestCase
{
    private string $file = '';

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'hge_gemini_timeout_');
        file_put_contents($this->file, '%PDF-1.4 minimal');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testWithoutABudgetEveryRequestGetsTheFullLimit(): void
    {
        $this->assertSame(180, \GeminiExtractor::requestTimeoutFor(null, 1000.0));
    }

    public function testTheLimitIsCutToWhatIsLeftOfTheBudget(): void
    {
        $this->assertSame(180, \GeminiExtractor::requestTimeoutFor(1500.0, 1000.0), 'plenty left: capped at the normal limit');
        $this->assertSame(100, \GeminiExtractor::requestTimeoutFor(1100.0, 1000.0));
        $this->assertSame(30, \GeminiExtractor::requestTimeoutFor(1030.0, 1000.0), 'exactly the minimum is still worth starting');
    }

    public function testNoRequestIsStartedWithTooLittleTimeLeft(): void
    {
        $this->assertNull(\GeminiExtractor::requestTimeoutFor(1029.0, 1000.0));
        $this->assertNull(\GeminiExtractor::requestTimeoutFor(1000.0, 1000.0));
        $this->assertNull(\GeminiExtractor::requestTimeoutFor(900.0, 1000.0), 'a deadline already in the past');
    }

    public function testAnExpiredBudgetFailsCleanlyAsTimedOutWithoutAnyNetworkCall(): void
    {
        // Pointed at a port nothing listens on: if a request were made it would
        // come back as "unreachable", so timed_out + not unreachable proves none was.
        $extractor = new \GeminiExtractor(['test-key'], null, 'http://127.0.0.1:1/');
        $extractor->setDeadline(microtime(true) - 1);

        $started = microtime(true);
        $result = $extractor->extract($this->file);

        $this->assertLessThan(2.0, microtime(true) - $started);
        $this->assertSame(['status' => 'failed', 'rows' => [], 'unreachable' => false, 'timed_out' => true], $result);
    }

    public function testAnUnreachableServerIsStillReportedAsUnreachableNotAsATimeout(): void
    {
        $extractor = new \GeminiExtractor(['test-key'], null, 'http://127.0.0.1:1/');
        $extractor->setDeadline(microtime(true) + 120);

        $result = $extractor->extract($this->file);

        $this->assertSame('failed', $result['status']);
        $this->assertTrue($result['unreachable']);
        $this->assertFalse($result['timed_out']);
    }

    public function testNoKeysAndAnUnreadableFileStillReturnTheFullResultShape(): void
    {
        $this->assertSame(['status' => 'failed', 'rows' => [], 'unreachable' => false, 'timed_out' => false], (new \GeminiExtractor([]))->extract($this->file));
        $this->assertSame(['status' => 'failed', 'rows' => [], 'unreachable' => false, 'timed_out' => false], (new \GeminiExtractor(['k']))->extract($this->file . '.missing'));
    }
}
