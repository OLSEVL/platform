<?php

namespace App\Services\Indicators;

/**
 * Mass Index (MI) — порт OsEngine Scripts/Mass_Index_MI.cs.
 *
 * EMA1 = EMA(High − Low, length_ema);
 * EMA2 = EMA(EMA1, length_ema);
 * MI = Σ(EMA1[i] / EMA2[i]) за period_sum+1 баров [index−period_sum .. index].
 *
 * Прогрев OsEngine: index < length_ema || index < period_sum;
 * дополнительно MI не выводится пока index < period_sum + 40 (эмуляция Count < periodSum+40).
 */
class MassIndexIndicatorService
{
    public const ID = 'os.mass_index';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'length_ema' => 9,
        'period_sum' => 25,
        'level_up' => 27.0,
        'level_down' => 26.5,
        'show_mi' => true,
        'show_level_up' => true,
        'show_level_down' => true,
        'color_mi' => '#ff0000', // Red
        'color_level' => '#add8e6', // LightBlue
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
     * @return array{mi: list<float>, level_up: list<float>, level_down: list<float>}
     */
    public function computeSeries(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $lenEma = max(1, (int) $p['length_ema']);
        $periodSum = max(1, (int) $p['period_sum']);
        $levelUp = (float) $p['level_up'];
        $levelDown = (float) $p['level_down'];
        $n = count($rows);

        $ema1 = array_fill(0, $n, 0.0);
        $ema2 = array_fill(0, $n, 0.0);
        $mi = array_fill(0, $n, 0.0);
        $up = array_fill(0, $n, $levelUp);
        $down = array_fill(0, $n, $levelDown);

        for ($index = 0; $index < $n; $index++) {
            $this->calcFirstEma($rows, $ema1, $index, $lenEma);

            // OsEngine: if (_series2.Values.Count < _periodSum.ValueInt) return;
            if ($index >= $lenEma) {
                $this->calcSecondEma($ema1, $ema2, $index, $lenEma);
            }

            if ($index < $lenEma || $index < $periodSum) {
                continue;
            }

            // OsEngine: if (_seriesMI.Values.Count < _periodSum.ValueInt + 40) return;
            if ($index < $periodSum + 40) {
                continue;
            }

            $mi[$index] = round($this->sumEmaRatio($ema1, $ema2, $index, $periodSum), 3);
        }

        return ['mi' => $mi, 'level_up' => $up, 'level_down' => $down];
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

        if ((bool) $p['show_mi']) {
            $series['mi'] = OsEngineCandleMath::packSeries($rows, $raw['mi']);
        }
        if ((bool) $p['show_level_up']) {
            $series['level_up'] = OsEngineCandleMath::packSeries($rows, $raw['level_up']);
        }
        if ((bool) $p['show_level_down']) {
            $series['level_down'] = OsEngineCandleMath::packSeries($rows, $raw['level_down']);
        }

        return [
            'series' => $series,
            'style' => [
                'color_mi' => (string) $p['color_mi'],
                'color_level' => (string) $p['color_level'],
                'line_width' => max(1, (int) $p['line_width']),
            ],
        ];
    }

    /**
     * CalcFirstEMA: EMA диапазона свечи (High − Low).
     *
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  list<float>  $series1
     */
    private function calcFirstEma(array $rows, array &$series1, int $index, int $lengthEma): void
    {
        $result = 0.0;

        if ($index === $lengthEma) {
            $lastMoving = 0.0;
            for ($i = $index - $lengthEma + 1; $i <= $index; $i++) {
                $lastMoving += (float) $rows[$i]['high'] - (float) $rows[$i]['low'];
            }
            $result = $lastMoving / $lengthEma;
        } elseif ($index > $lengthEma) {
            $a = round(2.0 / ($lengthEma + 1), 8);
            $emaLast = $series1[$index - 1];
            $p = (float) $rows[$index]['high'] - (float) $rows[$index]['low'];
            $result = $emaLast + ($a * ($p - $emaLast));
        }

        $series1[$index] = round($result, 8);
    }

    /**
     * CalcSecondEMA: EMA серии EMA1.
     *
     * @param  list<float>  $ema1
     * @param  list<float>  $series2
     */
    private function calcSecondEma(array $ema1, array &$series2, int $index, int $lengthEma): void
    {
        $result = 0.0;

        if ($index === $lengthEma) {
            $lastMoving = 0.0;
            for ($i = $index - $lengthEma + 1; $i <= $index; $i++) {
                $lastMoving += $ema1[$i];
            }
            $result = $lastMoving / $lengthEma;
        } elseif ($index > $lengthEma) {
            $a = round(2.0 / ($lengthEma + 1), 8);
            $emaLast = $series2[$index - 1];
            $p = $ema1[$index];
            $result = $emaLast + ($a * ($p - $emaLast));
        }

        $series2[$index] = round($result, 8);
    }

    /**
     * SumEma: сумма ema1/ema2 (или ema1 если ema2==0) от index−periodSum до index включительно.
     *
     * @param  list<float>  $ema1
     * @param  list<float>  $ema2
     */
    private function sumEmaRatio(array $ema1, array $ema2, int $index, int $periodSum): float
    {
        $result = 0.0;
        for ($i = $index - $periodSum; $i <= $index; $i++) {
            if ($ema2[$i] != 0.0) {
                $result += $ema1[$i] / $ema2[$i];
            } else {
                $result += $ema1[$i];
            }
        }

        return $result;
    }
}
