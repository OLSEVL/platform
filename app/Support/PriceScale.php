<?php

namespace App\Support;

/**
 * Масштаб цены для микро-инструментов (NGN/USD OTC ~0.00071, LBP/USD OTC ~0.00001).
 * Фиксированные 5–6 знаков после запятой схлопывают OHLC в одну линию:
 * при цене 0.00070976 и шаге бара ~1e-7 округление до 6 знаков даёт 0.000710 на всём OHLC.
 */
final class PriceScale
{
    /**
     * Единая точность для величин в единицах цены / разностей MA (AO, BB-полосы, StdDev, зоны).
     * round(..., 5|6) на NGN/LBP обнуляет осцилляторы и схлопывает каналы.
     */
    public const MARKET_DECIMALS = 8;

    /**
     * Сколько знаков после запятой нужно для величины цены.
     * Для всех микро-цен (&lt; 0.01) — всегда 8: это покрывает NGN и LBP без схлопывания свечей.
     * Обычные FX/крипто (&gt;= 0.01) остаются на 5 знаках.
     */
    public static function decimalsForMagnitude(float $absPrice): int
    {
        if ($absPrice <= 0) {
            return 5;
        }
        // NGN (~0.00071), LBP (~0.00001) и аналоги: единый формат 8 знаков.
        if ($absPrice < 0.01) {
            return self::MARKET_DECIMALS;
        }

        return 5;
    }

    /**
     * Округление серийных значений индикаторов (цена, разность MA, %, RSI/CCI/TRIX…).
     * Единые 8 знаков: на микро-парах меньше — схлопывание; для процентов хуже не становится.
     */
    public static function roundMarket(float $value): float
    {
        return round($value, self::MARKET_DECIMALS);
    }

    /**
     * Берём максимум |OHLC| по серии и по нему выбираем precision/minMove для графика.
     *
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
     * Формат серии Lightweight Charts: precision + minMove = 10^-precision.
     *
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
