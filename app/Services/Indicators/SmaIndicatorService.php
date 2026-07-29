<?php

namespace App\Services\Indicators;

/**
 * SMA — порт OsEngine Indicators/Scripts/Sma.cs.
 * Параметры: Length, Candle Point (Open/High/Low/Close/Median/Typical).
 */
class SmaIndicatorService
{
    public const ID = 'os.sma';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'length' => 14,
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
        $length = max(1, (int) $p['length']);
        $point = (string) $p['candle_point'];
        if (! in_array($point, OsEngineCandleMath::CANDLE_POINTS, true)) {
            $point = 'Close';
        }
        $n = count($rows);
        $values = array_fill(0, $n, 0.0);

        for ($index = 0; $index < $n; $index++) {
            // OsEngine: if (length > index) → 0; иначе Summ(index-length, index)/length.
            // ScriptSpells.Summ суммирует i = start+1 .. end → ровно length баров [index-length+1 .. index].
            if ($length > $index) {
                $values[$index] = 0.0;

                continue;
            }
            $values[$index] = OsEngineCandleMath::smaAt($rows, $index, $length, $point);
        }

        return $values;
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  array<string,mixed>|null  $params
     * @return array{series: array{ma?: list<array{time:int,value:float}>}, style: array<string,mixed>}
     */
    public function compute(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $raw = $this->computeSeries($rows, $p);
        $series = [];
        if ((bool) $p['show_line']) {
            $series['ma'] = OsEngineCandleMath::packSeries($rows, $raw);
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
