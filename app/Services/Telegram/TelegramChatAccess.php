<?php

namespace App\Services\Telegram;

use App\Models\Bot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Получатели Telegram-алертов и доступ к кнопкам/командам.
 *
 * - telegram_chat_id — основной (ручной) chat id из настроек.
 * - telegram_subscribers — list chat_id, накопившихся по /start (или вручную).
 * - telegram_auto_subscribe — если true (дефолт), новый чат при сообщении добавляется в subscribers;
 *   если false — работают только уже известные id (primary + subscribers).
 */
final class TelegramChatAccess
{
    public const CONFIG_SUBSCRIBERS = 'telegram_subscribers';

    public const CONFIG_AUTO_SUBSCRIBE = 'telegram_auto_subscribe';

    /**
     * Автоподписка включена по умолчанию (ключ отсутствует → true).
     *
     * @param  array<string, mixed>  $cfg
     */
    public static function autoSubscribeEnabled(array $cfg): bool
    {
        if (! array_key_exists(self::CONFIG_AUTO_SUBSCRIBE, $cfg)) {
            return true;
        }

        return (bool) $cfg[self::CONFIG_AUTO_SUBSCRIBE];
    }

    /**
     * Уникальный список chat_id, куда слать алерты и кому разрешены кнопки.
     *
     * @param  array<string, mixed>  $cfg
     * @return list<string>
     */
    public static function recipientChatIds(array $cfg): array
    {
        $ids = [];

        $primary = trim((string) ($cfg['telegram_chat_id'] ?? ''));
        if ($primary !== '') {
            $ids[] = $primary;
        }

        foreach (self::normalizeSubscribers($cfg[self::CONFIG_SUBSCRIBERS] ?? null) as $id) {
            $ids[] = $id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<string, mixed>  $cfg
     */
    public static function isChatAllowed(array $cfg, string $chatId): bool
    {
        $chatId = trim($chatId);
        if ($chatId === '') {
            return false;
        }

        return in_array($chatId, self::recipientChatIds($cfg), true);
    }

    /**
     * Проверка входящего сообщения/callback: при auto_subscribe неизвестный чат подписывается.
     * Возвращает true, если чату можно отвечать и жать кнопки.
     */
    public static function authorizeIncoming(Bot $bot, string $chatId): bool
    {
        $chatId = trim($chatId);
        if ($chatId === '') {
            return false;
        }

        $bot->refresh();
        /** @var array<string, mixed> $cfg */
        $cfg = is_array($bot->config) ? $bot->config : [];

        if (self::isChatAllowed($cfg, $chatId)) {
            return true;
        }

        if (! self::autoSubscribeEnabled($cfg)) {
            return false;
        }

        return self::subscribe($bot, $chatId);
    }

    /**
     * Добавить chat_id в telegram_subscribers (идемпотентно).
     */
    public static function subscribe(Bot $bot, string $chatId): bool
    {
        $chatId = trim($chatId);
        if ($chatId === '') {
            return false;
        }

        return (bool) DB::transaction(function () use ($bot, $chatId): bool {
            /** @var Bot|null $locked */
            $locked = Bot::query()->whereKey($bot->id)->lockForUpdate()->first();
            if ($locked === null) {
                return false;
            }

            /** @var array<string, mixed> $cfg */
            $cfg = is_array($locked->config) ? $locked->config : [];
            if (self::isChatAllowed($cfg, $chatId)) {
                $bot->refresh();

                return true;
            }

            $subs = self::normalizeSubscribers($cfg[self::CONFIG_SUBSCRIBERS] ?? null);
            $subs[] = $chatId;
            $cfg[self::CONFIG_SUBSCRIBERS] = array_values(array_unique($subs));
            $locked->config = $cfg;
            $locked->save();

            $bot->refresh();
            Log::info('telegram_subscriber_added', [
                'bot_id' => $bot->id,
                'chat_id' => $chatId,
            ]);

            return true;
        });
    }

    /**
     * Убрать chat_id из telegram_subscribers.
     * Если это же значение в telegram_chat_id — primary не трогаем (владелец остаётся в recipients).
     */
    public static function unsubscribe(Bot $bot, string $chatId): bool
    {
        $chatId = trim($chatId);
        if ($chatId === '') {
            return false;
        }

        return (bool) DB::transaction(function () use ($bot, $chatId): bool {
            /** @var Bot|null $locked */
            $locked = Bot::query()->whereKey($bot->id)->lockForUpdate()->first();
            if ($locked === null) {
                return false;
            }

            /** @var array<string, mixed> $cfg */
            $cfg = is_array($locked->config) ? $locked->config : [];
            $subs = self::normalizeSubscribers($cfg[self::CONFIG_SUBSCRIBERS] ?? null);
            $filtered = array_values(array_filter($subs, static fn (string $id): bool => $id !== $chatId));
            if ($filtered === $subs) {
                $bot->refresh();

                return self::isChatAllowed($cfg, $chatId) === false;
            }

            $cfg[self::CONFIG_SUBSCRIBERS] = $filtered;
            $locked->config = $cfg;
            $locked->save();
            $bot->refresh();

            Log::info('telegram_subscriber_removed', [
                'bot_id' => $bot->id,
                'chat_id' => $chatId,
            ]);

            return true;
        });
    }

    /**
     * @return list<string>
     */
    public static function normalizeSubscribers(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            $id = trim((string) $item);
            if ($id !== '') {
                $out[] = $id;
            }
        }

        return array_values(array_unique($out));
    }
}
