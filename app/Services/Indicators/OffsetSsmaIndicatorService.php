<?php

namespace App\Services\Indicators;

/**
 * Offset SSMA — порт OsEngine Indicators/Scripts/OffsetSsma.cs.
 *
 * Строит SSMA и сдвигает её вправо на `offset` баров:
 * Values[index] = Ssma[index − offset] (при index >= offset).
 * Используется для сравнения текущей цены с «исторической» средней.
 */
class OffsetSsmaIndicatorService
{
    public const ID = 'os.offset_ssma';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'length' => 5, // Ssma length в OffsetSsma.cs
        'offset' => 5, // Ssma offset
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
     * @return list<float>  0 = прогрев / нет значения
     */
    public function computeSeries(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $length = max(1, (int) $p['length']);
        $offset = max(0, (int) $p['offset']);
        $point = (string) $p['candle_point'];
        $n = count($rows);
        $values = array_fill(0, $n, 0.0);

        // Базовая SSMA без сдвига (как вложенный индикатор Ssma в OsEngine).
        $ssmaSvc = new SsmaIndicatorService;
        $ssma = $ssmaSvc->computeSeries($rows, [
            'length' => $length,
            'candle_point' => $point,
        ]);

        for ($index = 0; $index < $n; $index++) {
            // OsEngine: if (index >= _offset.ValueInt) → взять SSMA[index − offset].
            if ($index >= $offset) {
                $values[$index] = (float) $ssma[$index - $offset];
            }
        }

        return $values;
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  array<string,mixed>|null  $params
     * @return array{series: array{ssma?: list<array{time:int,value:float}>}, style: array<string,mixed>}
     */
    public function compute(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $raw = $this->computeSeries($rows, $p);
        $series = [];
        if ((bool) $p['show_line']) {
            $series['ssma'] = OsEngineCandleMath::packSeries($rows, $raw);
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
