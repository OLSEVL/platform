<?php

namespace App\Support;

use App\Services\Indicators\SweepRaidLiquidityDetector;

/**
 * Компактный снимок Sweep / Raid Liquidity для LLM-аналитика.
 *
 * Модель не ищет сетап сама: она получает уже найденные SWEEP/RAID/PB
 * (тот же детектор, что у индикатора и rule-based бота) и только
 * подтверждает или отклоняет кандидатов. Целевая экспирация — 60 секунд.
 */
final class SweepRaidLlmSnapshot
{
    /** Сколько последних M1-баров держать в «свежих» событиях (~30 мин). */
    private const RECENT_BAR_SEC = 30 * 60;

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $m1Rows
     * @param  array{
     *   pivot_length?:int,
     *   enter_sweep?:bool,
     *   enter_pullback?:bool
     * }  $options
     * @return array<string, mixed>
     */
    public static function build(array $m1Rows, array $options = []): array
    {
        if ($m1Rows === []) {
            return [
                'timeframe' => 'M1',
                'expiry_focus_sec' => 60,
                'pivot_length' => (int) ($options['pivot_length'] ?? 2),
                'enter_sweep' => (bool) ($options['enter_sweep'] ?? true),
                'enter_pullback' => (bool) ($options['enter_pullback'] ?? true),
                'current_price' => null,
                'last_bar_time' => null,
                'candidates' => [],
                'recent_events' => [],
                'recent_pullbacks' => [],
                'active_levels' => [],
            ];
        }

        $pivotLength = max(1, min(100, (int) ($options['pivot_length'] ?? 2)));
        $enterSweep = (bool) ($options['enter_sweep'] ?? true);
        $enterPullback = (bool) ($options['enter_pullback'] ?? true);

        $last = $m1Rows[array_key_last($m1Rows)];
        $lastIndex = count($m1Rows) - 1;
        $lastTime = (int) ($last['time'] ?? 0);
        $currentPrice = (float) ($last['close'] ?? 0);
        $cutoffTime = $lastTime - self::RECENT_BAR_SEC;

        $detector = new SweepRaidLiquidityDetector;
        $events = $detector->detect($m1Rows, $pivotLength);
        $pullbacks = $detector->pullbackEntries($m1Rows, $events);

        $recentEvents = [];
        foreach ($events as $event) {
            if ((int) ($event['time'] ?? 0) < $cutoffTime) {
                continue;
            }
            $recentEvents[] = self::compactEvent($event, 'takedown');
        }

        $recentPullbacks = [];
        foreach ($pullbacks as $entry) {
            if ((int) ($entry['time'] ?? 0) < $cutoffTime) {
                continue;
            }
            $recentPullbacks[] = self::compactEvent($entry, 'pullback');
        }

        // Кандидаты на подтверждение: только то, что сработало бы на последней закрытой M1.
        $candidates = [];
        if ($enterSweep) {
            foreach (array_reverse($events) as $event) {
                if ((int) ($event['bar_index'] ?? -1) !== $lastIndex) {
                    continue;
                }
                if (($event['kind'] ?? null) !== 'sweep') {
                    continue;
                }
                $candidates[] = self::compactEvent($event, 'sweep');
            }
        }
        if ($enterPullback) {
            foreach (array_reverse($pullbacks) as $entry) {
                if ((int) ($entry['bar_index'] ?? -1) !== $lastIndex) {
                    continue;
                }
                $candidates[] = self::compactEvent($entry, 'pullback');
            }
        }

        return [
            'timeframe' => 'M1',
            'expiry_focus_sec' => 60,
            'pivot_length' => $pivotLength,
            'enter_sweep' => $enterSweep,
            'enter_pullback' => $enterPullback,
            'current_price' => $currentPrice,
            'last_bar_time' => $lastTime,
            'last_bar_index' => $lastIndex,
            // LLM обязан выбрать side только из candidates (или NONE, если список пуст / сетап слабый).
            'candidates' => array_values($candidates),
            'recent_events' => array_slice($recentEvents, -20),
            'recent_pullbacks' => array_slice($recentPullbacks, -12),
            'active_levels' => self::activeLevels($recentEvents),
            'rule_reminder' => [
                'sweep_high' => 'зелёная свеча: тень выше FH, close ниже → PUT',
                'sweep_low' => 'красная свеча: тень ниже FL, close выше → CALL',
                'raid' => 'снятие без цвета свипа или возврат после закрепления телом; вход не на рейде, а на первой откатной',
                'pullback_high' => 'после снятия сверху первая зелёная → PUT',
                'pullback_low' => 'после снятия снизу первая красная → CALL',
                'invalid' => 'два закрытия подряд за уровнем = уровень съеден, нужен новый фрактал',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private static function compactEvent(array $event, string $entryMode): array
    {
        return [
            'entry_mode' => $entryMode === 'takedown'
                ? (string) ($event['kind'] ?? 'event')
                : $entryMode,
            'kind' => (string) ($event['kind'] ?? ''),
            'location' => (string) ($event['location'] ?? ''),
            'side' => (string) ($event['side'] ?? ''),
            'level_price' => (float) ($event['level_price'] ?? 0),
            'level_time' => (int) ($event['level_time'] ?? 0),
            'event_time' => (int) ($event['time'] ?? 0),
            'entry_price' => (float) ($event['entry_price'] ?? 0),
            'bar_index' => (int) ($event['bar_index'] ?? 0),
            'takedown_time' => (int) ($event['takedown_time'] ?? $event['time'] ?? 0),
            'takedown_price' => (float) ($event['takedown_price'] ?? $event['entry_price'] ?? 0),
        ];
    }

    /**
     * Уникальные FH/FL из недавних событий (для подписей в анализе).
     *
     * @param  list<array<string, mixed>>  $recentEvents
     * @return list<array<string, mixed>>
     */
    private static function activeLevels(array $recentEvents): array
    {
        $seen = [];
        $out = [];
        foreach (array_reverse($recentEvents) as $event) {
            $key = ($event['location'] ?? '').'|'.($event['level_time'] ?? '').'|'.($event['level_price'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = [
                'label' => (($event['location'] ?? '') === 'high') ? 'FH' : 'FL',
                'location' => (string) ($event['location'] ?? ''),
                'price' => (float) ($event['level_price'] ?? 0),
                'time' => (int) ($event['level_time'] ?? 0),
            ];
            if (count($out) >= 8) {
                break;
            }
        }

        return array_reverse($out);
    }
}
