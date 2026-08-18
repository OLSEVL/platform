<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Виртуальные входы отделены от bot_trades: они только измеряют серию,
        // не должны искажать PnL, статистику или отправляться на Pocket Option.
        Schema::create('bot_virtual_trades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_id')->constrained()->cascadeOnDelete();
            $table->string('instrument', 63);
            $table->string('side', 8);
            $table->string('status', 16)->default('open');
            $table->string('result', 16)->default('pending');
            $table->timestamp('entry_time');
            $table->timestamp('expiry_time');
            $table->timestamp('settled_at')->nullable();
            $table->decimal('entry_price', 20, 8);
            $table->decimal('expiry_price', 20, 8)->nullable();
            $table->string('dedupe_key', 191)->unique();
            $table->timestamps();

            $table->index(['bot_id', 'instrument', 'status', 'expiry_time']);
            $table->index(['status', 'expiry_time']);
        });

        // Одно состояние на «бот + пара». Победа или ничья обнуляет серию;
        // убыток увеличивает её и разрешает настоящий вход после заданного порога.
        Schema::create('bot_instrument_loss_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_id')->constrained()->cascadeOnDelete();
            $table->string('instrument', 63);
            $table->unsignedInteger('consecutive_losses')->default(0);
            $table->string('last_result', 16)->nullable();
            $table->timestamp('last_settled_at')->nullable();
            $table->timestamps();

            $table->unique(['bot_id', 'instrument']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_instrument_loss_states');
        Schema::dropIfExists('bot_virtual_trades');
    }
};
