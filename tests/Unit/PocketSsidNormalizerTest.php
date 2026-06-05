<?php

namespace Tests\Unit;

use App\Support\PocketSsidNormalizer;
use PHPUnit\Framework\TestCase;

class PocketSsidNormalizerTest extends TestCase
{
    public function test_normalizes_json_session_token(): void
    {
        $raw = '{"sessionToken":"abc123token","uid":42,"isDemo":1}';
        $ssid = PocketSsidNormalizer::normalize($raw, true);
        $this->assertNotNull($ssid);
        $this->assertStringStartsWith('42["auth",', $ssid);
        $this->assertStringContainsString('abc123token', $ssid);
        $this->assertStringContainsString('"uid":42', $ssid);
    }

    public function test_passes_through_full_auth_string(): void
    {
        $full = '42["auth",{"session":"x","uid":1,"isDemo":1,"platform":2}]';
        $normalized = PocketSsidNormalizer::normalize($full);
        $this->assertNotNull($normalized);
        $this->assertStringContainsString('"session":"x"', $normalized);
        $this->assertTrue(PocketSsidNormalizer::isValidPocketAuthString($normalized));
    }

    public function test_repairs_auth_with_session_token_key(): void
    {
        $broken = '42["auth",{"sessionToken":"abc123token","uid":"99","lang":"ru","isChart":1}]';
        $fixed = PocketSsidNormalizer::normalize($broken, true);
        $this->assertNotNull($fixed);
        $this->assertStringContainsString('"session":"abc123token"', $fixed);
        $this->assertStringNotContainsString('sessionToken', $fixed);
        $this->assertTrue(PocketSsidNormalizer::isValidPocketAuthString($fixed));
    }

    public function test_bare_session_token_uses_fallback_uid(): void
    {
        $token = 'fac48ae86f4c981fc100bacde226b480';
        $ssid = PocketSsidNormalizer::normalize($token, true, 123638158);
        $this->assertNotNull($ssid);
        $this->assertStringContainsString('"uid":123638158', $ssid);
        $this->assertStringContainsString($token, $ssid);
    }

    public function test_bare_session_without_uid_stays_zero(): void
    {
        $token = 'fac48ae86f4c981fc100bacde226b480';
        $ssid = PocketSsidNormalizer::normalize($token, true);
        $this->assertSame(0, PocketSsidNormalizer::extractUid((string) $ssid));
    }
}
