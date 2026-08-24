<?php

namespace App\Support;

/**
 * Конфиг и константы бота «Dual DeMarker Fast» (стратегия №3):
 * DeM1 выход с дельтой + подтверждение быстрого DeM2 в окне N баров,
 * мин. свечей в зоне, фильтр импульса, 2 колена (2-е только после LOSS 1-го).
 */
final class DemarkerDualFastConfig
{
    public const BOT_TYPE = 'demarker_dual_fast';

    public const VERSION = 'demarker_dual_fast_v1';

    /**
     * Дефолты под активную быструю конфигурацию:
     * DeM1=10 85/15 Δ2, DeM2=5 95/5 Δ3, 2 колена, 1 свеча экспирации.
     *
     * @return array<string, mixed>
     */
    public static function defaultConfig(): array
    {
        return [
            'count' => 120,
            'timeframe' => \App\Services\CandleService::TF_S30,
            // Опциональный фильтр поверх dual-DeMarker (0 = выкл.).
            'streak_len' => 0,
            'candle_strictness' => 'strict',
            'min_body_to_range_ratio' => 0.0,
            // Основной DeMarker (проценты 0..100 в форме).
            'demarker_period' => 10,
            'demarker_oversold' => 15.0,
            'demarker_overbought' => 85.0,
            'demarker_level_delta' => 2.0,
            // Быстрый DeMarker для подтверждения.
            'second_demarker_enabled' => true,
            'second_demarker_period' => 5,
            'second_demarker_oversold' => 5.0,
            'second_demarker_overbought' => 95.0,
            'second_demarker_level_delta' => 3.0,
            // Окно баров, в котором DeM2 может подтвердить выход DeM1 (включая сигнальный).
            'second_demarker_confirm_bars' => 3,
            // Мин. число баров DeM1 строго в зоне до бара выхода (бар выхода не считается).
            'min_candles_in_zone' => 3,
            // |H−L| сигнальной / среднее |H−L| за N предыдущих ≤ порога.
            'max_exit_candle_ratio' => 2.0,
            'exit_candle_avg_bars' => 10,
            // Колена на один сигнал: 1 = только вход; 2 = второе после LOSS первого.
            'entries_per_signal' => 2,
            'expiry_sec' => 30,
            'min_confidence' => 0.0,
            'min_payout' => 80.0,
            'stale_after_sec' => 30,
            'dedupe_bucket_sec' => 30,
            'telegram_enabled' => false,
            'telegram_notify_result' => true,
            'telegram_notify_on' => 'signal_trade',
            'telegram_send_chart' => true,
            'telegram_chat_id' => '',
            'telegram_auto_subscribe' => true,
            'telegram_analyst_llm_enabled' => true,
            'telegram_analyst_bot_id' => null,
            'version' => self::VERSION,
        ];
    }

    public static function is(string $botType): bool
    {
        return $botType === self::BOT_TYPE;
    }
}
