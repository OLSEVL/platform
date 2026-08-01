<?php

namespace App\Services\Indicators;

/**
 * OffsetSma — порт OsEngine Indicators/Scripts/OffsetSma.cs.
 *
 * SMA(Close, length), сдвинутая вправо на offset баров:
 * Values[index] = SMA[index − offset] при index >= offset.
 */
class OffsetSmaIndicatorService
{
    public const ID = 'os.offset_sma';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'length' => 5,
        'offset' => 5,
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
        $offset = max(0, (int) $p['offset']);
        $n = count($rows);

        // Вложенная SMA (ProcessIndicator("OffsetSma")).
        $sma = (new SmaIndicatorService)->computeSeries($rows, [
            'length' => $length,
            'candle_point' => 'Close',
        ]);

        $values = array_fill(0, $n, 0.0);
        for ($index = 0; $index < $n; $index++) {
            // OsEngine: if (index >= _offset) Values[index] = SMA[index - offset]
            if ($index >= $offset) {
                $values[$index] = $sma[$index - $offset];
            }
        }

        return $values;
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  array<string,mixed>|null  $params
     * @return array{series: array{sma?: list<array{time:int,value:float}>}, style: array<string,mixed>}
     */
    public function compute(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $raw = $this->computeSeries($rows, $p);
        $series = [];
        if ((bool) $p['show_line']) {
            $series['sma'] = OsEngineCandleMath::packSeries($rows, $raw);
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
