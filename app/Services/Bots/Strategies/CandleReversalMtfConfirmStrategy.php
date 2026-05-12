<?php

namespace App\Services\Bots\Strategies;

/**
 * MTF-стратегия разворота:
 * 1) На M5 ищем 3 закрытые свечи (impulse -> reversal -> confirm) и определяем сторону.
 * 2) На M1 требуем 3 последние закрытые свечи в ту же сторону + подтверждение по теням.
 * 3) Если M5 и M1 согласованы, отдаем side; иначе пропускаем вход.
 */
class CandleReversalMtfConfirmStrategy implements BotStrategyInterface
{
    public function analyze(array $closedCandles, ?array $partialCandle, array $context): ?array
    {
        $cfg = is_array($context['config'] ?? null) ? $context['config'] : [];
        $mtf = is_array($context['mtf'] ?? null) ? $context['mtf'] : [];
        $m5Bars = is_array($mtf['5'] ?? null) ? array_values($mtf['5']) : [];
        if (count($closedCandles) < 3 || count($m5Bars) < 3) {
            return null;
        }

        // Параметры M5 разворота (3 свечи).
        $m5MinWick = max(0.12, min(0.75, (float) ($cfg['m5_reversal_min_wick_ratio'] ?? 0.30)));
        $m5MaxBody = max(0.15, min(0.90, (float) ($cfg['m5_reversal_max_body_ratio'] ?? 0.70)));
        $m5RequireBreak = (bool) ($cfg['m5_require_break_rev_extreme'] ?? true);

        // Параметры M1 подтверждения (3 свечи).
        $m1MinWick = max(0.10, min(0.70, (float) ($cfg['m1_min_wick_ratio'] ?? 0.22)));
        $m1MinBody = max(0.05, min(0.80, (float) ($cfg['m1_min_body_ratio'] ?? 0.25)));
        $m1RequireWickDominance = (bool) ($cfg['m1_require_wick_dominance'] ?? true);
        // Где проверять тени на M1:
        // - last: только у последней закрытой свечи (типичное продолжение после M5-разворота; иначе сигналов почти нет);
        // - all: у всех трёх (максимально строго, как в ручной формулировке «3 свечи с тенями»).
        $m1WickScope = (string) ($cfg['m1_wick_requirement_scope'] ?? 'last');
        if (! in_array($m1WickScope, ['all', 'last'], true)) {
            $m1WickScope = 'last';
        }

        $side = $this->resolveM5Side($m5Bars, $m5MinWick, $m5MaxBody, $m5RequireBreak);
        if ($side === null) {
            return null;
        }

        /** @var list<array<string,mixed>> $lastM1 */
        $lastM1 = array_slice($closedCandles, -3);
        if (! $this->confirmM1Side($lastM1, $side, $m1MinWick, $m1MinBody, $m1RequireWickDominance, $m1WickScope)) {
            return null;
        }

        $payout = isset($context['payout']) ? (float) $context['payout'] : null;
        $payoutFactor = $payout !== null ? min(1.0, max(0.0, $payout / 100.0)) : 0.5;
        $m5Reversal = $m5Bars[count($m5Bars) - 2];
        $m5Wick = $side === 'CALL' ? $this->lowerWickRatio($m5Reversal) : $this->upperWickRatio($m5Reversal);
        $m1AvgWick = $this->avgWickRatioForSide($lastM1, $side);
        $m1AvgBody = $this->avgBodyRatio($lastM1);

        $confidence = round(
            0.35 * min(1.0, $m5Wick / 0.55)
            + 0.35 * min(1.0, $m1AvgWick / max(0.1, $m1MinWick))
            + 0.10 * min(1.0, $m1AvgBody / max(0.1, $m1MinBody))
            + 0.20 * $payoutFactor,
            4
        );
        $confidence = max($confidence, 0.56);

        return [
            'side' => $side,
            'confidence' => $confidence,
            'reasons_short' => [
                sprintf('M5: 3 закрытые свечи подтверждают сторону %s.', $side),
                sprintf('M1: 3 закрытые свечи в сторону %s с подтверждением тенями.', $side),
                sprintf('M1 средние метрики: wick=%.0f%% body=%.0f%%.', $m1AvgWick * 100, $m1AvgBody * 100),
            ],
            'analysis_text' => sprintf('reversal_mtf: side=%s m5_wick=%.2f m1_wick=%.2f conf=%.2f', $side, $m5Wick, $m1AvgWick, $confidence),
            'features' => [
                'm5_side' => $side,
                'm5_reversal_wick' => round($m5Wick, 4),
                'm5_require_break' => $m5RequireBreak,
                'm1_bars_checked' => 3,
                'm1_min_wick_ratio' => round($m1MinWick, 4),
                'm1_min_body_ratio' => round($m1MinBody, 4),
                'm1_require_wick_dominance' => $m1RequireWickDominance,
                'm1_wick_requirement_scope' => $m1WickScope,
                'm1_avg_wick' => round($m1AvgWick, 4),
                'm1_avg_body' => round($m1AvgBody, 4),
                'payout' => $payout,
                'partial_present' => $partialCandle !== null,
            ],
            'decision_version' => 'candle_reversal_confirm_v2_mtf',
        ];
    }

