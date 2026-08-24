<?php

namespace Tests\Unit;

use App\Support\ChartIndicatorInstanceRegistry;
use Tests\TestCase;

class ChartIndicatorInstanceRegistryTest extends TestCase
{
    public function test_registry_includes_rsi_and_sma(): void
    {
        $keys = ChartIndicatorInstanceRegistry::supportedHelpKeys();
        $this->assertContains('sma', $keys);
        $this->assertContains('ema', $keys);
        $this->assertContains('rsi', $keys);
        $this->assertContains('macd', $keys);
    }

    public function test_help_key_maps_to_api_key(): void
    {
        $this->assertSame('parabolic_sar', ChartIndicatorInstanceRegistry::apiKey('parabolic-sar'));
        $this->assertSame('stoch_rsi', ChartIndicatorInstanceRegistry::apiKey('stoch-rsi'));
    }
}
