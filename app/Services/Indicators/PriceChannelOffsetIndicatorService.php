<?php

namespace App\Services\Indicators;

/**
 * PriceChannelOffset — порт OsEngine Indicators/Scripts/PriceChannelOffset.cs.
 *
 * Внешние границы = max High / min Low за Length;
 * внутренние = внешние ± Offset% от высоты канала.
 */
class PriceChannelOffsetIndicatorService
{
    public const ID = 'os.price_channel_offset';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'length' => 50,
        'offset_percent' => 30.0,
        'show_up' => true,
        'show_down' => true,
        'show_up_border' => true,
        'show_down_border' => true,
        'color_up' => '#00ffff', // Aqua
        'color_down' => '#8a2be2', // BlueViolet
        'color_up_border' => '#f5f5f5', // WhiteSmoke
        'color_down_border' => '#f5f5f5',
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
     * @return array{up: list<float>, down: list<float>, up_border: list<float>, down_border: list<float>}
     */
    public function computeSeries(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $length = max(1, (int) $p['length']);
        $offsetPct = (float) $p['offset_percent'];
        $n = count($rows);

        $up = array_fill(0, $n, 0.0);
        $down = array_fill(0, $n, 0.0);
        $upBorder = array_fill(0, $n, 0.0);
        $downBorder = array_fill(0, $n, 0.0);

        for ($index = 0; $index < $n; $index++) {
            $upLine = 0.0;
            // OsEngine: if (index - length > 0) — строго больше.
            if ($index - $length > 0) {
                for ($i = $index; $i > -1 && $i > $index - $length; $i--) {
                    $h = (float) $rows[$i]['high'];
                    if ($upLine < $h) {
                        $upLine = $h;
                    }
                }
            }

            $downLine = 0.0;
            if ($index - $length > 0) {
                $downLine = PHP_FLOAT_MAX;
                for ($i = $index; $i > -1 && $i > $index - $length; $i--) {
                    $l = (float) $rows[$i]['low'];
                    if ($downLine > $l) {
                        $downLine = $l;
                    }
                }
            }

            $upBorder[$index] = $upLine;
            $downBorder[$index] = $downLine === PHP_FLOAT_MAX ? 0.0 : $downLine;

            // Внутренний отступ = Offset% от высоты внешнего канала.
            $channelHeight = $upBorder[$index] - $downBorder[$index];
            $offset = $channelHeight / 100.0 * $offsetPct;

            $up[$index] = $upBorder[$index] - $offset;
            $down[$index] = $downBorder[$index] + $offset;
        }

        return [
            'up' => $up,
            'down' => $down,
            'up_border' => $upBorder,
            'down_border' => $downBorder,
        ];
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
        if ((bool) $p['show_up_border']) {
            $series['up_border'] = OsEngineCandleMath::packSeries($rows, $raw['up_border']);
        }
        if ((bool) $p['show_down_border']) {
            $series['down_border'] = OsEngineCandleMath::packSeries($rows, $raw['down_border']);
        }

        return [
            'series' => $series,
            'style' => [
                'color_up' => (string) $p['color_up'],
                'color_down' => (string) $p['color_down'],
                'color_up_border' => (string) $p['color_up_border'],
                'color_down_border' => (string) $p['color_down_border'],
                'line_width' => max(1, (int) $p['line_width']),
            ],
        ];
    }
}
