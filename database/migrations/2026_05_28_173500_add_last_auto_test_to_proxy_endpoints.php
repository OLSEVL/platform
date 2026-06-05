<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proxy_endpoints', function (Blueprint $table): void {
            // Храним сводный итог последнего авто-теста в одном JSON:
            // статус, время, инструмент, причина и служебные метрики.
            $table->json('last_auto_test')->nullable()->after('last_http_check_mode');
        });
    }

    public function down(): void
    {
        Schema::table('proxy_endpoints', function (Blueprint $table): void {
            $table->dropColumn('last_auto_test');
        });
    }
};

