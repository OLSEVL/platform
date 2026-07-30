<?php

namespace App\Services\Indicators;

/**
 * Aroon — порт OsEngine Indicators/Scripts/Aroon.cs (CalcAroon).
 *
 * Up/Down по Close в окне [index-period .. index-1]; если last close обновил hi/lo → 100.
 * Уровни 70/30 на pane (как horizontal lines в OsEngine).
 */
class AroonIndicatorService
{
    public const ID = 'os.aroon';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'period' => 14,
        'show_up' => true,
        'show_down' => true,
        'color_up' => '#008000', // Green
        'color_down' => '#ff0000', // Red
        'line_width' => 1,
        'show_levels' => true,
        'level_up' => 70.0,
        'level_down' => 30.0,
    ];

    /** @return array<string, mixed> */
    public static function defaultParams(): array
    {
        return self::DEFAULT_PARAMS;
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  array<string,mixed>|null  $params
     * @return array{up: list<float>, down: list<float>, osc: list<float>}
     */
    public function computeSeries(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $period = max(1, (int) $p['period']);
        $n = count($rows);

        $up = array_fill(0, $n, 0.0);
        $down = array_fill(0, $n, 0.0);
        $osc = array_fill(0, $n, 0.0);

        for ($index = 0; $index < $n; $index++) {
            // OsEngine: if (period >= index) return;
            if ($period >= $index) {
                continue;
            }

            [$aroonUp, $aroonDown] = $this->calcAroon($rows, $index, $period);
            $up[$index] = $aroonUp;
            $down[$index] = $aroonDown;
            $osc[$index] = abs($aroonUp - $aroonDown);
        }

        return ['up' => $up, 'down' => $down, 'osc' => $osc];
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  array<string,mixed>|null  $params
     * @return array{
     *   series: array{up?: list<array{time:int,value:float}>, down?: list<array{time:int,value:float}>},
     *   style: array<string,mixed>,
     *   levels: array<string,mixed>
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

        $levels = ['show' => false];
        if ((bool) $p['show_levels']) {
            $levels = [
                'show' => true,
                'overbought' => (float) $p['level_up'],
                'oversold' => (float) $p['level_down'],
                'color_overbought' => 'rgba(161,161,170,0.55)',
                'color_oversold' => 'rgba(161,161,170,0.55)',
            ];
        }

        return [
            'series' => $series,
            'style' => [
                'color_up' => (string) $p['color_up'],
                'color_down' => (string) $p['color_down'],
                'line_width' => max(1, (int) $p['line_width']),
            ],
            'levels' => $levels,
        ];
    }

    /**
     * CalcAroon — окно Close [index-period .. index-1], затем проверка last close.
     *
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @return array{0: float, 1: float}
     */
    private function calcAroon(array $rows, int $index, int $period): array
    {
        $lastPrice = (float) $rows[$index]['close'];
        $max = -PHP_FLOAT_MAX;
        $min = PHP_FLOAT_MAX;
        $numBarUp = 0;
        $numBarDown = 0;
        $aroonUp = 0.0;
        $aroonDown = 0.0;

        for ($i = $index - $period; $i < $index; $i++) {
            $c = (float) $rows[$i]['close'];
            if ($max < $c) {
                $max = $c;
                $numBarUp = $index - $i;
                $aroonUp = 100.0 * ($period - $numBarUp) / $period;
            }
            if ($min > $c) {
                $min = $c;
                $numBarDown = $index - $i;
                $aroonDown = 100.0 * ($period - $numBarDown) / $period;
            }
        }

        if ($max < $lastPrice) {
            $aroonUp = 100.0;
        }
        if ($min > $lastPrice) {
            $aroonDown = 100.0;
        }

        return [$aroonUp, $aroonDown];
    }
}
