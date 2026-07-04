<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('telegram_sl_deep_links');
    }

    public function down(): void
    {
        // Таблица deep link (вариант B) намеренно не восстанавливается.
    }
};
