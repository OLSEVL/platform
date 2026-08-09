<?php

namespace App\Services\Bots\Strategies;

use App\Services\Indicators\RsiIndicatorService;
use App\Services\Indicators\SmaIndicatorService;

/**
 * RSI Contrtrend — порт логики OsEngine Robots/CounterTrend/RsiContrtrend.cs под бинарные CALL/PUT.
 *
 * Вход (как в OsEngine LogicOpenPosition):
 * - CALL (Buy):  close > SMA  и  RSI < downLine  — покупка перепроданности в аптренде;
 * - PUT  (Sell): close < SMA  и  RSI > upLine    — продажа перекупленности в даунтренде.
 *
 * Выход OsEngine (возврат к SMA/уровню RSI) на BO не нужен: фиксируем expiry_sec снаружи.
 */
class RsiContrtrendStrategy implements BotStrategyInterface
{
    /**
     * Дефолты под M1 OTC (sweep 2026-07-30):
     * OsEngine SMA50/RSI20/65-35 на PO даёт ~0 хитов — при RSI-экстремуме цена уже
     * по другую сторону SMA50. Короче SMA + мягче уровни → сигналы появляются.
     */
    public const DEFAULT_SMA_LENGTH = 10;

    public const DEFAULT_RSI_LENGTH = 14;

    public const DEFAULT_RSI_UP = 60.0;

    public const DEFAULT_RSI_DOWN = 40.0;

    public function __construct(
        private readonly ?SmaIndicatorService $sma = null,
        private readonly ?RsiIndicatorService $rsi = null,
    ) {}

    public function analyze(array $closedCandles, ?array $partialCandle, array $context): ?array
    {
        /** @var array<string, mixed> $cfg */
        $cfg = is_array($context['config'] ?? null) ? $context['config'] : [];

        $smaLen = max(2, min(500, (int) ($cfg['sma_length'] ?? self::DEFAULT_SMA_LENGTH)));
        $rsiLen = max(2, min(500, (int) ($cfg['rsi_length'] ?? self::DEFAULT_RSI_LENGTH)));
        $rsiUp = (float) ($cfg['rsi_up'] ?? self::DEFAULT_RSI_UP);
        $rsiDown = (float) ($cfg['rsi_down'] ?? self::DEFAULT_RSI_DOWN);

        // OsEngine: SMA warmup length+1; RSI — length+5 запас.
        $minBars = max($smaLen + 1, $rsiLen + 5);
        if (count($closedCandles) < $minBars) {
            return null;
        }

        $smaSvc = $this->sma ?? new SmaIndicatorService;
        $rsiSvc = $this->rsi ?? new RsiIndicatorService;

        $smaSeries = $smaSvc->computeSeries($closedCandles, [
            'length' => $smaLen,
            'candle_point' => 'Close',
        ]);
        $rsiSeries = $rsiSvc->computeSeries($closedCandles, [
            'length' => $rsiLen,
        ]);

        $i = count($closedCandles) - 1;
        $close = (float) $closedCandles[$i]['close'];
        $sma = (float) ($smaSeries[$i] ?? 0.0);
        $rsi = (float) ($rsiSeries[$i] ?? 0.0);

        // SMA/RSI = 0 на прогреве — сигнала нет.
        if ($sma <= 0.0 || $rsi <= 0.0) {
            return null;
        }

        $side = null;
        $setup = null;
        // OsEngine: SMA < close && RSI < down → Buy → CALL
        if ($sma < $close && $rsi < $rsiDown) {
            $side = 'CALL';
            $setup = 'rsi_oversold_in_uptrend';
        } elseif ($sma > $close && $rsi > $rsiUp) {
            // OsEngine: SMA > close && RSI > up → Sell → PUT
            $side = 'PUT';
            $setup = 'rsi_overbought_in_downtrend';
        }

        if ($side === null || $setup === null) {
            return null;
        }

        $payout = isset($context['payout']) ? (float) $context['payout'] : null;
        $payoutFactor = $payout !== null ? min(1.0, max(0.0, $payout / 100.0)) : 0.5;

        // Глубина захода в зону RSI: дальше от границы → увереннее.
        $zoneDepth = $side === 'CALL'
            ? min(1.0, max(0.0, ($rsiDown - $rsi) / max(1.0, $rsiDown)))
            : min(1.0, max(0.0, ($rsi - $rsiUp) / max(1.0, 100.0 - $rsiUp)));

        // Дистанция цены от SMA в долях ATR-независимом «относительном» виде от цены.
        $smaDist = min(1.0, abs($close - $sma) / max(abs($close), 1e-9) * 200.0);

        $confidence = round(
            0.45 * (0.55 + 0.45 * $zoneDepth)
            + 0.25 * (0.5 + 0.5 * $smaDist)
            + 0.30 * $payoutFactor,
            4
        );
        $confidence = max(0.56, min(0.95, $confidence));

        $reasons = [
            $side === 'CALL'
                ? sprintf('Цена выше SMA(%d), RSI(%.1f) < %.0f — отскок вверх в аптренде.', $smaLen, $rsi, $rsiDown)
                : sprintf('Цена ниже SMA(%d), RSI(%.1f) > %.0f — отскок вниз в даунтренде.', $smaLen, $rsi, $rsiUp),
            sprintf('SMA=%.8f · Close=%.8f · RSI=%.2f', $sma, $close, $rsi),
        ];
        if ($payout !== null) {
            $reasons[] = sprintf('Payout: %.0f%%', $payout);
        }

        return [
            'side' => $side,
            'confidence' => $confidence,
            'reasons_short' => $reasons,
            'analysis_text' => sprintf(
                'rsi_contrtrend: %s; sma=%.8f close=%.8f rsi=%.2f; confidence=%.2f.',
                $setup,
                $sma,
                $close,
                $rsi,
                $confidence
            ),
            'features' => [
                'setup' => $setup,
                'sma' => round($sma, 8),
                'rsi' => round($rsi, 4),
                'close' => round($close, 8),
                'sma_length' => $smaLen,
                'rsi_length' => $rsiLen,
                'rsi_up' => $rsiUp,
                'rsi_down' => $rsiDown,
                // Для Telegram StrongLevel-алерта: линия тренда = SMA.
                'level_price' => round($sma, 8),
                'level_kind' => 'sma',
                'level_strength' => round(50.0 + 40.0 * $zoneDepth, 2),
                'entry_regime' => 'bounce',
                'payout' => $payout,
                'partial_present' => $partialCandle !== null,
            ],
            'decision_version' => 'rsi_contrtrend_v1',
        ];
    }
}
