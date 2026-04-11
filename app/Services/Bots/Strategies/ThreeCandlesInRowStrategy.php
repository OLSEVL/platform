<?php

namespace App\Services\Bots\Strategies;

class ThreeCandlesInRowStrategy implements BotStrategyInterface
{
    public function analyze(array $closedCandles, ?array $partialCandle, array $context): ?array
    {
        if (count($closedCandles) < 3) {
            return null;
        }

        $last3 = array_slice($closedCandles, -3);
        $bull = count(array_filter($last3, fn (array $c) => $c['close'] > $c['open']));
        $bear = count(array_filter($last3, fn (array $c) => $c['close'] < $c['open']));

        $side = null;
        if ($bull === 3) {
            $side = 'CALL';
        } elseif ($bear === 3) {
            $side = 'PUT';
        }

        if ($side === null) {
            return null;
        }

        $avgBody = array_sum(array_map(
            fn (array $c) => abs((float) $c['close'] - (float) $c['open']),
            $last3
        )) / 3;

        $avgRange = array_sum(array_map(
            fn (array $c) => max(0.00000001, (float) $c['high'] - (float) $c['low']),
            $last3
        )) / 3;

        $bodyStrength = min(1.0, max(0.0, $avgBody / $avgRange));
        $payout = isset($context['payout']) ? (float) $context['payout'] : null;
        $payoutFactor = $payout !== null ? min(1.0, max(0.0, $payout / 100.0)) : 0.5;
        $confidence = round((0.65 * $bodyStrength) + (0.35 * $payoutFactor), 4);

        $reasons = [
            $side === 'CALL'
                ? '3 закрытые свечи подряд бычьи'
                : '3 закрытые свечи подряд медвежьи',
            sprintf('Средняя сила тела: %.2f%% диапазона', $bodyStrength * 100),
        ];
        if ($payout !== null) {
            $reasons[] = sprintf('Payout на момент сигнала: %.0f%%', $payout);
        }

        $analysisText = sprintf(
            'Стратегия three_candles_in_row: обнаружена последовательность из 3 %s свечей; confidence=%.2f.',
            $side === 'CALL' ? 'бычьих' : 'медвежьих',
            $confidence
        );

        return [
            'side' => $side,
            'confidence' => $confidence,
            'reasons_short' => $reasons,
            'analysis_text' => $analysisText,
            'features' => [
                'avg_body' => $avgBody,
                'avg_range' => $avgRange,
                'body_strength' => $bodyStrength,
                'payout' => $payout,
                'partial_present' => $partialCandle !== null,
            ],
            'decision_version' => 'three_candles_in_row_v1',
        ];
    }
}
