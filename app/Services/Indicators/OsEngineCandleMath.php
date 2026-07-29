<?php

namespace App\Services\Indicators;

/**
 * Общие примитивы для портирования индикаторов OsEngine (Scripts/*.cs).
 * Точки свечи — как Entity.CandlePointsArray / GetPoint.
 */
final class OsEngineCandleMath
{
    /** @var list<string> */
    public const CANDLE_POINTS = ['Open', 'High', 'Low', 'Close', 'Median', 'Typical'];

    /**
     * @param  array{time:int,open:float,high:float,low:float,close:float,volume?:float}  $candle
     */
    public static function candlePoint(array $candle, string $point): float
    {
        $o = (float) ($candle['open'] ?? 0);
        $h = (float) ($candle['high'] ?? 0);
        $l = (float) ($candle['low'] ?? 0);
        $c = (float) ($candle['close'] ?? 0);

        return match ($point) {
            'Open' => $o,
            'High' => $h,
            'Low' => $l,
            'Median' => ($h + $l) / 2.0,
            'Typical' => ($h + $l + $c) / 3.0,
            default => $c, // Close
        };
    }

    /**
     * Упаковка серии {time,value}: нули OsEngine («нет значения») не отдаём на график.
     *
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  list<float|null>  $values
     * @return list<array{time:int,value:float}>
     */
    public static function packSeries(array $rows, array $values, bool $skipZeros = true): array
    {
        $out = [];
        $n = min(count($rows), count($values));
        for ($i = 0; $i < $n; $i++) {
            $v = $values[$i];
            if ($v === null) {
                continue;
            }
            $fv = (float) $v;
            if ($skipZeros && $fv == 0.0) {
                continue;
            }
            $out[] = [
                'time' => (int) $rows[$i]['time'],
                'value' => $fv,
            ];
        }

        return $out;
    }

    /**
     * Простая SMA по closes[index-length+1 .. index] включительно (как OsEngine Sma / candles.Summ).
     *
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     */
    public static function smaAt(array $rows, int $index, int $length, string $candlePoint = 'Close'): float
    {
        if ($length <= 0 || $index < $length - 1 || $index >= count($rows)) {
            return 0.0;
        }
        $sum = 0.0;
        $from = $index - $length + 1;
        for ($i = $from; $i <= $index; $i++) {
            $sum += self::candlePoint($rows[$i], $candlePoint);
        }

        return $sum / $length;
    }
}
