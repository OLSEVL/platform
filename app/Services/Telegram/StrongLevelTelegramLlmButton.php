<?php

namespace App\Services\Telegram;

use App\Models\Bot;

/**
 * Inline-кнопка «Доп. анализ SMC Flow» на алертах Strong Level и Strong Trend Telegram.
 */
class StrongLevelTelegramLlmButton
{
    /** @var list<string> */
    public const SUPPORTED_BOT_TYPES = ['strong_level', 'strong_trend_telegram', 'candle_reversal_confirm', 'rsi_contrtrend', 'bollinger_contrtrend'];

    public const BUTTON_LABEL = '📊 Доп. анализ SMC Flow';

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
        return in_array($type, self::SUPPORTED_BOT_TYPES, true);
    }

    /**
     * Бот Chart Analyst для LLM-анализа (явный id или первый openrouter_llm_analyst пользователя).
     */
    public static function resolveAnalystBot(Bot $sourceBot): ?Bot
    {
        if (! self::supportsBotType($sourceBot->type)) {
            return null;
        }

        /** @var array<string, mixed> $cfg */
        $cfg = is_array($sourceBot->config) ? $sourceBot->config : [];
        $configuredId = (int) ($cfg['telegram_analyst_bot_id'] ?? 0);

        if ($configuredId > 0) {
            $configured = Bot::query()->find($configuredId);
            if (
                $configured
                && $configured->type === 'openrouter_llm_analyst'
                && $configured->user_id === $sourceBot->user_id
            ) {
                return $configured;
            }
        }

        return Bot::query()
            ->where('user_id', $sourceBot->user_id)
            ->where('type', 'openrouter_llm_analyst')
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
        if ($analystBot->type !== 'openrouter_llm_analyst') {
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
