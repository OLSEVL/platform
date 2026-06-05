<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bot_llm_logs')) {
            return;
        }

        Schema::table('bot_llm_logs', function (Blueprint $table): void {
            if (! Schema::hasColumn('bot_llm_logs', 'chart_levels_json')) {
                $table->json('chart_levels_json')->nullable()->after('usage_json');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('bot_llm_logs')) {
            return;
        }

        Schema::table('bot_llm_logs', function (Blueprint $table): void {
            if (Schema::hasColumn('bot_llm_logs', 'chart_levels_json')) {
                $table->dropColumn('chart_levels_json');
            }
        });
    }
};
