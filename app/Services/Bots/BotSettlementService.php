<?php

namespace App\Services\Bots;

use App\Models\BotTrade;
use App\Models\InstrumentCandle;
use App\Services\CandleService;
use App\Services\Mirror\MirrorPoResultService;
use App\Services\Mirror\UserMartingaleService;
use App\Services\Telegram\TelegramAlertService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BotSettlementService
{
    /**
     * Сколько секунд после экспирации ждём появления свечи в БД.
     * Ingest может отставать на несколько секунд; раньше этого окна не закрываем сделку без цены.
     */
    private const CANDLE_LOOKUP_GRACE_SEC = 120;

    public function __construct(
        private readonly CandleService $candleService,
    ) {}

    public function settleDueTrades(?int $botId = null, int $limit = 1000): array
    {
        $q = BotTrade::query()
            ->with('bot')
            ->where('status', BotTrade::STATUS_OPEN)
            ->where('expiry_time', '<=', now())
            ->orderBy('expiry_time')
            ->orderBy('id')
            ->limit($limit);

        if ($botId !== null) {
            $q->where('bot_id', $botId);
        }

        $rows = $q->get();
        $settled = 0;
        $skipped = 0;
        $settledFallback = 0;
        $awaitingPo = 0;

        $martingale = app(UserMartingaleService::class);
        $poResults = app(MirrorPoResultService::class);

        foreach ($rows as $trade) {
            $metaBefore = is_array($trade->meta_json) ? $trade->meta_json : [];
            $mirrorStatusBefore = (string) ($metaBefore['mirror_status'] ?? '');

            // Зеркальные executed: ждём expiry+5с, чтобы successcloseOrder успел попасть в кэш.
            if ($mirrorStatusBefore === 'executed') {
                $poReadyAt = $trade->expiry_time->copy()->addSeconds(MirrorPoResultService::PO_RESULT_DELAY_SEC);
                if ($poReadyAt->isFuture()) {
                    $awaitingPo++;
                    $skipped++;

                    continue;
                }
            }

            $expiryTs = (int) $trade->expiry_time->timestamp;
            $settlement = $this->resolveSettlementPriceAtExpiry((string) $trade->instrument, $expiryTs);
            $usedEntryFallback = false;

            if ($settlement === null) {
                $secondsSinceExpiry = time() - $expiryTs;
                if ($secondsSinceExpiry < self::CANDLE_LOOKUP_GRACE_SEC) {
                    // Свеча ещё может догрузиться — не закрываем преждевременно.
                    $skipped++;

                    continue;
                }

                // Нет рыночных данных даже после grace: закрываем как ничью по entry_price,
                // иначе status=open навсегда «висит» в статистике без маркеров на графике.
                $settlement = (float) $trade->entry_price;
                $usedEntryFallback = true;
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

            $meta = array_merge($metaBefore, [
                // Paper-итог по свечам платформы (статистика бота).
                'paper_result' => $result,
            ]);
            if ($usedEntryFallback) {
                $meta['settlement_fallback'] = 'entry_price_draw';
                $meta['settlement_fallback_reason'] = 'no_candle_at_expiry';
            }

            // Для executed зеркала: итог PO (successcloseOrder) → мартингейл; иначе fallback paper.
            $martingaleResult = $result;
            if ($mirrorStatusBefore === 'executed') {
                $po = $poResults->resolveForTrade($trade, $result);
                if ($po['result'] !== null) {
                    $meta['mirror_po_result'] = $po['result'];
                    $martingaleResult = $po['result'];
                }
                $meta['mirror_po_result_source'] = $po['source'];
                if ($po['profit'] !== null) {
                    $meta['mirror_po_profit'] = $po['profit'];
                }
                if (! empty($po['deal_id'])) {
                    $meta['pocket_deal_id'] = $po['deal_id'];
                }
                // Если после всех retry PO-result так и не получен, считаем зеркало
                // "не подтверждённо открытым" (PO open not confirmed):
                // 1) помечаем статус в meta для диагностики в UI/истории,
                // 2) НЕ двигаем мартингейл по paper-итогу этой сделки.
                if (($po['source'] ?? null) === MirrorPoResultService::SOURCE_ASSUME_NOT_OPENED) {
                    $meta['mirror_status'] = 'assumed_not_opened';
                    $martingaleResult = BotTrade::RESULT_DRAW;
                }
            }

            $updatePayload = [
                'status' => BotTrade::STATUS_SETTLED,
                'result' => $result,
                'expiry_price' => $expiry,
                'pnl' => $pnl,
                'settled_at' => now(),
                'meta_json' => $meta,
            ];

            // Settle + сдвиг мартингейла в одной транзакции: «одна сделка на PO»
            // отпускает следующую только после commit, когда шаг уже обновлён.
            DB::transaction(function () use ($trade, $updatePayload, $martingale, $martingaleResult, $mirrorStatusBefore): void {
                $trade->update($updatePayload);
                $trade->refresh();
                $martingale->advanceAfterSettledTrade($trade, $martingaleResult, $mirrorStatusBefore);
                // Новый бот включает реальную сделку в ту же серию, что и
                // виртуальные проверки: WIN/DRAW обнуляет её, LOSS продолжает.
                app(BotInstrumentLossStateService::class)->recordResult(
                    $trade->bot,
                    (string) $trade->instrument,
                    (string) $trade->result,
                );
            });
            $settled++;
            if ($usedEntryFallback) {
                $settledFallback++;
            }

            // После commit: WIN/LOSS в Telegram (ошибка отправки не откатывает settlement).
            $this->notifyTelegramTradeResult($trade);
        }

        return [
            'checked' => $rows->count(),
            'settled' => $settled,
            'skipped' => $skipped,
            'awaiting_po' => $awaitingPo,
            'settled_fallback' => $settledFallback,
        ];
    }

    /**
     * Шлёт WIN/LOSS/DRAW в Telegram, если у бота включены telegram + notify_result.
     * Сбой сети/токена только логируем — сделка уже settled.
     */
    private function notifyTelegramTradeResult(BotTrade $trade): void
    {
        try {
            $result = app(TelegramAlertService::class)->sendTradeResultAlert($trade);
            if (! ($result['ok'] ?? false)) {
                $error = (string) ($result['error'] ?? 'unknown');
                // Тихие «выкл.» не засоряем warning.
                if (! in_array($error, [
                    'telegram_disabled',
                    'telegram_result_notify_disabled',
                    'telegram_notify_disabled',
                    'telegram_token_or_chat_id_missing',
                    'already_notified',
                    'bot_missing',
                ], true)) {
                    Log::warning('telegram_trade_result_failed', [
                        'trade_id' => $trade->id,
                        'bot_id' => $trade->bot_id,
                        'error' => $error,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('telegram_trade_result_exception', [
                'trade_id' => $trade->id,
                'bot_id' => $trade->bot_id,
                'error' => $e->getMessage(),
            ]);
        }
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

        // 1) Сначала пытаемся взять цену из более точного таймфрейма (например 4015 = S15).
        // 2) Если точного ряда нет, откатываемся к классическому M1.
        $storedTf = (int) config('candles.stored_timeframe_id', 4015);
        $storedPeriodSec = max(1, (int) config('candles.base_bar_period_seconds', 15));

        $precise = $this->resolvePriceFromTimeframeAtExpiry(
            instrument: $instrument,
            expiryTs: $normalizedExpiryTs,
            timeframeId: $storedTf,
            barPeriodSec: $storedPeriodSec
        );
        if ($precise !== null) {
            return $precise;
        }

        return $this->resolvePriceFromTimeframeAtExpiry(
            instrument: $instrument,
            expiryTs: $normalizedExpiryTs,
            timeframeId: 1,
            barPeriodSec: 60
        );
    }

    /**
     * Публичный доступ к единому расчёту цены на экспирации.
     * Виртуальные сигналы используют его, чтобы не расходиться с paper-сделками
     * на S30/M1 из-за разных правил open/close или временного дрейфа свечей.
     */
    public function marketPriceAtExpiry(string $instrument, int $expiryTs): ?float
    {
        return $this->resolveSettlementPriceAtExpiry($instrument, $expiryTs);
    }

    /**
     * Вычисляет settlement-цену в рамках конкретного timeframe.
     *
     * Правило выбора цены:
     * - если экспирация попала ВНУТРЬ бара (expiryTs > barStart), берём open бара;
     * - если экспирация совпала с началом бара (expiryTs == barStart), берём close этого бара;
     * - если свеча по точному bucket не найдена, берём ближайшую свечу <= expiryTs и также
     *   применяем правило open/close по позиции expiry внутри этого бара.
     */
    private function resolvePriceFromTimeframeAtExpiry(
        string $instrument,
        int $expiryTs,
        int $timeframeId,
        int $barPeriodSec
    ): ?float {
        $barStartTs = (int) (floor($expiryTs / $barPeriodSec) * $barPeriodSec);
        $candle = InstrumentCandle::query()
            ->forInstrument($instrument)
            ->forTimeframe($timeframeId)
            ->where('time', $barStartTs)
            ->first(['time', 'open', 'close']);

        if (! $candle) {
            $candle = InstrumentCandle::query()
                ->forInstrument($instrument)
                ->forTimeframe($timeframeId)
                ->where('time', '<=', $expiryTs)
                ->orderByDesc('time')
                ->first(['time', 'open', 'close']);
        }

        if (! $candle) {
            // Для M1 поддерживаем "вариант 2": если legacy timeframe=1 пуст,
            // достраиваем минутный бар из S5 на лету.
            if ($timeframeId === 1) {
                $fromS5 = $this->resolveM1PriceFromS5AtExpiry($instrument, $expiryTs);
                if ($fromS5 !== null) {
                    return $fromS5;
                }
            }

            return null;
        }

        $candleOpenTs = (int) $candle->time;
        $isInsideBar = $expiryTs > $candleOpenTs;

        return $isInsideBar
            ? (float) $candle->open
            : (float) $candle->close;
    }

    /**
     * Восстанавливает settlement-цену M1 из S5-источника.
     *
     * Считаем ровно тот минутный бар, в который попала экспирация:
     * - если expiry внутри минуты -> используем open минуты;
     * - если expiry на границе минуты -> используем close этой минуты.
     */
    private function resolveM1PriceFromS5AtExpiry(string $instrument, int $expiryTs): ?float
    {
        $minuteStart = (int) (floor($expiryTs / 60) * 60);
        $minuteEnd = $minuteStart + 60;
        $m1 = $this->candleService->seriesForDashboard(
            instrument: $instrument,
            timeframeMinutes: 1,
            sinceUnix: $minuteStart,
            beforeUnix: $minuteEnd,
            maxBars: 1
        );
        if ($m1 === []) {
            return null;
        }
        $bar = $m1[array_key_last($m1)];
        $barOpenTs = (int) ($bar['time'] ?? $minuteStart);
        $isInsideBar = $expiryTs > $barOpenTs;

        return $isInsideBar
            ? (float) ($bar['open'] ?? 0.0)
            : (float) ($bar['close'] ?? 0.0);
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
            ->forTimeframe(CandleService::TF_S5)
            ->orderByDesc('time')
            ->first(['time']);

        // Fallback на legacy M1, если S5 по инструменту пока отсутствует.
        if (! $latestCandle) {
            $latestCandle = InstrumentCandle::query()
                ->forInstrument($instrument)
                ->forTimeframe(1)
                ->orderByDesc('time')
                ->first(['time']);
        }

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
