<?php

namespace App\Services\Indicators;

/**
 * Ultimate Oscillator — порт OsEngine Indicators/Scripts/UltimateOscilator.cs.
 * 100 * (4*avg7 + 2*avg14 + avg28) / 7, где avg = sum(BP)/sum(TR).
 */
class UltimateIndicatorService
{
    public const ID = 'os.ultimate';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'period1' => 7,
        'period2' => 14,
        'period3' => 28,
        'show_line' => true,
        'color' => '#1e90ff',
        'line_width' => 1,
        'show_levels' => true,
        'level_overbought' => 70.0,
        'level_oversold' => 30.0,
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
        $p1 = max(1, (int) $p['period1']);
        $p2 = max(1, (int) $p['period2']);
        $p3 = max(1, (int) $p['period3']);
        $n = count($rows);
        $values = array_fill(0, $n, 0.0);
        $bp = array_fill(0, $n, 0.0);
        $tr = array_fill(0, $n, 0.0);

        for ($index = 0; $index < $n; $index++) {
            if ($index == 0) {
                continue;
            }
            $close = (float) $rows[$index]['close'];
            $low = (float) $rows[$index]['low'];
            $high = (float) $rows[$index]['high'];
            $prevClose = (float) $rows[$index - 1]['close'];

            $bp[$index] = $close - min($low, $prevClose);
            $hiToLow = abs($high - $low);
            $closeToHigh = abs($prevClose - $high);
            $closeToLow = abs($prevClose - $low);
            $tr[$index] = max($hiToLow, $closeToHigh, $closeToLow);

            if ($index < $p1 || $index < $p2 || $index < $p3) {
                continue;
            }

            $bp1 = $this->sumRange($bp, $index - $p1, $index);
            $tr1 = $this->sumRange($tr, $index - $p1, $index);
            $bp2 = $this->sumRange($bp, $index - $p2, $index);
            $tr2 = $this->sumRange($tr, $index - $p2, $index);
            $bp3 = $this->sumRange($bp, $index - $p3, $index);
            $tr3 = $this->sumRange($tr, $index - $p3, $index);

            if ($tr1 == 0.0 || $tr2 == 0.0 || $tr3 == 0.0) {
                continue;
            }
            $avg7 = $bp1 / $tr1;
            $avg14 = $bp2 / $tr2;
            $avg28 = $bp3 / $tr3;
            $values[$index] = 100.0 * ((4.0 * $avg7) + (2.0 * $avg14) + $avg28) / 7.0;
        }

        return $values;
    }

    /** @param list<float> $arr */
    private function sumRange(array $arr, int $start, int $end): float
    {
        $sum = 0.0;
        $n = count($arr);
        for ($i = $start; $i < $n && $i <= $end; $i++) {
            if ($i >= 0) {
                $sum += $arr[$i];
            }
        }

        return $sum;
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
            $series['uo'] = OsEngineCandleMath::packSeries($rows, $raw);
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
}