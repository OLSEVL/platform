<?php

namespace App\Services\Telegram;

/**
 * Собирает chart_levels для полного M1/M5 графика в алерте Strong Trend Telegram.
 * Подсвечивает диапазон импульсной серии, старт, опционально MA и маркер входа
 * (в т.ч. после контр-свечи при wait_opposite_then_continue).
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
        // При опции «контр-свеча» паттерн = N импульсных + 1 обратная.
        $waitOpposite = (bool) ($features['wait_opposite_then_continue'] ?? false);
        $patternLen = $waitOpposite ? $streakLen + 1 : $streakLen;
        if (count($closedCandles) < $patternLen) {
            return ['levels' => [], 'zones' => [], 'entry' => null];
        }

        // Импульсная серия (без контр-свечи) — зона и «Старт».
        /** @var list<array<string, mixed>> $streak */
        $streak = $waitOpposite
            ? array_slice($closedCandles, -($streakLen + 1), $streakLen)
            : array_slice($closedCandles, -$streakLen);
        // Для зоны high/low при wait_opposite включаем и контр-бар — видно весь сетап.
        /** @var list<array<string, mixed>> $zoneBars */
        $zoneBars = $waitOpposite
            ? array_slice($closedCandles, -$patternLen)
            : $streak;

        $trend = (string) ($features['trend'] ?? $decision['trend'] ?? '');
        if (! in_array($trend, ['up', 'down'], true)) {
            $side = strtoupper((string) ($decision['side'] ?? ''));
            $trend = $side === 'PUT' ? 'down' : 'up';
        }

        $arrow = $trend === 'up' ? '↑' : '↓';
        $side = strtoupper((string) ($decision['side'] ?? ($trend === 'up' ? 'CALL' : 'PUT')));

        $lows = array_map(static fn (array $c): float => (float) ($c['low'] ?? 0), $zoneBars);
        $highs = array_map(static fn (array $c): float => (float) ($c['high'] ?? 0), $zoneBars);
        $zoneLow = min($lows);
        $zoneHigh = max($highs);
        if ($zoneHigh <= $zoneLow) {
            $zoneHigh = $zoneLow + 1e-8;
        }

        $first = $streak[0];
        // Точка входа: close контр-свечи (если ждём её) либо close N-й свечи серии.
        $entryBar = $closedCandles[array_key_last($closedCandles)];
        $startOpen = (float) ($first['open'] ?? 0);
        $entryClose = (float) ($entryBar['close'] ?? 0);

        $levels = [];
        if ($startOpen > 0.0) {
            // Пунктир — open первой свечи импульса (откуда началась серия).
            $levels[] = [
                'price' => $startOpen,
                'label' => sprintf('Старт %d×%s', $streakLen, $arrow),
                'timeframe' => 'M1',
                'style' => 'dashed',
                'kind' => $trend === 'up' ? 'support' : 'resistance',
            ];
        }

        // Если стратегия считала MA на входе — рисуем её как уровень контекста (как у Level-ботов).
        $maAtEntry = isset($features['pullback_ma_at_entry']) ? (float) $features['pullback_ma_at_entry'] : 0.0;
        if ($maAtEntry > 0.0) {
            $maType = strtoupper((string) ($features['pullback_ma_type'] ?? 'ema'));
            $maLen = (int) ($features['pullback_ma_length'] ?? 0);
            $maLabel = $maLen > 0 ? sprintf('%s(%d)', $maType, $maLen) : $maType;
            $levels[] = [
                'price' => $maAtEntry,
                'label' => $maLabel,
                'timeframe' => 'M1',
                'style' => 'solid',
                'kind' => 'neutral',
            ];
        }

        $entry = null;
        if ($entryClose > 0.0) {
            $entryLabel = $waitOpposite
                ? sprintf('%d×%s + контр → %s', $streakLen, $arrow, $side !== '' ? $side : 'вход')
                : sprintf('%d×%s', $streakLen, $arrow);
            $entry = [
                'price' => $entryClose,
                'side' => $side,
                'label' => $entryLabel,
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
