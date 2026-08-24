<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chart_indicator_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_id')->constrained('bots')->cascadeOnDelete();
            // Pilot: sma, ema — позже расширим список типов.
            $table->string('indicator_type', 32);
            $table->json('settings');
            $table->boolean('visible')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['bot_id', 'indicator_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chart_indicator_instances');
    }
};
