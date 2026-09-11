<?php

namespace App\Services\Bots\Strategies;

use App\Services\Indicators\RviIndicatorService;

/**
 * RVI Trade — порт OsEngine Robots/OnScriptIndicators/RviTrade.cs под CALL/PUT.
 *
 * Базовое условие OsEngine (состояние):
 * - CALL: signal < 0 и rvi > signal;
 * - PUT:  signal > 0 и rvi < signal.
 *
 * На M1 OTC это держится десятки баров подряд → тысячи сделок.
 * Поэтому по умолчанию require_cross=true: сигнал только в бар *пересечения*
 * rvi через signal (плюс signal по нужную сторону нуля), и min_signal_abs
 * отсекает слабые зоны около 0.
 *
 * Маппинг серий RviIndicatorService:
 * - DataSeries[0] / «RviUp»   → keys['rvi']
 * - DataSeries[1] / «RviDown» → keys['signal']
 */
class RviTradeStrategy implements BotStrategyInterface
{
    /** Чуть длиннее OsEngine 10 — спокойнее на M1. */
    public const DEFAULT_RVI_LENGTH = 14;

    /** Мин. |signal| от нуля — иначе зона около 0 даёт шум. */
    public const DEFAULT_MIN_SIGNAL_ABS = 0.20;

    public function __construct(
        private readonly ?RviIndicatorService $rvi = null,
    ) {}

    public function analyze(array $closedCandles, ?array $partialCandle, array $context): ?array
    {
        /** @var array<string, mixed> $cfg */
        $cfg = is_array($context['config'] ?? null) ? $context['config'] : [];

        $rviLen = max(2, min(500, (int) ($cfg['rvi_length'] ?? self::DEFAULT_RVI_LENGTH)));
        $minSignalAbs = max(0.0, (float) ($cfg['min_signal_abs'] ?? self::DEFAULT_MIN_SIGNAL_ABS));
        // true = только бар кросса rvi через signal (анти-шум).
        $requireCross = (bool) ($cfg['require_cross'] ?? true);

        // Signal появляется при index >= period+6; +1 бар для кросса.
        $minBars = $rviLen + ($requireCross ? 8 : 7);
        if (count($closedCandles) < $minBars) {
            return null;
        }

        $rviSvc = $this->rvi ?? new RviIndicatorService;
        $series = $rviSvc->computeSeries($closedCandles, [
            'period' => $rviLen,
        ]);

        $i = count($closedCandles) - 1;
        $close = (float) $closedCandles[$i]['close'];
        $rviUp = (float) ($series['rvi'][$i] ?? 0.0);
        $rviDown = (float) ($series['signal'][$i] ?? 0.0);
        $prevRviUp = (float) ($series['rvi'][$i - 1] ?? 0.0);
        $prevRviDown = (float) ($series['signal'][$i - 1] ?? 0.0);

        if ($i < $rviLen + 6) {
            return null;
        }

        // Базовое состояние OsEngine.
        $bullState = $rviDown < 0.0 && $rviUp > $rviDown;
        $bearState = $rviDown > 0.0 && $rviUp < $rviDown;

        if ($requireCross) {
            // Кросс: раньше rvi был по другую сторону signal (или равен).
            $bullCross = $prevRviUp <= $prevRviDown && $rviUp > $rviDown;
            $bearCross = $prevRviUp >= $prevRviDown && $rviUp < $rviDown;
            $bullState = $bullState && $bullCross;
            $bearState = $bearState && $bearCross;
        }

        // Фильтр силы: signal достаточно далеко от нуля.
        if (abs($rviDown) < $minSignalAbs) {
            return null;
        }

        $side = null;
        $setup = null;
        if ($bullState) {
            $side = 'CALL';
            $setup = 'rvi_bull_cross';
        } elseif ($bearState) {
            $side = 'PUT';
            $setup = 'rvi_bear_cross';
        }

        if ($side === null || $setup === null) {
            return null;
        }

        $payout = isset($context['payout']) ? (float) $context['payout'] : null;

        $sep = abs($rviUp - $rviDown);
        $zeroDist = abs($rviDown);
        $zoneDepth = min(1.0, max(0.0, 0.5 * min(1.0, $sep / 0.5) + 0.5 * min(1.0, $zeroDist / 0.5)));

        $confidence = round(
            (0.50 * (0.55 + 0.45 * $zoneDepth) + 0.20 * 0.7) / 0.70,
            4
        );
        $confidence = max(0.58, min(0.95, $confidence));

        $reasons = [
            $side === 'CALL'
                ? sprintf('RVI bull-кросс: rvi %.3f→%.3f через signal %.3f (signal<0).', $prevRviUp, $rviUp, $rviDown)
                : sprintf('RVI bear-кросс: rvi %.3f→%.3f через signal %.3f (signal>0).', $prevRviUp, $rviUp, $rviDown),
            sprintf('RVI period=%d · |signal|≥%.2f · Close=%.8f', $rviLen, $minSignalAbs, $close),
        ];
        if ($payout !== null) {
            $reasons[] = sprintf('Payout: %.0f%%', $payout);
        }

        return [
            'side' => $side,
            'confidence' => $confidence,
            'reasons_short' => $reasons,
            'analysis_text' => sprintf(
                'rvi_trade: %s; rvi=%.3f signal=%.3f close=%.8f; confidence=%.2f.',
                $setup,
                $rviUp,
                $rviDown,
                $close,
                $confidence
            ),
            'features' => [
                'setup' => $setup,
                'rvi' => round($rviUp, 4),
                'rvi_signal' => round($rviDown, 4),
                'rvi_prev' => round($prevRviUp, 4),
                'rvi_signal_prev' => round($prevRviDown, 4),
                'close' => round($close, 8),
                'rvi_length' => $rviLen,
                'min_signal_abs' => $minSignalAbs,
                'require_cross' => $requireCross,
                'level_price' => round($close, 8),
                'level_kind' => 'rvi',
                'level_strength' => round(50.0 + 40.0 * $zoneDepth, 2),
                'entry_regime' => 'bounce',
                'payout' => $payout,
                'partial_present' => $partialCandle !== null,
            ],
            'decision_version' => 'rvi_trade_v1',
        ];
    }
}
