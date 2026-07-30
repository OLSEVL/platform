<?php

namespace App\Services\Indicators;

/**
 * KalmanFilter — порт OsEngine Indicators/Scripts/KalmanFilter.cs.
 * Error = Kalman[i-1] + Distance*ShK; Velocity += Distance*K/100; Kalman = Error+Velocity.
 */
class KalmanIndicatorService
{
    public const ID = 'os.kalman';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'sharpness' => 1.0,
        'k' => 1.0,
        'show_line' => true,
        'color' => '#1e90ff',
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
        $sharpness = (float) $p['sharpness'];
        $k = (float) $p['k'];
        $n = count($rows);
        $values = array_fill(0, $n, 0.0);
        $velocity = array_fill(0, $n, 0.0);

        for ($index = 0; $index < $n; $index++) {
            if ($index == 0) {
                continue;
            }
            $shk = sqrt($sharpness * $k / 100.0);
            $distance = (float) $rows[$index]['close'] - $values[$index - 1];
            $velocity[$index] = $velocity[$index - 1] + $distance * $k / 100.0;
            $error = $values[$index - 1] + $distance * $shk;
            $values[$index] = $error + $velocity[$index];
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
            // index 0 = 0 (прогрев); дальше цена фильтра.
            $withNull = $raw;
            $withNull[0] = null;
            $series['kalman'] = OsEngineCandleMath::packSeries($rows, $withNull, false);
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