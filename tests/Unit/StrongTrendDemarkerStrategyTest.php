<?php

namespace Tests\Unit;

use App\Services\Bots\Strategies\StrongTrendDemarkerStrategy;
use App\Services\Indicators\DemarkerIndicatorService;
use PHPUnit\Framework\TestCase;

class StrongTrendDemarkerStrategyTest extends TestCase
{
    public function test_it_requires_bullish_streak_and_demarker_exit_from_lower_zone_for_call(): void
    {
        $strategy = new StrongTrendDemarkerStrategy(new DemarkerIndicatorService);
        $candles = [
            $this->candle(0, 10, 10.5, 9.8, 10.2),
            $this->candle(30, 10.2, 10.4, 9.4, 10.3),
            // Бычья свеча, но High не выше предыдущего и Low ниже: DeMarker остаётся внизу.
            $this->candle(60, 10.3, 10.4, 9.0, 10.35),
            // Вторая бычья свеча обновляет High: DeMarker выходит из зоны 10.
            $this->candle(90, 10.35, 10.9, 10.2, 10.8),
        ];

        $decision = $strategy->analyze($candles, null, [
            'config' => [
                'streak_len' => 2,
                'demarker_period' => 2,
                'demarker_oversold' => 10,
                'demarker_overbought' => 90,
            ],
        ]);

        $this->assertNotNull($decision);
        $this->assertSame('CALL', $decision['side']);
    }

    public function test_it_rejects_demarker_exit_without_matching_candle_streak(): void
    {
        $strategy = new StrongTrendDemarkerStrategy(new DemarkerIndicatorService);
        $candles = [
            $this->candle(0, 10, 10.5, 9.8, 10.2),
            $this->candle(30, 10.2, 10.4, 9.4, 10.3),
            $this->candle(60, 10.3, 10.4, 9.0, 10.0),
            // Выход из зоны есть, но последняя свеча медвежья — CALL запрещён.
            $this->candle(90, 10.0, 10.9, 9.9, 9.95),
        ];

        $this->assertNull($strategy->analyze($candles, null, [
            'config' => ['streak_len' => 2, 'demarker_period' => 2],
        ]));
    }

    public function test_zero_streak_allows_demarker_exit_without_matching_candle_color(): void
    {
        $strategy = new StrongTrendDemarkerStrategy(new DemarkerIndicatorService);
        $candles = [
            $this->candle(0, 10, 10.5, 9.8, 10.2),
            $this->candle(30, 10.2, 10.4, 9.4, 10.3),
            $this->candle(60, 10.3, 10.4, 9.0, 10.0),
            // Последняя свеча медвежья: при streak_len=2 CALL нет, при 0 — только DeMarker.
            $this->candle(90, 10.0, 10.9, 9.9, 9.95),
        ];

        $decision = $strategy->analyze($candles, null, [
            'config' => [
                'streak_len' => 0,
                'demarker_period' => 2,
                'demarker_oversold' => 10,
                'demarker_overbought' => 90,
            ],
        ]);

        $this->assertNotNull($decision);
        $this->assertSame('CALL', $decision['side']);
        $this->assertSame(0, $decision['features']['streak_len']);
    }

    public function test_min_body_ratio_rejects_weak_candles(): void
    {
        $strategy = new StrongTrendDemarkerStrategy(new DemarkerIndicatorService);
        // Те же условия CALL, но тела крошечные относительно диапазона.
        $candles = [
            $this->candle(0, 10, 10.5, 9.8, 10.2),
            $this->candle(30, 10.2, 10.4, 9.4, 10.3),
            $this->candle(60, 10.3, 12.0, 8.0, 10.35),
            $this->candle(90, 10.35, 12.5, 8.5, 10.40),
        ];

        $this->assertNull($strategy->analyze($candles, null, [
            'config' => [
                'streak_len' => 2,
                'demarker_period' => 2,
                'demarker_oversold' => 10,
                'demarker_overbought' => 90,
                'candle_strictness' => 'strict',
                'min_body_to_range_ratio' => 0.40,
            ],
        ]));
    }

    public function test_with_doji_mode_allows_flat_candle_in_streak(): void
    {
        $strategy = new StrongTrendDemarkerStrategy(new DemarkerIndicatorService);
        $candles = [
            $this->candle(0, 10, 10.5, 9.8, 10.2),
            $this->candle(30, 10.2, 10.4, 9.4, 10.3),
            // Доджи в серии: close == open — только with_doji считает её бычьей.
            $this->candle(60, 10.3, 10.4, 9.0, 10.3),
            $this->candle(90, 10.3, 10.9, 10.2, 10.8),
        ];

        $this->assertNull($strategy->analyze($candles, null, [
            'config' => [
                'streak_len' => 2,
                'demarker_period' => 2,
                'candle_strictness' => 'strict',
            ],
        ]));

        $decision = $strategy->analyze($candles, null, [
            'config' => [
                'streak_len' => 2,
                'demarker_period' => 2,
                'demarker_oversold' => 10,
                'demarker_overbought' => 90,
                'candle_strictness' => 'with_doji',
            ],
        ]);

        $this->assertNotNull($decision);
        $this->assertSame('CALL', $decision['side']);
    }

    /** @return array{time:int,open:float,high:float,low:float,close:float,volume:float} */
    private function candle(int $time, float $open, float $high, float $low, float $close): array
    {
        return compact('time', 'open', 'high', 'low', 'close') + ['volume' => 1.0];
    }
}
