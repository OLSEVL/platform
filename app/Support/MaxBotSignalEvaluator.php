<?php

namespace App\Support;

/**
 * Ядро MaxBot: сравнение суммы тел хвоста серии с максимумом одноцветных блоков в lookback.
 * Порт find_candle_strategy_signal из docs_need_release/MaxBot/main.py.
 */
final class MaxBotSignalEvaluator
{
    private const EPSILON = 1e-12;

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float}>  $candles
     * @param  array<string, mixed>  $strategy
     * @return array<string, mixed>|null
     */
    public static function findSignal(array $candles, array $strategy): ?array
    {
        $measured = max(1, (int) ($strategy['measured_candles'] ?? 1));
        $lookback = max(2, (int) ($strategy['lookback_bars'] ?? MaxBotConfig::DEFAULT_HISTORY_BARS));
        $streakLength = max(1, (int) ($strategy['streak_length'] ?? 1));
        $streakMode = (string) ($strategy['streak_mode'] ?? 'exact');
        $thresholdPct = (float) ($strategy['threshold_pct'] ?? 100.0);
        $colorFilter = trim((string) ($strategy['color'] ?? ''));

        if ($candles === []) {
            return null;
        }

        $color = $colorFilter !== '' ? $colorFilter : self::candleColor($candles[array_key_last($candles)]);
        if ($color === null) {
            return null;
        }

        $minimumHistory = $lookback + max($streakLength, $measured) + 1;
        if (count($candles) < $minimumHistory) {
            return null;
        }

        $streak = self::currentStreakLength($candles, $color);
        if ($streakMode === 'exact') {
            if ($streak !== $streakLength) {
                return null;
            }
        } elseif ($streak < $streakLength) {
            return null;
        }

        $candidate = array_slice($candles, -$measured);
        foreach ($candidate as $candle) {
            if (self::candleColor($candle) !== $color) {
                return null;
            }
        }

        $candidateBody = array_reduce(
            $candidate,
            static fn (float $sum, array $c): float => $sum + self::candleBody($c),
            0.0
        );
        if ($candidateBody <= self::EPSILON) {
            return null;
        }

        // Эталон — блоки того же цвета в lookback ДО измеряемого хвоста (без пересечения).
        $candidateStart = count($candles) - $measured;
        $reference = array_slice($candles, $candidateStart - $lookback, $lookback);
        $referenceMaximum = 0.0;
        $blockCount = count($reference) - $measured + 1;
        for ($start = 0; $start < $blockCount; $start++) {
            $block = array_slice($reference, $start, $measured);
            if (count($block) < $measured) {
                continue;
            }
            $sameColor = true;
            foreach ($block as $candle) {
                if (self::candleColor($candle) !== $color) {
                    $sameColor = false;
                    break;
                }
            }
            if (! $sameColor) {
                continue;
            }
            $blockBody = array_reduce(
                $block,
                static fn (float $sum, array $c): float => $sum + self::candleBody($c),
                0.0
            );
            $referenceMaximum = max($referenceMaximum, $blockBody);
        }

        if ($referenceMaximum <= self::EPSILON) {
            return null;
        }

        $ratioPct = $candidateBody / $referenceMaximum * 100.0;
        if ($ratioPct + self::EPSILON < $thresholdPct) {
            return null;
        }

        $signalCandle = $candles[array_key_last($candles)];

        return [
            'strategy_id' => (string) ($strategy['id'] ?? ''),
            'signal_timestamp' => (int) ($signalCandle['time'] ?? 0),
            'color' => $color,
            'streak_length' => $streak,
            'measured_candles' => $measured,
            'ratio_pct' => $ratioPct,
            'threshold_pct' => $thresholdPct,
            'close' => (float) ($signalCandle['close'] ?? 0),
        ];
    }

    /**
     * @param  array{time?:int,open?:float,close?:float}  $candle
     */
    public static function candleColor(array $candle): ?string
    {
        $body = (float) ($candle['close'] ?? 0) - (float) ($candle['open'] ?? 0);
        if (abs($body) <= self::EPSILON) {
            return null;
        }

        return $body > 0 ? 'green' : 'red';
    }

    /**
     * @param  array{open?:float,close?:float}  $candle
     */
    public static function candleBody(array $candle): float
    {
        return abs((float) ($candle['close'] ?? 0) - (float) ($candle['open'] ?? 0));
    }

    /**
     * @param  list<array{open?:float,close?:float}>  $candles
     */
    private static function currentStreakLength(array $candles, string $color): int
    {
        $length = 0;
        for ($i = count($candles) - 1; $i >= 0; $i--) {
            if (self::candleColor($candles[$i]) !== $color) {
                break;
            }
            $length++;
        }

        return $length;
    }
}
