<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bot_llm_logs')) {
            Schema::create('bot_llm_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('bot_id')->constrained()->cascadeOnDelete();
                $table->foreignId('bot_analysis_run_id')->nullable()->constrained('bot_analysis_runs')->nullOnDelete();
                $table->foreignId('bot_signal_id')->nullable()->constrained('bot_signals')->nullOnDelete();
                $table->foreignId('bot_trade_id')->nullable()->constrained('bot_trades')->nullOnDelete();
                $table->string('instrument', 63)->nullable();
                $table->string('provider', 32)->default('openrouter');
                $table->string('model', 128)->nullable();
                $table->string('status', 16)->default('ok');
                $table->unsignedInteger('latency_ms')->nullable();
                $table->json('request_json')->nullable();
                $table->json('response_json')->nullable();
                $table->longText('request_text')->nullable();
                $table->longText('response_text')->nullable();
                $table->json('usage_json')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamps();

                $table->index(['bot_id', 'created_at']);
                $table->index(['bot_id', 'status', 'created_at']);
                $table->index(['bot_analysis_run_id']);
                $table->index(['bot_signal_id']);
                $table->index(['bot_trade_id']);
            });

            return;
        }

        // Совместимость со старыми инсталляциями, где bot_llm_logs уже была создана ранее
        // в другом формате: аккуратно добавляем недостающие колонки, не теряя историю.
        Schema::table('bot_llm_logs', function (Blueprint $table): void {
            if (! Schema::hasColumn('bot_llm_logs', 'bot_analysis_run_id')) {
                $table->unsignedBigInteger('bot_analysis_run_id')->nullable()->after('bot_id');
            }
            if (! Schema::hasColumn('bot_llm_logs', 'bot_trade_id')) {
                $table->unsignedBigInteger('bot_trade_id')->nullable()->after('bot_signal_id');
            }
            if (! Schema::hasColumn('bot_llm_logs', 'provider')) {
                $table->string('provider', 32)->default('openrouter')->after('instrument');
            }
            if (! Schema::hasColumn('bot_llm_logs', 'request_text')) {
                $table->longText('request_text')->nullable()->after('response_json');
            }
            if (! Schema::hasColumn('bot_llm_logs', 'response_text')) {
                $table->longText('response_text')->nullable()->after('request_text');
            }
            if (! Schema::hasColumn('bot_llm_logs', 'usage_json')) {
                $table->json('usage_json')->nullable()->after('response_text');
            }
            if (! Schema::hasColumn('bot_llm_logs', 'error_message')) {
                $table->text('error_message')->nullable()->after('usage_json');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_llm_logs');
    }
};
