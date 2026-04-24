<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Добавляем новую настройку ограничения нагрузки на один proxy endpoint.
        // Значение "1" по умолчанию минимизирует риск массового падения,
        // когда слишком много инструментов случайно сходятся на одном прокси.
        DB::table('app_settings')->updateOrInsert(
            ['key' => 'proxy_max_instruments_per_endpoint'],
            [
                'value' => '1',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('app_settings')
            ->where('key', 'proxy_max_instruments_per_endpoint')
            ->delete();
    }
};
