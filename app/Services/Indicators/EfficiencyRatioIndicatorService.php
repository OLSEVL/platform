<?php

namespace App\Services\Indicators;

/**
 * Efficiency Ratio (ER) — порт OsEngine Scripts/EfficiencyRatio.cs.
 *
 * ER = |Close[index] − Close[index−length]| / Σ|Close[i] − Close[i−1]|,
 * где сумма по i = 0..length (Length+1 слагаемых).
 */
class EfficiencyRatioIndicatorService
{
    public const ID = 'os.efficiency_ratio';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'length' => 14,
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
        $length = max(1, (int) $p['length']);
        $n = count($rows);
        // null = прогрев (на график не отдаём); 0.0 после прогрева — валидный ER (нет нетто-движения).
        $values = array_fill(0, $n, null);

        for ($index = 0; $index < $n; $index++) {
            // OsEngine: if (index - Length > 0) — строго больше.
            if ($index - $length <= 0) {
                continue;
            }

            $eR1 = abs((float) $rows[$index]['close'] - (float) $rows[$index - $length]['close']);
            $eR2 = 0.0;

            // Сумма |ΔClose| по Length+1 шагам (i = 0..Length), как в EfficiencyRatio.cs.
            for ($i = 0; $i <= $length; $i++) {
                $eR2 += abs(
                    (float) $rows[$index - $i]['close'] - (float) $rows[$index - $i - 1]['close']
                );
            }

            if ($eR2 != 0.0) {
                $values[$index] = round($eR1 / $eR2, 4);
            } else {
                $values[$index] = 0.0;
            }
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

        if ((bool) $p['show_line']) {
            // skipZeros=false: ER=0 после прогрева — осмысленное значение (шум / боковик).
            $series['er'] = OsEngineCandleMath::packSeries($rows, $raw, false);
        }

        return [
            'series' => $series,
            'style' => [
                'color' => (string) $p['color'],
                'line_width' => max(1, (int) $p['line_width']),
            ],
        ];
    }
}
