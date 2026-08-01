<?php

namespace App\Services\Indicators;

/**
 * ParabolicPriceChannel — порт OsEngine Indicators/Scripts/ParabolicPriceChannel_indicator.cs.
 *
 * Канал max High / min Low + параболическая линия внутри с шагом
 * по исторической Hi−Low волатильности (Day/Week/Month).
 */
class ParabolicPriceChannelIndicatorService
{
    public const ID = 'os.parabolic_price_channel';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'length_up' => 21,
        'length_down' => 21,
        'volatility_period' => 'Day', // Day | Week | Month
        'averaging_period' => 15,
        'volatility_multi' => 0.1,
        'show_up' => true,
        'show_down' => true,
        'show_parabolic' => true,
        'show_volatility' => false,
        'color_up' => '#00ffff', // Aqua
        'color_down' => '#8a2be2', // BlueViolet
        'color_parabolic' => '#00ffff', // Aqua (Point series)
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
        $lengthUp = max(1, (int) $p['length_up']);
        $lengthDown = max(1, (int) $p['length_down']);
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

        $drawBelow = false;
        $lastDrawBelow = false;

        for ($index = 0; $index < $n; $index++) {
            // На первом баре сбрасываем сторону (как в OnProcess index==0).
            if ($index === 0) {
                $drawBelow = false;
                $lastDrawBelow = false;
            }

            if ($index <= 20) {
                continue;
            }
            if ($index < $lengthUp || $index < $lengthDown) {
                continue;
            }

            $hiLowDifference = $this->getVolatility($rows, $index, $volPeriod);
            $valueVolatility = $hiLowDifference * $volatMult;
            $volatility[$index] = round($valueVolatility, 6);

            $valueVolatilityAverage = $this->getAverageVolatilityValue(
                $volatility,
                $index,
                $averagingPeriod,
                $volatMult
            );

            $upLine = 0.0;
            if ($index - $lengthUp > 0) {
                for ($i = $index; $i > -1 && $i > $index - $lengthUp; $i--) {
                    $h = (float) $rows[$i]['high'];
                    if ($upLine < $h) {
                        $upLine = $h;
                    }
                }
            }

            $downLine = 0.0;
            if ($index - $lengthDown > 0) {
                $downLine = PHP_FLOAT_MAX;
                for ($i = $index; $i > -1 && $i > $index - $lengthDown; $i--) {
                    $l = (float) $rows[$i]['low'];
                    if ($downLine > $l) {
                        $downLine = $l;
                    }
                }
            }

            $up[$index] = $upLine;
            $down[$index] = $downLine === PHP_FLOAT_MAX ? 0.0 : $downLine;

            // Сторона: касание/пробой верхней → рисуем снизу; нижней → сверху.
            if ((float) $rows[$index]['high'] >= $upLine) {
                $drawBelow = true;
            }
            if ((float) $rows[$index]['low'] <= $down[$index]) {
                $drawBelow = false;
            }

            $changePosition = ($lastDrawBelow !== $drawBelow);

            if ($drawBelow) {
                if ($changePosition === true) {
                    // При смене стороны пишем текущий и предыдущий бар на нижнюю границу.
                    $parabolic[$index] = $down[$index];
                    $parabolic[$index - 1] = $down[$index];
                } else {
                    $value = $parabolic[$index - 1] + $valueVolatilityAverage;
                    if ($value < $down[$index]) {
                        $value = $down[$index];
                    }
                    if ($value > $up[$index]) {
                        $value = $up[$index];
                    }
                    $parabolic[$index] = $value;
                }
            } else {
                if ($changePosition === true) {
                    $parabolic[$index] = $up[$index];
                    $parabolic[$index - 1] = $up[$index];
                } else {
                    $value = $parabolic[$index - 1] - $valueVolatilityAverage;
                    if ($value > $up[$index]) {
                        $value = $up[$index];
                    }
                    if ($value < $down[$index]) {
                        $value = $down[$index];
                    }
                    $parabolic[$index] = $value;
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
     * Порт GetAverageVolatilityValue(index, values) из ParabolicPriceChannel.
     *
     * @param  list<float>  $values
     */
    private function getAverageVolatilityValue(
        array $values,
        int $index,
        int $averagingPeriod,
        float $volatMult
    ): float {
        if ($index <= $averagingPeriod) {
            return 0.0;
        }

        $avr = 0.0;
        $startIndex = $index - $averagingPeriod;
        if ($startIndex < 0) {
            $startIndex = 0;
        }
        for ($i = $startIndex; $i <= $index; $i++) {
            $avr += $values[$i] * $volatMult;
        }

        return $avr / $averagingPeriod;
    }
}
