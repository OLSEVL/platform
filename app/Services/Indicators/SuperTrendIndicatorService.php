<?php

namespace App\Services\Indicators;

/**
 * SuperTrend — порт OsEngine Indicators/Scripts/SuperTrend_indicator.cs.
 * Center = lower при бычьем направлении, upper при медвежьем; ATR × deviation.
 */
class SuperTrendIndicatorService
{
    public const ID = 'os.supertrend';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'length' => 15,
        'deviation' => 1.0,
        'candle_point' => 'Median', // Median | Typical
        'use_wicks' => true,
        'show_center' => true, // OsEngine: Center IsPainted=true
        'show_upper' => false,
        'show_lower' => false,
        'color_center' => '#ffd700', // Gold
        'color_upper' => '#ff4500', // OrangeRed
        'color_lower' => '#00ffff', // Aqua
        'line_width' => 2,
    ];

    /** @return array<string, mixed> */
    public static function defaultParams(): array
    {
        return self::DEFAULT_PARAMS;
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  array<string,mixed>|null  $params
     * @return array{center: list<float>, upper: list<float>, lower: list<float>, direction: list<int>}
     */
    public function computeSeries(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $length = max(1, (int) $p['length']);
        $deviation = (float) $p['deviation'];
        $point = (string) $p['candle_point'];
        if (! in_array($point, ['Median', 'Typical'], true)) {
            $point = 'Median';
        }
        $useWicks = (bool) $p['use_wicks'];
        $n = count($rows);

        $lower = array_fill(0, $n, 0.0);
        $upper = array_fill(0, $n, 0.0);
        $center = array_fill(0, $n, 0.0);
        $direction = array_fill(0, $n, 0);

        $atr = (new AtrIndicatorService)->computeSeries($rows, [
            'length' => $length,
            'series_type' => 'Absolute',
        ]);

        $dir = 0;
        for ($index = 0; $index < $n; $index++) {
            if ($index < $length) {
                continue;
            }

            $atrDev = $deviation * $atr[$index];
            $highPrice = $useWicks ? (float) $rows[$index]['high'] : (float) $rows[$index]['close'];
            $highPricePrev = $useWicks ? (float) $rows[$index - 1]['high'] : (float) $rows[$index - 1]['close'];
            $lowPrice = $useWicks ? (float) $rows[$index]['low'] : (float) $rows[$index]['close'];
            $lowPricePrev = $useWicks ? (float) $rows[$index - 1]['low'] : (float) $rows[$index - 1]['close'];

            $o = (float) $rows[$index]['open'];
            $h = (float) $rows[$index]['high'];
            $l = (float) $rows[$index]['low'];
            $c = (float) $rows[$index]['close'];
            $doji4 = ($o === $c && $o === $l && $o === $h);

            $src = OsEngineCandleMath::candlePoint($rows[$index], $point);
            $lower[$index] = $src - $atrDev;

            if ($lower[$index] > 0) {
                if ($doji4) {
                    $lower[$index] = $lower[$index - 1];
                } else {
                    $lower[$index] = ($lowPricePrev > $lower[$index - 1])
                        ? max($lower[$index], $lower[$index - 1])
                        : $lower[$index];
                }
            } else {
                $lower[$index] = $lower[$index - 1];
            }

            $upper[$index] = $src + $atrDev;
            if ($upper[$index] > 0) {
                if ($doji4) {
                    $upper[$index] = $upper[$index - 1];
                } else {
                    $upper[$index] = ($highPricePrev < $upper[$index - 1])
                        ? min($upper[$index], $upper[$index - 1])
                        : $upper[$index];
                }
            } else {
                $upper[$index] = $upper[$index - 1];
            }

            // Направление: пробой prev upper → up; prev lower → down; иначе hold.
            if ($highPrice > $upper[$index - 1]) {
                $dir = 1;
            } elseif ($lowPrice < $lower[$index - 1]) {
                $dir = -1;
            }
            $direction[$index] = $dir;
            $center[$index] = $dir === 1 ? $lower[$index] : $upper[$index];
        }

        return [
            'center' => $center,
            'upper' => $upper,
            'lower' => $lower,
            'direction' => $direction,
        ];
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  array<string,mixed>|null  $params
     * @return array{
     *   series: array{center?: list<array{time:int,value:float}>, upper?: list<array{time:int,value:float}>, lower?: list<array{time:int,value:float}>},
     *   style: array<string,mixed>
     * }
     */
    public function compute(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $raw = $this->computeSeries($rows, $p);
        $series = [];
        if ((bool) $p['show_center']) {
            $series['center'] = OsEngineCandleMath::packSeries($rows, $raw['center']);
        }
        if ((bool) $p['show_upper']) {
            $series['upper'] = OsEngineCandleMath::packSeries($rows, $raw['upper']);
        }
        if ((bool) $p['show_lower']) {
            $series['lower'] = OsEngineCandleMath::packSeries($rows, $raw['lower']);
        }

        return [
            'series' => $series,
            'style' => [
                'color_center' => (string) $p['color_center'],
                'color_upper' => (string) $p['color_upper'],
                'color_lower' => (string) $p['color_lower'],
                'line_width' => max(1, (int) $p['line_width']),
            ],
        ];
    }
}
