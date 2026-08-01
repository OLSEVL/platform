<?php

namespace App\Services\Bots\Strategies;

use App\Support\OsEngineBinaryDecision;
use App\Support\OsEngineDailyVolatilityAdaptive;

/**
 * VolatilityAdaptiveCandlesTrader — порт OsEngine Patterns под CALL/PUT.
 *
 * Логика входа (LogicOpenPosition):
 * - тело последней свечи в % close ≥ HeightSignalCandle;
 * - бычья → CALL, медвежья → PUT.
 *
 * HeightSignalCandle адаптируется от средней дневной волы (N дней × share%).
 * Trail-stop OsEngine не переносится — выход = expiry_sec.
 */
class VolAdaptiveCandlesStrategy implements BotStrategyInterface
{
    /** Фиксированный порог, если адаптация выключена / нет истории (мягко под OTC M1). */
    public const DEFAULT_HEIGHT_SIGNAL_CANDLE = 0.05;

    public const DEFAULT_DAYS_VOLA = 1;

    /** Доля дневной волы → порог тела сигнальной свечи (OsEngine default 20). */
    public const DEFAULT_HEIGHT_SIGNAL_VOLA_PERCENT = 20.0;

    public function analyze(array $closedCandles, ?array $partialCandle, array $context): ?array
    {
        /** @var array<string, mixed> $cfg */
        $cfg = is_array($context['config'] ?? null) ? $context['config'] : [];

        if (count($closedCandles) < 5) {
            return null;
        }

        $days = max(0, (int) ($cfg['days_volatility_adaptive'] ?? self::DEFAULT_DAYS_VOLA));
        $volaShare = max(0.0, (float) ($cfg['height_signal_candle_vola_percent'] ?? self::DEFAULT_HEIGHT_SIGNAL_VOLA_PERCENT));
        $fallback = max(0.0, (float) ($cfg['height_signal_candle'] ?? self::DEFAULT_HEIGHT_SIGNAL_CANDLE));

        // Каждый analyze пересчитываем порог (stateless PHP; в OsEngine — на смене дня).
        $height = OsEngineDailyVolatilityAdaptive::adaptHeightPercent(
            $closedCandles,
            $days,
            $volaShare,
            $fallback
        );
        if ($height === null || $height <= 0.0) {
            $height = $fallback;
        }

        $n = count($closedCandles);
        $last = $closedCandles[$n - 1];
        $open = (float) $last['open'];
        $close = (float) $last['close'];
        if ($close == 0.0) {
            return null;
        }

        // |O−C| / (C/100) — как в OsEngine.
        $bodyPct = abs($open - $close) / ($close / 100.0);
        if ($bodyPct < $height) {
            return null;
        }

        $side = null;
        $setup = null;
        if ($open < $close) {
            $side = 'CALL';
            $setup = 'vol_adaptive_candle_bull';
        } elseif ($open > $close) {
            $side = 'PUT';
            $setup = 'vol_adaptive_candle_bear';
        }
        if ($side === null || $setup === null) {
            return null;
        }

        $payout = isset($context['payout']) ? (float) $context['payout'] : null;
        $depth = min(1.0, max(0.0, ($bodyPct - $height) / max($height, 1e-9)));
        $confidence = OsEngineBinaryDecision::confidence($depth, $payout);

        $reasons = [
            $side === 'CALL'
                ? sprintf('Бычья сигнальная свеча: тело %.3f%% ≥ порог %.3f%%.', $bodyPct, $height)
                : sprintf('Медвежья сигнальная свеча: тело %.3f%% ≥ порог %.3f%%.', $bodyPct, $height),
            sprintf('Close=%.5f · days=%d · vola_share=%.1f%%', $close, $days, $volaShare),
        ];
        if ($payout !== null) {
            $reasons[] = sprintf('Payout: %.0f%%', $payout);
        }

        return OsEngineBinaryDecision::pack(
            $side,
            $setup,
            'vol_adaptive_candles_v1',
            $confidence,
            $reasons,
            sprintf(
                'vol_adaptive_candles: %s; body_pct=%.4f height=%.4f close=%.5f',
                $setup,
                $bodyPct,
                $height,
                $close
            ),
            [
                'close' => round($close, 8),
                'body_pct' => round($bodyPct, 6),
                'height_signal_candle' => round($height, 6),
                'days_volatility_adaptive' => $days,
                'level_price' => round($close, 8),
                'level_kind' => 'pattern',
                'level_strength' => round(50.0 + 40.0 * $depth, 2),
                'entry_regime' => 'impulse',
                'partial_present' => $partialCandle !== null,
            ],
            $payout,
        );
    }
}
