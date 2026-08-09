<?php

namespace App\Services\Bots\Strategies;

use App\Services\Indicators\MacdIndicatorService;
use App\Services\Indicators\MomentumIndicatorService;
use App\Support\OsEngineBinaryDecision;

/**
 * MomentumMacd — порт OsEngine Robots/Trend/MomentumMacd.cs под CALL/PUT.
 *
 * CALL: MACD > Signal && Momentum > 100.
 * PUT:  MACD < Signal && Momentum < 100.
 */
class MomentumMacdStrategy implements BotStrategyInterface
{
    public const DEFAULT_MOMENTUM_LENGTH = 5;

    public const DEFAULT_MACD_FAST = 12;

    public const DEFAULT_MACD_SLOW = 26;

    public const DEFAULT_MACD_SIGNAL = 9;

    public function __construct(
        private readonly ?MacdIndicatorService $macd = null,
        private readonly ?MomentumIndicatorService $mom = null,
    ) {}

    public function analyze(array $closedCandles, ?array $partialCandle, array $context): ?array
    {
        $cfg = is_array($context['config'] ?? null) ? $context['config'] : [];
        $momLen = max(2, min(200, (int) ($cfg['momentum_length'] ?? self::DEFAULT_MOMENTUM_LENGTH)));
        $fast = max(2, min(200, (int) ($cfg['macd_fast'] ?? self::DEFAULT_MACD_FAST)));
        $slow = max($fast + 1, min(300, (int) ($cfg['macd_slow'] ?? self::DEFAULT_MACD_SLOW)));
        $sig = max(2, min(100, (int) ($cfg['macd_signal'] ?? self::DEFAULT_MACD_SIGNAL)));
        $need = max($momLen, $slow + $sig) + 5;
        if (count($closedCandles) < $need) {
            return null;
        }

        $macdRaw = ($this->macd ?? new MacdIndicatorService)->computeSeries($closedCandles, [
            'length_fast' => $fast,
            'length_slow' => $slow,
            'length_signal' => $sig,
        ]);
        $momSeries = ($this->mom ?? new MomentumIndicatorService)->computeSeries($closedCandles, [
            'length' => $momLen,
        ]);
        $i = count($closedCandles) - 1;
        $macd = $macdRaw['macd'][$i] ?? null;
        $signal = $macdRaw['signal'][$i] ?? null;
        $mom = (float) ($momSeries[$i] ?? 0.0);
        if ($macd === null || $signal === null || $mom == 0.0) {
            return null;
        }
        $macd = (float) $macd;
        $signal = (float) $signal;

        $call = $macd > $signal && $mom > 100.0;
        $put = $macd < $signal && $mom < 100.0;
        if ($call && $put) {
            return null;
        }
        if (! $call && ! $put) {
            return null;
        }

        $side = $call ? 'CALL' : 'PUT';
        $setup = $call ? 'macd_mom_bull' : 'macd_mom_bear';
        $macdGap = abs($macd - $signal);
        $momGap = abs($mom - 100.0);
        $depth = min(1.0, ($macdGap * 1000.0) + ($momGap / 20.0));
        $payout = isset($context['payout']) ? (float) $context['payout'] : null;
        $close = (float) $closedCandles[$i]['close'];

        return OsEngineBinaryDecision::pack(
            $side,
            $setup,
            'momentum_macd_v1',
            OsEngineBinaryDecision::confidence(min(1.0, $depth), $payout),
            [
                $call
                    ? sprintf('MACD(%.8f) > Signal(%.8f) и Momentum(%.2f) > 100.', $macd, $signal, $mom)
                    : sprintf('MACD(%.8f) < Signal(%.8f) и Momentum(%.2f) < 100.', $macd, $signal, $mom),
            ],
            sprintf('momentum_macd: %s; macd=%.8f sig=%.8f mom=%.2f', $setup, $macd, $signal, $mom),
            [
                'macd' => round($macd, 8),
                'signal' => round($signal, 8),
                'momentum' => round($mom, 4),
                'close' => round($close, 8),
                'momentum_length' => $momLen,
                'macd_fast' => $fast,
                'macd_slow' => $slow,
                'macd_signal' => $sig,
                'level_price' => round($close, 8),
                'level_kind' => 'macd',
                'level_strength' => round(50.0 + 40.0 * min(1.0, $depth), 2),
                'partial_present' => $partialCandle !== null,
            ],
            $payout
        );
    }
}
