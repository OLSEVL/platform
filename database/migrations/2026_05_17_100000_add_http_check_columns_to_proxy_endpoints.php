<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proxy_endpoints', function (Blueprint $table) {
            $table->timestamp('last_http_check_at')->nullable()->after('cooldown_until');
            $table->unsignedSmallInteger('last_http_status')->nullable()->after('last_http_check_at');
            $table->unsignedInteger('last_http_latency_ms')->nullable()->after('last_http_status');
            // ok | warn | fail — результат последнего quick/deep теста из UI.
            $table->string('last_http_check_result', 16)->nullable()->after('last_http_latency_ms');
            $table->string('last_http_check_mode', 16)->nullable()->after('last_http_check_result');
        });
    }

    public function down(): void
    {
        Schema::table('proxy_endpoints', function (Blueprint $table) {
            $table->dropColumn([
                'last_http_check_at',
                'last_http_status',
                'last_http_latency_ms',
                'last_http_check_result',
                'last_http_check_mode',
            ]);
        });
    }
};
