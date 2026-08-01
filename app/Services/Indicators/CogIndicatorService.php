<?php

namespace App\Services\Indicators;

/**
 * COG (Centre of Gravity Oscillator) — порт OsEngine Scripts/COG_CentreOfGravity_Oscr.cs.
 *
 * COG = Σ(price × (j+1)) / Σ(price) − (length+1)/2,
 * где j растёт от 0 для баров [index−length .. index−1].
 * prev_cog — значение COG предыдущего бара.
 */
class CogIndicatorService
{
    public const ID = 'os.cog';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'length' => 14,
        'candle_point' => 'Close',
        'show_cog' => true,
        'show_prev_cog' => true,
        'color_cog' => '#ff0000', // Red
        'color_prev_cog' => '#00ffff', // Aqua
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
     * @return array{cog: list<float>, prev_cog: list<float>}
     */
    public function computeSeries(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $length = max(1, (int) $p['length']);
        $point = (string) $p['candle_point'];
        if (! in_array($point, OsEngineCandleMath::CANDLE_POINTS, true)) {
            $point = 'Close';
        }
        $n = count($rows);

        $cog = array_fill(0, $n, 0.0);
        $prevCog = array_fill(0, $n, 0.0);

        for ($index = 0; $index < $n; $index++) {
            if ($index < $length) {
                continue;
            }

            $temp = 0.0;
            $temp2 = 0.0;
            for ($i = $index - $length, $j = 0; $i < $index; $i++, $j++) {
                $price = OsEngineCandleMath::candlePoint($rows[$i], $point);
                $temp += $price * ($j + 1);
                $temp2 += $price;
            }

            if ($temp2 == 0.0) {
                continue;
            }

            $cogVal = $temp / $temp2 - ($length + 1) / 2.0;
            $cog[$index] = round($cogVal, 3);
            $prevCog[$index] = round($index > 0 ? $cog[$index - 1] : 0.0, 3);
        }

        return ['cog' => $cog, 'prev_cog' => $prevCog];
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

        if ((bool) $p['show_cog']) {
            $series['cog'] = OsEngineCandleMath::packSeries($rows, $raw['cog'], false);
        }
        if ((bool) $p['show_prev_cog']) {
            $series['prev_cog'] = OsEngineCandleMath::packSeries($rows, $raw['prev_cog'], false);
        }

        return [
            'series' => $series,
            'style' => [
                'color_cog' => (string) $p['color_cog'],
                'color_prev_cog' => (string) $p['color_prev_cog'],
                'line_width' => max(1, (int) $p['line_width']),
            ],
        ];
    }
}
