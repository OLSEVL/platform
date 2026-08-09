<?php

namespace App\Services\Bots\Strategies;

use App\Services\Indicators\LinRegChannelFastIndicatorService;
use App\Services\Indicators\SmaIndicatorService;
use App\Support\OsEngineBinaryDecision;

/**
 * BreakLinearRegressionChannel — порт OsEngine BreakLinearRegressionChannel.cs под CALL/PUT.
 *
 * CALL: close > LR up (+ опциональные SMA-фильтры). PUT: close < LR down.
 * Индикатор — LinRegChannelFast (как в роботе), не медленный LinRegChannel.
 */
class BreakLinRegChannelStrategy implements BotStrategyInterface
{
    public const DEFAULT_PERIOD = 40;

    public const DEFAULT_UP_DEVIATION = 1.0;

    public const DEFAULT_DOWN_DEVIATION = 1.0;

    public const DEFAULT_SMA_LENGTH = 50;

    public function __construct(
        private readonly ?LinRegChannelFastIndicatorService $lr = null,
        private readonly ?SmaIndicatorService $sma = null,
    ) {}

    public function analyze(array $closedCandles, ?array $partialCandle, array $context): ?array
    {
        $cfg = is_array($context['config'] ?? null) ? $context['config'] : [];
        $period = max(5, min(500, (int) ($cfg['period'] ?? self::DEFAULT_PERIOD)));
        $upDev = max(0.1, min(10.0, (float) ($cfg['up_deviation'] ?? self::DEFAULT_UP_DEVIATION)));
        $downDev = max(0.1, min(10.0, (float) ($cfg['down_deviation'] ?? self::DEFAULT_DOWN_DEVIATION)));
        $smaLen = max(2, min(500, (int) ($cfg['sma_length'] ?? self::DEFAULT_SMA_LENGTH)));
        $filterPos = (bool) ($cfg['sma_filter_position'] ?? false);
        $filterSlope = (bool) ($cfg['sma_filter_slope'] ?? false);
        if (count($closedCandles) < $period + 2) {
            return null;
        }

        $raw = ($this->lr ?? new LinRegChannelFastIndicatorService)->computeSeries($closedCandles, [
            'period' => $period,
            'up_deviation' => $upDev,
            'down_deviation' => $downDev,
            'candle_point' => 'Close',
        ]);
        $i = count($closedCandles) - 1;
        $close = (float) $closedCandles[$i]['close'];
        $up = (float) ($raw['up'][$i] ?? 0.0);
        $down = (float) ($raw['down'][$i] ?? 0.0);
        if ($up <= 0.0 || $down <= 0.0) {
            return null;
        }

        $sma = 0.0;
        $smaPrev = 0.0;
        if ($filterPos || $filterSlope) {
            if (count($closedCandles) < $smaLen + 2) {
                return null;
            }
            $smaSeries = ($this->sma ?? new SmaIndicatorService)->computeSeries($closedCandles, [
                'length' => $smaLen,
                'candle_point' => 'Close',
            ]);
            $sma = (float) ($smaSeries[$i] ?? 0.0);
            $smaPrev = (float) ($smaSeries[$i - 1] ?? 0.0);
            if ($sma <= 0.0) {
                return null;
            }
        }

        $call = $close > $up;
        $put = $close < $down;
        if ($call && $filterPos && ! ($close > $sma)) {
            $call = false;
        }
        if ($call && $filterSlope && ! ($sma >= $smaPrev)) {
            $call = false;
        }
        if ($put && $filterPos && ! ($close < $sma)) {
            $put = false;
        }
        if ($put && $filterSlope && ! ($sma <= $smaPrev)) {
            $put = false;
        }
        if ($call && $put) {
            return null;
        }
        if (! $call && ! $put) {
            return null;
        }

        $side = $call ? 'CALL' : 'PUT';
        $setup = $call ? 'lr_break_up' : 'lr_break_down';
        $level = $call ? $up : $down;
        $beyond = abs($close - $level);
        $width = max(abs($up - $down), abs($close) * 1e-9, 1e-12);
        $depth = min(1.0, $beyond / $width);
        $payout = isset($context['payout']) ? (float) $context['payout'] : null;

        return OsEngineBinaryDecision::pack(
            $side,
            $setup,
            'break_linreg_channel_v1',
            OsEngineBinaryDecision::confidence($depth, $payout),
            [
                $call
                    ? sprintf('Close(%.8f) > LR Up(%.8f).', $close, $up)
                    : sprintf('Close(%.8f) < LR Down(%.8f).', $close, $down),
            ],
            sprintf('break_linreg_channel: %s; up=%.8f down=%.8f', $setup, $up, $down),
            [
                'up' => round($up, 8),
                'down' => round($down, 8),
                'close' => round($close, 8),
                'period' => $period,
                'up_deviation' => $upDev,
                'down_deviation' => $downDev,
                'sma' => round($sma, 8),
                'level_price' => round($level, 8),
                'level_kind' => 'linreg_channel',
                'level_strength' => round(50.0 + 40.0 * $depth, 2),
                'partial_present' => $partialCandle !== null,
            ],
            $payout
        );
    }
}
