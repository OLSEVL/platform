<?php

namespace App\Services\Bots\Strategies;

use App\Services\Indicators\SmaIndicatorService;
use App\Services\Indicators\StochasticIndicatorService;
use App\Support\OsEngineBinaryDecision;

/**
 * SmaStochastic — порт OsEngine Robots/Trend/SmaStochastic.cs под CALL/PUT.
 *
 * CALL: close > SMA + step  И  %K кросс вверх через downline.
 * PUT:  close < SMA − step  И  %K кросс вниз через upline.
 * Step OsEngine=500 (абсолют) — для OTC используем step_pct от цены.
 */
class SmaStochasticStrategy implements BotStrategyInterface
{
    public const DEFAULT_SMA_LENGTH = 14;

    public const DEFAULT_STOCH_PERIOD1 = 5;

    public const DEFAULT_STOCH_PERIOD2 = 3;

    public const DEFAULT_STOCH_PERIOD3 = 3;

    public const DEFAULT_UP_LINE = 75.0;

    public const DEFAULT_DOWN_LINE = 25.0;

    /** Доля цены (0.0003 = 0.03%) вместо абсолютного Step=500. */
    public const DEFAULT_STEP_PCT = 0.0003;

    public function __construct(
        private readonly ?SmaIndicatorService $sma = null,
        private readonly ?StochasticIndicatorService $stoch = null,
    ) {}

    public function analyze(array $closedCandles, ?array $partialCandle, array $context): ?array
    {
        $cfg = is_array($context['config'] ?? null) ? $context['config'] : [];
        $smaLen = max(2, min(500, (int) ($cfg['sma_length'] ?? self::DEFAULT_SMA_LENGTH)));
        $p1 = max(1, min(200, (int) ($cfg['stoch_period1'] ?? self::DEFAULT_STOCH_PERIOD1)));
        $p2 = max(1, min(100, (int) ($cfg['stoch_period2'] ?? self::DEFAULT_STOCH_PERIOD2)));
        $p3 = max(1, min(100, (int) ($cfg['stoch_period3'] ?? self::DEFAULT_STOCH_PERIOD3)));
        $upLine = (float) ($cfg['up_line'] ?? self::DEFAULT_UP_LINE);
        $downLine = (float) ($cfg['down_line'] ?? self::DEFAULT_DOWN_LINE);
        $stepPct = max(0.0, min(0.05, (float) ($cfg['step_pct'] ?? self::DEFAULT_STEP_PCT)));
        $need = max($smaLen, $p1 + $p2 + $p3) + 5;
        if (count($closedCandles) < $need) {
            return null;
        }

        $smaSeries = ($this->sma ?? new SmaIndicatorService)->computeSeries($closedCandles, [
            'length' => $smaLen,
            'candle_point' => 'Close',
        ]);
        $stochRaw = ($this->stoch ?? new StochasticIndicatorService)->computeSeries($closedCandles, [
            'period1' => $p1,
            'period2' => $p2,
            'period3' => $p3,
        ]);
        $i = count($closedCandles) - 1;
        $close = (float) $closedCandles[$i]['close'];
        $sma = (float) ($smaSeries[$i] ?? 0.0);
        $k = (float) ($stochRaw['k'][$i] ?? 0.0);
        $kPrev = (float) ($stochRaw['k'][$i - 1] ?? 0.0);
        if ($sma <= 0.0 || $k == 0.0 && $kPrev == 0.0) {
            // Stochastic может быть 0 легитимно, но оба нуля на прогреве — пропуск.
            if ($k == 0.0 && $kPrev == 0.0) {
                return null;
            }
        }
        if ($sma <= 0.0) {
            return null;
        }

        $step = abs($close) * $stepPct;
        $kCrossUp = $kPrev <= $downLine && $k >= $downLine;
        $kCrossDown = $kPrev >= $upLine && $k <= $upLine;
        $call = $close > ($sma + $step) && $kCrossUp;
        $put = $close < ($sma - $step) && $kCrossDown;
        if ($call && $put) {
            return null;
        }
        if (! $call && ! $put) {
            return null;
        }

        $side = $call ? 'CALL' : 'PUT';
        $setup = $call ? 'sma_stoch_bull' : 'sma_stoch_bear';
        $zoneDepth = $call
            ? min(1.0, max(0.0, ($downLine - min($kPrev, $downLine)) / max(1.0, $downLine)))
            : min(1.0, max(0.0, (max($kPrev, $upLine) - $upLine) / max(1.0, 100.0 - $upLine)));
        $payout = isset($context['payout']) ? (float) $context['payout'] : null;

        return OsEngineBinaryDecision::pack(
            $side,
            $setup,
            'sma_stochastic_v1',
            OsEngineBinaryDecision::confidence($zoneDepth, $payout),
            [
                $call
                    ? sprintf('Close > SMA+step и %%K кросс вверх через %.0f.', $downLine)
                    : sprintf('Close < SMA−step и %%K кросс вниз через %.0f.', $upLine),
                sprintf('SMA=%.5f · K=%.2f → %.2f · step=%.6f', $sma, $kPrev, $k, $step),
            ],
            sprintf('sma_stochastic: %s; sma=%.5f k=%.2f', $setup, $sma, $k),
            [
                'sma' => round($sma, 8),
                'k' => round($k, 4),
                'k_prev' => round($kPrev, 4),
                'close' => round($close, 8),
                'sma_length' => $smaLen,
                'up_line' => $upLine,
                'down_line' => $downLine,
                'step_pct' => $stepPct,
                'level_price' => round($sma, 8),
                'level_kind' => 'sma',
                'level_strength' => round(50.0 + 40.0 * $zoneDepth, 2),
                'partial_present' => $partialCandle !== null,
            ],
            $payout
        );
    }
}
