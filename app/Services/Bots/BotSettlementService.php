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
     *
     * Важно: свечи в БД могут иметь дрейф времени (например, +2 часа).
     * Поэтому мы нормализуем время экспирации с учётом дрейфа, чтобы найти
     * правильную свечу — ту же, что отображается на графике.
     */
    private function resolveSettlementPriceAtExpiry(string $instrument, int $expiryTs): ?float
    {
        // Определяем текущий дрейф времени свечей для данного инструмента.
        $timeShiftSec = $this->detectCandleTimezoneDrift($instrument);

        // Нормализуем время экспирации с учётом дрейфа.
        // Если свечи сдвинуты на +2 часа, то expiryTs тоже нужно сдвинуть,
        // чтобы найти правильную свечу в БД.
        $normalizedExpiryTs = $expiryTs + $timeShiftSec;

        // Находим M1 свечу, в которой произошла экспирация.
        // Экспирация происходит в минутном бакете: floor(normalizedExpiryTs / 60) * 60
        $candleTime = (int) (floor($normalizedExpiryTs / 60) * 60);

        $candle = InstrumentCandle::query()
            ->forInstrument($instrument)
            ->forTimeframe(1) // M1
            ->where('time', $candleTime)
            ->first(['time', 'open', 'high', 'low', 'close']);

        if (! $candle) {
            // Fallback: ищем ближайшую свечу <= normalizedExpiryTs
            $candle = InstrumentCandle::query()
                ->forInstrument($instrument)
                ->forTimeframe(1)
                ->where('time', '<=', $normalizedExpiryTs)
                ->orderByDesc('time')
                ->first(['time', 'open', 'high', 'low', 'close']);
        }

        if (! $candle) {
            return null;
        }

        // Всегда используем close свечи — это цена, которую видит пользователь на графике.
        return (float) $candle->close;
    }

    /**
     * Определяет дрейф времени свечей для инструмента.
     *
     * Сравнивает время последней свечи с текущим серверным временем.
     * Если разница больше 90 минут и кратна целому часу, считаем это дрейфом.
     *
     * @return int Сдвиг в секундах (положительный, если свечи "впереди")
     */
    private function detectCandleTimezoneDrift(string $instrument): int
    {
        $latestCandle = InstrumentCandle::query()
            ->forInstrument($instrument)
            ->forTimeframe(1)
            ->orderByDesc('time')
            ->first(['time']);

        if (! $latestCandle) {
            return 0;
        }

        $serverUnix = time();
        $lastTs = (int) $latestCandle->time;
        $deltaSec = $lastTs - $serverUnix;

        // Если дрейф меньше 90 минут, считаем его нормальным
        if (abs($deltaSec) < 90 * 60) {
            return 0;
        }

        // Проверяем, кратен ли дрейф целому часу
        $hours = (int) round($deltaSec / 3600);
        if ($hours === 0 || abs($hours) > 12) {
            return 0;
        }

        // Возвращаем сдвиг, который нужно применить к expiryTs
        // Если свечи "впереди" на +2 часа, то expiryTs тоже нужно сдвинуть на +2 часа
        return $hours * 3600;
    }
}
