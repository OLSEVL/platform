<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Cache;

/**
 * FSM-состояние диалога Telegram-аналитика в cache (chat_id + bot_id).
 */
class TelegramAnalystStateStore
{
    private const TTL_SEC = 3600;

    public const STEP_MENU = 'menu';

    public const STEP_AWAIT_QUESTION = 'await_question';

    public const STEP_ANALYZING = 'analyzing';

    /**
     * @return array{step:string,instrument:?string}
     */
    public function get(int $botId, string $chatId): array
    {
        $stored = Cache::get($this->key($botId, $chatId));
        if (! is_array($stored)) {
            return $this->defaultState();
        }

        return [
            'step' => (string) ($stored['step'] ?? self::STEP_MENU),
            'instrument' => isset($stored['instrument']) ? (string) $stored['instrument'] : null,
        ];
    }

    /**
     * @param  array{step?:string,instrument?:?string}  $patch
     */
    public function put(int $botId, string $chatId, array $patch): void
    {
        $current = $this->get($botId, $chatId);
        $merged = array_merge($current, $patch);
        Cache::put($this->key($botId, $chatId), $merged, self::TTL_SEC);
    }

    public function clear(int $botId, string $chatId): void
    {
        Cache::forget($this->key($botId, $chatId));
    }

    /**
     * @return array{step:string,instrument:?string}
     */
    private function defaultState(): array
    {
        return [
            'step' => self::STEP_MENU,
            'instrument' => null,
        ];
    }

    private function key(int $botId, string $chatId): string
    {
        return 'telegram_analyst_state:'.$botId.':'.trim($chatId);
    }
}
