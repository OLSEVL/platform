<?php

namespace App\Services\Indicators;

/**
 * Linear Regression Line — порт OsEngine Indicators/Scripts/LinearRegressionLine.cs.
 * Least squares: y = a + b*x; на баре index отдаём значение в конце окна.
 */
class LinRegIndicatorService
{
    public const ID = 'os.linreg';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'period' => 14,
        'candle_point' => 'Close',
        'show_line' => true,
        'color' => '#ff69b4', // HotPink
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
     * @return list<float>
     */
    public function computeSeries(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $period = max(2, (int) $p['period']);
        $point = (string) $p['candle_point'];
        $n = count($rows);
        $values = array_fill(0, $n, 0.0);

        for ($index = 0; $index < $n; $index++) {
            if ($index - $period <= 0) {
                continue;
            }
            $fit = $this->fit($rows, $index, $period, $point);
            if ($fit === null) {
                continue;
            }
            [$a, $b] = $fit;
            // a + b * -(index - period + 1 - index) = a + b*(period-1)
            $values[$index] = $a + $b * ($period - 1);
        }

        return $values;
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @return array{0:float,1:float}|null
     */
    private function fit(array $rows, int $index, int $period, string $point): ?array
    {
        $sumy = 0.0;
        $sumx = 0.0;
        $sumxy = 0.0;
        $sumx2 = 0.0;
        for ($i = $index - $period + 1, $g = 0; $i <= $index; $i++, $g++) {
            $y = OsEngineCandleMath::candlePoint($rows[$i], $point);
            $sumy += $y;
            $sumxy += $y * $g;
            $sumx += $g;
            $sumx2 += $g * $g;
        }
        $c = $sumx2 * $period - $sumx * $sumx;
        if ($c == 0.0) {
            return null;
        }
        $b = ($sumxy * $period - $sumx * $sumy) / $c;
        $a = ($sumy - $sumx * $b) / $period;

        return [$a, $b];
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
        if ((bool) $p['show_line']) {
            $series['linreg'] = OsEngineCandleMath::packSeries($rows, $raw);
        }

        return [
            'series' => $series,
            'style' => [
                'color' => (string) $p['color'],
                'line_width' => max(1, (int) $p['line_width']),
            ],
        ];
    }
}