    /**
     * @param list<array<string,mixed>> $m5Bars
     */
    private function resolveM5Side(array $m5Bars, float $m5MinWick, float $m5MaxBody, bool $m5RequireBreak): ?string
    {
        $tail = array_slice($m5Bars, -3);
        if (count($tail) < 3) {
            return null;
        }
        $impulse = $tail[0];
        $reversal = $tail[1];
        $confirm = $tail[2];

        $call = $this->isBear($impulse)
            && $this->isBull($reversal)
            && $this->lowerWickRatio($reversal) >= $m5MinWick
            && $this->bodyToRangeRatio($reversal) <= $m5MaxBody
            && $this->isBull($confirm)
            && (! $m5RequireBreak || (float) $confirm['close'] > (float) $reversal['high']);

        $put = $this->isBull($impulse)
            && $this->isBear($reversal)
            && $this->upperWickRatio($reversal) >= $m5MinWick
            && $this->bodyToRangeRatio($reversal) <= $m5MaxBody
            && $this->isBear($confirm)
            && (! $m5RequireBreak || (float) $confirm['close'] < (float) $reversal['low']);

        if ($call && ! $put) {
            return 'CALL';
        }
        if ($put && ! $call) {
            return 'PUT';
        }

        return null;
    }

    /**
     * @param list<array<string,mixed>> $lastM1
     */
    private function confirmM1Side(
        array $lastM1,
        string $side,
        float $m1MinWick,
        float $m1MinBody,
        bool $m1RequireWickDominance,
        string $m1WickScope
    ): bool {
        // Сбрасываем ключи: array_slice сохраняет исходные индексы закрытого ряда.
        /** @var list<array<string,mixed>> $bars */
        $bars = array_values($lastM1);
        if (count($bars) < 3) {
            return false;
        }
        $lastIdx = count($bars) - 1;
        foreach ($bars as $idx => $bar) {
            if ($side === 'CALL' && ! $this->isBull($bar)) {
                return false;
            }
            if ($side === 'PUT' && ! $this->isBear($bar)) {
                return false;
            }
            if ($this->bodyToRangeRatio($bar) < $m1MinBody) {
                return false;
            }

            $applyWickRules = $m1WickScope === 'all' || $idx === $lastIdx;
            if (! $applyWickRules) {
                continue;
            }

            $sideWick = $side === 'CALL' ? $this->lowerWickRatio($bar) : $this->upperWickRatio($bar);
            $oppWick = $side === 'CALL' ? $this->upperWickRatio($bar) : $this->lowerWickRatio($bar);
            if ($sideWick < $m1MinWick) {
                return false;
            }
            if ($m1RequireWickDominance && $sideWick <= $oppWick) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<array<string,mixed>> $bars
     */
    private function avgWickRatioForSide(array $bars, string $side): float
    {
        if ($bars === []) {
            return 0.0;
        }
        $sum = 0.0;
        foreach ($bars as $bar) {
            $sum += $side === 'CALL' ? $this->lowerWickRatio($bar) : $this->upperWickRatio($bar);
        }

        return $sum / count($bars);
    }

    /**
     * @param list<array<string,mixed>> $bars
     */
    private function avgBodyRatio(array $bars): float
    {
        if ($bars === []) {
            return 0.0;
        }
        $sum = 0.0;
        foreach ($bars as $bar) {
            $sum += $this->bodyToRangeRatio($bar);
        }

        return $sum / count($bars);
    }

    /**
     * @param array<string,mixed> $c
     */
    private function isBull(array $c): bool
    {
        return (float) $c['close'] > (float) $c['open'];
    }

    /**
     * @param array<string,mixed> $c
     */
    private function isBear(array $c): bool
    {
        return (float) $c['close'] < (float) $c['open'];
    }

    /**
     * @param array<string,mixed> $c
     */
    private function bodyToRangeRatio(array $c): float
    {
        $range = max(1e-8, (float) $c['high'] - (float) $c['low']);
        $body = abs((float) $c['close'] - (float) $c['open']);

        return min(1.0, $body / $range);
    }

    /**
     * @param array<string,mixed> $c
     */
    private function lowerWickRatio(array $c): float
    {
        $range = max(1e-8, (float) $c['high'] - (float) $c['low']);
        $bodyBottom = min((float) $c['open'], (float) $c['close']);

        return min(1.0, ($bodyBottom - (float) $c['low']) / $range);
    }

    /**
     * @param array<string,mixed> $c
     */
    private function upperWickRatio(array $c): float
    {
        $range = max(1e-8, (float) $c['high'] - (float) $c['low']);
        $bodyTop = max((float) $c['open'], (float) $c['close']);

        return min(1.0, ((float) $c['high'] - $bodyTop) / $range);
    }
}
