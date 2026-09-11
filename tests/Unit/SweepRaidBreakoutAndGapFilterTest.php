<?php

namespace Tests\Unit;

use App\Services\Bots\Strategies\SweepRaidLiquidityStrategy;
use App\Services\Indicators\SweepRaidLiquidityDetector;
use App\Support\OtcGapFilter;
use PHPUnit\Framework\TestCase;

class SweepRaidBreakoutAndGapFilterTest extends TestCase
{
    public function test_breakout_entries_call_after_closes_above_fractal_high(): void
    {
        // pivot=1: high на баре 1 (11.0), затем два close выше → CALL (продолжение).
        $candles = [
            $this->candle(1, 10.0, 10.2, 9.8, 10.1),
            $this->candle(2, 10.1, 11.0, 10.0, 10.8), // FH
            $this->candle(3, 10.8, 10.9, 10.5, 10.7), // подтверждает пивот
            $this->candle(4, 10.7, 11.3, 10.6, 11.2), // close > FH
            $this->candle(5, 11.2, 11.5, 11.1, 11.4), // 2-й close > FH → breakout
        ];

        $entries = (new SweepRaidLiquidityDetector)->breakoutEntries($candles, 1, 2);
        $this->assertNotEmpty($entries);
        $last = $entries[array_key_last($entries)];
        $this->assertSame('CALL', $last['side']);
        $this->assertSame('breakout', $last['kind']);
        $this->assertSame(4, $last['bar_index']); // 0-based index of candle 5
    }

    public function test_strategy_enters_on_breakout_when_enabled(): void
    {
        $candles = [
            $this->candle(1, 10.0, 10.2, 9.8, 10.1),
            $this->candle(2, 10.1, 11.0, 10.0, 10.8),
            $this->candle(3, 10.8, 10.9, 10.5, 10.7),
            $this->candle(4, 10.7, 11.3, 10.6, 11.2),
            $this->candle(5, 11.2, 11.5, 11.1, 11.4),
        ];

        $decision = (new SweepRaidLiquidityStrategy)->analyze($candles, null, [
            'config' => [
                'srl_pivot_length' => 1,
                'srl_enter_sweep' => false,
                'srl_enter_pullback' => false,
                'srl_enter_breakout' => true,
                'srl_breakout_closes' => 2,
                'srl_demarker_enabled' => false,
                'expiry_sec' => 60,
            ],
        ]);

        $this->assertIsArray($decision);
        $this->assertSame('CALL', $decision['side']);
        $this->assertSame('breakout', $decision['features']['entry_mode']);
        $this->assertSame('sweep_raid_liquidity_v2', $decision['decision_version']);
    }

    public function test_gap_filter_skips_dirty_pair(): void
    {
        // Каждый бар открывается с огромным гэпом относительно предыдущего close.
        $candles = [];
        $price = 10.0;
        for ($i = 1; $i <= 20; $i++) {
            $open = $price + 5.0; // gap >> range
            $close = $open + 0.1;
            $candles[] = $this->candle($i, $open, $close + 0.05, $open - 0.05, $close);
            $price = $close;
        }

        $cfg = [
            'srl_gap_filter_enabled' => true,
            'srl_gap_lookback' => 15,
            'srl_gap_max_ratio' => 0.35,
            'srl_gap_size_mult' => 0.5,
        ];
        $this->assertTrue(OtcGapFilter::shouldSkip($candles, $cfg, 'srl'));

        $decision = (new SweepRaidLiquidityStrategy)->analyze($candles, null, [
            'config' => $cfg + [
                'srl_pivot_length' => 1,
                'srl_enter_sweep' => true,
                'srl_enter_pullback' => true,
                'srl_enter_breakout' => true,
                'expiry_sec' => 60,
            ],
        ]);
        $this->assertNull($decision);
    }

    public function test_gap_filter_off_does_not_skip(): void
    {
        $candles = [
            $this->candle(1, 10.0, 10.2, 9.8, 10.1),
            $this->candle(2, 10.1, 10.3, 10.0, 10.2),
        ];
        $this->assertFalse(OtcGapFilter::shouldSkip($candles, [
            'srl_gap_filter_enabled' => false,
        ], 'srl'));
    }

    /** @return array{time:int,open:float,high:float,low:float,close:float,volume:float} */
    private function candle(int $time, float $open, float $high, float $low, float $close): array
    {
        return compact('time', 'open', 'high', 'low', 'close') + ['volume' => 1.0];
    }
}
