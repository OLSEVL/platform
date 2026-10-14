<?php

namespace App\Support;

/**
 * Парсит блок ```chart_levels``` в конце ответа LLM-аналитика и нормализует уровни для отрисовки на графике.
 */
final class OpenRouterChartLevelsParser
{
    public const FENCE_TAG = 'chart_levels';

    /** System prompt аналитика с SMC Flow (текст + chart_levels). */
    public static function defaultAnalystSystemPrompt(): string
    {
        return self::defaultAnalystSystemPromptWithSmcFlow();
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function defaultAnalystSystemPromptForConfig(array $config): string
    {
        // Engulf-аналитик: подтверждение 3-шагового поглощения.
        if (OpenRouterLlmConfig::analystEngulfFlowEnabled($config)) {
            return self::defaultAnalystSystemPromptWithEngulf();
        }

        // Impulse LVL: уровни бота 47 на графике и в промпте, LLM сам даёт CALL/PUT/NONE.
        if (OpenRouterLlmConfig::analystImpulseLvlFlowEnabled($config)) {
            return self::defaultAnalystSystemPromptWithImpulseLvl();
        }

        // SRL-аналитик: отдельный пакет правил (подтверждение SWEEP/RAID/PB).
        if (OpenRouterLlmConfig::analystSrlFlowEnabled($config)) {
            return self::defaultAnalystSystemPromptWithSweepRaid();
        }

        return OpenRouterLlmConfig::analystSmcFlowEnabled($config)
            ? self::defaultAnalystSystemPromptWithSmcFlow()
            : self::defaultAnalystSystemPromptClassic();
    }

    /**
     * Краткий system prompt аналитика (вердикт CALL/PUT/NONE + вероятность, без chart_levels).
     *
     * @param  array<string, mixed>  $config
     */
    public static function defaultAnalystSystemPromptShortForConfig(array $config): string
    {
        if (OpenRouterLlmConfig::analystEngulfFlowEnabled($config)) {
            return self::defaultAnalystSystemPromptShortWithEngulf();
        }

        if (OpenRouterLlmConfig::analystImpulseLvlFlowEnabled($config)) {
            return self::defaultAnalystSystemPromptShortWithImpulseLvl();
        }

        if (OpenRouterLlmConfig::analystSrlFlowEnabled($config)) {
            return self::defaultAnalystSystemPromptShortWithSweepRaid();
        }

        return OpenRouterLlmConfig::analystSmcFlowEnabled($config)
            ? self::defaultAnalystSystemPromptShortWithSmcFlow()
            : self::defaultAnalystSystemPromptShortClassic();
    }

    /** Краткий режим Impulse LVL: вердикт с учётом impulse_lvl_summary. */
    public static function defaultAnalystSystemPromptShortWithImpulseLvl(): string
    {
        return <<<'PROMPT'
Ты торговый AI-аналитик для бинарных опционов (краткий режим Impulse LVL).
Целевая экспирация: 60 секунд (1 M1-бар).

Задача — вердикт CALL/PUT/NONE по impulse_lvl_summary (зоны LVL + live_setups) и свечам.
Ты МОЖЕШЬ дать CALL/PUT даже если live_setups пуст — если импульс и касание зоны согласованы.
Пустой live_setups сам по себе не запрещает сделку.

Данные в запросе:
- impulse_lvl_summary — активные зоны M1/M5/M15/M30, touching_zones, live_setups, rule_reminder
- closed_candles_m1 / s5 / mtf.* — контекст импульса и конфликта ТФ
- expiry_focus_sec: 60
- user_question — вопрос трейдера (может быть null)

Формат ответа (строго, 2–4 строки на русском, без markdown):
Рекомендация: CALL | PUT | NONE
Вероятность успеха: NN%
Причина: одно короткое предложение

Правила:
- Сторона входа задана в impulse_lvl_summary.entry_side_mode и rule_reminder (bounce = отбой, continuation = по импульсу).
- Следуй live_setups.side и rule_reminder.call/put; не переворачивай сторону сам.
- Нет импульса, нет касания LVL, пробитая зона или конфликт ТФ → NONE.
- Вероятность успеха: целое 0–100. При NONE обычно ≤50%.
- Пиши только по-русски (кроме CALL/PUT/NONE). Без chart_levels.
PROMPT;
    }

    /** Краткий режим без SMC Flow: только вердикт по MTF + S5. */
    public static function defaultAnalystSystemPromptShortClassic(): string
    {
        return <<<'PROMPT'
Ты торговый AI-аналитик для бинарных опционов (краткий режим).
Используй те же данные, что и в полном анализе: M1, S5, MTF (H1/M15/M5/M1 если mtf.enabled).

Задача — быстрый вердикт, без развёрнутого разбора и без уровней на графике.

Данные в запросе:
- closed_candles_m1, partial_candle_m1 — M1
- closed_candles_s5, partial_candle_s5 — S5
- mtf.m1_closed, m5_closed, m15_closed, h1_closed — старшие ТФ (если mtf.enabled)
- user_question — вопрос трейдера (может быть null)

Формат ответа (строго, 2–4 строки на русском, без markdown):
Рекомендация: CALL | PUT | NONE
Вероятность успеха: NN%
Причина: одно короткое предложение (обязательно при NONE; при CALL/PUT — по желанию)

Правила:
- CALL только при согласованном long-сетапе по MTF; PUT — при short.
- NONE при flat, противоречии ТФ, неуверенности или отсутствии сетапа.
- Вероятность успеха: целое число 0–100. При NONE обычно ≤50%.
- Пиши ответ только на русском языке (кириллица). Не используй английский текст, кроме терминов CALL/PUT/NONE.
- Не пиши развёрнутый MTF-разбор. Не добавляй блок уровней на графике.
PROMPT;
    }

    /** Краткий режим с SMC Flow: вердикт с учётом smc_flow_summary. */
    public static function defaultAnalystSystemPromptShortWithSmcFlow(): string
    {
        return <<<'PROMPT'
Ты торговый AI-аналитик для бинарных опционов (краткий режим).
Целевая экспирация: 3–6 минут. Тайминг — S5 + M1; контекст — M5/M15/H1 + smc_flow_summary.

Задача — быстрый вердикт, без развёрнутого разбора и без уровней на графике.

Данные в запросе:
- closed_candles_m1, partial_candle_m1 — M1
- closed_candles_s5, partial_candle_s5 — S5
- smc_flow_summary — снимок SMC Flow (M1)
- mtf.* — старшие ТФ (если mtf.enabled)
- expiry_focus_min: [3, 6]
- user_question — вопрос трейдера (может быть null)

Формат ответа (строго, 2–4 строки на русском, без markdown):
Рекомендация: CALL | PUT | NONE
Вероятность успеха: NN%
Причина: одно короткое предложение (обязательно при NONE; при CALL/PUT — по желанию)

Правила:
- Учитывай согласованность MTF и smc_flow_summary; при расхождении — NONE или низкая вероятность.
- CALL/PUT только при согласованном сетапе на 3–6 мин.
- Вероятность успеха: целое число 0–100.
- Пиши ответ только на русском языке (кириллица). Не используй английский текст, кроме терминов CALL/PUT/NONE.
- Не пиши развёрнутый разбор. Не добавляй блок уровней на графике.
PROMPT;
    }

    /**
     * Краткий режим Engulfing: LLM только подтверждает candidates из детектора.
     */
    public static function defaultAnalystSystemPromptShortWithEngulf(): string
    {
        return <<<'PROMPT'
Ты торговый AI-аналитик для бинарных опционов (краткий режим Engulfing Liquidity).
Целевая экспирация: 60 секунд (1 M1-бар).

Задача — подтвердить или отклонить уже найденные сетапы из engulfing_summary.candidates.
Ты НЕ ищешь новые поглощения сам по свечам — только валидируешь кандидатов детектора.

Данные в запросе:
- engulfing_summary — снимок детектора (candidates, recent_entries, rule_reminder)
- closed_candles_m1 / s5 / mtf.* — контекст качества (шум, импульс, конфликт)
- expiry_focus_sec: 60
- user_question — вопрос трейдера (может быть null)

Формат ответа (строго, 2–4 строки на русском, без markdown):
Рекомендация: CALL | PUT | NONE
Вероятность успеха: NN%
Причина: одно короткое предложение

Правила:
- Если candidates пуст → NONE.
- CALL/PUT только если side совпадает с одним из candidates и цепочка ENGULF 1→2→3 валидна.
- При сомнении / грязном контексте / «уже вышли» → NONE.
- Вероятность успеха: целое 0–100. При NONE обычно ≤50%.
- Пиши только по-русски (кроме CALL/PUT/NONE). Без chart_levels.
PROMPT;
    }

    /**
     * Краткий режим Sweep/Raid: LLM только подтверждает candidates из детектора.
     */
    public static function defaultAnalystSystemPromptShortWithSweepRaid(): string
    {
        return <<<'PROMPT'
Ты торговый AI-аналитик для бинарных опционов (краткий режим Sweep / Raid Liquidity).
Целевая экспирация: 60 секунд (1 M1-бар).

Задача — подтвердить или отклонить уже найденные сетапы из sweep_raid_summary.candidates.
Ты НЕ ищешь новые фракталы/свипы сам по свечам — только валидируешь кандидатов детектора.

Данные в запросе:
- sweep_raid_summary — снимок детектора (candidates, recent_events, recent_pullbacks, rule_reminder)
- closed_candles_m1 / s5 / mtf.* — контекст качества (шум, закрепление, конфликт)
- expiry_focus_sec: 60
- user_question — вопрос трейдера (может быть null)

Формат ответа (строго, 2–4 строки на русском, без markdown):
Рекомендация: CALL | PUT | NONE
Вероятность успеха: NN%
Причина: одно короткое предложение

Правила:
- Если candidates пуст → NONE.
- CALL/PUT только если side совпадает с одним из candidates и сетап не «съеден» (два закрытия за уровнем).
- При сомнении / грязном контексте → NONE.
- Вероятность успеха: целое 0–100. При NONE обычно ≤50%.
- Пиши только по-русски (кроме CALL/PUT/NONE). Без chart_levels.
PROMPT;
    }

    /** Полный режим Engulfing: подтверждение 3-шагового поглощения + entry на график. */
    public static function defaultAnalystSystemPromptWithEngulf(): string
    {
        return <<<'PROMPT'
Ты торговый AI-аналитик для бинарных опционов по стратегии Engulfing Liquidity.
Целевая экспирация: 60 секунд. Тайминг — закрытая M1 (+ S5 для микроконтекста).

Главная задача — подтвердить или отклонить уже найденные сетапы из engulfing_summary.
Детектор платформы уже разметил ENGULF 1/2/3. Ты НЕ изобретаешь новые паттерны «с нуля».

Данные в запросе:
- engulfing_summary:
  candidates — входы на последней закрытой M1 (подтверждать только их side),
  recent_entries — контекст недавних цепочек,
  rule_reminder — правила автора (classic / same-color, 3 шага)
- closed_candles_m1, partial_candle_m1 — M1
- closed_candles_s5, partial_candle_s5 — S5
- mtf.* — старшие ТФ (если mtf.enabled) только как фильтр качества, не как источник входа
- expiry_focus_sec: 60
- user_question — вопрос трейдера (может быть null)

Правила стратегии (кратко):
1) ENGULF 1 — первое поглощение (classic или same-color): задаёт контекст CALL/PUT, входа нет.
2) ENGULF 2 — одна свеча реакции противоположного цвета.
3) ENGULF 3 — контр-поглощение реакции в сторону шага 1 → вход на close 3-й свечи.
4) Classic: зелёная съела красную → CALL; красная съела зелёную → PUT.
5) Same-color: зелёная с high выше соседа → CALL; красная с low ниже → PUT.
6) Нет 3 подряд свечей цепочки, слабые тела, опоздание — NONE.

