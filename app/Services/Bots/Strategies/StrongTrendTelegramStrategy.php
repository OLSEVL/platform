<?php

namespace App\Services\Bots\Strategies;

/**
 * Детектор сильного тренда на M1: N закрытых свечей подряд в одну сторону.
 * Режим исполнения (алерт / сделка) задаётся в config бота (execution_mode).
 */
class StrongTrendTelegramStrategy implements BotStrategyInterface
{
    /** Дефолт длины серии, если в config бота не задан streak_len. */
    private const DEFAULT_STREAK = 6;

    public function analyze(array $closedCandles, ?array $partialCandle, array $context): ?array
    {
        /** @var array<string, mixed> $cfg */
        $cfg = is_array($context['config'] ?? null) ? $context['config'] : [];
        $streakLen = max(2, min(30, (int) ($cfg['streak_len'] ?? self::DEFAULT_STREAK)));
        // strict: close>open / close<open; with_doji: close>=open / close<=open.
        $strictness = (string) ($cfg['candle_strictness'] ?? 'strict');
        // 0 = фильтр выключен; иначе средняя доля тела в диапазоне свечи должна быть не ниже порога.
        $minBodyRatio = max(0.0, min(1.0, (float) ($cfg['min_body_to_range_ratio'] ?? 0.0)));

        if (count($closedCandles) < $streakLen) {
            return null;
        }

        /** @var list<array<string, mixed>> $last */
        $last = array_slice($closedCandles, -$streakLen);

        $direction = null;
        if ($this->allBull($last, $strictness)) {
            $direction = 'up';
        } elseif ($this->allBear($last, $strictness)) {
            $direction = 'down';
        }

        if ($direction === null) {
            return null;
        }

        $avgBody = $this->avgBodyToRangeRatio($last);
        if ($minBodyRatio > 0.0 && $avgBody < $minBodyRatio) {
            return null;
        }

        $payout = isset($context['payout']) ? (float) $context['payout'] : null;
        $payoutFactor = $payout !== null ? min(1.0, max(0.0, $payout / 100.0)) : 0.5;
        $confidence = round(0.60 * min(1.0, $avgBody / 0.45) + 0.40 * $payoutFactor, 4);
        $confidence = max($confidence, 0.55);

        $strictnessLabel = $strictness === 'with_doji' ? 'с доджи' : 'строго';
        $reasons = [
            sprintf(
                '%d свечей подряд %s (%s).',
                $streakLen,
                $direction === 'up' ? 'вверх' : 'вниз',
                $strictnessLabel
            ),
            sprintf('Средняя доля тела в диапазоне: %.0f%%', $avgBody * 100),
        ];
        if ($payout !== null) {
            $reasons[] = sprintf('Payout: %.0f%%', $payout);
        }

        // side в БД: CALL = восходящий тренд, PUT = нисходящий.
        $side = $direction === 'up' ? 'CALL' : 'PUT';
        $executionMode = (string) ($cfg['execution_mode'] ?? ((bool) ($cfg['notify_only'] ?? true) ? 'alert_only' : 'signal_trade'));

        return [
            'trend' => $direction,
            'side' => $side,
            'confidence' => $confidence,
            'notify_only' => $executionMode === 'alert_only',
            'reasons_short' => $reasons,
            'analysis_text' => sprintf(
                'strong_trend_telegram: %d×%s; strictness=%s; confidence=%.2f.',
                $streakLen,
                $direction,
                $strictness,
                $confidence
            ),
            'features' => [
                'streak_len' => $streakLen,
                'trend' => $direction,
                'candle_strictness' => $strictness,
                'min_body_to_range_ratio' => $minBodyRatio,
                'avg_body_to_range' => round($avgBody, 4),
                'payout' => $payout,
                'partial_present' => $partialCandle !== null,
            ],
            'decision_version' => 'strong_trend_telegram_v1',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $candles
     */
    private function allBull(array $candles, string $strictness): bool
    {
        foreach ($candles as $c) {
            $open = (float) $c['open'];
            $close = (float) $c['close'];
            if ($strictness === 'with_doji') {
                if ($close < $open) {
                    return false;
                }
            } elseif ($close <= $open) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array<string, mixed>>  $candles
     */
    private function allBear(array $candles, string $strictness): bool
    {
        foreach ($candles as $c) {
            $open = (float) $c['open'];
            $close = (float) $c['close'];
            if ($strictness === 'with_doji') {
                if ($close > $open) {
                    return false;
                }
            } elseif ($close >= $open) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array<string, mixed>>  $candles
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
