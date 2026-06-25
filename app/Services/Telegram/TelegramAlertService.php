<?php

namespace App\Services\Telegram;

use App\Models\Bot;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Отправка алертов в Telegram Bot API.
 * Token и chat_id берутся из config конкретного бота (не из .env).
 */
class TelegramAlertService
{
    /**
     * @return array{ok:bool,error?:string,message_id?:int}
     */
    public function sendMessage(string $token, string $chatId, string $text): array
    {
        $token = trim($token);
        $chatId = trim($chatId);
        if ($token === '' || $chatId === '') {
            return ['ok' => false, 'error' => 'telegram_token_or_chat_id_missing'];
        }

        try {
            $response = Http::timeout(15)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]);
        } catch (\Throwable $e) {
            Log::warning('telegram_send_failed', ['error' => $e->getMessage()]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }

        if (! $response->successful()) {
            $body = $response->json();
            $desc = is_array($body) ? (string) ($body['description'] ?? $response->body()) : $response->body();

            return ['ok' => false, 'error' => $desc !== '' ? $desc : 'telegram_http_'.$response->status()];
        }

        $payload = $response->json();
        $messageId = is_array($payload) ? ($payload['result']['message_id'] ?? null) : null;

        return [
            'ok' => true,
            'message_id' => is_numeric($messageId) ? (int) $messageId : null,
        ];
    }

    /**
     * Алерт о сильном тренде по результату стратегии strong_trend_telegram.
     *
     * @param  array<string, mixed>  $decision
     */
    public function sendTrendAlert(Bot $bot, string $instrument, array $decision, ?float $payout): array
    {
        /** @var array<string, mixed> $cfg */
        $cfg = is_array($bot->config) ? $bot->config : [];
        if (! (bool) ($cfg['telegram_enabled'] ?? false)) {
            return ['ok' => false, 'error' => 'telegram_disabled'];
        }

        $token = trim((string) ($cfg['telegram_bot_token'] ?? ''));
        $chatId = trim((string) ($cfg['telegram_chat_id'] ?? ''));
        if ($token === '' || $chatId === '') {
            return ['ok' => false, 'error' => 'telegram_token_or_chat_id_missing'];
        }

        $trend = (string) ($decision['trend'] ?? '');
        if (! in_array($trend, ['up', 'down'], true)) {
            $side = (string) ($decision['side'] ?? '');
            $trend = $side === 'CALL' ? 'up' : ($side === 'PUT' ? 'down' : '');
        }
        if ($trend === '') {
            return ['ok' => false, 'error' => 'invalid_trend'];
        }

        $streakLen = (int) ($decision['features']['streak_len'] ?? ($cfg['streak_len'] ?? 6));
        $strictness = (string) ($decision['features']['candle_strictness'] ?? ($cfg['candle_strictness'] ?? 'strict'));
        $strictnessLabel = $strictness === 'with_doji' ? 'с доджи' : 'строго';

        $icon = $trend === 'up' ? '🔼' : '🔽';
        $trendLabel = $trend === 'up' ? 'восходящий' : 'нисходящий';
        $candleLabel = $trend === 'up' ? 'бычьих' : 'медвежьих';

        $lines = [
            "{$icon} <b>Сильный {$trendLabel} тренд</b>",
            '',
            'Пара: <b>'.htmlspecialchars($instrument, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</b>',
            'Таймфрейм: M1',
            "{$streakLen} {$candleLabel} свечей подряд ({$strictnessLabel})",
        ];
        if ($payout !== null) {
            $lines[] = 'Payout: '.number_format($payout, 1, '.', '').'%';
        }
        $lines[] = 'Время: '.now()->utc()->format('d.m.Y H:i').' UTC';

        return $this->sendMessage($token, $chatId, implode("\n", $lines));
    }

    /**
     * Тестовое сообщение из настроек бота.
     */
    public function sendTestMessage(Bot $bot): array
    {
        /** @var array<string, mixed> $cfg */
        $cfg = is_array($bot->config) ? $bot->config : [];
        $token = trim((string) ($cfg['telegram_bot_token'] ?? ''));
        $chatId = trim((string) ($cfg['telegram_chat_id'] ?? ''));

        $text = "✅ <b>Тест Telegram</b>\n\nБот: ".htmlspecialchars($bot->name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')."\nВремя: ".now()->utc()->format('d.m.Y H:i').' UTC';

        return $this->sendMessage($token, $chatId, $text);
    }

    /**
     * Читает getUpdates и возвращает уникальные chat_id пользователей, написавших боту.
     *
     * @return array{ok:bool,error?:string,chats?:list<array{id:string,title?:string,username?:string,type?:string}>}
     */
    public function discoverChatIds(string $token): array
    {
        $token = trim($token);
        if ($token === '') {
            return ['ok' => false, 'error' => 'telegram_token_missing'];
        }

        try {
            $response = Http::timeout(15)->get("https://api.telegram.org/bot{$token}/getUpdates", [
                'limit' => 100,
                'timeout' => 0,
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        if (! $response->successful()) {
            $body = $response->json();
            $desc = is_array($body) ? (string) ($body['description'] ?? $response->body()) : $response->body();

            return ['ok' => false, 'error' => $desc !== '' ? $desc : 'telegram_http_'.$response->status()];
        }

        $payload = $response->json();
        $result = is_array($payload) ? ($payload['result'] ?? []) : [];
        if (! is_array($result)) {
            return ['ok' => true, 'chats' => []];
        }

        $chats = [];
        foreach ($result as $update) {
            if (! is_array($update)) {
                continue;
            }
            $chat = $update['message']['chat']
                ?? $update['edited_message']['chat']
                ?? $update['callback_query']['message']['chat']
                ?? null;
            if (! is_array($chat) || ! isset($chat['id'])) {
                continue;
            }
            $id = (string) $chat['id'];
            $chats[$id] = [
                'id' => $id,
                'type' => isset($chat['type']) ? (string) $chat['type'] : null,
                'title' => isset($chat['title']) ? (string) $chat['title'] : null,
                'username' => isset($chat['username']) ? (string) $chat['username'] : null,
                'first_name' => isset($chat['first_name']) ? (string) $chat['first_name'] : null,
            ];
        }

        return ['ok' => true, 'chats' => array_values($chats)];
    }

    /**
     * Long polling: getUpdates с timeout (Telegram держит соединение до N секунд).
     *
     * @return array{ok:bool,error?:string,updates?:list<array<string,mixed>>}
     */
    public function getUpdates(string $token, int $offset, int $timeoutSec = 30): array
    {
        $token = trim($token);
        if ($token === '') {
            return ['ok' => false, 'error' => 'telegram_token_missing'];
        }

        try {
            $response = Http::timeout(max(35, $timeoutSec + 5))->get("https://api.telegram.org/bot{$token}/getUpdates", [
                'offset' => max(0, $offset),
                'timeout' => max(0, min(50, $timeoutSec)),
                'allowed_updates' => json_encode(['message', 'callback_query']),
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        if (! $response->successful()) {
            $body = $response->json();
            $desc = is_array($body) ? (string) ($body['description'] ?? $response->body()) : $response->body();

            return ['ok' => false, 'error' => $desc !== '' ? $desc : 'telegram_http_'.$response->status()];
        }

        $payload = $response->json();
        $result = is_array($payload) ? ($payload['result'] ?? []) : [];

        return [
            'ok' => true,
            'updates' => is_array($result) ? $result : [],
        ];
    }

    /**
     * Перед long polling нужно снять webhook, иначе getUpdates не вернёт апдейты.
     *
     * @return array{ok:bool,error?:string}
     */
    public function deleteWebhook(string $token): array
    {
        $token = trim($token);
        if ($token === '') {
            return ['ok' => false, 'error' => 'telegram_token_missing'];
        }

        try {
            $response = Http::timeout(15)->post("https://api.telegram.org/bot{$token}/deleteWebhook");
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        if (! $response->successful()) {
            $body = $response->json();
            $desc = is_array($body) ? (string) ($body['description'] ?? $response->body()) : $response->body();

            return ['ok' => false, 'error' => $desc !== '' ? $desc : 'telegram_http_'.$response->status()];
        }

        return ['ok' => true];
    }

    /**
     * @param  array<string, mixed>  $replyMarkup  reply_markup (inline_keyboard и т.д.)
     * @return array{ok:bool,error?:string,message_id?:int}
     */
    public function sendMessageWithMarkup(string $token, string $chatId, string $text, array $replyMarkup): array
    {
        $token = trim($token);
        $chatId = trim($chatId);
        if ($token === '' || $chatId === '') {
            return ['ok' => false, 'error' => 'telegram_token_or_chat_id_missing'];
        }

        try {
            $response = Http::timeout(15)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
                'reply_markup' => json_encode($replyMarkup),
            ]);
        } catch (\Throwable $e) {
            Log::warning('telegram_send_markup_failed', ['error' => $e->getMessage()]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }

        if (! $response->successful()) {
            $body = $response->json();
            $desc = is_array($body) ? (string) ($body['description'] ?? $response->body()) : $response->body();

            return ['ok' => false, 'error' => $desc !== '' ? $desc : 'telegram_http_'.$response->status()];
        }

        $payload = $response->json();
        $messageId = is_array($payload) ? ($payload['result']['message_id'] ?? null) : null;

        return [
            'ok' => true,
            'message_id' => is_numeric($messageId) ? (int) $messageId : null,
        ];
    }

    /**
     * @return array{ok:bool,error?:string,message_id?:int}
     */
    public function sendPhoto(string $token, string $chatId, string $filePath, ?string $caption = null): array
    {
        $token = trim($token);
        $chatId = trim($chatId);
        if ($token === '' || $chatId === '' || ! is_readable($filePath)) {
            return ['ok' => false, 'error' => 'telegram_photo_invalid'];
        }

        try {
            $response = Http::timeout(30)
                ->attach('photo', fopen($filePath, 'r'), basename($filePath))
                ->post("https://api.telegram.org/bot{$token}/sendPhoto", array_filter([
                    'chat_id' => $chatId,
                    'caption' => $caption,
                    'parse_mode' => $caption !== null ? 'HTML' : null,
                ]));
        } catch (\Throwable $e) {
            Log::warning('telegram_send_photo_failed', ['error' => $e->getMessage()]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }

        if (! $response->successful()) {
            $body = $response->json();
            $desc = is_array($body) ? (string) ($body['description'] ?? $response->body()) : $response->body();

            return ['ok' => false, 'error' => $desc !== '' ? $desc : 'telegram_http_'.$response->status()];
        }

        $payload = $response->json();
        $messageId = is_array($payload) ? ($payload['result']['message_id'] ?? null) : null;

        return [
            'ok' => true,
            'message_id' => is_numeric($messageId) ? (int) $messageId : null,
        ];
    }

    /**
     * @return array{ok:bool,error?:string}
     */
    public function answerCallbackQuery(string $token, string $callbackQueryId, ?string $text = null): array
    {
        $token = trim($token);
        if ($token === '' || $callbackQueryId === '') {
            return ['ok' => false, 'error' => 'invalid_callback'];
        }

        try {
            $response = Http::timeout(10)->post("https://api.telegram.org/bot{$token}/answerCallbackQuery", array_filter([
                'callback_query_id' => $callbackQueryId,
                'text' => $text,
                'show_alert' => false,
            ]));
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        if (! $response->successful()) {
            $body = $response->json();
            $desc = is_array($body) ? (string) ($body['description'] ?? $response->body()) : $response->body();

            return ['ok' => false, 'error' => $desc !== '' ? $desc : 'telegram_http_'.$response->status()];
        }

        return ['ok' => true];
    }

    /**
     * Telegram лимит 4096 символов — режем длинный анализ на несколько сообщений.
     *
     * @return list<array{ok:bool,error?:string}>
     */
    public function sendLongMessage(string $token, string $chatId, string $text): array
    {
        $chunks = $this->splitTelegramText($text, 3900);
        if ($chunks === []) {
            return [['ok' => false, 'error' => 'empty_text']];
        }

        $results = [];
        foreach ($chunks as $chunk) {
            $results[] = $this->sendMessage($token, $chatId, $chunk);
        }

        return $results;
    }

    /**
     * @return list<string>
     */
    public function splitTelegramText(string $text, int $maxLen = 3900): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        if (mb_strlen($text) <= $maxLen) {
            return [$text];
        }

        $parts = [];
        $remaining = $text;
        while (mb_strlen($remaining) > $maxLen) {
            $slice = mb_substr($remaining, 0, $maxLen);
            $breakAt = mb_strrpos($slice, "\n\n");
            if ($breakAt === false || $breakAt < (int) ($maxLen * 0.5)) {
                $breakAt = mb_strrpos($slice, "\n");
            }
            if ($breakAt === false || $breakAt < (int) ($maxLen * 0.5)) {
                $breakAt = $maxLen;
            }
            $parts[] = trim(mb_substr($remaining, 0, $breakAt));
            $remaining = trim(mb_substr($remaining, $breakAt));
        }
        if ($remaining !== '') {
            $parts[] = $remaining;
        }

        return $parts;
    }

    /**
     * Текстовый список уровней для сообщения после анализа.
     *
     * @param  array{levels?:list<array<string,mixed>>,zones?:list<array<string,mixed>>,entry?:?array<string,mixed>}  $chartLevels
     */
    public function formatChartLevelsText(array $chartLevels): string
    {
        $lines = ['📍 <b>Уровни</b>'];
        $levels = is_array($chartLevels['levels'] ?? null) ? $chartLevels['levels'] : [];
        foreach ($levels as $lvl) {
            if (! is_array($lvl) || ! isset($lvl['price'])) {
                continue;
            }
            $label = htmlspecialchars((string) ($lvl['label'] ?? $lvl['kind'] ?? 'level'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $tf = isset($lvl['timeframe']) ? (string) $lvl['timeframe'].' ' : '';
            $lines[] = '• '.$tf.$label.': <code>'.htmlspecialchars((string) $lvl['price'], ENT_QUOTES).'</code>';
        }
        $zones = is_array($chartLevels['zones'] ?? null) ? $chartLevels['zones'] : [];
        foreach ($zones as $zone) {
            if (! is_array($zone)) {
                continue;
            }
            $low = $zone['price_low'] ?? $zone['low'] ?? null;
            $high = $zone['price_high'] ?? $zone['high'] ?? null;
            if ($low === null || $high === null) {
                continue;
            }
            $label = htmlspecialchars((string) ($zone['label'] ?? 'zone'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $lines[] = '• '.$label.': <code>'.$low.' – '.$high.'</code>';
        }
        $entry = is_array($chartLevels['entry'] ?? null) ? $chartLevels['entry'] : null;
        if ($entry && isset($entry['price'])) {
            $side = htmlspecialchars((string) ($entry['side'] ?? 'ENTRY'), ENT_QUOTES);
            $lines[] = '• Entry '.$side.': <code>'.htmlspecialchars((string) $entry['price'], ENT_QUOTES).'</code>';
        }

        return count($lines) > 1 ? implode("\n", $lines) : '';
    }

    /**
     * Token/chat_id из config бота-аналитика с Telegram.
     *
     * @return array{token:string,chat_id:string,enabled:bool}
     */
    public function analystTelegramCredentials(Bot $bot): array
    {
        /** @var array<string, mixed> $cfg */
        $cfg = is_array($bot->config) ? $bot->config : [];

        return [
            'token' => trim((string) ($cfg['telegram_bot_token'] ?? '')),
            'chat_id' => trim((string) ($cfg['telegram_chat_id'] ?? '')),
            'enabled' => (bool) ($cfg['telegram_enabled'] ?? false),
        ];
    }
}
