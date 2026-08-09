<?php

namespace App\Services\Bots\Strategies;

use App\Services\Indicators\SmaIndicatorService;

/**
 * PinBar Trade — порт OsEngine Robots/Patterns/PinBarTrade.cs под CALL/PUT.
 *
 * Вход (LogicOpenPosition) по последней свече + SMA:
 * - CALL (Buy):  close и open в верхней трети диапазона (high−low)/3 И sma < close;
 * - PUT  (Sell): close и open в нижней трети диапазона И sma > close.
 *
 * Выход OsEngine (trail stop) на BO не нужен: фиксируем expiry_sec снаружи.
 */
class PinBarTradeStrategy implements BotStrategyInterface
{
    /**
     * OsEngine Sma Period=100 слишком жёсткий для M1 OTC —
     * короче SMA → чаще совпадение направления пина и тренда.
     */
    public const DEFAULT_SMA_LENGTH = 50;

    public function __construct(
        private readonly ?SmaIndicatorService $sma = null,
    ) {}

    public function analyze(array $closedCandles, ?array $partialCandle, array $context): ?array
    {
        /** @var array<string, mixed> $cfg */
        $cfg = is_array($context['config'] ?? null) ? $context['config'] : [];

        $smaLen = max(2, min(500, (int) ($cfg['sma_length'] ?? self::DEFAULT_SMA_LENGTH)));

        // OsEngine: Values.Count < Sma.Length + 2 → выход (прогрев).
        $minBars = $smaLen + 2;
        if (count($closedCandles) < $minBars) {
            return null;
        }

        $smaSvc = $this->sma ?? new SmaIndicatorService;
        $smaSeries = $smaSvc->computeSeries($closedCandles, [
            'length' => $smaLen,
            'candle_point' => 'Close',
        ]);

        $i = count($closedCandles) - 1;
        $c = $closedCandles[$i];
        $open = (float) $c['open'];
        $high = (float) $c['high'];
        $low = (float) $c['low'];
        $close = (float) $c['close'];
        $sma = (float) ($smaSeries[$i] ?? 0.0);

        // SMA = 0 на прогреве — сигнала нет.
        if ($sma <= 0.0) {
            return null;
        }

        $range = $high - $low;
        // Плоская свеча: деление на ноль / нет «трети» диапазона.
        if ($range <= 0.0) {
            return null;
        }

        $third = $range / 3.0;
        $upperThirdFloor = $high - $third;
        $lowerThirdCeil = $low + $third;

        $side = null;
        $setup = null;
        // CALL: тело в верхней трети + цена выше SMA (бычий пин в аптренде).
        if ($close >= $upperThirdFloor && $open >= $upperThirdFloor && $sma < $close) {
            $side = 'CALL';
            $setup = 'pinbar_bull';
        } elseif ($close <= $lowerThirdCeil && $open <= $lowerThirdCeil && $sma > $close) {
            // PUT: тело в нижней трети + цена ниже SMA (медвежий пин в даунтренде).
            $side = 'PUT';
            $setup = 'pinbar_bear';
        }

        if ($side === null || $setup === null) {
            return null;
        }

        $payout = isset($context['payout']) ? (float) $context['payout'] : null;
        $payoutFactor = $payout !== null ? min(1.0, max(0.0, $payout / 100.0)) : 0.5;

        // Глубина «прижатия» к high/low внутри трети относительно всего range.
        $beyond = $side === 'CALL'
            ? ($close - $upperThirdFloor)
            : ($lowerThirdCeil - $close);
        $zoneDepth = min(1.0, max(0.0, $beyond / max($third, 1e-12)));

        $confidence = round(
            0.50 * (0.55 + 0.45 * $zoneDepth)
            + 0.20 * 0.7
            + 0.30 * $payoutFactor,
            4
        );
        $confidence = max(0.56, min(0.95, $confidence));

        $reasons = [
            $side === 'CALL'
                ? sprintf('Бычий пин: O/C в верхней 1/3 HL, SMA(%d)=%.8f < close=%.8f.', $smaLen, $sma, $close)
                : sprintf('Медвежий пин: O/C в нижней 1/3 HL, SMA(%d)=%.8f > close=%.8f.', $smaLen, $sma, $close),
            sprintf('HL=%.8f..%.8f · range=%.8f · SMA=%.8f', $low, $high, $range, $sma),
        ];
        if ($payout !== null) {
            $reasons[] = sprintf('Payout: %.0f%%', $payout);
        }

        return [
            'side' => $side,
            'confidence' => $confidence,
            'reasons_short' => $reasons,
            'analysis_text' => sprintf(
                'pinbar_trade: %s; close=%.8f sma=%.8f; confidence=%.2f.',
                $setup,
                $close,
                $sma,
                $confidence
            ),
            'features' => [
                'setup' => $setup,
                'close' => round($close, 8),
                'open' => round($open, 8),
                'high' => round($high, 8),
                'low' => round($low, 8),
                'sma' => round($sma, 8),
                'sma_length' => $smaLen,
                'level_price' => round($close, 8),
                'level_kind' => 'pinbar',
                'level_strength' => round(50.0 + 40.0 * $zoneDepth, 2),
                'entry_regime' => 'bounce',
                'payout' => $payout,
                'partial_present' => $partialCandle !== null,
            ],
            'decision_version' => 'pinbar_trade_v1',
        ];
    }
}
