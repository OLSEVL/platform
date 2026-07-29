<?php

namespace App\Services\Indicators;

/**
 * EMA — порт OsEngine Indicators/Scripts/Ema.cs.
 * Seed на index==length: SMA окна; далее EMA с a = round(2/(length+1), 8).
 */
class EmaIndicatorService
{
    public const ID = 'os.ema';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'length' => 14,
        'candle_point' => 'Close',
        'show_line' => true,
        'color' => '#8b0000', // DarkRed
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
        if (! in_array($point, OsEngineCandleMath::CANDLE_POINTS, true)) {
            $point = 'Close';
        }
        $n = count($rows);
        $values = array_fill(0, $n, 0.0);

        for ($index = 0; $index < $n; $index++) {
            $result = 0.0;
            if ($index === $length) {
                // OsEngine: сумма с index-length+1 до index включительно / length
                $lastMoving = 0.0;
                for ($i = $index - $length + 1; $i <= $index; $i++) {
                    $lastMoving += OsEngineCandleMath::candlePoint($rows[$i], $point);
                }
                $result = $lastMoving / $length;
            } elseif ($index > $length) {
                $a = round(2.0 / ($length + 1), 8);
                $emaLast = $values[$index - 1];
                $price = OsEngineCandleMath::candlePoint($rows[$index], $point);
                $result = $emaLast + ($a * ($price - $emaLast));
            }
            $values[$index] = round($result, 8);
        }

        return $values;
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  array<string,mixed>|null  $params
     * @return array{series: array{ema?: list<array{time:int,value:float}>}, style: array<string,mixed>}
     */
    public function compute(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $raw = $this->computeSeries($rows, $p);
        $series = [];
        if ((bool) $p['show_line']) {
            $series['ema'] = OsEngineCandleMath::packSeries($rows, $raw);
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
