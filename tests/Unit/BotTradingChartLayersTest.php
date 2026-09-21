<?php

namespace Tests\Unit;

use App\Models\Bot;
use App\Services\CandleService;
use App\Support\BotTradingChartLayers;
use Tests\TestCase;

class BotTradingChartLayersTest extends TestCase
{
    public function test_sweep_raid_enables_srl_layer(): void
    {
        $bot = $this->bot('sweep_raid_liquidity');
        $this->assertSame(['layer-sweep-raid-liquidity'], BotTradingChartLayers::layerIds($bot));
    }

    public function test_sweep_raid_with_demarker_adds_demarker_layer(): void
    {
        $bot = $this->bot('sweep_raid_liquidity', [
            'srl_demarker_enabled' => true,
            'srl_demarker_period' => 6,
            'srl_demarker_oversold' => 10,
            'srl_demarker_overbought' => 90,
        ]);
        $this->assertSame(
            ['layer-sweep-raid-liquidity', 'layer-demarker'],
            BotTradingChartLayers::layerIds($bot)
        );
        $out = BotTradingChartLayers::overlayParams($bot, 'demarker', [
            'period' => 14,
            'level_oversold' => 0.3,
            'level_overbought' => 0.7,
        ]);
        $this->assertSame(6, $out['period']);
        $this->assertEqualsWithDelta(0.1, $out['level_oversold'], 1e-9);
        $this->assertEqualsWithDelta(0.9, $out['level_overbought'], 1e-9);
    }

    public function test_srl_analyst_enables_llm_and_srl_layers(): void
    {
        $bot = $this->bot(\App\Support\ChartAnalystBotTypes::SRL, [
            'analyst_srl_flow_enabled' => true,
            'srl_pivot_length' => 3,
            'srl_enter_sweep' => true,
            'srl_enter_pullback' => false,
        ]);

        $this->assertSame(
            ['layer-llm-levels', 'layer-sweep-raid-liquidity'],
            BotTradingChartLayers::layerIds($bot)
        );
        $params = BotTradingChartLayers::overlayParams($bot, 'srl', [
            'pivot_length' => 2,
            'show_sweeps' => false,
            'show_raids' => true,
            'show_pullback_entries' => true,
        ]);
        $this->assertSame(3, $params['pivot_length']);
        $this->assertTrue($params['show_sweeps']);
        $this->assertFalse($params['show_pullback_entries']);
    }

    public function test_engulf_analyst_enables_llm_and_engulf_layers(): void
    {
        $bot = $this->bot(\App\Support\ChartAnalystBotTypes::ENGULF, [
            'eng_allow_classic' => true,
            'eng_allow_same_color' => false,
        ]);

        $this->assertSame(
            ['layer-llm-levels', 'layer-engulfing-liquidity'],
            BotTradingChartLayers::layerIds($bot)
        );
        $params = BotTradingChartLayers::overlayParams($bot, 'eng', [
            'allow_classic' => false,
            'allow_same_color' => true,
        ]);
        $this->assertTrue($params['allow_classic']);
        $this->assertFalse($params['allow_same_color']);
    }

    public function test_impulse_analyst_enables_llm_levels_only(): void
    {
        $bot = $this->bot(\App\Support\ChartAnalystBotTypes::IMPULSE, [
            'analyst_impulse_lvl_flow_enabled' => true,
        ]);

        $this->assertSame(['layer-llm-levels'], BotTradingChartLayers::layerIds($bot));
    }

    public function test_demarker_enables_demarker_layer(): void
    {
        $bot = $this->bot('strong_trend_demarker');
        $this->assertSame([], BotTradingChartLayers::layerIds($bot));
        $this->assertTrue(BotTradingChartLayers::usesChartIndicatorInstances($bot));
        $this->assertSame(
            [],
            BotTradingChartLayers::layerIds($this->bot(\App\Support\DemarkerDualFastConfig::BOT_TYPE))
        );
    }

    public function test_rsi_contrtrend_uses_instances_not_legacy_layers(): void
    {
        $bot = $this->bot('rsi_contrtrend');
        $this->assertSame([], BotTradingChartLayers::layerIds($bot));
        $this->assertTrue(BotTradingChartLayers::usesChartIndicatorInstances($bot));
    }

