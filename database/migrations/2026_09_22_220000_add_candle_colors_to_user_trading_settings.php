<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Персональные цвета свечей графика (по умолчанию — как на Pocket Option).
 * null в колонках = использовать ChartCandleColors::DEFAULT_*.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_trading_settings', function (Blueprint $table) {
            $table->string('candle_up_color', 7)->nullable()->after('mirror_proxy_auto_history');
            $table->string('candle_down_color', 7)->nullable()->after('candle_up_color');
        });
    }

    public function down(): void
    {
        Schema::table('user_trading_settings', function (Blueprint $table) {
            $table->dropColumn(['candle_up_color', 'candle_down_color']);
        });
    }
};
