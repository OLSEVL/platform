<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_trades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bot_signal_id')->nullable()->constrained('bot_signals')->nullOnDelete();
            $table->string('instrument', 63);
            $table->string('side', 8);
            $table->string('status', 16)->default('open');
            $table->string('result', 16)->default('pending');
            $table->timestamp('entry_time');
            $table->timestamp('expiry_time');
            $table->timestamp('settled_at')->nullable();
            $table->decimal('entry_price', 20, 8);
            $table->decimal('expiry_price', 20, 8)->nullable();
            $table->decimal('payout_snapshot', 5, 2)->nullable();
            $table->decimal('pnl', 10, 4)->nullable();
            $table->json('meta_json')->nullable();
            $table->timestamps();

            $table->index(['bot_id', 'status', 'expiry_time']);
            $table->index(['instrument', 'entry_time']);
            $table->index(['result', 'settled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_trades');
    }
};
