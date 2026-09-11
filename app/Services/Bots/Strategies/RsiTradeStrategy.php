<?php

namespace App\Services\Bots\Strategies;

use App\Services\Indicators\RsiIndicatorService;

/**
 * RSI Trade — порт OsEngine Robots/OnScriptIndicators/RsiTrade.cs под CALL/PUT.
 *
 * ВАЖНО: это CROSS-логика, НЕ зональная как RsiContrtrendStrategy.
 * Вход (LogicOpenPosition) по двум последним значениям RSI:
 * - CALL (Buy):  prevRsi < rsi_down AND currRsi > rsi_down — выход из перепроданности вверх;
 * - PUT  (Sell): prevRsi > rsi_up   AND currRsi < rsi_up   — выход из перекупленности вниз.
 *
 * Выход OsEngine (обратный кросс) на BO не нужен: expiry_sec снаружи.
 */
class RsiTradeStrategy implements BotStrategyInterface
{
    /** M1 OTC: короче OsEngine 20. */
    public const DEFAULT_RSI_LENGTH = 14;

    /** OsEngine Up Line=65 / Down Line=35. */
    public const DEFAULT_RSI_UP = 65.0;

    public const DEFAULT_RSI_DOWN = 35.0;

    public function __construct(
        private readonly ?RsiIndicatorService $rsi = null,
    ) {}

    public function analyze(array $closedCandles, ?array $partialCandle, array $context): ?array
    {
        /** @var array<string, mixed> $cfg */
        $cfg = is_array($context['config'] ?? null) ? $context['config'] : [];

        $rsiLen = max(2, min(500, (int) ($cfg['rsi_length'] ?? self::DEFAULT_RSI_LENGTH)));
        $rsiUp = (float) ($cfg['rsi_up'] ?? self::DEFAULT_RSI_UP);
        $rsiDown = (float) ($cfg['rsi_down'] ?? self::DEFAULT_RSI_DOWN);

        // OsEngine: Values.Count < length + 5 → прогрев; нужны 2 валидных RSI.
        $minBars = $rsiLen + 5;
        if (count($closedCandles) < $minBars) {
            return null;
        }

        $rsiSvc = $this->rsi ?? new RsiIndicatorService;
        $rsiSeries = $rsiSvc->computeSeries($closedCandles, [
            'length' => $rsiLen,
        ]);

        $i = count($closedCandles) - 1;
        if ($i < 1) {
            return null;
        }

        $close = (float) $closedCandles[$i]['close'];
        $currRsi = (float) ($rsiSeries[$i] ?? 0.0);
        $prevRsi = (float) ($rsiSeries[$i - 1] ?? 0.0);

        // 0 на прогреве — нет двух валидных значений для кросса.
        if ($currRsi <= 0.0 || $prevRsi <= 0.0) {
            return null;
        }

        $side = null;
        $setup = null;
        // CALL: пересечение down-линии снизу вверх.
        if ($prevRsi < $rsiDown && $currRsi > $rsiDown) {
            $side = 'CALL';
            $setup = 'rsi_cross_up_from_oversold';
        } elseif ($prevRsi > $rsiUp && $currRsi < $rsiUp) {
            // PUT: пересечение up-линии сверху вниз.
            $side = 'PUT';
            $setup = 'rsi_cross_down_from_overbought';
        }

        if ($side === null || $setup === null) {
            return null;
        }

        $payout = isset($context['payout']) ? (float) $context['payout'] : null;

        // Насколько далеко ушли за линию после кросса.
        $zoneDepth = $side === 'CALL'
            ? min(1.0, max(0.0, ($currRsi - $rsiDown) / max(1.0, $rsiDown)))
            : min(1.0, max(0.0, ($rsiUp - $currRsi) / max(1.0, 100.0 - $rsiUp)));

        $confidence = round(
            (0.50 * (0.55 + 0.45 * $zoneDepth) + 0.20 * 0.7) / 0.70,
            4
        );
        $confidence = max(0.56, min(0.95, $confidence));

        $reasons = [
            $side === 'CALL'
                ? sprintf('RSI кросс вверх через %.0f: prev=%.2f → curr=%.2f.', $rsiDown, $prevRsi, $currRsi)
                : sprintf('RSI кросс вниз через %.0f: prev=%.2f → curr=%.2f.', $rsiUp, $prevRsi, $currRsi),
            sprintf('RSI(%d) · Up=%.0f · Down=%.0f · Close=%.8f', $rsiLen, $rsiUp, $rsiDown, $close),
        ];
        if ($payout !== null) {
            $reasons[] = sprintf('Payout: %.0f%%', $payout);
        }

        return [
            'side' => $side,
            'confidence' => $confidence,
            'reasons_short' => $reasons,
            'analysis_text' => sprintf(
                'rsi_trade: %s; prev=%.2f curr=%.2f close=%.8f; confidence=%.2f.',
                $setup,
                $prevRsi,
                $currRsi,
                $close,
                $confidence
            ),
            'features' => [
                'setup' => $setup,
                'rsi' => round($currRsi, 4),
                'rsi_prev' => round($prevRsi, 4),
                'close' => round($close, 8),
                'rsi_length' => $rsiLen,
                'rsi_up' => $rsiUp,
                'rsi_down' => $rsiDown,
                'level_price' => round($close, 8),
                'level_kind' => 'rsi',
                'level_strength' => round(50.0 + 40.0 * $zoneDepth, 2),
                'entry_regime' => 'bounce',
                'payout' => $payout,
                'partial_present' => $partialCandle !== null,
            ],
            'decision_version' => 'rsi_trade_v1',
        ];
    }
}
