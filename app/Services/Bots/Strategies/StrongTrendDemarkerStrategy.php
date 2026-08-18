<?php

namespace App\Services\Bots\Strategies;

use App\Services\Indicators\DemarkerIndicatorService;

/**
 * Сигнал: выход DeMarker из зоны. Опционально его подтверждают N одноцветных
 * свечей (streak_len=0 выключает серию). Не наследует MA и контр-свечу
 * Strong Trend Telegram, но переносит строгость свечей и долю тела.
 */
class StrongTrendDemarkerStrategy implements BotStrategyInterface
{
    public function __construct(
        private readonly DemarkerIndicatorService $demarker,
    ) {}

    public function analyze(array $closedCandles, ?array $partialCandle, array $context): ?array
    {
        /** @var array<string, mixed> $config */
        $config = is_array($context['config'] ?? null) ? $context['config'] : [];
        // 0 = серия свечей выключена (сигнал только по DeMarker); 1–30 = N одноцветных закрытых.
        $streakLength = max(0, min(30, (int) ($config['streak_len'] ?? 6)));
        $period = max(1, min(500, (int) ($config['demarker_period'] ?? 6)));
        // strict: close>open / close<open; with_doji: close>=open / close<=open.
        $strictness = (string) ($config['candle_strictness'] ?? 'strict');
        if (! in_array($strictness, ['strict', 'with_doji'], true)) {
            $strictness = 'strict';
        }
        // 0 = фильтр выключен; иначе средняя доля тела в диапазоне свечи серии ≥ порога.
        $minBodyRatio = max(0.0, min(1.0, (float) ($config['min_body_to_range_ratio'] ?? 0.0)));
        // В форме зоны вводятся привычно в процентах (10/90), а DeMarker считает 0..1.
        $oversold = max(0.0, min(1.0, (float) ($config['demarker_oversold'] ?? 10) / 100));
        $overbought = max(0.0, min(1.0, (float) ($config['demarker_overbought'] ?? 90) / 100));
        if ($oversold >= $overbought || count($closedCandles) < max($streakLength, $period + 2)) {
            return null;
        }

        $demarker = $this->demarker->computeSeries($closedCandles, ['period' => $period]);
        $current = (float) $demarker[array_key_last($demarker)];
        $previous = (float) $demarker[array_key_last($demarker) - 1];

        // Выход из перепроданности → CALL, из перекупленности → PUT.
        $isCall = $previous <= $oversold && $current > $oversold;
        $isPut = $previous >= $overbought && $current < $overbought;
        // array_slice(..., 0) вернул бы всю историю — при 0 серию не берём.
        $streak = $streakLength > 0 ? array_slice($closedCandles, -$streakLength) : [];
        if ($streakLength > 0) {
            $isCall = $isCall && $this->allBull($streak, $strictness);
            $isPut = $isPut && $this->allBear($streak, $strictness);
        }
        if (! $isCall && ! $isPut) {
            return null;
        }

        // Доля тела имеет смысл только когда проверяем серию свечей.
        $avgBody = $streakLength > 0 ? $this->avgBodyToRangeRatio($streak) : 0.0;
        if ($streakLength > 0 && $minBodyRatio > 0.0 && $avgBody < $minBodyRatio) {
            return null;
        }

        $side = $isCall ? 'CALL' : 'PUT';
        $trend = $isCall ? 'up' : 'down';
        $zone = $isCall ? 'перепроданности' : 'перекупленности';
        $strictnessLabel = $strictness === 'with_doji' ? 'с доджи' : 'строго';
        $streakReason = $streakLength > 0
            ? sprintf(
                '%d закрытых свечей подряд %s (%s).',
                $streakLength,
                $trend === 'up' ? 'вверх' : 'вниз',
                $strictnessLabel
            )
            : 'Фильтр серии свечей выключен (0).';

        return [
            'trend' => $trend,
            'side' => $side,
            'confidence' => 0.65,
            'notify_only' => false,
            'reasons_short' => array_values(array_filter([
                $streakReason,
                $streakLength > 0 ? sprintf('Средняя доля тела в диапазоне: %.0f%%', $avgBody * 100) : null,
                sprintf('DeMarker(%d) вышел из зоны %s: %.1f → %.1f.', $period, $zone, $previous * 100, $current * 100),
            ])),
            'analysis_text' => sprintf(
                'strong_trend_demarker: %d×%s + DeMarker(%d) %.3f→%.3f; strictness=%s; body=%.2f; %s.',
                $streakLength,
                $trend,
                $period,
                $previous,
                $current,
                $strictness,
                $avgBody,
                $side
            ),
            'features' => [
                'streak_len' => $streakLength,
                'trend' => $trend,
                'candle_strictness' => $strictness,
                'min_body_to_range_ratio' => $minBodyRatio,
                'avg_body_to_range' => round($avgBody, 4),
                'demarker_period' => $period,
                'demarker_previous' => round($previous, 6),
                'demarker_current' => round($current, 6),
                'demarker_oversold' => $oversold,
                'demarker_overbought' => $overbought,
            ],
            'decision_version' => 'strong_trend_demarker_v1',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $candles
     */
    private function allBull(array $candles, string $strictness): bool
    {
        foreach ($candles as $candle) {
            $open = (float) $candle['open'];
            $close = (float) $candle['close'];
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
        foreach ($candles as $candle) {
            $open = (float) $candle['open'];
            $close = (float) $candle['close'];
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
     * Средняя доля тела |close−open| в диапазоне high−low по серии.
     *
     * @param  list<array<string, mixed>>  $candles
     */
    private function avgBodyToRangeRatio(array $candles): float
    {
        if ($candles === []) {
            return 0.0;
        }
        $sum = 0.0;
        foreach ($candles as $candle) {
            $range = max(1e-8, (float) $candle['high'] - (float) $candle['low']);
            $body = abs((float) $candle['close'] - (float) $candle['open']);
            $sum += min(1.0, $body / $range);
        }

        return $sum / count($candles);
    }
}
