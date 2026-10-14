<?php

namespace App\Services\Telegram;

use App\Models\Bot;

/**
 * Inline-кнопка «Доп. анализ нейросетью» на алертах Strong Level, CRC, Strong Trend, DeMarker, Sweep / Raid, Engulfing и SMC Flow.
 */
class StrongLevelTelegramLlmButton
{
    /** @var list<string> */
    public const SUPPORTED_BOT_TYPES = \App\Support\LevelBounceBotTypes::CARD_AND_TELEGRAM;

    public const BUTTON_LABEL = '📊 Доп. анализ нейросетью';

    /** Подпись кнопки на веб-карточках (без emoji — компактнее в сетке). */
    public const WEB_BUTTON_LABEL = 'Доп. анализ нейросетью';

    public const CALLBACK_PREFIX = 'sllm:';

    public const DEFAULT_LLM_QUESTION = 'напиши ответ кратко, посчитай процент вероятности успеха анализа';

    /**
     * @param  array<string, mixed>  $cfg
     */
    public static function isEnabled(array $cfg): bool
    {
        if (! array_key_exists('telegram_analyst_llm_enabled', $cfg)) {
            return true;
        }

        return (bool) $cfg['telegram_analyst_llm_enabled'];
    }

    /**
     * @param  array<string, mixed>  $cfg
     */
    public static function llmQuestionFromConfig(array $cfg): string
    {
        $question = trim((string) ($cfg['telegram_analyst_llm_question'] ?? ''));

        return $question !== '' ? $question : self::DEFAULT_LLM_QUESTION;
    }

    public static function supportsBotType(string $type): bool
    {
        // Эти типы шлют ту же кнопку, но не входят в CARD_AND_TELEGRAM (level-bounce).
        return in_array($type, ['strong_trend_telegram', 'strong_trend_demarker', \App\Support\DemarkerDualFastConfig::BOT_TYPE, 'sweep_raid_liquidity', 'engulfing_liquidity', 'smc_flow', \App\Support\ImpulseLvl1minConfig::BOT_TYPE, \App\Support\ImpulseLvl1minConfig::BOT_TYPE_S15], true)
            || in_array($type, self::SUPPORTED_BOT_TYPES, true);
    }

    /**
     * Бот Chart Analyst для LLM-анализа (явный id или первый analyst пользователя).
     */
    public static function resolveAnalystBot(Bot $sourceBot): ?Bot
    {
        if (! self::supportsBotType($sourceBot->type)) {
            return null;
        }

        return self::findAnalystBotForUser($sourceBot);
    }

    /**
     * Chart Analyst для кнопки на веб-карточках: сам бот, если это аналитик,
     * иначе тот же поиск, что у Telegram (даже если тип не шлёт inline-кнопку).
     */
    public static function resolveAnalystBotForCards(Bot $sourceBot): ?Bot
    {
        if (\App\Support\ChartAnalystBotTypes::isAnalyst($sourceBot->type)) {
            return $sourceBot;
        }

        return self::findAnalystBotForUser($sourceBot);
    }

    /**
     * Показывать ли «Доп. анализ нейросетью» на карточках: нужен Chart Analyst
     * и не выключена галка (для типов, у которых она есть в настройках).
     */
    public static function isAvailableOnCards(Bot $sourceBot): bool
    {
        /** @var array<string, mixed> $cfg */
        $cfg = is_array($sourceBot->config) ? $sourceBot->config : [];

        if (\App\Support\ChartAnalystBotTypes::isAnalyst($sourceBot->type)) {
            return \App\Support\HybridAnalystConfig::isHybridTelegramLlmButtonEnabled($cfg);
        }

        if (self::supportsBotType($sourceBot->type) && ! self::isEnabled($cfg)) {
            return false;
        }

        return self::resolveAnalystBotForCards($sourceBot) !== null;
    }