    public function test_candle_only_bot_has_no_trading_layers(): void
    {
        $this->assertSame([], BotTradingChartLayers::layerIds($this->bot('three_candles_in_row')));
        $this->assertSame([], BotTradingChartLayers::layerIds($this->bot('three_soldier')));
        $this->assertSame([], BotTradingChartLayers::layerIds($this->bot('custom_candles_impulse')));
    }

    public function test_overlay_srl_uses_bot_pivot_and_entry_flags(): void
    {
        $bot = $this->bot('sweep_raid_liquidity', [
            'srl_pivot_length' => 5,
            'srl_enter_sweep' => true,
            'srl_enter_pullback' => false,
        ]);
        $out = BotTradingChartLayers::overlayParams($bot, 'srl', [
            'pivot_length' => 2,
            'show_fractals' => false,
            'show_sweeps' => false,
            'show_raids' => true,
            'show_pullback_entries' => true,
        ]);
        $this->assertSame(5, $out['pivot_length']);
        $this->assertTrue($out['show_fractals']);
        $this->assertTrue($out['show_sweeps']);
        $this->assertFalse($out['show_raids']);
        $this->assertFalse($out['show_pullback_entries']);
    }

    public function test_overlay_demarker_converts_percent_zones_to_0_1(): void
    {
        $bot = $this->bot('strong_trend_demarker', [
            'demarker_period' => 6,
            'demarker_oversold' => 10,
            'demarker_overbought' => 90,
        ]);
        $out = BotTradingChartLayers::overlayParams($bot, 'demarker', [
            'period' => 14,
            'level_oversold' => 0.3,
            'level_overbought' => 0.7,
        ]);
        $this->assertSame(6, $out['period']);
        $this->assertEqualsWithDelta(0.1, $out['level_oversold'], 1e-9);
        $this->assertEqualsWithDelta(0.9, $out['level_overbought'], 1e-9);
    }

    public function test_overlay_does_not_apply_foreign_bot_config(): void
    {
        $bot = $this->bot('sweep_raid_liquidity', ['rsi_length' => 3]);
        $out = BotTradingChartLayers::overlayParams($bot, 'rsi', ['length' => 14]);
        $this->assertSame(14, $out['length']);
    }

    public function test_overlay_bollinger_uses_bb_keys(): void
    {
        $bot = $this->bot('bollinger_contrtrend', [
            'bb_length' => 18,
            'bb_deviation' => 2.5,
        ]);
        $out = BotTradingChartLayers::overlayParams($bot, 'bollinger', [
            'length' => 20,
            'deviation' => 2.0,
        ]);
        $this->assertSame(18, $out['length']);
        $this->assertEqualsWithDelta(2.5, $out['deviation'], 1e-9);
    }

    public function test_chart_timeframe_follows_demarker_config(): void
    {
        $this->assertSame(1, BotTradingChartLayers::chartTimeframeMinutes($this->bot('sweep_raid_liquidity')));
        $this->assertSame(
            CandleService::TF_S30,
            BotTradingChartLayers::chartTimeframeMinutes($this->bot('strong_trend_demarker'))
        );
        $this->assertSame(
            1,
            BotTradingChartLayers::chartTimeframeMinutes($this->bot('strong_trend_demarker', ['timeframe' => 1]))
        );
        $this->assertSame(
            CandleService::TF_S30,
            BotTradingChartLayers::chartTimeframeMinutes($this->bot(\App\Support\DemarkerDualFastConfig::BOT_TYPE))
        );
    }

    public function test_telegram_pullback_enables_ema_when_required(): void
    {
        $off = $this->bot('strong_trend_telegram', ['require_pullback_to_ma' => false]);
        $this->assertSame([], BotTradingChartLayers::layerIds($off));

        $on = $this->bot('strong_trend_telegram', [
            'require_pullback_to_ma' => true,
            'pullback_ma_type' => 'ema',
            'pullback_ma_length' => 12,
        ]);
        $this->assertSame(['layer-ema'], BotTradingChartLayers::layerIds($on));
        $out = BotTradingChartLayers::overlayParams($on, 'ema', ['length' => 14]);
        $this->assertSame(12, $out['length']);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function bot(string $type, array $config = []): Bot
    {
        $bot = new Bot;
        $bot->type = $type;
        $bot->config = $config;

        return $bot;
    }
}
