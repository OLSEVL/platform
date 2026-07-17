<?php

namespace Tests\Unit;

use App\Services\Bots\Strategies\ThreeCandlesInRowStrategy;
use PHPUnit\Framework\TestCase;

class ThreeCandlesInRowStrategyTest extends TestCase
{
    /** Три сильных бычьих свечи (тело ≈ range). */
    private function strongBullStreak(): array
    {
        $candles = [];
        $price = 1.1000;
        for ($i = 0; $i < 3; $i++) {
            $open = $price;
            $close = $price + 0.0010;
            $candles[] = [
                'time' => 1000 + ($i * 60),
                'open' => $open,
                'high' => $close,
                'low' => $open,
                'close' => $close,
                'volume' => 1.0,
            ];
            $price = $close;
        }

        return $candles;
    }

    /** Три бычьих с огромными тенями (слабое тело). */
    private function weakBullStreak(): array
    {
        $candles = [];
        $price = 1.1000;
        for ($i = 0; $i < 3; $i++) {
            $candles[] = [
                'time' => 1000 + ($i * 60),
                'open' => $price,
                'high' => $price + 0.0020,
                'low' => $price - 0.0020,
                'close' => $price + 0.0001,
                'volume' => 1.0,
            ];
            $price += 0.0001;
        }

        return $candles;
    }

    public function test_three_bull_emits_put(): void
    {
        $decision = (new ThreeCandlesInRowStrategy)->analyze($this->strongBullStreak(), null, [
            'payout' => 92.0,
            'config' => [],
        ]);

        $this->assertNotNull($decision);
        $this->assertSame('PUT', $decision['side']);
        $this->assertSame('bull', $decision['features']['direction']);
    }

    public function test_min_body_filter_rejects_weak_streak(): void
    {
        $decision = (new ThreeCandlesInRowStrategy)->analyze($this->weakBullStreak(), null, [
            'payout' => 92.0,
            'config' => ['min_body_to_range_ratio' => 0.65],
        ]);

        $this->assertNull($decision);
    }

    public function test_min_body_filter_allows_strong_streak(): void
    {
        $decision = (new ThreeCandlesInRowStrategy)->analyze($this->strongBullStreak(), null, [
            'payout' => 92.0,
            'config' => ['min_body_to_range_ratio' => 0.65],
        ]);

        $this->assertNotNull($decision);
        $this->assertSame('PUT', $decision['side']);
    }

    public function test_trade_hours_reject_outside_window(): void
    {
        $decision = (new ThreeCandlesInRowStrategy)->analyze($this->strongBullStreak(), null, [
            'payout' => 92.0,
            'now_utc' => '2026-07-16 03:15:00',
            'config' => [
                'trade_hour_utc_from' => 7,
                'trade_hour_utc_to' => 21,
            ],
        ]);

        $this->assertNull($decision);
    }

    public function test_trade_hours_allow_inside_window(): void
    {
        $decision = (new ThreeCandlesInRowStrategy)->analyze($this->strongBullStreak(), null, [
            'payout' => 92.0,
            'now_utc' => '2026-07-16 14:15:00',
            'config' => [
                'min_body_to_range_ratio' => 0.65,
                'trade_hour_utc_from' => 7,
                'trade_hour_utc_to' => 21,
            ],
        ]);

        $this->assertNotNull($decision);
        $this->assertSame('PUT', $decision['side']);
    }
}
