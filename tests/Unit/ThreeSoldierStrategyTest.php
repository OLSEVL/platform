<?php

namespace Tests\Unit;

use App\Services\Bots\Strategies\ThreeSoldierStrategy;
use PHPUnit\Framework\TestCase;

/**
 * Контракт Three Soldier: 3 бычьих/медвежьих свечи с высотой → CALL/PUT.
 */
class ThreeSoldierStrategyTest extends TestCase
{
    /** @return list<array{time:int,open:float,high:float,low:float,close:float,volume:float}> */
    private function flatBars(int $n, float $price): array
    {
        $candles = [];
        for ($i = 0; $i < $n; $i++) {
            $candles[] = [
                'time' => 1_700_000_000 + ($i * 60),
                'open' => $price,
                'high' => $price + 0.0001,
                'low' => $price - 0.0001,
                'close' => $price,
                'volume' => 1.0,
            ];
        }

        return $candles;
    }

    public function test_warmup_returns_null(): void
    {
        $decision = (new ThreeSoldierStrategy)->analyze($this->flatBars(3, 1.1), null, [
            'payout' => 92.0,
            'config' => [],
        ]);
        $this->assertNull($decision);
    }

    public function test_three_bull_soldiers_emits_call(): void
    {
        // Паттерн: open[0]=1.00 → close[2]=1.20 → height ≈ 20% ≫ 0.08.
        // Каждая свеча тело ≥ 0.02%.
        $candles = [
            ['time' => 1, 'open' => 1.00, 'high' => 1.02, 'low' => 0.99, 'close' => 1.00, 'volume' => 1.0],
            ['time' => 2, 'open' => 1.00, 'high' => 1.02, 'low' => 0.99, 'close' => 1.00, 'volume' => 1.0],
            ['time' => 3, 'open' => 1.00, 'high' => 1.08, 'low' => 0.99, 'close' => 1.07, 'volume' => 1.0],
            ['time' => 4, 'open' => 1.07, 'high' => 1.15, 'low' => 1.06, 'close' => 1.14, 'volume' => 1.0],
            ['time' => 5, 'open' => 1.14, 'high' => 1.22, 'low' => 1.13, 'close' => 1.20, 'volume' => 1.0],
        ];

        $decision = (new ThreeSoldierStrategy)->analyze($candles, null, [
            'payout' => 92.0,
            'config' => [
                'height_soldiers' => 0.08,
                'min_height_one_soldier' => 0.02,
            ],
        ]);

        $this->assertNotNull($decision);
        $this->assertSame('CALL', $decision['side']);
        $this->assertSame('three_soldiers_bull', $decision['features']['setup']);
        $this->assertSame('pattern', $decision['features']['level_kind']);
        $this->assertSame(1.20, $decision['features']['level_price']);
        $this->assertSame('three_soldier_v1', $decision['decision_version']);
    }

    public function test_three_bear_soldiers_emits_put(): void
    {
        $candles = [
            ['time' => 1, 'open' => 1.20, 'high' => 1.21, 'low' => 1.19, 'close' => 1.20, 'volume' => 1.0],
            ['time' => 2, 'open' => 1.20, 'high' => 1.21, 'low' => 1.19, 'close' => 1.20, 'volume' => 1.0],
            ['time' => 3, 'open' => 1.20, 'high' => 1.21, 'low' => 1.12, 'close' => 1.13, 'volume' => 1.0],
            ['time' => 4, 'open' => 1.13, 'high' => 1.14, 'low' => 1.05, 'close' => 1.06, 'volume' => 1.0],
            ['time' => 5, 'open' => 1.06, 'high' => 1.07, 'low' => 0.98, 'close' => 1.00, 'volume' => 1.0],
        ];

        $decision = (new ThreeSoldierStrategy)->analyze($candles, null, [
            'payout' => 90.0,
            'config' => [
                'height_soldiers' => 0.08,
                'min_height_one_soldier' => 0.02,
            ],
        ]);

        $this->assertNotNull($decision);
        $this->assertSame('PUT', $decision['side']);
        $this->assertSame('three_soldiers_bear', $decision['features']['setup']);
    }

    public function test_mixed_candles_no_signal(): void
    {
        // Разнонаправленные свечи — не солдаты.
        $candles = [
            ['time' => 1, 'open' => 1.00, 'high' => 1.02, 'low' => 0.99, 'close' => 1.00, 'volume' => 1.0],
            ['time' => 2, 'open' => 1.00, 'high' => 1.02, 'low' => 0.99, 'close' => 1.00, 'volume' => 1.0],
            ['time' => 3, 'open' => 1.00, 'high' => 1.10, 'low' => 0.99, 'close' => 1.09, 'volume' => 1.0],
            ['time' => 4, 'open' => 1.09, 'high' => 1.10, 'low' => 1.00, 'close' => 1.01, 'volume' => 1.0],
            ['time' => 5, 'open' => 1.01, 'high' => 1.12, 'low' => 1.00, 'close' => 1.11, 'volume' => 1.0],
        ];

        $decision = (new ThreeSoldierStrategy)->analyze($candles, null, [
            'payout' => 92.0,
            'config' => [],
        ]);

        $this->assertNull($decision);
    }
}
