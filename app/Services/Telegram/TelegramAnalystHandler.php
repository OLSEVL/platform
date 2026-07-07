<?php

namespace App\Services\Telegram;

use App\Jobs\RunTelegramAnalystAnalysisJob;
use App\Models\Bot;
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
        if ($bot->type !== 'openrouter_llm_analyst') {
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
        if ($chatId === '' || ! $this->isChatAllowed($bot, $chatId)) {
            return;
        }

        $text = trim((string) ($message['text'] ?? ''));
        if ($text === '') {
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

        if ($chatId === '' || ! $this->isChatAllowed($bot, $chatId)) {
            if ($callbackId !== '') {
                $this->telegram->answerCallbackQuery($token, $callbackId);
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

        if (str_starts_with($data, 'ins:')) {
            $index = (int) substr($data, 4);
            $instrument = $this->instruments->instrumentByIndex($bot, $index);
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

        $this->telegram->answerCallbackQuery($token, $callbackId);
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
     * @param  array{items:list<array{index:int,instrument:string,payout:?float}>,total:int}  $menuData
     * @return list<list<array{text:string,callback_data:string}>>
     */
    private function buildInstrumentKeyboard(array $menuData): array
    {
        $rows = [];
        $row = [];
        foreach ($menuData['items'] as $item) {
            $label = $item['instrument'];
            if ($item['payout'] !== null) {
                $label .= ' '.number_format($item['payout'], 0).'%';
            }
            if (mb_strlen($label) > 32) {
                $label = mb_substr($item['instrument'], 0, 28).'…';
            }
            $row[] = [
                'text' => $label,
                'callback_data' => 'ins:'.$item['index'],
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

    private function isChatAllowed(Bot $bot, string $chatId): bool
    {
        /** @var array<string, mixed> $cfg */
        $cfg = is_array($bot->config) ? $bot->config : [];
        $allowedChat = trim((string) ($cfg['telegram_chat_id'] ?? ''));
        if ($allowedChat === '') {
            return true;
        }

        return $allowedChat === $chatId;
    }
}
