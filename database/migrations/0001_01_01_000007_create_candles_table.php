<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candles', function (Blueprint $table) {
            $table->id();
            $table->string('instrument', 63);
            $table->unsignedSmallInteger('timeframe')->default(1);
            $table->unsignedBigInteger('time');
            $table->decimal('open', 20, 8);
            $table->decimal('high', 20, 8);
            $table->decimal('low', 20, 8);
            $table->decimal('close', 20, 8);
            $table->decimal('volume', 20, 8)->default(0);
            $table->timestamps();
            $table->unique(['instrument', 'timeframe', 'time']);
            $table->index(['instrument', 'timeframe', 'time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candles');
    }
};
