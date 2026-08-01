<?php

namespace App\Services\Indicators;

/**
 * QStick — порт OsEngine Scripts/QStick.cs.
 *
 * Сглаживает (Close − Open) выбранной MA: SMA или EMA (по умолчанию SMA).
 * EMA: seed SMA на index==length, далее a = round(2/(length+1), 8).
 */
class QstickIndicatorService
{
    public const ID = 'os.qstick';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'length' => 14,
        'type_ma' => 'SMA',
        'show_line' => true,
        'color' => '#ff0000', // Red
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
        $typeMa = (string) $p['type_ma'];
        $n = count($rows);
        $values = array_fill(0, $n, 0.0);

        if ($typeMa === 'EMA') {
            for ($index = 0; $index < $n; $index++) {
                $result = 0.0;
                if ($index === $length) {
                    // Seed: SMA (close−open) за length баров.
                    $lastMoving = 0.0;
                    for ($i = $index - $length + 1; $i <= $index; $i++) {
                        $lastMoving += (float) $rows[$i]['close'] - (float) $rows[$i]['open'];
                    }
                    $result = $lastMoving / $length;
                } elseif ($index > $length) {
                    $a = round(2.0 / ($length + 1), 8);
                    $emaLast = $values[$index - 1];
                    $diff = (float) $rows[$index]['close'] - (float) $rows[$index]['open'];
                    $result = $emaLast + ($a * ($diff - $emaLast));
                }
                $values[$index] = round($result, 8);
            }
        } else {
            // SMA: среднее (close−open) за length баров.
            for ($index = 0; $index < $n; $index++) {
                if ($length > $index) {
                    continue;
                }
                $sumClose = 0.0;
                $sumOpen = 0.0;
                for ($i = $index - $length + 1; $i <= $index; $i++) {
                    $sumClose += (float) $rows[$i]['close'];
                    $sumOpen += (float) $rows[$i]['open'];
                }
                $values[$index] = ($sumClose - $sumOpen) / $length;
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
            $series['qstick'] = OsEngineCandleMath::packSeries($rows, $raw, false);
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
