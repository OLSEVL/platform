<?php

namespace App\Services\Bots\Strategies;

use App\Services\Indicators\ParabolicBollingerIndicatorService;
use App\Support\OsEngineBinaryDecision;

/**
 * ParabolicBollinger — порт OsEngine Robots/Trend/ParabolicBollinger.cs под CALL/PUT.
 *
 * OsEngine: BuyAtStop на BB up / SellAtStop на BB down, только если parabolic
 * внутри канала (up > para && down < para). На BO: пробой high/low при том же guard.
 */
class ParabolicBollingerStrategy implements BotStrategyInterface
{
    public const DEFAULT_LENGTH = 21;

    public const DEFAULT_DEVIATION = 2.0;

    public const DEFAULT_AVERAGING_PERIOD = 15;

    public const DEFAULT_VOLATILITY_MULTI = 0.15;

    public function __construct(private readonly ?ParabolicBollingerIndicatorService $pb = null) {}

    public function analyze(array $closedCandles, ?array $partialCandle, array $context): ?array
    {
        /** @var array<string, mixed> $cfg */
        $cfg = is_array($context['config'] ?? null) ? $context['config'] : [];
        $len = max(5, min(500, (int) ($cfg['length'] ?? self::DEFAULT_LENGTH)));
        $dev = max(0.1, min(10.0, (float) ($cfg['deviation'] ?? self::DEFAULT_DEVIATION)));
        $avg = max(2, min(200, (int) ($cfg['averaging_period'] ?? self::DEFAULT_AVERAGING_PERIOD)));
        $vMulti = max(0.01, min(5.0, (float) ($cfg['volatility_multi'] ?? self::DEFAULT_VOLATILITY_MULTI)));
        $vPeriod = (string) ($cfg['volatility_period'] ?? 'Day');
        if (! in_array($vPeriod, ['Day', 'Week', 'Month'], true)) {
            $vPeriod = 'Day';
        }
        if (count($closedCandles) < $len + $avg + 5) {
            return null;
        }

        // Комбо-индикатор: BB-полосы + параболическая линия внутри канала.
        $raw = ($this->pb ?? new ParabolicBollingerIndicatorService)->computeSeries($closedCandles, [
            'length' => $len,
            'deviation' => $dev,
            'averaging_period' => $avg,
            'volatility_multi' => $vMulti,
            'volatility_period' => $vPeriod,
        ]);
        $i = count($closedCandles) - 1;
        $high = (float) $closedCandles[$i]['high'];
        $low = (float) $closedCandles[$i]['low'];
        $close = (float) $closedCandles[$i]['close'];
        $up = (float) ($raw['up'][$i] ?? 0.0);
        $down = (float) ($raw['down'][$i] ?? 0.0);
        $para = (float) ($raw['parabolic'][$i] ?? 0.0);
        if ($up <= 0.0 || $down <= 0.0 || $para <= 0.0) {
            return null;
        }
        // Guard OsEngine: parabolic должен быть внутри полос.
        if (! ($up > $para && $down < $para)) {
            return null;
        }

        $call = $high >= $up;
        $put = $low <= $down;
        if ($call && $put) {
            return null;
        }
        if (! $call && ! $put) {
            return null;
        }

        $side = $call ? 'CALL' : 'PUT';
        $setup = $call ? 'pb_break_up' : 'pb_break_down';
        $level = $call ? $up : $down;
        $beyond = $call ? ($high - $up) : ($down - $low);
        $width = max(abs($up - $down), abs($close) * 1e-9, 1e-12);
        $depth = min(1.0, $beyond / $width);
        $payout = isset($context['payout']) ? (float) $context['payout'] : null;

        return OsEngineBinaryDecision::pack(
            $side,
            $setup,
            'parabolic_bollinger_v1',
            OsEngineBinaryDecision::confidence($depth, $payout),
            [
                $call
                    ? sprintf('Пробой BB Up(%.5f), SAR внутри канала (%.5f).', $up, $para)
                    : sprintf('Пробой BB Down(%.5f), SAR внутри канала (%.5f).', $down, $para),
            ],
            sprintf('parabolic_bollinger: %s; up=%.5f down=%.5f para=%.5f', $setup, $up, $down, $para),
            [
                'up' => round($up, 8),
                'down' => round($down, 8),
                'parabolic' => round($para, 8),
                'close' => round($close, 8),
                'length' => $len,
                'deviation' => $dev,
                'level_price' => round($level, 8),
                'level_kind' => 'parabolic_bollinger',
                'level_strength' => round(50.0 + 40.0 * $depth, 2),
                'partial_present' => $partialCandle !== null,
            ],
            $payout
        );
    }
}
