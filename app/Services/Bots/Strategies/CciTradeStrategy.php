<?php

namespace App\Services\Bots\Strategies;

use App\Services\Indicators\CciIndicatorService;

/**
 * CCI Trade — порт OsEngine Robots/OnScriptIndicators/CciTrade.cs под CALL/PUT.
 *
 * Вход (LogicOpenPosition):
 * - CALL (Buy):  CCI < cci_down (−150) — глубокая перепроданность;
 * - PUT  (Sell): CCI > cci_up (150)   — глубокая перекупленность.
 *
 * Выход OsEngine (возврат к противоположной линии) на BO не нужен: expiry_sec снаружи.
 */
class CciTradeStrategy implements BotStrategyInterface
{
    /** OsEngine Cci Length=20. */
    public const DEFAULT_CCI_LENGTH = 20;

    public const DEFAULT_CCI_UP = 150.0;

    public const DEFAULT_CCI_DOWN = -150.0;

    public function __construct(
        private readonly ?CciIndicatorService $cci = null,
    ) {}

    public function analyze(array $closedCandles, ?array $partialCandle, array $context): ?array
    {
        /** @var array<string, mixed> $cfg */
        $cfg = is_array($context['config'] ?? null) ? $context['config'] : [];

        $cciLen = max(2, min(500, (int) ($cfg['cci_length'] ?? self::DEFAULT_CCI_LENGTH)));
        $cciUp = (float) ($cfg['cci_up'] ?? self::DEFAULT_CCI_UP);
        $cciDown = (float) ($cfg['cci_down'] ?? self::DEFAULT_CCI_DOWN);

        // CciIndicatorService: значение появляется при index > length → запас length+2.
        $minBars = $cciLen + 2;
        if (count($closedCandles) < $minBars) {
            return null;
        }

        $cciSvc = $this->cci ?? new CciIndicatorService;
        $cciSeries = $cciSvc->computeSeries($closedCandles, [
            'length' => $cciLen,
        ]);

        $i = count($closedCandles) - 1;
        $close = (float) $closedCandles[$i]['close'];
        // API quirk: warmup = null (не 0.0) — валидный CCI может быть ≈0.
        $cciRaw = $cciSeries[$i] ?? null;
        if ($cciRaw === null) {
            return null;
        }
        $cci = (float) $cciRaw;

        $side = null;
        $setup = null;
        // OsEngine: CCI < downLine → Buy → CALL; CCI > upLine → Sell → PUT.
        if ($cci < $cciDown) {
            $side = 'CALL';
            $setup = 'cci_oversold';
        } elseif ($cci > $cciUp) {
            $side = 'PUT';
            $setup = 'cci_overbought';
        }

        if ($side === null || $setup === null) {
            return null;
        }

        $payout = isset($context['payout']) ? (float) $context['payout'] : null;
        $payoutFactor = $payout !== null ? min(1.0, max(0.0, $payout / 100.0)) : 0.5;

        // Глубина захода за ±150 относительно ширины зоны.
        $zoneDepth = $side === 'CALL'
            ? min(1.0, max(0.0, ($cciDown - $cci) / max(1.0, abs($cciDown))))
            : min(1.0, max(0.0, ($cci - $cciUp) / max(1.0, abs($cciUp))));

        $confidence = round(
            0.50 * (0.55 + 0.45 * $zoneDepth)
            + 0.20 * 0.7
            + 0.30 * $payoutFactor,
            4
        );
        $confidence = max(0.56, min(0.95, $confidence));

        $reasons = [
            $side === 'CALL'
                ? sprintf('CCI(%.2f) < %.0f — перепроданность, отскок вверх.', $cci, $cciDown)
                : sprintf('CCI(%.2f) > %.0f — перекупленность, отскок вниз.', $cci, $cciUp),
            sprintf('CCI len=%d · Up=%.0f · Down=%.0f · Close=%.5f', $cciLen, $cciUp, $cciDown, $close),
        ];
        if ($payout !== null) {
            $reasons[] = sprintf('Payout: %.0f%%', $payout);
        }

        return [
            'side' => $side,
            'confidence' => $confidence,
            'reasons_short' => $reasons,
            'analysis_text' => sprintf(
                'cci_trade: %s; cci=%.2f close=%.5f; confidence=%.2f.',
                $setup,
                $cci,
                $close,
                $confidence
            ),
            'features' => [
                'setup' => $setup,
                'cci' => round($cci, 5),
                'close' => round($close, 8),
                'cci_length' => $cciLen,
                'cci_up' => $cciUp,
                'cci_down' => $cciDown,
                'level_price' => round($close, 8),
                'level_kind' => 'cci',
                'level_strength' => round(50.0 + 40.0 * $zoneDepth, 2),
                'entry_regime' => 'bounce',
                'payout' => $payout,
                'partial_present' => $partialCandle !== null,
            ],
            'decision_version' => 'cci_trade_v1',
        ];
    }
}
