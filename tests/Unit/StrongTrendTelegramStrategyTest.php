<?php

namespace Tests\Unit;

use App\Services\Bots\Strategies\StrongTrendTelegramStrategy;
use PHPUnit\Framework\TestCase;

class StrongTrendTelegramStrategyTest extends TestCase
{
    public function test_detects_six_bullish_candles_strict_mode(): void
    {
        $strategy = new StrongTrendTelegramStrategy;
        $candles = [];
        $price = 1.1000;
        for ($i = 0; $i < 6; $i++) {
            $open = $price;
            $close = $price + 0.0005;
            $candles[] = [
                'time' => 1000 + ($i * 60),
                'open' => $open,
                'high' => $close + 0.0001,
                'low' => $open - 0.0001,
                'close' => $close,
                'volume' => 1.0,
            ];
            $price = $close;
        }

        $decision = $strategy->analyze($candles, null, [
            'config' => [
                'streak_len' => 6,
                'candle_strictness' => 'strict',
            ],
            'payout' => 85.0,
        ]);

        $this->assertNotNull($decision);
        $this->assertSame('up', $decision['trend']);
        $this->assertTrue($decision['notify_only']);
        $this->assertSame('CALL', $decision['side']);
    }

    public function test_rejects_mixed_streak(): void
    {
        $strategy = new StrongTrendTelegramStrategy;
        $candles = [];
        $price = 1.1000;
        for ($i = 0; $i < 5; $i++) {
            $open = $price;
            $close = $price + 0.0005;
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
        $candles[] = [
            'time' => 1300,
            'open' => $price,
            'high' => $price,
            'low' => $price - 0.0005,
            'close' => $price - 0.0004,
            'volume' => 1.0,
        ];

        $decision = $strategy->analyze($candles, null, [
            'config' => ['streak_len' => 6, 'candle_strictness' => 'strict'],
        ]);

        $this->assertNull($decision);
    }

    public function test_with_doji_mode_allows_flat_candle(): void
    {
        $strategy = new StrongTrendTelegramStrategy;
        $candles = [];
        $price = 1.1000;
        for ($i = 0; $i < 5; $i++) {
            $open = $price;
            $close = $price + 0.0004;
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
        $candles[] = [
            'time' => 1300,
            'open' => $price,
            'high' => $price + 0.0001,
            'low' => $price - 0.0001,
            'close' => $price,
            'volume' => 1.0,
        ];

        $decision = $strategy->analyze($candles, null, [
            'config' => [
                'streak_len' => 6,
                'candle_strictness' => 'with_doji',
            ],
        ]);

        $this->assertNotNull($decision);
        $this->assertSame('up', $decision['trend']);
    }

    public function test_min_body_ratio_filter_rejects_weak_candles(): void
    {
        $strategy = new StrongTrendTelegramStrategy;
        $candles = [];
        $price = 1.1000;
        for ($i = 0; $i < 6; $i++) {
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

        $decision = $strategy->analyze($candles, null, [
            'config' => [
                'streak_len' => 6,
                'candle_strictness' => 'strict',
                'min_body_to_range_ratio' => 0.40,
            ],
        ]);

        $this->assertNull($decision);
    }

    public function test_signal_trade_mode_sets_notify_only_false(): void
    {
        $strategy = new StrongTrendTelegramStrategy;
        $candles = [];
        $price = 1.1000;
        for ($i = 0; $i < 6; $i++) {
            $open = $price;
            $close = $price + 0.0005;
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

        $decision = $strategy->analyze($candles, null, [
            'config' => [
                'streak_len' => 6,
                'execution_mode' => 'signal_trade',
            ],
        ]);

        $this->assertNotNull($decision);
        $this->assertFalse($decision['notify_only']);
    }
}
