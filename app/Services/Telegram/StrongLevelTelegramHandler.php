<?php

namespace App\Services\Telegram;

use App\Jobs\RunTelegramAnalystAnalysisJob;
use App\Models\Bot;
use Illuminate\Support\Facades\Log;

/**
 * Обработчик callback_query для Strong Level / Strong Trend Telegram: кнопка LLM → Chart Analyst.
 */
class StrongLevelTelegramHandler
{
    public function __construct(
        private readonly TelegramAlertService $telegram,
        private readonly TelegramAnalystInstrumentService $instruments,
    ) {}

    public function handleUpdate(Bot $bot, array $update): void
    {
        if (! StrongLevelTelegramLlmButton::supportsBotType($bot->type)) {
            return;
        }

        /** @var array<string, mixed> $cfg */
        $cfg = is_array($bot->config) ? $bot->config : [];
        $token = trim((string) ($cfg['telegram_bot_token'] ?? ''));
        if ($token === '') {
            return;
        }

        if (! isset($update['callback_query']) || ! is_array($update['callback_query'])) {
            return;
        }

        $this->handleCallback($bot, $token, $cfg, $update['callback_query']);
    }

    /**
     * @param  array<string, mixed>  $cfg
     * @param  array<string, mixed>  $callback
     */
    private function handleCallback(Bot $bot, string $token, array $cfg, array $callback): void
    {
        $callbackId = (string) ($callback['id'] ?? '');
        $data = (string) ($callback['data'] ?? '');
        $message = is_array($callback['message'] ?? null) ? $callback['message'] : [];
        $chat = is_array($message['chat'] ?? null) ? $message['chat'] : [];
        $chatId = isset($chat['id']) ? (string) $chat['id'] : '';

        if ($chatId === '' || ! $this->isChatAllowed($cfg, $chatId)) {
            Log::warning('telegram_llm_callback_chat_denied', [
                'bot_id' => $bot->id,
                'chat_id' => $chatId,
                'allowed' => trim((string) ($cfg['telegram_chat_id'] ?? '')),
                'data' => $data,
            ]);
            if ($callbackId !== '') {
                $botLabel = $bot->type === 'strong_trend_telegram' ? 'Strong Trend Telegram' : 'Strong Level';
                $hint = trim((string) ($cfg['telegram_chat_id'] ?? '')) !== ''
                    ? 'Чат не совпадает с chat_id в настройках '.$botLabel
                    : 'Доступ запрещён';
                $this->telegram->answerCallbackQuery($token, $callbackId, $hint);
            }

            return;
        }

        $parsed = StrongLevelTelegramLlmButton::parseCallbackData($data);
        if ($parsed === null) {
            Log::warning('telegram_llm_callback_unknown', ['bot_id' => $bot->id, 'data' => $data]);
            if ($callbackId !== '') {
                $this->telegram->answerCallbackQuery($token, $callbackId, 'Неизвестная кнопка');
            }

            return;
        }

        $analyst = Bot::query()->find($parsed['analyst_bot_id']);
        if (
            $analyst === null
            || $analyst->type !== 'openrouter_llm_analyst'
            || $analyst->user_id !== $bot->user_id
        ) {
            $this->telegram->answerCallbackQuery($token, $callbackId, 'Chart Analyst не найден');
            $settingsHint = $bot->type === 'strong_trend_telegram'
                ? 'Strong Trend Telegram → Telegram'
                : 'Strong Level → Telegram';
            $this->telegram->sendMessage(
                $token,
                $chatId,
                '❌ Бот Chart Analyst не настроен. Укажите его в настройках '.$settingsHint.'.'
            );

            return;
        }

        $analystCreds = $this->telegram->analystTelegramCredentials($analyst);
        if ($analystCreds['token'] === '') {
            $this->telegram->answerCallbackQuery($token, $callbackId, 'Нет token Chart Analyst');
            $this->telegram->sendMessage($token, $chatId, '❌ У Chart Analyst не задан telegram_bot_token.');

            return;
        }

        $instrument = $this->instruments->resolveActiveInstrument($parsed['instrument']);
        if ($instrument === null) {
            $this->telegram->answerCallbackQuery($token, $callbackId, 'Пара не найдена');
            $this->telegram->sendMessage(
                $token,
                $chatId,
                '❌ Инструмент <b>'.htmlspecialchars($parsed['instrument'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</b> не найден.'
            );

            return;
        }

        $question = StrongLevelTelegramLlmButton::llmQuestionFromConfig($cfg);

        try {
            RunTelegramAnalystAnalysisJob::dispatch($analyst->id, $chatId, $instrument, $question);
        } catch (\Throwable $e) {
            Log::error('telegram_llm_dispatch_failed', [
                'source_bot_id' => $bot->id,
                'analyst_bot_id' => $analyst->id,
                'instrument' => $instrument,
                'error' => $e->getMessage(),
            ]);
            $this->telegram->answerCallbackQuery($token, $callbackId, 'Ошибка очереди');
            $this->telegram->sendMessage($token, $chatId, '❌ Не удалось поставить анализ в очередь. Проверьте queue worker telegram-analyst.');

            return;
        }

        $this->telegram->answerCallbackQuery($token, $callbackId, 'Запускаю LLM…');
        $this->telegram->sendMessage(
            $token,
            $chatId,
            '⏳ Запущен LLM-анализ <b>'.htmlspecialchars($instrument, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')."</b>.\n".
            'Ответ придёт от Chart Analyst SMC Flow (обычно 1–3 мин).'
        );
    }

    /**
     * @param  array<string, mixed>  $cfg
     */
    private function isChatAllowed(array $cfg, string $chatId): bool
    {
        $allowedChat = trim((string) ($cfg['telegram_chat_id'] ?? ''));
        if ($allowedChat === '') {
            return true;
        }

        return $allowedChat === $chatId;
    }
}
