<?php

namespace Tests\Unit;

use App\Services\Indicators\AcIndicatorService;
use App\Services\Indicators\AoIndicatorService;
use App\Services\Indicators\BollingerIndicatorService;
use App\Services\Indicators\StdDevIndicatorService;
use Tests\TestCase;

/**
 * Микро-пары (NGN/USD OTC ~0.00071): округление до 5–6 знаков убивало AO/StdDev/BB.
 * Проверяем, что после PriceScale::roundMarket серии остаются «живыми».
 */
class MicroPriceIndicatorsTest extends TestCase
{
    /**
     * @return list<array{time:int,open:float,high:float,low:float,close:float,volume:float}>
     */
    private function ngnLikeRows(int $n = 120): array
    {
        $rows = [];
        $price = 0.00071000;
        $t = 1_700_000_000;
        for ($i = 0; $i < $n; $i++) {
            // Шаг ~1e-7 — реалистично для NGN OTC S5.
            $drift = (($i % 17) - 8) * 1.1e-7;
            $mid = $price + $drift;
            $rows[] = [
                'time' => $t + $i * 5,
                'open' => $mid - 3e-8,
                'high' => $mid + 8e-8,
                'low' => $mid - 8e-8,
                'close' => $mid + 2e-8,
                'volume' => 10.0,
            ];
            $price = $mid;
        }

        return $rows;
    }

    public function test_ao_stays_nonzero_on_micro_prices(): void
    {
        $raw = (new AoIndicatorService)->computeSeries($this->ngnLikeRows());
        $nonzero = array_filter($raw, static fn (float $v): bool => abs($v) > 0.0);
        $this->assertGreaterThan(40, count($nonzero));
        $this->assertLessThan(1e-5, max(array_map('abs', $raw)));
    }

    public function test_ac_stays_nonzero_on_micro_prices(): void
    {
        $raw = (new AcIndicatorService)->computeSeries($this->ngnLikeRows());
        $nonzero = array_filter($raw, static fn (float $v): bool => abs($v) > 0.0);
        $this->assertGreaterThan(20, count($nonzero));
    }

    public function test_stddev_and_bollinger_width_alive_on_micro_prices(): void
    {
        $rows = $this->ngnLikeRows();
        $std = (new StdDevIndicatorService)->computeSeries($rows);
        $nzStd = array_filter($std, static fn (float $v): bool => abs($v) > 0.0);
        $this->assertGreaterThan(40, count($nzStd));

        $bb = (new BollingerIndicatorService)->computeSeries($rows);
        $widths = [];
        foreach ($bb['up'] as $i => $up) {
            $widths[] = (float) $up - (float) $bb['down'][$i];
        }
        $nzWidth = array_filter($widths, static fn (float $v): bool => $v > 0.0);
        $this->assertGreaterThan(40, count($nzWidth));
    }
}
