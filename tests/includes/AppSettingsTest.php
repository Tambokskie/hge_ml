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

    public function testResolveGeminiApiKeysReturnsAllFourSlotsInOrder(): void
    {
        $pdo = TestDb::pdo();
        set_app_setting($pdo, 'gemini_api_key', 'Key1', 1);
        set_app_setting($pdo, 'gemini_api_key_2', 'Key2', 1);
        set_app_setting($pdo, 'gemini_api_key_3', 'Key3', 1);
        set_app_setting($pdo, 'gemini_api_key_4', 'Key4', 1);

        $this->assertSame(['Key1', 'Key2', 'Key3', 'Key4'], resolve_gemini_api_keys($pdo));
    }

    public function testResolveGeminiApiKeysSkipsUnsetMiddleSlots(): void
    {
        $pdo = TestDb::pdo();
        set_app_setting($pdo, 'gemini_api_key', 'Key1', 1);
        set_app_setting($pdo, 'gemini_api_key_4', 'Key4', 1);

        $this->assertSame(['Key1', 'Key4'], resolve_gemini_api_keys($pdo));
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
    public function testTheFifthKeyIsAClaudeKeyUnlessAnotherProviderIsChosen(): void
    {
        $pdo = TestDb::pdo();
        set_app_setting($pdo, 'gemini_api_key', 'G1', 1);
        set_app_setting($pdo, 'ai_api_key_5', 'C5', 1);

        $this->assertSame('gemini', resolve_ai_slot_provider($pdo, 1));
        $this->assertSame('claude', resolve_ai_slot_provider($pdo, 5));
        // only the Gemini slot feeds GeminiExtractor
        $this->assertSame(['G1'], resolve_gemini_api_keys($pdo));
        $this->assertSame(
            [['slot' => 1, 'provider' => 'gemini', 'key' => 'G1'], ['slot' => 5, 'provider' => 'claude', 'key' => 'C5']],
            resolve_ai_key_slots($pdo)
        );
    }

    public function testASlotCanBeConnectedToAnotherProvider(): void
    {
        $pdo = TestDb::pdo();
        set_app_setting($pdo, 'gemini_api_key', 'WasGemini', 1);
        set_app_setting($pdo, 'ai_provider_1', 'claude', 1);
        set_app_setting($pdo, 'ai_api_key_5', 'NowGemini', 1);
        set_app_setting($pdo, 'ai_provider_5', 'gemini', 1);

        $this->assertSame(['NowGemini'], resolve_gemini_api_keys($pdo));
        $this->assertSame('claude', resolve_ai_slot_provider($pdo, 1));
    }

    public function testAnUnknownSavedProviderFallsBackToTheSlotsDefault(): void
    {
        $pdo = TestDb::pdo();
        set_app_setting($pdo, 'ai_provider_2', 'openai', 1);
        set_app_setting($pdo, 'ai_provider_5', '', 1);

        $this->assertSame('gemini', resolve_ai_slot_provider($pdo, 2));
        $this->assertSame('claude', resolve_ai_slot_provider($pdo, 5));
    }

    public function testNeighbouringSlotsOfOneProviderShareASegmentAndSlotOrderIsKept(): void
    {
        $slot = static fn (int $n, string $provider, string $key): array => ['slot' => $n, 'provider' => $provider, 'key' => $key];

        $this->assertSame(
            [['provider' => 'gemini', 'keys' => ['G1', 'G2']], ['provider' => 'claude', 'keys' => ['C5']]],
            group_ai_slots_into_segments([$slot(1, 'gemini', 'G1'), $slot(2, 'gemini', 'G2'), $slot(5, 'claude', 'C5')])
        );
        $this->assertSame(
            [['provider' => 'claude', 'keys' => ['C1']], ['provider' => 'gemini', 'keys' => ['G2']], ['provider' => 'claude', 'keys' => ['C3']]],
            group_ai_slots_into_segments([$slot(1, 'claude', 'C1'), $slot(2, 'gemini', 'G2'), $slot(3, 'claude', 'C3')])
        );
        $this->assertSame([], group_ai_slots_into_segments([]));
    }

    public function testASlotStatusNamesTheProviderAndMasksTheKey(): void
    {
        $pdo = TestDb::pdo();
        set_app_setting($pdo, 'ai_api_key_5', 'sk-ant-secret-1234', 1);

        $status = ai_slot_display_status($pdo, 5);

        $this->assertTrue($status['configured']);
        $this->assertSame('claude', $status['provider']);
        $this->assertStringEndsWith('1234', $status['masked']);
        $this->assertStringNotContainsString('secret', $status['masked']);
        $this->assertFalse(ai_slot_display_status($pdo, 3)['configured']);
    }
}
