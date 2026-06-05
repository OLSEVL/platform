<?php

namespace App\Support;

/**
 * Парсинг цепочки сумм мартингейла из строки профиля (1, 2, 2, 4).
 */
final class MartingaleAmountsParser
{
    public const MIN_AMOUNT = 1.0;

    public const MAX_AMOUNT = 20000.0;

    /**
     * @return array{amounts: list<float>, error: ?string}
     */
    public static function parse(string $raw): array
    {
        $normalized = str_replace(';', ',', $raw);
        $parts = preg_split('/\s*,\s*/', trim($normalized), -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false || $parts === []) {
            return ['amounts' => [], 'error' => 'Укажите хотя бы одну сумму (например: 1, 2, 4).'];
        }

        $amounts = [];
        foreach ($parts as $part) {
            $token = trim($part);
            if ($token === '') {
                continue;
            }
            if (! is_numeric($token)) {
                return ['amounts' => [], 'error' => 'Некорректное число в цепочке: '.$token];
            }
            $value = (float) $token;
            if ($value < self::MIN_AMOUNT || $value > self::MAX_AMOUNT) {
                return [
                    'amounts' => [],
                    'error' => sprintf(
                        'Каждая сумма должна быть от %s до %s (получено: %s).',
                        self::formatAmount(self::MIN_AMOUNT),
                        self::formatAmount(self::MAX_AMOUNT),
                        $token
                    ),
                ];
            }
            $amounts[] = round($value, 2);
        }

        if ($amounts === []) {
            return ['amounts' => [], 'error' => 'Укажите хотя бы одну сумму.'];
        }

        return ['amounts' => $amounts, 'error' => null];
    }

    /** @param  list<float>  $amounts */
    public static function formatListForInput(array $amounts): string
    {
        return implode(', ', array_map(
            fn (float $a) => self::formatAmount($a),
            $amounts
        ));
    }

    public static function formatAmount(float $amount): string
    {
        $formatted = number_format($amount, 2, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }
}
