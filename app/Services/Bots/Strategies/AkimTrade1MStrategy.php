<?php

namespace App\Services\Bots\Strategies;

class AkimTrade1MStrategy implements BotStrategyInterface
{
    /**
     * Основной lookback для оценки контекста по M1.
     * Нужен, чтобы видеть не только последнюю свечу, но и локальную структуру:
     * тренд, коррекцию и текущую "зону риска" (флет).
     */
    private const CONTEXT_BARS = 90;

    /**
     * Длина окна для оценки "узкого диапазона" (мини-боковика).
     * Если диапазон слишком узкий, вход пропускаем как шумный участок.
     */
    private const RANGE_WINDOW = 12;

    public function analyze(array $closedCandles, ?array $partialCandle, array $context): ?array
    {
        if (count($closedCandles) < self::CONTEXT_BARS) {
            return null;
        }

        // Берем только рабочий хвост: дневной контекст + локальная динамика.
        $bars = array_slice($closedCandles, -self::CONTEXT_BARS);
        $last = $bars[array_key_last($bars)];

        // 1) Определяем "направление дня" через пробой раннего диапазона дня.
        // Логика близка к идее из видео: сначала ищем стартовую структуру дня,
        // затем работаем только в сторону подтвержденного направления.
        $dayBreak = $this->dayBreakDirection($bars);
        if ($dayBreak === null) {
            return null;
        }

        // 2) Фильтр боковика: если текущий диапазон сжат, вход пропускаем.
        // Это следует правилу "внутри мини-накопления не лезть".
        if ($this->isMiniRange($bars)) {
            return null;
        }

        // 3) Вход "от отката к тренду":
        // - при bullish ждем медвежью свечу с заметной нижней тенью (реакция от поддержки),
        // - при bearish ждем бычью свечу с заметной верхней тенью.
        // Смысл: ловим продолжение основного движения после локального отката.
        $lowerWickRatio = $this->lowerWickRatio($last);
        $upperWickRatio = $this->upperWickRatio($last);
        $isBull = $this->isBull($last);
        $isBear = $this->isBear($last);

        $side = null;
        $setupTag = null;
        if ($dayBreak === 'up' && $isBear && $lowerWickRatio >= 0.32) {
            $side = 'CALL';
            $setupTag = 'pullback_to_uptrend';
        } elseif ($dayBreak === 'down' && $isBull && $upperWickRatio >= 0.32) {
            $side = 'PUT';
            $setupTag = 'pullback_to_downtrend';
        }

        if ($side === null) {
            return null;
        }

        // 4) Дополнительный фильтр силы: текущая цена должна оставаться
        // выше/ниже EMA(21) в сторону приоритетного тренда.
        $ema21 = $this->ema($bars, 21);
        if ($dayBreak === 'up' && (float) $last['close'] < $ema21) {
            return null;
        }
        if ($dayBreak === 'down' && (float) $last['close'] > $ema21) {
            return null;
        }

        // 5) Confidence собираем из:
        // - соответствия day-break направлению,
        // - качества "теневого" сигнала,
        // - положения относительно EMA,
        // - payout (если есть).
        $payout = isset($context['payout']) ? (float) $context['payout'] : null;
        $payoutFactor = $payout !== null ? min(1.0, max(0.0, $payout / 100.0)) : 0.55;
        $wickScore = $dayBreak === 'up' ? min(1.0, $lowerWickRatio / 0.6) : min(1.0, $upperWickRatio / 0.6);
        $emaScore = 1.0;
        $confidence = round(0.45 * $wickScore + 0.35 * $emaScore + 0.20 * $payoutFactor, 4);
        $confidence = max($confidence, 0.56);

        return [
            'side' => $side,
            'confidence' => $confidence,
            'reasons_short' => [
                sprintf('Дневное направление подтверждено как %s через пробой стартового диапазона.', $dayBreak),
                sprintf('Сигнал от отката к тренду: %s.', $setupTag),
                sprintf('Тень сигнальной свечи: lower=%.0f%%, upper=%.0f%%.', $lowerWickRatio * 100, $upperWickRatio * 100),
                sprintf('Цена относительно EMA21: close=%.5f, ema21=%.5f.', (float) $last['close'], $ema21),
            ],
            'analysis_text' => sprintf(
                'AKIM1M: day=%s; setup=%s; side=%s; conf=%.2f.',
                $dayBreak,
                $setupTag,
                $side,
                $confidence
            ),
            'features' => [
                'day_break_direction' => $dayBreak,
                'setup' => $setupTag,
                'lower_wick_ratio' => round($lowerWickRatio, 4),
                'upper_wick_ratio' => round($upperWickRatio, 4),
                'ema21' => round($ema21, 6),
                'close' => round((float) $last['close'], 6),
                'partial_present' => $partialCandle !== null,
                'payout' => $payout,
            ],
            'decision_version' => 'akim_trade_1m_v1',
        ];
    }

    /**
     * Направление по "пробою стартового диапазона дня":
     * - берем первые 20% свечей рабочего окна как "старт",
     * - если текущая цена выше стартового high -> up,
     * - если ниже стартового low -> down.
     */
    private function dayBreakDirection(array $bars): ?string
    {
        $n = count($bars);
        if ($n < 30) {
            return null;
        }
        $seedSize = max(12, (int) floor($n * 0.2));
        $seed = array_slice($bars, 0, $seedSize);
        $seedHigh = max(array_map(static fn (array $b): float => (float) $b['high'], $seed));
        $seedLow = min(array_map(static fn (array $b): float => (float) $b['low'], $seed));
        $lastClose = (float) $bars[array_key_last($bars)]['close'];

        if ($lastClose > $seedHigh) {
            return 'up';
        }
        if ($lastClose < $seedLow) {
            return 'down';
        }

        return null;
    }

    private function isMiniRange(array $bars): bool
    {
        $slice = array_slice($bars, -self::RANGE_WINDOW);
        $high = max(array_map(static fn (array $b): float => (float) $b['high'], $slice));
        $low = min(array_map(static fn (array $b): float => (float) $b['low'], $slice));
        $lastClose = (float) $slice[array_key_last($slice)]['close'];
        $rangePct = $lastClose > 0 ? ($high - $low) / $lastClose : 0.0;

        return $rangePct < 0.0018;
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
