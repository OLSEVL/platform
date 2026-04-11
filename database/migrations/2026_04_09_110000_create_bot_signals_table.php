<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_id')->constrained()->cascadeOnDelete();
            $table->string('instrument', 63);
            $table->string('side', 8);
            $table->decimal('confidence', 5, 4)->nullable();
            $table->decimal('payout_snapshot', 5, 2)->nullable();
            $table->timestamp('signal_at');
            $table->timestamp('expiry_time');
            $table->json('reasons_json')->nullable();
            $table->text('analysis_text')->nullable();
            $table->json('features_json')->nullable();
            $table->string('decision_version', 64)->nullable();
            $table->string('dedupe_key', 191)->unique();
            $table->timestamps();

            $table->index(['bot_id', 'signal_at']);
            $table->index(['instrument', 'signal_at']);
            $table->index(['expiry_time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_signals');
    }
};
