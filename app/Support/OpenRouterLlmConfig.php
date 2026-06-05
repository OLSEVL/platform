<?php

namespace App\Support;

/**
 * Общие лимиты свечей для ботов openrouter_llm и openrouter_llm_analyst.
 * Одно значение на таймфрейм: столько баров читаем из БД, столько же уходит в LLM.
 */
final class OpenRouterLlmConfig
{
    /** Максимум M1-баров в одном запросе к модели (защита от слишком большого JSON). */
    public const M1_MAX = 500;

    public const M5_MAX = 300;

    public const M15_MAX = 300;

    public const H1_MAX = 200;

    /** Секундные S5-бары (ingest timeframe=4005): для точного тайминга внутри M1. */
    public const S5_MAX = 1500;

    /**
     * Убирает устаревшее поле candles_take после сохранения настроек.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function withoutLegacyCandlesTake(array $config): array
    {
        unset($config['candles_take']);

        return $config;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function m1BarCount(array $config, int $default = 120, int $min = 1): int
    {
        return max($min, min(self::M1_MAX, (int) ($config['count'] ?? $default)));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function m5BarCount(array $config, int $default = 96, int $min = 1): int
    {
        return max($min, min(self::M5_MAX, (int) ($config['m5_take'] ?? $default)));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function m15BarCount(array $config, int $default = 64, int $min = 1): int
    {
        return max($min, min(self::M15_MAX, (int) ($config['m15_take'] ?? $default)));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function h1BarCount(array $config, int $default = 24, int $min = 1): int
    {
        return max($min, min(self::H1_MAX, (int) ($config['h1_take'] ?? $default)));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function s5BarCount(array $config, int $default = 240, int $min = 1): int
    {
        return max($min, min(self::S5_MAX, (int) ($config['s5_take'] ?? $default)));
    }

    /**
     * Карта таймфрейм → число баров для BotDataService::mtfSeries.
     *
     * @param  array<string, mixed>  $config
     * @return array<int, int>
     */
    public static function mtfBarMap(array $config, bool $includeM1 = false, int $m1Min = 1): array
    {
        $map = [
            5 => self::m5BarCount($config),
            15 => self::m15BarCount($config),
            60 => self::h1BarCount($config),
        ];
        if ($includeM1) {
            $map[1] = self::m1BarCount($config, 120, $m1Min);
        }

        return $map;
    }
}
