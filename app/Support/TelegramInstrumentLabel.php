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

    /** @var array<string, string> display_name без кода в скобках. */
    private static array $displayCache = [];

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

        $displayName = self::resolveDisplayName($instrument);

        // Нет названия или оно совпадает с кодом — не дублируем «EURUSD_otc (EURUSD_otc)».
        if ($displayName === '' || strcasecmp($displayName, $instrument) === 0) {
            return self::$cache[$instrument] = $instrument;
        }

        return self::$cache[$instrument] = $displayName.' ('.$instrument.')';
    }

    /**
     * Только display_name (как state.display_name в updateBotM1), без кода в скобках.
     */
    public static function displayName(string $instrument): string
    {
        $instrument = trim($instrument);
        if ($instrument === '') {
            return '';
        }
        if (array_key_exists($instrument, self::$displayCache)) {
            return self::$displayCache[$instrument];
        }
        $name = self::resolveDisplayName($instrument);
        $resolved = $name !== '' ? $name : $instrument;

        return self::$displayCache[$instrument] = $resolved;
    }

    private static function resolveDisplayName(string $instrument): string
    {
        try {
            $displayName = PocketInstrument::query()
                ->where('instrument', $instrument)
                ->value('display_name');
        } catch (\Throwable) {
            // Нет миграций (юнит без RefreshDatabase) или сбой БД — не ломаем сборку текста алерта.
            return '';
        }

        return is_string($displayName) ? trim($displayName) : '';
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
        self::$displayCache = [];
    }
}
