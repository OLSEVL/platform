<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_trading_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Полная строка SSID из браузера (42["auth",...]); хранится зашифрованной на уровне модели.
            $table->text('pocket_ssid')->nullable();
            $table->boolean('is_demo')->default(true);
            $table->decimal('order_amount', 10, 2)->default(1);
            // Зеркалирование: каждая paper-сделка бота → один ордер на Pocket Option.
            $table->boolean('mirror_trades_enabled')->default(false);
            $table->timestamps();

            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_trading_settings');
    }
};
