<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proxy_endpoints', function (Blueprint $table) {
            // История переводов в dead: reason, instrument_id, timestamp (+ опциональный meta).
            $table->json('dead_reason_log')->nullable()->after('last_auto_test');
        });
    }

    public function down(): void
    {
        Schema::table('proxy_endpoints', function (Blueprint $table) {
            $table->dropColumn('dead_reason_log');
        });
    }
};
