<?php

namespace App\Services\Indicators;

use App\Support\PriceScale;

/**
 * RAVI — порт OsEngine Scripts/RAVI.cs.
 *
 * RAVI = PriceScale::roundMarket((SMA_fast − SMA_slow) / SMA_slow * 100).
 * CalcSma: сумма от index−length до index−1 (текущий бар НЕ включается) / length.
 */
class RaviIndicatorService
{
    public const ID = 'os.ravi';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'length_slow' => 65,
        'length_fast' => 7,
        'candle_point' => 'Close',
        'up_line' => 3.0,
        'down_line' => -3.0,
        'show_ravi' => true,
        'show_up_line' => true,
        'show_down_line' => true,
        'color_ravi' => '#8b0000', // DarkRed (OsEngine seriesSma)
        'color_up_line' => '#ffffe0', // LightYellow
        'color_down_line' => '#ffffe0',
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
     * @return array{ravi: list<float>, up_line: list<float>, down_line: list<float>}
     */
    public function computeSeries(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $slowLen = max(1, (int) $p['length_slow']);
        $fastLen = max(1, (int) $p['length_fast']);
        $point = (string) $p['candle_point'];
        if (! in_array($point, OsEngineCandleMath::CANDLE_POINTS, true)) {
            $point = 'Close';
        }
        $upLine = (float) $p['up_line'];
        $downLine = (float) $p['down_line'];
        $n = count($rows);

        $ravi = array_fill(0, $n, 0.0);
        $up = array_fill(0, $n, $upLine);
        $down = array_fill(0, $n, $downLine);

        for ($index = 0; $index < $n; $index++) {
            // OsEngine: fast+3 > index || slow+3 > index → 0.
            if ($fastLen + 3 > $index || $slowLen + 3 > $index) {
                continue;
            }

            $smaSlow = $this->calcSma($rows, $index, $slowLen, $point);
            $smaFast = $this->calcSma($rows, $index, $fastLen, $point);

            if ($smaSlow == 0.0) {
                continue;
            }

            $ravi[$index] = PriceScale::roundMarket(($smaFast - $smaSlow) / $smaSlow * 100.0);
        }

        return ['ravi' => $ravi, 'up_line' => $up, 'down_line' => $down];
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

        if ((bool) $p['show_ravi']) {
            $series['ravi'] = OsEngineCandleMath::packSeries($rows, $raw['ravi']);
        }
        if ((bool) $p['show_up_line']) {
            $series['up_line'] = OsEngineCandleMath::packSeries($rows, $raw['up_line']);
        }
        if ((bool) $p['show_down_line']) {
            $series['down_line'] = OsEngineCandleMath::packSeries($rows, $raw['down_line']);
        }

        return [
            'series' => $series,
            'style' => [
                'color_ravi' => (string) $p['color_ravi'],
                'color_up_line' => (string) $p['color_up_line'],
                'color_down_line' => (string) $p['color_down_line'],
                'line_width' => max(1, (int) $p['line_width']),
            ],
        ];
    }

    /**
     * CalcSma OsEngine: i от index−length до index−1 (без текущего бара).
     *
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     */
    private function calcSma(array $rows, int $index, int $length, string $point): float
    {
        $sma = 0.0;
        for ($i = $index - $length; $i < $index; $i++) {
            $sma += OsEngineCandleMath::candlePoint($rows[$i], $point);
        }

        return $sma / $length;
    }
}
