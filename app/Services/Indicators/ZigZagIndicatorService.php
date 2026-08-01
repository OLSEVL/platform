<?php

namespace App\Services\Indicators;

/**
 * ZigZag — порт OsEngine Indicators/Scripts/ZigZag.cs.
 *
 * Фильтрует мелкие колебания: swing high/low = экстремум High/Low на окне Length
 * (index … index−Length). Ломаная ZigZagLine интерполируется между подтверждёнными
 * свингами; highs/lows — точки экстремумов (опционально).
 *
 * Отличие от оригинала: ReBuildLine вызываем и при add, и при update свинга —
 * иначе линия почти не появляется (в C# rebuild только на update).
 */
class ZigZagIndicatorService
{
    public const ID = 'os.zigzag';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'length' => 14,
        'show_line' => true,
        'show_highs' => true,
        'show_lows' => true,
        'show_points' => false, // OsEngine ZigZag-серия IsPainted=false
        'color_line' => '#6495ed', // CornflowerBlue
        'color_high' => '#adff2f', // GreenYellow
        'color_low' => '#ff0000', // Red
        'line_width' => 1,
        'point_radius' => 3,
    ];

    /** @return array<string, mixed> */
    public static function defaultParams(): array
    {
        return self::DEFAULT_PARAMS;
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  array<string,mixed>|null  $params
     * @return array{
     *   zigzag: list<float>,
     *   line: list<float>,
     *   highs: list<float>,
     *   lows: list<float>
     * }
     */
    public function computeSeries(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $period = max(1, (int) $p['length']);
        $n = count($rows);

        $zigZag = array_fill(0, $n, 0.0);
        $line = array_fill(0, $n, 0.0);
        $highs = array_fill(0, $n, 0.0);
        $lows = array_fill(0, $n, 0.0);

        if ($n === 0) {
            return ['zigzag' => $zigZag, 'line' => $line, 'highs' => $highs, 'lows' => $lows];
        }

        $currentZigZagHigh = 0.0;
        $currentZigZagLow = 0.0;
        $lastSwingIndex = -1;
        $lastSwingPrice = 0.0;
        $trendDir = 0;

        for ($index = 0; $index < $n; $index++) {
            // Прогрев: length*2 баров, как в OsEngine (сброс state).
            if ($index < $period * 2) {
                $currentZigZagHigh = 0.0;
                $currentZigZagLow = 0.0;
                $lastSwingIndex = -1;
                $lastSwingPrice = 0.0;
                $trendDir = 0;

                continue;
            }

            $high = (float) $rows[$index]['high'];
            $low = (float) $rows[$index]['low'];

            if ($lastSwingPrice == 0.0) {
                $lastSwingPrice = $low + ($high - $low) / 2.0;
            }

            $isSwingHigh = $high == $this->getExtremum($rows, $period, 'High', $index);
            $isSwingLow = $low == $this->getExtremum($rows, $period, 'Low', $index);

            if (! $isSwingHigh && ! $isSwingLow) {
                continue;
            }

            $saveValue = 0.0;
            $addHigh = false;
            $addLow = false;
            $updateHigh = false;
            $updateLow = false;

            if ($trendDir === 1 && $isSwingHigh && $high >= $lastSwingPrice) {
                $saveValue = $high;
                $updateHigh = true;
            } elseif ($trendDir === -1 && $isSwingLow && $low <= $lastSwingPrice) {
                $saveValue = $low;
                $updateLow = true;
            } elseif ($trendDir <= 0 && $isSwingHigh) {
                $saveValue = $high;
                $addHigh = true;
                $trendDir = 1;
            } elseif ($trendDir >= 0 && $isSwingLow) {
                $saveValue = $low;
                $addLow = true;
                $trendDir = -1;
            }

            if (! ($addHigh || $addLow || $updateHigh || $updateLow)) {
                continue;
            }

            // При update снимаем предыдущий свинг (в C# писали 0 вместо NaN).
            if ($updateHigh && $lastSwingIndex >= 0) {
                $zigZag[$lastSwingIndex] = 0.0;
                $highs[$lastSwingIndex] = 0.0;
            } elseif ($updateLow && $lastSwingIndex >= 0) {
                $zigZag[$lastSwingIndex] = 0.0;
                $lows[$lastSwingIndex] = 0.0;
            }

            if ($addHigh || $updateHigh) {
                $currentZigZagHigh = $saveValue;
                $zigZag[$index] = $currentZigZagHigh;
                $highs[$index] = $currentZigZagHigh;
            } elseif ($addLow || $updateLow) {
                $currentZigZagLow = $saveValue;
                $zigZag[$index] = $currentZigZagLow;
                $lows[$index] = $currentZigZagLow;
            }

            $lastSwingIndex = $index;
            $lastSwingPrice = $saveValue;

            // Ломаная между свингами (и на add, и на update — см. класс-док).
            $this->reBuildLine($zigZag, $line);
        }

        return ['zigzag' => $zigZag, 'line' => $line, 'highs' => $highs, 'lows' => $lows];
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
            $series['line'] = OsEngineCandleMath::packSeries($rows, $raw['line']);
        }
        if ((bool) $p['show_highs']) {
            $series['highs'] = OsEngineCandleMath::packSeries($rows, $raw['highs']);
        }
        if ((bool) $p['show_lows']) {
            $series['lows'] = OsEngineCandleMath::packSeries($rows, $raw['lows']);
        }
        if ((bool) $p['show_points']) {
            $series['zigzag'] = OsEngineCandleMath::packSeries($rows, $raw['zigzag']);
        }

        return [
            'series' => $series,
            'style' => [
                'color_line' => (string) $p['color_line'],
                'color_high' => (string) $p['color_high'],
                'color_low' => (string) $p['color_low'],
                'line_width' => max(1, (int) $p['line_width']),
                'point_radius' => max(1, (int) $p['point_radius']),
            ],
        ];
    }

    /**
     * Max High / Min Low на [index−period … index] включительно.
     *
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     */
    private function getExtremum(array $rows, int $period, string $points, int $index): float
    {
        $values = [];
        for ($i = $index; $i >= $index - $period; $i--) {
            if ($i < 0) {
                break;
            }
            $values[] = OsEngineCandleMath::candlePoint($rows[$i], $points);
        }

        if ($values === []) {
            return 0.0;
        }

        return $points === 'High' ? max($values) : min($values);
    }

    /**
     * Линейная интерполяция между ненулевыми точками ZigZag → seriesToLine.
     *
     * @param  list<float>  $zigZag
     * @param  list<float>  $line
     */
    private function reBuildLine(array $zigZag, array &$line): void
    {
        $n = count($zigZag);
        // Обнуляем линию перед полной перестройкой (после update старые сегменты иначе «висят»).
        for ($i = 0; $i < $n; $i++) {
            $line[$i] = 0.0;
        }

        $curPoint = 0.0;
        $lastPointIndex = 0;

        for ($i = 0; $i < $n; $i++) {
            if ($zigZag[$i] == 0.0) {
                continue;
            }

            if ($curPoint == 0.0) {
                $curPoint = $zigZag[$i];
                $lastPointIndex = $i;
                $line[$i] = $curPoint;

                continue;
            }

            $mult = abs($curPoint - $zigZag[$i]) / max(1, ($i - $lastPointIndex));
            if ($zigZag[$i] < $curPoint) {
                $mult = -$mult;
            }

            $curValue = $curPoint;
            for ($i2 = $lastPointIndex; $i2 < $i; $i2++) {
                $line[$i2] = $curValue;
                $curValue += $mult;
            }
            $line[$i] = $zigZag[$i];

            $curPoint = $zigZag[$i];
            $lastPointIndex = $i;
        }
    }
}
