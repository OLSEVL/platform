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

    /** Аналитик подтверждения 3-шагового поглощения (Engulfing Liquidity). */
    public const ENGULF = 'openrouter_llm_analyst_engulf';

    /** Аналитик Impulse LVL: уровни/импульс бота 47 в промпт, LLM сам даёт CALL/PUT/NONE. */
    public const IMPULSE = 'openrouter_llm_analyst_ilvl';

    /**
     * Аналитик Author Flow (средний вариант): пул D/E/sweep/raid_pb + контекст;
     * side только из candidates или NONE. Горизонт — следующая S15.
     */
    public const AUTHOR_FLOW = 'openrouter_llm_analyst_af';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::CLASSIC, self::SRL, self::ENGULF, self::IMPULSE, self::AUTHOR_FLOW];
    }

    public static function isAnalyst(string $type): bool
    {
        return in_array($type, self::all(), true);
    }

    public static function isSrl(string $type): bool
    {
        return $type === self::SRL;
    }

    public static function isEngulf(string $type): bool
    {
        return $type === self::ENGULF;
    }

    public static function isImpulse(string $type): bool
    {
        return $type === self::IMPULSE;
    }

    public static function isAuthorFlow(string $type): bool
    {
        return $type === self::AUTHOR_FLOW;
    }

    /** Человекочитаемое имя для UI / seed. */
    public static function label(string $type): string
    {
        return match ($type) {
            self::SRL => 'OpenRouter Chart Analyst Sweep/Raid',
            self::ENGULF => 'OpenRouter Chart Analyst Engulfing',
            self::IMPULSE => 'OpenRouter Chart Analyst Impulse LVL',
            self::AUTHOR_FLOW => 'OpenRouter Chart Analyst Author Flow',
            default => 'OpenRouter Chart Analyst',
        };
    }
}
