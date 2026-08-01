<?php

namespace App\Services\Indicators;

/**
 * OffsetEma — порт OsEngine Indicators/Scripts/OffsetEma.cs.
 *
 * EMA(Close, length), сдвинутая вправо на offset баров:
 * Values[index] = EMA[index − offset] при index − offset >= 0.
 */
class OffsetEmaIndicatorService
{
    public const ID = 'os.offset_ema';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'length' => 5,
        'offset' => 5,
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
        $offset = max(0, (int) $p['offset']);
        $n = count($rows);

        // Вложенная EMA (ProcessIndicator("EmaOffset")).
        $ema = (new EmaIndicatorService)->computeSeries($rows, [
            'length' => $length,
            'candle_point' => 'Close',
        ]);

        $values = array_fill(0, $n, 0.0);
        for ($index = 0; $index < $n; $index++) {
            // OsEngine: if (index - offset >= 0) Values[index] = EMA[index - offset]
            if ($index - $offset >= 0) {
                $values[$index] = $ema[$index - $offset];
            }
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
