<?php

namespace App\Services\Indicators;

/**
 * Momentum — порт OsEngine Indicators/Scripts/Momentum.cs.
 * value = (price / price[index-length]) * 100; round 6.
 */
class MomentumIndicatorService
{
    public const ID = 'os.momentum';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'length' => 5,
        'candle_point' => 'Close',
        'show_line' => true,
        'color' => '#00ffff', // Aqua
        'line_width' => 1,
        'show_levels' => true,
        'level_mid' => 100.0,
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
        $length = max(1, (int) $p['length']);
        $point = (string) $p['candle_point'];
        $n = count($rows);
        $values = array_fill(0, $n, 0.0);

        for ($index = 0; $index < $n; $index++) {
            if ($index < $length) {
                continue;
            }
            $divider = OsEngineCandleMath::candlePoint($rows[$index - $length], $point);
            if ($divider == 0.0) {
                continue;
            }
            $cur = OsEngineCandleMath::candlePoint($rows[$index], $point);
            $values[$index] = round(($cur / $divider) * 100.0, 6);
        }

        return $values;
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  array<string,mixed>|null  $params
     * @return array{series: array<string,mixed>, style: array<string,mixed>, levels: array<string,mixed>}
     */
    public function compute(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $raw = $this->computeSeries($rows, $p);
        $series = [];
        if ((bool) $p['show_line']) {
            $series['momentum'] = OsEngineCandleMath::packSeries($rows, $raw);
        }

        $levels = ['show' => false];
        if ((bool) $p['show_levels']) {
            // Уровень 100 — «нейтраль» Momentum; рисуем как overbought=oversold=mid.
            $mid = (float) $p['level_mid'];
            $levels = [
                'show' => true,
                'overbought' => $mid,
                'oversold' => $mid,
                'color_overbought' => 'rgba(161,161,170,0.55)',
                'color_oversold' => 'rgba(161,161,170,0.55)',
            ];
        }

        return [
            'series' => $series,
            'style' => [
                'color' => (string) $p['color'],
                'line_width' => max(1, (int) $p['line_width']),
            ],
            'levels' => $levels,
        ];
    }
}