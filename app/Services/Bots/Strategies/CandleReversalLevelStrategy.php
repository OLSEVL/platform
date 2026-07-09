<?php

namespace App\Services\Bots\Strategies;

use App\Services\Bots\Levels\CandleReversalLevelAnalyzer;
use App\Services\Indicators\PriceActionDgtIndicatorService;
use App\Services\Indicators\SmcFlowIndicatorService;

/**
 * Candle Reversal + Confirm v3: отбитие от сильного уровня + настраиваемое
 * подтверждение M5 rejection и M1 разворотных свечей.
 */
class CandleReversalLevelStrategy implements BotStrategyInterface
{
    public function __construct(
        private readonly ?CandleReversalLevelAnalyzer $analyzer = null,
    ) {}

    public function analyze(array $closedCandles, ?array $partialCandle, array $context): ?array
    {
        /** @var array<string, mixed> $cfg */
        $cfg = is_array($context['config'] ?? null) ? $context['config'] : [];
        $mtf = is_array($context['mtf'] ?? null) ? $context['mtf'] : [];
        $paParams = is_array($context['pa_params'] ?? null)
            ? $context['pa_params']
            : PriceActionDgtIndicatorService::defaultParams();
        $smcParams = is_array($context['smc_params'] ?? null)
            ? $context['smc_params']
            : SmcFlowIndicatorService::defaultParams();
        $payout = isset($context['payout']) ? (float) $context['payout'] : null;

        $analyzer = $this->analyzer ?? new CandleReversalLevelAnalyzer(
            new PriceActionDgtIndicatorService,
            new SmcFlowIndicatorService,
        );

        return $analyzer->analyzeEntry(
            $closedCandles,
            $partialCandle,
            $cfg,
            $mtf,
            $paParams,
            $smcParams,
            $payout,
        );
    }
}
