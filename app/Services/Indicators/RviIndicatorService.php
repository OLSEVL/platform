<?php

namespace App\Services\Indicators;

use App\Support\PriceScale;

/**
 * RVI — порт OsEngine Indicators/Scripts/RVI.cs.
 * Value1 = sum(moveAvg)/sum(rangeAvg) за period;
 * Value2 = (rvi + 2*rvi1 + 2*rvi2 + rvi3)/6.
 */
class RviIndicatorService
{
    public const ID = 'os.rvi';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'period' => 5,
        'show_rvi' => true,
        'show_signal' => true,
        'color_rvi' => '#8b0000', // DarkRed
        'color_signal' => '#1e90ff',
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
     * @return array{rvi: list<float>, signal: list<float>}
     */
    public function computeSeries(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $period = max(1, (int) $p['period']);
        $n = count($rows);
        $rvi = array_fill(0, $n, 0.0);
        $signal = array_fill(0, $n, 0.0);
        $moveAvg = array_fill(0, $n, 0.0);
        $rangeAvg = array_fill(0, $n, 0.0);

        for ($index = 0; $index < $n; $index++) {
            if ($index <= $period) {
                continue;
            }
            if ($index > 3) {
                $moveAvg[$index] =
                    ((float) $rows[$index]['close'] - (float) $rows[$index]['open'])
                    + 2.0 * ((float) $rows[$index - 1]['close'] - (float) $rows[$index - 1]['open'])
                    + 2.0 * ((float) $rows[$index - 2]['close'] - (float) $rows[$index - 2]['open'])
                    + ((float) $rows[$index - 3]['close'] - (float) $rows[$index - 3]['open']);
                $rangeAvg[$index] =
                    ((float) $rows[$index]['high'] - (float) $rows[$index]['low'])
                    + 2.0 * ((float) $rows[$index - 1]['high'] - (float) $rows[$index - 1]['low'])
                    + 2.0 * ((float) $rows[$index - 2]['high'] - (float) $rows[$index - 2]['low'])
                    + ((float) $rows[$index - 3]['high'] - (float) $rows[$index - 3]['low']);
            }

            if ($index - $period + 1 <= 0) {
                continue;
            }
            $sumMa = 0.0;
            $sumRa = 0.0;
            for ($i = $index - $period + 1; $i <= $index; $i++) {
                $sumMa += $moveAvg[$i];
                $sumRa += $rangeAvg[$i];
            }
            if ($sumRa == 0.0 || $sumMa == 0.0) {
                $rvi[$index] = 0.0;
            } else {
                $rvi[$index] = PriceScale::roundMarket($sumMa / $sumRa);
            }

            if ($index >= $period + 6) {
                $signal[$index] = round(
                    ($rvi[$index] + 2.0 * $rvi[$index - 1] + 2.0 * $rvi[$index - 2] + $rvi[$index - 3]) / 6.0,
                    2
                );
            }
        }

        return ['rvi' => $rvi, 'signal' => $signal];
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
        if ((bool) $p['show_rvi']) {
            $series['rvi'] = OsEngineCandleMath::packSeries($rows, $raw['rvi'], false);
        }
        if ((bool) $p['show_signal']) {
            $series['signal'] = OsEngineCandleMath::packSeries($rows, $raw['signal'], false);
        }

        return [
            'series' => $series,
            'style' => [
                'color_rvi' => (string) $p['color_rvi'],
                'color_signal' => (string) $p['color_signal'],
                'line_width' => max(1, (int) $p['line_width']),
            ],
        ];
    }
}