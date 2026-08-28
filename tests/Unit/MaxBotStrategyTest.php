<?php

namespace Tests\Unit;

use App\Services\Bots\Strategies\MaxBotStrategy;
use App\Support\MaxBotConfig;
use App\Support\MaxBotSignalEvaluator;
use Tests\TestCase;

class MaxBotStrategyTest extends TestCase
{
    /** @return list<array{time:int,open:float,high:float,low:float,close:float}> */
    private function bullBar(int $time, float $body = 0.0010): array
    {
        return [
            'time' => $time,
            'open' => 1.0,
            'high' => 1.0 + $body + 0.0002,
            'low' => 0.9998,
            'close' => 1.0 + $body,
        ];
    }

    /** @return list<array{time:int,open:float,high:float,low:float,close:float}> */
    private function bearBar(int $time, float $body = 0.0010): array
    {
        return [
            'time' => $time,
            'open' => 1.0 + $body,
            'high' => 1.0 + $body + 0.0002,
            'low' => 0.9998,
            'close' => 1.0,
        ];
    }

    public function test_signal_evaluator_detects_strong_bull_impulse(): void
    {
        $candles = [];
        for ($i = 0; $i < 105; $i++) {
            $candles[] = $this->bullBar(1_700_000_000 + $i * 60, 0.0004);
        }
        // Сильный импульс на хвосте.
        $candles[] = $this->bullBar(1_700_000_000 + 105 * 60, 0.0012);

        $signal = MaxBotSignalEvaluator::findSignal($candles, [
            'id' => '1:110%',
            'streak_length' => 1,
            'streak_mode' => 'at_least',
            'measured_candles' => 1,
            'lookback_bars' => 100,
            'threshold_pct' => 110.0,
            'color' => '',
        ]);

        $this->assertNotNull($signal);
        $this->assertGreaterThanOrEqual(110.0, $signal['ratio_pct']);
        $this->assertSame('green', $signal['color']);
    }

    public function test_strategy_returns_call_on_trend_green(): void
    {
        $candles = [];
        for ($i = 0; $i < 105; $i++) {
            $candles[] = $this->bullBar(1_700_000_000 + $i * 60, 0.0004);
        }
        $candles[] = $this->bullBar(1_700_000_000 + 105 * 60, 0.0012);

        $strategy = new MaxBotStrategy;
        $out = $strategy->analyze($candles, null, [
            'config' => [
                'strategies' => MaxBotConfig::defaultStrategies(),
            ],
            'payout' => 92.0,
        ]);

        $this->assertNotNull($out);
        $this->assertSame('CALL', $out['side']);
        $this->assertSame('1:110%', $out['features']['strategy_id']);
        $this->assertSame(MaxBotConfig::VERSION, $out['decision_version']);
    }

    public function test_strategy_counter_puts_on_green(): void
    {
        $candles = [];
        for ($i = 0; $i < 105; $i++) {
            $candles[] = $this->bullBar(1_700_000_000 + $i * 60, 0.0004);
        }
        $candles[] = $this->bullBar(1_700_000_000 + 105 * 60, 0.0012);

        $rules = MaxBotConfig::defaultStrategies();
        $rules[0]['direction'] = 'counter';

        $strategy = new MaxBotStrategy;
        $out = $strategy->analyze($candles, null, [
            'config' => ['strategies' => $rules],
            'payout' => 90.0,
        ]);

        $this->assertNotNull($out);
        $this->assertSame('PUT', $out['side']);
    }

    public function test_live_mode_uses_partial_candle(): void
    {
        $closed = [];
        for ($i = 0; $i < 105; $i++) {
            $closed[] = $this->bearBar(1_700_000_000 + $i * 60, 0.0004);
        }
        $partial = $this->bearBar(1_700_000_000 + 105 * 60, 0.0015);

        $rules = MaxBotConfig::defaultStrategies();
        $rules[0]['streak_length'] = 1;
        $rules[0]['measured_candles'] = 1;
        $rules[0]['threshold_pct'] = 100.0;

        $strategy = new MaxBotStrategy;
        $out = $strategy->analyze($closed, $partial, [
            'config' => ['strategies' => $rules],
            'payout' => 90.0,
        ]);

        $this->assertNotNull($out);
        $this->assertSame('PUT', $out['side']);
        $this->assertTrue($out['features']['partial_present']);
    }

    public function test_first_matching_strategy_wins(): void
    {
        $candles = [];
        for ($i = 0; $i < 105; $i++) {
            $candles[] = $this->bullBar(1_700_000_000 + $i * 60, 0.0004);
        }
        $candles[] = $this->bullBar(1_700_000_000 + 105 * 60, 0.0012);

        $rules = MaxBotConfig::defaultStrategies();
        $rules[0]['enabled'] = false;

        $strategy = new MaxBotStrategy;
        $out = $strategy->analyze($candles, null, [
            'config' => ['strategies' => $rules],
            'payout' => 90.0,
        ]);

        $this->assertNotNull($out);
        $this->assertSame('2:120%', $out['features']['strategy_id']);
    }
}
