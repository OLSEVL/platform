<?php

namespace App\Services\Indicators;

/**
 * Linear Regression Channel Fast — порт OsEngine
 * Indicators/Scripts/LinearRegressionChannelFast_Indicator.cs.
 *
 * В отличие от LinRegChannel: CanReBuildHistoricalValues=false у границ —
 * up/down пишутся только на текущем index; mid переписывает окно (как в OnProcess),
 * но без полной исторической перестройки канала на каждом баре для границ.
 */
class LinRegChannelFastIndicatorService
{
    public const ID = 'os.linreg_channel_fast';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'period' => 100,
        'candle_point' => 'Close',
        'up_deviation' => 2.0,
        'down_deviation' => 2.0,
        'show_up' => true,
        'show_mid' => false, // IsPainted=false у Regression Line в OsEngine
        'show_down' => true,
        'color_up' => '#00ffff', // Aqua
        'color_mid' => '#ffd700', // Gold
        'color_down' => '#ff4500', // OrangeRed
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
     * @return array{up: list<float>, mid: list<float>, down: list<float>}
     */
    public function computeSeries(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $period = max(2, (int) $p['period']);
        $point = (string) $p['candle_point'];
        $upDev = (float) $p['up_deviation'];
        $downDev = (float) $p['down_deviation'];
        $n = count($rows);

        $up = array_fill(0, $n, 0.0);
        $mid = array_fill(0, $n, 0.0);
        $down = array_fill(0, $n, 0.0);

        for ($index = 0; $index < $n; $index++) {
            // OsEngine: if (index - period <= 0) return;
            if ($index - $period <= 0) {
                continue;
            }

            $sumy = 0.0;
            $sumx = 0.0;
            $sumxy = 0.0;
            $sumx2 = 0.0;

            // Линейная регрессия по окну [index−period+1 .. index].
            for ($i = $index - $period + 1, $g = 0; $i <= $index; $i++, $g++) {
                $y = OsEngineCandleMath::candlePoint($rows[$i], $point);
                $sumy += $y;
                $sumxy += $y * $g;
                $sumx += $g;
                $sumx2 += $g * $g;
            }

            $c = $sumx2 * $period - $sumx * $sumx;
            if ($c == 0.0) {
                continue;
            }

            $b = ($sumxy * $period - $sumx * $sumy) / $c;
            $a = ($sumy - $sumx * $b) / $period;

            // Переписываем mid на всём окне (как в Fast OnProcess).
            for ($i = $index - $period + 1; $i <= $index; $i++) {
                $mid[$i] = $a + $b * -($index - $period + 1 - $i);
            }

            // Средняя абсолютная ошибка до линии регрессии.
            $standartError = 0.0;
            for ($i = $index - $period + 1; $i <= $index; $i++) {
                $pt = OsEngineCandleMath::candlePoint($rows[$i], $point);
                $standartError += abs($pt - $mid[$i]);
            }
            $standartError /= $period;

            // Границы — ТОЛЬКО на текущем index (CanReBuildHistoricalValues=false).
            $up[$index] = $mid[$index] + ($standartError * $upDev);
            $down[$index] = $mid[$index] - ($standartError * $downDev);
        }

        return ['up' => $up, 'mid' => $mid, 'down' => $down];
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
        if ((bool) $p['show_up']) {
            $series['up'] = OsEngineCandleMath::packSeries($rows, $raw['up']);
        }
        if ((bool) $p['show_mid']) {
            $series['mid'] = OsEngineCandleMath::packSeries($rows, $raw['mid']);
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
