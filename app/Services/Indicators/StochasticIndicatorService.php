<?php

namespace App\Services\Indicators;

/**
 * Stochastic — порт OsEngine Indicators/Scripts/Stochastic.cs.
 *
 * Period1: окно high/low для сырых t1/t2.
 * Period2: SMA(t1)/SMA(t2) → %K.
 * Period3: SMA(%K) → %D («K average»).
 *
 * GetK возвращает 0 до прогрева Period2+Period3+3 — как в OsEngine;
 * на график нули не отдаём (packSeries skipZeros=true).
 */
class StochasticIndicatorService
{
    public const ID = 'os.stochastic';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'period1' => 5,
        'period2' => 3,
        'period3' => 3,
        'show_k' => true,
        'show_d' => true,
        'color_k' => '#1e90ff', // DodgerBlue
        'color_d' => '#8b0000', // DarkRed
        'line_width' => 1,
        // Классические зоны стохастика (в OsEngine Scripts уровни не рисуются — добавляем для pane).
        'level_overbought' => 80.0,
        'level_oversold' => 20.0,
        'show_levels' => true,
    ];

    /** @return array<string, mixed> */
    public static function defaultParams(): array
    {
        return self::DEFAULT_PARAMS;
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  array<string,mixed>|null  $params
     * @return array{k: list<float>, d: list<float>}
     */
    public function computeSeries(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $period1 = max(1, (int) $p['period1']);
        $period2 = max(1, (int) $p['period2']);
        $period3 = max(1, (int) $p['period3']);
        $n = count($rows);

        $t1 = array_fill(0, $n, 0.0);
        $t2 = array_fill(0, $n, 0.0);
        $tM1 = array_fill(0, $n, 0.0);
        $tM2 = array_fill(0, $n, 0.0);
        $k = array_fill(0, $n, 0.0);
        $kM = array_fill(0, $n, 0.0);

        for ($index = 0; $index < $n; $index++) {
            // OsEngine: if (index <= Period1 || index <= Period2 || index <= Period3) return;
            if ($index <= $period1 || $index <= $period2 || $index <= $period3) {
                continue;
            }

            $t1[$index] = $this->getT1($rows, $index, $period1);
            $t2[$index] = $this->getT2($rows, $index, $period1);
            $tM1[$index] = $this->getAverage($t1, $index, $period2);
            $tM2[$index] = $this->getAverage($t2, $index, $period2);
            // В массиве _k OsEngine хранит сырое значение (без Round) — GetAverage(%D) опирается на него.
            $k[$index] = $this->getK($tM1, $tM2, $index, $period2, $period3);
            $kM[$index] = $this->getAverage($k, $index, $period3);
        }

        // SeriesOne/Two в OsEngine = Round(..., 2) — округляем только на выходе.
        for ($index = 0; $index < $n; $index++) {
            $k[$index] = round($k[$index], 2);
            $kM[$index] = round($kM[$index], 2);
        }

        return [
            'k' => $k,
            'd' => $kM,
        ];
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  array<string,mixed>|null  $params
     * @return array{
     *   series: array{k?: list<array{time:int,value:float}>, d?: list<array{time:int,value:float}>},
     *   style: array<string,mixed>,
     *   levels: array{overbought: float, oversold: float, show: bool}
     * }
     */
    public function compute(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $raw = $this->computeSeries($rows, $p);
        $series = [];
        if ((bool) $p['show_k']) {
            $series['k'] = OsEngineCandleMath::packSeries($rows, $raw['k']);
        }
        if ((bool) $p['show_d']) {
            $series['d'] = OsEngineCandleMath::packSeries($rows, $raw['d']);
        }

        return [
            'series' => $series,
            'style' => [
                'color_k' => (string) $p['color_k'],
                'color_d' => (string) $p['color_d'],
                'line_width' => max(1, (int) $p['line_width']),
            ],
            'levels' => [
                'overbought' => (float) $p['level_overbought'],
                'oversold' => (float) $p['level_oversold'],
                'show' => (bool) $p['show_levels'],
            ],
        ];
    }

    /**
     * Порт GetAverage: SMA последних `length` элементов, заканчиваясь на index.
     * Важно: в OsEngine List.Count == index+1 (списки растут в OnProcess),
     * поэтому нельзя брать count() всего предзаполненного массива.
     *
     * @param  list<float>  $list
     */
    private function getAverage(array $list, int $index, int $length): float
    {
        $lastMoving = 0.0;
        $count = $index + 1; // как List.Count в OsEngine на момент расчёта
        for ($i = $index; $i > -1 && $i > $count - 1 - $length; $i--) {
            $lastMoving += (float) $list[$i];
        }

        return $lastMoving / $length;
    }

    /**
     * t1 = Close − lowest Low в окне Period1.
     *
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $candles
     */
    private function getT1(array $candles, int $index, int $period1): float
    {
        if ($index - $period1 + 1 <= 0) {
            return 0.0;
        }
        $low = PHP_FLOAT_MAX;
        for ($i = $index - $period1 + 1; $i <= $index; $i++) {
            $lv = (float) $candles[$i]['low'];
            if ($lv < $low) {
                $low = $lv;
            }
        }

        return (float) $candles[$index]['close'] - $low;
    }

    /**
     * t2 = highest High − lowest Low в окне Period1.
     *
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $candles
     */
    private function getT2(array $candles, int $index, int $period1): float
    {
        if ($index - $period1 + 1 <= 0) {
            return 0.0;
        }
        $low = PHP_FLOAT_MAX;
        for ($i = $index - $period1 + 1; $i <= $index; $i++) {
            $lv = (float) $candles[$i]['low'];
            if ($lv < $low) {
                $low = $lv;
            }
        }
        $hi = 0.0;
        for ($i = $index - $period1 + 1; $i <= $index; $i++) {
            $hv = (float) $candles[$i]['high'];
            if ($hv > $hi) {
                $hi = $hv;
            }
        }

        return $hi - $low;
    }

    /**
     * %K = 100 * tM1 / tM2 (после прогрева Period2+Period3+3).
     *
     * @param  list<float>  $tM1
     * @param  list<float>  $tM2
     */
    private function getK(array $tM1, array $tM2, int $index, int $period2, int $period3): float
    {
        if ($index < $period2 + $period3 + 3
            || $tM2[$index] == 0.0
            || $tM1[$index] == 0.0) {
            return 0.0;
        }

        return 100.0 * $tM1[$index] / $tM2[$index];
    }
}
