<?php

namespace App\Support;

/**
 * Масштаб цены для графика и индикаторов.
 *
 * Раньше для FX (цена ≥ 0.01) использовали 5 знаков — из‑за этого UAH/USD и др. «почти-доджи»
 * с телом 0.000002 схлопывались в идеальный доджi на графике, хотя PO рисует микро-тело.
 * PO и candles в БД — decimal(20,8); для всех инструментов единый формат 8 знаков.
 */
final class PriceScale
{
    /**
     * Точность OHLC и ценовых индикаторов: совпадает с PO/ingest и колонками candles.*.
     * round(..., 5) на NGN/LBP обнуляет осцилляторы; round(..., 8) — нет.
     */
    public const MARKET_DECIMALS = 8;

    /**
     * Знаков после запятой для оси графика (Lightweight Charts priceFormat).
     * Всегда 8 — как у PO и в БД; не зависит от величины цены.
     */
    public static function chartDecimals(): int
    {
        return self::MARKET_DECIMALS;
    }

    /**
     * @deprecated Используйте chartDecimals(); оставлено для совместимости тестов/вызовов.
     */
    public static function decimalsForMagnitude(float $absPrice): int
    {
        return self::MARKET_DECIMALS;
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
     * OHLC для API графика: только обрезка float-шума до 8 знаков (как в БД), без урезания до 5.
     *
     * @param  list<array<string, mixed>>  $candles
     */
    public static function resolveFromCandles(array $candles): int
    {
        return self::chartDecimals();
    }

    /** OHLC с графика: 8 знаков, без потери sub-tick тел свечей. */
    public static function normalizeChartPrice(float $price): float
    {
        return self::roundMarket($price);
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
