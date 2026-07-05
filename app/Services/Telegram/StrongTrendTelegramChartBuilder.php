<?php

namespace App\Services\Telegram;

/**
 * Собирает chart_levels для миниатюры M1 в алерте Strong Trend Telegram.
 * Подсвечивает диапазон серии свечей и маркер на последнем close.
 */
class StrongTrendTelegramChartBuilder
{
    /**
     * @param  array<string, mixed>  $decision
     * @param  list<array<string, mixed>>  $closedCandles  закрытые M1 (как в scan)
     * @return array{levels:list<array<string,mixed>>,zones:list<array<string,mixed>>,entry:?array<string,mixed>}
     */
    public function chartLevelsFromDecision(array $decision, array $closedCandles): array
    {
        $features = is_array($decision['features'] ?? null) ? $decision['features'] : [];
        $streakLen = max(2, (int) ($features['streak_len'] ?? 6));
        if (count($closedCandles) < $streakLen) {
            return ['levels' => [], 'zones' => [], 'entry' => null];
        }

        /** @var list<array<string, mixed>> $streak */
        $streak = array_slice($closedCandles, -$streakLen);

        $trend = (string) ($features['trend'] ?? $decision['trend'] ?? '');
        if (! in_array($trend, ['up', 'down'], true)) {
            $side = strtoupper((string) ($decision['side'] ?? ''));
            $trend = $side === 'PUT' ? 'down' : 'up';
        }

        $arrow = $trend === 'up' ? '↑' : '↓';
        $side = strtoupper((string) ($decision['side'] ?? ($trend === 'up' ? 'CALL' : 'PUT')));

        // Диапазон high/low серии — полупрозрачная зона на графике (импульс).
        $lows = array_map(static fn (array $c): float => (float) ($c['low'] ?? 0), $streak);
        $highs = array_map(static fn (array $c): float => (float) ($c['high'] ?? 0), $streak);
        $zoneLow = min($lows);
        $zoneHigh = max($highs);
        if ($zoneHigh <= $zoneLow) {
            $zoneHigh = $zoneLow + 1e-8;
        }

        $first = $streak[0];
        $last = $streak[array_key_last($streak)];
        $startOpen = (float) ($first['open'] ?? 0);
        $lastClose = (float) ($last['close'] ?? 0);

        $levels = [];
        if ($startOpen > 0.0) {
            // Пунктир — open первой свечи серии (откуда начался импульс).
            $levels[] = [
                'price' => $startOpen,
                'label' => sprintf('Старт %d×%s', $streakLen, $arrow),
                'timeframe' => 'M1',
                'style' => 'dashed',
                'kind' => $trend === 'up' ? 'support' : 'resistance',
            ];
        }

        $entry = null;
        if ($lastClose > 0.0) {
            $entry = [
                'price' => $lastClose,
                'side' => $side,
                'label' => sprintf('%d×%s', $streakLen, $arrow),
            ];
        }

        return [
            'levels' => $levels,
            'zones' => [[
                'price_low' => $zoneLow,
                'price_high' => $zoneHigh,
            ]],
            'entry' => $entry,
        ];
    }
}
