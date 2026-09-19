<?php

namespace App\Support;

use App\Models\PocketInstrument;

/**
 * Подпись инструмента в Telegram-сообщениях и подписях к PNG.
 *
 * В алертах раньше уходил только короткий код (колонка instrument, напр. AUDNZD_otc).
 * Теперь: полное имя из display_name таблицы pocket_instruments + код в скобках:
 * «AUD/NZD OTC (AUDNZD_otc)». Если display_name пуст — только код.
 */
final class TelegramInstrumentLabel
{
    /** @var array<string, string> Кэш на время PHP-процесса (один алерт часто форматирует пару несколько раз). */
    private static array $cache = [];

    /**
     * Человекочитаемая подпись: «Название (код)» или просто «код».
     */
    public static function format(string $instrument): string
    {
        $instrument = trim($instrument);
        if ($instrument === '') {
            return '';
        }

        if (array_key_exists($instrument, self::$cache)) {
            return self::$cache[$instrument];
        }

        try {
            $displayName = PocketInstrument::query()
                ->where('instrument', $instrument)
                ->value('display_name');
        } catch (\Throwable) {
            // Нет миграций (юнит без RefreshDatabase) или сбой БД — не ломаем сборку текста алерта.
            return self::$cache[$instrument] = $instrument;
        }

        $displayName = is_string($displayName) ? trim($displayName) : '';

        // Нет названия или оно совпадает с кодом — не дублируем «EURUSD_otc (EURUSD_otc)».
        if ($displayName === '' || strcasecmp($displayName, $instrument) === 0) {
            return self::$cache[$instrument] = $instrument;
        }

        return self::$cache[$instrument] = $displayName.' ('.$instrument.')';
    }

    /**
     * То же, что format(), сразу экранированное для Telegram parse_mode=HTML.
     */
    public static function html(string $instrument): string
    {
        return htmlspecialchars(self::format($instrument), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Сброс кэша (юнит-тесты и смена display_name в том же процессе). */
    public static function clearCache(): void
    {
        self::$cache = [];
    }
}
