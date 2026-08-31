<?php

namespace App\Support;

/**
 * Гибридный режим Chart Analyst (openrouter_llm_analyst):
 * при сигнале выбранного source-бота запускает тот же LLM-анализ,
 * дополняя payload полным decision source. Опционально paper/PO на bot_id аналитика.
 */
final class HybridAnalystConfig
{
    public const TRIGGER_ON_SIGNAL = 'on_signal';

    public const TRIGGER_ON_PO_MIRROR = 'on_po_mirror';

    /** @var list<string> */
    public const TRIGGERS = [
        self::TRIGGER_ON_SIGNAL,
        self::TRIGGER_ON_PO_MIRROR,
    ];

    public const PUBLISH_ALWAYS = 'always';

    public const PUBLISH_AGREE_SOURCE = 'agree_source';

    public const PUBLISH_CALL_PUT_ONLY = 'call_put_only';

    /** @var list<string> */
    public const PUBLISH_MODES = [
        self::PUBLISH_ALWAYS,
        self::PUBLISH_AGREE_SOURCE,
        self::PUBLISH_CALL_PUT_ONLY,
    ];

    /** Фиксированный source bot_id. */
    public const SOURCE_MODE_FIXED = 'fixed';

    /** Динамически — бот с лучшим paper winrate за окно. */
    public const SOURCE_MODE_BEST_WINRATE = 'best_winrate';

    /** @var list<string> */
    public const SOURCE_MODES = [
        self::SOURCE_MODE_FIXED,
        self::SOURCE_MODE_BEST_WINRATE,
    ];

    /** Окна рейтинга лидера (секунды). */
    public const LEADER_WINDOW_30M = 1800;

    public const LEADER_WINDOW_1H = 3600;

    public const LEADER_WINDOW_2H = 7200;

    public const LEADER_WINDOW_3H = 10800;

    public const LEADER_WINDOW_6H = 21600;

    /** @var list<int> */
    public const LEADER_WINDOWS = [
        self::LEADER_WINDOW_30M,
        self::LEADER_WINDOW_1H,
        self::LEADER_WINDOW_2H,
        self::LEADER_WINDOW_3H,
        self::LEADER_WINDOW_6H,
    ];

    public const LEADER_MIN_TRADES_DEFAULT = 10;

    /** Кэш пересчёта лидера (сек) — чтобы не бить БД на каждый сигнал. */
    public const LEADER_CACHE_TTL_SEC = 60;

    /**
     * Типы ботов, которые не могут быть source для гибрида:
     * Chart Analyst (приёмник) и ручной дашборд (нет авто CALL/PUT).
     *
     * @return list<string>
     */
    public static function excludedSourceBotTypes(): array
    {
        return array_values(array_unique(array_merge(
            ChartAnalystBotTypes::all(),
            [ManualTradeConfig::BOT_TYPE],
        )));
    }

