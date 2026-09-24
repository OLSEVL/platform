<?php

namespace App\Services\Bots;

use App\Models\Bot;
use App\Models\BotLlmLog;
use App\Models\BotSignal;
use App\Models\BotTrade;
use App\Models\PocketInstrument;
use App\Services\Mirror\MirrorTradeDispatcher;
use App\Support\HybridAnalystConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Paper (+ опционально PO) для гибридного Chart Analyst.
 * Side — только из LLM; expiry — из source; сумма на PO — из профиля «Торговля».
 */
class HybridAnalystTradeService
{
    public function __construct(
        private readonly BotDataService $botDataService,
    ) {}

    /**
     * @param  array<string, mixed>  $cfg  config аналитика
     * @param  array<string, mixed>  $sourceSignal
     * @return array{ok:bool,skipped?:string,signal_id?:int,trade_id?:int,mirrored?:bool}
     */
    public function maybeOpenTrade(
        Bot $analystBot,
        string $instrument,
        string $analysisText,
        array $cfg,
        array $sourceSignal,
        ?int $llmLogId = null,
    ): array {
        $sourceSide = strtoupper((string) ($sourceSignal['side'] ?? ''));
        if (! HybridAnalystConfig::shouldOpenPaperTrade($cfg, $analysisText, $sourceSide)) {
            return ['ok' => false, 'skipped' => 'filters_or_paper_disabled'];
        }

        $llmSide = HybridAnalystConfig::extractRecommendationSide($analysisText);
        if (! in_array($llmSide, ['CALL', 'PUT'], true)) {
            return ['ok' => false, 'skipped' => 'llm_side_not_tradeable'];
        }

        $sourceSignalId = (int) ($sourceSignal['source_signal_id'] ?? 0);
        // Один paper на пару analyst + source_signal (не дублируем при ретрае job).
        $dedupeKey = sprintf('hybrid:%d:src_signal:%d', $analystBot->id, $sourceSignalId);
        if ($sourceSignalId > 0 && BotSignal::query()->where('dedupe_key', $dedupeKey)->exists()) {
            return ['ok' => false, 'skipped' => 'dedupe'];
        }

        // Не копить несколько open по одной паре у аналитика.
        $hasOpen = BotTrade::query()
            ->where('bot_id', $analystBot->id)
            ->where('instrument', $instrument)
            ->where('status', BotTrade::STATUS_OPEN)
            ->exists();
        if ($hasOpen) {
            return ['ok' => false, 'skipped' => 'open_trade_exists'];
        }

        $expirySec = $this->resolveExpirySec($sourceSignal);
        $confidence = $this->resolveConfidence($analysisText, $sourceSignal);
        $entryPrice = $this->resolveEntryPrice($instrument);
        if ($entryPrice === null) {
            return ['ok' => false, 'skipped' => 'no_entry_price'];
        }

        $instrumentRow = PocketInstrument::query()
            ->where('instrument', $instrument)
            ->first(['instrument', 'current_payout']);
        $payoutSnapshot = $instrumentRow?->current_payout !== null
            ? (float) $instrumentRow->current_payout
            : null;

        $wantMirror = HybridAnalystConfig::isMirrorTradesEnabled($cfg);
        $entryAt = now();
        $expiryAt = $entryAt->copy()->addSeconds($expirySec);
        $createdSignalId = 0;
        $createdTradeId = 0;

        try {
            DB::transaction(function () use (
                $analystBot,
                $instrument,
                $llmSide,
                $confidence,
                $payoutSnapshot,
                $entryAt,
                $expiryAt,
                $expirySec,
                $entryPrice,
                $dedupeKey,
                $sourceSignal,
                $analysisText,
                $llmLogId,
                $wantMirror,
                &$createdSignalId,
                &$createdTradeId,
            ): void {
                $signal = BotSignal::query()->create([
                    'bot_id' => $analystBot->id,
                    'instrument' => $instrument,
                    'side' => $llmSide,
                    'confidence' => $confidence,
                    'payout_snapshot' => $payoutSnapshot,
                    'signal_at' => $entryAt,
                    'expiry_time' => $expiryAt,
                    'reasons_json' => [
                        'hybrid',
                        'source_bot_id:'.(int) ($sourceSignal['source_bot_id'] ?? 0),
                        'source_signal_id:'.(int) ($sourceSignal['source_signal_id'] ?? 0),
                        'source_side:'.(string) ($sourceSignal['side'] ?? ''),
                    ],
                    'analysis_text' => mb_substr($analysisText, 0, 65000),
                    'features_json' => [
                        'hybrid' => true,
                        'llm_side' => $llmSide,
                        'llm_success_percent' => HybridAnalystConfig::extractSuccessPercent($analysisText),
                        'source_bot_id' => $sourceSignal['source_bot_id'] ?? null,
                        'source_bot_type' => $sourceSignal['source_bot_type'] ?? null,
                        'source_signal_id' => $sourceSignal['source_signal_id'] ?? null,
                        'source_bot_trade_id' => $sourceSignal['source_bot_trade_id'] ?? null,
                        'source_side' => $sourceSignal['side'] ?? null,
                        'expiry_sec_from_source' => $expirySec,
                    ],
                    'decision_version' => 'hybrid_analyst_v1',
                    'dedupe_key' => $dedupeKey,
                ]);
                $createdSignalId = (int) $signal->id;

                $trade = BotTrade::query()->create([
                    'bot_id' => $analystBot->id,
                    'bot_signal_id' => $signal->id,
                    'instrument' => $instrument,
                    'side' => $llmSide,
                    'status' => BotTrade::STATUS_OPEN,
                    'result' => BotTrade::RESULT_PENDING,
                    'entry_time' => $entryAt,
                    'expiry_time' => $expiryAt,
                    'entry_price' => $entryPrice,
                    'payout_snapshot' => $payoutSnapshot,
                    'meta_json' => [
                        'hybrid' => true,
                        'confidence' => $confidence,
                        'decision_version' => 'hybrid_analyst_v1',
                        'expiry_sec_used' => $expirySec,
                        'source_bot_id' => $sourceSignal['source_bot_id'] ?? null,
                        'source_signal_id' => $sourceSignal['source_signal_id'] ?? null,
                        'hybrid_mirror_requested' => $wantMirror,
                    ],
                ]);
                $createdTradeId = (int) $trade->id;

                if ($llmLogId !== null && $llmLogId > 0 && Schema::hasTable('bot_llm_logs')) {
                    BotLlmLog::query()
                        ->whereKey($llmLogId)
                        ->where('bot_id', $analystBot->id)
                        ->update([
                            'bot_signal_id' => $signal->id,
                            'bot_trade_id' => $trade->id,
                        ]);
                }
            });
        } catch (Throwable $e) {
            Log::error('hybrid_analyst_paper_failed', [
                'analyst_bot_id' => $analystBot->id,
                'instrument' => $instrument,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'skipped' => 'db_error'];
        }

        if ($createdTradeId > 0) {
            try {
                $opened = BotTrade::query()->with('bot')->find($createdTradeId);
                if ($opened) {
                    app(\App\Services\BotLiveEventsBroadcast::class)->tradeOpened($opened);
                }
            } catch (Throwable) {
                // Redis недоступен — UI на HTTP fallback.
            }
        }

        $mirrored = false;
        if ($wantMirror && $createdTradeId > 0) {
            try {
                app(MirrorTradeDispatcher::class)->dispatch($createdTradeId);
                $mirrored = true;
            } catch (Throwable $e) {
                Log::warning('hybrid_analyst_mirror_dispatch_failed', [
                    'trade_id' => $createdTradeId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'ok' => true,
            'signal_id' => $createdSignalId,
            'trade_id' => $createdTradeId,
            'mirrored' => $mirrored,
        ];
    }

    /**
     * @param  array<string, mixed>  $sourceSignal
     */
    private function resolveExpirySec(array $sourceSignal): int
    {
        $fromTradeSec = null;
        $sourceTradeId = (int) ($sourceSignal['source_bot_trade_id'] ?? 0);
        if ($sourceTradeId > 0) {
            $trade = BotTrade::query()->find($sourceTradeId);
            if ($trade) {
                $metaExpiry = (int) (($trade->meta_json['expiry_sec_used'] ?? 0));
                if ($metaExpiry >= 60) {
                    $fromTradeSec = $metaExpiry;
                } elseif ($trade->entry_time && $trade->expiry_time) {
                    $fromTradeSec = (int) abs($trade->expiry_time->getTimestamp() - $trade->entry_time->getTimestamp());
                }
            }
        }

        return HybridAnalystConfig::resolveExpirySecFromSource($sourceSignal, $fromTradeSec);
    }

    /**
     * @param  array<string, mixed>  $sourceSignal
     */
    private function resolveConfidence(string $analysisText, array $sourceSignal): float
    {
        $pct = HybridAnalystConfig::extractSuccessPercent($analysisText);
        if ($pct !== null) {
            return max(0.0, min(1.0, $pct / 100.0));
        }

        return max(0.0, min(1.0, (float) ($sourceSignal['confidence'] ?? 0.55)));
    }

    private function resolveEntryPrice(string $instrument): ?float
    {
        $series = $this->botDataService->series($instrument, 5, true, 31536000, 1);
        $partial = is_array($series['partial'] ?? null) ? $series['partial'] : null;
        if ($partial !== null && isset($partial['close'])) {
            return (float) $partial['close'];
        }
        $closed = is_array($series['closed'] ?? null) ? $series['closed'] : [];
        if ($closed !== []) {
            $last = end($closed);

            return isset($last['close']) ? (float) $last['close'] : null;
        }

        return null;
    }
}
