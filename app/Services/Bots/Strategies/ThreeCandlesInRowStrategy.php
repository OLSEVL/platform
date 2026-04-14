<?php

namespace App\Services\Bots\Strategies;

class ThreeCandlesInRowStrategy implements BotStrategyInterface
{
    /** Сколько подряд закрытых свечей в одну сторону ищем (фиксировано по ТЗ). */
    private const STREAK = 3;

    public function analyze(array $closedCandles, ?array $partialCandle, array $context): ?array
    {
        if (count($closedCandles) < self::STREAK) {
            return null;
        }

        /** @var list<array<string,mixed>> $last */
        $last = array_slice($closedCandles, -self::STREAK);

        $side = null;
        $direction = null;
        if ($this->allBull($last)) {
            $side = 'PUT';
            $direction = 'bull';
        } elseif ($this->allBear($last)) {
            $side = 'CALL';
            $direction = 'bear';
        }

        if ($side === null) {
            return null;
        }

        $avgBody = $this->avgBodyToRangeRatio($last);
        $payout = isset($context['payout']) ? (float) $context['payout'] : null;
        $payoutFactor = $payout !== null ? min(1.0, max(0.0, $payout / 100.0)) : 0.5;

        // Простая оценка для фильтра min_confidence в движке (тела свечей + доля выплаты).
        $confidence = round(0.55 * min(1.0, $avgBody / 0.45) + 0.45 * $payoutFactor, 4);
        $confidence = max($confidence, 0.56);

        $reasons = [
            sprintf(
                'Три свечи подряд %s → вход %s на 1 период экспирации (против направления серии).',
                $direction === 'bull' ? 'вверх' : 'вниз',
                $side
            ),
            sprintf('Средняя доля тела в диапазоне: %.0f%%', $avgBody * 100),
        ];
        if ($payout !== null) {
            $reasons[] = sprintf('Payout: %.0f%%', $payout);
        }

        $analysisText = sprintf(
            'three_same_then_opposite: %d×%s → %s; confidence=%.2f.',
            self::STREAK,
            $direction,
            $side,
            $confidence
        );

        return [
            'side' => $side,
            'confidence' => $confidence,
            'reasons_short' => $reasons,
            'analysis_text' => $analysisText,
            'features' => [
                'streak_len' => self::STREAK,
                'direction' => $direction,
                'avg_body_to_range' => round($avgBody, 4),
                'payout' => $payout,
                'partial_present' => $partialCandle !== null,
            ],
            'decision_version' => 'three_same_then_opposite_v1',
        ];
    }

    /**
     * Все свечи строго бычьи (тело вверх, без доджи).
     *
     * @param  list<array<string,mixed>>  $candles
     */
    private function allBull(array $candles): bool
    {
        foreach ($candles as $c) {
            if (! ((float) $c['close'] > (float) $c['open'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Все свечи строго медвежьи.
     *
     * @param  list<array<string,mixed>>  $candles
     */
    private function allBear(array $candles): bool
    {
        foreach ($candles as $c) {
            if (! ((float) $c['close'] < (float) $c['open'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Средняя доля тела в диапазоне свечи по группе.
     *
     * @param  list<array<string,mixed>>  $candles
     */
    private function avgBodyToRangeRatio(array $candles): float
    {
        if ($candles === []) {
            return 0.0;
        }
        $sum = 0.0;
        foreach ($candles as $c) {
            $range = max(1e-8, (float) $c['high'] - (float) $c['low']);
            $body = abs((float) $c['close'] - (float) $c['open']);
            $sum += min(1.0, $body / $range);
        }

        return $sum / count($candles);
    }
}
