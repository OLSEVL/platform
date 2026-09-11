<?php

namespace App\Services\Bots\Strategies;

use App\Services\Indicators\BollingerIndicatorService;

/**
 * Bollinger Contrtrend — порт OsEngine Robots/CounterTrend/StrategyBollinger.cs под CALL/PUT.
 *
 * Вход (LogicOpenPosition):
 * - CALL (Buy):  close < BollingerDown  — отскок от нижней полосы;
 * - PUT  (Sell): close > BollingerUp    — отскок от верхней полосы.
 *
 * Выход OsEngine (пересечение SMA15) на BO не нужен: фиксируем expiry_sec снаружи.
 * SMA в OsEngine только для закрытия позиции — на графике/карточках показываем mid BB.
 */
class BollingerContrtrendStrategy implements BotStrategyInterface
{
    /** OsEngine chart Bollinger: Length=12, Deviation=2. */
    public const DEFAULT_BB_LENGTH = 12;

    public const DEFAULT_BB_DEVIATION = 2.0;

    public function __construct(
        private readonly ?BollingerIndicatorService $bollinger = null,
    ) {}

    public function analyze(array $closedCandles, ?array $partialCandle, array $context): ?array
    {
        /** @var array<string, mixed> $cfg */
        $cfg = is_array($context['config'] ?? null) ? $context['config'] : [];

        $bbLen = max(2, min(500, (int) ($cfg['bb_length'] ?? self::DEFAULT_BB_LENGTH)));
        $bbDev = max(0.1, min(10.0, (float) ($cfg['bb_deviation'] ?? self::DEFAULT_BB_DEVIATION)));

        // OsEngine Bollinger: первое значение на index = length+1 → нужен запас length+2.
        $minBars = $bbLen + 2;
        if (count($closedCandles) < $minBars) {
            return null;
        }

        $bbSvc = $this->bollinger ?? new BollingerIndicatorService;
        $bands = $bbSvc->computeSeries($closedCandles, [
            'length' => $bbLen,
            'deviation' => $bbDev,
        ]);

        $i = count($closedCandles) - 1;
        $close = (float) $closedCandles[$i]['close'];
        $up = (float) ($bands['up'][$i] ?? 0.0);
        $down = (float) ($bands['down'][$i] ?? 0.0);
        $mid = (float) ($bands['mid'][$i] ?? 0.0);

        // 0 на прогреве — сигнала нет.
        if ($up <= 0.0 || $down <= 0.0 || $mid <= 0.0) {
            return null;
        }

        $side = null;
        $setup = null;
        // OsEngine: close < down → Buy → CALL; close > up → Sell → PUT.
        if ($close < $down) {
            $side = 'CALL';
            $setup = 'bb_touch_lower';
        } elseif ($close > $up) {
            $side = 'PUT';
            $setup = 'bb_touch_upper';
        }

        if ($side === null || $setup === null) {
            return null;
        }

        $payout = isset($context['payout']) ? (float) $context['payout'] : null;

        // Глубина за пределами полосы относительно ширины канала (up−down).
        $bandWidth = max(abs($up - $down), abs($close) * 1e-9, 1e-12);
        $beyond = $side === 'CALL'
            ? ($down - $close)
            : ($close - $up);
        $zoneDepth = min(1.0, max(0.0, $beyond / $bandWidth));

        $confidence = round(
            (0.50 * (0.55 + 0.45 * $zoneDepth) + 0.20 * 0.7) / 0.70,
            4
        );
        $confidence = max(0.56, min(0.95, $confidence));

        $bandPrice = $side === 'CALL' ? $down : $up;
        $reasons = [
            $side === 'CALL'
                ? sprintf('Close %.8f ниже нижней BB(%d, %.1fσ)=%.8f — отскок вверх.', $close, $bbLen, $bbDev, $down)
                : sprintf('Close %.8f выше верхней BB(%d, %.1fσ)=%.8f — отскок вниз.', $close, $bbLen, $bbDev, $up),
            sprintf('Mid=%.8f · Up=%.8f · Down=%.8f', $mid, $up, $down),
        ];
        if ($payout !== null) {
            $reasons[] = sprintf('Payout: %.0f%%', $payout);
        }

        return [
            'side' => $side,
            'confidence' => $confidence,
            'reasons_short' => $reasons,
            'analysis_text' => sprintf(
                'bollinger_contrtrend: %s; close=%.8f up=%.8f mid=%.8f down=%.8f; confidence=%.2f.',
                $setup,
                $close,
                $up,
                $mid,
                $down,
                $confidence
            ),
            'features' => [
                'setup' => $setup,
                'close' => round($close, 8),
                'bb_up' => round($up, 8),
                'bb_mid' => round($mid, 8),
                'bb_down' => round($down, 8),
                'bb_length' => $bbLen,
                'bb_deviation' => $bbDev,
                // Для карточек/Telegram: касаемая полоса как «уровень».
                'level_price' => round($bandPrice, 8),
                'level_kind' => $side === 'CALL' ? 'bb_lower' : 'bb_upper',
                'level_strength' => round(50.0 + 40.0 * $zoneDepth, 2),
                'entry_regime' => 'bounce',
                'payout' => $payout,
                'partial_present' => $partialCandle !== null,
            ],
            'decision_version' => 'bollinger_contrtrend_v1',
        ];
    }
}
