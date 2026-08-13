<?php

namespace App\Support;

use App\Services\Telegram\TelegramChatAccess;
use Illuminate\Http\Request;

/**
 * Общие поля Telegram в config ботов: автоподписка и сохранение списка подписчиков.
 */
final class TelegramBotConfigFields
{
    /**
     * Правила validate() для форм настроек (дополняют telegram_*).
     *
     * @return array<string, list<string>>
     */
    public static function validationRules(): array
    {
        return [
            'telegram_auto_subscribe' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Смержить telegram_auto_subscribe в config; telegram_subscribers не затираем из формы.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $existingConfig
     * @return array<string, mixed>
     */
    public static function mergeFromRequest(Request $request, array $config, array $existingConfig = []): array
    {
        // Чекбокс: отмечен → true; снят (поля нет в POST) → false.
        $config[TelegramChatAccess::CONFIG_AUTO_SUBSCRIBE] = $request->boolean('telegram_auto_subscribe');

        // Список подписчиков живёт только на сервере (poll /start) — сохраняем как был.
        if (array_key_exists(TelegramChatAccess::CONFIG_SUBSCRIBERS, $existingConfig)) {
            $config[TelegramChatAccess::CONFIG_SUBSCRIBERS] = $existingConfig[TelegramChatAccess::CONFIG_SUBSCRIBERS];
        }

        return $config;
    }
}
