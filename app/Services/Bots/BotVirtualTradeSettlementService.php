<?php

namespace App\Services\Bots;

use App\Models\BotVirtualTrade;
use App\Models\BotTrade;
use Illuminate\Support\Facades\DB;

/**
 * Закрывает виртуальные сделки теми же котировками, что и paper-сделки.
 * После результата обновляет серию конкретного инструмента, но не создаёт
 * BotTrade и не запускает уведомления или PO-зеркало.
 */
class BotVirtualTradeSettlementService
{
    private const CANDLE_LOOKUP_GRACE_SEC = 120;

    public function __construct(
        private readonly BotSettlementService $settlement,
        private readonly BotInstrumentLossStateService $lossState,
    ) {}

    public function settleDueVirtualTrades(?int $botId = null, int $limit = 1000): array
    {
        $query = BotVirtualTrade::query()
            ->with('bot')
            ->where('status', BotVirtualTrade::STATUS_OPEN)
            ->where('expiry_time', '<=', now())
            ->orderBy('expiry_time')
            ->orderBy('id')
            ->limit($limit);
        if ($botId !== null) {
            $query->where('bot_id', $botId);
        }

        $checked = 0;
        $settled = 0;
        $skipped = 0;
        foreach ($query->get() as $virtualTrade) {
            $checked++;
            $expiryTimestamp = (int) $virtualTrade->expiry_time->timestamp;
            $expiryPrice = $this->settlement->marketPriceAtExpiry(
                (string) $virtualTrade->instrument,
                $expiryTimestamp,
            );
            if ($expiryPrice === null && time() - $expiryTimestamp < self::CANDLE_LOOKUP_GRACE_SEC) {
                // Данные ingest могут прийти с задержкой; не считаем результат раньше времени.
                $skipped++;

                continue;
            }

            // После grace без цены считаем ничью, как и основной settlement paper-сделок.
            $expiryPrice ??= (float) $virtualTrade->entry_price;
            $result = $this->result(
                (string) $virtualTrade->side,
                (float) $virtualTrade->entry_price,
                $expiryPrice,
            );

            DB::transaction(function () use ($virtualTrade, $expiryPrice, $result): void {
                // Повторная проверка защищает от двойного settlement параллельными процессами.
                $fresh = BotVirtualTrade::query()->lockForUpdate()->find($virtualTrade->id);
                if ($fresh === null || $fresh->status !== BotVirtualTrade::STATUS_OPEN) {
                    return;
                }
                $fresh->update([
                    'status' => BotVirtualTrade::STATUS_SETTLED,
                    'result' => $result,
                    'expiry_price' => $expiryPrice,
                    // Как у paper: фиксируем момент экспирации, не время cron.
                    'settled_at' => $fresh->expiry_time,
                ]);
                $this->lossState->recordResult($fresh->bot, (string) $fresh->instrument, $result);
            });
            $settled++;
        }

        return compact('checked', 'settled', 'skipped');
    }

    private function result(string $side, float $entryPrice, float $expiryPrice): string
    {
        if ($side === 'CALL') {
            return $expiryPrice > $entryPrice
                ? BotTrade::RESULT_WIN
                : ($expiryPrice < $entryPrice ? BotTrade::RESULT_LOSS : BotTrade::RESULT_DRAW);
        }

        return $expiryPrice < $entryPrice
            ? BotTrade::RESULT_WIN
            : ($expiryPrice > $entryPrice ? BotTrade::RESULT_LOSS : BotTrade::RESULT_DRAW);
    }
}
