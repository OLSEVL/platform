<?php

namespace App\Support;

use App\Services\Indicators\SweepRaidLiquidityDetector;

/**
 * Компактный снимок Sweep / Raid Liquidity для LLM-аналитика.
 *
 * Модель не ищет сетап сама: она получает уже найденные SWEEP/RAID/PB/BO
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
     *   enter_pullback?:bool,
     *   enter_breakout?:bool,
     *   breakout_closes?:int
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
                'enter_breakout' => (bool) ($options['enter_breakout'] ?? false),
                'breakout_closes' => (int) ($options['breakout_closes'] ?? 2),
                'current_price' => null,
                'last_bar_time' => null,
                'candidates' => [],
                'recent_events' => [],
                'recent_pullbacks' => [],
                'recent_breakouts' => [],
                'active_levels' => [],
            ];
        }

        $pivotLength = max(1, min(100, (int) ($options['pivot_length'] ?? 2)));
        $enterSweep = (bool) ($options['enter_sweep'] ?? true);
        $enterPullback = (bool) ($options['enter_pullback'] ?? true);
        $enterBreakout = (bool) ($options['enter_breakout'] ?? false);
        $breakoutCloses = max(1, min(5, (int) ($options['breakout_closes'] ?? 2)));

        $last = $m1Rows[array_key_last($m1Rows)];
        $lastIndex = count($m1Rows) - 1;
        $lastTime = (int) ($last['time'] ?? 0);
        $currentPrice = (float) ($last['close'] ?? 0);
        $cutoffTime = $lastTime - self::RECENT_BAR_SEC;

        $detector = new SweepRaidLiquidityDetector;
        $events = $detector->detect($m1Rows, $pivotLength);
        $pullbacks = $detector->pullbackEntries($m1Rows, $events);
        $breakouts = $enterBreakout
            ? $detector->breakoutEntries($m1Rows, $pivotLength, $breakoutCloses)
            : [];

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

        $recentBreakouts = [];
        foreach ($breakouts as $entry) {
            if ((int) ($entry['time'] ?? 0) < $cutoffTime) {
                continue;
            }
            $recentBreakouts[] = self::compactEvent($entry, 'breakout');
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
        if ($enterBreakout) {
            foreach (array_reverse($breakouts) as $entry) {
                if ((int) ($entry['bar_index'] ?? -1) !== $lastIndex) {
                    continue;
                }
                $candidates[] = self::compactEvent($entry, 'breakout');
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
            'enter_breakout' => $enterBreakout,
            'breakout_closes' => $breakoutCloses,
            'current_price' => $currentPrice,
            'last_bar_time' => $lastTime,
            'last_bar_index' => $lastIndex,
            // LLM обязан выбрать side только из candidates (или NONE, если список пуст / сетап слабый).
            'candidates' => array_values($candidates),
            'recent_events' => array_slice($recentEvents, -20),
            'recent_pullbacks' => array_slice($recentPullbacks, -12),
            'recent_breakouts' => array_slice($recentBreakouts, -12),
            'active_levels' => self::activeLevels($recentEvents),
            'rule_reminder' => [
                'sweep_high' => 'зелёная свеча: тень выше FH, close ниже → PUT (fade)',
                'sweep_low' => 'красная свеча: тень ниже FL, close выше → CALL (fade)',
                'raid' => 'снятие без цвета свипа или возврат после закрепления телом; вход не на рейде, а на первой откатной',
                'pullback_high' => 'после снятия сверху первая зелёная → PUT',
                'pullback_low' => 'после снятия снизу первая красная → CALL',
                'breakout_high' => 'N close подряд выше FH без возврата → CALL (продолжение, не fade)',
                'breakout_low' => 'N close подряд ниже FL без возврата → PUT (продолжение)',
                'dual_mode' => 'fade и breakout — разные режимы от одного FH/FL; не смешивать с рейдом',
                'invalid' => 'в режиме fade два закрытия за уровнем = уровень съеден; в режиме breakout это вход продолжения',
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
