<?php

namespace App\Services\Indicators;

/**
 * ParabolicBollinger — порт OsEngine Indicators/Scripts/ParabolicBollinger_indicator.cs.
 *
 * Полосы Боллинджера (SMA ± σ×Deviation) + параболическая линия внутри канала,
 * шаг которой зависит от Hi-Low волатильности за Day/Week/Month.
 */
class ParabolicBollingerIndicatorService
{
    public const ID = 'os.parabolic_bollinger';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'length' => 21,
        'deviation' => 2.0,
        'volatility_period' => 'Day', // Day | Week | Month
        'averaging_period' => 15,
        'volatility_multi' => 0.1,
        'show_up' => true,
        'show_down' => true,
        'show_parabolic' => true,
        'show_volatility' => false, // IsPainted=false в OsEngine
        'color_up' => '#00ffff', // Aqua
        'color_down' => '#8a2be2', // BlueViolet
        'color_parabolic' => '#ffff00', // Yellow
        'color_volatility' => '#7fffd4', // Aquamarine
        'line_width' => 1,
        'point_radius' => 2,
    ];

    /** @return array<string, mixed> */
    public static function defaultParams(): array
    {
        return self::DEFAULT_PARAMS;
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  array<string,mixed>|null  $params
     * @return array{up: list<float>, down: list<float>, parabolic: list<float>, volatility: list<float>}
     */
    public function computeSeries(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $length = max(1, (int) $p['length']);
        $deviation = (float) $p['deviation'];
        $volPeriod = (string) $p['volatility_period'];
        if (! in_array($volPeriod, ['Day', 'Week', 'Month'], true)) {
            $volPeriod = 'Day';
        }
        $averagingPeriod = max(1, (int) $p['averaging_period']);
        $volatMult = (float) $p['volatility_multi'];
        $n = count($rows);

        $up = array_fill(0, $n, 0.0);
        $down = array_fill(0, $n, 0.0);
        $parabolic = array_fill(0, $n, 0.0);
        $volatility = array_fill(0, $n, 0.0);

        // Центральная SMA (ProcessIndicator("Central SMA")).
        $sma = (new SmaIndicatorService)->computeSeries($rows, [
            'length' => $length,
            'candle_point' => 'Close',
        ]);

        $drawBelow = false;
        $lastDrawBelow = false;

        for ($index = 0; $index < $n; $index++) {
            // OsEngine: if (index <= _lenght) return; + if (index <= 20) return;
            if ($index <= $length || $index <= 20) {
                continue;
            }

            $hiLowDifference = $this->getVolatility($rows, $index, $volPeriod);
            if ($hiLowDifference == 0.0) {
                continue;
            }

            $valueVolatility = $hiLowDifference * $volatMult;
            $volatility[$index] = round($valueVolatility, 6);

            // Средний шаг параболы по уже заполненной серии ValueVolatility.
            $valueVolatilityAverage = $this->getAverageVolatilityValue(
                $volatility,
                $index,
                $averagingPeriod,
                $volatMult
            );

            $valueSma = $sma[$index];
            if ($valueSma == 0.0) {
                continue;
            }

            // σ как в Bollinger: сумма квадратов отклонений Close − SMA.
            $sumSq = 0.0;
            $from = $index - $length + 1;
            for ($i = $from; $i <= $index; $i++) {
                $diff = (float) $rows[$i]['close'] - $valueSma;
                $sumSq += $diff * $diff;
            }
            $div = $length > 30 ? ($length - 1) : $length;
            $sigma = $div > 0 ? sqrt($sumSq / $div) : 0.0;

            $upLine = round($valueSma + $sigma * $deviation, 6);
            $downLine = round($valueSma - $sigma * $deviation, 6);
            $up[$index] = $upLine;
            $down[$index] = $downLine;

            // Переключение стороны параболы при выходе за полосы.
            if ((float) $rows[$index]['high'] > $upLine) {
                $drawBelow = true;
            }
            if ((float) $rows[$index]['low'] < $downLine) {
                $drawBelow = false;
            }

            $changePosition = ($lastDrawBelow !== $drawBelow);

            if ($drawBelow) {
                // Растём от нижней полосы вверх.
                if ($parabolic[$index - 1] != 0.0 && $changePosition === false) {
                    $value = $parabolic[$index - 1] + $valueVolatilityAverage;
                    if ($value < $down[$index]) {
                        $value = $down[$index];
                    }
                    if ($value > $up[$index]) {
                        $value = $up[$index];
                    }
                    $parabolic[$index] = $value;
                } else {
                    $parabolic[$index] = $down[$index];
                }
            } else {
                // Падаем от верхней полосы вниз.
                if ($parabolic[$index - 1] != 0.0 && $changePosition === false) {
                    $value = $parabolic[$index - 1] - $valueVolatilityAverage;
                    if ($value > $up[$index]) {
                        $value = $up[$index];
                    }
                    if ($value < $down[$index]) {
                        $value = $down[$index];
                    }
                    $parabolic[$index] = $value;
                } else {
                    $parabolic[$index] = $up[$index];
                }
            }

            $lastDrawBelow = $drawBelow;
        }

        return [
            'up' => $up,
            'down' => $down,
            'parabolic' => $parabolic,
            'volatility' => $volatility,
        ];
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
        if ((bool) $p['show_up']) {
            $series['up'] = OsEngineCandleMath::packSeries($rows, $raw['up']);
        }
        if ((bool) $p['show_down']) {
            $series['down'] = OsEngineCandleMath::packSeries($rows, $raw['down']);
        }
        if ((bool) $p['show_parabolic']) {
            $series['parabolic'] = OsEngineCandleMath::packSeries($rows, $raw['parabolic']);
        }
        if ((bool) $p['show_volatility']) {
            $series['volatility'] = OsEngineCandleMath::packSeries($rows, $raw['volatility']);
        }

        return [
            'series' => $series,
            'style' => [
                'color_up' => (string) $p['color_up'],
                'color_down' => (string) $p['color_down'],
                'color_parabolic' => (string) $p['color_parabolic'],
                'color_volatility' => (string) $p['color_volatility'],
                'line_width' => max(1, (int) $p['line_width']),
                'point_radius' => max(1, (int) $p['point_radius']),
                'paint_parabolic_as' => 'points',
            ],
        ];
    }

    /**
     * Hi−Low диапазон за Day/Week/Month от времени бара (GetVolatility).
     *
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $candles
     */
    private function getVolatility(array $candles, int $index, string $period): float
    {
        $calcStart = (int) $candles[$index]['time'];
        $timeCutoff = $calcStart;
        if (str_contains($period, 'Day')) {
            $timeCutoff = $calcStart - 86400;
        } elseif (str_contains($period, 'Week')) {
            $timeCutoff = $calcStart - 7 * 86400;
        } elseif (str_contains($period, 'Month')) {
            $timeCutoff = (new \DateTimeImmutable('@'.$calcStart))
                ->modify('-1 month')
                ->getTimestamp();
        }

        $high = (float) $candles[$index]['high'];
        $low = (float) $candles[$index]['low'];
        $l = 1;
        while ($index - $l >= 0 && (int) $candles[$index - $l]['time'] >= $timeCutoff) {
            $h = (float) $candles[$index - $l]['high'];
            $lo = (float) $candles[$index - $l]['low'];
            if ($h > $high) {
                $high = $h;
            }
            if ($lo < $low) {
                $low = $lo;
            }
            $l++;
        }

        return $high - $low;
    }

    /**
     * Порт GetAverageVolatilityValue: среднее ValueVolatility×multi за averagingPeriod.
     * Values.Count во время OnProcess ≈ index+1 (серия растёт до текущего бара).
     *
     * @param  list<float>  $values
     */
    private function getAverageVolatilityValue(
        array $values,
        int $index,
        int $averagingPeriod,
        float $volatMult
    ): float {
        $count = $index + 1;
        if ($count <= $averagingPeriod) {
            return $values[$index];
        }

        $avr = 0.0;
        $from = $count - $averagingPeriod;
        for ($i = $from; $i < $count; $i++) {
            $avr += $values[$i] * $volatMult;
        }

        return $avr / $averagingPeriod;
    }
}
