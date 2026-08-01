<?php

namespace App\Services\Indicators;

/**
 * DPO (Detrended Price Oscillator) — порт OsEngine Scripts/DPO_Detrended_Price_Oscillator.cs.
 *
 * DPO = X − SMA(length), где X — цена на смещённом баре index − (length/2) + 1
 * (целочисленное деление length/2 как в C#).
 */
class DpoIndicatorService
{
    public const ID = 'os.dpo';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'length_sma' => 14,
        'candle_point' => 'Close',
        'show_line' => true,
        'color' => '#1e90ff', // DodgerBlue
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
        $length = max(1, (int) $p['length_sma']);
        $point = (string) $p['candle_point'];
        if (! in_array($point, OsEngineCandleMath::CANDLE_POINTS, true)) {
            $point = 'Close';
        }
        $n = count($rows);
        $values = array_fill(0, $n, 0.0);

        for ($index = 0; $index < $n; $index++) {
            // OsEngine: if (index < length) return;
            if ($index < $length) {
                continue;
            }

            // C# integer division: index - (length / 2) + 1.
            $shiftIndex = $index - intdiv($length, 2) + 1;
            if ($shiftIndex < 0 || $shiftIndex >= $n) {
                continue;
            }

            $xPrevPeriod = OsEngineCandleMath::candlePoint($rows[$shiftIndex], $point);
            $sma = OsEngineCandleMath::smaAt($rows, $index, $length, $point);
            $values[$index] = $xPrevPeriod - $sma;
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
            $series['dpo'] = OsEngineCandleMath::packSeries($rows, $raw, false);
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
