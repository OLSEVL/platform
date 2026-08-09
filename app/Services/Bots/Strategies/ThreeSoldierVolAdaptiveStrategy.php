<?php

namespace App\Services\Bots\Strategies;

use App\Support\OsEngineBinaryDecision;
use App\Support\OsEngineDailyVolatilityAdaptive;

/**
 * ThreeSoldierVolatilityAdaptive — порт OsEngine Patterns под CALL/PUT.
 *
 * Как ThreeSoldier, но HeightSoldiers / MinHeightOneSoldier подстраиваются
 * от средней дневной волы (доля % от avg daily H−L).
 * Stop/take от высоты паттерна не переносятся — expiry_sec.
 */
class ThreeSoldierVolAdaptiveStrategy implements BotStrategyInterface
{
    /** Fallback под OTC M1, если адаптация недоступна. */
    public const DEFAULT_HEIGHT_SOLDIERS = 0.08;

    public const DEFAULT_MIN_HEIGHT_ONE_SOLDIER = 0.02;

    public const DEFAULT_DAYS_VOLA = 1;

    /** OsEngine: Height soldiers volatility percent = 5. */
    public const DEFAULT_HEIGHT_SOLDIERS_VOLA_PERCENT = 5.0;

    /** OsEngine: Min height one soldier volatility percent = 1. */
    public const DEFAULT_MIN_ONE_VOLA_PERCENT = 1.0;

    public function analyze(array $closedCandles, ?array $partialCandle, array $context): ?array
    {
        /** @var array<string, mixed> $cfg */
        $cfg = is_array($context['config'] ?? null) ? $context['config'] : [];

        if (count($closedCandles) < 5) {
            return null;
        }

        $days = max(0, (int) ($cfg['days_volatility_adaptive'] ?? self::DEFAULT_DAYS_VOLA));
        $allShare = max(0.0, (float) ($cfg['height_soldiers_vola_percent'] ?? self::DEFAULT_HEIGHT_SOLDIERS_VOLA_PERCENT));
        $oneShare = max(0.0, (float) ($cfg['min_height_one_soldier_vola_percent'] ?? self::DEFAULT_MIN_ONE_VOLA_PERCENT));
        $fallbackAll = max(0.0, (float) ($cfg['height_soldiers'] ?? self::DEFAULT_HEIGHT_SOLDIERS));
        $fallbackOne = max(0.0, (float) ($cfg['min_height_one_soldier'] ?? self::DEFAULT_MIN_HEIGHT_ONE_SOLDIER));

        $heightSoldiers = OsEngineDailyVolatilityAdaptive::adaptHeightPercent(
            $closedCandles,
            $days,
            $allShare,
            $fallbackAll
        ) ?? $fallbackAll;
        $minOne = OsEngineDailyVolatilityAdaptive::adaptHeightPercent(
            $closedCandles,
            $days,
            $oneShare,
            $fallbackOne
        ) ?? $fallbackOne;

        $n = count($closedCandles);
        $c1 = $closedCandles[$n - 3];
        $c2 = $closedCandles[$n - 2];
        $c3 = $closedCandles[$n - 1];
        $lastClose = (float) $c3['close'];
        if ($lastClose == 0.0) {
            return null;
        }

        // Высота всего паттерна: |open[-3] − close[-1]| в % close.
        $patternPct = abs((float) $c1['open'] - $lastClose) / ($lastClose / 100.0);
        if ($patternPct < $heightSoldiers) {
            return null;
        }

        foreach ([$c1, $c2, $c3] as $bar) {
            $barClose = (float) $bar['close'];
            if ($barClose == 0.0) {
                return null;
            }
            $bodyPct = abs((float) $bar['open'] - $barClose) / ($barClose / 100.0);
            if ($bodyPct < $minOne) {
                return null;
            }
        }

        $bull = ((float) $c1['open'] < (float) $c1['close'])
            && ((float) $c2['open'] < (float) $c2['close'])
            && ((float) $c3['open'] < (float) $c3['close']);
        $bear = ((float) $c1['open'] > (float) $c1['close'])
            && ((float) $c2['open'] > (float) $c2['close'])
            && ((float) $c3['open'] > (float) $c3['close']);

        if ($bull) {
            $side = 'CALL';
            $setup = 'three_soldiers_vol_bull';
        } elseif ($bear) {
            $side = 'PUT';
            $setup = 'three_soldiers_vol_bear';
        } else {
            return null;
        }

        $payout = isset($context['payout']) ? (float) $context['payout'] : null;
        $depth = min(1.0, max(0.0, ($patternPct - $heightSoldiers) / max($heightSoldiers, 1e-9)));
        $confidence = OsEngineBinaryDecision::confidence($depth, $payout);

        $reasons = [
            $side === 'CALL'
                ? sprintf('Три бычьих солдата (vola-adaptive), высота %.3f%% (порог %.3f%%).', $patternPct, $heightSoldiers)
                : sprintf('Три медвежьих солдата (vola-adaptive), высота %.3f%% (порог %.3f%%).', $patternPct, $heightSoldiers),
            sprintf('Close=%.8f · min_one=%.3f%% · days=%d', $lastClose, $minOne, $days),
        ];
        if ($payout !== null) {
            $reasons[] = sprintf('Payout: %.0f%%', $payout);
        }

        return OsEngineBinaryDecision::pack(
            $side,
            $setup,
            'three_soldier_vol_adaptive_v1',
            $confidence,
            $reasons,
            sprintf(
                'three_soldier_vol_adaptive: %s; pattern_pct=%.4f height=%.4f close=%.8f',
                $setup,
                $patternPct,
                $heightSoldiers,
                $lastClose
            ),
            [
                'close' => round($lastClose, 8),
                'pattern_height_pct' => round($patternPct, 6),
                'height_soldiers' => round($heightSoldiers, 6),
                'min_height_one_soldier' => round($minOne, 6),
                'days_volatility_adaptive' => $days,
                'level_price' => round($lastClose, 8),
                'level_kind' => 'pattern',
                'level_strength' => round(50.0 + 40.0 * $depth, 2),
                'entry_regime' => 'impulse',
                'partial_present' => $partialCandle !== null,
            ],
            $payout,
        );
    }
}
