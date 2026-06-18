<?php

namespace Tests\Unit;

use App\Services\LuxOrderBlockBreakerDetector;
use PHPUnit\Framework\TestCase;

class LuxOrderBlockBreakerDetectorTest extends TestCase
{
    /**
     * Синтетический ряд: формируем swing high, пробой вверх → bullish OB на свече с минимальным low.
     */
    public function test_bullish_order_block_appears_after_swing_high_breakout(): void
    {
        $t = 1_700_000_000;
        $candles = $this->buildSwingHighBreakoutSeries($t);

        $zones = (new LuxOrderBlockBreakerDetector(length: 3, showBull: 3, showBear: 0))->activeZones($candles);
        $obs = array_values(array_filter($zones, fn ($z) => $z['type'] === 'order_block_bull'));

        $this->assertNotEmpty($obs, 'Ожидается хотя бы один bullish OB');
        $this->assertEqualsWithDelta(1.02, $obs[0]['price_low'], 1e-9);
        // При равных low Pine берёт самую старую свечу в диапазоне → high 1.035 (бар t+180).
        $this->assertEqualsWithDelta(1.035, $obs[0]['price_high'], 1e-9);
    }

    public function test_bullish_order_block_removed_when_close_breaks_above_ob_top_after_breaker(): void
    {
        $t = 1_700_000_000;
        $candles = $this->buildSwingHighBreakoutSeries($t);
        // breaker: тело ниже btm OB
        $candles[] = ['time' => $t + 420, 'open' => 1.06, 'high' => 1.07, 'low' => 1.01, 'close' => 1.015];
        // удаление: close > top OB (1.05)
        $candles[] = ['time' => $t + 480, 'open' => 1.06, 'high' => 1.08, 'low' => 1.055, 'close' => 1.07];

        $zones = (new LuxOrderBlockBreakerDetector(length: 3, showBull: 3, showBear: 0))->activeZones($candles);
        $obs = array_values(array_filter($zones, fn ($z) => str_starts_with($z['type'], 'order_block_bull')));

        $this->assertCount(0, $obs);
    }

    public function test_bullish_breaker_emits_two_segments(): void
    {
        $t = 1_700_000_000;
        $candles = $this->buildSwingHighBreakoutSeries($t);
        $candles[] = ['time' => $t + 420, 'open' => 1.06, 'high' => 1.07, 'low' => 1.01, 'close' => 1.015];

        $zones = (new LuxOrderBlockBreakerDetector(length: 3, showBull: 3, showBear: 0))->activeZones($candles);
        $ob = array_values(array_filter($zones, fn ($z) => $z['type'] === 'order_block_bull'));
        $brk = array_values(array_filter($zones, fn ($z) => $z['type'] === 'order_block_bull_break'));

        $this->assertCount(1, $ob);
        $this->assertCount(1, $brk);
        $this->assertSame($ob[0]['time_end'], $brk[0]['time_start']);
    }

    /**
     * @return list<array{time: int, open: float, high: float, low: float, close: float}>
     */
    private function buildSwingHighBreakoutSeries(int $t): array
    {
        return [
            ['time' => $t, 'open' => 1.0, 'high' => 1.01, 'low' => 0.99, 'close' => 1.0],
            ['time' => $t + 60, 'open' => 1.0, 'high' => 1.02, 'low' => 0.995, 'close' => 1.01],
            ['time' => $t + 120, 'open' => 1.01, 'high' => 1.04, 'low' => 1.0, 'close' => 1.03],   // swing high кандидат
            ['time' => $t + 180, 'open' => 1.03, 'high' => 1.035, 'low' => 1.02, 'close' => 1.025],
            ['time' => $t + 240, 'open' => 1.025, 'high' => 1.03, 'low' => 1.02, 'close' => 1.022],
            ['time' => $t + 300, 'open' => 1.022, 'high' => 1.028, 'low' => 1.02, 'close' => 1.024],
            // OB-свеча с минимальным low в диапазоне после swing
            ['time' => $t + 360, 'open' => 1.03, 'high' => 1.05, 'low' => 1.02, 'close' => 1.04],
            // пробой swing high (1.04) вверх
            ['time' => $t + 420, 'open' => 1.04, 'high' => 1.06, 'low' => 1.035, 'close' => 1.055],
        ];
    }
}
