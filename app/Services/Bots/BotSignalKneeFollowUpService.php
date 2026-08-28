<?php

namespace App\Services\Bots;

use App\Models\Bot;
use App\Models\BotSignal;
use App\Models\BotTrade;
use App\Support\DemarkerDualFastConfig;
use App\Services\Mirror\MirrorTradeDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Второе (и далее) колено после LOSS предыдущего на том же сигнале.
 * Не ждёт новый DeMarker-сигнал: открывает сделку в ту же сторону сразу после settle.
 */
class BotSignalKneeFollowUpService
{
    /**
     * Если сделка — колено K < entries_per_signal и результат LOSS,
     * создаёт колено K+1. Возвращает новую сделку или null.
     */
    public function maybeOpenNextKnee(BotTrade $settledTrade): ?BotTrade
    {
        $settledTrade->loadMissing('bot', 'signal');
        $bot = $settledTrade->bot;
        if (! $bot instanceof Bot || ! DemarkerDualFastConfig::is((string) $bot->type)) {
            return null;
        }

        if ((string) $settledTrade->status !== BotTrade::STATUS_SETTLED) {
            return null;
        }
        if ((string) $settledTrade->result !== BotTrade::RESULT_LOSS) {
            return null;
        }

        $meta = is_array($settledTrade->meta_json) ? $settledTrade->meta_json : [];
        $knee = max(1, (int) ($meta['knee'] ?? 1));
        $entriesPerSignal = max(1, min(5, (int) ($meta['entries_per_signal']
            ?? (($bot->config ?? [])['entries_per_signal'] ?? 2))));

        // Уже последнее колено — follow-up не нужен.
        if ($knee >= $entriesPerSignal) {
            return null;
        }

        // Защита от двойного follow-up при повторном settle/retry.
        $nextKnee = $knee + 1;
        $already = BotTrade::query()
            ->where('bot_id', $bot->id)
            ->where('instrument', $settledTrade->instrument)
            ->where('meta_json->knee_parent_trade_id', $settledTrade->id)
            ->exists();
        if ($already) {
            return null;
        }

        $config = is_array($bot->config) ? $bot->config : [];
        $timeframe = (int) ($config['timeframe'] ?? \App\Services\CandleService::TF_S30);
        $expirySec = $timeframe === \App\Services\CandleService::TF_S30 ? 30 : 60;
        // Вход второго колена = цена экспирации первого (момент settle).
        $entryPrice = $settledTrade->expiry_price !== null
            ? (float) $settledTrade->expiry_price
            : (float) $settledTrade->entry_price;
        $entryAt = $settledTrade->expiry_time?->copy() ?? now();
        $expiryAt = $entryAt->copy()->addSeconds($expirySec);
        $side = strtoupper((string) $settledTrade->side);
        if ($side !== 'CALL' && $side !== 'PUT') {
            return null;
        }

        $parentSignal = $settledTrade->signal;
        $features = is_array($parentSignal?->features_json) ? $parentSignal->features_json : [];
        $features['knee'] = $nextKnee;
        $features['knee_parent_trade_id'] = $settledTrade->id;
        $features['entries_per_signal'] = $entriesPerSignal;

        $newTrade = null;
        DB::transaction(function () use (
            $bot,
            $settledTrade,
            $parentSignal,
            $side,
            $entryAt,
            $expiryAt,
            $expirySec,
            $entryPrice,
            $features,
            $knee,
            $nextKnee,
            $entriesPerSignal,
            &$newTrade
        ): void {
            $dedupeKey = sprintf(
                '%d:%s:knee%d:from:%d',
                $bot->id,
                $settledTrade->instrument,
                $nextKnee,
                $settledTrade->id
            );

            $signal = BotSignal::query()->create([
                'bot_id' => $bot->id,
                'instrument' => $settledTrade->instrument,
                'side' => $side,
                'confidence' => $parentSignal?->confidence ?? 0.7,
                'payout_snapshot' => $settledTrade->payout_snapshot,
                'signal_at' => $entryAt,
                'expiry_time' => $expiryAt,
                'reasons_json' => array_values(array_filter([
                    sprintf('Колено %d/%d после LOSS колена %d.', $nextKnee, $entriesPerSignal, $knee),
                    ...((array) ($parentSignal?->reasons_json ?? [])),
                ])),
                'analysis_text' => sprintf(
                    'demarker_dual_fast knee %d after LOSS of trade #%d',
                    $nextKnee,
                    $settledTrade->id
                ),
                'features_json' => $features,
                'decision_version' => DemarkerDualFastConfig::VERSION,
                'dedupe_key' => $dedupeKey,
            ]);

            $newTrade = BotTrade::query()->create([
                'bot_id' => $bot->id,
                'bot_signal_id' => $signal->id,
                'instrument' => $settledTrade->instrument,
                'side' => $side,
                'status' => BotTrade::STATUS_OPEN,
                'result' => BotTrade::RESULT_PENDING,
                'entry_time' => $entryAt,
                'expiry_time' => $expiryAt,
                'entry_price' => $entryPrice,
                'payout_snapshot' => $settledTrade->payout_snapshot,
                'meta_json' => [
                    'confidence' => $signal->confidence,
                    'decision_version' => DemarkerDualFastConfig::VERSION,
                    'expiry_sec_used' => $expirySec,
                    'knee' => $nextKnee,
                    'entries_per_signal' => $entriesPerSignal,
                    'knee_parent_trade_id' => $settledTrade->id,
                ],
            ]);
        });

        // Вызывающий код (settlement) уже закоммитил LOSS — зеркалим сразу.
        if ($newTrade instanceof BotTrade) {
            try {
                app(MirrorTradeDispatcher::class)->dispatch((int) $newTrade->id);
            } catch (\Throwable $e) {
                Log::warning('knee_follow_up_mirror_failed', [
                    'trade_id' => $newTrade->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $newTrade;
    }

    /**
     * Для backtest: по LOSS колена 1 строим запись колена 2 без БД BotTrade.
     *
     * @param  array<string, mixed>  $knee1Trade  буферная запись BotBacktestEngine
     * @return array<string, mixed>|null
     */
    public function nextKneeBacktestRow(array $knee1Trade, float $payout): ?array
    {
        $decision = is_array($knee1Trade['decision_json'] ?? null) ? $knee1Trade['decision_json'] : [];
        $features = is_array($decision['features'] ?? null) ? $decision['features'] : [];
        $knee = max(1, (int) ($features['knee'] ?? 1));
        $entries = max(1, min(5, (int) ($features['entries_per_signal'] ?? 2)));
        if ($knee >= $entries) {
            return null;
        }
        if (($knee1Trade['result'] ?? '') !== BotTrade::RESULT_LOSS) {
            return null;
        }

        $expirySec = (int) ($knee1Trade['expiry_sec'] ?? 30);
        $entryTs = (int) ($knee1Trade['expiry_ts'] ?? 0);
        if ($entryTs <= 0) {
            return null;
        }
        $entryPrice = (float) ($knee1Trade['expiry_price'] ?? 0);
        $expiryTs = $entryTs + $expirySec;
        $side = strtoupper((string) ($knee1Trade['side'] ?? ''));
        $nextKnee = $knee + 1;
        $features['knee'] = $nextKnee;
        $features['knee_parent_signal_ts'] = (int) ($knee1Trade['signal_ts'] ?? 0);
        $decision['features'] = $features;
        $decision['analysis_text'] = sprintf(
            'demarker_dual_fast knee %d after LOSS @ %d',
            $nextKnee,
            (int) ($knee1Trade['signal_ts'] ?? 0)
        );

        return [
            'side' => $side,
            'signal_ts' => $entryTs,
            'entry_ts' => $entryTs,
            'expiry_ts' => $expiryTs,
            'expiry_sec' => $expirySec,
            'entry_price' => $entryPrice,
            'confidence' => (float) ($knee1Trade['confidence'] ?? 0.7),
            'decision_json' => $decision,
            'payout_snapshot' => $payout,
        ];
    }
}
