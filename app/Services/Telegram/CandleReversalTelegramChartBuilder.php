<?php

namespace App\Services\Telegram;

/**
 * Миниатюра M1 для алерта Candle Reversal + Confirm:
 * зона последних 3 M1-свечей подтверждения + маркер входа.
 */
class CandleReversalTelegramChartBuilder
{
    /**
     * @param  array<string, mixed>  $decision
     * @param  list<array<string, mixed>>  $closedCandles
     * @return array{levels:list<array<string,mixed>>,zones:list<array<string,mixed>>,entry:?array<string,mixed>}
     */
    public function chartLevelsFromDecision(array $decision, array $closedCandles, ?float $entryPrice = null): array
    {
        if (count($closedCandles) < 3) {
            return ['levels' => [], 'zones' => [], 'entry' => null];
        }

        /** @var list<array<string, mixed>> $confirmBars */
        $confirmBars = array_slice($closedCandles, -3);
        $side = strtoupper((string) ($decision['side'] ?? ''));
        $arrow = $side === 'PUT' ? '↓' : '↑';

        $lows = array_map(static fn (array $c): float => (float) ($c['low'] ?? 0), $confirmBars);
        $highs = array_map(static fn (array $c): float => (float) ($c['high'] ?? 0), $confirmBars);
        $zoneLow = min($lows);
        $zoneHigh = max($highs);
        if ($zoneHigh <= $zoneLow) {
            $zoneHigh = $zoneLow + 1e-8;
        }

        $lastClose = $entryPrice ?? (float) ($confirmBars[array_key_last($confirmBars)]['close'] ?? 0);
        $entry = null;
        if ($lastClose > 0.0 && $side !== '') {
            $entry = [
                'price' => $lastClose,
                'side' => $side,
                'label' => 'M1×3 '.$arrow,
            ];
        }

        return [
            'levels' => [],
            'zones' => [[
                'price_low' => $zoneLow,
                'price_high' => $zoneHigh,
            ]],
            'entry' => $entry,
        ];
    }
}
