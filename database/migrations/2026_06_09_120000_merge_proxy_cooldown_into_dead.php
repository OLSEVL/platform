<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('proxy_endpoints')
            ->where('status', 'cooldown')
            ->update([
                'status' => 'dead',
                'updated_at' => now(),
            ]);

        $legacyThreshold = DB::table('app_settings')
            ->where('key', 'proxy_cooldown_streak_threshold')
            ->value('value');

        if ($legacyThreshold !== null) {
            DB::table('app_settings')->updateOrInsert(
                ['key' => 'proxy_dead_streak_threshold'],
                [
                    'value' => (string) $legacyThreshold,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
            DB::table('app_settings')
                ->where('key', 'proxy_cooldown_streak_threshold')
                ->delete();
        }

        Schema::table('proxy_endpoints', function (Blueprint $table) {
            $table->dropColumn('cooldown_until');
        });
    }

    public function down(): void
    {
        Schema::table('proxy_endpoints', function (Blueprint $table) {
            $table->timestamp('cooldown_until')->nullable()->after('last_success_at');
        });

        $deadThreshold = DB::table('app_settings')
            ->where('key', 'proxy_dead_streak_threshold')
            ->value('value');

        if ($deadThreshold !== null) {
            DB::table('app_settings')->updateOrInsert(
                ['key' => 'proxy_cooldown_streak_threshold'],
                [
                    'value' => (string) $deadThreshold,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
            DB::table('app_settings')
                ->where('key', 'proxy_dead_streak_threshold')
                ->delete();
        }
    }
};
