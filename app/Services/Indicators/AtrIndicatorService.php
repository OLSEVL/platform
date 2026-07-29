<?php

namespace App\Services\Indicators;

/**
 * ATR — порт OsEngine Indicators/Scripts/ATR.cs.
 * True Range + сглаживание Wilder; Series type Absolute|Percent.
 */
class AtrIndicatorService
{
    public const ID = 'os.atr';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'length' => 14,
        'series_type' => 'Absolute', // Absolute | Percent
        'show_line' => true,
        'color' => '#1e90ff',
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
        $percent = ((string) $p['series_type']) === 'Percent';
        $n = count($rows);

        $trueRange = array_fill(0, $n, 0.0);
        $moving = array_fill(0, $n, 0.0);
        $values = array_fill(0, $n, 0.0);

        for ($index = 0; $index < $n; $index++) {
            if ($index === 0) {
                $trueRange[0] = 0.0;
            } else {
                $hiToLow = abs((float) $rows[$index]['high'] - (float) $rows[$index]['low']);
                $closeToHigh = abs((float) $rows[$index - 1]['close'] - (float) $rows[$index]['high']);
                $closeToLow = abs((float) $rows[$index - 1]['close'] - (float) $rows[$index]['low']);
                $tr = max($hiToLow, $closeToHigh, $closeToLow);
                if ($percent && $tr != 0.0) {
                    $prevOpen = (float) $rows[$index - 1]['open'];
                    if ($prevOpen != 0.0) {
                        $tr = $tr / ($prevOpen / 100.0);
                    }
                }
                $trueRange[$index] = $tr;
            }

            $this->movingAverageWild($trueRange, $moving, $length, $index);
            $values[$index] = round($moving[$index], 9);
        }

        return $values;
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  array<string,mixed>|null  $params
     * @return array{series: array{atr?: list<array{time:int,value:float}>}, style: array<string,mixed>}
     */
    public function compute(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $raw = $this->computeSeries($rows, $p);
        $series = [];
        if ((bool) $p['show_line']) {
            $series['atr'] = OsEngineCandleMath::packSeries($rows, $raw);
        }

        return [
            'series' => $series,
            'style' => [
                'color' => (string) $p['color'],
                'line_width' => max(1, (int) $p['line_width']),
                'series_type' => (string) $p['series_type'],
            ],
        ];
    }

    /**
     * Порт MovingAverageWild из ATR.cs (потоковый вариант без list.Add).
     * Seed: SMA первых `length` TR (индекс length-1); далее Wilder.
     *
     * @param  list<float>  $valuesSeries
     * @param  list<float>  $moving
     */
    private function movingAverageWild(array $valuesSeries, array &$moving, int $length, int $index): void
    {
        if ($index < $length - 1) {
            $moving[$index] = 0.0;

            return;
        }

        if ($index === $length - 1) {
            $sum = 0.0;
            for ($i = 0; $i <= $index; $i++) {
                $sum += $valuesSeries[$i];
            }
            $moving[$index] = $sum / $length;

            return;
        }

        // Wilder: (prev*(len-1) + tr) / len
        $lastValueSeries = round($valuesSeries[$index], 9);
        $lastValueMoving = $moving[$index - 1];
        $moving[$index] = round(($lastValueMoving * ($length - 1) + $lastValueSeries) / $length, 9);
    }
}
