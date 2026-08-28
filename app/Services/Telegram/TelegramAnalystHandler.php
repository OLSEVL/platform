<?php

namespace App\Services\Telegram;

use App\Support\ChartAnalystBotTypes;

use App\Jobs\RunTelegramAnalystAnalysisJob;
use App\Models\Bot;
use App\Services\Telegram\StrongLevelTelegramLlmButton;
use App\Support\OpenRouterLlmConfig;
use Illuminate\Support\Facades\Log;

/**
 * Обработчик Telegram-апдейтов для бота openrouter_llm_analyst (меню инструментов → вопрос → анализ).
 */
class TelegramAnalystHandler
{
    public function __construct(
        private readonly TelegramAlertService $telegram,
        private readonly TelegramAnalystInstrumentService $instruments,
        private readonly TelegramAnalystStateStore $state,
    ) {}

    public function handleUpdate(Bot $bot, array $update): void
    {
        if (! ChartAnalystBotTypes::isAnalyst($bot->type)) {
            return;
        }

        $creds = $this->telegram->analystTelegramCredentials($bot);
        if (! $creds['enabled'] || $creds['token'] === '') {
            return;
        }

        $token = $creds['token'];

        if (isset($update['callback_query']) && is_array($update['callback_query'])) {
            $this->handleCallback($bot, $token, $update['callback_query']);

            return;
        }

        if (isset($update['message']) && is_array($update['message'])) {
            $this->handleMessage($bot, $token, $update['message']);
        }
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function handleMessage(Bot $bot, string $token, array $message): void
    {
        $chat = is_array($message['chat'] ?? null) ? $message['chat'] : [];
        $chatId = isset($chat['id']) ? (string) $chat['id'] : '';
        if ($chatId === '') {
            return;
        }

        $text = trim((string) ($message['text'] ?? ''));
        if ($text === '') {
            return;
        }

        $command = strtolower(explode('@', explode(' ', $text)[0] ?? '')[0] ?? '');
        // /stop — отписка от алертов Chart Analyst (даже при выключенной автоподписке).
        if ($command === '/stop') {
            TelegramChatAccess::unsubscribe($bot, $chatId);
            $this->sendReplyText(
                $token,
                $chatId,
                '🔕 Подписка отключена. /start — подписаться снова (если автоподписка включена).',
                TelegramAnalystReplyKeyboard::mainMarkup()
            );

            return;
        }

        if (! TelegramChatAccess::authorizeIncoming($bot, $chatId)) {
            if ($command === '/start' || TelegramAnalystReplyKeyboard::isMenuTrigger($text)) {
                $this->sendReplyText(
                    $token,
                    $chatId,
                    '🔒 Автоподписка выключена. Добавьте этот chat_id в настройках бота на платформе.',
                    TelegramAnalystReplyKeyboard::mainMarkup()
                );
            }

            return;
        }

        if (TelegramAnalystReplyKeyboard::isMenuTrigger($text)) {
            $this->openInstrumentMenu($bot, $token, $chatId);

            return;
        }

        $current = $this->state->get($bot->id, $chatId);
        if ($current['step'] === TelegramAnalystStateStore::STEP_AWAIT_QUESTION && $current['instrument']) {
            $question = TelegramAnalystReplyKeyboard::isSkipTrigger($text) ? '' : $text;
            $this->startAnalysis($bot, $token, $chatId, (string) $current['instrument'], $question);

            return;
        }

        if ($current['step'] === TelegramAnalystStateStore::STEP_ANALYZING) {
            $this->sendReplyText(
                $token,
                $chatId,
                '⏳ Анализ ещё выполняется. Подождите или '.TelegramAnalystReplyKeyboard::menuHint().' для нового выбора.',
                TelegramAnalystReplyKeyboard::mainMarkup()
            );

            return;
        }

        $this->sendReplyText(
            $token,
            $chatId,
            "Привет! Я Chart Analyst SMC Flow.\n\n".
            TelegramAnalystReplyKeyboard::menuHint().' — выбрать инструмент для анализа.',
            TelegramAnalystReplyKeyboard::mainMarkup()
        );
        $this->sendInstrumentMenu($bot, $token, $chatId);
    }

    /**
     * @param  array<string, mixed>  $callback
     */
    private function handleCallback(Bot $bot, string $token, array $callback): void
    {
        $callbackId = (string) ($callback['id'] ?? '');
        $data = (string) ($callback['data'] ?? '');
        $message = is_array($callback['message'] ?? null) ? $callback['message'] : [];
        $chat = is_array($message['chat'] ?? null) ? $message['chat'] : [];
        $chatId = isset($chat['id']) ? (string) $chat['id'] : '';

        // Inline-кнопки меню/инструментов — только для подписчиков (или с автоподпиской).
        if ($chatId === '' || ! TelegramChatAccess::authorizeIncoming($bot, $chatId)) {
            if ($callbackId !== '') {
                $this->telegram->answerCallbackQuery($token, $callbackId, 'Нет доступа');
            }

            return;
        }

        if ($data === 'cmd:menu') {
            $this->openInstrumentMenu($bot, $token, $chatId);
            $this->telegram->answerCallbackQuery($token, $callbackId);

            return;
        }

        if ($data === 'cmd:skip') {
            $current = $this->state->get($bot->id, $chatId);
            if ($current['step'] === TelegramAnalystStateStore::STEP_AWAIT_QUESTION && $current['instrument']) {
                $this->startAnalysis($bot, $token, $chatId, (string) $current['instrument'], '');
                $this->telegram->answerCallbackQuery($token, $callbackId, 'Без вопроса');
            } else {
                $this->telegram->answerCallbackQuery($token, $callbackId, 'Сначала выберите инструмент');
            }

            return;
        }

        // Выбор пары из меню: callback_data = «ins:EURUSD_otc» (имя, не индекс),
        // иначе при обновлении payout индекс указывал бы на другую пару.
        $callbackRaw = $this->instruments->parseInstrumentCallback($data);
        if ($callbackRaw !== null) {
            $instrument = $this->instruments->resolveCallbackSelection($bot, $callbackRaw);
            if ($instrument === null) {
                $this->telegram->answerCallbackQuery($token, $callbackId, 'Инструмент недоступен');
                $this->sendInstrumentMenu($bot, $token, $chatId);

                return;
            }

            /** @var array<string, mixed> $cfg */
            $cfg = is_array($bot->config) ? $bot->config : [];
            // Опция в настройках бота: сразу анализ без шага «ваш вопрос?».
            if (OpenRouterLlmConfig::telegramSkipUserQuestion($cfg)) {
                $this->startAnalysis($bot, $token, $chatId, $instrument, '');
                $this->telegram->answerCallbackQuery($token, $callbackId, $instrument);

                return;
            }

            $this->state->put($bot->id, $chatId, [
                'step' => TelegramAnalystStateStore::STEP_AWAIT_QUESTION,
                'instrument' => $instrument,
            ]);
            $questionText = '❓ <b>Ваш вопрос по '.htmlspecialchars($instrument, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')."</b>?\n\n".
                'Напишите текст, '.TelegramAnalystReplyKeyboard::skipHint().' — анализ без вопроса. '.
                TelegramAnalystReplyKeyboard::menuHint().' — вернуться к списку.';
            $this->sendReplyText($token, $chatId, $questionText, TelegramAnalystReplyKeyboard::awaitQuestionMarkup());
            $this->telegram->answerCallbackQuery($token, $callbackId, $instrument);

            return;
        }

        // Кнопка «Доп. анализ SMC Flow» с гибрид-алерта (sllm:analystId:instrument) — без hybrid_source_signal.
        $parsedLlm = StrongLevelTelegramLlmButton::parseCallbackData($data);
        if ($parsedLlm !== null && $parsedLlm['analyst_bot_id'] === (int) $bot->id) {
            $this->handleSelfLlmButton($bot, $token, $callbackId, $chatId, $parsedLlm);

            return;
        }

        $this->telegram->answerCallbackQuery($token, $callbackId);
    }

    /**
     * Повторный независимый анализ по inline-кнопке под гибрид-сообщением.
     *
     * @param  array{analyst_bot_id:int,instrument:string}  $parsed
     */
    private function handleSelfLlmButton(Bot $bot, string $token, string $callbackId, string $chatId, array $parsed): void
    {
        $instrument = $this->instruments->resolveActiveInstrument($parsed['instrument']);
        if ($instrument === null) {
            $this->telegram->answerCallbackQuery($token, $callbackId, 'Пара не найдена');
            $this->sendReplyText(
                $token,
                $chatId,
                '❌ Инструмент <b>'.htmlspecialchars($parsed['instrument'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</b> не найден.',
                TelegramAnalystReplyKeyboard::mainMarkup()
            );

            return;
        }

        /** @var array<string, mixed> $cfg */
        $cfg = is_array($bot->config) ? $bot->config : [];
        $question = StrongLevelTelegramLlmButton::llmQuestionFromConfig($cfg);

        try {
            RunTelegramAnalystAnalysisJob::dispatch($bot->id, $chatId, $instrument, $question);
        } catch (\Throwable $e) {
            Log::error('telegram_analyst_hybrid_llm_dispatch_failed', [
                'analyst_bot_id' => $bot->id,
                'instrument' => $instrument,
                'error' => $e->getMessage(),
            ]);
            $this->telegram->answerCallbackQuery($token, $callbackId, 'Ошибка очереди');
            $this->sendReplyText(
                $token,
                $chatId,
                '❌ Не удалось поставить анализ в очередь. Проверьте queue worker telegram-analyst.',
                TelegramAnalystReplyKeyboard::mainMarkup()
            );

            return;
        }

        $this->telegram->answerCallbackQuery($token, $callbackId, 'Запускаю LLM…');
        $this->sendReplyText(
            $token,
            $chatId,
            '⏳ Запущен независимый LLM-анализ <b>'.htmlspecialchars($instrument, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')."</b>.\n".
            'Без гибрида и сигнала source (обычно 1–3 мин).',
            TelegramAnalystReplyKeyboard::mainMarkup()
        );
    }

    private function startAnalysis(Bot $bot, string $token, string $chatId, string $instrument, string $question): void
    {
        $this->state->put($bot->id, $chatId, [
            'step' => TelegramAnalystStateStore::STEP_ANALYZING,
            'instrument' => $instrument,
        ]);

        $this->sendReplyText(
            $token,
            $chatId,
            '⏳ Анализирую <b>'.htmlspecialchars($instrument, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</b>… (обычно 1–3 мин)',
            TelegramAnalystReplyKeyboard::mainMarkup()
        );

        try {
            RunTelegramAnalystAnalysisJob::dispatch($bot->id, $chatId, $instrument, $question);
        } catch (\Throwable $e) {
            Log::error('telegram_analyst_dispatch_failed', ['error' => $e->getMessage()]);
            $this->state->put($bot->id, $chatId, ['step' => TelegramAnalystStateStore::STEP_MENU]);
            $this->sendReplyText($token, $chatId, '❌ Не удалось поставить анализ в очередь. Проверьте queue worker.', TelegramAnalystReplyKeyboard::mainMarkup());
        }
    }

    /**
     * Сброс шага и показ inline-меню инструментов (+ reply-кнопка «Меню» остаётся внизу).
     */
    private function openInstrumentMenu(Bot $bot, string $token, string $chatId): void
    {
        $this->state->put($bot->id, $chatId, [
            'step' => TelegramAnalystStateStore::STEP_MENU,
            'instrument' => null,
        ]);
        $this->sendReplyText(
            $token,
            $chatId,
            '📋 Выберите инструмент из списка ниже ('.TelegramAnalystReplyKeyboard::menuHint().' — обновить).',
            TelegramAnalystReplyKeyboard::mainMarkup()
        );
        $this->sendInstrumentMenu($bot, $token, $chatId);
    }

    /**
     * Текстовое сообщение с постоянной reply-клавиатурой (не inline).
     *
     * @param  array<string, mixed>  $replyMarkup
     */
    private function sendReplyText(string $token, string $chatId, string $text, array $replyMarkup): void
    {
        $this->telegram->sendMessageWithMarkup($token, $chatId, $text, $replyMarkup);
    }

    private function sendInstrumentMenu(Bot $bot, string $token, string $chatId): void
    {
        $menuData = $this->instruments->keyboardItems($bot);
        if ($menuData['total'] === 0) {
            $this->sendReplyText(
                $token,
                $chatId,
                'Нет активных инструментов по текущим фильтрам (min_payout / allowed_instruments). Измените настройки бота.',
                TelegramAnalystReplyKeyboard::mainMarkup()
            );

            return;
        }

        $keyboard = $this->buildInstrumentKeyboard($menuData);
        $keyboard[] = TelegramAnalystReplyKeyboard::inlineInstrumentFooterRow();
        $text = '📋 <b>Выберите инструмент</b> (всего '.$menuData['total'].")\n".
            TelegramAnalystReplyKeyboard::menuHint().' — обновить список';

        $result = $this->telegram->sendMessageWithMarkup($token, $chatId, $text, [
            'inline_keyboard' => $keyboard,
        ]);
        if (! ($result['ok'] ?? false)) {
            Log::warning('telegram_analyst_instrument_menu_failed', [
                'bot_id' => $bot->id,
                'chat_id' => $chatId,
                'total' => $menuData['total'],
                'error' => $result['error'] ?? 'unknown',
            ]);
            $this->sendReplyText(
                $token,
                $chatId,
                '❌ Не удалось показать список инструментов: '.htmlspecialchars((string) ($result['error'] ?? 'ошибка Telegram'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                TelegramAnalystReplyKeyboard::mainMarkup()
            );
        }
    }

    /**
     * @param  array{items:list<array{instrument:string,callback_data:string,payout:?float}>,total:int}  $menuData
     * @return list<list<array{text:string,callback_data:string}>>
     */
    private function buildInstrumentKeyboard(array $menuData): array
    {
        $rows = [];
        $row = [];
        foreach ($menuData['items'] as $item) {
            // Текст кнопки можно укорачивать; callback_data всегда несёт полное имя пары.
            $label = $item['instrument'];
            if ($item['payout'] !== null) {
                $label .= ' '.number_format($item['payout'], 0).'%';
            }
            if (mb_strlen($label) > 32) {
                $label = mb_substr($item['instrument'], 0, 28).'…';
            }
            $row[] = [
                'text' => $label,
                'callback_data' => $item['callback_data'],
            ];
            if (count($row) >= 3) {
                $rows[] = $row;
                $row = [];
            }
        }
        if ($row !== []) {
            $rows[] = $row;
        }

        return $rows;
    }

}
