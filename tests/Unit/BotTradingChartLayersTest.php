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

    public function test_demarker_enables_demarker_layer(): void
    {
        $bot = $this->bot('strong_trend_demarker');
        $this->assertSame(['layer-demarker'], BotTradingChartLayers::layerIds($bot));
    }

    public function test_rsi_contrtrend_enables_rsi_and_sma(): void
    {
        $bot = $this->bot('rsi_contrtrend');
        $this->assertSame(['layer-rsi', 'layer-sma'], BotTradingChartLayers::layerIds($bot));
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
