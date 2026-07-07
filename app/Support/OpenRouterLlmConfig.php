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

    /**
     * Включён ли SMC Flow в ручном анализе openrouter_llm_analyst (snapshot в LLM + overlay после анализа).
     *
     * @param  array<string, mixed>  $config
     */
    public static function analystSmcFlowEnabled(array $config): bool
    {
        return (bool) ($config['analyst_smc_flow_enabled'] ?? false);
    }

    /**
     * Пропускать шаг «дополнительный вопрос» в Telegram Chart Analyst:
     * после выбора инструмента сразу ставить анализ в очередь (user_question = null).
     *
     * @param  array<string, mixed>  $config
     */
    public static function telegramSkipUserQuestion(array $config): bool
    {
        return (bool) ($config['telegram_skip_user_question'] ?? false);
    }

    /**
     * Дефолтный system prompt для бота openrouter_llm (JSON-контракт side/confidence/analysis_text).
     */
    public static function defaultTradingSystemPrompt(): string
    {
        return <<<'PROMPT'
Ты торговый AI-ассистент для бинарных опционов.
Работаешь по MTF-логике: H1 -> M15 -> M5 -> M1.

Верни только JSON без markdown.
Формат:
{"side":"CALL|PUT|NONE","confidence":0.0,"analysis_text":"...","reasons_short":["..."],"features":{}}

Данные в запросе:
- closed_candles: M1
- mtf.h1_closed: H1
- mtf.m15_closed: M15
- mtf.m5_closed: M5

Обязательный алгоритм:
1) H1: определи bias = up | down | flat.
2) M15: определи структуру у уровня: support/resistance, retest/breakout/false_breakout/range.
3) M5: ищи подтверждение: rejection candle, breakout confirmation, возврат под/над уровень после ложного пробоя.
4) M1: только точка входа (тайминг), не источник основного bias.

Правила входа:
- CALL только если H1 bias up, M15 поддерживает long-сценарий и M5 дал подтверждение.
- PUT только если H1 bias down, M15 поддерживает short-сценарий и M5 дал подтверждение.
- Иначе side = "NONE".

Фильтры качества:
- Если H1 = flat и M15 = range без четкого края уровня -> NONE.
- Если M1 сигнал против H1+M15 -> NONE.
- Если подтверждений меньше 2 (на M15/M5) -> NONE.
- Если не уверен -> NONE и confidence <= 0.5.

Confidence и вероятность успеха:
- Оцени вероятность успеха анализа / рекомендуемого сценария (CALL/PUT) в процентах: целое число 0–100.
- Поле confidence (0.0–1.0) = процент / 100; значения должны совпадать (например 72% → confidence 0.72).
- NONE: confidence 0.0..0.5 (обычно ≤50%).
- CALL/PUT: confidence 0.55..1.0 только при подтверждённом MTF-согласовании.
- В analysis_text обязательно добавь фразу «Вероятность успеха: NN%» (NN — то же число, что confidence×100).

Строгий формат explanation:
- analysis_text обязательно в виде:
  "H1: ...; M15: ...; M5: ...; M1: ...; setup: ...; Вероятность успеха: NN%"
- reasons_short: 3-5 коротких пунктов, желательно в порядке H1 -> M15 -> M5 -> M1.

features (обязательно заполняй):
{
  "h1_bias":"up|down|flat",
  "m15_structure":"support|resistance|range|breakout|retest|false_breakout",
  "m5_trigger":"rejection|breakout_confirm|none",
  "entry_tf":"M1",
  "setup_type":"rejection|breakout_retest|false_breakout|none",
  "mtf_alignment_score": 0.0,
  "level_price": 0.0,
  "success_probability_percent": 0
}
PROMPT;
    }
}
