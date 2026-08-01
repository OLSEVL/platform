<?php

namespace App\Services\Indicators;

/**
 * PriceChannelAdaptive — порт OsEngine Indicators/Scripts/PriceChannelAdaptive.cs.
 *
 * Длина канала = max(trunc(Ratio / ADX), 1): при сильном ADX канал короче,
 * при слабом — длиннее. Up/Down = Highest/Lowest за адаптивное окно (ScriptSpells).
 */
class PriceChannelAdaptiveIndicatorService
{
    public const ID = 'os.price_channel_adaptive';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'adx_period' => 10,
        'ratio' => 100,
        'show_up' => true,
        'show_down' => true,
        'show_length' => false, // Channel adaptive length — IsPainted=false в OsEngine
        'color_up' => '#1e90ff', // DodgerBlue
        'color_down' => '#ff0000', // Red
        'color_length' => '#f5f5f5', // WhiteSmoke
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
     * @return array{up: list<float>, down: list<float>, length: list<float>}
     */
    public function computeSeries(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $lengthAdx = max(1, (int) $p['adx_period']);
        $ratio = max(1, (int) $p['ratio']);
        $n = count($rows);

        $up = array_fill(0, $n, 0.0);
        $down = array_fill(0, $n, 0.0);
        $adaptiveLength = array_fill(0, $n, 0.0);

        // Вложенный ADX с периодом Adx Period.
        $adxSeries = (new AdxIndicatorService)->computeSeries($rows, [
            'length' => $lengthAdx,
        ]);
        $adx = $adxSeries['adx'];

        for ($index = 0; $index < $n; $index++) {
            // OsEngine: if (index - _lengthAdx * 2 + 2 < 0) return;
            if ($index - $lengthAdx * 2 + 2 < 0) {
                continue;
            }

            $adxOnIndex = $adx[$index];
            // DataSeries.Last во время OnProcess ≈ текущее заполненное значение;
            // при нулевом ADX канал не считаем.
            if ($adxOnIndex == 0.0) {
                continue;
            }

            // Адаптивная длина: Ratio / ADX, минимум 1 бар.
            $x = (int) max((int) floor($ratio / $adxOnIndex), 1);
            $adaptiveLength[$index] = (float) $x;

            // ScriptSpells.Highest/Lowest(start, end): цикл i = start+1 .. end.
            $up[$index] = $this->highest($rows, $index - $x, $index);
            $down[$index] = $this->lowest($rows, $index - $x, $index);
        }

        return ['up' => $up, 'down' => $down, 'length' => $adaptiveLength];
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
        if ((bool) $p['show_length']) {
            $series['length'] = OsEngineCandleMath::packSeries($rows, $raw['length']);
        }

        return [
            'series' => $series,
            'style' => [
                'color_up' => (string) $p['color_up'],
                'color_down' => (string) $p['color_down'],
                'color_length' => (string) $p['color_length'],
                'line_width' => max(1, (int) $p['line_width']),
            ],
        ];
    }

    /**
     * Порт ScriptSpells.Highest: max High на [startIndex+1 .. endIndex].
     *
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     */
    private function highest(array $rows, int $startIndex, int $endIndex): float
    {
        if ($endIndex < $startIndex) {
            $tmp = $endIndex;
            $endIndex = $startIndex;
            $startIndex = $tmp;
        }
        $n = count($rows);
        if ($startIndex < 0) {
            $startIndex = 0;
        }
        if ($endIndex >= $n) {
            $endIndex = $n - 1;
        }
        if ($endIndex === $startIndex) {
            return 0.0;
        }

        $result = -INF;
        for ($i = $startIndex + 1; $i <= $endIndex; $i++) {
            $h = (float) $rows[$i]['high'];
            if ($h > $result) {
                $result = $h;
            }
        }

        return $result === -INF ? 0.0 : $result;
    }

    /**
     * Порт ScriptSpells.Lowest: min Low на [startIndex+1 .. endIndex].
     *
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     */
    private function lowest(array $rows, int $startIndex, int $endIndex): float
    {
        if ($endIndex < $startIndex) {
            $tmp = $endIndex;
            $endIndex = $startIndex;
            $startIndex = $tmp;
        }
        $n = count($rows);
        if ($startIndex < 0) {
            $startIndex = 0;
        }
        if ($endIndex >= $n) {
            $endIndex = $n - 1;
        }
        if ($endIndex === $startIndex) {
            return 0.0;
        }

        $result = INF;
        for ($i = $startIndex + 1; $i <= $endIndex; $i++) {
            $l = (float) $rows[$i]['low'];
            if ($l < $result) {
                $result = $l;
            }
        }

        return $result === INF ? 0.0 : $result;
    }
}
