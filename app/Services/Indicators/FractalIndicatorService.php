<?php

namespace App\Services\Indicators;

/**
 * Fractal — порт OsEngine Indicators/Scripts/Fractal.cs.
 * Up/Down фрактал на баре index-2 при доминировании high/low над ±2 соседями.
 * Значение = цена экстремума; 0 = нет фрактала.
 */
class FractalIndicatorService
{
    public const ID = 'os.fractal';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'show_up' => true,
        'show_down' => true,
        'color_up' => '#00bfff', // DeepSkyBlue
        'color_down' => '#ff4500', // OrangeRed
    ];

    /** @return array<string, mixed> */
    public static function defaultParams(): array
    {
        return self::DEFAULT_PARAMS;
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  array<string,mixed>|null  $params
     * @return array{up: list<float>, down: list<float>}
     */
    public function computeSeries(array $rows, ?array $params = null): array
    {
        $n = count($rows);
        $up = array_fill(0, $n, 0.0);
        $down = array_fill(0, $n, 0.0);

        for ($index = 0; $index < $n; $index++) {
            if ($index - 5 <= 0) {
                continue;
            }
            // Пишем на index-2 (подтверждение двумя барами справа).
            $target = $index - 2;
            $h2 = (float) $rows[$target]['high'];
            $l2 = (float) $rows[$target]['low'];
            if (
                $h2 >= (float) $rows[$index - 1]['high']
                && $h2 >= (float) $rows[$index]['high']
                && $h2 >= (float) $rows[$index - 3]['high']
                && $h2 >= (float) $rows[$index - 4]['high']
            ) {
                $up[$target] = $h2;
            }
            if (
                $l2 <= (float) $rows[$index - 1]['low']
                && $l2 <= (float) $rows[$index]['low']
                && $l2 <= (float) $rows[$index - 3]['low']
                && $l2 <= (float) $rows[$index - 4]['low']
            ) {
                $down[$target] = $l2;
            }
        }

        return ['up' => $up, 'down' => $down];
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

        return [
            'series' => $series,
            'style' => [
                'color_up' => (string) $p['color_up'],
                'color_down' => (string) $p['color_down'],
            ],
        ];
    }
}