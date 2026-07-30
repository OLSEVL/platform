<?php

namespace App\Services\Indicators;

/**
 * DeMarker (DeM) — порт OsEngine Indicators/Scripts/DeMarker_DeM.cs.
 * DeMax/DeMin по High/Low vs prev; SMA(DeMax)/(SMA(DeMax)+SMA(DeMin)).
 */
class DemarkerIndicatorService
{
    public const ID = 'os.demarker';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'period' => 14,
        'show_line' => true,
        'color' => '#00ffff',
        'line_width' => 1,
        'show_levels' => true,
        'level_overbought' => 0.7,
        'level_oversold' => 0.3,
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
        $period = max(1, (int) $p['period']);
        $n = count($rows);
        $values = array_fill(0, $n, 0.0);
        $deMax = array_fill(0, $n, 0.0);
        $deMin = array_fill(0, $n, 0.0);

        for ($index = 0; $index < $n; $index++) {
            if ($index == 0) {
                continue;
            }
            $lastHigh = (float) $rows[$index]['high'];
            $prevHigh = (float) $rows[$index - 1]['high'];
            $lastLow = (float) $rows[$index]['low'];
            $prevLow = (float) $rows[$index - 1]['low'];

            $deMax[$index] = $lastHigh > $prevHigh ? ($lastHigh - $prevHigh) : 0.0;
            $deMin[$index] = $lastLow < $prevLow ? ($prevLow - $lastLow) : 0.0;

            if ($index < $period) {
                continue;
            }

            $smaMax = $this->smaDem($deMax, $index, $period);
            $smaMin = $this->smaDem($deMin, $index, $period);
            if ($smaMax + $smaMin == 0.0) {
                continue;
            }
            $values[$index] = $smaMax / ($smaMax + $smaMin);
        }

        return $values;
    }

    /** @param list<float> $list */
    private function smaDem(array $list, int $index, int $period): float
    {
        $sum = 0.0;
        $len = 0;
        for ($i = $index; $i >= 0 && $i > $index - $period; $i--) {
            $len++;
            $sum += $list[$i];
        }

        return $len === 0 ? 0.0 : $sum / $len;
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
        if ((bool) $p['show_line']) {
            $series['dem'] = OsEngineCandleMath::packSeries($rows, $raw);
        }
        $levels = ['show' => false];
        if ((bool) $p['show_levels']) {
            $levels = [
                'show' => true,
                'overbought' => (float) $p['level_overbought'],
                'oversold' => (float) $p['level_oversold'],
                'color_overbought' => 'rgba(250,204,21,0.7)',
                'color_oversold' => 'rgba(250,204,21,0.7)',
            ];
        }

        return [
            'series' => $series,
            'style' => [
                'color' => (string) $p['color'],
                'line_width' => max(1, (int) $p['line_width']),
            ],
            'levels' => $levels,
        ];
    }
}