Обязательно в тексте анализа:
1) Есть ли кандидат в candidates (явно: side, engulf_kind) или список пуст.
2) Подтверждаешь или отклоняешь (почему: качество тел, реакция, конфликт MTF/шум).
3) Явная рекомендация: CALL, PUT или NONE.
4) Горизонт 60 секунд при CALL/PUT.
5) Строка «Вероятность успеха: NN%».
PROMPT
            .self::promptInstructionsWithEngulf();
    }

    /** Полный режим Impulse LVL: зоны бота 47 + решение LLM (не только confirm). */
    public static function defaultAnalystSystemPromptWithImpulseLvl(): string
    {
        return <<<'PROMPT'
Ты торговый AI-аналитик для бинарных опционов по стратегии Impulse LVL (порт бота 47).
Целевая экспирация: 60 секунд. Тайминг — live M1 + S5; контекст — M5/M15/M30 и H1.

Главная задача — разбор импульса к trim-mean/MAX и непробитых LVL-зон, затем CALL/PUT/NONE.
Зоны уже посчитаны платформой (impulse_lvl_summary). Не выдумывай далёкие уровни.
live_setups — подсказка детектора 47, не обязательный фильтр: ты сам решаешь.

Данные в запросе:
- impulse_lvl_summary:
  active_zones / touching_zones — непробитые LVL (tf, kind support|resistance, zone_min/max, touching),
  live_setups — хиты профилей/MAX на текущем баре (может быть пусто),
  rule_reminder — кратко правила 47
- closed_candles_m1, partial_candle_m1 — M1
- closed_candles_s5, partial_candle_s5 — S5
- mtf.m1_closed, m5_closed, m15_closed, h1_closed — старшие ТФ (если mtf.enabled)
- expiry_focus_sec: 60
- user_question — вопрос трейдера (может быть null)

Правила стратегии (как бот 47; сторону бери из impulse_lvl_summary):
1) Импульс: тело (или live-фитиль) сильно больше trim-mean × множитель профиля, либо MAX-ratio к истории.
2) LVL: цена касается непробитой зоны. bounce — против импульса; continuation — по импульсу. Точные CALL/PUT — в rule_reminder и live_setups.side.
3) ТФ зон: M1 body_to_wick, M5/M15/M30 wick_tip. Старший ТФ важнее при близких timestamps.
4) Экспирация 60 секунд. При сомнении, флэте или конфликте MTF — NONE.

