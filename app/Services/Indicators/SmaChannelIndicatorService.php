<?php

namespace App\Services\Indicators;

/**
 * SmaChannel — порт OsEngine Indicators/Scripts/SmaChannel.cs.
 * Up/Down = SMA ± SMA * deviation/100.
 */
class SmaChannelIndicatorService
{
    public const ID = 'os.sma_channel';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'length' => 21,
        'deviation' => 2.0,
        'show_up' => true,
        'show_mid' => false,
        'show_down' => true,
        'color_up' => '#008000',
        'color_mid' => '#add8e6', // LightBlue
        'color_down' => '#ff0000',
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
     * @return array{up: list<float>, mid: list<float>, down: list<float>}
     */
    public function computeSeries(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $length = max(1, (int) $p['length']);
        $dev = (float) $p['deviation'];
        $n = count($rows);
        $up = array_fill(0, $n, 0.0);
        $mid = array_fill(0, $n, 0.0);
        $down = array_fill(0, $n, 0.0);

        $sma = (new SmaIndicatorService)->computeSeries($rows, [
            'length' => $length,
            'candle_point' => 'Close',
        ]);

        for ($index = 0; $index < $n; $index++) {
            // OsEngine: if (index <= length) return;
            if ($index <= $length) {
                continue;
            }
            $valueSma = (float) $sma[$index];
            $delta = round($valueSma * $dev / 100.0, 6);
            $mid[$index] = $valueSma;
            $up[$index] = $valueSma + $delta;
            $down[$index] = $valueSma - $delta;
        }

        return ['up' => $up, 'mid' => $mid, 'down' => $down];
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
        if ((bool) $p['show_mid']) {
            $series['mid'] = OsEngineCandleMath::packSeries($rows, $raw['mid']);
        }
        if ((bool) $p['show_down']) {
            $series['down'] = OsEngineCandleMath::packSeries($rows, $raw['down']);
        }

        return [
            'series' => $series,
            'style' => [
                'color_up' => (string) $p['color_up'],
                'color_mid' => (string) $p['color_mid'],
                'color_down' => (string) $p['color_down'],
                'line_width' => max(1, (int) $p['line_width']),
            ],
        ];
    }
}