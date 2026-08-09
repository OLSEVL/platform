<?php

namespace App\Services\Indicators;

use App\Support\PriceScale;

/**
 * CMO (Chande Momentum Oscillator) — порт OsEngine Indicators/Scripts/CMO.cs.
 * 100 * (sumPos - sumNeg) / (sumPos + sumNeg) за period; цикл OsEngine пропускает i==0.
 */
class CmoIndicatorService
{
    public const ID = 'os.cmo';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'period' => 14,
        'show_line' => true,
        'color' => '#ffa500', // Orange
        'line_width' => 1,
        'show_levels' => true,
        'level_overbought' => 50.0,
        'level_oversold' => -50.0,
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
        $cmo1 = array_fill(0, $n, 0.0);
        $cmo2 = array_fill(0, $n, 0.0);

        for ($index = 0; $index < $n; $index++) {
            if ($index == 0) {
                continue;
            }
            $change = (float) $rows[$index]['close'] - (float) $rows[$index - 1]['close'];
            if ($change > 0) {
                $cmo1[$index] = $change;
                $cmo2[$index] = 0.0;
            } elseif ($change < 0) {
                $cmo1[$index] = 0.0;
                $cmo2[$index] = abs($change);
            }

            if ($index < $period) {
                continue;
            }

            $sumPos = 0.0;
            $sumNeg = 0.0;
            // OsEngine: for (i = index-period+1; i > 0 && i <= index; i++) — пропускает 0.
            for ($i = $index - $period + 1; $i > 0 && $i <= $index; $i++) {
                $sumPos += $cmo1[$i];
                $sumNeg += $cmo2[$i];
            }
            if ($sumPos + $sumNeg == 0.0) {
                continue;
            }
            $values[$index] = PriceScale::roundMarket(100.0 * ($sumPos - $sumNeg) / ($sumPos + $sumNeg));
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
            $withNull = [];
            foreach ($raw as $i => $v) {
                $withNull[$i] = ($i < $period) ? null : $v;
            }
            $series['cmo'] = OsEngineCandleMath::packSeries($rows, $withNull, false);
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