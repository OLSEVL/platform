<?php

namespace App\Services\Indicators;

/**
 * SSMA (Smoothed SMA / Wilder-like) — порт OsEngine Indicators/Scripts/Ssma.cs.
 *
 * Seed на index==length = SMA окна; далее: ssma = (ssmaPrev * (length-1) + price) / length.
 * Используется Alligator и может переиспользоваться другими индикаторами OsEngine.
 */
class SsmaIndicatorService
{
    public const ID = 'os.ssma';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'length' => 14,
        'candle_point' => 'Close',
    ];

    /** @return array<string, mixed> */
    public static function defaultParams(): array
    {
        return self::DEFAULT_PARAMS;
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  array<string,mixed>|null  $params
     * @return list<float>  0 = прогрев (index < length)
     */
    public function computeSeries(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $length = max(1, (int) $p['length']);
        $point = (string) $p['candle_point'];
        $n = count($rows);
        $values = array_fill(0, $n, 0.0);

        for ($index = 0; $index < $n; $index++) {
            if ($index < $length) {
                // До seed-индекса OsEngine оставляет 0.
                continue;
            }

            if ($index === $length) {
                // Seed: SMA по окну [index-length+1 .. index].
                $sum = 0.0;
                for ($i = $index - $length + 1; $i <= $index; $i++) {
                    $sum += OsEngineCandleMath::candlePoint($rows[$i], $point);
                }
                $values[$index] = $sum / $length;
            } else {
                $prev = $values[$index - 1];
                $price = OsEngineCandleMath::candlePoint($rows[$index], $point);
                $values[$index] = ($prev * ($length - 1) + $price) / $length;
            }
        }

        return $values;
    }
}
