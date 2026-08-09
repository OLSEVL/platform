<?php

namespace App\Services\Mirror;

use App\Models\BotTrade;
use App\Models\UserTradingSetting;
use App\Support\ManualTradeConfig;

/**
 * Мартингейл: при включённой «Одна сделка на PO» не открываем новый ордер,
 * пока на платформе есть ещё не settled paper-сделка с успешным зеркалом (executed).
 *
 * Ждём именно settle (win/loss), а не только expiry: шаг цепочки сумм двигается
 * при закрытии paper, иначе следующая сделка берёт устаревшую сумму.
 *
 * Ручная кнопка: флаг manual_respect_wait_previous — ждать open PO и блокировать
 * зеркало ботов своей open-сделкой (даже если у профиля wait previous выкл.).
 */
class MirrorWaitPreviousService
{
    public const SKIP_STATUS = 'skipped_wait_previous_trade';

    /**
     * Пропуск зеркала бота, если в настройках включено ожидание и есть блокирующая paper-сделка.
     */
    public function shouldSkipMirror(
        UserTradingSetting $settings,
        int $userId,
        int $tradeId,
    ): bool {
        // Обычный wait previous профиля (с учётом исключения manual, если флаг ручной выкл.).
        if ((bool) $settings->mirror_wait_previous_settled
            && $this->hasBlockingMirroredTrade($userId, $tradeId, (bool) $settings->manual_respect_wait_previous)) {
            return true;
        }

        // Отдельно: open ручная всегда блокирует ботов, пока manual_respect_wait_previous=true
        // (даже если зеркальный wait previous в профиле выключен).
        if ((bool) $settings->manual_respect_wait_previous
            && $this->hasOpenManualExecutedTrade($userId, $tradeId)) {
            return true;
        }

        return false;
    }

    /**
     * Ручная кнопка: ждать любую open executed (боты + другие ручные), если флаг включён.
     */
    public function shouldSkipManualPlace(UserTradingSetting $settings, int $userId): bool
    {
        if (! (bool) $settings->manual_respect_wait_previous) {
            return false;
        }

        return $this->hasBlockingMirroredTrade($userId, 0, true);
    }

    /**
     * Другая paper-сделка со статусом open и уже отправленным ордером на PO.
     *
     * @param  bool  $includeManual  включать ли source=manual в список блокеров
     */
    public function hasBlockingMirroredTrade(int $userId, int $exceptTradeId, bool $includeManual = true): bool
    {
        $q = BotTrade::query()
            ->where('status', BotTrade::STATUS_OPEN)
            ->where('meta_json->mirror_status', 'executed')
            ->whereHas('bot', fn ($q) => $q->where('user_id', $userId));

        if ($exceptTradeId > 0) {
            $q->where('id', '!=', $exceptTradeId);
        }

        if (! $includeManual) {
            // Ручные open не блокируют ботов, пока manual_respect_wait_previous=false.
            $q->where(function ($inner): void {
                $inner->whereNull('meta_json->source')
                    ->orWhere('meta_json->source', '!=', ManualTradeConfig::META_SOURCE);
            });
        }

        return $q->exists();
    }

    /** Есть ли open executed именно ручной сделки (source=manual). */
    public function hasOpenManualExecutedTrade(int $userId, int $exceptTradeId): bool
    {
        $q = BotTrade::query()
            ->where('status', BotTrade::STATUS_OPEN)
            ->where('meta_json->mirror_status', 'executed')
            ->where('meta_json->source', ManualTradeConfig::META_SOURCE)
            ->whereHas('bot', fn ($q) => $q->where('user_id', $userId));

        if ($exceptTradeId > 0) {
            $q->where('id', '!=', $exceptTradeId);
        }

        return $q->exists();
    }
}
