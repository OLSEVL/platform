<?php

namespace App\Support;

/**
 * Компактный снимок SMC Flow для LLM-аналитика (экспирация 3–6 мин, таймфрейм M1).
 * Полный overlay (lines/areas/labels) остаётся для отрисовки на графике после анализа.
 */
final class SmcFlowLlmSnapshot
{
    /** Сколько последних M1-баров учитывать для «свежих» сигналов (≈20 мин). */
    private const RECENT_BAR_SEC = 20 * 60;

    /** Зоны FVG/OB «рядом с ценой» — в пределах 0.5% от close. */
    private const NEAR_PRICE_RATIO = 0.005;

    /**
     * @param  array{
     *   lines:list<array<string,mixed>>,
     *   areas:list<array<string,mixed>>,
     *   labels:list<array<string,mixed>>,
     *   modules:list<string>,
     *   label_size?:string
     * }  $overlay
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $m1Rows
     * @return array<string, mixed>
     */
    public static function build(array $overlay, array $m1Rows): array
    {
        if ($m1Rows === []) {
            return [
                'timeframe' => 'M1',
                'expiry_focus_min' => [3, 6],
                'modules' => [],
                'current_price' => null,
                'last_bar_time' => null,
            ];
        }

        $last = $m1Rows[array_key_last($m1Rows)];
        $lastTime = (int) ($last['time'] ?? 0);
        $currentPrice = (float) ($last['close'] ?? 0);
        $cutoffTime = $lastTime - self::RECENT_BAR_SEC;

        $labels = is_array($overlay['labels'] ?? null) ? $overlay['labels'] : [];
        $areas = is_array($overlay['areas'] ?? null) ? $overlay['areas'] : [];
        $lines = is_array($overlay['lines'] ?? null) ? $overlay['lines'] : [];
        $modules = is_array($overlay['modules'] ?? null) ? $overlay['modules'] : [];

        return [
            'timeframe' => 'M1',
            'expiry_focus_min' => [3, 6],
            'modules' => $modules,
            'current_price' => $currentPrice,
            'last_bar_time' => $lastTime,
            'ut_bot' => self::snapshotUtBot($labels, $cutoffTime),
            'hull' => self::snapshotHull($lines, $lastTime),
            'structure' => self::snapshotStructure($labels, $cutoffTime),
            'fvg_zones' => self::snapshotZones($areas, $currentPrice, $lastTime, 'lux_fvg'),
            'order_blocks' => self::snapshotZones($areas, $currentPrice, $lastTime, 'lux_ob'),
            'three_bar_reversal' => self::snapshotThreeBarReversal($labels, $cutoffTime),
            'reversal_signals' => self::snapshotReversalSignals($labels, $lines, $cutoffTime),
            'rolling_levels' => self::snapshotRollingLevels($labels, $lines),
            'session' => self::snapshotSession($areas, $lastTime),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $labels
     * @return array<string, mixed>
     */
    private static function snapshotUtBot(array $labels, int $cutoffTime): array
    {
        $signals = [];
        foreach ($labels as $lb) {
            $text = (string) ($lb['text'] ?? '');
            if ($text !== 'Buy' && $text !== 'Sell') {
                continue;
            }
            $time = (int) ($lb['time'] ?? 0);
            if ($time < $cutoffTime) {
                continue;
            }
            $signals[] = [
                'side' => $text === 'Buy' ? 'CALL' : 'PUT',
                'time' => $time,
                'price' => (float) ($lb['price'] ?? 0),
            ];
        }

        $last = $signals !== [] ? $signals[array_key_last($signals)] : null;

        return [
            'recent_signals' => $signals,
            'last_signal' => $last,
            'bias' => $last !== null ? (string) $last['side'] : null,
        ];
    }

    /**
     * Hull bias по цвету последнего сегмента основной линии (без kind — как в computeHullSuite).
     *
     * @param  list<array<string,mixed>>  $lines
     * @return array<string, mixed>|null
     */
    private static function snapshotHull(array $lines, int $lastBarTime): ?array
    {
        $candidate = null;
        foreach ($lines as $ln) {
            if (isset($ln['kind'])) {
                continue;
            }
            if ((int) ($ln['time_end'] ?? 0) !== $lastBarTime) {
                continue;
            }
            $width = (int) ($ln['width'] ?? 0);
            if ($width < 2) {
                continue;
            }
            $candidate = $ln;
        }

        if ($candidate === null) {
            return null;
        }

        $color = strtolower((string) ($candidate['color'] ?? ''));
        $bias = 'neutral';
        if (str_contains($color, '00ff00') || str_contains($color, '22c55e') || str_contains($color, '14d990')) {
            $bias = 'bull';
        } elseif (str_contains($color, 'ff0000') || str_contains($color, 'ef4444') || str_contains($color, 'f24968')) {
            $bias = 'bear';
        }

        return [
            'bias' => $bias,
            'last_price' => (float) ($candidate['price_end'] ?? 0),
            'time' => $lastBarTime,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $labels
     * @return array<string, mixed>
     */
    private static function snapshotStructure(array $labels, int $cutoffTime): array
    {
        $events = [];
        $swings = [];
        foreach ($labels as $lb) {
            $time = (int) ($lb['time'] ?? 0);
            $kind = (string) ($lb['kind'] ?? '');
            $text = (string) ($lb['text'] ?? '');

            if ($kind === 'mxwll_structure' && $time >= $cutoffTime && $text !== '') {
                $events[] = [
                    'event' => $text,
                    'time' => $time,
                    'price' => (float) ($lb['price'] ?? 0),
                ];
            }
            if ($kind === 'mxwll_swing' && in_array($text, ['HH', 'LH', 'HL', 'LL'], true)) {
                $swings[] = [
                    'label' => $text,
                    'time' => $time,
                    'price' => (float) ($lb['price'] ?? 0),
                ];
            }
        }

        return [
            'recent_events' => array_slice($events, -12),
            'recent_swings' => array_slice($swings, -8),
            'last_event' => $events !== [] ? $events[array_key_last($events)] : null,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $areas
     * @return list<array<string, mixed>>
     */
    private static function snapshotZones(array $areas, float $currentPrice, int $lastBarTime, string $kindPrefix): array
    {
        if ($currentPrice <= 0) {
            return [];
        }

        $nearBand = $currentPrice * self::NEAR_PRICE_RATIO;
        $out = [];
        foreach ($areas as $area) {
            $kind = (string) ($area['kind'] ?? '');
            if (! str_starts_with($kind, $kindPrefix)) {
                continue;
            }
            $timeEnd = (int) ($area['time_end'] ?? 0);
            if ($timeEnd < $lastBarTime) {
                continue;
            }
            $high = (float) ($area['price_high'] ?? 0);
            $low = (float) ($area['price_low'] ?? 0);
            if ($high <= 0 && $low <= 0) {
                continue;
            }
            // Зона актуальна, если цена внутри или в пределах nearBand от границ.
            if ($currentPrice < $low - $nearBand || $currentPrice > $high + $nearBand) {
                continue;
            }
            $out[] = [
                'kind' => $kind,
                'time_start' => (int) ($area['time_start'] ?? 0),
                'time_end' => $timeEnd,
                'price_high' => $high,
                'price_low' => $low,
                'side' => str_contains($kind, 'bull') ? 'CALL' : (str_contains($kind, 'bear') ? 'PUT' : null),
            ];
        }

        return array_slice($out, -10);
    }

    /**
     * @param  list<array<string,mixed>>  $labels
     * @return list<array<string, mixed>>
     */
    private static function snapshotThreeBarReversal(array $labels, int $cutoffTime): array
    {
        $out = [];
        foreach ($labels as $lb) {
            $kind = (string) ($lb['kind'] ?? '');
            if ($kind !== 'three_br_bull' && $kind !== 'three_br_bear') {
                continue;
            }
            $time = (int) ($lb['time'] ?? 0);
            if ($time < $cutoffTime) {
                continue;
            }
            $out[] = [
                'pattern' => $kind === 'three_br_bull' ? 'bull_reversal' : 'bear_reversal',
                'side' => $kind === 'three_br_bull' ? 'CALL' : 'PUT',
                'time' => $time,
                'price' => (float) ($lb['price'] ?? 0),
            ];
        }

        return array_slice($out, -6);
    }

    /**
     * @param  list<array<string,mixed>>  $labels
     * @param  list<array<string,mixed>>  $lines
     * @return array<string, mixed>
     */
    private static function snapshotReversalSignals(array $labels, array $lines, int $cutoffTime): array
    {
        $momentum = [];
        foreach ($labels as $lb) {
            $kind = (string) ($lb['kind'] ?? '');
            if ($kind !== 'rs_momentum_bull' && $kind !== 'rs_momentum_bear') {
                continue;
            }
            $time = (int) ($lb['time'] ?? 0);
            if ($time < $cutoffTime) {
                continue;
            }
            $momentum[] = [
                'side' => $kind === 'rs_momentum_bull' ? 'CALL' : 'PUT',
                'time' => $time,
                'price' => (float) ($lb['price'] ?? 0),
            ];
        }

        $sr = [];
        foreach ($lines as $ln) {
            $kind = (string) ($ln['kind'] ?? '');
            if ($kind !== 'rs_resistance' && $kind !== 'rs_support') {
                continue;
            }
            $sr[] = [
                'kind' => $kind === 'rs_resistance' ? 'resistance' : 'support',
                'price' => (float) ($ln['price_end'] ?? $ln['price_start'] ?? 0),
                'time_end' => (int) ($ln['time_end'] ?? 0),
            ];
        }

        return [
            'momentum' => array_slice($momentum, -6),
            'sr_levels' => array_slice($sr, -4),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $labels
     * @param  list<array<string,mixed>>  $lines
     * @return array<string, mixed>
     */
    private static function snapshotRollingLevels(array $labels, array $lines): array
    {
        $levels = [];
        foreach ($labels as $lb) {
            if ((string) ($lb['kind'] ?? '') !== 'mxwll_level') {
                continue;
            }
            $text = (string) ($lb['text'] ?? '');
            if (! preg_match('/^(240|1440)[HL]$/i', $text)) {
                continue;
            }
            $levels[] = [
                'label' => $text,
                'price' => (float) ($lb['price'] ?? 0),
                'time' => (int) ($lb['time'] ?? 0),
            ];
        }

        return ['levels' => $levels];
    }

    /**
     * @param  list<array<string,mixed>>  $areas
     */
    private static function snapshotSession(array $areas, int $lastBarTime): ?string
    {
        foreach ($areas as $area) {
            $kind = (string) ($area['kind'] ?? '');
            if (! str_starts_with($kind, 'session_')) {
                continue;
            }
            $start = (int) ($area['time_start'] ?? 0);
            $end = (int) ($area['time_end'] ?? 0);
            if ($lastBarTime >= $start && $lastBarTime <= $end) {
                return strtoupper(str_replace('session_', '', $kind));
            }
        }

        return null;
    }
}
