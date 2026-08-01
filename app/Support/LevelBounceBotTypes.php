<?php

namespace App\Support;

/**
 * Типы ботов OsEngine→CALL/PUT и level-bounce: Telegram StrongLevel pipeline.
 * UI «Карточки» доступен всем ботам; supportsCards здесь — только для Telegram-алертов.
 */
final class LevelBounceBotTypes
{
    /** @var list<string> */
    public const CARD_AND_TELEGRAM = [
        'strong_level',
        'candle_reversal_confirm',
        'rsi_contrtrend',
        'bollinger_contrtrend',
        'pinbar_trade',
        'williams_range_trade',
        'three_soldier',
        'cci_trade',
        'rsi_trade',
        'rvi_trade',
        'envelop_trend',
        'price_channel_trade',
        'parabolic_sar_trade',
        'break_linreg_channel',
        'pivot_points',
        'sma_stochastic',
        'momentum_macd',
        'parabolic_bollinger',
        'parabolic_price_channel',
        'vol_adaptive_candles',
        'three_soldier_vol_adaptive',
        'custom_candles_impulse',
    ];

    /** Новые mean-reversion / pattern порты (дефолты runtime как у bollinger_contrtrend). */
    /** @var list<string> */
    public const OS_ENGINE_BINARY = [
        'rsi_contrtrend',
        'bollinger_contrtrend',
        'pinbar_trade',
        'williams_range_trade',
        'three_soldier',
        'cci_trade',
        'rsi_trade',
        'rvi_trade',
        'envelop_trend',
        'price_channel_trade',
        'parabolic_sar_trade',
        'break_linreg_channel',
        'pivot_points',
        'sma_stochastic',
        'momentum_macd',
        'parabolic_bollinger',
        'parabolic_price_channel',
        'vol_adaptive_candles',
        'three_soldier_vol_adaptive',
        'custom_candles_impulse',
    ];

    public static function supportsCards(string $type): bool
    {
        // Историческое имя: сейчас гейтит Telegram StrongLevel-алерты (не UI карточек).
        return in_array($type, self::CARD_AND_TELEGRAM, true);
    }

    public static function isOsEngineBinary(string $type): bool
    {
        return in_array($type, self::OS_ENGINE_BINARY, true);
    }
}
