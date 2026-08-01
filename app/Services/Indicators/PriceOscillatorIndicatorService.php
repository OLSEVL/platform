<?php

namespace App\Services\Indicators;

/**
 * Price Oscillator (PO) — порт OsEngine CandleChart/Indicators/PriceOscillator.cs.
 *
 * PO = SMA(short) − SMA(long); режим Punkt — в пунктах, Persent — в % от short MA:
 * result = difference / (maShort / 100).
 * По умолчанию MA Simple, Length 12/26 (конструктор с uniqName).
 */
class PriceOscillatorIndicatorService
{
    public const ID = 'os.price_oscillator';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'length_short' => 12,
        'length_long' => 26,
        'candle_point' => 'Close',
        'type_search' => 'Punkt', // Punkt | Persent (орфография OsEngine)
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
        $lenShort = max(1, (int) $p['length_short']);
        $lenLong = max(1, (int) $p['length_long']);
        $point = (string) $p['candle_point'];
        $typeSearch = (string) $p['type_search'];
        $n = count($rows);
        $values = array_fill(0, $n, 0.0);

        // MovingAverage по умолчанию TypeCalculationAverage=Simple.
        $smaSvc = new SmaIndicatorService;
        $maShort = $smaSvc->computeSeries($rows, ['length' => $lenShort, 'candle_point' => $point]);
        $maLong = $smaSvc->computeSeries($rows, ['length' => $lenLong, 'candle_point' => $point]);

        for ($index = 0; $index < $n; $index++) {
            // OsEngine GetValue: если short/long == 0 → 0.
            if ($maShort[$index] == 0.0 || $maLong[$index] == 0.0) {
                continue;
            }

            $difference = (float) $maShort[$index] - (float) $maLong[$index];

            if ($typeSearch === 'Persent') {
                // Percent: difference / (maShort / 100)
                $values[$index] = round($difference / ((float) $maShort[$index] / 100.0), 8);
            } else {
                // Punkt (дефолт)
                $values[$index] = round($difference, 8);
            }
        }

        return $values;
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  array<string,mixed>|null  $params
     * @return array{series: array{po?: list<array{time:int,value:float}>}, style: array<string,mixed>}
     */
    public function compute(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $raw = $this->computeSeries($rows, $p);
        $series = [];
        if ((bool) $p['show_line']) {
            // PO может быть ≈0 при равенстве MA — не выкидываем нули.
            $series['po'] = OsEngineCandleMath::packSeries($rows, $raw, false);
        }

        return [
            'series' => $series,
            'style' => [
                'color' => (string) $p['color'],
                'line_width' => max(1, (int) $p['line_width']),
                'type_search' => (string) $p['type_search'],
            ],
        ];
    }
}