    /** Может ли бот с таким type быть source гибрида. */
    public static function isSourceBotType(string $type): bool
    {
        return ! in_array($type, self::excludedSourceBotTypes(), true);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\Bot>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\Bot>
     */
    public static function applySourceBotQuery($query)
    {
        return $query->whereNotIn('type', self::excludedSourceBotTypes());
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function isEnabled(array $config): bool
    {
        return (bool) ($config['hybrid_enabled'] ?? false);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function sourceMode(array $config): string
    {
        $mode = (string) ($config['hybrid_source_mode'] ?? self::SOURCE_MODE_FIXED);

        return in_array($mode, self::SOURCE_MODES, true)
            ? $mode
            : self::SOURCE_MODE_FIXED;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function isBestWinrateSourceMode(array $config): bool
    {
        return self::sourceMode($config) === self::SOURCE_MODE_BEST_WINRATE;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function sourceBotId(array $config): int
    {
        return max(0, (int) ($config['hybrid_source_bot_id'] ?? 0));
    }

    /**
     * Последний выбранный лидер (для fallback при ничьей / малой выборке).
     *
     * @param  array<string, mixed>  $config
     */
    public static function previousLeaderBotId(array $config): int
    {
        return max(0, (int) ($config['hybrid_leader_bot_id'] ?? 0));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function leaderWindowSec(array $config): int
    {
        $sec = (int) ($config['hybrid_leader_window_sec'] ?? self::LEADER_WINDOW_1H);

        return in_array($sec, self::LEADER_WINDOWS, true)
            ? $sec
            : self::LEADER_WINDOW_1H;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function leaderMinTrades(array $config): int
    {
        return max(1, min(500, (int) ($config['hybrid_leader_min_trades'] ?? self::LEADER_MIN_TRADES_DEFAULT)));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function trigger(array $config): string
    {
        $trigger = (string) ($config['hybrid_trigger'] ?? self::TRIGGER_ON_SIGNAL);

        return in_array($trigger, self::TRIGGERS, true)
            ? $trigger
            : self::TRIGGER_ON_SIGNAL;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function publishMode(array $config): string
    {
        $mode = (string) ($config['hybrid_publish'] ?? self::PUBLISH_ALWAYS);

        return in_array($mode, self::PUBLISH_MODES, true)
            ? $mode
            : self::PUBLISH_ALWAYS;
    }

    /**
     * Мин. «Вероятность успеха» (%) из ответа LLM для Telegram / торговли.
     * 0 = порог выключен (не фильтруем по вероятности).
     *
     * @param  array<string, mixed>  $config
     */
    public static function minSuccessPercent(array $config): int
    {
        return max(0, min(100, (int) ($config['hybrid_min_success_percent'] ?? 0)));
    }

    /**
     * Paper-сделка на Chart Analyst после успешного гибрид-фильтра.
     *
     * @param  array<string, mixed>  $config
     */
    public static function isPaperTradesEnabled(array $config): bool
    {
        return (bool) ($config['hybrid_paper_trades_enabled'] ?? false);
    }

    /**
     * Зеркало paper→PO для гибридных сделок (сумма из профиля «Торговля»).
     *
     * @param  array<string, mixed>  $config
     */
    public static function isMirrorTradesEnabled(array $config): bool
    {
        return (bool) ($config['hybrid_mirror_trades_enabled'] ?? false);
    }

    /**
     * Кнопка «Доп. анализ SMC Flow» под гибрид-алертом и обычным Telegram-ответом Chart Analyst.
     *
     * @param  array<string, mixed>  $config
     */
    public static function isHybridTelegramLlmButtonEnabled(array $config): bool
    {
        if (! array_key_exists('hybrid_telegram_llm_button_enabled', $config)) {
            return true;
        }

        return (bool) $config['hybrid_telegram_llm_button_enabled'];
    }

    /**
     * Expiry гибридной сделки: из source decision / длительности source-сделки.
     * Fallback 180 сек, если в payload нет валидного значения.
     *
     * @param  array<string, mixed>  $sourceSignal
     */
    public static function resolveExpirySecFromSource(array $sourceSignal, ?int $fromTradeSec = null): int
    {
        $fromPayload = (int) ($sourceSignal['expiry_sec'] ?? 0);
        if ($fromPayload >= 60) {
            return max(60, min(3600, $fromPayload));
        }
        if ($fromTradeSec !== null && $fromTradeSec >= 60) {
            return max(60, min(3600, $fromTradeSec));
        }

        return 180;
    }

    /**
     * Секция system prompt: как учитывать сигнал source-бота.
     * Добавляется к активному промпту только в гибридном запросе (runtime).
     *
     * Двухфазный формат снижает якорение на side source: сначала независимый вердикт,
     * потом сверка. Фильтры/торговля читают только итоговую «Рекомендация:».
     */
    public static function systemPromptSection(): string
    {
        return <<<'PROMPT'

---
Гибридный режим (сигнал другого бота):
В JSON есть поле hybrid_source_signal — decision бота первичного анализа
(side, confidence, analysis_text, reasons_short, features, decision_version, bot meta).

СТРОГИЙ ПОРЯДОК ОТВЕТА (две фазы, не нарушай):

Фаза A — независимый вердикт (ДО чтения hybrid_source_signal):
1) Оцени рынок только по своим данным: MTF, S5, SMC Flow (если есть), payout.
2) Пока НЕ смотри hybrid_source_signal (ни side, ни analysis_text, ни reasons).
3) Запиши строку ровно в формате: «Независимый вердикт: CALL» или PUT или NONE.
4) Кратко (1–3 предложения) обоснуй независимый вывод.

Фаза B — сверка с source (ПОСЛЕ фазы A):
5) Только теперь открой hybrid_source_signal и сравни с независимым вердиктом.
6) Напиши: согласен / не согласен с source (укажи имя/тип бота и его side).
7) Итоговая строка ровно: «Рекомендация: CALL» или PUT или NONE — это финальный side для публикации/торговли.
8) Если независимый вердикт противоречит source или сетап слабый — чаще Рекомендация: NONE (или низкая вероятность), кратко объясни расхождение.
9) Не копируй side source слепо и не подгоняй фазу A под source задним числом.
10) Обязательно одна строка «Вероятность успеха: NN%» (целое 0–100) — только после итоговой Рекомендации; по ней фильтруется публикация.

Краткий режим: те же метки по порядку —
«Независимый вердикт: …» → одна фраза сверки → «Рекомендация: …» → «Вероятность успеха: …%».
PROMPT;
    }

    /**
     * Достаёт итоговый CALL|PUT|NONE из текста ответа аналитика.
     * В гибриде приоритет у последней «Рекомендация:» (после независимого вердикта).
     * «Независимый вердикт:» для фильтров/торговли не используется.
     */
    public static function extractRecommendationSide(string $analysisText): ?string
    {
        $text = trim($analysisText);
        if ($text === '') {
            return null;
        }

        // Последнее вхождение — итоговая рекомендация после сверки с source.
        if (preg_match_all('/Рекомендация\s*:\s*(CALL|PUT|NONE)\b/iu', $text, $matches) > 0) {
            $last = $matches[1][count($matches[1]) - 1] ?? null;

            return $last !== null ? strtoupper((string) $last) : null;
        }

        // Fallback: первая явная метка в начале ответа (краткий режим без «Рекомендация:»).
        if (preg_match('/^\s*(CALL|PUT|NONE)\b/iu', $text, $m)) {
            return strtoupper($m[1]);
        }

        return null;
    }

    /**
     * Достаёт «Вероятность успеха: NN%» из текста ответа LLM (0–100).
     * Берём последнее вхождение — в гибриде это вероятность после итоговой Рекомендации.
     */
    public static function extractSuccessPercent(string $analysisText): ?int
    {
        $text = trim($analysisText);
        if ($text === '') {
            return null;
        }

        if (preg_match_all('/Вероятность\s+успеха\s*:\s*(\d{1,3})\s*%/iu', $text, $matches) > 0) {
            $last = $matches[1][count($matches[1]) - 1] ?? null;

            return $last !== null ? max(0, min(100, (int) $last)) : null;
        }

        // Краткий/английский fallback (тоже последнее вхождение).
        if (preg_match_all('/success\s*(?:probability|rate)?\s*[:=]\s*(\d{1,3})\s*%/iu', $text, $matches) > 0) {
            $last = $matches[1][count($matches[1]) - 1] ?? null;

            return $last !== null ? max(0, min(100, (int) $last)) : null;
        }

        return null;
    }

    /**
     * Общий фильтр гибрида (Telegram + торговля): publish mode + мин. вероятность.
     *
     * @param  array<string, mixed>  $config
     */
    public static function shouldPublishToTelegram(array $config, string $analysisText, string $sourceSide): bool
    {
        return self::passesHybridFilters($config, $analysisText, $sourceSide);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function passesHybridFilters(array $config, string $analysisText, string $sourceSide): bool
    {
        $mode = self::publishMode($config);
        $llmSide = self::extractRecommendationSide($analysisText);
        $sourceSide = strtoupper(trim($sourceSide));

        $modeOk = match ($mode) {
            self::PUBLISH_CALL_PUT_ONLY => in_array($llmSide, ['CALL', 'PUT'], true),
            self::PUBLISH_AGREE_SOURCE => $llmSide !== null
                && in_array($sourceSide, ['CALL', 'PUT'], true)
                && $llmSide === $sourceSide,
            default => true,
        };

        if (! $modeOk) {
            return false;
        }

        // Доп. фильтр: мин. вероятность успеха из прогноза LLM (0 = выкл.).
        $minPct = self::minSuccessPercent($config);
        if ($minPct <= 0) {
            return true;
        }

        $successPct = self::extractSuccessPercent($analysisText);
        // Нет строки вероятности при включённом пороге — не публикуем (не угадываем).
        if ($successPct === null) {
            return false;
        }

        return $successPct >= $minPct;
    }

    /**
     * Открывать ли paper: фильтры гибрида + paper вкл + LLM дал CALL/PUT.
     *
     * @param  array<string, mixed>  $config
     */
    public static function shouldOpenPaperTrade(array $config, string $analysisText, string $sourceSide): bool
    {
        if (! self::isPaperTradesEnabled($config)) {
            return false;
        }
        if (! self::passesHybridFilters($config, $analysisText, $sourceSide)) {
            return false;
        }

        $llmSide = self::extractRecommendationSide($analysisText);

        return in_array($llmSide, ['CALL', 'PUT'], true);
    }

    /**
     * Собирает payload hybrid_source_signal из BotSignal / decision.
     *
     * @param  array<string, mixed>  $decision
     * @return array<string, mixed>
     */
    public static function buildSourceSignalPayload(
        int $sourceBotId,
        string $sourceBotName,
        string $sourceBotType,
        int $signalId,
        string $instrument,
        string $side,
        float $confidence,
        array $decision,
        ?int $botTradeId = null,
    ): array {
        return [
            'source_bot_id' => $sourceBotId,
            'source_bot_name' => $sourceBotName,
            'source_bot_type' => $sourceBotType,
            'source_signal_id' => $signalId,
            'source_bot_trade_id' => $botTradeId,
            'instrument' => $instrument,
            'side' => strtoupper($side),
            'confidence' => $confidence,
            'analysis_text' => (string) ($decision['analysis_text'] ?? ''),
            'reasons_short' => is_array($decision['reasons_short'] ?? null)
                ? $decision['reasons_short']
                : [],
            'features' => is_array($decision['features'] ?? null)
                ? $decision['features']
                : [],
            'decision_version' => (string) ($decision['decision_version'] ?? 'v1'),
            'trend' => isset($decision['trend']) ? (string) $decision['trend'] : null,
            'expiry_sec' => isset($decision['expiry_sec']) ? (int) $decision['expiry_sec'] : null,
            'notify_only' => (bool) ($decision['notify_only'] ?? false),
        ];
    }

    /**
     * Текст ответа source-бота для дописывания в Telegram после гибрид-анализа LLM.
     * Plain text (без HTML) — экранирование делает вызывающий код.
     *
     * @param  array<string, mixed>  $sourceSignal
     */
    public static function formatSourceReplyForTelegram(array $sourceSignal): string
    {
        $name = trim((string) ($sourceSignal['source_bot_name'] ?? 'source'));
        $type = trim((string) ($sourceSignal['source_bot_type'] ?? ''));
        $side = strtoupper(trim((string) ($sourceSignal['side'] ?? '')));
        $confidence = isset($sourceSignal['confidence']) ? (float) $sourceSignal['confidence'] : null;
        $analysisText = trim((string) ($sourceSignal['analysis_text'] ?? ''));
        /** @var list<mixed> $reasons */
        $reasons = is_array($sourceSignal['reasons_short'] ?? null)
            ? array_values($sourceSignal['reasons_short'])
            : [];

        $meta = $name;
        if ($type !== '') {
            $meta .= ' ('.$type.')';
        }
        if (in_array($side, ['CALL', 'PUT', 'NONE'], true)) {
            $meta .= ' → '.$side;
        }
        // Confidence у rule-based часто 0..1; если >1 — уже проценты.
        if ($confidence !== null && $confidence > 0) {
            $pct = $confidence <= 1.0 ? (int) round($confidence * 100) : (int) round($confidence);
            $meta .= ' · conf '.$pct.'%';
        }

        $lines = ['📥 Ответ source: '.$meta];
        if ($analysisText !== '') {
            // Ограничиваем, чтобы гибрид-сообщение не раздувалось бесконечно.
            if (mb_strlen($analysisText) > 2500) {
                $analysisText = mb_substr($analysisText, 0, 2500).'…';
            }
            $lines[] = $analysisText;
        }

        $reasonLines = [];
        foreach ($reasons as $reason) {
            $r = trim(is_scalar($reason) ? (string) $reason : '');
            if ($r !== '') {
                $reasonLines[] = '• '.$r;
            }
            if (count($reasonLines) >= 8) {
                break;
            }
        }
        if ($reasonLines !== []) {
            $lines[] = implode("\n", $reasonLines);
        }

        // Если нет ни текста, ни причин — всё равно оставляем meta (side source полезен в чате).
        return implode("\n", $lines);
    }
}
