<?php

namespace App\Services\Bots\Strategies;

/**
 * Three Soldier — порт OsEngine Robots/Patterns/ThreeSoldier.cs под CALL/PUT.
 *
 * Несмотря на комментарий «countertrend» в соседних роботах, LogicOpenPosition —
 * трендовый вход по трём солдатам:
 * - CALL (Buy):  три бычьи свечи подряд (open < close) при достаточной высоте паттерна;
 * - PUT  (Sell): три медвежьи свечи подряд (open > close).
 *
 * Фильтры высоты (в % от close, как в OsEngine):
 * - height_soldiers — мин. |open[-3] − close[-1]| / (close/100);
 * - min_height_one_soldier — мин. тело каждой из трёх свечей.
 *
 * Выход OsEngine (stop/take от высоты паттерна) на BO не нужен: expiry_sec снаружи.
 */
class ThreeSoldierStrategy implements BotStrategyInterface
{
    /**
     * OsEngine 1.0 / 0.2 слишком жёстко для M1 OTC (почти нет хитов) —
     * мягкие дефолты под микро-движения.
     */
    public const DEFAULT_HEIGHT_SOLDIERS = 0.08;

    public const DEFAULT_MIN_HEIGHT_ONE_SOLDIER = 0.02;

    public function analyze(array $closedCandles, ?array $partialCandle, array $context): ?array
    {
        /** @var array<string, mixed> $cfg */
        $cfg = is_array($context['config'] ?? null) ? $context['config'] : [];

        $heightSoldiers = max(0.0, (float) ($cfg['height_soldiers'] ?? self::DEFAULT_HEIGHT_SOLDIERS));
        $minOne = max(0.0, (float) ($cfg['min_height_one_soldier'] ?? self::DEFAULT_MIN_HEIGHT_ONE_SOLDIER));

        // Нужны индексы Count-3 .. Count-1 → минимум 5 баров с запасом как в ТЗ.
        if (count($closedCandles) < 5) {
            return null;
        }

        $n = count($closedCandles);
        $c1 = $closedCandles[$n - 3];
        $c2 = $closedCandles[$n - 2];
        $c3 = $closedCandles[$n - 1];

        $lastClose = (float) $c3['close'];
        if ($lastClose == 0.0) {
            return null;
        }

        // Высота всего паттерна в «процентах close» (как OsEngine: / (close/100)).
        $patternPct = abs((float) $c1['open'] - $lastClose) / ($lastClose / 100.0);
        if ($patternPct < $heightSoldiers) {
            return null;
        }

        // Каждая свеча должна иметь достаточное тело.
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

        $side = null;
        $setup = null;
        $bull1 = (float) $c1['open'] < (float) $c1['close'];
        $bull2 = (float) $c2['open'] < (float) $c2['close'];
        $bull3 = (float) $c3['open'] < (float) $c3['close'];
        $bear1 = (float) $c1['open'] > (float) $c1['close'];
        $bear2 = (float) $c2['open'] > (float) $c2['close'];
        $bear3 = (float) $c3['open'] > (float) $c3['close'];

        if ($bull1 && $bull2 && $bull3) {
            $side = 'CALL';
            $setup = 'three_soldiers_bull';
        } elseif ($bear1 && $bear2 && $bear3) {
            $side = 'PUT';
            $setup = 'three_soldiers_bear';
        }

        if ($side === null || $setup === null) {
            return null;
        }

        $payout = isset($context['payout']) ? (float) $context['payout'] : null;
        $payoutFactor = $payout !== null ? min(1.0, max(0.0, $payout / 100.0)) : 0.5;

        // Глубже паттерн относительно порога → выше уверенность.
        $zoneDepth = min(1.0, max(0.0, ($patternPct - $heightSoldiers) / max($heightSoldiers, 1e-9)));

        $confidence = round(
            0.50 * (0.55 + 0.45 * $zoneDepth)
            + 0.20 * 0.7
            + 0.30 * $payoutFactor,
            4
        );
        $confidence = max(0.56, min(0.95, $confidence));

        $reasons = [
            $side === 'CALL'
                ? sprintf('Три бычьих солдата, высота паттерна %.3f%% (порог %.3f%%).', $patternPct, $heightSoldiers)
                : sprintf('Три медвежьих солдата, высота паттерна %.3f%% (порог %.3f%%).', $patternPct, $heightSoldiers),
            sprintf('Close=%.8f · min_one=%.3f%%', $lastClose, $minOne),
        ];
        if ($payout !== null) {
            $reasons[] = sprintf('Payout: %.0f%%', $payout);
        }

        return [
            'side' => $side,
            'confidence' => $confidence,
            'reasons_short' => $reasons,
            'analysis_text' => sprintf(
                'three_soldier: %s; pattern_pct=%.4f close=%.8f; confidence=%.2f.',
                $setup,
                $patternPct,
                $lastClose,
                $confidence
            ),
            'features' => [
                'setup' => $setup,
                'close' => round($lastClose, 8),
                'pattern_height_pct' => round($patternPct, 6),
                'height_soldiers' => $heightSoldiers,
                'min_height_one_soldier' => $minOne,
                'level_price' => round($lastClose, 8),
                'level_kind' => 'pattern',
                'level_strength' => round(50.0 + 40.0 * $zoneDepth, 2),
                'entry_regime' => 'bounce',
                'payout' => $payout,
                'partial_present' => $partialCandle !== null,
            ],
            'decision_version' => 'three_soldier_v1',
        ];
    }
}
