<?php

namespace App\Services\Bots;

use App\Models\Bot;
use App\Models\BotInstrumentLossState;
use App\Models\BotTrade;
use Illuminate\Support\Facades\DB;

/**
 * Централизованно ведёт серию для Strong Trend + DeMarker.
 * Реальные и виртуальные сделки завершаются разными сервисами, поэтому
 * обновление общего состояния находится здесь, а не в одном из них.
 */
class BotInstrumentLossStateService
{
    public const BOT_TYPE = 'strong_trend_demarker';

    public function consecutiveLosses(Bot $bot, string $instrument): int
    {
        if ($bot->type !== self::BOT_TYPE) {
            return 0;
        }

        return (int) (BotInstrumentLossState::query()
            ->where('bot_id', $bot->id)
            ->where('instrument', $instrument)
            ->value('consecutive_losses') ?? 0);
    }

    /**
 * WIN и DRAW разрывают серию: только последовательные LOSS дают право
 * на настоящий вход (если losses_before_entry > 0). Вызов должен идти
 * в транзакции с settlement.
     */
    public function recordResult(Bot $bot, string $instrument, string $result): void
    {
        if ($bot->type !== self::BOT_TYPE) {
            return;
        }

        DB::transaction(function () use ($bot, $instrument, $result): void {
            $state = BotInstrumentLossState::query()
                ->where('bot_id', $bot->id)
                ->where('instrument', $instrument)
                ->lockForUpdate()
                ->first();

            if ($state === null) {
                $state = BotInstrumentLossState::query()->create([
                    'bot_id' => $bot->id,
                    'instrument' => $instrument,
                    'consecutive_losses' => 0,
                ]);
            }

            $losses = $result === BotTrade::RESULT_LOSS
                ? $state->consecutive_losses + 1
                : 0;
            $state->update([
                'consecutive_losses' => $losses,
                'last_result' => $result,
                'last_settled_at' => now(),
            ]);
        });
    }
}
