<?php

namespace App\Services\Indicators;

use App\Support\PriceScale;

/**
 * RSI — порт OsEngine Indicators/Scripts/RSI.cs.
 * Сглаживание приростов — MovingAverageHard: seed SMA, далее a = 2/(length*2) = 1/length.
 */
class RsiIndicatorService
{
    public const ID = 'os.rsi';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'length' => 14,
        'show_line' => true,
        'color' => '#1e90ff',
        'line_width' => 1,
        // Горизонтали зоны (для pane; в OsEngine рисуются отдельно, дефолты классические)
        'level_overbought' => 70.0,
        'level_oversold' => 30.0,
        'show_levels' => true,
    ];

    /** @return array<string, mixed> */
    public static function defaultParams(): array
    {
        return self::DEFAULT_PARAMS;
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  array<string,mixed>|null  $params
     * @return list<float>  0 = нет значения
     */
    public function computeSeries(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $length = max(1, (int) $p['length']);
        $n = count($rows);
        $values = array_fill(0, $n, 0.0);

        for ($index = 0; $index < $n; $index++) {
            // OsEngine: if (index - length - 1 <= 0) return;
            if ($index - $length - 1 <= 0) {
                continue;
            }

            $startIndex = 1;
            if ($index > $length) {
                $startIndex = $index - $length - 20;
                if ($startIndex - 1 < 0) {
                    $startIndex = $index - $length + 1;
                }
            }

            $priceChangeHigh = [];
            $priceChangeLow = [];
            $avgHigh = [];
            $avgLow = [];

            for ($i = $startIndex, $valueInd = 0; $i <= $index && $i < $n; $i++, $valueInd++) {
                $diff = (float) $rows[$i]['close'] - (float) $rows[$i - 1]['close'];
                if ($diff > 0) {
                    $priceChangeHigh[] = $diff;
                    $priceChangeLow[] = 0.0;
                } else {
                    $priceChangeLow[] = (float) $rows[$i - 1]['close'] - (float) $rows[$i]['close'];
                    $priceChangeHigh[] = 0.0;
                }
                $this->movingAverageHard($priceChangeHigh, $avgHigh, $length, $valueInd);
                $this->movingAverageHard($priceChangeLow, $avgLow, $length, $valueInd);
            }

            if ($avgHigh === []) {
                continue;
            }

            $averageHigh = $avgHigh[count($avgHigh) - 1];
            $averageLow = $avgLow[count($avgLow) - 1];

            if ($averageHigh != 0.0 && $averageLow != 0.0) {
                $rsi = 100.0 * (1.0 - $averageLow / ($averageLow + $averageHigh));
            } else {
                $rsi = 100.0;
            }

            $values[$index] = PriceScale::roundMarket($rsi);
        }

        return $values;
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  array<string,mixed>|null  $params
     * @return array{
     *   series: array{rsi?: list<array{time:int,value:float}>},
     *   style: array<string,mixed>,
     *   levels: array{overbought: float, oversold: float, show: bool}
     * }
     */
    public function compute(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $raw = $this->computeSeries($rows, $p);
        $series = [];
        if ((bool) $p['show_line']) {
            $series['rsi'] = OsEngineCandleMath::packSeries($rows, $raw);
        }

        return [
            'series' => $series,
            'style' => [
                'color' => (string) $p['color'],
                'line_width' => max(1, (int) $p['line_width']),
            ],
            'levels' => [
                'overbought' => (float) $p['level_overbought'],
                'oversold' => (float) $p['level_oversold'],
                'show' => (bool) $p['show_levels'],
            ],
        ];
    }

    /**
     * Порт private MovingAverageHard из RSI.cs.
     *
     * @param  list<float>  $valuesSeries
     * @param  list<float>  $moving
     */
    private function movingAverageHard(array $valuesSeries, array &$moving, int $length, int $index): void
    {
        if ($index === $length) {
            $lastMoving = 0.0;
            for ($i = $index; $i < count($valuesSeries) && $i > $index - 1 - $length; $i--) {
                $lastMoving += $valuesSeries[$i];
            }
            $lastMoving = $lastMoving / $length;
            while (count($moving) <= $index) {
                $moving[] = 0.0;
            }
            $moving[$index] = $lastMoving;
        } elseif ($index > $length) {
            $a = 2.0 / ($length * 2);
            $lastValueMoving = $moving[$index - 1];
            $lastValueSeries = $valuesSeries[$index];
            $nowValueMoving = $lastValueMoving + $a * ($lastValueSeries - $lastValueMoving);
            while (count($moving) <= $index) {
                $moving[] = 0.0;
            }
            $moving[$index] = $nowValueMoving;
        }
    }
}
