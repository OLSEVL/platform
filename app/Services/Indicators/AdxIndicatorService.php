<?php

namespace App\Services\Indicators;

use App\Support\PriceScale;

/**
 * ADX / +DI / −DI — порт OsEngine Indicators/Scripts/ADX.cs (CalculateADX).
 * Отдельная pane: сила тренда (ADX) и направление (+DI/−DI).
 */
class AdxIndicatorService
{
    public const ID = 'os.adx';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'length' => 14,
        'show_adx' => true,
        'show_plus' => true,
        'show_minus' => true,
        'color_adx' => '#228b22', // ForestGreen
        'color_plus' => '#0000ff', // Blue
        'color_minus' => '#ff0000', // Red
        'line_width' => 1,
        'show_levels' => true,
        'level_weak' => 20.0,
        'level_strong' => 25.0,
    ];

    /** @return array<string, mixed> */
    public static function defaultParams(): array
    {
        return self::DEFAULT_PARAMS;
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  array<string,mixed>|null  $params
     * @return array{adx: list<float>, plus: list<float>, minus: list<float>}
     */
    public function computeSeries(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $length = max(1, (int) $p['length']);
        $n = count($rows);

        $dmPlus = array_fill(0, $n, 0.0);
        $dmMinus = array_fill(0, $n, 0.0);
        $tr = array_fill(0, $n, 0.0);
        $smoothedTR = array_fill(0, $n, 0.0);
        $smoothedDMPlus = array_fill(0, $n, 0.0);
        $smoothedDMMinus = array_fill(0, $n, 0.0);
        $diPlus = array_fill(0, $n, 0.0);
        $diMinus = array_fill(0, $n, 0.0);
        $dx = array_fill(0, $n, 0.0);
        $adx = array_fill(0, $n, 0.0);

        for ($index = 0; $index < $n; $index++) {
            if ($index === 0) {
                continue;
            }

            $upMove = (float) $rows[$index]['high'] - (float) $rows[$index - 1]['high'];
            $downMove = (float) $rows[$index - 1]['low'] - (float) $rows[$index]['low'];

            if ($upMove > $downMove && $upMove > 0) {
                $dmPlus[$index] = $upMove;
                $dmMinus[$index] = 0.0;
            } elseif ($downMove > $upMove && $downMove > 0) {
                $dmMinus[$index] = $downMove;
                $dmPlus[$index] = 0.0;
            } else {
                $dmPlus[$index] = 0.0;
                $dmMinus[$index] = 0.0;
            }

            $highLow = (float) $rows[$index]['high'] - (float) $rows[$index]['low'];
            $highPrevClose = abs((float) $rows[$index]['high'] - (float) $rows[$index - 1]['close']);
            $lowPrevClose = abs((float) $rows[$index]['low'] - (float) $rows[$index - 1]['close']);
            $tr[$index] = max($highLow, max($highPrevClose, $lowPrevClose));

            if ($index < $length) {
                $sumTR = 0.0;
                $sumDMPlus = 0.0;
                $sumDMMinus = 0.0;
                for ($i = 0; $i <= $index; $i++) {
                    $sumTR += $tr[$i];
                    $sumDMPlus += $dmPlus[$i];
                    $sumDMMinus += $dmMinus[$i];
                }
                $div = $index + 1;
                $smoothedTR[$index] = $sumTR / $div;
                $smoothedDMPlus[$index] = $sumDMPlus / $div;
                $smoothedDMMinus[$index] = $sumDMMinus / $div;
            } else {
                $smoothedTR[$index] = ($smoothedTR[$index - 1] * ($length - 1) + $tr[$index]) / $length;
                $smoothedDMPlus[$index] = ($smoothedDMPlus[$index - 1] * ($length - 1) + $dmPlus[$index]) / $length;
                $smoothedDMMinus[$index] = ($smoothedDMMinus[$index - 1] * ($length - 1) + $dmMinus[$index]) / $length;
            }

            if ($smoothedTR[$index] > 0) {
                $diPlus[$index] = 100.0 * ($smoothedDMPlus[$index] / $smoothedTR[$index]);
                $diMinus[$index] = 100.0 * ($smoothedDMMinus[$index] / $smoothedTR[$index]);
            }

            $diSum = $diPlus[$index] + $diMinus[$index];
            $diDiff = abs($diPlus[$index] - $diMinus[$index]);
            $dx[$index] = $diSum > 0 ? 100.0 * ($diDiff / $diSum) : 0.0;

            if ($index < $length * 2 - 1) {
                $adx[$index] = 0.0;
            } elseif ($index === $length * 2 - 1) {
                $sumDX = 0.0;
                for ($i = $length; $i <= $index; $i++) {
                    $sumDX += $dx[$i];
                }
                $adx[$index] = $sumDX / $length;
            } else {
                $adx[$index] = ($adx[$index - 1] * ($length - 1) + $dx[$index]) / $length;
            }
        }

        // Округление как в OsEngine (4 знака) на выходе серий.
        for ($i = 0; $i < $n; $i++) {
            $adx[$i] = PriceScale::roundMarket($adx[$i]);
            $diPlus[$i] = PriceScale::roundMarket($diPlus[$i]);
            $diMinus[$i] = PriceScale::roundMarket($diMinus[$i]);
        }

        return ['adx' => $adx, 'plus' => $diPlus, 'minus' => $diMinus];
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  array<string,mixed>|null  $params
     * @return array{
     *   series: array{adx?: list<array{time:int,value:float}>, plus?: list<array{time:int,value:float}>, minus?: list<array{time:int,value:float}>},
     *   style: array<string,mixed>,
     *   levels: array<string,mixed>
     * }
     */
    public function compute(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $raw = $this->computeSeries($rows, $p);
        $series = [];
        if ((bool) $p['show_adx']) {
            $series['adx'] = OsEngineCandleMath::packSeries($rows, $raw['adx']);
        }
        if ((bool) $p['show_plus']) {
            $series['plus'] = OsEngineCandleMath::packSeries($rows, $raw['plus']);
        }
        if ((bool) $p['show_minus']) {
            $series['minus'] = OsEngineCandleMath::packSeries($rows, $raw['minus']);
        }

        $levels = ['show' => false];
        if ((bool) $p['show_levels']) {
            $levels = [
                'show' => true,
                'overbought' => (float) $p['level_strong'],
                'oversold' => (float) $p['level_weak'],
                'color_overbought' => 'rgba(161,161,170,0.55)',
                'color_oversold' => 'rgba(161,161,170,0.55)',
            ];
        }

        return [
            'series' => $series,
            'style' => [
                'color_adx' => (string) $p['color_adx'],
                'color_plus' => (string) $p['color_plus'],
                'color_minus' => (string) $p['color_minus'],
                'line_width' => max(1, (int) $p['line_width']),
            ],
            'levels' => $levels,
        ];
    }
}
