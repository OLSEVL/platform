<?php

namespace App\Services\Indicators;

/**
 * ATR Channel (Kopyrkin) — порт OsEngine Charts/CandleChart/Indicators/AtrChannel.cs.
 *
 * Трейлинг-линия на базе WATR (экспоненциально сглаженный True Range, IsWatr=true):
 * в sell/none — reverse = LPrice + Mult×WATR; в buy — HPrice − Mult×WATR.
 * GetValue смотрит на свечу index-1 (как в исходнике).
 */
class AtrChannelIndicatorService
{
    public const ID = 'os.atr_channel';

    /** Side.None / Buy / Sell из OsEngine.Entity.Side */
    private const SIDE_NONE = 0;

    private const SIDE_BUY = 1;

    private const SIDE_SELL = 2;

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'length' => 14,
        'multiplier' => 3.0,
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
        $multiplier = (float) $p['multiplier'];
        $n = count($rows);

        // WATR: экспоненциально взвешенный ATR (MovingAverageExponentiallyWeighted).
        $watr = $this->computeWatr($rows, $length);

        $values = [];
        $hPrice = 0.0;
        $lPrice = 0.0;
        $currentSide = self::SIDE_NONE;

        for ($index = 0; $index < $n; $index++) {
            $values[] = $this->getValue(
                $rows,
                $index,
                $values,
                $watr,
                $length,
                $multiplier,
                $hPrice,
                $lPrice,
                $currentSide
            );
        }

        return $values;
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  array<string,mixed>|null  $params
     * @return array{series: array{channel?: list<array{time:int,value:float}>}, style: array<string,mixed>}
     */
    public function compute(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $raw = $this->computeSeries($rows, $p);
        $series = [];
        if ((bool) $p['show_line']) {
            // Канал почти всегда ≈ цене — нули пропускаем на всякий случай.
            $series['channel'] = OsEngineCandleMath::packSeries($rows, $raw);
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
     * Порт GetValue: возвращает reverse-уровень; мутирует H/L/side по ссылке.
     *
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $candles
     * @param  list<float>  $values  уже посчитанные значения (Count == index до Add)
     * @param  list<float>  $watr
     */
    private function getValue(
        array $candles,
        int $index,
        array $values,
        array $watr,
        int $length,
        float $multiplier,
        float &$hPrice,
        float &$lPrice,
        int &$currentSide
    ): float {
        if ($index < 3) {
            return (float) $candles[$index]['close'];
        }

        // OsEngine берёт OHLC предыдущего бара.
        $currentCandleClose = (float) $candles[$index - 1]['close'];
        $currentHigh = (float) $candles[$index - 1]['high'];
        $currentLow = (float) $candles[$index - 1]['low'];

        // Пока Values.Count < Length (т.е. index < Length) — seed H/L и Close.
        if (count($values) < $length) {
            $hPrice = $currentHigh;
            $lPrice = $currentLow;

            return $currentCandleClose;
        }

        $previousValue = $values[max(0, $index - 1)];
        $wAtr = $watr[$index - 1];

        if ($wAtr == 0.0) {
            return $currentCandleClose;
        }

        $reverse = $previousValue;

        if ($currentSide !== self::SIDE_BUY) {
            // Sell / None: стоп сверху от минимума.
            if ($currentLow < $lPrice) {
                $lPrice = $currentLow;
            }
            $reverse = $lPrice + $multiplier * $wAtr;

            if ($currentCandleClose >= $reverse) {
                $currentSide = self::SIDE_BUY;
                $hPrice = $currentCandleClose;
            }
        } elseif ($currentSide !== self::SIDE_SELL) {
            // Buy: стоп снизу от максимума.
            if ($currentHigh > $hPrice) {
                $hPrice = $currentHigh;
            }
            $reverse = $hPrice - $multiplier * $wAtr;

            if ($currentCandleClose < $reverse) {
                $currentSide = self::SIDE_SELL;
                $lPrice = $currentCandleClose;
            }
        } else {
            // Недостижимо (Buy && Sell), оставлено для паритета с исходником.
            $currentSide = self::SIDE_NONE;
            $reverse = $previousValue;
        }

        return $reverse;
    }

    /**
     * WATR = EMA-сглаженный True Range (IsWatr path в старом Atr.cs).
     *
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @return list<float>
     */
    private function computeWatr(array $rows, int $length): array
    {
        $n = count($rows);
        $trueRange = array_fill(0, $n, 0.0);
        $moving = array_fill(0, $n, 0.0);
        $lambda = 2.0 / ($length + 1);

        for ($index = 0; $index < $n; $index++) {
            if ($index === 0) {
                $trueRange[0] = 0.0;
            } else {
                $hiToLow = abs((float) $rows[$index]['high'] - (float) $rows[$index]['low']);
                $closeToHigh = abs((float) $rows[$index - 1]['close'] - (float) $rows[$index]['high']);
                $closeToLow = abs((float) $rows[$index - 1]['close'] - (float) $rows[$index]['low']);
                $trueRange[$index] = max($hiToLow, $closeToHigh, $closeToLow);
            }

            // length > count → нули; length == count → SMA seed; иначе EMA.
            if ($index < $length - 1) {
                $moving[$index] = 0.0;
            } elseif ($index === $length - 1) {
                $sum = 0.0;
                for ($i = 0; $i <= $index; $i++) {
                    $sum += $trueRange[$i];
                }
                $moving[$index] = $sum / $length;
            } else {
                $lastTr = round($trueRange[$index], 7);
                $prev = $moving[$index - 1];
                $moving[$index] = round($prev + ($lastTr - $prev) * $lambda, 7);
            }
        }

        // OsEngine Atr.GetValue округляет до 7 знаков на выходе.
        for ($i = 0; $i < $n; $i++) {
            $moving[$i] = round($moving[$i], 7);
        }

        return $moving;
    }
}
