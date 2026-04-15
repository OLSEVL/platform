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
     * Почему это важно:
     * - старый расчёт всегда брал close последней свечи <= expiry_time;
     * - если экспирация попадает ВНУТРЬ текущего бара, close этого бара — это уже "будущее"
     *   относительно точки экспирации, что искажает итог (утечка пост-экспирационного движения).
     *
     * Новая логика:
     * - приоритетно берем более точный timeframe (stored_timeframe_id, например 4015),
     *   затем fallback на классический M1;
     * - если экспирация внутри найденного бара, используем open бара (цена на старте бара,
     *   уже известная к моменту экспирации);
     * - если бар полностью завершился к expiry_time, используем close.
     */
    private function resolveSettlementPriceAtExpiry(string $instrument, int $expiryTs): ?float
    {
        $candidateTimeframes = $this->candidateSettlementTimeframes();

        foreach ($candidateTimeframes as $timeframe) {
            $candle = InstrumentCandle::query()
                ->forInstrument($instrument)
                ->forTimeframe($timeframe)
                ->where('time', '<=', $expiryTs)
                ->orderByDesc('time')
                ->first(['time', 'open', 'close']);

            if (! $candle) {
                continue;
            }

            $barStartTs = (int) $candle->time;
            $barPeriodSec = $this->timeframePeriodSeconds($timeframe);
            $barEndTsExclusive = $barStartTs + $barPeriodSec;

            // Если экспирация попала внутрь текущего бара, close будет содержать движение после expiry.
            // В этом случае фиксируем цену по open данного бара.
            if ($expiryTs < $barEndTsExclusive) {
                return (float) $candle->open;
            }

            return (float) $candle->close;
        }

        return null;
    }

    /**
     * @return list<int>
     */
    private function candidateSettlementTimeframes(): array
    {
        $storedTf = (int) config('candles.stored_timeframe_id', 4015);

        $candidates = array_values(array_unique(array_filter([
            $storedTf > 0 ? $storedTf : null,
            1,
        ])));

        return $candidates === [] ? [1] : $candidates;
    }

    private function timeframePeriodSeconds(int $timeframe): int
    {
        $storedTf = (int) config('candles.stored_timeframe_id', 4015);
        if ($timeframe === $storedTf) {
            $base = (int) config('candles.base_bar_period_seconds', 15);

            return $base > 0 ? $base : 15;
        }

        // Исторический M1.
        if ($timeframe === 1) {
            return 60;
        }

        // Защитный fallback (если появятся другие TF-идентификаторы).
        return 60;
    }
}
