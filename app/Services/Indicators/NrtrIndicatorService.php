<?php

namespace App\Services\Indicators;

/**
 * NRTR — порт OsEngine Indicators/Scripts/NRTR.cs.
 *
 * Center line переключается между upLine/downLine по пробою close;
 * up/down линии по умолчанию скрыты (IsPainted=false в OsEngine).
 */
class NrtrIndicatorService
{
    public const ID = 'os.nrtr';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'length' => 15,
        'deviation' => 1.0,
        'show_center' => true,
        'show_up' => false,
        'show_down' => false,
        'color_center' => '#ff0000', // Red
        'color_up' => '#00ffff', // Aqua
        'color_down' => '#8a2be2', // BlueViolet
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
     * @return array{center: list<float>, up: list<float>, down: list<float>, direction: list<int>}
     */
    public function computeSeries(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $period = max(1, (int) $p['length']);
        $deviation = (float) $p['deviation'];
        $n = count($rows);

        $up = array_fill(0, $n, 0.0);
        $down = array_fill(0, $n, 0.0);
        $center = array_fill(0, $n, 0.0);
        $directions = array_fill(0, $n, 0);

        for ($index = 0; $index < $n; $index++) {
            if ($index < $period) {
                continue;
            }

            $closePrice = (float) $rows[$index]['close'];
            $closePricePrev = (float) $rows[$index - 1]['close'];

            $maxHigh = (float) $rows[$index]['high'];
            for ($i = $index; $i > -1 && $i > $index - $period; $i--) {
                $h = (float) $rows[$i]['high'];
                if ($maxHigh < $h) {
                    $maxHigh = $h;
                }
            }

            $minLow = (float) $rows[$index]['low'];
            for ($i = $index; $i > -1 && $i > $index - $period; $i--) {
                $l = (float) $rows[$i]['low'];
                if ($minLow > $l) {
                    $minLow = $l;
                }
            }

            $upLine = $maxHigh * (1.0 - $deviation / 100.0);
            $downLine = $minLow * (1.0 + $deviation / 100.0);
            $up[$index] = $upLine;
            $down[$index] = $downLine;

            // Seed на index==period или если prev center == 0.
            if ($index === $period || $center[$index - 1] == 0.0) {
                $dir = $closePrice >= $closePricePrev ? 1 : -1;
                $center[$index] = $dir === 1 ? $upLine : $downLine;
                $directions[$index] = $dir;

                continue;
            }

            $direction = $directions[$index - 1];
            if ($direction === 0) {
                $direction = $closePricePrev >= $center[$index - 1] ? 1 : -1;
            }

            $prevCenter = $center[$index - 1];

            if ($direction === 1) {
                if ($closePrice < $prevCenter) {
                    $direction = -1;
                    $center[$index] = $downLine;
                } else {
                    $center[$index] = $upLine > $prevCenter ? $upLine : $prevCenter;
                }
            } else {
                if ($closePrice > $prevCenter) {
                    $direction = 1;
                    $center[$index] = $upLine;
                } else {
                    $center[$index] = $downLine < $prevCenter ? $downLine : $prevCenter;
                }
            }

            $directions[$index] = $direction;
        }

        return [
            'center' => $center,
            'up' => $up,
            'down' => $down,
            'direction' => $directions,
        ];
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  array<string,mixed>|null  $params
     * @return array{
     *   series: array{center?: list<array{time:int,value:float}>, up?: list<array{time:int,value:float}>, down?: list<array{time:int,value:float}>},
     *   style: array<string,mixed>
     * }
     */
    public function compute(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $raw = $this->computeSeries($rows, $p);
        $series = [];
        if ((bool) $p['show_center']) {
            $series['center'] = OsEngineCandleMath::packSeries($rows, $raw['center']);
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
                'color_center' => (string) $p['color_center'],
                'color_up' => (string) $p['color_up'],
                'color_down' => (string) $p['color_down'],
                'line_width' => max(1, (int) $p['line_width']),
            ],
        ];
    }
}
