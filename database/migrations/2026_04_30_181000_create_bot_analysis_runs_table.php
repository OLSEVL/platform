<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_analysis_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_id')->constrained()->cascadeOnDelete();
            $table->string('instrument', 63);
            $table->string('status', 16)->default('running');
            $table->string('skip_reason', 64)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->json('runtime_json')->nullable();
            $table->json('market_snapshot_json')->nullable();
            $table->json('decision_json')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['bot_id', 'started_at']);
            $table->index(['bot_id', 'status', 'started_at']);
            $table->index(['bot_id', 'instrument', 'started_at']);
            $table->index(['bot_id', 'skip_reason', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_analysis_runs');
    }
};
