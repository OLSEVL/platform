<?php

namespace App\Support;

use App\Models\UserTradingSetting;

/**
 * Цвета свечей графика: дефолт как на Pocket Option, с override на пользователя.
 *
 * Hex сняты со скрина PO-терминала (насыщенный lime / чистый red), не TradingView teal.
 */
class ChartCandleColors
{
    /** Бычья свеча по умолчанию (как на PO). */
    public const DEFAULT_UP = '#22ff00';

    /** Медвежья / doji по умолчанию (как на PO). */
    public const DEFAULT_DOWN = '#ff0000';

    /**
     * @return array{up: string, down: string}
     */
    public static function defaults(): array
    {
        return [
            'up' => self::DEFAULT_UP,
            'down' => self::DEFAULT_DOWN,
        ];
    }

    /**
     * Нормализация hex (#rgb / #rrggbb / без #) → lowercase #rrggbb или null.
     */
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $hex = strtolower(trim($raw));
        if ($hex === '') {
            return null;
        }

        if ($hex[0] !== '#') {
            $hex = '#'.$hex;
        }

        if (preg_match('/^#([0-9a-f]{3})$/', $hex, $m) === 1) {
            $s = $m[1];

            return '#'.$s[0].$s[0].$s[1].$s[1].$s[2].$s[2];
        }

        if (preg_match('/^#([0-9a-f]{6})$/', $hex) === 1) {
            return $hex;
        }

        return null;
    }

    /**
     * Цвета для boot JSON графика (dashboard / bot).
     *
     * @return array{up: string, down: string}
     */
    public static function forUser(?int $userId): array
    {
        $defaults = [
            'up' => self::normalize((string) config('chart.candle_up_color', self::DEFAULT_UP)) ?? self::DEFAULT_UP,
            'down' => self::normalize((string) config('chart.candle_down_color', self::DEFAULT_DOWN)) ?? self::DEFAULT_DOWN,
        ];
        if ($userId === null || $userId <= 0) {
            return $defaults;
        }

        $row = UserTradingSetting::query()
            ->where('user_id', $userId)
            ->first(['candle_up_color', 'candle_down_color']);

        if ($row === null) {
            return $defaults;
        }

        return [
            'up' => self::normalize($row->candle_up_color) ?? $defaults['up'],
            'down' => self::normalize($row->candle_down_color) ?? $defaults['down'],
        ];
    }
}
