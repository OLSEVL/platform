<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Настройка порога stale для payout-подсветки в таблице инструментов.
        // Значение по умолчанию сохраняет текущее поведение (5 минут), но делает его
        // управляемым из UI, без необходимости менять Blade-код и деплоить заново.
        DB::table('app_settings')->updateOrInsert(
            ['key' => 'payout_stale_minutes_threshold'],
            [
                'value' => '5',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('app_settings')
            ->where('key', 'payout_stale_minutes_threshold')
            ->delete();
    }
};
