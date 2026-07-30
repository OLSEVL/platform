<?php

namespace App\Services\Indicators;

/**
 * VWMA — порт OsEngine Indicators/Scripts/VWMA.cs.
 * VWMA = Σ(price × volume) / Σ(volume) по окну Length.
 * Если сумма объёмов = 0, остаётся 0 (как в OsEngine).
 */
class VwmaIndicatorService
{
    public const ID = 'os.vwma';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'length' => 14,
        'candle_point' => 'Close',
        'show_line' => true,
        'color' => '#00ffff', // Aqua
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
            // OsEngine: if (length > index) return; иначе окно [index-length+1 .. index].
            if ($length > $index) {
                continue;
            }

            $average = 0.0;
            $weights = 0.0;
            for ($i = $index; $i > $index - $length; $i--) {
                $vol = (float) ($rows[$i]['volume'] ?? 0);
                $average += OsEngineCandleMath::candlePoint($rows[$i], $point) * $vol;
                $weights += $vol;
            }
            if ($weights != 0.0) {
                $average = $average / $weights;
            }
            $values[$index] = $average;
        }

        return $values;
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  array<string,mixed>|null  $params
     * @return array{series: array{vwma?: list<array{time:int,value:float}>}, style: array<string,mixed>}
     */
    public function compute(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $raw = $this->computeSeries($rows, $p);
        $series = [];
        if ((bool) $p['show_line']) {
            $series['vwma'] = OsEngineCandleMath::packSeries($rows, $raw);
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
