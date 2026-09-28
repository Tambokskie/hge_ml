<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;
use Tests\TestDb;

require_once __DIR__ . '/../../includes/app_settings.php';

class AppSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        TestDb::resetSchema();
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO users (name, username, email, password, role) VALUES ('Admin', 'admin', 'a@a.com', 'x', 'administrator')");
    }

    public function testGetAppSettingReturnsNullWhenUnset(): void
    {
        $this->assertNull(get_app_setting(TestDb::pdo(), 'gemini_api_key'));
    }

    public function testSetAppSettingThenGetReturnsSavedValue(): void
    {
        $pdo = TestDb::pdo();
        set_app_setting($pdo, 'gemini_api_key', 'AIzaTestKey123', 1);

        $this->assertSame('AIzaTestKey123', get_app_setting($pdo, 'gemini_api_key'));
    }

    public function testSetAppSettingOverwritesExistingValue(): void
    {
        $pdo = TestDb::pdo();
        set_app_setting($pdo, 'gemini_api_key', 'FirstValue', 1);
        set_app_setting($pdo, 'gemini_api_key', 'SecondValue', 1);

        $this->assertSame('SecondValue', get_app_setting($pdo, 'gemini_api_key'));
    }

    public function testResolveGeminiApiKeysPrefersDbValueOverEnvConstant(): void
    {
        $pdo = TestDb::pdo();
        set_app_setting($pdo, 'gemini_api_key', 'DbStoredKey', 1);

        $this->assertSame(['DbStoredKey'], resolve_gemini_api_keys($pdo));
    }

    public function testResolveGeminiApiKeysDropsAnExplicitlyEmptyStoredSlot(): void
    {
        $pdo = TestDb::pdo();
        set_app_setting($pdo, 'gemini_api_key', 'OnlyKey', 1);
        set_app_setting($pdo, 'gemini_api_key_2', '', 1);

        $this->assertSame(['OnlyKey'], resolve_gemini_api_keys($pdo));
    }

    public function testResolveGeminiApiKeysReturnsEmptyArrayWhenNothingConfigured(): void
    {
        $this->assertSame([], resolve_gemini_api_keys(TestDb::pdo()));
    }

    public function testGeminiKeyDisplayStatusReportsUnconfiguredWhenNothingIsSet(): void
    {
        $status = gemini_key_display_status(TestDb::pdo(), 'gemini_api_key', '');

        $this->assertFalse($status['configured']);
        $this->assertNull($status['masked']);
    }

    public function testGeminiKeyDisplayStatusMasksAllButLastFourCharacters(): void
    {
        $pdo = TestDb::pdo();
        set_app_setting($pdo, 'gemini_api_key', 'AIzaSyABCDEFG1234', 1);

        $status = gemini_key_display_status($pdo, 'gemini_api_key', '');

        $this->assertTrue($status['configured']);
        $this->assertSame('••••1234', $status['masked']);
    }

    public function testGeminiKeyDisplayStatusFallsBackToEnvValueWhenDbUnset(): void
    {
        $status = gemini_key_display_status(TestDb::pdo(), 'gemini_api_key', 'EnvFallbackKey');

        $this->assertTrue($status['configured']);
        $this->assertSame('••••kKey', $status['masked']);
    }
}
