<?php

namespace Tests\Unit;

use App\Services\Bots\AuthorFlow\AuthorFlowContextBuilder;
use App\Services\Bots\AuthorFlow\AuthorFlowRanker;
use App\Services\Bots\AuthorFlow\BreakoutEScenario;
use App\Services\Bots\AuthorFlow\EngulfDScenario;
use App\Services\Bots\Strategies\AuthorFlowStrategy;
use App\Services\Indicators\EngulfingDetector;
use App\Services\Indicators\SweepRaidLiquidityDetector;
use PHPUnit\Framework\TestCase;

class AuthorFlowStrategyTest extends TestCase
{
    public function test_e_wins_over_d_when_both_enabled_on_same_bar(): void
    {
        // Синтетический пул: Ranker видит E(priority 10) и D(20) на одном баре.
        $ranker = new AuthorFlowRanker;
        $picked = $ranker->pick([
            [
                'setup' => 'engulf_D',
                'side' => 'PUT',
                'bar_index' => 20,
                'priority' => 20,
                'score' => 0.88,
                'features' => ['reason_ru' => 'D fade'],
            ],
            [
                'setup' => 'breakout_E',
                'side' => 'CALL',
                'bar_index' => 20,
                'priority' => 10,
                'score' => 0.70,
                'features' => ['reason_ru' => 'E hold'],
            ],
        ], [
            'last_bar_index' => 20,
            'structure_bias' => 'range',
        ], [
            'af_require_structure' => false,
            'af_require_open_liquidity' => false,
        ]);

        $this->assertSame('breakout_E', $picked['setup'] ?? null);
        $this->assertSame('CALL', $picked['side'] ?? null);
    }

    public function test_engulf_d_scenario_maps_detector_entry(): void
    {
        $candles = [
            $this->candle(1, 10.0, 10.1, 9.9, 10.0),
            $this->candle(2, 10.0, 10.05, 9.85, 9.90),
            $this->candle(3, 9.90, 10.20, 9.80, 10.15), // step1 CALL
            $this->candle(4, 10.15, 10.18, 10.00, 10.02), // reaction
            $this->candle(5, 10.02, 10.25, 9.98, 10.22), // step3 → CALL
        ];

        $scenario = new EngulfDScenario(new EngulfingDetector);
        $out = $scenario->collect($candles, [
            'structure_bias' => 'up',
            'open_liquidity_call' => true,
            'open_liquidity_put' => true,
            'config' => [
                'af_enable_engulf_d' => true,
                'af_eng_allow_classic' => true,
                'af_eng_allow_same_color' => true,
                'af_eng_require_level' => false,
                'af_eng_skip_if_left' => false,
                'af_eng_skip_if_breakout_hold' => false,
                'af_require_structure' => false,
            ],
        ]);

        $this->assertNotEmpty($out);
        $last = $out[array_key_last($out)];
        $this->assertSame('engulf_D', $last['setup']);
        $this->assertSame('CALL', $last['side']);
        $this->assertSame(20, $last['priority']);
        $this->assertSame(4, $last['bar_index']); // 0-based last
    }

    public function test_breakout_e_scenario_emits_continuation(): void
    {
        // FH на индексе 2 (high=11), затем два close выше → CALL breakout.
        $candles = [];
        for ($i = 0; $i < 12; $i++) {
            $base = 10.0 + ($i * 0.01);
            $candles[] = $this->candle(
                1000 + $i * 15,
                $base,
                $i === 2 ? 11.0 : $base + 0.05,
                $base - 0.05,
                $i >= 5 ? 11.2 : $base + 0.02,
            );
        }

        $scenario = new BreakoutEScenario(new SweepRaidLiquidityDetector);
        $out = $scenario->collect($candles, [
            'structure_bias' => 'up',
            'open_liquidity_call' => true,
            'config' => [
                'af_enable_breakout_e' => true,
                'af_srl_pivot_length' => 2,
                'af_breakout_hold_closes' => 2,
                'af_require_structure' => false,
            ],
        ]);

        $calls = array_values(array_filter($out, static fn (array $c): bool => $c['side'] === 'CALL'));
        $this->assertNotEmpty($calls);
        $this->assertSame('breakout_E', $calls[0]['setup']);
        $this->assertSame(10, $calls[0]['priority']);
    }

    public function test_strategy_returns_null_when_gap_filter_trips(): void
    {
        $candles = [];
        for ($i = 0; $i < 40; $i++) {
            // Огромные гэпы open vs prev close.
            $candles[] = $this->candle(1000 + $i, 10.0 + $i, 10.1 + $i, 9.9 + $i, 10.05 + $i);
        }

        $strategy = new AuthorFlowStrategy(
            new AuthorFlowContextBuilder,
            new AuthorFlowRanker,
            [new EngulfDScenario],
        );

        $decision = $strategy->analyze($candles, null, [
            'config' => [
                'af_gap_filter_enabled' => true,
                'af_gap_lookback' => 20,
                'af_gap_max_ratio' => 0.10,
                'af_gap_size_mult' => 0.1,
                'min_confidence' => 0.0,
                'af_require_structure' => false,
                'af_enable_engulf_d' => true,
                'af_eng_require_level' => false,
            ],
        ]);

        $this->assertNull($decision);
    }

    /**
     * @return array{time:int,open:float,high:float,low:float,close:float,volume:float}
     */
    private function candle(int $time, float $o, float $h, float $l, float $c): array
    {
        return [
            'time' => $time,
            'open' => $o,
            'high' => $h,
            'low' => $l,
            'close' => $c,
            'volume' => 1.0,
        ];
    }
}
