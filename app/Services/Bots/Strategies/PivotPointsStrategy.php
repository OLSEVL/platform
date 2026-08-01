<?php

namespace App\Services\Bots\Strategies;

use App\Services\Indicators\PivotFloorIndicatorService;
use App\Support\OsEngineBinaryDecision;

/**
 * PivotPointsRobot — порт OsEngine Patterns/PivotPointsRobot.cs под CALL/PUT.
 *
 * CALL: open < R1 && close > R1 (бычий кросс R1).
 * PUT:  open > S1 && close < S1 (медвежий кросс S1).
 * На M1 OTC уровни Daily меняются раз в сутки — сигналов мало, но чистые.
 */
class PivotPointsStrategy implements BotStrategyInterface
{
    public const DEFAULT_PERIOD = 'Daily';

    public function __construct(private readonly ?PivotFloorIndicatorService $pivot = null) {}

    public function analyze(array $closedCandles, ?array $partialCandle, array $context): ?array
    {
        $cfg = is_array($context['config'] ?? null) ? $context['config'] : [];
        $period = (string) ($cfg['pivot_period'] ?? self::DEFAULT_PERIOD);
        if (! in_array($period, ['Daily', 'Weekly'], true)) {
            $period = 'Daily';
        }
        // Нужна история минимум на 2 календарных дня M1 (~1500 баров желательно; минимум 200).
        if (count($closedCandles) < 200) {
            return null;
        }

        $raw = ($this->pivot ?? new PivotFloorIndicatorService)->computeSeries($closedCandles, [
            'period' => $period,
        ]);
        $i = count($closedCandles) - 1;
        $open = (float) $closedCandles[$i]['open'];
        $close = (float) $closedCandles[$i]['close'];
        $r1 = (float) ($raw['r1'][$i] ?? 0.0);
        $s1 = (float) ($raw['s1'][$i] ?? 0.0);
        $p = (float) ($raw['p'][$i] ?? 0.0);
        if ($r1 <= 0.0 || $s1 <= 0.0) {
            return null;
        }

        $call = $close > $r1 && $open < $r1;
        $put = $close < $s1 && $open > $s1;
        if ($call && $put) {
            return null;
        }
        if (! $call && ! $put) {
            return null;
        }

        $side = $call ? 'CALL' : 'PUT';
        $setup = $call ? 'pivot_cross_r1' : 'pivot_cross_s1';
        $level = $call ? $r1 : $s1;
        $beyond = abs($close - $level);
        $span = max(abs($r1 - $s1), abs($close) * 1e-9, 1e-12);
        $depth = min(1.0, $beyond / $span);
        $payout = isset($context['payout']) ? (float) $context['payout'] : null;

        return OsEngineBinaryDecision::pack(
            $side,
            $setup,
            'pivot_points_v1',
            OsEngineBinaryDecision::confidence($depth, $payout),
            [
                $call
                    ? sprintf('Кросс R1 вверх: O=%.5f C=%.5f R1=%.5f.', $open, $close, $r1)
                    : sprintf('Кросс S1 вниз: O=%.5f C=%.5f S1=%.5f.', $open, $close, $s1),
                sprintf('Pivot P=%.5f (%s)', $p, $period),
            ],
            sprintf('pivot_points: %s; r1=%.5f s1=%.5f', $setup, $r1, $s1),
            [
                'r1' => round($r1, 8),
                's1' => round($s1, 8),
                'p' => round($p, 8),
                'open' => round($open, 8),
                'close' => round($close, 8),
                'pivot_period' => $period,
                'level_price' => round($level, 8),
                'level_kind' => 'pivot',
                'level_strength' => round(55.0 + 35.0 * $depth, 2),
                'partial_present' => $partialCandle !== null,
            ],
            $payout
        );
    }
}
