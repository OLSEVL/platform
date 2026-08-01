<?php

namespace App\Services\Indicators;

/**
 * Dynamic Trend Detector (DTD) — порт OsEngine CandleChart/Indicators/DynamicTrendDetector.cs.
 *
 * Канал Копыркина: при бычьем режиме линия = max(high) окна × (1 − corr%/100),
 * при медвежьем = min(low) × (1 + corr%/100). Окно растёт до Length, затем фиксируется.
 * Состояние Period / currentSide накапливается между барами (как в OsEngine GetValue).
 */
class DynamicTrendDetectorIndicatorService
{
    public const ID = 'os.dynamic_trend_detector';

    /** Сторона тренда: 0=None, 1=Buy, −1=Sell (аналог OsEngine Side). */
    private const SIDE_NONE = 0;

    private const SIDE_BUY = 1;

    private const SIDE_SELL = -1;

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'length' => 14,
        'correction_coeff' => 3.0, // проценты
        'show_line' => true,
        'color' => '#1e90ff', // DodgerBlue
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
     * @return list<float>
     */
    public function computeSeries(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $length = max(1, (int) $p['length']);
        $corrPct = (float) $p['correction_coeff'];
        $n = count($rows);
        $values = [];

        // Состояние как поля класса в OsEngine (Period, currentSide).
        $period = 0;
        $currentSide = self::SIDE_NONE;

        $correctionPercent = $corrPct / 100.0;
        $coeffUp = 1.0 - $correctionPercent;
        $coeffDown = 1.0 + $correctionPercent;

        for ($index = 0; $index < $n; $index++) {
            $values[$index] = $this->getValue(
                $rows,
                $values,
                $index,
                $length,
                $coeffUp,
                $coeffDown,
                $period,
                $currentSide
            );
        }

        return $values;
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  array<string,mixed>|null  $params
     * @return array{series: array{dtd?: list<array{time:int,value:float}>}, style: array<string,mixed>}
     */
    public function compute(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $raw = $this->computeSeries($rows, $p);
        $series = [];
        if ((bool) $p['show_line']) {
            $series['dtd'] = OsEngineCandleMath::packSeries($rows, $raw);
        }

        return [
            'series' => $series,
            'style' => [
                'color' => (string) $p['color'],
                'line_width' => max(1, (int) $p['line_width']),
            ],
        ];
    }

    /**
     * Порт GetValue: обновляет $period / $currentSide по ссылке.
     *
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  list<float>  $values  уже посчитанные значения [0 .. index-1]
     */
    private function getValue(
        array $rows,
        array $values,
        int $index,
        int $length,
        float $coeffUp,
        float $coeffDown,
        int &$period,
        int &$currentSide
    ): float {
        if ($index <= 2) {
            $period = 0;
        }

        $currentCandleClose = (float) $rows[$index]['close'];

        // ProcessAll: до Add Values.Count==0 → вернуть close текущего бара.
        if (count($values) === 0) {
            return $currentCandleClose;
        }

        // currentTrendClose = Values[index−1] (уже добавленные точки).
        $currentTrendClose = (float) $values[max(0, $index - 1)];

        if ($index >= 1) {
            if ($currentTrendClose < $currentCandleClose) {
                if ($currentSide !== self::SIDE_BUY) {
                    $period = 0;
                }
                $currentSide = self::SIDE_BUY;
            } elseif ($currentTrendClose >= $currentCandleClose) {
                if ($currentSide !== self::SIDE_SELL) {
                    $period = 0;
                }
                $currentSide = self::SIDE_SELL;
            } else {
                // Ветвь теоретически недостижима (close либо < либо >=), как в исходнике.
                $currentSide = self::SIDE_NONE;
                $period = 0;
            }
        }

        if ($period < $length) {
            if ($currentSide !== self::SIDE_NONE) {
                $startIdx = max(0, $index - $period - 1);
                $numItems = max(1, $period - 1);
                $sub = array_slice($rows, $startIdx, $numItems);
                $period += 1;

                if ($currentSide === self::SIDE_BUY) {
                    return $this->maxHigh($sub) * $coeffUp;
                }

                return $this->minLow($sub) * $coeffDown;
            }

            return $currentTrendClose;
        }

        // Period >= Length — фиксированное окно Length.
        $startIdx = max(0, $index - $length - 1);
        $numItems = max(1, $length - 1);
        $sub = array_slice($rows, $startIdx, $numItems);

        return $currentSide === self::SIDE_BUY
            ? $this->maxHigh($sub) * $coeffUp
            : $this->minLow($sub) * $coeffDown;
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $candles
     */
    private function maxHigh(array $candles): float
    {
        $max = -PHP_FLOAT_MAX;
        foreach ($candles as $c) {
            $h = (float) $c['high'];
            if ($h > $max) {
                $max = $h;
            }
        }

        return $max === -PHP_FLOAT_MAX ? 0.0 : $max;
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $candles
     */
    private function minLow(array $candles): float
    {
        $min = PHP_FLOAT_MAX;
        foreach ($candles as $c) {
            $l = (float) $c['low'];
            if ($l < $min) {
                $min = $l;
            }
        }

        return $min === PHP_FLOAT_MAX ? 0.0 : $min;
    }
}
