<?php

namespace App\Services\Mirror;

use App\Models\BotTrade;
use App\Models\UserTradingSetting;

/**
 * Мартингейл: при включённой «Одна сделка на PO» не открываем новый ордер,
 * пока на платформе есть ещё не settled paper-сделка с успешным зеркалом (executed).
 *
 * Ждём именно settle (win/loss), а не только expiry: шаг цепочки сумм двигается
 * при закрытии paper, иначе следующая сделка берёт устаревшую сумму.
 */
class MirrorWaitPreviousService
{
    public const SKIP_STATUS = 'skipped_wait_previous_trade';

    /**
     * Пропуск, если в настройках включено ожидание и есть блокирующая paper-сделка.
     */
    public function shouldSkipMirror(
        UserTradingSetting $settings,
        int $userId,
        int $tradeId,
    ): bool {
        if (! (bool) $settings->mirror_wait_previous_settled) {
            return false;
        }

        return $this->hasBlockingMirroredTrade($userId, $tradeId);
    }

    /**
     * Другая paper-сделка со статусом open и уже отправленным ордером на PO.
     * Блокируем до settle — даже если expiry уже прошёл (цена/итог ещё не посчитаны).
     */
    public function hasBlockingMirroredTrade(int $userId, int $exceptTradeId): bool
    {
        return BotTrade::query()
            ->where('id', '!=', $exceptTradeId)
            ->where('status', BotTrade::STATUS_OPEN)
            ->where('meta_json->mirror_status', 'executed')
            ->whereHas('bot', fn ($q) => $q->where('user_id', $userId))
            ->exists();
    }
}
