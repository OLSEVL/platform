<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('user_trading_settings', 'mirror_proxy_auto')) {
            return;
        }

        Schema::table('user_trading_settings', function (Blueprint $table) {
            // Автовыбор active-прокси с минимальным числом пар (нагрузка ingest).
            $table->boolean('mirror_proxy_auto')->default(false)->after('mirror_proxy_endpoint_ids');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('user_trading_settings', 'mirror_proxy_auto')) {
            return;
        }

        Schema::table('user_trading_settings', function (Blueprint $table) {
            $table->dropColumn('mirror_proxy_auto');
        });
    }
};
