<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('user_trading_settings', 'mirror_wait_previous_settled')) {
            return;
        }

        Schema::table('user_trading_settings', function (Blueprint $table) {
            $table->dropColumn('mirror_wait_previous_settled');
        });
    }

    public function down(): void
    {
        Schema::table('user_trading_settings', function (Blueprint $table) {
            $table->boolean('mirror_wait_previous_settled')
                ->default(false)
                ->after('mirror_trades_enabled');
        });
    }
};
