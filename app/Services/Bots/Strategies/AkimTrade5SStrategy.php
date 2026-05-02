<?php

namespace App\Services\Bots\Strategies;

class AkimTrade5SStrategy implements BotStrategyInterface
{
    /**
     * Для "быстрой" версии берем компактный контекст.
     * Стратегия рассчитана на мелкий ТФ (S5), поэтому акцент на локальную структуру.
     */
    private const CONTEXT_BARS = 140;

    /**
     * Окно для определения локального мини-тренда.
     */
    private const MICRO_TREND_WINDOW = 10;

    /**
     * Окно для детекции мини-боковика/накопления.
     */
    private const MICRO_RANGE_WINDOW = 14;

    public function analyze(array $closedCandles, ?array $partialCandle, array $context): ?array
    {
        if (count($closedCandles) < self::CONTEXT_BARS) {
            return null;
        }

        $bars = array_slice($closedCandles, -self::CONTEXT_BARS);
        $last = $bars[array_key_last($bars)];

        // 1) Базовый тренд для входа по направлению.
        // Здесь используем простую и устойчивую оценку:
        // наклон EMA(20) + положение цены относительно EMA.
        $emaFast = $this->ema($bars, 20);
        $emaSlow = $this->ema($bars, 50);
        $trend = null;
        if ($emaFast < $emaSlow && (float) $last['close'] < $emaFast) {
            $trend = 'down';
        } elseif ($emaFast > $emaSlow && (float) $last['close'] > $emaFast) {
            $trend = 'up';
        }
        if ($trend === null) {
            return null;
        }

        // 2) Фильтр "опасной зоны": внутри узкого мини-боковика пропускаем,
        // чтобы не ловить серию ложных входов.
        $inMiniRange = $this->isMiniRange($bars);
        if ($inMiniRange) {
            return null;
        }

        // 3) Логика сигнала из второго видео:
        // - при down-тренде ищем зеленую свечу с верхней тенью и входим PUT,
        // - при up-тренде ищем красную свечу с нижней тенью и входим CALL.
        $isBull = $this->isBull($last);
        $isBear = $this->isBear($last);
        $upperWick = $this->upperWickRatio($last);
        $lowerWick = $this->lowerWickRatio($last);

        $side = null;
        $setupTag = null;
        if ($trend === 'down' && $isBull && $upperWick >= 0.28) {
            $side = 'PUT';
            $setupTag = 'counter_candle_in_downtrend';
        } elseif ($trend === 'up' && $isBear && $lowerWick >= 0.28) {
            $side = 'CALL';
            $setupTag = 'counter_candle_in_uptrend';
        }
        if ($side === null) {
            return null;
        }

        // 4) Фильтр "после жесткого импульса":
        // если за последние несколько баров движение уже перегрето,
        // сигнал считаем менее качественным (по видео такие участки дают риски).
        $microMoveScore = $this->microMoveScore($bars);
        if ($microMoveScore > 0.92) {
            return null;
        }

        $payout = isset($context['payout']) ? (float) $context['payout'] : null;
        $payoutFactor = $payout !== null ? min(1.0, max(0.0, $payout / 100.0)) : 0.50;
        $wickScore = $trend === 'down' ? min(1.0, $upperWick / 0.55) : min(1.0, $lowerWick / 0.55);
        $trendScore = 1.0 - abs(($emaFast - $emaSlow) / max(1e-8, (float) $last['close']));
        $trendScore = max(0.0, min(1.0, 1.0 - $trendScore));

        $confidence = round(0.5 * $wickScore + 0.3 * $trendScore + 0.2 * $payoutFactor, 4);
        $confidence = max($confidence, 0.58);

        return [
            'side' => $side,
            'confidence' => $confidence,
            'reasons_short' => [
                sprintf('Локальный тренд: %s (EMA20/EMA50).', $trend),
                sprintf('Сигнальная свеча против микро-движения: %s.', $setupTag),
                sprintf('Тени сигнала: upper=%.0f%%, lower=%.0f%%.', $upperWick * 100, $lowerWick * 100),
                sprintf('Мини-боковик отсутствует, вход вне зоны накопления.'),
            ],
            'analysis_text' => sprintf(
                'AKIM5S: trend=%s; setup=%s; side=%s; conf=%.2f.',
                $trend,
                $setupTag,
                $side,
                $confidence
            ),
            'features' => [
                'trend' => $trend,
                'setup' => $setupTag,
                'ema_fast' => round($emaFast, 6),
                'ema_slow' => round($emaSlow, 6),
                'upper_wick_ratio' => round($upperWick, 4),
                'lower_wick_ratio' => round($lowerWick, 4),
                'micro_move_score' => round($microMoveScore, 4),
                'partial_present' => $partialCandle !== null,
                'payout' => $payout,
            ],
            'decision_version' => 'akim_trade_5s_v1',
        ];
    }

    private function isMiniRange(array $bars): bool
    {
        $slice = array_slice($bars, -self::MICRO_RANGE_WINDOW);
        $high = max(array_map(static fn (array $b): float => (float) $b['high'], $slice));
        $low = min(array_map(static fn (array $b): float => (float) $b['low'], $slice));
        $close = (float) $slice[array_key_last($slice)]['close'];
        $rangePct = $close > 0 ? ($high - $low) / $close : 0.0;

        return $rangePct < 0.0009;
    }

    /**
     * Нормированная оценка "насколько резко ушла цена" на последних барах.
     * Чем ближе к 1, тем более односторонний импульс и выше риск позднего входа.
     */
    private function microMoveScore(array $bars): float
    {
        $slice = array_slice($bars, -self::MICRO_TREND_WINDOW);
        $first = (float) $slice[0]['close'];
        $last = (float) $slice[array_key_last($slice)]['close'];
        $maxHigh = max(array_map(static fn (array $b): float => (float) $b['high'], $slice));
        $minLow = min(array_map(static fn (array $b): float => (float) $b['low'], $slice));
        $range = max(1e-8, $maxHigh - $minLow);
        $delta = abs($last - $first);

        return min(1.0, $delta / $range);
    }

    private function isBull(array $c): bool
    {
        return (float) $c['close'] > (float) $c['open'];
    }

    private function isBear(array $c): bool
    {
        return (float) $c['close'] < (float) $c['open'];
    }

    private function lowerWickRatio(array $c): float
    {
        $high = (float) $c['high'];
        $low = (float) $c['low'];
        $open = (float) $c['open'];
        $close = (float) $c['close'];
        $range = max(1e-8, $high - $low);
        $bodyLow = min($open, $close);
        $lowerWick = $bodyLow - $low;

        return min(1.0, max(0.0, $lowerWick / $range));
    }

    private function upperWickRatio(array $c): float
    {
        $high = (float) $c['high'];
        $low = (float) $c['low'];
        $open = (float) $c['open'];
        $close = (float) $c['close'];
        $range = max(1e-8, $high - $low);
        $bodyHigh = max($open, $close);
        $upperWick = $high - $bodyHigh;

        return min(1.0, max(0.0, $upperWick / $range));
    }

    private function ema(array $bars, int $period): float
    {
        $period = max(2, $period);
        $k = 2 / ($period + 1);
        $ema = (float) $bars[0]['close'];
        foreach ($bars as $bar) {
            $close = (float) $bar['close'];
            $ema = ($close * $k) + ($ema * (1 - $k));
        }

        return $ema;
    }
}