Обязательно в тексте анализа:
1) Есть ли импульс и есть ли touching_zones (явно: какой tf / kind).
2) Согласованность MTF и live_setups (если список не пуст — сверься, но не копируй слепо).
3) Явная рекомендация: CALL, PUT или NONE.
4) Горизонт 60 секунд при CALL/PUT.
5) Строка «Вероятность успеха: NN%».
PROMPT
            .self::promptInstructionsWithImpulseLvl();
    }

    /** Полный режим Sweep/Raid: подтверждение SWEEP/RAID/PB + уровни на график. */
    public static function defaultAnalystSystemPromptWithSweepRaid(): string
    {
        return <<<'PROMPT'
Ты торговый AI-аналитик для бинарных опционов по стратегии Sweep / Raid Liquidity.
Целевая экспирация: 60 секунд. Тайминг — закрытая M1 (+ S5 для микроконтекста).

Главная задача — подтвердить или отклонить уже найденные сетапы из sweep_raid_summary.
Детектор платформы уже разметил FH/FL, SWEEP, RAID и PB. Ты НЕ изобретаешь новые уровни «с нуля».

Данные в запросе:
- sweep_raid_summary:
  candidates — сетапы на последней закрытой M1 (подтверждать только их side),
  recent_events / recent_pullbacks — контекст серии,
  active_levels — FH/FL,
  rule_reminder — правила автора
- closed_candles_m1, partial_candle_m1 — M1
- closed_candles_s5, partial_candle_s5 — S5
- mtf.* — старшие ТФ (если mtf.enabled) только как фильтр качества, не как источник входа
- expiry_focus_sec: 60
- user_question — вопрос трейдера (может быть null)

Правила стратегии (кратко):
1) Свип хая: зелёная свеча, тень выше FH, close ниже → PUT (вход на свипе).
2) Свип лоу: красная свеча, тень ниже FL, close выше → CALL.
3) Рейд: снятие без цвета свипа или возврат после закрепления телом — вход НЕ на рейде, а на первой откатной.
4) Откат сверху: первая зелёная после снятия → PUT; снизу: первая красная → CALL.
5) Два закрытия подряд за уровнем = уровень съеден → NONE, нужен новый фрактал.

