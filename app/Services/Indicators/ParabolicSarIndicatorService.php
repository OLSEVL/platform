<?php

namespace App\Services\Indicators;

/**
 * Parabolic SAR — порт OsEngine Indicators/Scripts/ParabolicSAR.cs (ProcessAll / GetValueParabolicSar).
 * Точки на цене: trend=1 → ниже Low, trend=0 → выше High.
 */
class ParabolicSarIndicatorService
{
    public const ID = 'os.parabolic_sar';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'step' => 0.02, // AF
        'max_step' => 0.2, // Max AF
        'show_line' => true,
        'color' => '#1e90ff', // DodgerBlue
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
     * @return list<float>
     */
    public function computeSeries(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $afStep = (float) $p['step'];
        $maxAf = (float) $p['max_step'];
        $n = count($rows);

        $values = [];
        $masTrend = [];
        $masHp = [];
        $masLp = [];
        $masAf = [];

        for ($i = 0; $i < $n; $i++) {
            if (count($values) < 2) {
                $dop = $this->getValueParabolicSar($rows, $i, 0.0, 0.0, 0.0, 0.0, 0.0, $afStep, $maxAf);
            } else {
                $last = count($values) - 1;
                $dop = $this->getValueParabolicSar(
                    $rows,
                    $i,
                    $values[$last],
                    $masTrend[$last],
                    $masHp[$last],
                    $masLp[$last],
                    $masAf[$last],
                    $afStep,
                    $maxAf
                );
            }

            $line = $dop[0];
            $trend = $dop[1];
            $hi = (float) $rows[$i]['high'];
            $lo = (float) $rows[$i]['low'];

            // ProcessAll: если SAR внутринутри бара — прижимаем к Low (бычий) / High (медвежий).
            if ($line > $hi) {
                // bearish point above
            } elseif ($line < $lo) {
                // bullish point below
            } else {
                if ($trend == 1.0) {
                    $line = $lo;
                } else {
                    $line = $hi;
                }
            }

            $values[] = $line;
            $masTrend[] = $dop[1];
            $masHp[] = $dop[2];
            $masLp[] = $dop[3];
            $masAf[] = $dop[4];
        }

        return $values;
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  array<string,mixed>|null  $params
     * @return array{series: array{sar?: list<array{time:int,value:float}>}, style: array<string,mixed>}
     */
    public function compute(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $raw = $this->computeSeries($rows, $p);
        $series = [];
        if ((bool) $p['show_line']) {
            // SAR почти никогда не 0 — но нули всё равно пропускаем.
            $series['sar'] = OsEngineCandleMath::packSeries($rows, $raw);
        }

        return [
            'series' => $series,
            'style' => [
                'color' => (string) $p['color'],
                'point_radius' => max(1, (int) $p['point_radius']),
                'paint_as' => 'points', // LWC: lineVisible=false + pointMarkersVisible
            ],
        ];
    }

    /**
     * Порт GetValueParabolicSar: [line, trend, hp, lp, af].
     *
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $candles
     * @return array{0: float, 1: float, 2: float, 3: float, 4: float}
     */
    private function getValueParabolicSar(
        array $candles,
        int $index,
        float $lineP,
        float $trendP,
        float $hpP,
        float $lpP,
        float $afP,
        float $afStep,
        float $maxAf
    ): array {
        if ($index - 2 < 1) {
            return [
                (float) $candles[$index]['close'],
                1.0,
                (float) $candles[$index]['high'],
                (float) $candles[$index]['low'],
                $afStep,
            ];
        }

        $reverseP = 0;

        if ($trendP == 1.0) {
            if ((float) $candles[$index]['low'] < $lineP) {
                $trendP = 0.0;
                $reverseP = 1;
                $lineP = $hpP;
                $lpP = (float) $candles[$index]['low'];
                $afP = $afStep;
            }
        } else {
            if ((float) $candles[$index]['high'] > $lineP) {
                $trendP = 1.0;
                $reverseP = 1;
                $lineP = $lpP;
                $hpP = (float) $candles[$index]['high'];
                $afP = $afStep;
            }
        }

        if ($reverseP === 0) {
            if ($trendP == 1.0) {
                if ((float) $candles[$index]['high'] > $hpP) {
                    $hpP = (float) $candles[$index]['high'];
                    $afP = $afP + $afStep;
                    if ($afP > $maxAf) {
                        $afP = $maxAf;
                    }
                }
            } else {
                if ((float) $candles[$index]['low'] < $lpP) {
                    $lpP = (float) $candles[$index]['low'];
                    $afP = $afP + $afStep;
                    if ($afP > $maxAf) {
                        $afP = $maxAf;
                    }
                }
            }
        }

        if ($reverseP === 0) {
            if ($trendP == 1.0) {
                $lineP = $lineP + $afP * ($hpP - $lineP);
                if ((float) $candles[$index]['low'] < $lineP) {
                    $lineP = (float) $candles[$index]['low'];
                }
            } else {
                $lineP = $lineP + $afP * ($lpP - $lineP);
                if ((float) $candles[$index]['high'] > $lineP) {
                    $lineP = (float) $candles[$index]['high'];
                }
            }
        }

        return [
            round($lineP, 8),
            $trendP,
            $hpP,
            $lpP,
            $afP,
        ];
    }
}
