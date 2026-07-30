<?php

namespace App\Services\Indicators;

/**
 * StdDev — порт OsEngine Indicators/Scripts/StdDev.cs.
 * σ по окну period; length2 quirk OsEngine сохранён.
 */
class StdDevIndicatorService
{
    public const ID = 'os.stddev';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'period' => 20,
        'candle_point' => 'Close',
        'show_line' => true,
        'color' => '#008000',
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
        $period = max(1, (int) $p['period']);
        $point = (string) $p['candle_point'];
        $n = count($rows);
        $values = array_fill(0, $n, 0.0);

        for ($index = 0; $index < $n; $index++) {
            if ($index - $period <= 0) {
                continue;
            }
            // OsEngine quirk: length2 = index-period если index-period <= period, иначе period.
            $length2 = ($index - $period <= $period) ? ($index - $period) : $period;
            if ($length2 <= 0) {
                continue;
            }

            $sum = 0.0;
            for ($j = $index; $j > $index - $period; $j--) {
                $sum += OsEngineCandleMath::candlePoint($rows[$j], $point);
            }
            $m = $sum / $period;

            $sd = 0.0;
            for ($i = $index; $i > $index - $length2; $i--) {
                $x = OsEngineCandleMath::candlePoint($rows[$i], $point) - $m;
                $sd += $x * $x;
            }
            $values[$index] = round(sqrt($sd / $length2), 5);
        }

        return $values;
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
            $series['stddev'] = OsEngineCandleMath::packSeries($rows, $raw);
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