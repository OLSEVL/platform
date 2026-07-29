<?php

namespace App\Services\Indicators;

/**
 * Bollinger Bands — порт OsEngine Indicators/Scripts/Bollinger.cs.
 * Центр = SMA(Close, Length); полосы = SMA ± Deviation * σ.
 * σ: при Length > 30 делитель (n-1), иначе n (как в OsEngine).
 */
class BollingerIndicatorService
{
    public const ID = 'os.bollinger';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'length' => 21,
        'deviation' => 2.0,
        'show_up' => true,
        'show_mid' => true,
        'show_down' => true,
        'color_up' => '#22c55e',
        'color_mid' => '#22c55e',
        'color_down' => '#22c55e',
        'line_width' => 1,
    ];

    /** @return array<string, mixed> */
    public static function defaultParams(): array
    {
        return self::DEFAULT_PARAMS;
    }

    /**
     * Сырые массивы mid/up/down (длина = count($rows)); 0 = нет значения.
     *
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  array<string,mixed>|null  $params
     * @return array{mid: list<float>, up: list<float>, down: list<float>}
     */
    public function computeSeries(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $length = max(1, (int) $p['length']);
        $deviation = (float) $p['deviation'];
        $n = count($rows);

        $mid = array_fill(0, $n, 0.0);
        $up = array_fill(0, $n, 0.0);
        $down = array_fill(0, $n, 0.0);

        // OsEngine: if (index <= length) return; → первое значение на index = length+1
        for ($index = $length + 1; $index < $n; $index++) {
            $valueSma = OsEngineCandleMath::smaAt($rows, $index, $length, 'Close');
            if ($valueSma == 0.0) {
                continue;
            }
            $mid[$index] = $valueSma;

            $sumSq = 0.0;
            $from = $index - $length + 1;
            for ($i = $from; $i <= $index; $i++) {
                $diff = (float) $rows[$i]['close'] - $valueSma;
                $sumSq += $diff * $diff;
            }
            // OsEngine: length > 30 → / (n-1), иначе / n
            $div = $length > 30 ? ($length - 1) : $length;
            $sigma = $div > 0 ? sqrt($sumSq / $div) : 0.0;

            $up[$index] = round($valueSma + $sigma * $deviation, 6);
            $down[$index] = round($valueSma - $sigma * $deviation, 6);
        }

        return ['mid' => $mid, 'up' => $up, 'down' => $down];
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  array<string,mixed>|null  $params
     * @return array{
     *   series: array{mid?: list<array{time:int,value:float}>, up?: list<array{time:int,value:float}>, down?: list<array{time:int,value:float}>},
     *   style: array<string,mixed>
     * }
     */
    public function compute(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $raw = $this->computeSeries($rows, $p);

        $series = [];
        if ((bool) $p['show_mid']) {
            $series['mid'] = OsEngineCandleMath::packSeries($rows, $raw['mid']);
        }
        if ((bool) $p['show_up']) {
            $series['up'] = OsEngineCandleMath::packSeries($rows, $raw['up']);
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
