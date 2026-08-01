<?php

namespace App\Services\Indicators;

/**
 * VHFilter — порт OsEngine Scripts/VHFilter.cs.
 *
 * highLow = max(Close) − min(Close) за period баров.
 * VHFilter = highLow / Σ|Close[i] − Close[i−1]| за period; округление до 2 знаков.
 */
class VhFilterIndicatorService
{
    public const ID = 'os.vh_filter';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'period' => 28,
        'show_line' => true,
        'color' => '#0000ff', // Blue
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
        $n = count($rows);
        $values = array_fill(0, $n, 0.0);
        $highLow = [];

        for ($index = 0; $index < $n; $index++) {
            $highLow[$index] = $this->getHighLow($rows, $index, $period);

            if ($index < $period) {
                continue;
            }

            $sum = 0.0;
            for ($i = $index - $period + 1; $i <= $index; $i++) {
                $sum += abs((float) $rows[$i]['close'] - (float) $rows[$i - 1]['close']);
            }

            if ($sum == 0.0) {
                continue;
            }

            $values[$index] = round($highLow[$index] / $sum, 2);
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
            $series['vh_filter'] = OsEngineCandleMath::packSeries($rows, $raw);
        }

        return [
            'series' => $series,
            'style' => [
                'color' => (string) $p['color'],
                'line_width' => max(1, (int) $p['line_width']),
            ],
        ];
    }

    /**
     * max(Close) − min(Close) в окне [index−period+1 .. index].
     *
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     */
    private function getHighLow(array $rows, int $index, int $period): float
    {
        if ($index < $period) {
            return 0.0;
        }

        $maxHigh = 0.0;
        $maxLow = PHP_FLOAT_MAX;

        for ($i = $index - $period + 1; $i <= $index; $i++) {
            $c = (float) $rows[$i]['close'];
            if ($maxHigh < $c) {
                $maxHigh = $c;
            }
            if ($maxLow > $c) {
                $maxLow = $c;
            }
        }

        return $maxHigh - $maxLow;
    }
}
