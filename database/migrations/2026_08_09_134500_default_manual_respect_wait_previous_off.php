<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Дефолт «Ждать предыдущую на PO» → выкл.; сбрасываем текущие профили (фича только что вышла).
        Schema::table('user_trading_settings', function (Blueprint $table) {
            $table->boolean('manual_respect_wait_previous')->default(false)->change();
        });

        DB::table('user_trading_settings')->update([
            'manual_respect_wait_previous' => false,
        ]);
    }

    public function down(): void
    {
        Schema::table('user_trading_settings', function (Blueprint $table) {
            $table->boolean('manual_respect_wait_previous')->default(true)->change();
        });
    }
};
