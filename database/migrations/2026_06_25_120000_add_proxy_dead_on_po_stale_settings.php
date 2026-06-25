<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Авто-dead прокси, если «Событие PO» не обновлялось дольше порога (отдельно от UI-подсветки payout_stale_minutes_threshold).
        DB::table('app_settings')->updateOrInsert(
            ['key' => 'proxy_dead_on_po_stale_enabled'],
            [
                'value' => '0',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::table('app_settings')->updateOrInsert(
            ['key' => 'proxy_dead_on_po_stale_minutes'],
            [
                'value' => '30',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('app_settings')
            ->whereIn('key', ['proxy_dead_on_po_stale_enabled', 'proxy_dead_on_po_stale_minutes'])
            ->delete();
    }
};
