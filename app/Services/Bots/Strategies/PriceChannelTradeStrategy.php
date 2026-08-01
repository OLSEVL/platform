<?php

namespace App\Services\Bots\Strategies;

use App\Services\Indicators\PriceChannelIndicatorService;
use App\Support\OsEngineBinaryDecision;

/**
 * PriceChannelTrade — порт OsEngine Robots/Trend/PriceChannelTrade.cs под CALL/PUT.
 *
 * Важно: канал берём с бара i-1 (OsEngine Values[Count-2]), иначе текущий high
 * почти всегда равен up и чистый пробой не детектится.
 */
class PriceChannelTradeStrategy implements BotStrategyInterface
{
    public const DEFAULT_LENGTH_UP = 14;

    public const DEFAULT_LENGTH_DOWN = 14;

    public function __construct(private readonly ?PriceChannelIndicatorService $pc = null) {}

    public function analyze(array $closedCandles, ?array $partialCandle, array $context): ?array
    {
        $cfg = is_array($context['config'] ?? null) ? $context['config'] : [];
        $lenUp = max(2, min(500, (int) ($cfg['length_up'] ?? self::DEFAULT_LENGTH_UP)));
        $lenDown = max(2, min(500, (int) ($cfg['length_down'] ?? self::DEFAULT_LENGTH_DOWN)));
        $need = max($lenUp, $lenDown) + 3;
        if (count($closedCandles) < $need) {
            return null;
        }

        $raw = ($this->pc ?? new PriceChannelIndicatorService)->computeSeries($closedCandles, [
            'length_up' => $lenUp,
            'length_down' => $lenDown,
        ]);
        $i = count($closedCandles) - 1;
        $prev = $i - 1;
        $high = (float) $closedCandles[$i]['high'];
        $low = (float) $closedCandles[$i]['low'];
        $close = (float) $closedCandles[$i]['close'];
        // Count-2: канал предыдущего закрытого бара.
        $up = (float) ($raw['up'][$prev] ?? 0.0);
        $down = (float) ($raw['down'][$prev] ?? 0.0);
        if ($up <= 0.0 || $down <= 0.0) {
            return null;
        }

        $call = $high > $up;
        $put = $low < $down;
        if ($call && $put) {
            return null;
        }
        if (! $call && ! $put) {
            return null;
        }

        $side = $call ? 'CALL' : 'PUT';
        $setup = $call ? 'pc_break_up' : 'pc_break_down';
        $level = $call ? $up : $down;
        $beyond = $call ? ($high - $up) : ($down - $low);
        $width = max(abs($up - $down), abs($close) * 1e-9, 1e-12);
        $depth = min(1.0, $beyond / $width);
        $payout = isset($context['payout']) ? (float) $context['payout'] : null;

        return OsEngineBinaryDecision::pack(
            $side,
            $setup,
            'price_channel_trade_v1',
            OsEngineBinaryDecision::confidence($depth, $payout),
            [
                $call
                    ? sprintf('High > PC Up(prev)=%.5f — пробой вверх.', $up)
                    : sprintf('Low < PC Down(prev)=%.5f — пробой вниз.', $down),
            ],
            sprintf('price_channel_trade: %s; up=%.5f down=%.5f', $setup, $up, $down),
            [
                'up' => round($up, 8),
                'down' => round($down, 8),
                'close' => round($close, 8),
                'length_up' => $lenUp,
                'length_down' => $lenDown,
                'level_price' => round($level, 8),
                'level_kind' => 'price_channel',
                'level_strength' => round(50.0 + 40.0 * $depth, 2),
                'partial_present' => $partialCandle !== null,
            ],
            $payout
        );
    }
}
