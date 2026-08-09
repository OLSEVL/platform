<?php

namespace App\Services\Bots\Strategies;

use App\Services\Indicators\EnvelopsIndicatorService;
use App\Support\OsEngineBinaryDecision;

/**
 * EnvelopTrend — порт OsEngine Robots/Trend/EnvelopTrend.cs под CALL/PUT.
 *
 * OsEngine ставит BuyAtStop/SellAtStop на границы Envelops; на BO моделируем
 * пробой закрытой свечи: high>=up → CALL, low<=down → PUT (взаимоисключение).
 */
class EnvelopTrendStrategy implements BotStrategyInterface
{
    /** OsEngine robot Length=10; для M1 OTC чуть длиннее — меньше шума. */
    public const DEFAULT_LENGTH = 14;

    /** OsEngine robot Deviation=0.3 (% от SMA). */
    public const DEFAULT_DEVIATION = 0.25;

    public function __construct(private readonly ?EnvelopsIndicatorService $envelops = null) {}

    public function analyze(array $closedCandles, ?array $partialCandle, array $context): ?array
    {
        $cfg = is_array($context['config'] ?? null) ? $context['config'] : [];
        $len = max(2, min(500, (int) ($cfg['length'] ?? self::DEFAULT_LENGTH)));
        $dev = max(0.01, min(20.0, (float) ($cfg['deviation'] ?? self::DEFAULT_DEVIATION)));
        if (count($closedCandles) < $len + 2) {
            return null;
        }

        $raw = ($this->envelops ?? new EnvelopsIndicatorService)->computeSeries($closedCandles, [
            'length' => $len,
            'deviation' => $dev,
        ]);
        $i = count($closedCandles) - 1;
        $high = (float) $closedCandles[$i]['high'];
        $low = (float) $closedCandles[$i]['low'];
        $close = (float) $closedCandles[$i]['close'];
        $up = (float) ($raw['up'][$i] ?? 0.0);
        $down = (float) ($raw['down'][$i] ?? 0.0);
        $mid = (float) ($raw['mid'][$i] ?? 0.0);
        if ($up <= 0.0 || $down <= 0.0) {
            return null;
        }

        $call = $high >= $up;
        $put = $low <= $down;
        if ($call && $put) {
            return null; // противоречивый бар — как PriceChannel skip
        }
        if (! $call && ! $put) {
            return null;
        }

        $side = $call ? 'CALL' : 'PUT';
        $setup = $call ? 'envelop_break_up' : 'envelop_break_down';
        $level = $call ? $up : $down;
        $beyond = $call ? ($high - $up) : ($down - $low);
        $width = max(abs($up - $down), abs($close) * 1e-9, 1e-12);
        $depth = min(1.0, $beyond / $width);
        $payout = isset($context['payout']) ? (float) $context['payout'] : null;

        return OsEngineBinaryDecision::pack(
            $side,
            $setup,
            'envelop_trend_v1',
            OsEngineBinaryDecision::confidence($depth, $payout),
            [
                $call
                    ? sprintf('High пробил верхнюю Envelop(%.8f).', $up)
                    : sprintf('Low пробил нижнюю Envelop(%.8f).', $down),
                sprintf('Mid=%.8f · Close=%.8f', $mid, $close),
            ],
            sprintf('envelop_trend: %s; up=%.8f down=%.8f close=%.8f', $setup, $up, $down, $close),
            [
                'up' => round($up, 8),
                'down' => round($down, 8),
                'mid' => round($mid, 8),
                'close' => round($close, 8),
                'length' => $len,
                'deviation' => $dev,
                'level_price' => round($level, 8),
                'level_kind' => 'envelop',
                'level_strength' => round(50.0 + 40.0 * $depth, 2),
                'partial_present' => $partialCandle !== null,
            ],
            $payout
        );
    }
}
