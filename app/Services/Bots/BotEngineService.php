<?php

namespace App\Services\Bots;

use App\Models\Bot;
use App\Models\BotSignal;
use App\Models\BotTrade;
use App\Models\PocketInstrument;
use App\Services\Bots\Strategies\BotStrategyInterface;
use App\Services\Bots\Strategies\ThreeCandlesInRowStrategy;
use Illuminate\Support\Facades\DB;

class BotEngineService
{
    public function __construct(
        private readonly BotDataService $botDataService,
    ) {}

    public function scanBot(Bot $bot): array
    {
        if (! $bot->is_active) {
            return ['processed' => 0, 'signals' => 0, 'trades' => 0];
        }

        $config = is_array($bot->config) ? $bot->config : [];
        $count = (int) ($config['count'] ?? 120);
        $expirySec = (int) ($config['expiry_sec'] ?? 180);
        $minConfidence = (float) ($config['min_confidence'] ?? 0.55);
        $staleAfterSec = (int) ($config['stale_after_sec'] ?? 30);

        $strategy = $this->resolveStrategy($bot->type);
        if (! $strategy) {
            return ['processed' => 0, 'signals' => 0, 'trades' => 0];
        }

        $instruments = PocketInstrument::active()
            ->orderByDesc('current_payout')
            ->orderByDesc('priority')
            ->orderBy('instrument')
            ->get(['instrument', 'current_payout']);

        $processed = 0;
        $signals = 0;
        $trades = 0;

        foreach ($instruments as $instrumentRow) {
            $instrument = (string) $instrumentRow->instrument;
            $processed++;

            $series = $this->botDataService->series($instrument, $count, true, $staleAfterSec);
            $closed = $series['closed'];
            if (count($closed) < 3) {
                continue;
            }
            $partial = $series['partial'];
            $decision = $strategy->analyze($closed, $partial, [
                'instrument' => $instrument,
                'payout' => $instrumentRow->current_payout !== null ? (float) $instrumentRow->current_payout : null,
                'config' => $config,
            ]);
            if (! $decision) {
                continue;
            }

            $confidence = (float) ($decision['confidence'] ?? 0);
            if ($confidence < $minConfidence) {
                continue;
            }

            $side = (string) ($decision['side'] ?? '');
            if (! in_array($side, ['CALL', 'PUT'], true)) {
                continue;
            }

            $bucket = intdiv(time(), 60) * 60;
            $dedupeKey = sprintf('%d:%s:%d:%s', $bot->id, $instrument, $bucket, $side);

            if (BotSignal::query()->where('dedupe_key', $dedupeKey)->exists()) {
                continue;
            }

            $hasOpenTrade = BotTrade::query()
                ->where('bot_id', $bot->id)
                ->where('instrument', $instrument)
                ->where('status', BotTrade::STATUS_OPEN)
                ->exists();
            if ($hasOpenTrade) {
                continue;
            }

            $entryPrice = (float) (($partial['close'] ?? null) ?? end($closed)['close']);
            $entryAt = now();
            $expiryAt = $entryAt->copy()->addSeconds($expirySec);
            $payoutSnapshot = $instrumentRow->current_payout !== null ? (float) $instrumentRow->current_payout : null;

            DB::transaction(function () use (
                $bot,
                $instrument,
                $side,
                $confidence,
                $payoutSnapshot,
                $entryAt,
                $expiryAt,
                $decision,
                $dedupeKey,
                $entryPrice,
                &$signals,
                &$trades
            ): void {
                $signal = BotSignal::query()->create([
                    'bot_id' => $bot->id,
                    'instrument' => $instrument,
                    'side' => $side,
                    'confidence' => $confidence,
                    'payout_snapshot' => $payoutSnapshot,
                    'signal_at' => $entryAt,
                    'expiry_time' => $expiryAt,
                    'reasons_json' => $decision['reasons_short'] ?? [],
                    'analysis_text' => (string) ($decision['analysis_text'] ?? ''),
                    'features_json' => $decision['features'] ?? [],
                    'decision_version' => (string) ($decision['decision_version'] ?? 'v1'),
                    'dedupe_key' => $dedupeKey,
                ]);

                BotTrade::query()->create([
                    'bot_id' => $bot->id,
                    'bot_signal_id' => $signal->id,
                    'instrument' => $instrument,
                    'side' => $side,
                    'status' => BotTrade::STATUS_OPEN,
                    'result' => BotTrade::RESULT_PENDING,
                    'entry_time' => $entryAt,
                    'expiry_time' => $expiryAt,
                    'entry_price' => $entryPrice,
                    'payout_snapshot' => $payoutSnapshot,
                    'meta_json' => [
                        'confidence' => $confidence,
                        'decision_version' => $signal->decision_version,
                    ],
                ]);

                $signals++;
                $trades++;
            });
        }

        return [
            'processed' => $processed,
            'signals' => $signals,
            'trades' => $trades,
        ];
    }

    private function resolveStrategy(string $type): ?BotStrategyInterface
    {
        return match ($type) {
            'three_candles_in_row' => new ThreeCandlesInRowStrategy,
            default => null,
        };
    }
}
