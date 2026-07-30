<?php

namespace App\Services\Indicators;

/**
 * CCI — порт OsEngine Indicators/Scripts/CCI.cs.
 *
 * CCI = (price − SMA) / (0.015 × meanAbsDev),
 * где meanAbsDev = Σ|price−SMA| / length (в C# «md» — сумма, деление на length в знаменателе).
 *
 * Валидные значения могут быть ≈0 (пересечение нуля) — packSeries с skipZeros=false,
 * «нет значения» храним как null.
 */
class CciIndicatorService
{
    public const ID = 'os.cci';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'length' => 20,
        'candle_point' => 'Typical',
        'show_line' => true,
        'color' => '#5f9ea0', // CadetBlue
        'line_width' => 1,
        // Классические зоны CCI (в OsEngine Scripts уровни не рисуются).
        'level_overbought' => 100.0,
        'level_oversold' => -100.0,
        'show_levels' => true,
    ];

    /** @return array<string, mixed> */
    public static function defaultParams(): array
    {
        return self::DEFAULT_PARAMS;
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  array<string,mixed>|null  $params
     * @return list<?float>
     */
    public function computeSeries(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $length = max(1, (int) $p['length']);
        $point = (string) $p['candle_point'];
        if (! in_array($point, OsEngineCandleMath::CANDLE_POINTS, true)) {
            $point = 'Typical';
        }
        $n = count($rows);
        $values = array_fill(0, $n, null);

        for ($index = 0; $index < $n; $index++) {
            // OsEngine: if (index - length <= 0) return;
            if ($index - $length <= 0) {
                continue;
            }

            $sum = 0.0;
            for ($i = $index; $i > $index - $length; $i--) {
                $sum += OsEngineCandleMath::candlePoint($rows[$i], $point);
            }
            $ma = $sum / $length;

            $md = 0.0; // сумма |ma − price| за окно (не среднее)
            for ($i = $index; $i > $index - $length; $i--) {
                $md += abs($ma - OsEngineCandleMath::candlePoint($rows[$i], $point));
            }
            if ($md == 0.0) {
                continue;
            }

            // (price - ma) / (md * 0.015 / length) == (price - ma) / (0.015 * meanDev)
            $cci = (OsEngineCandleMath::candlePoint($rows[$index], $point) - $ma) / ($md * 0.015 / $length);
            $values[$index] = round($cci, 5);
        }

        return $values;
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  array<string,mixed>|null  $params
     * @return array{
     *   series: array{cci?: list<array{time:int,value:float}>},
     *   style: array<string,mixed>,
     *   levels: array{overbought: float, oversold: float, show: bool}
     * }
     */
    public function compute(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $raw = $this->computeSeries($rows, $p);
        $series = [];
        if ((bool) $p['show_line']) {
            // skipZeros=false: пересечение нуля — валидный сигнал CCI.
            $series['cci'] = OsEngineCandleMath::packSeries($rows, $raw, false);
        }

        return [
            'series' => $series,
            'style' => [
                'color' => (string) $p['color'],
                'line_width' => max(1, (int) $p['line_width']),
            ],
            'levels' => [
                'overbought' => (float) $p['level_overbought'],
                'oversold' => (float) $p['level_oversold'],
                'show' => (bool) $p['show_levels'],
            ],
        ];
    }
}
