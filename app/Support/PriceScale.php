<?php

namespace App\Support;

/**
 * Масштаб цены для микро-инструментов (LBP/USD OTC ~0.0000057).
 * Фиксированные 5 знаков после запятой схлопывают OHLC в одну линию.
 */
final class PriceScale
{
    public static function decimalsForMagnitude(float $absPrice): int
    {
        if ($absPrice <= 0) {
            return 5;
        }
        if ($absPrice < 0.00001) {
            return 10;
        }
        if ($absPrice < 0.0001) {
            return 8;
        }
        if ($absPrice < 0.01) {
            return 6;
        }

        return 5;
    }

    /**
     * @param  list<array<string, mixed>>  $candles
     */
    public static function resolveFromCandles(array $candles): int
    {
        $maxAbs = 0.0;
        foreach ($candles as $candle) {
            foreach (['open', 'high', 'low', 'close'] as $field) {
                $maxAbs = max($maxAbs, abs((float) ($candle[$field] ?? 0)));
            }
        }

        return self::decimalsForMagnitude($maxAbs);
    }

    public static function roundPrice(float $price, int $decimals): float
    {
        return round($price, $decimals);
    }

    public static function minMoveForDecimals(int $decimals): float
    {
        return 10 ** (-$decimals);
    }

    /**
     * @return array{precision:int,min_move:float}
     */
    public static function chartFormat(int $decimals): array
    {
        return [
            'precision' => $decimals,
            'min_move' => self::minMoveForDecimals($decimals),
        ];
    }
}