    /**
     * Явный telegram_analyst_bot_id, иначе preferred type, иначе любой Chart Analyst пользователя.
     */
    private static function findAnalystBotForUser(Bot $sourceBot): ?Bot
    {
        /** @var array<string, mixed> $cfg */
        $cfg = is_array($sourceBot->config) ? $sourceBot->config : [];
        $configuredId = (int) ($cfg['telegram_analyst_bot_id'] ?? 0);

        if ($configuredId > 0) {
            $configured = Bot::query()->find($configuredId);
            if (
                $configured
                && \App\Support\ChartAnalystBotTypes::isAnalyst($configured->type)
                && $configured->user_id === $sourceBot->user_id
            ) {
                return $configured;
            }
        }

        $preferType = match ($sourceBot->type) {
            'sweep_raid_liquidity' => \App\Support\ChartAnalystBotTypes::SRL,
            'engulfing_liquidity' => \App\Support\ChartAnalystBotTypes::ENGULF,
            \App\Support\ImpulseLvl1minConfig::BOT_TYPE, \App\Support\ImpulseLvl1minConfig::BOT_TYPE_S15 => \App\Support\ChartAnalystBotTypes::IMPULSE,
            default => \App\Support\ChartAnalystBotTypes::CLASSIC,
        };

        $preferred = Bot::query()
            ->where('user_id', $sourceBot->user_id)
            ->where('type', $preferType)
            ->orderByDesc('is_active')
            ->orderBy('id')
            ->first();
        if ($preferred) {
            return $preferred;
        }

        return Bot::query()
            ->where('user_id', $sourceBot->user_id)
            ->whereIn('type', \App\Support\ChartAnalystBotTypes::all())
            ->orderByDesc('is_active')
            ->orderBy('id')
            ->first();
    }

    public static function encodeCallbackData(int $analystBotId, string $instrument): ?string
    {
        $instrument = trim($instrument);
        if ($analystBotId <= 0 || $instrument === '') {
            return null;
        }

        $data = self::CALLBACK_PREFIX.$analystBotId.':'.$instrument;
        if (strlen($data) > 64) {
            return null;
        }

        return $data;
    }

    /**
     * @return array{analyst_bot_id:int,instrument:string}|null
     */
    public static function parseCallbackData(string $data): ?array
    {
        $data = trim($data);
        if (! str_starts_with($data, self::CALLBACK_PREFIX)) {
            return null;
        }

        $rest = substr($data, strlen(self::CALLBACK_PREFIX));
        $sep = strpos($rest, ':');
        if ($sep === false) {
            return null;
        }

        $analystId = (int) substr($rest, 0, $sep);
        $instrument = trim(substr($rest, $sep + 1));
        if ($analystId <= 0 || $instrument === '') {
            return null;
        }

        return [
            'analyst_bot_id' => $analystId,
            'instrument' => $instrument,
        ];
    }

    /**
     * Inline-кнопка повторного LLM на алертах Chart Analyst (гибрид + обычный Telegram-анализ).
     * Callback тот же (sllm:analystId:instrument), обрабатывает TelegramAnalystHandler.
     *
     * @param  array<string, mixed>  $analystCfg
     * @return array{inline_keyboard:list<list<array{text:string,callback_data:string}>>}|null
     */
    public static function inlineKeyboardMarkupForAnalystSelf(Bot $analystBot, string $instrument, array $analystCfg): ?array
    {
        if (! \App\Support\ChartAnalystBotTypes::isAnalyst($analystBot->type)) {
            return null;
        }

        if (! \App\Support\HybridAnalystConfig::isHybridTelegramLlmButtonEnabled($analystCfg)) {
            return null;
        }

        $callbackData = self::encodeCallbackData($analystBot->id, $instrument);
        if ($callbackData === null) {
            return null;
        }

        return [
            'inline_keyboard' => [[
                [
                    'text' => self::BUTTON_LABEL,
                    'callback_data' => $callbackData,
                ],
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $sourceCfg
     * @return array{inline_keyboard:list<list<array{text:string,callback_data:string}>>}|null
     */
    public static function inlineKeyboardMarkup(array $sourceCfg, Bot $sourceBot, string $instrument): ?array
    {
        if (! self::isEnabled($sourceCfg)) {
            return null;
        }

        $analyst = self::resolveAnalystBot($sourceBot);
        if ($analyst === null) {
            return null;
        }

        $callbackData = self::encodeCallbackData($analyst->id, $instrument);
        if ($callbackData === null) {
            return null;
        }

        return [
            'inline_keyboard' => [[
                [
                    'text' => self::BUTTON_LABEL,
                    'callback_data' => $callbackData,
                ],
            ]],
        ];
    }
}
