<?php

namespace Tests\Unit;

use App\Support\BotInstrumentMarketScope;
use App\Support\EngulfingLiquidityConfig;
use App\Support\ImpulseLvl1minConfig;
use PHPUnit\Framework\TestCase;

class BotInstrumentMarketScopeTest extends TestCase
{
    public function test_otc_detection_by_suffix(): void
    {
        $this->assertTrue(BotInstrumentMarketScope::isOtc('EURUSD_otc'));
        $this->assertTrue(BotInstrumentMarketScope::isOtc('eurusd_OTC'));
        $this->assertFalse(BotInstrumentMarketScope::isOtc('EURUSD'));
        $this->assertFalse(BotInstrumentMarketScope::isOtc('AUDJPY'));
    }

    public function test_allows_all_otc_non_otc(): void
    {
        $this->assertTrue(BotInstrumentMarketScope::allows('EURUSD', BotInstrumentMarketScope::ALL));
        $this->assertTrue(BotInstrumentMarketScope::allows('EURUSD_otc', BotInstrumentMarketScope::ALL));

        $this->assertTrue(BotInstrumentMarketScope::allows('EURUSD_otc', BotInstrumentMarketScope::OTC));
        $this->assertFalse(BotInstrumentMarketScope::allows('EURUSD', BotInstrumentMarketScope::OTC));

        $this->assertTrue(BotInstrumentMarketScope::allows('EURUSD', BotInstrumentMarketScope::NON_OTC));
        $this->assertFalse(BotInstrumentMarketScope::allows('EURUSD_otc', BotInstrumentMarketScope::NON_OTC));
    }

    public function test_normalize_unknown_falls_back_to_all(): void
    {
        $this->assertSame(BotInstrumentMarketScope::ALL, BotInstrumentMarketScope::normalize('weird'));
        $this->assertSame(BotInstrumentMarketScope::OTC, BotInstrumentMarketScope::normalize('OTC'));
    }

    public function test_defaults_include_market_scope_all(): void
    {
        $this->assertSame(
            BotInstrumentMarketScope::ALL,
            EngulfingLiquidityConfig::defaultConfig()['market_scope']
        );
        $this->assertSame(
            BotInstrumentMarketScope::ALL,
            ImpulseLvl1minConfig::defaultConfig()['market_scope']
        );
    }
}
