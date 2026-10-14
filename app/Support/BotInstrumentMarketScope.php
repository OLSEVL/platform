<?php

namespace App\Support;

use Illuminate\Validation\Rule;

/**
 * Фильтр рынка для ботов: все пары / только OTC / только non-OTC.
 *
 * OTC в платформе — суффикс `_otc` у кода инструмента (EURUSD_otc vs EURUSD).
 */
final class BotInstrumentMarketScope
{
    public const ALL = 'all';

    public const OTC = 'otc';

    public const NON_OTC = 'non_otc';

    /**
     * @return list<string>
     */
    public static function allowed(): array
    {
        return [self::ALL, self::OTC, self::NON_OTC];
    }

    public static function normalize(mixed $raw): string
    {
        $value = strtolower(trim((string) $raw));

        return in_array($value, self::allowed(), true) ? $value : self::ALL;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): string
    {
        return self::normalize($config['market_scope'] ?? self::ALL);
    }

    public static function isOtc(string $instrument): bool
    {
        return str_ends_with(strtolower(trim($instrument)), '_otc');
    }

    public static function allows(string $instrument, string $scope): bool
    {
        $scope = self::normalize($scope);
        if ($scope === self::ALL) {
            return true;
        }
        $isOtc = self::isOtc($instrument);

        return $scope === self::OTC ? $isOtc : ! $isOtc;
    }

    /**
     * @return array<int, mixed>
     */
    public static function validationRules(): array
    {
        return ['required', 'string', Rule::in(self::allowed())];
    }

    /**
     * Подписи для UI.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::ALL => 'Все пары',
            self::OTC => 'Только OTC',
            self::NON_OTC => 'Только non-OTC (без _otc)',
        ];
    }
}
