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

    public function test_max_body_ratio_rejects_stretched_candles(): void
    {
        $strategy = new StrongTrendTelegramStrategy;
        $candles = [];
        $price = 1.1000;
        // Почти полное тело (high≈close, low≈open) → avg body ~1.0 > 0.70.
        for ($i = 0; $i < 6; $i++) {
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

        $decision = $strategy->analyze($candles, null, [
            'config' => [
                'streak_len' => 6,
                'candle_strictness' => 'strict',
                'max_body_to_range_ratio' => 0.70,
            ],
        ]);

        $this->assertNull($decision);
    }

    public function test_pullback_to_ema_accepts_impulse_then_touch_then_continue(): void
    {
        $strategy = new StrongTrendTelegramStrategy;
        $candles = [];
        $price = 1.1000;
        $t = 1000;

        // Прогрев EMA(9).
        for ($i = 0; $i < 20; $i++) {
            $open = $price;
            $close = $price + 0.00005;
            $candles[] = [
                'time' => $t,
                'open' => $open,
                'high' => $close + 0.00002,
                'low' => $open - 0.00002,
                'close' => $close,
                'volume' => 1.0,
            ];
            $price = $close;
            $t += 60;
        }

        // 6 бычьих: первая касается зоны около цены (EMA рядом), остальные продолжают вверх недалеко.
        for ($i = 0; $i < 6; $i++) {
            $open = $price;
            $close = $price + 0.00015;
            $candles[] = [
                'time' => $t,
                'open' => $open,
                'high' => $close + 0.00005,
                // Глубокий low на первых барах — касание MA внутри серии.
                'low' => $open - ($i < 2 ? 0.00040 : 0.00005),
                'close' => $close,
                'volume' => 1.0,
            ];
            $price = $close;
            $t += 60;
        }

        $decision = $strategy->analyze($candles, null, [
            'config' => [
                'streak_len' => 6,
                'candle_strictness' => 'strict',
                'min_body_to_range_ratio' => 0.0,
                'max_body_to_range_ratio' => 0.0,
                'require_pullback_to_ma' => true,
                'pullback_bars' => 2,
                'pullback_ma_type' => 'ema',
                'pullback_ma_length' => 9,
                'pullback_touch_atr_mult' => 1.5,
                'pullback_max_extension_atr' => 2.5,
                'execution_mode' => 'signal_trade',
            ],
            'payout' => 90.0,
        ]);

        $this->assertNotNull($decision);
        $this->assertSame('CALL', $decision['side']);
        $this->assertTrue($decision['features']['require_pullback_to_ma']);
    }

    public function test_pullback_rejects_pure_six_candle_streak_without_touch(): void
    {
        $strategy = new StrongTrendTelegramStrategy;
        $candles = [];
        $price = 1.1000;
        // Длинный разгон далеко от EMA без касаний — soft-фильтр должен отсечь.
        for ($i = 0; $i < 40; $i++) {
            $open = $price;
            $close = $price + 0.0010;
            $candles[] = [
                'time' => 1000 + ($i * 60),
                'open' => $open,
                'high' => $close + 0.00002,
                'low' => $open + 0.00001,
                'close' => $close,
                'volume' => 1.0,
            ];
            $price = $close;
        }

        $decision = $strategy->analyze($candles, null, [
            'config' => [
                'streak_len' => 6,
                'require_pullback_to_ma' => true,
                'pullback_bars' => 2,
                'pullback_ma_type' => 'ema',
                'pullback_ma_length' => 9,
                'pullback_touch_atr_mult' => 0.10,
                'pullback_max_extension_atr' => 0.8,
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
