<?php

namespace App\Services\Indicators;

/**
 * MACD — порт OsEngine Indicators/Scripts/MACD.cs.
 *
 * macd = EMA(fast) − EMA(slow);
 * signal = EMA(macd) со seed SMA на index==signalLength (как в OsEngine ProcessSignalLine);
 * histogram = macd − signal.
 *
 * Важно: валидные значения могут быть ≈0 (пересечение нуля), поэтому packSeries
 * вызываем с skipZeros=false, а «нет значения» храним как null.
 */
class MacdIndicatorService
{
    public const ID = 'os.macd';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'length_fast' => 12,
        'length_slow' => 26,
        'length_signal' => 9,
        // OsEngine: histogram+signal видны, MACD-линия по умолчанию скрыта — у нас показываем все три.
        'show_macd' => true,
        'show_signal' => true,
        'show_histogram' => true,
        'color_macd' => '#006400', // DarkGreen
        'color_signal' => '#8b0000', // DarkRed
        'color_histogram' => '#1e90ff', // DodgerBlue
        'color_histogram_neg' => '#f43f5e', // подсветка отрицательных столбцов
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
     * @return array{macd: list<?float>, signal: list<?float>, histogram: list<?float>}
     */
    public function computeSeries(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $fastLen = max(1, (int) $p['length_fast']);
        $slowLen = max(1, (int) $p['length_slow']);
        $signalLen = max(1, (int) $p['length_signal']);
        $n = count($rows);

        // Переиспользуем уже портированную EMA OsEngine (seed SMA на index==length).
        $emaSvc = new EmaIndicatorService;
        $emaFast = $emaSvc->computeSeries($rows, ['length' => $fastLen, 'candle_point' => 'Close']);
        $emaSlow = $emaSvc->computeSeries($rows, ['length' => $slowLen, 'candle_point' => 'Close']);

        $macd = array_fill(0, $n, null);
        $signal = array_fill(0, $n, null);
        $histogram = array_fill(0, $n, null);

        // Как в OsEngine: до прогрева обеих EMA серия остаётся пустой (у них 0 / return).
        $warmup = max($fastLen, $slowLen);
        for ($index = 0; $index < $n; $index++) {
            if ($index < $warmup) {
                continue;
            }

            $macdVal = round((float) $emaFast[$index] - (float) $emaSlow[$index], 8);
            $macd[$index] = $macdVal;

            // ProcessSignalLine: seed SMA ровно на index==signalLen, иначе EMA от предыдущего сигнала.
            $signalVal = $this->processSignalLine($macd, $signal, $signalLen, $index);
            $signal[$index] = $signalVal;
            $histogram[$index] = round($macdVal - $signalVal, 8);
        }

        return [
            'macd' => $macd,
            'signal' => $signal,
            'histogram' => $histogram,
        ];
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  array<string,mixed>|null  $params
     * @return array{
     *   series: array{
     *     macd?: list<array{time:int,value:float}>,
     *     signal?: list<array{time:int,value:float}>,
     *     histogram?: list<array{time:int,value:float}>
     *   },
     *   style: array<string,mixed>
     * }
     */
    public function compute(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $raw = $this->computeSeries($rows, $p);
        $series = [];

        // skipZeros=false: пересечение нуля — валидное значение MACD/signal/histogram.
        if ((bool) $p['show_macd']) {
            $series['macd'] = OsEngineCandleMath::packSeries($rows, $raw['macd'], false);
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
                'color_macd' => (string) $p['color_macd'],
                'color_signal' => (string) $p['color_signal'],
                'color_histogram' => (string) $p['color_histogram'],
                'color_histogram_neg' => (string) $p['color_histogram_neg'],
                'line_width' => max(1, (int) $p['line_width']),
            ],
        ];
    }

    /**
     * Порт private ProcessSignalLine из MACD.cs.
     *
     * @param  list<?float>  $macdValues
     * @param  list<?float>  $signalValues
     */
    private function processSignalLine(array $macdValues, array $signalValues, int $length, int $index): float
    {
        $result = 0.0;

        if ($index === $length) {
            // Seed: SMA последних `length` значений MACD (включая текущий index).
            $lastMoving = 0.0;
            for ($i = $index - $length + 1; $i <= $index; $i++) {
                $lastMoving += (float) ($macdValues[$i] ?? 0.0);
            }
            $result = $lastMoving / $length;
        } elseif ($index > $length) {
            // Классическая EMA: a = 2/(len+1), prev может быть 0 при позднем прогреве (как OsEngine).
            $a = round(2.0 / ($length + 1), 8);
            $emaLast = (float) ($signalValues[$index - 1] ?? 0.0);
            $p = (float) ($macdValues[$index] ?? 0.0);
            $result = $emaLast + ($a * ($p - $emaLast));
        }

        return round($result, 8);
    }
}
