<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('user_trading_settings', 'mirror_proxy_endpoint_ids')) {
            return;
        }

        Schema::table('user_trading_settings', function (Blueprint $table) {
            // Пул active-прокси для случайного выбора при зеркале (anti-ban по IP).
            $table->json('mirror_proxy_endpoint_ids')->nullable()->after('mirror_wait_previous_settled');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('user_trading_settings', 'mirror_proxy_endpoint_ids')) {
            return;
        }

        Schema::table('user_trading_settings', function (Blueprint $table) {
            $table->dropColumn('mirror_proxy_endpoint_ids');
        });
    }
};
