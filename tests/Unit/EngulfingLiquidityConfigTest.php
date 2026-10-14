<?php

namespace Tests\Unit;

use App\Support\EngulfingLiquidityConfig;
use PHPUnit\Framework\TestCase;

class EngulfingLiquidityConfigTest extends TestCase
{
    public function test_continuous_scan_defaults_on(): void
    {
        $this->assertTrue(EngulfingLiquidityConfig::continuousScanEnabled([]));
        $this->assertTrue(EngulfingLiquidityConfig::continuousScanEnabled(['continuous_scan' => true]));
        $this->assertFalse(EngulfingLiquidityConfig::continuousScanEnabled(['continuous_scan' => false]));
        $this->assertFalse(EngulfingLiquidityConfig::continuousScanEnabled(['continuous_scan' => '0']));
    }

    public function test_scan_workers_whitelist(): void
    {
        $this->assertSame(6, EngulfingLiquidityConfig::scanWorkers([]));
        $this->assertSame(8, EngulfingLiquidityConfig::scanWorkers(['scan_workers' => 8]));
        $this->assertSame(6, EngulfingLiquidityConfig::scanWorkers(['scan_workers' => 99]));
    }

    public function test_detector_options_soft_defaults_when_keys_missing(): void
    {
        $opts = EngulfingLiquidityConfig::detectorOptions([
            'eng_allow_classic' => true,
            'eng_allow_same_color' => true,
        ]);

        // Старые config без ключей фильтров — не ломаем бэктесты/тесты.
        $this->assertFalse($opts['require_level']);
        $this->assertFalse($opts['skip_if_left']);
        $this->assertFalse($opts['skip_if_breakout_hold']);
        $this->assertSame(1, $opts['reaction_max_bars']);
    }

    public function test_detector_options_honour_stream_filters(): void
    {
        $opts = EngulfingLiquidityConfig::detectorOptions([
            'eng_require_level' => true,
            'eng_reaction_max_bars' => 3,
            'eng_skip_if_left' => true,
            'eng_skip_if_breakout_hold' => true,
            'eng_breakout_hold_closes' => 2,
            'eng_level_lookback' => 40,
            'eng_level_touch_pct' => 0.15,
        ]);

        $this->assertTrue($opts['require_level']);
        $this->assertTrue($opts['skip_if_left']);
        $this->assertTrue($opts['skip_if_breakout_hold']);
        $this->assertSame(3, $opts['reaction_max_bars']);
        $this->assertSame(2, $opts['breakout_hold_closes']);
    }

    public function test_default_config_enables_live_and_stream_filters(): void
    {
        $d = EngulfingLiquidityConfig::defaultConfig();
        $this->assertTrue($d['continuous_scan']);
        $this->assertSame(5, $d['scan_interval_sec']);
        $this->assertSame(6, $d['scan_workers']);
        $this->assertTrue($d['eng_require_level']);
        $this->assertSame(3, $d['eng_reaction_max_bars']);
        $this->assertTrue($d['eng_skip_if_left']);
        $this->assertTrue($d['eng_skip_if_breakout_hold']);
        $this->assertSame(EngulfingLiquidityConfig::VERSION, $d['version']);
    }

    public function test_merge_defaults_upgrades_v2_live_infra(): void
    {
        $bot = new \App\Models\Bot;
        $bot->forceFill([
            'type' => EngulfingLiquidityConfig::BOT_TYPE,
            'config' => [
                'version' => 'engulfing_liquidity_v2',
                'scan_interval_sec' => 60,
                'expiry_sec' => 60,
            ],
        ]);

        $m = EngulfingLiquidityConfig::mergeDefaults($bot);
        $this->assertSame(EngulfingLiquidityConfig::VERSION, $m['version']);
        $this->assertTrue($m['continuous_scan']);
        $this->assertSame(5, $m['scan_interval_sec']);
        $this->assertSame(6, $m['scan_workers']);
        $this->assertTrue($m['eng_require_level']);
        $this->assertSame(60, $m['expiry_sec']);
    }
}
