<?php

namespace App\Services\Indicators;

/**
 * Williams %R — порт OsEngine Indicators/Scripts/WilliamsRange.cs.
 *
 * WR = round(-100 * (highMax - close) / (highMax - lowMin), 2) за окно period.
 * При high-low==0 — предыдущее значение серии (как в OsEngine).
 */
class WilliamsRangeIndicatorService
{
    public const ID = 'os.williams';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'period' => 14,
        'show_line' => true,
        'color' => '#1e90ff', // DodgerBlue
        'line_width' => 1,
        'show_levels' => true,
        'level_overbought' => -20.0,
        'level_oversold' => -80.0,
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
        $values = array_fill(0, $n, 0.0);

        for ($index = 0; $index < $n; $index++) {
            if ($index < $period) {
                continue;
            }

            $highMax = $this->windowHigh($rows, $index, $period);
            $lowMin = $this->windowLow($rows, $index, $period);
            $range = $highMax - $lowMin;

            if ($range == 0.0) {
                // OsEngine: return _series.Values[_series.Values.Count - 1]
                $values[$index] = $index > 0 ? $values[$index - 1] : 0.0;

                continue;
            }

            $close = (float) $rows[$index]['close'];
            $values[$index] = round(-100.0 * ($highMax - $close) / $range, 2);
        }

        return $values;
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  array<string,mixed>|null  $params
     * @return array{
     *   series: array{wr?: list<array{time:int,value:float}>},
     *   style: array<string,mixed>,
     *   levels: array<string,mixed>
     * }
     */
    public function compute(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $raw = $this->computeSeries($rows, $p);
        $series = [];
        if ((bool) $p['show_line']) {
            $series['wr'] = OsEngineCandleMath::packSeries($rows, $raw);
        }

        $levels = ['show' => false];
        if ((bool) $p['show_levels']) {
            $levels = [
                'show' => true,
                'overbought' => (float) $p['level_overbought'],
                'oversold' => (float) $p['level_oversold'],
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

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     */
    private function windowHigh(array $rows, int $index, int $period): float
    {
        $max = 0.0;
        for ($i = $index - $period + 1; $i <= $index; $i++) {
            $h = (float) $rows[$i]['high'];
            if ($max < $h) {
                $max = $h;
            }
        }

        return $max;
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     */
    private function windowLow(array $rows, int $index, int $period): float
    {
        $min = PHP_FLOAT_MAX;
        for ($i = $index - $period + 1; $i <= $index; $i++) {
            $l = (float) $rows[$i]['low'];
            if ($min > $l) {
                $min = $l;
            }
        }

        return $min;
    }
}
