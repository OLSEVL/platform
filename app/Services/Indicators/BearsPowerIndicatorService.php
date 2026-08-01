<?php

namespace App\Services\Indicators;

/**
 * Bears Power — порт OsEngine CandleChart/Indicators/BearsPower.cs.
 *
 * Bears Power = Low − EMA(Close, period).
 * OsEngine: TypeCalculationAverage = Exponential (метод Elder) — используем EmaIndicatorService.
 */
class BearsPowerIndicatorService
{
    public const ID = 'os.bears_power';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'period' => 13,
        'show_histogram' => true,
        'color_histogram' => '#1e90ff', // DodgerBlue
        'color_histogram_neg' => '#8b0000', // DarkRed
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
        $period = max(1, (int) $p['period']);
        $n = count($rows);

        // EMA Close — OsEngine MovingAverage с Exponential (Elder).
        $ema = (new EmaIndicatorService)->computeSeries($rows, [
            'length' => $period,
            'candle_point' => 'Close',
        ]);

        $values = array_fill(0, $n, 0.0);
        for ($index = 0; $index < $n; $index++) {
            // OsEngine: index < Period || ema==0 → 0.
            if ($index < $period || $ema[$index] == 0.0) {
                continue;
            }
            $values[$index] = (float) $rows[$index]['low'] - (float) $ema[$index];
        }

        return $values;
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  array<string,mixed>|null  $params
     * @return array{series: array<string,mixed>, style: array<string,mixed>}
     */
    public function compute(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $raw = $this->computeSeries($rows, $p);
        $series = [];

        if ((bool) $p['show_histogram']) {
            $series['bears_power'] = OsEngineCandleMath::packSeries($rows, $raw, false);
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
