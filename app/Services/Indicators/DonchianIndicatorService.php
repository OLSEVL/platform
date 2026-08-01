<?php

namespace App\Services\Indicators;

/**
 * Donchian Channel — порт OsEngine CandleChart/Indicators/DonchianChannel.cs.
 *
 * Верхняя линия = max(high) за окно Length+1 баров (i от Length до 0 включительно).
 * Нижняя = min(low) за то же окно. Средняя = (up + down) / 2.
 *
 * В OsEngine mid считается как valueDown + Round((up−down)/2, 0) — целочисленное
 * округление ломает OTC-инструменты с дробными котировками; здесь используем точное
 * арифметическое среднее без Round(..., 0).
 */
class DonchianIndicatorService
{
    public const ID = 'os.donchian';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'length' => 20,
        'show_up' => true,
        'show_mid' => true,
        'show_down' => true,
        'color_up' => '#1e90ff', // DodgerBlue
        'color_mid' => '#8b0000', // DarkRed
        'color_down' => '#1e90ff', // DodgerBlue
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
     * @return array{up: list<float>, mid: list<float>, down: list<float>}
     */
    public function computeSeries(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $length = max(1, (int) $p['length']);
        $n = count($rows);

        $up = array_fill(0, $n, 0.0);
        $mid = array_fill(0, $n, 0.0);
        $down = array_fill(0, $n, 0.0);

        for ($index = 0; $index < $n; $index++) {
            // OsEngine: lastCandleIndex - Length < 0 → нули (прогрев).
            if ($index - $length < 0) {
                continue;
            }

            $valueUp = 0.0;
            $valueDown = PHP_FLOAT_MAX;

            // Окно Length+1 свечей: i от Length до 0, candleIndex = index - i.
            for ($i = $length; $i >= 0; $i--) {
                $candleIndex = $index - $i;
                $h = (float) $rows[$candleIndex]['high'];
                $l = (float) $rows[$candleIndex]['low'];

                if ($h > $valueUp) {
                    $valueUp = $h;
                }
                if ($l < $valueDown) {
                    $valueDown = $l;
                }
            }

            $up[$index] = $valueUp;
            $down[$index] = $valueDown;
            // Точное среднее без OsEngine Round(..., 0) — см. комментарий класса.
            $mid[$index] = ($valueUp + $valueDown) / 2.0;
        }

        return ['up' => $up, 'mid' => $mid, 'down' => $down];
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  array<string,mixed>|null  $params
     * @return array{
     *   series: array<string, list<array{time:int,value:float}>>,
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
        if ((bool) $p['show_mid']) {
            $series['mid'] = OsEngineCandleMath::packSeries($rows, $raw['mid']);
        }
        if ((bool) $p['show_down']) {
            $series['down'] = OsEngineCandleMath::packSeries($rows, $raw['down']);
        }

        return [
            'series' => $series,
            'style' => [
                'key' => 'donchian',
                'color_up' => (string) $p['color_up'],
                'color_mid' => (string) $p['color_mid'],
                'color_down' => (string) $p['color_down'],
                'line_width' => max(1, (int) $p['line_width']),
            ],
        ];
    }
}
