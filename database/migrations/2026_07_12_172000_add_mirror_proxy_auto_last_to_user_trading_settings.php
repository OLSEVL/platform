<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('user_trading_settings', 'mirror_proxy_auto_last_endpoint_id')) {
            Schema::table('user_trading_settings', function (Blueprint $table) {
                // Последний endpoint, выбранный режимом «Авто» (для подсветки в таблице).
                $table->unsignedBigInteger('mirror_proxy_auto_last_endpoint_id')->nullable()
                    ->after('mirror_proxy_auto');
                $table->timestamp('mirror_proxy_auto_last_at')->nullable()
                    ->after('mirror_proxy_auto_last_endpoint_id');
                // История недавних auto-выборов: [{id, at}, ...] — чтобы видеть, какие прокси уже брались.
                $table->json('mirror_proxy_auto_history')->nullable()
                    ->after('mirror_proxy_auto_last_at');
            });
        }
    }

    public function down(): void
    {
        Schema::table('user_trading_settings', function (Blueprint $table) {
            if (Schema::hasColumn('user_trading_settings', 'mirror_proxy_auto_history')) {
                $table->dropColumn('mirror_proxy_auto_history');
            }
            if (Schema::hasColumn('user_trading_settings', 'mirror_proxy_auto_last_at')) {
                $table->dropColumn('mirror_proxy_auto_last_at');
            }
            if (Schema::hasColumn('user_trading_settings', 'mirror_proxy_auto_last_endpoint_id')) {
                $table->dropColumn('mirror_proxy_auto_last_endpoint_id');
            }
        });
    }
};
