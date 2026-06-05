<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pocket_instruments', function (Blueprint $table) {
            // Время последнего реального события payout от Pocket Options (WebSocket).
            $table->timestamp('last_payout_event_at')->nullable()->after('last_payout_at');
            // Ingest считает событие устаревшим, если PO не присылал payout дольше INGEST_PAYOUT_TTL_SEC.
            $table->boolean('payout_is_stale')->default(false)->after('last_payout_event_at');
            $table->index('last_payout_event_at');
            $table->index('payout_is_stale');
        });

        // Для уже накопленных строк переносим старое last_payout_at как приближение к событию PO.
        DB::table('pocket_instruments')
            ->whereNotNull('last_payout_at')
            ->update([
                'last_payout_event_at' => DB::raw('last_payout_at'),
            ]);
    }

    public function down(): void
    {
        Schema::table('pocket_instruments', function (Blueprint $table) {
            $table->dropIndex(['last_payout_event_at']);
            $table->dropIndex(['payout_is_stale']);
            $table->dropColumn(['last_payout_event_at', 'payout_is_stale']);
        });
    }
};
