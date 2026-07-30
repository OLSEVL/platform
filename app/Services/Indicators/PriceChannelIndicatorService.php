<?php

namespace App\Services\Indicators;

/**
 * PriceChannel (Donchian-like) — порт OsEngine Indicators/Scripts/PriceChannel.cs.
 *
 * Up = max high за lengthUp; Down = min low за lengthDown.
 * Прогрев: index - length > 0 (строго больше).
 */
class PriceChannelIndicatorService
{
    public const ID = 'os.price_channel';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'length_up' => 21,
        'length_down' => 21,
        'show_up' => true,
        'show_down' => true,
        'color_up' => '#00ffff', // Aqua
        'color_down' => '#8a2be2', // BlueViolet
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
     * @return array{up: list<float>, down: list<float>}
     */
    public function computeSeries(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $lengthUp = max(1, (int) $p['length_up']);
        $lengthDown = max(1, (int) $p['length_down']);
        $n = count($rows);

        $up = array_fill(0, $n, 0.0);
        $down = array_fill(0, $n, 0.0);

        for ($index = 0; $index < $n; $index++) {
            if ($index - $lengthUp > 0) {
                $upLine = 0.0;
                for ($i = $index; $i > -1 && $i > $index - $lengthUp; $i--) {
                    $h = (float) $rows[$i]['high'];
                    if ($upLine < $h) {
                        $upLine = $h;
                    }
                }
                $up[$index] = $upLine;
            }

            if ($index - $lengthDown > 0) {
                $downLine = PHP_FLOAT_MAX;
                for ($i = $index; $i > -1 && $i > $index - $lengthDown; $i--) {
                    $l = (float) $rows[$i]['low'];
                    if ($downLine > $l) {
                        $downLine = $l;
                    }
                }
                $down[$index] = $downLine;
            }
        }

        return ['up' => $up, 'down' => $down];
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  array<string,mixed>|null  $params
     * @return array{
     *   series: array{up?: list<array{time:int,value:float}>, down?: list<array{time:int,value:float}>},
     *   style: array<string,mixed>
     * }
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
                'line_width' => max(1, (int) $p['line_width']),
            ],
        ];
    }
}
