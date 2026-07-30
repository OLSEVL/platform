<?php

namespace App\Services\Indicators;

/**
 * IvashovRange — порт OsEngine Indicators/Scripts/IvashovRange.cs.
 * range = |SMA(close, lengthMa) - close|; value = SMA(range, lengthAvg).
 */
class IvashovRangeIndicatorService
{
    public const ID = 'os.ivashov';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'length_ma' => 100,
        'length_avg' => 100,
        'show_line' => true,
        'color' => '#0000ff',
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
        $lengthMa = max(1, (int) $p['length_ma']);
        $lengthAvg = max(1, (int) $p['length_avg']);
        $n = count($rows);
        $values = array_fill(0, $n, 0.0);
        $moving = array_fill(0, $n, 0.0);
        $range = array_fill(0, $n, 0.0);
        $average = array_fill(0, $n, 0.0);

        for ($index = 0; $index < $n; $index++) {
            if ($lengthMa > $index) {
                $moving[$index] = 0.0;
            } else {
                // OsEngine ScriptSpells.Summ(start, end): цикл с i = startIndex+1 .. endIndex включительно.
                // Summ(index-lengthMa, index, Close) → сумма lengthMa баров / lengthMa.
                $sum = 0.0;
                $start = $index - $lengthMa;
                for ($i = $start + 1; $i <= $index; $i++) {
                    $sum += (float) $rows[$i]['close'];
                }
                $moving[$index] = $sum / $lengthMa;
            }

            $range[$index] = $moving[$index] == 0.0
                ? 0.0
                : abs($moving[$index] - (float) $rows[$index]['close']);

            if ($index >= $lengthAvg) {
                $var = 0.0;
                for ($i = $index - $lengthAvg + 1; $i <= $index; $i++) {
                    $var += $range[$i];
                }
                $average[$index] = round($var / $lengthAvg, 4);
            }

            if ($index < $lengthAvg || $index < $lengthMa || $moving[$index] == 0.0) {
                $values[$index] = 0.0;
            } else {
                $values[$index] = $average[$index];
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
            $series['ivashov'] = OsEngineCandleMath::packSeries($rows, $raw);
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