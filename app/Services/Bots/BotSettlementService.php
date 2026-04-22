<?php

namespace App\Services\Bots;

use App\Models\BotTrade;
use App\Models\InstrumentCandle;

class BotSettlementService
{
    public function settleDueTrades(?int $botId = null, int $limit = 1000): array
    {
        $q = BotTrade::query()
            ->where('status', BotTrade::STATUS_OPEN)
            ->where('expiry_time', '<=', now())
            ->orderBy('expiry_time')
            ->limit($limit);

        if ($botId !== null) {
            $q->where('bot_id', $botId);
        }

        $rows = $q->get();
        $settled = 0;
        $skipped = 0;

        foreach ($rows as $trade) {
            $expiryTs = (int) $trade->expiry_time->timestamp;
            $settlement = $this->resolveSettlementPriceAtExpiry((string) $trade->instrument, $expiryTs);

            if ($settlement === null) {
                $skipped++;

                continue;
            }

            $entry = (float) $trade->entry_price;
            $expiry = $settlement;
            $side = (string) $trade->side;

            $result = BotTrade::RESULT_DRAW;
            if ($side === 'CALL') {
                $result = $expiry > $entry ? BotTrade::RESULT_WIN : ($expiry < $entry ? BotTrade::RESULT_LOSS : BotTrade::RESULT_DRAW);
            } elseif ($side === 'PUT') {
                $result = $expiry < $entry ? BotTrade::RESULT_WIN : ($expiry > $entry ? BotTrade::RESULT_LOSS : BotTrade::RESULT_DRAW);
            }

            $payout = $trade->payout_snapshot !== null ? (float) $trade->payout_snapshot : 0.0;
            $pnl = match ($result) {
                BotTrade::RESULT_WIN => round($payout / 100, 4),
                BotTrade::RESULT_LOSS => -1.0,
                default => 0.0,
            };

            $trade->update([
                'status' => BotTrade::STATUS_SETTLED,
                'result' => $result,
                'expiry_price' => $expiry,
                'pnl' => $pnl,
                'settled_at' => now(),
            ]);
            $settled++;
        }

        return [
            'checked' => $rows->count(),
            'settled' => $settled,
            'skipped' => $skipped,
        ];
    }

    /**
     * Возвращает цену в момент экспирации сделки.
     *
     * Используем close M1 свечи, в которой произошла экспирация.
     * Это соответствует тому, что пользователь видит на графике.
     */
    private function resolveSettlementPriceAtExpiry(string $instrument, int $expiryTs): ?float
    {
        // Находим M1 свечу, в которой произошла экспирация.
        // Экспирация происходит в минутном бакете: floor(expiryTs / 60) * 60
        $candleTime = (int) (floor($expiryTs / 60) * 60);

        $candle = InstrumentCandle::query()
            ->forInstrument($instrument)
            ->forTimeframe(1) // M1
            ->where('time', $candleTime)
            ->first(['time', 'open', 'high', 'low', 'close']);

        if (! $candle) {
            // Fallback: ищем ближайшую свечу <= expiryTs
            $candle = InstrumentCandle::query()
                ->forInstrument($instrument)
                ->forTimeframe(1)
                ->where('time', '<=', $expiryTs)
                ->orderByDesc('time')
                ->first(['time', 'open', 'high', 'low', 'close']);
        }

        if (! $candle) {
            return null;
        }

        // Всегда используем close свечи — это цена, которую видит пользователь на графике.
        return (float) $candle->close;
    }

}
