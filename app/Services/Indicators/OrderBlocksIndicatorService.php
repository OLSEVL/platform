<?php

namespace App\Services\Indicators;

use App\Services\LuxOrderBlockBreakerDetector;

/**
 * Параметры и стили оверлея «Order Blocks & Breaker Blocks» (LuxAlgo, orderblock.txt).
 * Детекция зон — в LuxOrderBlockBreakerDetector; здесь дефолты, merge и раскраска для API.
 */
class OrderBlocksIndicatorService
{
    /** Дефолты совпадают с inputs Pine (length=10, show 3/3, useBody=false). */
    public const DEFAULT_PARAMS = [
        'length' => 10,
        'show_bull' => 3,
        'show_bear' => 3,
        'use_body' => false,
        'color_bull_bg' => '#2157f3',
        'color_bull_bg_alpha' => 80,
        'color_bull_border' => '#2157f3',
        'color_bull_break_bg' => '#ff1100',
        'color_bull_break_bg_alpha' => 80,
        'color_bull_break_border' => '#ff1100',
        'color_bear_bg' => '#ff5d00',
        'color_bear_bg_alpha' => 80,
        'color_bear_border' => '#ff5d00',
        'color_bear_break_bg' => '#0cb51a',
        'color_bear_break_bg_alpha' => 80,
        'color_bear_break_border' => '#0cb51a',
    ];

    /** @return array<string, mixed> */
    public static function defaultParams(): array
    {
        return self::DEFAULT_PARAMS;
    }

    /** @param  array<string, mixed>|null  $params */
    public function createDetector(?array $params = null): LuxOrderBlockBreakerDetector
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);

        return new LuxOrderBlockBreakerDetector(
            length: max(3, (int) ($p['length'] ?? 10)),
            showBull: max(0, (int) ($p['show_bull'] ?? 3)),
            showBear: max(0, (int) ($p['show_bear'] ?? 3)),
            useBody: (bool) ($p['use_body'] ?? false),
        );
    }

    /**
     * Добавляет fill/border к зонам ордерблоков для отрисовки на графике.
     *
     * @param  array<int, array<string, mixed>>  $zones
     * @param  array<string, mixed>|null  $params
     * @return array<int, array<string, mixed>>
     */
    public function applyStylesToZones(array $zones, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $styles = $this->zoneStyles($p);

        return array_map(function (array $zone) use ($styles): array {
            if (! str_starts_with((string) ($zone['type'] ?? ''), 'order_block')) {
                return $zone;
            }
            $style = $styles[$zone['type']] ?? null;
            if ($style === null) {
                return $zone;
            }

            return array_merge($zone, $style);
        }, $zones);
    }

    /**
     * @param  array<string, mixed>  $p
     * @return array<string, array{fill: string, border: string, label: string, label_color: string}>
     */
    private function zoneStyles(array $p): array
    {
        return [
            'order_block_bull' => [
                'fill' => $this->fillRgba((string) $p['color_bull_bg'], (int) $p['color_bull_bg_alpha']),
                'border' => '1px solid '.$this->borderRgba((string) $p['color_bull_border']),
                'label' => 'Бычий ордерблок',
                'label_color' => $this->borderRgba((string) $p['color_bull_border']),
            ],
            'order_block_bull_break' => [
                'fill' => $this->fillRgba((string) $p['color_bull_break_bg'], (int) $p['color_bull_break_bg_alpha']),
                'border' => '1px dashed '.$this->borderRgba((string) $p['color_bull_break_border']),
                'label' => 'Бычий брейкер',
                'label_color' => $this->borderRgba((string) $p['color_bull_break_border']),
            ],
            'order_block_bear' => [
                'fill' => $this->fillRgba((string) $p['color_bear_bg'], (int) $p['color_bear_bg_alpha']),
                'border' => '1px solid '.$this->borderRgba((string) $p['color_bear_border']),
                'label' => 'Медвежий ордерблок',
                'label_color' => $this->borderRgba((string) $p['color_bear_border']),
            ],
            'order_block_bear_break' => [
                'fill' => $this->fillRgba((string) $p['color_bear_break_bg'], (int) $p['color_bear_break_bg_alpha']),
                'border' => '1px dashed '.$this->borderRgba((string) $p['color_bear_break_border']),
                'label' => 'Медвежий брейкер',
                'label_color' => $this->borderRgba((string) $p['color_bear_break_border']),
            ],
        ];
    }

    /** Заливка: Pine color.new(hex, transparency) → rgba с opacity (100 - alpha) / 100. */
    private function fillRgba(string $hex, int $transparency): string
    {
        [$r, $g, $b] = $this->hexRgb($hex);
        $opacity = (100 - max(0, min(100, $transparency))) / 100;

        return sprintf('rgba(%d, %d, %d, %.2f)', $r, $g, $b, $opacity);
    }

    /** Граница — непрозрачный rgb из hex (как css.notransp() в Pine). */
    private function borderRgba(string $hex): string
    {
        [$r, $g, $b] = $this->hexRgb($hex);

        return sprintf('rgba(%d, %d, %d, 0.85)', $r, $g, $b);
    }

    /** @return array{0: int, 1: int, 2: int} */
    private function hexRgb(string $hex): array
    {
        $hex = ltrim(trim($hex), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        if (strlen($hex) !== 6 || ! ctype_xdigit($hex)) {
            return [128, 128, 128];
        }

        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }
}
