<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('indicator_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_id')->constrained('bots')->cascadeOnDelete();
            // Ключ индикатора, например price_action_dgt (совпадает с вложенным ключом в legacy config).
            $table->string('indicator_key', 64);
            $table->json('settings');
            $table->timestamps();

            $table->unique(['bot_id', 'indicator_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('indicator_settings');
    }
};
