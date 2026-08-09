<?php

namespace App\Services\Indicators;

use App\Support\PriceScale;

/**
 * Envelops — порт OsEngine Indicators/Scripts/Envelops.cs.
 * Центр = SMA(Close); up/down = SMA ± SMA * deviation/100.
 * Прогрев: index <= length → нет значений.
 */
class EnvelopsIndicatorService
{
    public const ID = 'os.envelops';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'length' => 21,
        'deviation' => 2.0,
        'show_up' => true,
        'show_mid' => false, // OsEngine: Centre line IsPainted=false
        'show_down' => true,
        'color_up' => '#22c55e',
        'color_mid' => '#22c55e',
        'color_down' => '#22c55e',
        'line_width' => 1,
    ];

    /** @return array<string, mixed> */
    public static function defaultParams(): array
    {
        return self::DEFAULT_PARAMS;
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  array<string,mixed>|null  $params
     * @return array{mid: list<float>, up: list<float>, down: list<float>}
     */
    public function computeSeries(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $length = max(1, (int) $p['length']);
        $deviation = (float) $p['deviation'];
        $n = count($rows);

        $mid = array_fill(0, $n, 0.0);
        $up = array_fill(0, $n, 0.0);
        $down = array_fill(0, $n, 0.0);

        // Вложенный SMA(Close) — как ProcessIndicator("Central SMA").
        $sma = (new SmaIndicatorService)->computeSeries($rows, [
            'length' => $length,
            'candle_point' => 'Close',
        ]);

        for ($index = $length + 1; $index < $n; $index++) {
            $valueSma = $sma[$index];
            if ($valueSma == 0.0) {
                continue;
            }
            $mid[$index] = $valueSma;
            $up[$index] = PriceScale::roundMarket($valueSma + $valueSma * $deviation / 100.0);
            $down[$index] = PriceScale::roundMarket($valueSma - $valueSma * $deviation / 100.0);
        }

        return ['mid' => $mid, 'up' => $up, 'down' => $down];
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  array<string,mixed>|null  $params
     * @return array{
     *   series: array{mid?: list<array{time:int,value:float}>, up?: list<array{time:int,value:float}>, down?: list<array{time:int,value:float}>},
     *   style: array<string,mixed>
     * }
     */
    public function compute(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $raw = $this->computeSeries($rows, $p);
        $series = [];
        if ((bool) $p['show_mid']) {
            $series['mid'] = OsEngineCandleMath::packSeries($rows, $raw['mid']);
        }
        if ((bool) $p['show_up']) {
            $series['up'] = OsEngineCandleMath::packSeries($rows, $raw['up']);
        }
        if ((bool) $p['show_down']) {
            $series['down'] = OsEngineCandleMath::packSeries($rows, $raw['down']);
        }

        return [
            'series' => $series,
            'style' => [
                'color_up' => (string) $p['color_up'],
                'color_mid' => (string) $p['color_mid'],
                'color_down' => (string) $p['color_down'],
                'line_width' => max(1, (int) $p['line_width']),
            ],
        ];
    }
}
