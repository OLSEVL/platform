<?php

namespace App\Support;

/**
 * Семейство ботов Chart Analyst (ручной анализ / Telegram / гибрид, без автоскана).
 *
 * Классический и SMC Flow живут в type=openrouter_llm_analyst (флаг analyst_smc_flow_enabled).
 * Sweep/Raid — отдельный type, чтобы дефолты, промпт и слой графика не смешивались с SMC.
 */
final class ChartAnalystBotTypes
{
    /** Классический аналитик и вариант SMC Flow. */
    public const CLASSIC = 'openrouter_llm_analyst';

    /** Аналитик подтверждения сетапов Sweep / Raid Liquidity. */
    public const SRL = 'openrouter_llm_analyst_srl';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::CLASSIC, self::SRL];
    }

    public static function isAnalyst(string $type): bool
    {
        return in_array($type, self::all(), true);
    }

    public static function isSrl(string $type): bool
    {
        return $type === self::SRL;
    }

    /** Человекочитаемое имя для UI / seed. */
    public static function label(string $type): string
    {
        return match ($type) {
            self::SRL => 'OpenRouter Chart Analyst Sweep/Raid',
            default => 'OpenRouter Chart Analyst',
        };
    }
}
