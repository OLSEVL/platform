<?php

namespace App\Services\Indicators;

/**
 * ROC (Rate of Change) — порт OsEngine Indicators/Scripts/ROC.cs.
 * value = (price - price[n]) / price[n] * 100; round 3.
 */
class RocIndicatorService
{
    public const ID = 'os.roc';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'period' => 13,
        'candle_point' => 'Close',
        'show_line' => true,
        'color' => '#00ffff',
        'line_width' => 1,
        'show_levels' => true,
        'level_zero' => 0.0,
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
        $point = (string) $p['candle_point'];
        $n = count($rows);
        $values = array_fill(0, $n, 0.0);

        for ($index = 0; $index < $n; $index++) {
            if ($index < $period) {
                continue;
            }
            $prev = OsEngineCandleMath::candlePoint($rows[$index - $period], $point);
            if ($prev == 0.0) {
                continue;
            }
            $cur = OsEngineCandleMath::candlePoint($rows[$index], $point);
            $values[$index] = round(($cur - $prev) / $prev * 100.0, 3);
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
        $period = max(1, (int) $p['period']);
        $raw = $this->computeSeries($rows, $p);
        $series = [];
        if ((bool) $p['show_line']) {
            // Прогрев → null, валидный 0 на нулевой линии оставляем.
            $withNull = [];
            foreach ($raw as $i => $v) {
                $withNull[$i] = ($i < $period) ? null : $v;
            }
            $series['roc'] = OsEngineCandleMath::packSeries($rows, $withNull, false);
        }

        $levels = ['show' => false];
        if ((bool) $p['show_levels']) {
            $z = (float) $p['level_zero'];
            $levels = [
                'show' => true,
                'overbought' => $z,
                'oversold' => $z,
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