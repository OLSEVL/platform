<?php

namespace App\Services\Indicators;

/**
 * Accelerator Oscillator (AC) — порт OsEngine CandleChart/Indicators/Ac.cs.
 *
 * AC = AO − SMA(AO, length_short).
 * AO = SMA(Median, short) − SMA(Median, long) — простые MA по Median, НЕ EMA из AoIndicatorService.
 * Периоды по умолчанию: long=34, short=5 (в AwesomeOscillator chart-версии short=5, long=32/34).
 */
class AcIndicatorService
{
    public const ID = 'os.ac';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'length_long' => 34,
        'length_short' => 5,
        'show_histogram' => true,
        'color_histogram' => '#00bfff', // DeepSkyBlue
        'color_histogram_neg' => '#8b0000', // DarkRed
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
        $longLen = max(1, (int) $p['length_long']);
        $shortLen = max(1, (int) $p['length_short']);
        $n = count($rows);

        // AO на Median через простые SMA (TypeCalculationAverage=Simple в Ac.cs).
        $smaSvc = new SmaIndicatorService;
        $smaShort = $smaSvc->computeSeries($rows, ['length' => $shortLen, 'candle_point' => 'Median']);
        $smaLong = $smaSvc->computeSeries($rows, ['length' => $longLen, 'candle_point' => 'Median']);

        $ao = array_fill(0, $n, 0.0);
        for ($index = 0; $index < $n; $index++) {
            // OsEngine AO: index - LengthLong <= 0 || index - LengthShort <= 0 → 0.
            if ($index - $longLen <= 0 || $index - $shortLen <= 0) {
                continue;
            }
            $ao[$index] = round((float) $smaShort[$index] - (float) $smaLong[$index], 6);
        }

        // SMA(AO, length_short) — сглаживание серии AO простой MA.
        $aoSma = array_fill(0, $n, 0.0);
        for ($index = 0; $index < $n; $index++) {
            if ($shortLen > $index) {
                continue;
            }
            $sum = 0.0;
            for ($i = $index - $shortLen + 1; $i <= $index; $i++) {
                $sum += $ao[$i];
            }
            $aoSma[$index] = $sum / $shortLen;
        }

        $values = array_fill(0, $n, 0.0);
        for ($index = 0; $index < $n; $index++) {
            // OsEngine: index < short || index < long || ao==0 → 0.
            if ($index < $shortLen || $index < $longLen || $ao[$index] == 0.0) {
                continue;
            }
            $values[$index] = $ao[$index] - $aoSma[$index];
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

        if ((bool) $p['show_histogram']) {
            // skipZeros=false: нулевое пересечение AC — валидная точка.
            $series['ac'] = OsEngineCandleMath::packSeries($rows, $raw, false);
        }

        return [
            'series' => $series,
            'style' => [
                'color_histogram' => (string) $p['color_histogram'],
                'color_histogram_neg' => (string) $p['color_histogram_neg'],
            ],
        ];
    }
}
