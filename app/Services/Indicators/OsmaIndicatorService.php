<?php

namespace App\Services\Indicators;

/**
 * OsMa — порт OsEngine Indicators/Scripts/OsMa.cs.
 * OsMa = MACD − Signal; затем EMA(OsMa) как «Signal Line»; Histogram = OsMa − SignalLine.
 */
class OsmaIndicatorService
{
    public const ID = 'os.osma';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'length_fast' => 12,
        'length_slow' => 26,
        'length_signal' => 9,
        'show_osma' => true,
        'show_signal' => true,
        'show_histogram' => true,
        'color_osma' => '#ffff00', // Yellow
        'color_signal' => '#ff0000', // Red
        'color_histogram' => '#1e90ff',
        'color_histogram_neg' => '#f43f5e',
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
     * @return array{osma: list<?float>, signal: list<?float>, histogram: list<?float>}
     */
    public function computeSeries(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $fast = max(1, (int) $p['length_fast']);
        $slow = max(1, (int) $p['length_slow']);
        $sigLen = max(1, (int) $p['length_signal']);
        $n = count($rows);

        $macdBag = (new MacdIndicatorService)->computeSeries($rows, [
            'length_fast' => $fast,
            'length_slow' => $slow,
            'length_signal' => $sigLen,
        ]);

        $osma = array_fill(0, $n, null);
        $signal = array_fill(0, $n, null);
        $histogram = array_fill(0, $n, null);

        $warmup = max($fast, $slow, $sigLen);
        for ($index = 0; $index < $n; $index++) {
            if ($index < $warmup) {
                continue;
            }
            $macd = $macdBag['macd'][$index];
            $macdSignal = $macdBag['signal'][$index];
            if ($macd === null || $macdSignal === null) {
                continue;
            }
            $osmaVal = (float) $macd - (float) $macdSignal;
            $osma[$index] = $osmaVal;

            // ProcessSignalLine над серией OsMa (seed SMA на index==sigLen).
            $signalVal = $this->emaOnSeries($osma, $signal, $sigLen, $index);
            $signal[$index] = $signalVal;
            $histogram[$index] = $osmaVal - $signalVal;
        }

        return ['osma' => $osma, 'signal' => $signal, 'histogram' => $histogram];
    }

    /**
     * @param  list<?float>  $values
     * @param  list<?float>  $emaOut
     */
    private function emaOnSeries(array $values, array $emaOut, int $length, int $index): float
    {
        if ($index == $length) {
            $sum = 0.0;
            for ($i = $index - $length + 1; $i <= $index; $i++) {
                $sum += (float) ($values[$i] ?? 0);
            }

            return round($sum / $length, 8);
        }
        if ($index > $length) {
            $a = round(2.0 / ($length + 1), 8);
            $emaLast = (float) ($emaOut[$index - 1] ?? 0);
            $p = (float) ($values[$index] ?? 0);

            return round($emaLast + ($a * ($p - $emaLast)), 8);
        }

        return 0.0;
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
        if ((bool) $p['show_osma']) {
            $series['osma'] = OsEngineCandleMath::packSeries($rows, $raw['osma'], false);
        }
        if ((bool) $p['show_signal']) {
            $series['signal'] = OsEngineCandleMath::packSeries($rows, $raw['signal'], false);
        }
        if ((bool) $p['show_histogram']) {
            $series['histogram'] = OsEngineCandleMath::packSeries($rows, $raw['histogram'], false);
        }

        return [
            'series' => $series,
            'style' => [
                'color_osma' => (string) $p['color_osma'],
                'color_signal' => (string) $p['color_signal'],
                'color_histogram' => (string) $p['color_histogram'],
                'color_histogram_neg' => (string) $p['color_histogram_neg'],
                'line_width' => max(1, (int) $p['line_width']),
            ],
        ];
    }
}