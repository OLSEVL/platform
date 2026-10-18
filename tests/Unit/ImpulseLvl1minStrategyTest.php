<?php

namespace Tests\Unit;

use App\Services\Bots\Strategies\ImpulseLvl1minStrategy;
use App\Support\ImpulseLvl1minConfig;
use App\Support\ImpulseLvl1minLevels;
use App\Support\ImpulseLvl1minLiveWick;
use App\Support\ImpulseLvl1minSignalEvaluator;
use Tests\TestCase;

class ImpulseLvl1minStrategyTest extends TestCase
{
    /** @return array{time:int,open:float,high:float,low:float,close:float} */
    private function bar(int $time, float $open, float $high, float $low, float $close): array
    {
        return ['time' => $time, 'open' => $open, 'high' => $high, 'low' => $low, 'close' => $close];
    }

    /** @return array{time:int,open:float,high:float,low:float,close:float} */
    private function bull(int $i, float $body = 0.0010, float $wick = 0.0002): array
    {
        $open = 1.0;
        $close = $open + $body;

        return $this->bar(1_700_000_000 + $i * 60, $open, $close + $wick, $open - $wick, $close);
    }

    /** @return list<array{time:int,open:float,high:float,low:float,close:float}> */
    private function history(int $n = 120, float $body = 0.0010): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = $this->bull($i, $body);
        }

        return $out;
    }

    public function test_trimmed_mean_drops_ten_low_and_ten_high(): void
    {
        $candles = [];
        for ($i = 0; $i < 80; $i++) {
            $candles[] = $this->bull($i, 1.0);
        }
        for ($i = 80; $i < 90; $i++) {
            $candles[] = $this->bull($i, 0.1);
        }
        for ($i = 90; $i < 100; $i++) {
            $candles[] = $this->bull($i, 10.0);
        }

        $mean = ImpulseLvl1minSignalEvaluator::trimmedMeanBody($candles, 100, 10, 10);
        $this->assertEqualsWithDelta(1.0, $mean, 1e-9);
    }

    public function test_doji_has_zero_direction(): void
    {
        $this->assertSame(0, ImpulseLvl1minSignalEvaluator::candleDirection([
            'open' => 1.0,
            'close' => 1.0,
        ]));
    }

    public function test_profile_without_lvl_returns_call_on_impulse(): void
    {
        $closed = $this->history(120, 0.0010);
        $impulse = $this->bull(120, 0.0040, 0.0002);

        $strategy = new ImpulseLvl1minStrategy;
        $out = $strategy->analyze($closed, $impulse, [
            'config' => array_merge(ImpulseLvl1minConfig::defaultConfig(), [
                'lvl_filter' => false,
                'max_enabled' => false,
            ]),
            'payout' => 80.0,
            'mtf' => [],
        ]);

        $this->assertNotNull($out);
        $this->assertSame('CALL', $out['side']);
        $this->assertContains($out['features']['strategy_id'], ['extra', 'extra_wl']);
        $this->assertSame(ImpulseLvl1minConfig::VERSION, $out['decision_version']);
        $this->assertStringContainsString((string) $out['features']['profile_emoji'], (string) $out['features']['output_label']);
        $this->assertNotSame('', (string) $out['features']['output_label']);
        $this->assertNotSame('', (string) $out['features']['profile_emoji']);
        $this->assertStringContainsString((string) $out['features']['profile_emoji'], $out['reasons_short'][0]);
        $this->assertSame(ImpulseLvl1minConfig::ENTRY_SIDE_CONTINUATION, $out['features']['entry_regime']);
    }

    public function test_entry_side_bounce_returns_put_on_bull(): void
    {
        $closed = $this->history(120, 0.0010);
        $impulse = $this->bull(120, 0.0040, 0.0002);

        $strategy = new ImpulseLvl1minStrategy;
        $out = $strategy->analyze($closed, $impulse, [
            'config' => array_merge(ImpulseLvl1minConfig::defaultConfig(), [
                'lvl_filter' => false,
                'max_enabled' => false,
                'entry_side_mode' => ImpulseLvl1minConfig::ENTRY_SIDE_BOUNCE,
            ]),
            'payout' => 80.0,
            'mtf' => [],
        ]);

        $this->assertNotNull($out);
        $this->assertSame('PUT', $out['side']);
        $this->assertSame(ImpulseLvl1minConfig::ENTRY_SIDE_BOUNCE, $out['features']['entry_regime']);
        $this->assertStringContainsString('отбой', $out['reasons_short'][1] ?? '');
    }

    public function test_side_from_impulse_color_stays_cheap(): void
    {
        $cont = ImpulseLvl1minConfig::defaultConfig();
        $bounce = array_merge($cont, ['entry_side_mode' => ImpulseLvl1minConfig::ENTRY_SIDE_BOUNCE]);
        $t0 = hrtime(true);
        for ($i = 0; $i < 2000; $i++) {
            ImpulseLvl1minConfig::sideFromImpulseColor($i % 2 === 0 ? 'green' : 'red', $bounce);
            ImpulseLvl1minConfig::sideFromImpulseColor($i % 2 === 0 ? 'green' : 'red', $cont);
        }
        $ms = (hrtime(true) - $t0) / 1e6;
        $this->assertLessThan(80.0, $ms, 'маппинг CALL/PUT не должен тормозить scan');
        $this->assertSame('CALL', ImpulseLvl1minConfig::sideFromImpulseColor('green', $cont));
        $this->assertSame('PUT', ImpulseLvl1minConfig::sideFromImpulseColor('green', $bounce));
        $this->assertSame(1, ImpulseLvl1minConfig::impulseDirectionFromColor('green'));
        $this->assertSame(-1, ImpulseLvl1minConfig::impulseDirectionFromColor('red'));
        $this->assertSame(ImpulseLvl1minConfig::ENTRY_SIDE_CONTINUATION, ImpulseLvl1minConfig::entrySideMode([]));
    }

    public function test_lvl_filter_blocks_when_no_swings(): void
    {
        $closed = $this->history(120, 0.0010);
        $impulse = $this->bull(120, 0.0040, 0.0002);

        $strategy = new ImpulseLvl1minStrategy;
        $out = $strategy->analyze($closed, $impulse, [
            'config' => array_merge(ImpulseLvl1minConfig::defaultConfig(), [
                'lvl_filter' => true,
                'max_enabled' => false,
            ]),
            'payout' => 80.0,
            'mtf' => [],
        ]);

        $this->assertNull($out);
    }

    public function test_lvl_touch_on_m1_swing_high_allows_call_on_impulse(): void
    {
        $closed = [];
        for ($i = 0; $i < 40; $i++) {
            $closed[] = $this->bull($i, 0.0010, 0.0001);
        }
        // Свинг high: 3 слева и 3 справа ниже пика.
        for ($i = 40; $i < 43; $i++) {
            $closed[] = $this->bar(1_700_000_000 + $i * 60, 1.00, 1.002, 0.999, 1.001);
        }
        $closed[] = $this->bar(1_700_000_000 + 43 * 60, 1.00, 1.050, 0.999, 1.001);
        for ($i = 44; $i < 47; $i++) {
            $closed[] = $this->bar(1_700_000_000 + $i * 60, 1.00, 1.002, 0.999, 1.001);
        }
        for ($i = 47; $i < 120; $i++) {
            $closed[] = $this->bar(1_700_000_000 + $i * 60, 1.00, 1.003, 0.999, 1.001);
        }

        $impulse = $this->bar(1_700_000_000 + 120 * 60, 1.00, 1.050, 0.999, 1.0045);

        $strategy = new ImpulseLvl1minStrategy;
        $out = $strategy->analyze($closed, $impulse, [
            'config' => array_merge(ImpulseLvl1minConfig::defaultConfig(), [
                'lvl_filter' => true,
                'max_enabled' => false,
            ]),
            'payout' => 80.0,
            'mtf' => [],
        ]);

        $this->assertNotNull($out);
        $this->assertSame('CALL', $out['side']);
        $this->assertContains('M1', $out['features']['lvl_labels']);
        $this->assertNotEmpty($out['features']['lvl_zones']);

        $bounceCfg = array_merge(ImpulseLvl1minConfig::defaultConfig(), [
            'lvl_filter' => true,
            'max_enabled' => false,
            'entry_side_mode' => ImpulseLvl1minConfig::ENTRY_SIDE_BOUNCE,
        ]);
        $bounce = $strategy->analyze($closed, $impulse, [
            'config' => $bounceCfg,
            'payout' => 80.0,
            'mtf' => [],
        ]);
        $this->assertNotNull($bounce);
        $this->assertSame('PUT', $bounce['side']);
        $this->assertNotEmpty($bounce['features']['lvl_zones']);
        $filterPrice = (float) $bounce['features']['lvl_zones'][0]['price'];
        $this->assertGreaterThan(1.04, $filterPrice, 'фильтр — свинг high, не поддержка');
        // Старый PNG брал direction из PUT (−1) и рисовал поддержку (~0.999), не зону фильтра.
        $wrongSideZones = ImpulseLvl1minLevels::matchedZones($closed, $impulse, [], -1, $bounceCfg);
        $wrongPrices = array_map(static fn (array $z): float => (float) $z['price'], $wrongSideZones);
        $this->assertNotContains($filterPrice, $wrongPrices);
        $chartZones = ImpulseLvl1minLevels::zonesForTelegramChart(
            $closed,
            $impulse,
            [],
            $bounce['features'],
            $bounceCfg,
        );
        $this->assertNotEmpty($chartZones);
        $this->assertEqualsWithDelta($filterPrice, (float) $chartZones[0]['price'], 1e-9);
        $this->assertSame(1, ImpulseLvl1minLevels::chartDirectionFromFeatures($bounce['features'], $impulse));
    }

    public function test_max_priority_over_profile_when_both_match(): void
    {
        $closed = $this->history(120, 0.0010);
        $impulse = $this->bull(120, 0.0040);

        $cfg = array_merge(ImpulseLvl1minConfig::defaultConfig(), [
            'lvl_filter' => false,
            'max_enabled' => true,
        ]);

        $signal = ImpulseLvl1minSignalEvaluator::findSignal($closed, $impulse, $cfg, []);
        $this->assertNotNull($signal);
        $this->assertSame('max', $signal['kind']);
        $this->assertStringStartsWith('MAX', (string) $signal['strategy_id']);
        $this->assertGreaterThanOrEqual(110.0, $signal['value']);
        $all = ImpulseLvl1minSignalEvaluator::findAllSignals($closed, $impulse, $cfg, []);
        $this->assertGreaterThanOrEqual(2, count($all));
        $ids = array_column($all, 'strategy_id');
        $this->assertTrue((bool) preg_grep('/^MAX/', $ids));
        $this->assertContains('extra_wl', $ids);
        $packed = (new ImpulseLvl1minStrategy)->analyze($closed, $impulse, [
            'config' => $cfg,
            'payout' => 80.0,
            'mtf' => [],
        ]);
        $this->assertNotNull($packed);
        $this->assertSame('max', $packed['features']['kind']);
        $this->assertNotEmpty($packed['features']['extra_alerts']);
    }

    public function test_prev_suffix_when_live_partial_after_closed_impulse(): void
    {
        $closed = $this->history(118, 0.0010);
        $closed[] = $this->bar(1_700_000_000 + 118 * 60, 1.001, 1.0012, 0.999, 1.000);
        $closed[] = $this->bull(119, 0.0040);
        $partial = $this->bull(120, 0.0010, 0.0002);
        $out = (new ImpulseLvl1minStrategy)->analyze($closed, $partial, [
            'config' => array_merge(ImpulseLvl1minConfig::defaultConfig(), [
                'lvl_filter' => false,
                'max_enabled' => false,
            ]),
            'payout' => 80.0,
            'mtf' => [],
        ]);
        $this->assertNotNull($out);
        $this->assertTrue((bool) $out['features']['is_prev']);
        // «prev» — суффикс caption (как в updateBotM1), не часть output_label.
        $this->assertStringNotContainsString('prev', (string) $out['features']['output_label']);
        $caption = app(\App\Services\Telegram\LevelBounceAlertPresenter::class)
            ->formatImpulseLvlCaption('EURUSD_otc', $out, 80.0, new \DateTimeImmutable('2026-09-25 11:37:05', new \DateTimeZone('Europe/Kyiv')));
        $this->assertStringContainsString('prev', $caption);
        $this->assertStringContainsString('▲', $caption);
        $this->assertStringNotContainsString('CALL', $caption);
        $this->assertStringContainsString('11:37:05', $caption);
    }

    public function test_put_on_bear_impulse_continuation(): void
    {
        $closed = [];
        for ($i = 0; $i < 120; $i++) {
            $closed[] = $this->bar(1_700_000_000 + $i * 60, 1.001, 1.0012, 0.999, 1.000);
        }
        $impulse = $this->bar(1_700_000_000 + 120 * 60, 1.004, 1.0042, 0.999, 1.000);

        $strategy = new ImpulseLvl1minStrategy;
        $out = $strategy->analyze($closed, $impulse, [
            'config' => array_merge(ImpulseLvl1minConfig::defaultConfig(), [
                'lvl_filter' => false,
                'max_enabled' => false,
            ]),
            'payout' => 80.0,
            'mtf' => [],
        ]);

        $this->assertNotNull($out);
        $this->assertSame('PUT', $out['side']);
    }

    public function test_htf_only_uses_provided_closed_mtf(): void
    {
        $m5 = [
            $this->bar(1_700_000_000, 1.0, 1.01, 0.99, 1.001),
            $this->bar(1_700_000_300, 1.0, 1.02, 0.99, 1.001),
            $this->bar(1_700_000_600, 1.0, 1.01, 0.99, 1.001),
        ];
        $swings = ImpulseLvl1minLevels::findSwings($m5, 1, 1, true);
        $this->assertNotEmpty($swings);
        $this->assertSame(1_700_000_300, $swings[0]['time']);
    }

    public function test_overlay_m5_includes_eval_high_and_not_future_m1(): void
    {
        $t0 = 1_700_000_000 - (1_700_000_000 % 300);
        $m1 = [];
        for ($i = 0; $i < 12; $i++) {
            $high = $i === 11 ? 1.05 : 1.001;
            $m1[] = $this->bar($t0 + $i * 60, 1.0, $high, 0.999, 1.0005);
        }

        $mtf = ImpulseLvl1minLevels::overlayFormingHtfFromM1($m1, []);
        $m5 = $mtf['5'] ?? [];
        $this->assertNotEmpty($m5);
        $last = $m5[array_key_last($m5)];
        $this->assertEqualsWithDelta(1.05, (float) $last['high'], 1e-9);

        $future = $this->bar($t0 + 12 * 60, 1.0, 9.99, 0.999, 1.0005);
        $withFuture = ImpulseLvl1minLevels::overlayFormingHtfFromM1(array_merge($m1, [$future]), []);
        $futureLast = $withFuture['5'][array_key_last($withFuture['5'])];
        $this->assertGreaterThan(1.05, (float) $futureLast['high']);
    }

    public function test_forming_m5_confirms_swing_when_closed_mtf_lacks_open_bucket(): void
    {
        $t0 = 1_700_000_000 - (1_700_000_000 % 300);
        $closedM5 = [
            $this->bar($t0, 1.0, 1.010, 0.999, 1.001),
            $this->bar($t0 + 300, 1.0, 1.011, 0.999, 1.001),
            $this->bar($t0 + 600, 1.0, 1.100, 0.999, 1.001),
            $this->bar($t0 + 900, 1.0, 1.012, 0.999, 1.001),
        ];
        $this->assertSame([], ImpulseLvl1minLevels::findSwings($closedM5, 2, 2, true));

        $m1Closed = [];
        for ($i = 0; $i < 20; $i++) {
            $m1Closed[] = $this->bar($t0 + $i * 60, 1.0, 1.003, 0.999, 1.001);
        }
        $eval = $this->bar($t0 + 20 * 60, 1.0, 1.100, 0.999, 1.0045);

        $without = ImpulseLvl1minLevels::match($m1Closed, $eval, ['5' => $closedM5], 1, ImpulseLvl1minConfig::defaultConfig());
        $this->assertTrue($without['ok']);
        $this->assertContains('M5', $without['labels']);

        $noOverlayWouldNeedFive = ImpulseLvl1minLevels::findSwings(
            ImpulseLvl1minLevels::overlayFormingHtfFromM1(array_merge($m1Closed, [$eval]), ['5' => $closedM5])['5'],
            2,
            2,
            true
        );
        $this->assertNotEmpty($noOverlayWouldNeedFive);
    }

    public function test_scan_interval_default_is_five_seconds(): void
    {
        $bot = new \App\Models\Bot;
        $bot->forceFill(['type' => ImpulseLvl1minConfig::BOT_TYPE, 'config' => []]);
        $this->assertSame(5, \App\Support\BotScanCoordinator::scanIntervalSecForBot($bot));
        $this->assertSame(6, ImpulseLvl1minConfig::scanWorkers([]));
        $this->assertSame(
            ['b', 'e'],
            \App\Support\BotScanCoordinator::takeShard(['a', 'b', 'c', 'd', 'e'], 1, 3)
        );
        $this->assertSame(['index' => 2, 'count' => 6], \App\Support\BotScanCoordinator::parseScanShard('2/6'));
        $this->assertNull(\App\Support\BotScanCoordinator::parseScanShard('0/1'));
        $this->assertTrue(ImpulseLvl1minConfig::flag([], 'eval_prev_candle', true));
        $this->assertFalse(ImpulseLvl1minConfig::flag(['eval_prev_candle' => false], 'eval_prev_candle', true));
        $this->assertFalse(ImpulseLvl1minConfig::flag([], 'occupancy_blocks_alerts', false));
        $this->assertSame('bot', ImpulseLvl1minConfig::occupancyScope(['occupancy_scope' => 'bot']));
        $this->assertSame('instrument', ImpulseLvl1minConfig::occupancyScope([]));
    }

    public function test_eval_prev_flag_off_skips_closed_replay(): void
    {
        $closed = $this->history(118, 0.0010);
        $closed[] = $this->bar(1_700_000_000 + 118 * 60, 1.001, 1.0012, 0.999, 1.000);
        $closed[] = $this->bull(119, 0.0040);
        $partial = $this->bull(120, 0.0010, 0.0002);
        $out = (new ImpulseLvl1minStrategy)->analyze($closed, $partial, [
            'config' => array_merge(ImpulseLvl1minConfig::defaultConfig(), [
                'lvl_filter' => false,
                'max_enabled' => false,
                'eval_prev_candle' => false,
            ]),
            'payout' => 80.0,
            'mtf' => [],
        ]);
        $this->assertNull($out);
    }

    public function test_send_all_bar_alerts_off_drops_extra_alerts(): void
    {
        $closed = $this->history(120, 0.0010);
        $impulse = $this->bull(120, 0.0040);
        $cfg = array_merge(ImpulseLvl1minConfig::defaultConfig(), [
            'lvl_filter' => false,
            'send_all_bar_alerts' => false,
        ]);
        $out = (new ImpulseLvl1minStrategy)->analyze($closed, $impulse, [
            'config' => $cfg,
            'payout' => 80.0,
            'mtf' => [],
        ]);
        $this->assertNotNull($out);
        $this->assertSame([], $out['features']['extra_alerts']);
        $this->assertGreaterThanOrEqual(2, count(ImpulseLvl1minSignalEvaluator::findAllSignals($closed, $impulse, $cfg, [])));
    }

    public function test_engine_resolves_strategy(): void
    {
        $engine = app(\App\Services\Bots\BotEngineService::class);
        $this->assertInstanceOf(ImpulseLvl1minStrategy::class, $engine->strategyForType(ImpulseLvl1minConfig::BOT_TYPE));
        $this->assertInstanceOf(ImpulseLvl1minStrategy::class, $engine->strategyForType(ImpulseLvl1minConfig::BOT_TYPE_S15));
    }

    public function test_s15_defaults_and_ladders_leave_m1_untouched(): void
    {
        $t0 = hrtime(true);
        $m1 = ImpulseLvl1minConfig::defaultConfig();
        $s15 = ImpulseLvl1minConfig::defaultConfig(ImpulseLvl1minConfig::BOT_TYPE_S15);
        $ms = (hrtime(true) - $t0) / 1e6;
        $this->assertLessThan(5.0, $ms, 'defaultConfig M1+S15 должен быть дешёвым');

        $this->assertSame(60, $m1['timeframe_sec']);
        $this->assertSame(60, $m1['expiry_sec']);
        $this->assertSame(400, $m1['count']);
        $this->assertSame(ImpulseLvl1minConfig::VERSION, $m1['version']);
        $this->assertSame('body_to_wick', $m1['level_settings']['1']['zone_mode']);
        $this->assertArrayNotHasKey('m1_take', $m1);

        $this->assertSame(15, $s15['timeframe_sec']);
        $this->assertSame(15, $s15['expiry_sec']);
        $this->assertSame(800, $s15['count']);
        $this->assertSame(ImpulseLvl1minConfig::VERSION_S15, $s15['version']);
        $this->assertFalse($s15['live_candle_wick_signals']);
        $this->assertSame(120, $s15['m1_take']);
        $this->assertSame('wick_tip', $s15['level_settings']['1']['zone_mode']);
        $this->assertSame('body_to_wick', $s15['level_settings']['4015']['zone_mode']);

        $this->assertSame(4015, ImpulseLvl1minConfig::dashboardTimeframeId(15));
        $this->assertSame(1, ImpulseLvl1minConfig::dashboardTimeframeId(60));
        $this->assertSame(15, ImpulseLvl1minConfig::barPeriodSec($s15));
        $this->assertSame(60, ImpulseLvl1minConfig::barPeriodSec($m1));
        $this->assertSame([1, 5, 15], array_keys(ImpulseLvl1minConfig::mtfTakeMap($s15)));
        $this->assertSame([5, 15, 30], array_keys(ImpulseLvl1minConfig::mtfTakeMap($m1)));
        $this->assertSame([4015, 1, 5, 15], array_keys(ImpulseLvl1minConfig::lvlTfLadder($s15)));
        $this->assertSame([1, 5, 15, 30], array_keys(ImpulseLvl1minConfig::lvlTfLadder($m1)));
        $this->assertSame('S15', ImpulseLvl1minLevels::tfLabel(15));

        $s15Bot = new \App\Models\Bot;
        $s15Bot->forceFill(['type' => ImpulseLvl1minConfig::BOT_TYPE_S15, 'config' => []]);
        $this->assertSame(5, \App\Support\BotScanCoordinator::scanIntervalSecForBot($s15Bot));

        $runtime = app(\App\Services\Bots\BotEngineService::class)->runtimeSettings($s15Bot);
        $this->assertSame(15, $runtime['config']['timeframe_sec']);
        $this->assertSame(4015, $runtime['series_timeframe']);
        $this->assertSame(15, $runtime['expiry_sec']);

        $closed = $this->history(120, 0.0010);
        $impulse = $this->bull(120, 0.0040);
        $out = (new ImpulseLvl1minStrategy)->analyze($closed, $impulse, [
            'config' => array_merge($s15, ['lvl_filter' => false, 'max_enabled' => false]),
            'payout' => 80.0,
            'mtf' => [],
            'bot_type' => ImpulseLvl1minConfig::BOT_TYPE_S15,
        ]);
        $this->assertNotNull($out);
        $this->assertSame(ImpulseLvl1minConfig::VERSION_S15, $out['decision_version']);
    }

    public function test_s15_settings_partial_shows_m1_take_not_m30(): void
    {
        view()->share('errors', new \Illuminate\Support\ViewErrorBag);
        $bot = new \App\Models\Bot;
        $bot->forceFill(['id' => 99049, 'type' => ImpulseLvl1minConfig::BOT_TYPE_S15]);
        $bot->exists = true;
        $cfg = ImpulseLvl1minConfig::defaultConfig(ImpulseLvl1minConfig::BOT_TYPE_S15);
        $t0 = hrtime(true);
        $html = view('bots._impulse-lvl-1min-settings', ['bot' => $bot, 'cfg' => $cfg])->render();
        $ms = (hrtime(true) - $t0) / 1e6;
        $this->assertLessThan(200.0, $ms, 'partial настроек S15 должен рендериться быстро');
        $this->assertStringContainsString('Impulse LVL S15', $html);
        $this->assertStringContainsString('name="m1_take"', $html);
        $this->assertStringContainsString('Фитиль живой S15', $html);
        $this->assertStringContainsString('Только закрытая S15', $html);
        $this->assertStringContainsString('Живой HTF из S15', $html);
        $this->assertStringNotContainsString('name="m30_take"', $html);

        $m1Bot = new \App\Models\Bot;
        $m1Bot->forceFill(['id' => 47, 'type' => ImpulseLvl1minConfig::BOT_TYPE]);
        $m1Bot->exists = true;
        $m1Html = view('bots._impulse-lvl-1min-settings', [
            'bot' => $m1Bot,
            'cfg' => ImpulseLvl1minConfig::defaultConfig(),
        ])->render();
        $this->assertStringContainsString('Impulse LVL 1min', $m1Html);
        $this->assertStringContainsString('name="m30_take"', $m1Html);
        $this->assertStringContainsString('Фитиль живой M1', $m1Html);
        $this->assertStringNotContainsString('name="m1_take"', $m1Html);
    }

    public function test_fold_s5_into_s15_keeps_open_and_expands_wick(): void
    {
        $t0 = hrtime(true);
        $a = ImpulseLvl1minLiveWick::foldS5IntoBarPeriod([
            'time' => 1005, 'open' => 1.00, 'high' => 1.02, 'low' => 0.99, 'close' => 1.01, 'volume' => 1,
        ], 15, null);
        $b = ImpulseLvl1minLiveWick::foldS5IntoBarPeriod([
            'time' => 1010, 'open' => 1.01, 'high' => 1.04, 'low' => 0.98, 'close' => 1.03, 'volume' => 1,
        ], 15, $a);
        $ms = (hrtime(true) - $t0) / 1e6;
        $this->assertLessThan(2.0, $ms, 'fold S5→S15 должен быть O(1)');
        $this->assertNotNull($a);
        $this->assertSame(1005, $a['time']);
        $this->assertEqualsWithDelta(1.00, $b['open'], 1e-12);
        $this->assertEqualsWithDelta(1.04, $b['high'], 1e-12);
        $this->assertEqualsWithDelta(0.98, $b['low'], 1e-12);
        $this->assertEqualsWithDelta(1.03, $b['close'], 1e-12);
        $this->assertNull(ImpulseLvl1minLiveWick::foldS5IntoBarPeriod($a, 5, null));
        $this->assertNull(ImpulseLvl1minLiveWick::foldS5IntoBarPeriod($a, 60, null));
        $next = ImpulseLvl1minLiveWick::foldS5IntoBarPeriod([
            'time' => 1020, 'open' => 2.0, 'high' => 2.1, 'low' => 1.9, 'close' => 2.05,
        ], 15, $b);
        $this->assertSame(1020, $next['time']);
        $this->assertEqualsWithDelta(2.0, $next['open'], 1e-12);
    }

    public function test_python_new_config_maps_same_thresholds_as_defaults(): void
    {
        $t0 = hrtime(true);
        $loaded = \App\Support\ImpulseLvl1minPythonConfig::load(base_path('docs_need_release/1min/config.py'));
        $ms = (hrtime(true) - $t0) / 1e6;
        $this->assertLessThan(3000.0, $ms, 'парс config.py должен быть разовым, не горячим путём');

        $overlay = $loaded['overlay'];
        $this->assertSame(5, $overlay['scan_interval_sec']);
        $this->assertSame(80.0, $overlay['min_payout']);
        $this->assertTrue($overlay['lvl_filter']);
        $ids = array_column($overlay['profiles'], 'id');
        $this->assertSame(['extra_wl', 'extra', 'extreme', 'structure', 'structure_no_lvl'], $ids);
        $this->assertSame(70, $overlay['trim_low_count']);
        $this->assertTrue($overlay['overlay_forming_htf']);
        $this->assertTrue($overlay['send_all_bar_alerts']);
        $this->assertTrue($overlay['live_candle_wick_signals']);
        $this->assertTrue($overlay['occupancy_blocks_paper']);
        $this->assertFalse($overlay['occupancy_blocks_alerts']);
        $this->assertSame(5, $overlay['trim_high_count']);
        $this->assertArrayHasKey(1, $overlay['profiles'][0]['candle_multipliers']);
        $this->assertEqualsWithDelta(2.5, $overlay['profiles'][0]['candle_multipliers'][1], 1e-9);
        $this->assertFalse($overlay['profiles'][4]['filter_enabled']);
        $this->assertEqualsWithDelta(110.0, ImpulseLvl1minConfig::maxPercentForLength(1, $overlay), 1e-9);
        $this->assertEqualsWithDelta(130.0, ImpulseLvl1minConfig::maxPercentForLength(2, $overlay), 1e-9);
        $this->assertEqualsWithDelta(120.0, ImpulseLvl1minConfig::maxPercentForLength(5, $overlay), 1e-9);
        $m5 = ImpulseLvl1minConfig::levelSettingsForTf(5, $overlay);
        $this->assertSame('wick_tip', $m5['zone_mode']);
        $this->assertEqualsWithDelta(0.15, $m5['wick_tip_fraction'], 1e-9);
        $this->assertEqualsWithDelta(0.05, $m5['distance_body_multiplier'], 1e-9);
        $this->assertGreaterThan(50, count($loaded['pairs']));
        $this->assertSame('⚪', $overlay['profiles'][0]['emoji']);
        $this->assertSame('🟢', $overlay['profiles'][1]['emoji']);
        $this->assertSame('🟡', $overlay['profiles'][2]['emoji']);
        $this->assertSame('🟣', $overlay['profiles'][3]['emoji']);
        $this->assertSame('🟤', $overlay['profiles'][4]['emoji']);
    }

    public function test_imported_max_percent_overrides_hardcoded_default(): void
    {
        $this->assertEqualsWithDelta(150.0, ImpulseLvl1minConfig::maxPercentForLength(1, [
            'max_percent_series' => [1 => 150.0],
        ]), 1e-9);
    }

    public function test_structure_no_lvl_fires_when_swings_missing(): void
    {
        $closed = $this->history(120, 0.0010);
        $impulse = $this->bull(120, 0.0028, 0.0002);
        $cfg = array_merge(ImpulseLvl1minConfig::defaultConfig(), [
            'lvl_filter' => true,
            'max_enabled' => false,
            'profiles' => [
                ['id' => 'extra_wl', 'enabled' => true, 'label' => 'x', 'candle_multipliers' => [1 => 4.0], 'filter_enabled' => true],
                ['id' => 'structure_no_lvl', 'enabled' => true, 'label' => '-', 'candle_multipliers' => [1 => 2.7], 'filter_enabled' => false],
            ],
        ]);
        $out = (new ImpulseLvl1minStrategy)->analyze($closed, $impulse, [
            'config' => $cfg,
            'payout' => 80.0,
            'mtf' => [],
        ]);
        $this->assertNotNull($out);
        $this->assertSame('structure_no_lvl', $out['features']['strategy_id']);
        $this->assertStringContainsString('🟤', (string) $out['features']['output_label']);
        $this->assertSame('structure_no_lvl', (string) $out['features']['strategy_id']);
    }

    public function test_telegram_brief_includes_colored_circle_and_profile_id(): void
    {
        $closed = $this->history(120, 0.0010);
        $impulse = $this->bull(120, 0.0040, 0.0002);
        $decision = (new ImpulseLvl1minStrategy)->analyze($closed, $impulse, [
            'config' => array_merge(ImpulseLvl1minConfig::defaultConfig(), [
                'lvl_filter' => false,
                'max_enabled' => false,
            ]),
            'payout' => 80.0,
            'mtf' => [],
        ]);
        $this->assertNotNull($decision);
        $presenter = app(\App\Services\Telegram\TelegramAlertService::class);
        $format = static fn () => $presenter->formatStrongLevelAlertText(
            'EURUSD_otc',
            $decision,
            80.0,
            60,
            1.004,
            'brief',
            ImpulseLvl1minConfig::BOT_TYPE,
        );
        $format();
        $t0 = hrtime(true);
        $text = $format();
        $ms = (hrtime(true) - $t0) / 1e6;
        $this->assertLessThan(50.0, $ms, 'формат алерта не должен тормозить скан');
        // Одна строка как оригинал: display (payout%) label value [suffixes] — без «Impulse LVL 1min».
        $this->assertStringContainsString((string) $decision['features']['profile_emoji'], $text);
        $this->assertStringContainsString((string) $decision['features']['output_label'], $text);
        $this->assertStringContainsString('(80%)', $text);
        $this->assertStringContainsString('▲', $text);
        $this->assertStringNotContainsString('CALL', $text);
        $this->assertStringNotContainsString('PUT', $text);
        $this->assertMatchesRegularExpression('/\b\d{2}:\d{2}:\d{2}\b/', $text);
        $this->assertStringNotContainsString('Impulse LVL 1min', $text);
        $this->assertStringNotContainsString('Пара:', $text);
    }

    public function test_impulse_telegram_chart_matches_original_size(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('gd required');
        }
        $closed = $this->history(50, 0.0010);
        $impulse = $this->bull(50, 0.0040, 0.0002);
        $series = array_merge($closed, [$impulse]);
        $renderer = app(\App\Services\Telegram\ImpulseLvl1minTelegramChartRenderer::class);
        $zones = [[
            'tf' => 'M5',
            'tf_seconds' => 300,
            'zone_min' => 1.003,
            'zone_max' => 1.005,
        ]];
        $opts = ['max_bars' => 50, 'signal_time' => (int) $impulse['time']];
        // Прогрев GD/шрифтов — холодный старт не входит в бюджет алерта.
        $warm = $renderer->render($series, $zones, $opts);
        if ($warm !== null) {
            @unlink($warm);
        }
        $t0 = hrtime(true);
        $path = $renderer->render($series, $zones, $opts);
        $ms = (hrtime(true) - $t0) / 1e6;
        $this->assertNotNull($path);
        $this->assertFileExists($path);
        $info = getimagesize($path);
        $this->assertIsArray($info);
        $this->assertSame(960, $info[0]);
        $this->assertSame(600, $info[1]);
        // Тёплый рендер ~150–250ms на CI; жёсткий бюджет не держим — сеть Telegram всё равно дольше.
        $this->assertLessThan(1500.0, $ms, 'аномально медленный рендер PNG');
        @unlink($path);
    }

    public function test_live_wick_measure_prefers_upper_side(): void
    {
        [$measure, $dir] = ImpulseLvl1minLiveWick::measureAndDirection([
            'open' => 1.0,
            'high' => 1.008,
            'low' => 0.999,
            'close' => 1.0,
        ]);
        $this->assertEqualsWithDelta(0.008, $measure, 1e-12);
        $this->assertSame(1, $dir);
    }

    public function test_live_wick_merge_does_not_shrink_high(): void
    {
        $t = 1_700_000_000;
        $prev = $this->bar($t, 1.0, 1.005, 0.999, 1.002);
        $late = $this->bar($t, 1.0, 1.002, 0.998, 1.001);
        $merged = ImpulseLvl1minLiveWick::mergeOhlc($prev, $late);
        $this->assertEqualsWithDelta(1.005, $merged['high'], 1e-12);
        $this->assertEqualsWithDelta(0.998, $merged['low'], 1e-12);
        $this->assertEqualsWithDelta(1.0, $merged['open'], 1e-12);
        $this->assertEqualsWithDelta(1.001, $merged['close'], 1e-12);
    }

    public function test_live_wick_doji_body_calls_on_upper_wick(): void
    {
        $closed = $this->history(120, 0.0010);
        $t = 1_700_000_000 + 120 * 60;
        $partial = $this->bar($t, 1.0, 1.008, 0.9998, 1.0);
        $base = array_merge(ImpulseLvl1minConfig::defaultConfig(), [
            'lvl_filter' => false,
            'max_enabled' => false,
            'eval_prev_candle' => false,
        ]);
        $off = (new ImpulseLvl1minStrategy)->analyze($closed, $partial, [
            'config' => $base,
            'payout' => 80.0,
            'mtf' => [],
            'bot_id' => 90047,
            'instrument' => 'WICK_DOJI',
        ]);
        $this->assertNull($off);

        $on = (new ImpulseLvl1minStrategy)->analyze($closed, $partial, [
            'config' => array_merge($base, ['live_candle_wick_signals' => true]),
            'payout' => 80.0,
            'mtf' => [],
            'bot_id' => 90047,
            'instrument' => 'WICK_DOJI',
        ]);
        $this->assertNotNull($on);
        $this->assertSame('CALL', $on['side']);
    }

    public function test_live_wick_second_scan_same_measure_is_silent(): void
    {
        $closed = $this->history(120, 0.0010);
        $t = 1_700_000_000 + 120 * 60;
        $partial = $this->bar($t, 1.0, 1.008, 0.9998, 1.0);
        $ctx = [
            'config' => array_merge(ImpulseLvl1minConfig::defaultConfig(), [
                'lvl_filter' => false,
                'max_enabled' => false,
                'eval_prev_candle' => false,
                'live_candle_wick_signals' => true,
            ]),
            'payout' => 80.0,
            'mtf' => [],
            'bot_id' => 90048,
            'instrument' => 'WICK_DEDUP',
        ];
        $first = (new ImpulseLvl1minStrategy)->analyze($closed, $partial, $ctx);
        $this->assertNotNull($first);
        $second = (new ImpulseLvl1minStrategy)->analyze($closed, $partial, $ctx);
        $this->assertNull($second);
    }

    public function test_live_wick_flag_ignored_without_partial_backtest(): void
    {
        $closed = $this->history(119, 0.0010);
        $t = 1_700_000_000 + 119 * 60;
        $closed[] = $this->bar($t, 1.0, 1.008, 0.9998, 1.0);
        $out = (new ImpulseLvl1minStrategy)->analyze($closed, null, [
            'config' => array_merge(ImpulseLvl1minConfig::defaultConfig(), [
                'lvl_filter' => false,
                'max_enabled' => false,
                'live_candle_wick_signals' => true,
            ]),
            'payout' => 80.0,
            'mtf' => [],
            'bot_id' => 90049,
            'instrument' => 'WICK_BT',
        ]);
        $this->assertNull($out);
    }

    public function test_python_updatebotm1_maps_live_wick_flag(): void
    {
        $path = base_path('docs_need_release/1min/updateBotM1/config.py');
        if (! is_file($path)) {
            $this->markTestSkipped('updateBotM1/config.py нет в дереве');
        }
        $loaded = \App\Support\ImpulseLvl1minPythonConfig::load($path);
        $this->assertTrue($loaded['overlay']['live_candle_wick_signals']);
    }

    public function test_live_wick_analyze_stays_cheap(): void
    {
        $closed = $this->history(120, 0.0010);
        $t = 1_700_000_000 + 120 * 60;
        $partial = $this->bar($t, 1.0, 1.008, 0.9998, 1.0);
        $ctx = [
            'config' => array_merge(ImpulseLvl1minConfig::defaultConfig(), [
                'lvl_filter' => false,
                'max_enabled' => false,
                'eval_prev_candle' => false,
                'live_candle_wick_signals' => true,
            ]),
            'payout' => 80.0,
            'mtf' => [],
            'bot_id' => 90050,
            'instrument' => 'WICK_PERF',
        ];
        $strategy = new ImpulseLvl1minStrategy;
        $strategy->analyze($closed, $partial, $ctx);
        $t0 = hrtime(true);
        for ($i = 0; $i < 20; $i++) {
            $strategy->analyze($closed, $partial, array_merge($ctx, ['instrument' => 'WICK_PERF_'.$i]));
        }
        $ms = (hrtime(true) - $t0) / 1e6;
        $this->assertLessThan(400.0, $ms, 'wick-скан 20 пар не должен раздувать analyze');
    }
}
