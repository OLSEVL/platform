<?php

namespace App\Support;

use App\Services\Indicators\EngulfingDetector;

/**
 * Компактный снимок 3-шагового поглощения для LLM-аналитика.
 *
 * Детектор находит цепочку ENGULF 1 → реакция → ENGULF 3 (вход).
 * Модель не ищет паттерн сама — только подтверждает или отклоняет candidates.
 * Целевая экспирация — 60 секунд (как у rule-based engulfing_liquidity).
 */
final class EngulfingLlmSnapshot
{
    /** Сколько последних M1-баров держать в «свежих» событиях (~30 мин). */
    private const RECENT_BAR_SEC = 30 * 60;

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $m1Rows
     * @param  array{
     *   allow_classic?:bool,
     *   allow_same_color?:bool
     * }  $options
     * @return array<string, mixed>
     */
    public static function build(array $m1Rows, array $options = []): array
    {
        if ($m1Rows === []) {
            return [
                'timeframe' => 'M1',
                'expiry_focus_sec' => 60,
                'allow_classic' => (bool) ($options['allow_classic'] ?? true),
                'allow_same_color' => (bool) ($options['allow_same_color'] ?? true),
                'current_price' => null,
                'last_bar_time' => null,
                'candidates' => [],
                'recent_entries' => [],
            ];
        }

        $allowClassic = (bool) ($options['allow_classic'] ?? true);
        $allowSameColor = (bool) ($options['allow_same_color'] ?? true);

        $last = $m1Rows[array_key_last($m1Rows)];
        $lastIndex = count($m1Rows) - 1;
        $lastTime = (int) ($last['time'] ?? 0);
        $currentPrice = (float) ($last['close'] ?? 0);
        $cutoffTime = $lastTime - self::RECENT_BAR_SEC;

        $detector = new EngulfingDetector;
        $entries = $detector->detect($m1Rows, [
            'allow_classic' => $allowClassic,
            'allow_same_color' => $allowSameColor,
        ]);

        $recentEntries = [];
        foreach ($entries as $entry) {
            if ((int) ($entry['time'] ?? 0) < $cutoffTime) {
                continue;
            }
            $recentEntries[] = self::compactEntry($entry);
        }

        // Кандидаты на подтверждение: только вход на последней закрытой M1.
        $candidates = [];
        foreach (array_reverse($entries) as $entry) {
            if ((int) ($entry['bar_index'] ?? -1) !== $lastIndex) {
                continue;
            }
            $candidates[] = self::compactEntry($entry);
            break;
        }

        return [
            'timeframe' => 'M1',
            'expiry_focus_sec' => 60,
            'allow_classic' => $allowClassic,
            'allow_same_color' => $allowSameColor,
            'current_price' => $currentPrice,
            'last_bar_time' => $lastTime,
            'last_bar_index' => $lastIndex,
            // LLM обязан выбрать side только из candidates (или NONE, если список пуст / сетап слабый).
            'candidates' => array_values($candidates),
            'recent_entries' => array_slice($recentEntries, -12),
            'rule_reminder' => [
                'step1' => 'ENGULF 1 — первое поглощение (classic или same-color): контекст CALL/PUT, входа ещё нет',
                'step2' => 'ENGULF 2 — одна свеча реакции противоположного цвета',
                'step3' => 'ENGULF 3 — контр-поглощение реакции в сторону шага 1 → вход на close',
                'classic_call' => 'зелёная съела красную → контекст CALL',
                'classic_put' => 'красная съела зелёную → контекст PUT',
                'same_color_call' => 'зелёная same-color сняла high соседа → CALL',
                'same_color_put' => 'красная same-color сняла low соседа → PUT',
                'invalid' => 'нет 3 подряд свечей цепочки, слабое тело, опоздание (уже ушли) → NONE',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private static function compactEntry(array $entry): array
    {
        return [
            'setup' => (string) ($entry['setup'] ?? 'engulf_entry'),
            'kind' => (string) ($entry['kind'] ?? 'engulf'),
            'side' => (string) ($entry['side'] ?? ''),
            'engulf_kind' => (string) ($entry['engulf_kind'] ?? ''),
            'event_time' => (int) ($entry['time'] ?? 0),
            'entry_price' => (float) ($entry['entry_price'] ?? 0),
            'bar_index' => (int) ($entry['bar_index'] ?? 0),
            'step1_time' => (int) ($entry['step1_time'] ?? 0),
            'step2_time' => (int) ($entry['step2_time'] ?? 0),
            'step3_time' => (int) ($entry['step3_time'] ?? 0),
        ];
    }
}
