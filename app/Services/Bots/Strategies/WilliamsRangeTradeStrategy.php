<?php

namespace App\Services\Bots\Strategies;

use App\Services\Indicators\WilliamsRangeIndicatorService;

/**
 * Williams Range Trade — порт OsEngine Robots/CounterTrend/WilliamsRangeTrade.cs под CALL/PUT.
 *
 * Вход (LogicOpenPosition):
 * - CALL (Buy):  Williams %R < wr_down — перепроданность;
 * - PUT  (Sell): Williams %R > wr_up  — перекупленность.
 *
 * На BO по умолчанию require_cross=true: сигнал только при *входе* в зону
 * (предыдущий бар ещё не был за порогом) — иначе шум из-за удержания экстремума.
 *
 * Выход OsEngine (возврат к противоположной линии) на BO не нужен: expiry_sec снаружи.
 */
class WilliamsRangeTradeStrategy implements BotStrategyInterface
{
    /** Чуть длиннее OsEngine default — меньше ложных экстремумов на M1 OTC. */
    public const DEFAULT_WR_LENGTH = 21;

    /** Жёстче классических −20/−80: реже, но глубже в зоне. */
    public const DEFAULT_WR_UP = -10.0;

    public const DEFAULT_WR_DOWN = -90.0;

    public function __construct(
        private readonly ?WilliamsRangeIndicatorService $williams = null,
    ) {}

    public function analyze(array $closedCandles, ?array $partialCandle, array $context): ?array
    {
        /** @var array<string, mixed> $cfg */
        $cfg = is_array($context['config'] ?? null) ? $context['config'] : [];

        $wrLen = max(2, min(500, (int) ($cfg['wr_length'] ?? self::DEFAULT_WR_LENGTH)));
        $wrUp = (float) ($cfg['wr_up'] ?? self::DEFAULT_WR_UP);
        $wrDown = (float) ($cfg['wr_down'] ?? self::DEFAULT_WR_DOWN);
        // true = только первый бар входа в зону (анти-шум для paper/BO).
        $requireCross = (bool) ($cfg['require_cross'] ?? true);

        // OsEngine: Values.Count < period + 2 → прогрев; +1 бар для кросса.
        $minBars = $wrLen + ($requireCross ? 3 : 2);
        if (count($closedCandles) < $minBars) {
            return null;
        }

        $wrSvc = $this->williams ?? new WilliamsRangeIndicatorService;
        // API quirk: параметр периода называется `period`, не `length`.
        $wrSeries = $wrSvc->computeSeries($closedCandles, [
            'period' => $wrLen,
        ]);

        $i = count($closedCandles) - 1;
        $close = (float) $closedCandles[$i]['close'];
        $wr = (float) ($wrSeries[$i] ?? 0.0);
        $prevWr = (float) ($wrSeries[$i - 1] ?? 0.0);

        // На прогреве WR=0.0 (вне шкалы −100..0) — сигнала нет.
        if ($i < $wrLen) {
            return null;
        }

        $side = null;
        $setup = null;
        // OsEngine: WR < downLine → Buy → CALL; WR > upLine → Sell → PUT.
        $callNow = $wr < $wrDown;
        $putNow = $wr > $wrUp;
        if ($requireCross) {
            // Вход в зону: прошлый бар ещё не был за порогом.
            $callNow = $callNow && $prevWr >= $wrDown;
            $putNow = $putNow && $prevWr <= $wrUp;
        }

        if ($callNow) {
            $side = 'CALL';
            $setup = 'wr_oversold';
        } elseif ($putNow) {
            $side = 'PUT';
            $setup = 'wr_overbought';
        }

        if ($side === null || $setup === null) {
            return null;
        }

        $payout = isset($context['payout']) ? (float) $context['payout'] : null;

        // Глубина захода за линию: дальше от порога → увереннее (шкала WR −100..0).
        $zoneDepth = $side === 'CALL'
            ? min(1.0, max(0.0, ($wrDown - $wr) / max(1.0, abs($wrDown) + 20.0)))
            : min(1.0, max(0.0, ($wr - $wrUp) / max(1.0, abs($wrUp) + 20.0)));

        $confidence = round(
            (0.50 * (0.55 + 0.45 * $zoneDepth) + 0.20 * 0.7) / 0.70,
            4
        );
        $confidence = max(0.58, min(0.95, $confidence));

        $reasons = [
            $side === 'CALL'
                ? sprintf('Williams %%R вошёл ниже %.0f (было %.2f → %.2f) — перепроданность.', $wrDown, $prevWr, $wr)
                : sprintf('Williams %%R вошёл выше %.0f (было %.2f → %.2f) — перекупленность.', $wrUp, $prevWr, $wr),
            sprintf('WR=%d · Up=%.0f · Down=%.0f · Close=%.8f', $wrLen, $wrUp, $wrDown, $close),
        ];
        if ($payout !== null) {
            $reasons[] = sprintf('Payout: %.0f%%', $payout);
        }

        return [
            'side' => $side,
            'confidence' => $confidence,
            'reasons_short' => $reasons,
            'analysis_text' => sprintf(
                'williams_range_trade: %s; wr=%.2f prev=%.2f close=%.8f; confidence=%.2f.',
                $setup,
                $wr,
                $prevWr,
                $close,
                $confidence
            ),
            'features' => [
                'setup' => $setup,
                'williams' => round($wr, 4),
                'williams_prev' => round($prevWr, 4),
                'close' => round($close, 8),
                'wr_length' => $wrLen,
                'wr_up' => $wrUp,
                'wr_down' => $wrDown,
                'require_cross' => $requireCross,
                'level_price' => round($close, 8),
                'level_kind' => 'williams',
                'level_strength' => round(50.0 + 40.0 * $zoneDepth, 2),
                'entry_regime' => 'bounce',
                'payout' => $payout,
                'partial_present' => $partialCandle !== null,
            ],
            'decision_version' => 'williams_range_trade_v1',
        ];
    }
}
