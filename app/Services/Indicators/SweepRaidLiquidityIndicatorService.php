<?php

namespace App\Services\Indicators;

/**
 * Самостоятельный оверлей стратегии «Свип / рейд ликвидности».
 *
 * Он намеренно не расширяет Liquidity Swings: LuxAlgo-уровни остаются
 * независимым индикатором, а здесь отображаются только фракталы и условия
 * торговой стратегии, которые использует SweepRaidLiquidityStrategy.
 */
class SweepRaidLiquidityIndicatorService
{
    public const DEFAULT_PARAMS = [
        'pivot_length' => 2,
        'show_fractals' => true,
        'show_sweeps' => true,
        'show_raids' => true,
        'show_pullback_entries' => true,
        'color_fractal_high' => '#ef4444',
        'color_fractal_low' => '#14b8a6',
        'color_sweep' => '#f59e0b',
        'color_raid' => '#a78bfa',
        'color_pullback' => '#22c55e',
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
        $pivotLength = max(1, min(100, (int) ($p['pivot_length'] ?? 2)));
        $detector = new SweepRaidLiquidityDetector;
        $events = $detector->detect($rows, $pivotLength);
        $lines = [];
        $labels = [];
        $fractalKeys = [];

        foreach ($events as $event) {
            $isHigh = ($event['location'] ?? '') === 'high';
            $kind = (string) ($event['kind'] ?? '');
            $fractalColor = $isHigh ? (string) $p['color_fractal_high'] : (string) $p['color_fractal_low'];

            // Один и тот же фрактал может быть снят несколькими свечами. Рисуем
            // его один раз, чтобы оверлей не засорялся повторяющимися отметками.
            $fractalKey = (string) $event['level_time'].'|'.(string) $event['level_price'];
            if ((bool) $p['show_fractals'] && ! isset($fractalKeys[$fractalKey])) {
                $fractalKeys[$fractalKey] = true;
                $lines[] = [
                    'time_start' => (int) $event['level_time'],
                    'time_end' => (int) $event['time'],
                    'price' => (float) $event['level_price'],
                    'color' => $fractalColor,
                    'style' => 'Dashed',
                    'width' => 1,
                ];
                $labels[] = [
                    'time' => (int) $event['level_time'],
                    'price' => (float) $event['level_price'],
                    'text' => $isHigh ? 'FH' : 'FL',
                    'color' => $fractalColor,
                    'position' => $isHigh ? 'above' : 'below',
                ];
            }

            if (($kind === 'sweep' && ! (bool) $p['show_sweeps'])
                || ($kind === 'raid' && ! (bool) $p['show_raids'])) {
                continue;
            }
            $color = $kind === 'sweep' ? (string) $p['color_sweep'] : (string) $p['color_raid'];
            $labels[] = [
                'time' => (int) $event['time'],
                'price' => (float) $event['entry_price'],
                'text' => ($isHigh ? '▼ ' : '▲ ').strtoupper($kind).' '.($isHigh ? 'H' : 'L').' · '.(string) $event['side'],
                'color' => $color,
                'position' => $isHigh ? 'above' : 'below',
            ];
        }

        if ((bool) $p['show_pullback_entries']) {
            foreach ($detector->pullbackEntries($rows, $events) as $entry) {
                $isHigh = ($entry['location'] ?? '') === 'high';
                $labels[] = [
                    'time' => (int) $entry['time'],
                    'price' => (float) $entry['entry_price'],
                    'text' => ($isHigh ? '▼ ' : '▲ ').'PB · '.(string) $entry['side'],
                    'color' => (string) $p['color_pullback'],
                    'position' => $isHigh ? 'above' : 'below',
                ];
            }
        }

        return [
            'lines' => array_slice($lines, -320),
            'labels' => array_slice($labels, -500),
            'label_size' => $this->normalizeLabelSize((string) $p['label_size']),
        ];
    }

    private function normalizeLabelSize(string $raw): string
    {
        $size = strtolower(trim($raw));

        return in_array($size, ['tiny', 'small', 'normal'], true) ? $size : 'small';
    }
}
