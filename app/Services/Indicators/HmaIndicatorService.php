<?php

namespace App\Services\Indicators;

/**
 * Hull Moving Average (HMA) — порт OsEngine Indicators/Scripts/HMA.cs.
 *
 * HMA = WMA(2*WMA(n/2) - WMA(n), sqrt(n)).
 * Важно: GetWma в OsEngine использует цикл i=0..period включительно (period+1 весов).
 */
class HmaIndicatorService
{
    public const ID = 'os.hma';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'length' => 14,
        'candle_point' => 'Close',
        'show_line' => true,
        'color' => '#ff0000', // Red
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
        $length = max(1, (int) $p['length']);
        $point = (string) $p['candle_point'];
        $n = count($rows);

        $intermediate = array_fill(0, $n, 0.0);
        $values = array_fill(0, $n, 0.0);

        $halfLen = (int) round($length / 2.0);
        $sqrtLen = (int) round(sqrt($length));

        for ($index = 0; $index < $n; $index++) {
            if ($length > $index) {
                continue;
            }

            $wma1 = $this->getWma($rows, $index, $halfLen, $point);
            $wma2 = $this->getWma($rows, $index, $length, $point);
            $intermediate[$index] = 2.0 * $wma1 - $wma2;
            $values[$index] = $this->getWmaSeries($intermediate, $index, $sqrtLen);
        }

        return $values;
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  array<string,mixed>|null  $params
     * @return array{
     *   series: array{hma?: list<array{time:int,value:float}>},
     *   style: array<string,mixed>
     * }
     */
    public function compute(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $raw = $this->computeSeries($rows, $p);
        $series = [];
        if ((bool) $p['show_line']) {
            $series['hma'] = OsEngineCandleMath::packSeries($rows, $raw);
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
     * WMA по свечам — порт GetWma из HMA.cs (веса 0..period).
     *
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     */
    private function getWma(array $rows, int $index, int $period, string $point): float
    {
        $sum = 0.0;
        $weights = 0.0;

        for ($i = 0; $i <= $period; $i++) {
            $srcIdx = $index - ($period - $i);
            $price = OsEngineCandleMath::candlePoint($rows[$srcIdx], $point);
            $sum += $price * $i;
            $weights += $i;
        }

        $result = $weights != 0.0 ? $sum / $weights : 0.0;

        return round($result, 4);
    }

    /**
     * WMA по промежуточной серии — порт GetWmaSeries из HMA.cs.
     *
     * @param  list<float>  $series
     */
    private function getWmaSeries(array $series, int $index, int $period): float
    {
        $sum = 0.0;
        $weights = 0.0;

        for ($i = 0; $i <= $period; $i++) {
            $srcIdx = $index - ($period - $i);
            $sum += $series[$srcIdx] * $i;
            $weights += $i;
        }

        $result = $weights != 0.0 ? $sum / $weights : 0.0;

        return round($result, 4);
    }
}
