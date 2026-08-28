<?php

namespace App\Services\Bots\Strategies;

use App\Support\MaxBotConfig;
use App\Support\MaxBotSignalEvaluator;
use App\Support\OsEngineBinaryDecision;

/**
 * MaxBot — несколько правил ratio тел к историческому максимуму; первая сработавшая побеждает.
 */
class MaxBotStrategy implements BotStrategyInterface
{
    public function analyze(array $closedCandles, ?array $partialCandle, array $context): ?array
    {
        /** @var array<string, mixed> $cfg */
        $cfg = is_array($context['config'] ?? null) ? $context['config'] : [];
        $strategies = MaxBotConfig::enabledStrategies($cfg);
        if ($strategies === []) {
            return null;
        }

        foreach ($strategies as $strategy) {
            $timing = (string) ($strategy['signal_timing'] ?? 'live');
            $candles = $this->candlesForTiming($closedCandles, $partialCandle, $timing);
            if ($candles === []) {
                continue;
            }

            $signal = MaxBotSignalEvaluator::findSignal($candles, $strategy);
            if ($signal === null) {
                continue;
            }

            return $this->packDecision($signal, $strategy, $partialCandle, $context);
        }

        return null;
    }

    /**
     * live: закрытые + partial (или последняя закрытая в бэктесте без partial).
     * closed: только закрытые бары.
     *
     * @param  list<array<string, mixed>>  $closedCandles
     * @return list<array{time:int,open:float,high:float,low:float,close:float}>
     */
    private function candlesForTiming(array $closedCandles, ?array $partialCandle, string $timing): array
    {
        if ($timing === 'closed') {
            return $this->normalizeCandles($closedCandles);
        }

        // live: как в оригинальном MaxBot — текущий бар участвует в расчёте ratio.
        if ($partialCandle !== null) {
            $byTime = [];
            foreach ($this->normalizeCandles($closedCandles) as $candle) {
                $byTime[$candle['time']] = $candle;
            }
            $partial = $this->normalizeCandle($partialCandle);
            if ($partial !== null) {
                $byTime[$partial['time']] = $partial;
            }
            ksort($byTime);

            return array_values($byTime);
        }

        // Бэктест: partial нет — оцениваем на последней закрытой (эквивалент закрытия live-бара).
        return $this->normalizeCandles($closedCandles);
    }

    /**
     * @param  array<string, mixed>  $signal
     * @param  array<string, mixed>  $strategy
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function packDecision(array $signal, array $strategy, ?array $partialCandle, array $context): array
    {
        $color = (string) ($signal['color'] ?? 'green');
        $direction = (string) ($strategy['direction'] ?? 'trend');
        $side = $this->resolveSide($color, $direction);

        $ratioPct = (float) ($signal['ratio_pct'] ?? 0);
        $thresholdPct = (float) ($signal['threshold_pct'] ?? 100.0);
        $strategyId = (string) ($signal['strategy_id'] ?? '');
        $close = (float) ($signal['close'] ?? 0);

        $payout = isset($context['payout']) ? (float) $context['payout'] : null;
        // Чем выше ratio относительно порога — тем выше confidence.
        $depth = min(1.0, max(0.0, ($ratioPct - $thresholdPct) / max($thresholdPct, 1e-9)));
        $confidence = OsEngineBinaryDecision::confidence($depth, $payout);

        $reasons = [
            sprintf(
                '%s → %.1f%% (порог %.0f%%, серия %d %s)',
                $strategyId,
                $ratioPct,
                $thresholdPct,
                (int) ($signal['streak_length'] ?? 0),
                $color === 'green' ? 'быч.' : 'медв.'
            ),
            sprintf('Close=%.8f · measured=%d · timing=%s', $close, (int) ($signal['measured_candles'] ?? 1), (string) ($strategy['signal_timing'] ?? 'live')),
        ];
        if ($payout !== null) {
            $reasons[] = sprintf('Payout: %.0f%%', $payout);
        }

        $setup = $color === 'green' ? 'max_bot_bull' : 'max_bot_bear';

        return OsEngineBinaryDecision::pack(
            $side,
            $setup,
            MaxBotConfig::VERSION,
            $confidence,
            $reasons,
            sprintf(
                'max_bot: %s %s ratio=%.1f%% streak=%d close=%.8f',
                $strategyId,
                $setup,
                $ratioPct,
                (int) ($signal['streak_length'] ?? 0),
                $close
            ),
            [
                'strategy_id' => $strategyId,
                'signal_timestamp' => (int) ($signal['signal_timestamp'] ?? 0),
                'ratio_pct' => round($ratioPct, 4),
                'threshold_pct' => $thresholdPct,
                'streak_length' => (int) ($signal['streak_length'] ?? 0),
                'measured_candles' => (int) ($signal['measured_candles'] ?? 1),
                'impulse_color' => $color,
                'direction_mode' => $direction,
                'signal_timing' => (string) ($strategy['signal_timing'] ?? 'live'),
                'close' => round($close, 8),
                'level_price' => round($close, 8),
                'level_kind' => 'impulse',
                'level_strength' => round(50.0 + 40.0 * $depth, 2),
                'entry_regime' => 'impulse',
                'partial_present' => $partialCandle !== null,
            ],
            $payout,
        );
    }

    private function resolveSide(string $color, string $direction): string
    {
        $bullish = $color === 'green';
        if ($direction === 'counter') {
            return $bullish ? 'PUT' : 'CALL';
        }

        return $bullish ? 'CALL' : 'PUT';
    }

    /**
     * @param  list<array<string, mixed>>  $candles
     * @return list<array{time:int,open:float,high:float,low:float,close:float}>
     */
    private function normalizeCandles(array $candles): array
    {
        $out = [];
        foreach ($candles as $candle) {
            $normalized = $this->normalizeCandle($candle);
            if ($normalized !== null) {
                $out[] = $normalized;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $candle
     * @return array{time:int,open:float,high:float,low:float,close:float}|null
     */
    private function normalizeCandle(array $candle): ?array
    {
        $time = (int) ($candle['time'] ?? 0);
        if ($time <= 0) {
            return null;
        }

        return [
            'time' => $time,
            'open' => (float) ($candle['open'] ?? 0),
            'high' => (float) ($candle['high'] ?? 0),
            'low' => (float) ($candle['low'] ?? 0),
            'close' => (float) ($candle['close'] ?? 0),
        ];
    }
}