Обязательно в тексте анализа:
1) Есть ли кандидат в candidates (явно: какой setup / side) или список пуст.
2) Подтверждаешь или отклоняешь (почему: цвет, закрепление, конфликт MTF/шум).
3) Явная рекомендация: CALL, PUT или NONE.
4) Горизонт 60 секунд при CALL/PUT.
5) Строка «Вероятность успеха: NN%».
PROMPT
            .self::promptInstructionsWithSweepRaid();
    }

    /** Аналитик без SMC Flow: MTF + S5, как до интеграции индикатора. */
    public static function defaultAnalystSystemPromptClassic(): string
    {
        return <<<'PROMPT'
Ты торговый AI-аналитик для бинарных опционов.
Работаешь по MTF-логике: H1 -> M15 -> M5 -> M1, плюс секундный поток S5 для тайминга.

Главная задача — разбор графика и рыночной структуры.

Данные в запросе:
- closed_candles_m1, partial_candle_m1 — минутки M1
- closed_candles_s5, partial_candle_s5 — секундные S5 (5 сек); микроструктура и тайминг внутри текущей M1
- mtf.m1_closed, m5_closed, m15_closed, h1_closed — старшие ТФ (если mtf.enabled)
- user_question — дополнительный вопрос трейдера (может быть null)
PROMPT
            .self::promptInstructionsClassic();
    }

    public static function defaultAnalystSystemPromptWithSmcFlow(): string
    {
        return <<<'PROMPT'
Ты торговый AI-аналитик для бинарных опционов.
Целевая экспирация сделок: 3–6 минут (чаще всего). Тайминг входа — S5 + M1; контекст — M5/M15/H1.

Работаешь по MTF-логике: H1 -> M15 -> M5 -> M1, плюс секундный поток S5 и компактный снимок SMC Flow (M1).

Главная задача — разбор графика, smart money структуры и согласованности сигналов SMC Flow.

Данные в запросе:
- closed_candles_m1, partial_candle_m1 — минутки M1
- closed_candles_s5, partial_candle_s5 — секундные S5 (5 сек); микроструктура и тайминг внутри текущей M1
- smc_flow_summary — компактный снимок индикатора SMC Flow (M1, настройки бота):
  ut_bot (Buy/Sell), hull.bias, structure (BoS/CHoCH, swings), fvg_zones, order_blocks,
  three_bar_reversal, reversal_signals, rolling_levels, session
- mtf.m1_closed, m5_closed, m15_closed, h1_closed — старшие ТФ (если mtf.enabled)
- expiry_focus_min: [3, 6] — ориентир длительности опциона
- user_question — дополнительный вопрос трейдера (может быть null)

Обязательно в тексте анализа:
1) Согласованы ли MTF-структура и smc_flow_summary (явно: «согласованы» / «не согласованы» + почему).
2) Явная торговая рекомендация: CALL, PUT или NONE (если NONE — краткая причина).
3) Точка входа и горизонт 3–6 мин, если даёшь CALL/PUT.
4) Вероятность успеха анализа в процентах (целое число 0–100) отдельной строкой: «Вероятность успеха: NN%».
PROMPT
            .self::promptInstructionsWithSmcFlow();
    }

    /** Формат ответа для классического аналитика (без SMC Flow). */
    public static function promptInstructionsClassic(): string
    {
        return <<<'TXT'

Формат ответа (обязательно):
1) Связный текст на русском: H1 → M15 → M5 → M1 → уровни → сценарии.
   Торговая рекомендация (CALL/PUT) опциональна — только при явном согласованном сетапе.
TXT
            .self::successProbabilityInstructionBlock()
            .<<<'TXT'
2) В самом конце ответа — отдельный блок для отрисовки на графике (после текста, без markdown вокруг JSON внутри блока):

