<?php

namespace App\Services\Bots;

use App\Jobs\RunHybridAnalystAnalysisJob;
use App\Models\Bot;
use App\Support\HybridAnalystConfig;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ищет Chart Analyst с включённым гибридом и ставит LLM-анализ в очередь.
 * Source: фиксированный bot_id или текущий лидер по paper winrate.
 */
class HybridAnalystDispatcher
{
    public function __construct(
        private readonly HybridAnalystLeaderService $leaderService,
    ) {}

    /**
     * @param  array<string, mixed>  $decision  полный decision source-стратегии
     */
    public function maybeDispatch(
        Bot $sourceBot,
        int $signalId,
        string $instrument,
        string $side,
        float $confidence,
        array $decision,
        string $trigger,
        ?int $botTradeId = null,
    ): void {
        if (! in_array($trigger, HybridAnalystConfig::TRIGGERS, true)) {
            return;
        }

        $side = strtoupper(trim($side));
        if (! in_array($side, ['CALL', 'PUT'], true)) {
            return;
        }

        if ($signalId <= 0 || $instrument === '') {
            return;
        }

        // on_po_mirror: только если у source включено зеркало на PO.
        if ($trigger === HybridAnalystConfig::TRIGGER_ON_PO_MIRROR && ! $sourceBot->isMirrorTradesEnabled()) {
            return;
        }

        // Кандидат должен быть допустимого типа (Chart Analyst сам себя не слушает).
        if (! in_array($sourceBot->type, HybridAnalystConfig::SOURCE_BOT_TYPES, true)) {
            return;
        }

        $analysts = Bot::query()
            ->where('user_id', $sourceBot->user_id)
            ->where('type', 'openrouter_llm_analyst')
            ->orderBy('id')
            ->get();

        foreach ($analysts as $analyst) {
            /** @var array<string, mixed> $cfg */
            $cfg = is_array($analyst->config) ? $analyst->config : [];
            if (! HybridAnalystConfig::isEnabled($cfg)) {
                continue;
            }
            if (HybridAnalystConfig::trigger($cfg) !== $trigger) {
                continue;
            }

            if (! $this->sourceMatchesAnalyst($analyst, $cfg, $sourceBot)) {
                continue;
            }

            $sourcePayload = HybridAnalystConfig::buildSourceSignalPayload(
                (int) $sourceBot->id,
                (string) $sourceBot->name,
                (string) $sourceBot->type,
                $signalId,
                $instrument,
                $side,
                $confidence,
                $decision,
                $botTradeId,
            );

            try {
                RunHybridAnalystAnalysisJob::dispatch(
                    $analyst->id,
                    $instrument,
                    $sourcePayload,
                );
            } catch (Throwable $e) {
                Log::error('hybrid_analyst_dispatch_failed', [
                    'analyst_bot_id' => $analyst->id,
                    'source_bot_id' => $sourceBot->id,
                    'source_signal_id' => $signalId,
                    'trigger' => $trigger,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $cfg
     */
    private function sourceMatchesAnalyst(Bot $analyst, array $cfg, Bot $sourceBot): bool
    {
        if (HybridAnalystConfig::isBestWinrateSourceMode($cfg)) {
            $leaderId = $this->leaderService->resolveLeaderBotId($analyst, $cfg);

            return $leaderId > 0 && $leaderId === (int) $sourceBot->id;
        }

        return HybridAnalystConfig::sourceBotId($cfg) === (int) $sourceBot->id;
    }
}
