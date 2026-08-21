<?php

namespace Tests\Unit;

use App\Services\Bots\Strategies\StrongTrendDemarkerStrategy;
use App\Services\Indicators\DemarkerIndicatorService;
use PHPUnit\Framework\TestCase;

class StrongTrendDemarkerStrategyTest extends TestCase
{
    public function test_it_requires_bullish_streak_before_oversold_then_exit_for_call(): void
    {
        $strategy = new StrongTrendDemarkerStrategy(new DemarkerIndicatorService);
        $decision = $strategy->analyze($this->callPath(), null, [
            'config' => [
                'streak_len' => 2,
                'demarker_period' => 1,
                'demarker_oversold' => 10,
                'demarker_overbought' => 90,
            ],
        ]);

        $this->assertNotNull($decision);
        $this->assertSame('CALL', $decision['side']);
        $this->assertTrue($decision['features']['streak_before_zone']);
        $this->assertSame('strong_trend_demarker_v2', $decision['decision_version']);
        // Серия — бары 4–5 (time 120 и 150), зона начинается на 180.
        $this->assertSame(120, $decision['features']['streak_from_time']);
        $this->assertSame(150, $decision['features']['streak_to_time']);
        $this->assertSame(180, $decision['features']['zone_entry_time']);
    }

    public function test_it_rejects_when_candles_before_zone_are_not_a_bullish_streak(): void
    {
        $strategy = new StrongTrendDemarkerStrategy(new DemarkerIndicatorService);
        $candles = $this->callPath();
        // Бары сразу до входа в зону делаем медвежьими — серия «до зоны» ломается.
        $candles[4] = $this->candle(120, 10.80, 10.81, 10.60, 10.62);
        $candles[5] = $this->candle(150, 10.62, 10.63, 10.40, 10.45);

        $this->assertNull($strategy->analyze($candles, null, [
            'config' => [
                'streak_len' => 2,
                'demarker_period' => 1,
                'demarker_oversold' => 10,
                'demarker_overbought' => 90,
            ],
        ]));
    }

    public function test_zero_streak_allows_demarker_exit_even_if_exit_bar_is_bearish(): void
    {
        $strategy = new StrongTrendDemarkerStrategy(new DemarkerIndicatorService);
        $candles = $this->callPath();
        $last = array_key_last($candles);
        // Выход из зоны за счёт High, close ниже open — при streak=0 цвет последней свечи не важен.
        $candles[$last] = $this->candle(240, 10.50, 11.00, 10.08, 10.40);

        $decision = $strategy->analyze($candles, null, [
            'config' => [
                'streak_len' => 0,
                'demarker_period' => 1,
                'demarker_oversold' => 10,
                'demarker_overbought' => 90,
            ],
        ]);

        $this->assertNotNull($decision);
        $this->assertSame('CALL', $decision['side']);
        $this->assertSame(0, $decision['features']['streak_len']);
        $this->assertFalse($decision['features']['streak_before_zone']);
    }

    public function test_min_body_ratio_rejects_weak_pre_zone_candles(): void
    {
        $strategy = new StrongTrendDemarkerStrategy(new DemarkerIndicatorService);
        $candles = $this->callPath();
        // Те же бычьи бары до зоны, но тело крошечное относительно диапазона.
        $candles[4] = $this->candle(120, 10.70, 12.00, 8.00, 10.72);
        $candles[5] = $this->candle(150, 10.72, 12.20, 8.20, 10.74);

        $this->assertNull($strategy->analyze($candles, null, [
            'config' => [
                'streak_len' => 2,
                'demarker_period' => 1,
                'demarker_oversold' => 10,
                'demarker_overbought' => 90,
                'min_body_to_range_ratio' => 0.40,
            ],
        ]));
    }

    public function test_with_doji_mode_allows_flat_candle_in_pre_zone_streak(): void
    {
        $strategy = new StrongTrendDemarkerStrategy(new DemarkerIndicatorService);
        $candles = $this->callPath();
        $candles[4] = $this->candle(120, 10.70, 10.90, 10.60, 10.70);

        $this->assertNull($strategy->analyze($candles, null, [
            'config' => [
                'streak_len' => 2,
                'demarker_period' => 1,
                'candle_strictness' => 'strict',
                'demarker_oversold' => 10,
                'demarker_overbought' => 90,
            ],
        ]));

        $decision = $strategy->analyze($candles, null, [
            'config' => [
                'streak_len' => 2,
                'demarker_period' => 1,
                'demarker_oversold' => 10,
                'demarker_overbought' => 90,
                'candle_strictness' => 'with_doji',
            ],
        ]);

        $this->assertNotNull($decision);
        $this->assertSame('CALL', $decision['side']);
    }

    public function test_put_requires_bearish_streak_before_overbought(): void
    {
        $strategy = new StrongTrendDemarkerStrategy(new DemarkerIndicatorService);
        $decision = $strategy->analyze($this->putPath(), null, [
            'config' => [
                'streak_len' => 2,
                'demarker_period' => 1,
                'demarker_oversold' => 10,
                'demarker_overbought' => 90,
            ],
        ]);

        $this->assertNotNull($decision);
        $this->assertSame('PUT', $decision['side']);
        $this->assertSame(180, $decision['features']['zone_entry_time']);
    }

    /**
     * Рост (DeM=1) → резкий слив в перепроданность → выход вверх.
     *
     * @return list<array{time:int,open:float,high:float,low:float,close:float,volume:float}>
     */
    private function callPath(): array
    {
        $candles = [];
        $t = 0;
        $p = 10.0;
        for ($i = 0; $i < 6; $i++) {
            $open = $p;
            $close = $p + 0.15;
            $candles[] = $this->candle($t, $open, $close + 0.02, $open - 0.01, $close);
            $p = $close;
            $t += 30;
        }
        $candles[] = $this->candle($t, $p, $p + 0.01, $p - 0.60, $p - 0.50);
        $t += 30;
        $p -= 0.50;
        $candles[] = $this->candle($t, $p, $p + 0.01, $p - 0.15, $p - 0.10);
        $t += 30;
        $p -= 0.10;
        $candles[] = $this->candle($t, $p, $p + 0.50, $p - 0.02, $p + 0.20);

        return $candles;
    }

    /**
     * Падение (DeM=0) → вынос в перекупленность → выход вниз.
     *
     * @return list<array{time:int,open:float,high:float,low:float,close:float,volume:float}>
     */
    private function putPath(): array
    {
        $candles = [];
        $t = 0;
        $p = 12.0;
        for ($i = 0; $i < 6; $i++) {
            $open = $p;
            $close = $p - 0.15;
            $candles[] = $this->candle($t, $open, $open + 0.01, $close - 0.02, $close);
            $p = $close;
            $t += 30;
        }
        $candles[] = $this->candle($t, $p, $p + 0.60, $p - 0.01, $p + 0.50);
        $t += 30;
        $p += 0.50;
        $candles[] = $this->candle($t, $p, $p + 0.15, $p - 0.01, $p + 0.10);
        $t += 30;
        $p += 0.10;
        $candles[] = $this->candle($t, $p, $p + 0.02, $p - 0.50, $p - 0.20);

        return $candles;
    }

    /** @return array{time:int,open:float,high:float,low:float,close:float,volume:float} */
    private function candle(int $time, float $open, float $high, float $low, float $close): array
    {
        return compact('time', 'open', 'high', 'low', 'close') + ['volume' => 1.0];
    }
}
