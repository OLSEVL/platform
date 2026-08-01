<?php

namespace Tests\Unit;

use App\Services\Indicators\VolatilityCandlesIndicatorService;
use PHPUnit\Framework\TestCase;

/**
 * Smoke-тест VolatilityCandles: форма серии и ненулевые значения после прогрева Length+1.
 */
class VolatilityCandlesIndicatorTest extends TestCase
{
    /** @return list<array{time:int,open:float,high:float,low:float,close:float,volume:float}> */
    private function syntheticRows(int $count = 120): array
    {
        $rows = [];
        $base = 100.0;
        for ($i = 0; $i < $count; $i++) {
            $close = $base + $i * 0.08 + sin($i / 6) * 0.5;
            $open = $close - 0.05;
            // Чередуем узкие и широкие бары — чтобы амплитуда менялась.
            $span = ($i % 7 === 0) ? 0.8 : 0.25;
            $high = max($open, $close) + $span;
            $low = min($open, $close) - $span * 0.6;
            $rows[] = [
                'time' => 1_700_000_000 + $i * 60,
                'open' => $open,
                'high' => $high,
                'low' => $low,
                'close' => $close,
                'volume' => 0.0,
            ];
        }

        return $rows;
    }

    public function test_compute_series_shape_and_warmup(): void
    {
        $rows = $this->syntheticRows(120);
        $svc = new VolatilityCandlesIndicatorService;
        $raw = $svc->computeSeries($rows, ['length' => 50, 'weight_coeff' => 0.2]);

        $this->assertCount(120, $raw);
        // До Length+1 значения = 0 (OsEngine early return).
        for ($i = 0; $i <= 50; $i++) {
            $this->assertSame(0.0, $raw[$i], "warmup index $i");
        }

        $tail = array_slice($raw, 51);
        $hasNonZero = false;
        foreach ($tail as $v) {
            if (abs($v) > 1e-9) {
                $hasNonZero = true;
                break;
            }
        }
        $this->assertTrue($hasNonZero, 'ожидали ненулевые значения после прогрева');
    }

    public function test_compute_packs_series_vc(): void
    {
        $rows = $this->syntheticRows(100);
        $out = (new VolatilityCandlesIndicatorService)->compute($rows);
        $this->assertArrayHasKey('series', $out);
        $this->assertArrayHasKey('vc', $out['series']);
        $this->assertNotEmpty($out['series']['vc']);
        $this->assertSame('#00ffff', $out['style']['color']);
    }

    public function test_raw_formula_matches_half_range_over_mid(): void
    {
        // Одна широкая свеча: H=110, L=90 → Median=100 → (110-100)/100*100 = 10
        // Эквивалент (H−L)/(H+L)*100 = 20/200*100 = 10
        $candle = ['time' => 1, 'open' => 100.0, 'high' => 110.0, 'low' => 90.0, 'close' => 105.0, 'volume' => 0.0];
        $rows = array_fill(0, 55, $candle);
        for ($i = 0; $i < 55; $i++) {
            $rows[$i]['time'] = 1_700_000_000 + $i * 60;
        }
        // Length=50 → первый валидный index=51; при k=1 сглаживание = сырое значение текущего бара.
        $raw = (new VolatilityCandlesIndicatorService)->computeSeries($rows, [
            'length' => 50,
            'weight_coeff' => 1.0,
        ]);
        $this->assertEqualsWithDelta(10.0, $raw[51], 0.001);
    }
}
