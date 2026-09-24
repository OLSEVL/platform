<?php

namespace App\Services\Indicators;

/**
 * Strat Assistant — классификация баров The Strat (Rob Smith) для ручного фильтра.
 *
 * Тип относительно предыдущей свечи:
 * 1  inside  — high/low не вышли за диапазон прошлого бара (сжатие);
 * 2u         — пробит только high (directional up);
 * 2d         — пробит только low (directional down);
 * 3  outside — пробиты обе стороны (смена контроля).
 *
 * Комбо на закрытии паттерна: 2-2 reversal, 2-1-2 C/R, 3-1-2, 3-2.
 * Не торгует сам и не меняет логику ботов — только маркеры на графике.
 */
class StratAssistantIndicatorService
{
    public const ID = 'tv.strat_assistant';

    public const TYPE_INSIDE = '1';

    public const TYPE_TWO_UP = '2u';

    public const TYPE_TWO_DOWN = '2d';

    public const TYPE_OUTSIDE = '3';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'show_types' => true,
        'show_combos' => true,
        'color_inside' => '#a1a1aa',
        'color_two_up' => '#22c55e',
        'color_two_down' => '#ef4444',
        'color_outside' => '#eab308',
        'color_combo_bull' => '#38bdf8',
        'color_combo_bear' => '#f472b6',
    ];

    /** @return array<string, mixed> */
    public static function defaultParams(): array
    {
        return self::DEFAULT_PARAMS;
    }

    /**
     * Тип бара i относительно бара i-1. Бар 0 без эталона — null.
     *
     * @param  array{high:float,low:float}  $curr
     * @param  array{high:float,low:float}  $prev
     */
    public static function classifyBar(array $curr, array $prev): string
    {
        $high = (float) $curr['high'];
        $low = (float) $curr['low'];
        $prevHigh = (float) $prev['high'];
        $prevLow = (float) $prev['low'];
        $brokeHigh = $high > $prevHigh;
        $brokeLow = $low < $prevLow;
        if ($brokeHigh && $brokeLow) {
            return self::TYPE_OUTSIDE;
        }
        if ($brokeHigh) {
            return self::TYPE_TWO_UP;
        }
        if ($brokeLow) {
            return self::TYPE_TWO_DOWN;
        }

        return self::TYPE_INSIDE;
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  array<string, mixed>|null  $params
     * @return array{id:string,title:string,markers:list<array<string,mixed>>,meta:array<string,mixed>}
     */
    public function compute(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $n = count($rows);
        $types = array_fill(0, $n, null);
        for ($i = 1; $i < $n; $i++) {
            $types[$i] = self::classifyBar($rows[$i], $rows[$i - 1]);
        }

        $markers = [];
        $comboCount = 0;
        $showTypes = (bool) $p['show_types'];
        $showCombos = (bool) $p['show_combos'];

        for ($i = 1; $i < $n; $i++) {
            $type = $types[$i];
            if (! is_string($type)) {
                continue;
            }
            $time = (int) $rows[$i]['time'];
            $combo = $showCombos ? $this->comboAt($types, $i) : null;
            if ($combo !== null) {
                $comboCount++;
                $bull = $combo['dir'] === 'up';
                $markers[] = [
                    'time' => $time,
                    'position' => $bull ? 'aboveBar' : 'belowBar',
                    'color' => $bull ? (string) $p['color_combo_bull'] : (string) $p['color_combo_bear'],
                    'shape' => $bull ? 'arrowUp' : 'arrowDown',
                    'text' => $combo['text'],
                ];
            }
            if (! $showTypes) {
                continue;
            }
            $markers[] = $this->typeMarker($type, $time, $p, $combo !== null);
        }

        return [
            'id' => self::ID,
            'title' => 'Strat Assistant',
            'markers' => $markers,
            'meta' => [
                'bars' => $n,
                'typed' => max(0, $n - 1),
                'combos' => $comboCount,
            ],
        ];
    }

    /**
     * Комбо с приоритетом 312 → 212 → 22R → 32 (одно на закрывающий бар).
     *
     * @param  list<string|null>  $types
     * @return array{text:string,dir:'up'|'down'}|null
     */
    private function comboAt(array $types, int $i): ?array
    {
        $c = $types[$i];
        $p1 = $i >= 1 ? $types[$i - 1] : null;
        $p2 = $i >= 2 ? $types[$i - 2] : null;

        if ($p2 === self::TYPE_OUTSIDE && $p1 === self::TYPE_INSIDE && $c === self::TYPE_TWO_UP) {
            return ['text' => '312', 'dir' => 'up'];
        }
        if ($p2 === self::TYPE_OUTSIDE && $p1 === self::TYPE_INSIDE && $c === self::TYPE_TWO_DOWN) {
            return ['text' => '312', 'dir' => 'down'];
        }
        if ($p2 === self::TYPE_TWO_UP && $p1 === self::TYPE_INSIDE && $c === self::TYPE_TWO_UP) {
            return ['text' => '212C', 'dir' => 'up'];
        }
        if ($p2 === self::TYPE_TWO_DOWN && $p1 === self::TYPE_INSIDE && $c === self::TYPE_TWO_DOWN) {
            return ['text' => '212C', 'dir' => 'down'];
        }
        if ($p2 === self::TYPE_TWO_UP && $p1 === self::TYPE_INSIDE && $c === self::TYPE_TWO_DOWN) {
            return ['text' => '212R', 'dir' => 'down'];
        }
        if ($p2 === self::TYPE_TWO_DOWN && $p1 === self::TYPE_INSIDE && $c === self::TYPE_TWO_UP) {
            return ['text' => '212R', 'dir' => 'up'];
        }
        if ($p1 === self::TYPE_TWO_UP && $c === self::TYPE_TWO_DOWN) {
            return ['text' => '22R', 'dir' => 'down'];
        }
        if ($p1 === self::TYPE_TWO_DOWN && $c === self::TYPE_TWO_UP) {
            return ['text' => '22R', 'dir' => 'up'];
        }
        if ($p1 === self::TYPE_OUTSIDE && $c === self::TYPE_TWO_UP) {
            return ['text' => '32', 'dir' => 'up'];
        }
        if ($p1 === self::TYPE_OUTSIDE && $c === self::TYPE_TWO_DOWN) {
            return ['text' => '32', 'dir' => 'down'];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    private function typeMarker(string $type, int $time, array $p, bool $comboOccupiedOpposite): array
    {
        return match ($type) {
            self::TYPE_TWO_UP => [
                'time' => $time,
                'position' => $comboOccupiedOpposite ? 'belowBar' : 'aboveBar',
                'color' => (string) $p['color_two_up'],
                'shape' => 'arrowUp',
                'text' => '2',
            ],
            self::TYPE_TWO_DOWN => [
                'time' => $time,
                'position' => $comboOccupiedOpposite ? 'aboveBar' : 'belowBar',
                'color' => (string) $p['color_two_down'],
                'shape' => 'arrowDown',
                'text' => '2',
            ],
            self::TYPE_OUTSIDE => [
                'time' => $time,
                'position' => $comboOccupiedOpposite ? 'belowBar' : 'aboveBar',
                'color' => (string) $p['color_outside'],
                'shape' => 'square',
                'text' => '3',
            ],
            default => [
                'time' => $time,
                'position' => $comboOccupiedOpposite ? 'aboveBar' : 'belowBar',
                'color' => (string) $p['color_inside'],
                'shape' => 'circle',
                'text' => '1',
            ],
        };
    }
}
