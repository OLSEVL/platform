<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Хранилище прогонов настроек бота по истории свечей (не путать с live BotTrade).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_backtest_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // queued → running → completed|failed|cancelled
            $table->string('status', 16)->default('queued');
            $table->unsignedTinyInteger('progress_pct')->default(0);
            // Период истории (unix UTC), который реально прогоняем.
            $table->unsignedInteger('from_ts');
            $table->unsignedInteger('to_ts');
            // Snapshot настроек на момент старта (live config бота может потом измениться).
            $table->json('config_snapshot_json');
            // Параметры самого прогона: пары, payout, stake, флаги симуляции.
            $table->json('params_json');
            // Агрегированные метрики после завершения (WR, PF, equity…).
            $table->json('summary_json')->nullable();
            $table->unsignedInteger('bars_total')->default(0);
            $table->unsignedInteger('bars_done')->default(0);
            $table->unsignedInteger('trades_total')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['bot_id', 'created_at']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('bot_backtest_trades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_backtest_run_id')->constrained('bot_backtest_runs')->cascadeOnDelete();
            $table->string('instrument', 63);
            $table->string('side', 8);
            $table->string('result', 16)->default('pending');
            $table->unsignedInteger('signal_ts');
            $table->unsignedInteger('entry_ts');
            $table->unsignedInteger('expiry_ts');
            $table->unsignedInteger('expiry_sec');
            $table->decimal('entry_price', 20, 8);
            $table->decimal('expiry_price', 20, 8)->nullable();
            $table->decimal('payout_snapshot', 5, 2)->nullable();
            // PnL в «юнитах ставки»: win = payout/100, loss = -1, draw = 0.
            $table->decimal('pnl', 10, 4)->nullable();
            $table->decimal('confidence', 8, 4)->nullable();
            $table->json('decision_json')->nullable();
            $table->timestamps();

            $table->index(['bot_backtest_run_id', 'instrument', 'entry_ts'], 'bbt_run_instr_entry_idx');
            $table->index(['bot_backtest_run_id', 'result'], 'bbt_run_result_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_backtest_trades');
        Schema::dropIfExists('bot_backtest_runs');
    }
};
