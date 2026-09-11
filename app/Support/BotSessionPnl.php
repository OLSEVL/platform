<?php

namespace App\Support;

use App\Models\Bot;
use App\Models\BotTrade;
use Carbon\Carbon;

/**
 * Сессионный PnL бота (сегодня) для правил мартингейла.
 *
 * Автор: при уже зелёном «профиле» за день часто не ставит перекрытие после минуса.
 * Берём сумму колонки `pnl` (как пишет BotSettlementService: win≈payout/100, loss≈−1).
 */
class BotSessionPnl
{
    /**
     * Сумма paper PnL по закрытым сделкам бота за календарный день.
     * Положительное = сессия в плюсе.
     *
     * @param  int|null  $excludeTradeId  Не учитывать сделку (текущий settle — «день до минуса»).
     */
    public static function todayNet(Bot $bot, ?Carbon $day = null, ?int $excludeTradeId = null): float
    {
        $day = ($day ?? now())->copy()->startOfDay();
        $end = $day->copy()->endOfDay();

        $query = BotTrade::query()
            ->where('bot_id', $bot->id)
            ->whereNotNull('result')
            ->where('result', '!=', BotTrade::RESULT_PENDING)
            ->whereBetween('created_at', [$day, $end]);
        if ($excludeTradeId !== null && $excludeTradeId > 0) {
            $query->where('id', '!=', $excludeTradeId);
        }

        return round((float) $query->sum('pnl'), 4);
    }

    public static function isSessionGreen(Bot $bot, ?Carbon $day = null, ?int $excludeTradeId = null): bool
    {
        return self::todayNet($bot, $day, $excludeTradeId) > 0;
    }
}
