<?php

namespace App\Services\Indicators;

/**
 * Trix — порт OsEngine Indicators/Scripts/Trix.cs.
 * Тройная EMA цены, затем % изменение EMA3: (ema3[i]-ema3[i-1])*100/ema3[i-1].
 * Прогрев OsEngine: index < period*7 - 1.
 */
class TrixIndicatorService
{
    public const ID = 'os.trix';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'length' => 9,
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
        $period = max(1, (int) $p['length']);
        $point = (string) $p['candle_point'];
        $n = count($rows);
        $values = array_fill(0, $n, 0.0);

        $prices = [];
        $ema1 = [];
        $ema2 = [];
        $ema3 = [];

        for ($index = 0; $index < $n; $index++) {
            $prices[$index] = OsEngineCandleMath::candlePoint($rows[$index], $point);
            $ema1[$index] = $this->emaAt($prices, $ema1, $period, $index);
            $ema2[$index] = $this->emaAt($ema1, $ema2, $period, $index);
            $ema3[$index] = $this->emaAt($ema2, $ema3, $period, $index);

            if ($index <= $period) {
                continue;
            }
            // OsEngine: index < period*7-1 || ema3[prev]==0 || ema3==0 → 0
            if ($index < $period * 7 - 1 || ($ema3[$index - 1] ?? 0) == 0.0 || ($ema3[$index] ?? 0) == 0.0) {
                continue;
            }
            $values[$index] = round(($ema3[$index] - $ema3[$index - 1]) * 100.0 / $ema3[$index - 1], 4);
        }

        return $values;
    }

    /**
     * EMA со seed SMA на index==period (как GetEma1/2/3 в OsEngine Trix).
     *
     * @param  list<float>  $src
     * @param  list<float>  $emaPrev
     */
    private function emaAt(array $src, array $emaPrev, int $period, int $index): float
    {
        if ($index < $period) {
            return 0.0;
        }
        if ($index == $period) {
            $sum = 0.0;
            for ($i = $index - $period + 1; $i <= $index; $i++) {
                $sum += (float) $src[$i];
            }

            return round($sum / $period, 8);
        }
        $a = round(2.0 / ($period + 1), 8);
        $last = (float) ($emaPrev[$index - 1] ?? 0);
        $price = (float) $src[$index];

        return round($last + ($a * ($price - $last)), 8);
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  array<string,mixed>|null  $params
     * @return array{series: array<string,mixed>, style: array<string,mixed>, levels: array<string,mixed>}
     */
    public function compute(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $period = max(1, (int) $p['length']);
        $raw = $this->computeSeries($rows, $p);
        $series = [];
        if ((bool) $p['show_line']) {
            $withNull = [];
            $warmup = $period * 7 - 1;
            foreach ($raw as $i => $v) {
                $withNull[$i] = ($i < $warmup) ? null : $v;
            }
            $series['trix'] = OsEngineCandleMath::packSeries($rows, $withNull, false);
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