<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_trading_settings', function (Blueprint $table) {
            // Ручная кнопка: ждать/учитывать «одна сделка на PO» в обе стороны (дефолт как Verdict).
            $table->boolean('manual_respect_wait_previous')->default(false)->after('mirror_wait_previous_settled');
            // Ручная сделка двигает martingale_step при settle (по умолчанию нет).
            $table->boolean('manual_advance_martingale')->default(false)->after('manual_respect_wait_previous');
            // Сумма ставки с шага мартингейла профиля, а не из поля панели (поле тогда read-only).
            $table->boolean('manual_amount_from_martingale')->default(false)->after('manual_advance_martingale');
        });
    }

    public function down(): void
    {
        Schema::table('user_trading_settings', function (Blueprint $table) {
            $table->dropColumn([
                'manual_respect_wait_previous',
                'manual_advance_martingale',
                'manual_amount_from_martingale',
            ]);
        });
    }
};
