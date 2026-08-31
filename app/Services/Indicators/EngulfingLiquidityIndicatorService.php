<?php

namespace App\Services\Indicators;

/**
 * Оверлей 3-шагового поглощения на графике.
 *
 * Метки ENGULF 1/2/3 на каждой завершённой цепочке; на шаге 3 — side (CALL/PUT).
 * Использует тот же EngulfingDetector, что и торговый бот.
 */
class EngulfingLiquidityIndicatorService
{
    public const DEFAULT_PARAMS = [
        'allow_classic' => true,
        'allow_same_color' => true,
        'show_step_markers' => true,
        'show_entries' => true,
        'color_step1' => '#3b82f6',
        'color_step2' => '#f59e0b',
        'color_step3_call' => '#22c55e',
        'color_step3_put' => '#ef4444',
        'label_size' => 'small',
    ];

    /** @return array<string,mixed> */
    public static function defaultParams(): array
    {
        return self::DEFAULT_PARAMS;
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume:float}>  $rows
     * @param  array<string,mixed>|null  $params
     * @return array{lines:list<array<string,mixed>>,labels:list<array<string,mixed>>,label_size:string}
     */
    public function compute(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $detector = new EngulfingDetector;
        $entries = $detector->detect($rows, [
            'allow_classic' => (bool) ($p['allow_classic'] ?? true),
            'allow_same_color' => (bool) ($p['allow_same_color'] ?? true),
        ]);

        $labels = [];
        $showSteps = (bool) ($p['show_step_markers'] ?? true);
        $showEntries = (bool) ($p['show_entries'] ?? true);

        foreach ($entries as $entry) {
            $side = (string) ($entry['side'] ?? '');
            $step3Color = $side === 'CALL'
                ? (string) $p['color_step3_call']
                : (string) $p['color_step3_put'];
            $isUp = $side === 'CALL';

            if ($showSteps) {
                $labels[] = $this->label(
                    (int) $entry['step1_time'],
                    (float) ($rows[(int) $entry['step1_bar_index']]['close'] ?? $entry['entry_price']),
                    'ENGULF 1',
                    (string) $p['color_step1'],
                    $isUp ? 'below' : 'above',
                );
                $labels[] = $this->label(
                    (int) $entry['step2_time'],
                    (float) ($rows[(int) $entry['step2_bar_index']]['close'] ?? $entry['entry_price']),
                    'ENGULF 2',
                    (string) $p['color_step2'],
                    $isUp ? 'above' : 'below',
                );
            }

            if ($showEntries || $showSteps) {
                $kind = (string) ($entry['engulf_kind'] ?? '');
                $kindTag = $kind === 'same_color' ? ' SC' : '';
                $labels[] = $this->label(
                    (int) $entry['step3_time'],
                    (float) $entry['entry_price'],
                    ($isUp ? '▲ ' : '▼ ').'ENGULF 3 · '.$side.$kindTag,
                    $step3Color,
                    $isUp ? 'below' : 'above',
                );
            }
        }

        return [
            'lines' => [],
            'labels' => array_slice($labels, -500),
            'label_size' => $this->normalizeLabelSize((string) ($p['label_size'] ?? 'small')),
        ];
    }

    /** @return array<string,mixed> */
    private function label(int $time, float $price, string $text, string $color, string $position): array
    {
        return [
            'time' => $time,
            'price' => $price,
            'text' => $text,
            'color' => $color,
            'position' => $position,
        ];
    }

    private function normalizeLabelSize(string $raw): string
    {
        $size = strtolower(trim($raw));

        return in_array($size, ['tiny', 'small', 'normal'], true) ? $size : 'small';
    }
}
