<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Настройки синхронизации пула прокси с Webshare.
 *
 * Значения живут в app_settings, поэтому их можно менять из интерфейса
 * без перезапуска приложения или изменения .env. Отсутствующая запись
 * намеренно трактуется как "включено": это сохраняет выбранное по умолчанию
 * безопасное поведение и для уже установленных инстансов приложения.
 */
final class WebshareProxyRefreshSettings
{
    /** Ежедневная полная замена пула в 00:00. */
    public const SETTING_KEY = 'webshare_daily_proxy_refresh_enabled';

    /** Почасовой refresh только когда free_slots=0 (пул исчерпан). */
    public const HOURLY_ON_EXHAUSTED_SETTING_KEY = 'webshare_hourly_exhausted_proxy_refresh_enabled';

    /**
     * Возвращает состояние ночного обновления; по умолчанию — включено.
     */
    public static function isEnabled(): bool
    {
        return self::readBoolSetting(self::SETTING_KEY, default: true);
    }

    /**
     * Сохраняет пользовательское состояние ночной синхронизации.
     */
    public static function setEnabled(bool $enabled): void
    {
        self::writeBoolSetting(self::SETTING_KEY, $enabled);
    }

    /**
     * Возвращает состояние почасового обновления при исчерпанном пуле;
     * по умолчанию — включено.
     */
    public static function isHourlyOnExhaustedEnabled(): bool
    {
        return self::readBoolSetting(self::HOURLY_ON_EXHAUSTED_SETTING_KEY, default: true);
    }

    /**
     * Сохраняет состояние почасового refresh при исчерпании слотов.
     */
    public static function setHourlyOnExhaustedEnabled(bool $enabled): void
    {
        self::writeBoolSetting(self::HOURLY_ON_EXHAUSTED_SETTING_KEY, $enabled);
    }

    /**
     * Читает boolean-флаг из app_settings с явным default при отсутствии ключа.
     */
    private static function readBoolSetting(string $key, bool $default): bool
    {
        // Во время первичной установки миграции могут ещё не создать таблицу.
        if (! Schema::hasTable('app_settings')) {
            return $default;
        }

        $value = DB::table('app_settings')
            ->where('key', $key)
            ->value('value');

        if ($value === null) {
            return $default;
        }

        return in_array((string) $value, ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * Пишет boolean-флаг в app_settings как '1'/'0'.
     */
    private static function writeBoolSetting(string $key, bool $enabled): void
    {
        DB::table('app_settings')->updateOrInsert(
            ['key' => $key],
            [
                'value' => $enabled ? '1' : '0',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }
}
