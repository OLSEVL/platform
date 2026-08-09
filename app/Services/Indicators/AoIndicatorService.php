<?php

namespace App\Services\Indicators;

use App\Support\PriceScale;

/**
 * Awesome Oscillator (AO) — порт OsEngine Indicators/Scripts/AO.cs.
 *
 * AO = EMA(Typical, fast) − EMA(Typical, slow); прогрев: index - slow <= 0 → 0.
 * На графике — гистограмма (зелёная ≥0, красная <0), как MACD histogram.
 */
class AoIndicatorService
{
    public const ID = 'os.ao';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'length_fast' => 5,
        'length_slow' => 32,
        'candle_point' => 'Typical',
        'show_histogram' => true,
        'color_histogram' => '#006400', // DarkGreen
        'color_histogram_neg' => '#f43f5e',
    ];

    /** @return array<string, mixed> */
    public static function defaultParams(): array
    {
        return self::DEFAULT_PARAMS;
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  array<string,mixed>|null  $params
     * @return list<float>
     */
    public function computeSeries(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $fastLen = max(1, (int) $p['length_fast']);
        $slowLen = max(1, (int) $p['length_slow']);
        $point = (string) $p['candle_point'];
        $n = count($rows);

        $emaSvc = new EmaIndicatorService;
        $emaFast = $emaSvc->computeSeries($rows, ['length' => $fastLen, 'candle_point' => $point]);
        $emaSlow = $emaSvc->computeSeries($rows, ['length' => $slowLen, 'candle_point' => $point]);

        $values = array_fill(0, $n, 0.0);
        for ($index = 0; $index < $n; $index++) {
            // OsEngine: index - slow <= 0 || index - fast <= 0 → return 0
            if ($index - $slowLen <= 0 || $index - $fastLen <= 0) {
                continue;
            }

            // 8 знаков: на NGN |AO|~1e-7, round(...,6) обнулял гистограмму.
            $values[$index] = PriceScale::roundMarket((float) $emaFast[$index] - (float) $emaSlow[$index]);
        }

        return $values;
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  array<string,mixed>|null  $params
     * @return array{
     *   series: array{ao?: list<array{time:int,value:float}>},
     *   style: array<string,mixed>
     * }
     */
    public function compute(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $raw = $this->computeSeries($rows, $p);
        $series = [];
        if ((bool) $p['show_histogram']) {
            // skipZeros=false: нулевое пересечение — валидное значение AO.
            $series['ao'] = OsEngineCandleMath::packSeries($rows, $raw, false);
        }

        return [
            'series' => $series,
            'style' => [
                'color_histogram' => (string) $p['color_histogram'],
                'color_histogram_neg' => (string) $p['color_histogram_neg'],
            ],
        ];
    }
}
