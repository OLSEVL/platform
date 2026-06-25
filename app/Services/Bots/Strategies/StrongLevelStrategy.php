<?php

namespace App\Services\Bots\Strategies;

use App\Services\Bots\Levels\StrongLevelAnalyzer;
use App\Services\Indicators\PriceActionDgtIndicatorService;
use App\Services\Indicators\SmcFlowIndicatorService;

/**
 * Бот «сильные уровни»: конфлюенс DGT S/R + SMC rolling/3BR/RS + MTF swing.
 * Вход на отскок или пробой с подтверждением M5/M1.
 */
class StrongLevelStrategy implements BotStrategyInterface
{
    public function __construct(
        private readonly ?StrongLevelAnalyzer $analyzer = null,
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

        $analyzer = $this->analyzer ?? new StrongLevelAnalyzer(
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
