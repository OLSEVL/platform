<?php

namespace App\Services\Bots\Strategies;

use App\Services\Indicators\ParabolicSarIndicatorService;
use App\Support\OsEngineBinaryDecision;

/**
 * ParabolicSarTrade — порт OsEngine Robots/Trend/ParabolicSarTrade.cs под CALL/PUT.
 *
 * OsEngine: close > SAR → Buy, close < SAR → Sell. Для OTC по умолчанию требуем
 * кросс (смена стороны относительно предыдущего бара), иначе слишком много сигналов.
 */
class ParabolicSarTradeStrategy implements BotStrategyInterface
{
    public const DEFAULT_STEP = 0.02;

    public const DEFAULT_MAX_STEP = 0.2;

    public function __construct(private readonly ?ParabolicSarIndicatorService $sar = null) {}

    public function analyze(array $closedCandles, ?array $partialCandle, array $context): ?array
    {
        $cfg = is_array($context['config'] ?? null) ? $context['config'] : [];
        $step = max(0.001, min(1.0, (float) ($cfg['step'] ?? self::DEFAULT_STEP)));
        $maxStep = max($step, min(1.0, (float) ($cfg['max_step'] ?? self::DEFAULT_MAX_STEP)));
        $requireCross = array_key_exists('require_cross', $cfg) ? (bool) $cfg['require_cross'] : true;
        if (count($closedCandles) < 10) {
            return null;
        }

        $series = ($this->sar ?? new ParabolicSarIndicatorService)->computeSeries($closedCandles, [
            'step' => $step,
            'max_step' => $maxStep,
        ]);
        $i = count($closedCandles) - 1;
        $close = (float) $closedCandles[$i]['close'];
        $sar = (float) ($series[$i] ?? 0.0);
        $prevClose = (float) $closedCandles[$i - 1]['close'];
        $prevSar = (float) ($series[$i - 1] ?? 0.0);
        if ($sar <= 0.0 || $prevSar <= 0.0) {
            return null;
        }

        $bullNow = $close > $sar;
        $bearNow = $close < $sar;
        if (! $bullNow && ! $bearNow) {
            return null;
        }
        if ($requireCross) {
            $bullCross = $bullNow && $prevClose <= $prevSar;
            $bearCross = $bearNow && $prevClose >= $prevSar;
            if (! $bullCross && ! $bearCross) {
                return null;
            }
            $side = $bullCross ? 'CALL' : 'PUT';
            $setup = $bullCross ? 'sar_cross_bull' : 'sar_cross_bear';
        } else {
            $side = $bullNow ? 'CALL' : 'PUT';
            $setup = $bullNow ? 'sar_above' : 'sar_below';
        }

        $dist = abs($close - $sar) / max(abs($close), 1e-9);
        $depth = min(1.0, $dist * 500.0);
        $payout = isset($context['payout']) ? (float) $context['payout'] : null;

        return OsEngineBinaryDecision::pack(
            $side,
            $setup,
            'parabolic_sar_trade_v1',
            OsEngineBinaryDecision::confidence($depth, $payout),
            [
                $side === 'CALL'
                    ? sprintf('Close(%.8f) выше SAR(%.8f).', $close, $sar)
                    : sprintf('Close(%.8f) ниже SAR(%.8f).', $close, $sar),
            ],
            sprintf('parabolic_sar_trade: %s; sar=%.8f close=%.8f', $setup, $sar, $close),
            [
                'sar' => round($sar, 8),
                'close' => round($close, 8),
                'step' => $step,
                'max_step' => $maxStep,
                'require_cross' => $requireCross,
                'level_price' => round($sar, 8),
                'level_kind' => 'parabolic_sar',
                'level_strength' => round(50.0 + 40.0 * $depth, 2),
                'partial_present' => $partialCandle !== null,
            ],
            $payout
        );
    }
}