TXT
            .self::chartLevelsFenceExample(includeZones: false, includeEntry: false)
            .self::chartLevelsRulesBlock();
    }

    /**
     * Обязательная строка с оценкой вероятности успеха анализа в процентах (для текстовых ответов аналитика).
     */
    private static function successProbabilityInstructionBlock(): string
    {
        return <<<'TXT'

Вероятность успеха анализа:
- Оцени вероятность успеха анализа / рекомендуемого сценария (CALL/PUT) в процентах: целое число 0–100.
- В тексте обязательно укажи отдельной строкой: «Вероятность успеха: NN%».
- Строку с вероятностью размести в конце текстового блока, непосредственно перед блоком chart_levels (и перед «Рекомендация: ...», если она есть).

TXT;
    }

    /**
     * Пример JSON в fence и правила label — общие для classic и SMC Flow.
     */
    private static function chartLevelsFenceExample(bool $includeZones = true, bool $includeEntry = true): string
    {
        $zones = $includeZones
            ? <<<'JSON'

  "zones": [
    {
      "price_high": 1.08610,
      "price_low": 1.08520,
      "timeframe": "M15",
      "label": "M15 зона · 1.08520–1.08610"
    }
  ],
JSON
            : "\n  \"zones\": [],\n";

        $entry = $includeEntry
            ? <<<'JSON'

  "entry": {
    "price": 1.08550,
    "side": "CALL",
    "label": "Вход CALL · 1.08550"
  }
JSON
            : "\n  \"entry\": null";

        return <<<TXT

```chart_levels
{
  "levels": [
    {
      "price": 1.08542,
      "kind": "support",
      "timeframe": "H1",
      "label": "H1 поддержка · 1.08542",
      "style": "solid"
    }
  ],{$zones}{$entry}
}
```
TXT;
    }

    /** Правила поля label и структуры chart_levels. */
    private static function chartLevelsRulesBlock(): string
    {
        return <<<'TXT'

Правила chart_levels:
- Блок только внутри ```chart_levels ... ``` (закрывающие ``` обязательны).
- Корень JSON — объект { ... }, не массив [ ... ]; первый символ после открытия блока — {.
- price / price_high / price_low — числа из данных свечей (не выдумывай далёкие уровни).
- kind: support | resistance | pivot | range_high | range_low | entry
- timeframe: H1 | M15 | M5 | M1 (опционально)
- style: solid | dashed (опционально)
- levels и zones — все значимые уровни, которые упомянул в тексте; лимита по количеству нет.
- entry — при рекомендации CALL/PUT укажи price и side; при NONE — null или не указывай поле.

Правила поля label (обязательно, для подписей на графике и в Telegram):
- label всегда на русском языке (не на английском).
- В label обязательно укажи те же числовые значения, что в price / price_low / price_high — цифры должны совпадать с полями JSON.
- Формат levels: «{TF} {смысл} · {price}» — примеры: «H1 поддержка · 1.08542», «M1 сопротивление · 1.08710», «M5 пивот · 1.08600».
- Смысл (поддержка / сопротивление / пивот / максимум диапазона / минимум диапазона) — кратко по-русски, согласованно с kind.
- Формат zones: «{TF} зона · {price_low}–{price_high}» — пример: «M15 зона · 1.08520–1.08610».
- Формат entry: «Вход CALL · {price}» или «Вход PUT · {price}».
- Не дублируй price только в поле JSON без цифр в label — цифра в label обязательна.
TXT;
    }

    /**
     * Инструкция для system prompt: текст анализа + JSON-уровни в конце (с SMC Flow).
     */
    public static function promptInstructions(): string
    {
        return self::promptInstructionsWithSmcFlow();
    }

    public static function promptInstructionsWithSmcFlow(): string
    {
        return <<<'TXT'

Формат ответа (обязательно):
1) Связный текст на русском: H1 → M15 → M5 → M1 → SMC Flow → согласованность → уровни → сценарий на 3–6 мин.
   В конце текстового блока — строка «Рекомендация: CALL» или «Рекомендация: PUT» или «Рекомендация: NONE (причина)».
TXT
            .self::successProbabilityInstructionBlock()
            .<<<'TXT'
2) В самом конце ответа — отдельный блок для отрисовки на графике (после текста, без markdown вокруг JSON внутри блока):

TXT
            .self::chartLevelsFenceExample(includeZones: true, includeEntry: true)
            .self::chartLevelsRulesBlock();
    }

    /** Формат ответа для аналитика Sweep/Raid (уровни FH/FL + entry 60с). */
    public static function promptInstructionsWithSweepRaid(): string
    {
        return <<<'TXT'

Формат ответа (обязательно):
1) Связный текст на русском: candidates → правило свип/рейд/PB → качество контекста → сценарий на 60 с.
   В конце текстового блока — строка «Рекомендация: CALL» или «Рекомендация: PUT» или «Рекомендация: NONE (причина)».
TXT
            .self::successProbabilityInstructionBlock()
            .<<<'TXT'
2) В самом конце ответа — отдельный блок для отрисовки на графике (после текста, без markdown вокруг JSON внутри блока):

TXT
            .self::chartLevelsFenceExample(includeZones: false, includeEntry: true)
            .self::chartLevelsRulesBlock()
            .<<<'TXT'

Дополнительно для Sweep/Raid:
- В levels указывай подтверждённые FH/FL из sweep_raid_summary.active_levels (label вида «M1 FH · price» / «M1 FL · price»).
- entry.price бери из кандидата (entry_price), side — только CALL или PUT из candidates.
- Не рисуй уровни, которых нет в snapshot.
TXT;
    }

    /** Формат ответа Impulse LVL: зоны LVL + entry 60с. */
    public static function promptInstructionsWithImpulseLvl(): string
    {
        return <<<'TXT'

Формат ответа (обязательно):
1) Связный текст на русском: H1 → M15 → M5 → M1 → Impulse LVL зоны → импульс → сценарий на 60 с.
   В конце текстового блока — строка «Рекомендация: CALL» или «Рекомендация: PUT» или «Рекомендация: NONE (причина)».
TXT
            .self::successProbabilityInstructionBlock()
            .<<<'TXT'
2) В самом конце ответа — отдельный блок для отрисовки на графике (после текста, без markdown вокруг JSON внутри блока):

TXT
            .self::chartLevelsFenceExample(includeZones: true, includeEntry: true)
            .self::chartLevelsRulesBlock()
            .<<<'TXT'

Дополнительно для Impulse LVL:
- В levels/zones копируй цены из impulse_lvl_summary.active_zones (label вида «M15 сопротивление · price» / «M5 зона · low–high»).
- timeframe: H1 | M30 | M15 | M5 | M1.
- entry.price — close или край касаемой зоны; side только CALL или PUT (как live_setups / rule_reminder, bounce или continuation).
- Не рисуй уровни, которых нет в snapshot.
TXT;
    }

    /** Формат ответа для аналитика Engulfing (entry 60с). */
    public static function promptInstructionsWithEngulf(): string
    {
        return <<<'TXT'

Формат ответа (обязательно):
1) Связный текст на русском: candidates → цепочка ENGULF 1/2/3 → качество контекста → сценарий на 60 с.
   В конце текстового блока — строка «Рекомендация: CALL» или «Рекомендация: PUT» или «Рекомендация: NONE (причина)».
TXT
            .self::successProbabilityInstructionBlock()
            .<<<'TXT'
2) В самом конце ответа — отдельный блок для отрисовки на графике (после текста, без markdown вокруг JSON внутри блока):

TXT
            .self::chartLevelsFenceExample(includeZones: false, includeEntry: true)
            .self::chartLevelsRulesBlock()
            .<<<'TXT'

Дополнительно для Engulfing:
- entry.price бери из кандидата (entry_price), side — только CALL или PUT из candidates.
- В levels можно указать цену входа (kind entry) с label «M1 вход · price».
- Не рисуй уровни, которых нет в snapshot.
TXT;
    }

    /**
     * Отделяет читаемый текст от блока chart_levels.
     *
     * @return array{
     *   analysis_text:string,
     *   chart_levels_raw:?string,
     *   fence_found:bool
     * }
     */
    public static function splitAssistantResponse(string $raw): array
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return [
                'analysis_text' => '',
                'chart_levels_raw' => null,
                'fence_found' => false,
            ];
        }

        // ```chart_levels ... ``` (закрытый fence)
        $patternClosed = '/```(?:'.preg_quote(self::FENCE_TAG, '/').'|json\s+'.preg_quote(self::FENCE_TAG, '/').')\s*\r?\n([\s\S]*?)\r?\n```/iu';
        if (preg_match($patternClosed, $trimmed, $matches, PREG_OFFSET_CAPTURE)) {
            return self::splitResult($trimmed, (int) $matches[0][1], (string) ($matches[1][0] ?? ''));
        }

        // Обрезанный ответ: открывающий ```chart_levels без закрывающих ```
        $patternOpenFence = '/```(?:'.preg_quote(self::FENCE_TAG, '/').'|json\s+'.preg_quote(self::FENCE_TAG, '/').')\s*\r?\n([\s\S]*)$/iu';
        if (preg_match($patternOpenFence, $trimmed, $matches, PREG_OFFSET_CAPTURE)) {
            return self::splitResult($trimmed, (int) $matches[0][1], (string) ($matches[1][0] ?? ''));
        }

        // Без fence: «chart_levels:» или «chart_levels» + JSON в конце (частая ошибка Llama и др.)
        $patternPlain = '/\r?\n\s*#*\s*'.preg_quote(self::FENCE_TAG, '/').'\s*:?\s*\r?\n([\s\S]+)$/iu';
        if (preg_match($patternPlain, $trimmed, $matches, PREG_OFFSET_CAPTURE)) {
            return self::splitResult($trimmed, (int) $matches[0][1], (string) ($matches[1][0] ?? ''));
        }

        return [
            'analysis_text' => $trimmed,
            'chart_levels_raw' => null,
            'fence_found' => false,
        ];
    }

    /**
     * @return array{analysis_text:string,chart_levels_raw:?string,fence_found:bool}
     */
    private static function splitResult(string $full, int $blockOffset, string $jsonRaw): array
    {
        $analysisText = trim(substr($full, 0, $blockOffset));
        $jsonRaw = trim($jsonRaw);

        return [
            'analysis_text' => $analysisText,
            'chart_levels_raw' => $jsonRaw !== '' ? $jsonRaw : null,
            'fence_found' => true,
        ];
    }

    /**
     * @return array{
     *   ok:bool,
     *   message:?string,
     *   data:array{levels:list<array<string,mixed>>,zones:list<array<string,mixed>>,entry:?array<string,mixed>}
     * }
     */
    public static function normalize(?string $jsonRaw, bool $fenceFound): array
    {
        $empty = [
            'ok' => false,
            'message' => null,
            'data' => [
                'levels' => [],
                'zones' => [],
                'entry' => null,
            ],
        ];

        if (! $fenceFound) {
            return $empty;
        }

        if ($jsonRaw === null || trim($jsonRaw) === '') {
            return [
                'ok' => false,
                'message' => 'уровни: не распознаны',
                'data' => $empty['data'],
            ];
        }

        $decoded = self::decodeChartLevelsPayload($jsonRaw);
        if ($decoded === null) {
            return [
                'ok' => false,
                'message' => 'уровни: не распознаны',
                'data' => $empty['data'],
            ];
        }

        $levels = self::normalizeLevels($decoded['levels'] ?? null);
        $zones = self::normalizeZones($decoded['zones'] ?? null);
        $entry = self::normalizeEntry($decoded['entry'] ?? null);

        if ($levels === [] && $zones === [] && $entry === null) {
            return [
                'ok' => false,
                'message' => 'уровни: не распознаны',
                'data' => $empty['data'],
            ];
        }

        return [
            'ok' => true,
            'message' => null,
            'data' => [
                'levels' => $levels,
                'zones' => $zones,
                'entry' => $entry,
            ],
        ];
    }

    /**
     * Пытается распарсить JSON уровней: нормальный decode, починка типичных ошибок LLM, salvage из обрезка.
     *
     * @return array<string, mixed>|null
     */
    private static function decodeChartLevelsPayload(string $jsonRaw): ?array
    {
        $trimmed = trim($jsonRaw);
        if ($trimmed === '') {
            return null;
        }

        $repaired = self::repairJsonRaw($trimmed);
        $candidates = array_unique(array_filter([
            $trimmed,
            $repaired,
            self::tryCloseTruncatedJson($repaired),
            self::tryCloseTruncatedJson($trimmed),
        ], static fn (string $s): bool => $s !== ''));

        foreach ($candidates as $candidate) {
            try {
                $decoded = json_decode($candidate, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    return $decoded;
                }
            } catch (\JsonException) {
                continue;
            }
        }

        return self::salvageChartLevelsPayload($trimmed);
    }

    /** Типичные ошибки моделей: `[ "levels":` вместо `{ "levels":`, лишние backticks. */
    private static function repairJsonRaw(string $jsonRaw): string
    {
        $s = trim($jsonRaw);
        $s = preg_replace('/^```(?:json)?\s*/i', '', $s) ?? $s;
        $s = preg_replace('/\s*```$/', '', $s) ?? $s;
        $s = trim($s);

        // Llama иногда оборачивает объект в массив: [ "levels": [ ... ] ]
        if (preg_match('/^\[\s*"levels"\s*:/s', $s)) {
            $s = preg_replace('/^\[\s*/', '{', $s, 1) ?? $s;
            if (preg_match('/\]\s*$/', $s)) {
                $s = preg_replace('/\]\s*$/', '}', $s, 1) ?? $s;
            }
        }

        return $s;
    }

    /** Дозакрывает обрезанный JSON (max_tokens / обрыв на середине объекта). */
    private static function tryCloseTruncatedJson(string $jsonRaw): string
    {
        $candidate = rtrim(rtrim($jsonRaw), ',');
        for ($attempt = 0; $attempt < 10; $attempt++) {
            try {
                $decoded = json_decode($candidate, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    return $candidate;
                }
            } catch (\JsonException) {
            }

            $needCurly = substr_count($candidate, '{') - substr_count($candidate, '}');
            $needSquare = substr_count($candidate, '[') - substr_count($candidate, ']');
            if ($needCurly > 0) {
                $candidate .= '}';
            } elseif ($needSquare > 0) {
                $candidate .= ']';
            } else {
                break;
            }
        }

        return $candidate;
    }

    /**
     * Извлекает уровни/зоны из невалидного JSON по полям price / price_high+price_low.
     *
     * @return array<string, mixed>|null
     */
    private static function salvageChartLevelsPayload(string $raw): ?array
    {
        $levels = [];
        if (preg_match_all('/\{[^{}]*"price"\s*:\s*[0-9]+(?:\.[0-9]+)?[^{}]*\}/s', $raw, $levelMatches)) {
            foreach ($levelMatches[0] as $blob) {
                try {
                    $item = json_decode($blob, true, 64, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    continue;
                }
                if (is_array($item) && isset($item['price']) && is_numeric($item['price'])) {
                    $levels[] = $item;
                }
            }
        }

        $zones = [];
        if (preg_match_all('/\{[^{}]*"price_high"\s*:\s*[0-9]+(?:\.[0-9]+)?[^{}]*"price_low"\s*:\s*[0-9]+(?:\.[0-9]+)?[^{}]*\}/s', $raw, $zoneMatches)) {
            foreach ($zoneMatches[0] as $blob) {
                try {
                    $item = json_decode($blob, true, 64, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    continue;
                }
                if (is_array($item) && isset($item['price_high'], $item['price_low'])) {
                    $zones[] = $item;
                }
            }
        }

        $entry = null;
        if (preg_match('/\{[^{}]*"price"\s*:\s*[0-9]+(?:\.[0-9]+)?[^{}]*"side"\s*:\s*"(?:CALL|PUT)"[^{}]*\}/s', $raw, $entryMatch)) {
            try {
                $parsedEntry = json_decode($entryMatch[0], true, 64, JSON_THROW_ON_ERROR);
                if (is_array($parsedEntry)) {
                    $entry = $parsedEntry;
                }
            } catch (\JsonException) {
            }
        }

        if ($levels === [] && $zones === [] && $entry === null) {
            return null;
        }

        // Обрезанный объект уровня без закрывающей }: { "price": 1.4, "kind": "resistance",
        if (preg_match_all(
            '/\{\s*"price"\s*:\s*([0-9]+(?:\.[0-9]+)?)\s*,\s*"kind"\s*:\s*"([^"]+)"(?:\s*,\s*"timeframe"\s*:\s*"([^"]+)")?(?:\s*,\s*"label"\s*:\s*"((?:[^"\\\\]|\\\\.)*)")?(?:\s*,\s*"style"\s*:\s*"([^"]+)")?\s*(?:,|\s*$)/m',
            $raw,
            $partialMatches,
            PREG_SET_ORDER
        )) {
            foreach ($partialMatches as $m) {
                $price = (float) $m[1];
                $already = false;
                foreach ($levels as $existing) {
                    if (is_array($existing) && isset($existing['price']) && (float) $existing['price'] === $price) {
                        $already = true;
                        break;
                    }
                }
                if ($already) {
                    continue;
                }
                $item = [
                    'price' => $price,
                    'kind' => $m[2],
                ];
                if (! empty($m[3])) {
                    $item['timeframe'] = $m[3];
                }
                if (isset($m[4]) && $m[4] !== '') {
                    $item['label'] = $m[4];
                }
                if (! empty($m[5])) {
                    $item['style'] = $m[5];
                }
                $levels[] = $item;
            }
        }

        if ($levels === [] && $zones === [] && $entry === null) {
            return null;
        }

        return [
            'levels' => $levels,
            'zones' => $zones,
            'entry' => $entry,
        ];
    }

    /**
     * @return list<array{price:float,kind:string,timeframe:?string,label:string,style:string}>
     */
    private static function normalizeLevels(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            if (! is_array($item)) {
                continue;
            }
            $price = self::toFloat($item['price'] ?? $item['price_level'] ?? $item['level'] ?? null);
            if ($price === null) {
                continue;
            }
            $kind = self::normalizeKind((string) ($item['kind'] ?? $item['type'] ?? $item['role'] ?? 'level'));
            $out[] = [
                'price' => $price,
                'kind' => $kind,
                'timeframe' => self::normalizeTimeframe($item['timeframe'] ?? null),
                'label' => self::normalizeLevelLabel(
                    (string) ($item['label'] ?? $item['name'] ?? ''),
                    $kind,
                    $price,
                    self::normalizeTimeframe($item['timeframe'] ?? null),
                ),
                'style' => self::normalizeStyle((string) ($item['style'] ?? '')),
            ];
        }

        return $out;
    }

    /**
     * @return list<array{price_high:float,price_low:float,timeframe:?string,label:string}>
     */
    private static function normalizeZones(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            if (! is_array($item)) {
                continue;
            }
            $high = self::toFloat($item['price_high'] ?? $item['high'] ?? null);
            $low = self::toFloat($item['price_low'] ?? $item['low'] ?? null);
            if ($high === null || $low === null) {
                continue;
            }
            if ($high < $low) {
                [$high, $low] = [$low, $high];
            }
            $out[] = [
                'price_high' => $high,
                'price_low' => $low,
                'timeframe' => self::normalizeTimeframe($item['timeframe'] ?? null),
                'label' => self::normalizeZoneLabel(
                    (string) ($item['label'] ?? $item['name'] ?? ''),
                    self::normalizeTimeframe($item['timeframe'] ?? null),
                    $low,
                    $high,
                ),
            ];
        }

        return $out;
    }

    /**
     * @return array{price:float,side:?string,label:string}|null
     */
    private static function normalizeEntry(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }
        $price = self::toFloat($raw['price'] ?? $raw['price_level'] ?? null);
        if ($price === null) {
            return null;
        }
        $sideRaw = strtoupper(trim((string) ($raw['side'] ?? '')));
        $side = in_array($sideRaw, ['CALL', 'PUT'], true) ? $sideRaw : null;

        return [
            'price' => $price,
            'side' => $side,
            'label' => self::normalizeEntryLabel((string) ($raw['label'] ?? $raw['name'] ?? ''), $side, $price),
        ];
    }

    private static function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value)) {
            return null;
        }
        $f = (float) $value;

        return is_finite($f) ? $f : null;
    }

    private static function normalizeKind(string $kind): string
    {
        $k = strtolower(trim($kind));
        $allowed = ['support', 'resistance', 'pivot', 'range_high', 'range_low', 'entry', 'level'];

        return in_array($k, $allowed, true) ? $k : 'level';
    }

    private static function normalizeStyle(string $style): string
    {
        $s = strtolower(trim($style));

        return $s === 'dashed' ? 'dashed' : 'solid';
    }

    private static function normalizeTimeframe(mixed $tf): ?string
    {
        if ($tf === null || $tf === '') {
            return null;
        }
        $t = strtoupper(trim((string) $tf));
        $map = [
            'H1' => 'H1',
            '1H' => 'H1',
            '60' => 'H1',
            'M15' => 'M15',
            '15' => 'M15',
            'M5' => 'M5',
            '5' => 'M5',
            'M1' => 'M1',
            '1' => 'M1',
        ];

        return $map[$t] ?? (preg_match('/^(H1|M15|M5|M1)$/i', $t) ? strtoupper($t) : null);
    }

    private static function normalizeLevelLabel(string $label, string $kind, float $price, ?string $timeframe): string
    {
        $label = trim($label);
        $priceStr = self::formatPriceLabel($price);
        if ($label === '') {
            return mb_substr(self::defaultLevelLabelRu($kind, $timeframe, $priceStr), 0, 96);
        }
        if (! self::labelContainsPrice($label, $price)) {
            $label = $label.' · '.$priceStr;
        }

        return mb_substr($label, 0, 96);
    }

    private static function normalizeZoneLabel(string $label, ?string $timeframe, float $low, float $high): string
    {
        $label = trim($label);
        $lowStr = self::formatPriceLabel($low);
        $highStr = self::formatPriceLabel($high);
        $rangeStr = $lowStr.'–'.$highStr;
        if ($label === '') {
            $tf = $timeframe !== null && $timeframe !== '' ? $timeframe.' ' : '';

            return mb_substr($tf.'зона · '.$rangeStr, 0, 96);
        }
        if (! str_contains($label, $lowStr) && ! str_contains($label, $highStr)) {
            $label = $label.' · '.$rangeStr;
        }

        return mb_substr($label, 0, 96);
    }

    private static function normalizeEntryLabel(string $label, ?string $side, float $price): string
    {
        $label = trim($label);
        $priceStr = self::formatPriceLabel($price);
        if ($label === '') {
            $sideLabel = $side === 'PUT' ? 'PUT' : ($side === 'CALL' ? 'CALL' : '');

            return mb_substr('Вход'.($sideLabel !== '' ? ' '.$sideLabel : '').' · '.$priceStr, 0, 96);
        }
        if (! self::labelContainsPrice($label, $price)) {
            $label = $label.' · '.$priceStr;
        }

        return mb_substr($label, 0, 96);
    }

    private static function defaultLevelLabelRu(string $kind, ?string $timeframe, string $priceStr): string
    {
        $tf = $timeframe !== null && $timeframe !== '' ? $timeframe.' ' : '';
        $kindRu = match ($kind) {
            'support' => 'поддержка',
            'resistance' => 'сопротивление',
            'pivot' => 'пивот',
            'range_high' => 'макс. диапазона',
            'range_low' => 'мин. диапазона',
            'entry' => 'вход',
            default => 'уровень',
        };

        return $tf.$kindRu.' · '.$priceStr;
    }

    private static function formatPriceLabel(float $price): string
    {
        if ($price >= 1000) {
            return number_format($price, 2, '.', '');
        }
        if ($price >= 10) {
            return rtrim(rtrim(number_format($price, 4, '.', ''), '0'), '.');
        }

        return rtrim(rtrim(number_format($price, 5, '.', ''), '0'), '.');
    }

    private static function labelContainsPrice(string $label, float $price): bool
    {
        $formatted = self::formatPriceLabel($price);
        if (str_contains($label, $formatted)) {
            return true;
        }
        // LLM иногда пишет лишние/меньше знаков — проверяем укороченный префикс.
        $compact = rtrim(rtrim(number_format($price, 8, '.', ''), '0'), '.');

        return $compact !== '' && str_contains($label, $compact);
    }
}
