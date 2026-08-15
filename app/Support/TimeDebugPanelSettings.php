<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Включение диагностической плашки времени на графике бота.
 * Хранится в app_settings (страница /settings), без .env-флага.
 */
final class TimeDebugPanelSettings
{
    public const SETTING_KEY = 'time_debug_panel_enabled';

    /** Читает флаг из app_settings; по умолчанию выключено. */
    public static function isEnabled(): bool
    {
        if (! Schema::hasTable('app_settings')) {
            return false;
        }

        $value = DB::table('app_settings')
            ->where('key', self::SETTING_KEY)
            ->value('value');

        return in_array((string) $value, ['1', 'true', 'on', 'yes'], true);
    }

    /** Сохраняет включение/выключение плашки. */
    public static function setEnabled(bool $enabled): void
    {
        DB::table('app_settings')->updateOrInsert(
            ['key' => self::SETTING_KEY],
            [
                'value' => $enabled ? '1' : '0',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }
}
