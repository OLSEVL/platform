<?php

namespace App\Services\Indicators;

/**
 * Stochastic Momentum Index — порт OsEngine Indicators/Scripts/StochasticMomentumIndex.cs.
 * DM = close - mid(HL); Diff = H-L; двойное SMA сглаживание; SMI = 100*dm2/diff2; signal = SMA(SMI).
 */
class SmiIndicatorService
{
    public const ID = 'os.smi';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'period1' => 13,
        'period2' => 25,
        'period3' => 2,
        'period4' => 3,
        'show_smi' => true,
        'show_signal' => true,
        'color_smi' => '#8a2be2', // BlueViolet
        'color_signal' => '#008000', // Green
        'line_width' => 1,
        'show_levels' => true,
        'level_overbought' => 40.0,
        'level_oversold' => -40.0,
    ];

    /** @return array<string, mixed> */
    public static function defaultParams(): array
    {
        return self::DEFAULT_PARAMS;
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  array<string,mixed>|null  $params
     * @return array{smi: list<float>, signal: list<float>}
     */
    public function computeSeries(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $p1 = max(1, (int) $p['period1']);
        $p2 = max(1, (int) $p['period2']);
        $p3 = max(1, (int) $p['period3']);
        $p4 = max(1, (int) $p['period4']);
        $n = count($rows);

        $dm = array_fill(0, $n, 0.0);
        $diff = array_fill(0, $n, 0.0);
        $dm1 = array_fill(0, $n, 0.0);
        $diff1 = array_fill(0, $n, 0.0);
        $dm2 = array_fill(0, $n, 0.0);
        $diff2 = array_fill(0, $n, 0.0);
        $diffS = array_fill(0, $n, 0.0);
        $diffS2 = array_fill(0, $n, 0.0);

        for ($index = 0; $index < $n; $index++) {
            if ($index <= $p1 || $index <= $p2 || $index <= $p3) {
                continue;
            }
            $dm[$index] = $this->getDm($rows, $index, $p1);
            $diff[$index] = $this->getDiff($rows, $index, $p1);
            $dm1[$index] = $this->getAverage($dm, $index, $p2);
            $diff1[$index] = $this->getAverage($diff, $index, $p2);
            $dm2[$index] = $this->getAverage($dm1, $index, $p3);
            $diff2[$index] = $this->getAverage($diff1, $index, $p3);

            if ($index < $p2 + $p3 + $p4 + 3 || $dm2[$index] == 0.0 || $diff2[$index] == 0.0) {
                $diffS[$index] = 0.0;
            } else {
                $diffS[$index] = 100.0 * $dm2[$index] / $diff2[$index];
            }
            $diffS2[$index] = $this->getAverage($diffS, $index, $p4);
        }

        $smi = [];
        $signal = [];
        for ($i = 0; $i < $n; $i++) {
            $smi[$i] = round($diffS[$i], 2);
            $signal[$i] = round($diffS2[$i], 2);
        }

        return ['smi' => $smi, 'signal' => $signal];
    }

    /**
     * OsEngine GetAverage: sum last `length` values / length
     * (цикл i > list.Count-1-length при Count==index+1 → i > index-length).
     *
     * @param  list<float>  $list
     */
    private function getAverage(array $list, int $index, int $length): float
    {
        $sum = 0.0;
        $count = count($list);
        for ($i = $index; $i > -1 && $i > $count - 1 - $length; $i--) {
            $sum += $list[$i];
        }

        return $sum / $length;
    }

    /** @param list<array{high:float,low:float,close:float}> $rows */
    private function getDm(array $rows, int $index, int $period1): float
    {
        if ($index - $period1 + 1 <= 0) {
            return 0.0;
        }
        $low = PHP_FLOAT_MAX;
        $hi = 0.0;
        for ($i = $index - $period1 + 1; $i <= $index; $i++) {
            $l = (float) $rows[$i]['low'];
            $h = (float) $rows[$i]['high'];
            if ($l < $low) {
                $low = $l;
            }
            if ($h > $hi) {
                $hi = $h;
            }
        }

        return (float) $rows[$index]['close'] - ($hi + $low) / 2.0;
    }

    /** @param list<array{high:float,low:float}> $rows */
    private function getDiff(array $rows, int $index, int $period1): float
    {
        if ($index - $period1 + 1 <= 0) {
            return 0.0;
        }
        $low = PHP_FLOAT_MAX;
        $hi = 0.0;
        for ($i = $index - $period1 + 1; $i <= $index; $i++) {
            $l = (float) $rows[$i]['low'];
            $h = (float) $rows[$i]['high'];
            if ($l < $low) {
                $low = $l;
            }
            if ($h > $hi) {
                $hi = $h;
            }
        }

        return $hi - $low;
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  array<string,mixed>|null  $params
     * @return array{series: array<string,mixed>, style: array<string,mixed>, levels: array<string,mixed>}
     */
    public function compute(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $raw = $this->computeSeries($rows, $p);
        $series = [];
        if ((bool) $p['show_smi']) {
            $series['smi'] = OsEngineCandleMath::packSeries($rows, $raw['smi'], false);
        }
        if ((bool) $p['show_signal']) {
            $series['signal'] = OsEngineCandleMath::packSeries($rows, $raw['signal'], false);
        }
        $levels = ['show' => false];
        if ((bool) $p['show_levels']) {
            $levels = [
                'show' => true,
                'overbought' => (float) $p['level_overbought'],
                'oversold' => (float) $p['level_oversold'],
                'color_overbought' => 'rgba(161,161,170,0.55)',
                'color_oversold' => 'rgba(161,161,170,0.55)',
            ];
        }

        return [
            'series' => $series,
            'style' => [
                'color_smi' => (string) $p['color_smi'],
                'color_signal' => (string) $p['color_signal'],
                'line_width' => max(1, (int) $p['line_width']),
            ],
            'levels' => $levels,
        ];
    }
}