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
            // Удаляем legacy-поля старого формата логирования, которые больше не используются
            // в текущей модели/сервисах и только засоряют схему.
            if (Schema::hasColumn('bot_llm_logs', 'assistant_content')) {
                $table->dropColumn('assistant_content');
            }
            if (Schema::hasColumn('bot_llm_logs', 'parsed_json')) {
                $table->dropColumn('parsed_json');
            }
            if (Schema::hasColumn('bot_llm_logs', 'error_text')) {
                $table->dropColumn('error_text');
            }
            if (Schema::hasColumn('bot_llm_logs', 'prompt_preview')) {
                $table->dropColumn('prompt_preview');
            }
            if (Schema::hasColumn('bot_llm_logs', 'prompt_sha1')) {
                $table->dropColumn('prompt_sha1');
            }
            if (Schema::hasColumn('bot_llm_logs', 'retention_days')) {
                $table->dropColumn('retention_days');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('bot_llm_logs')) {
            return;
        }

        Schema::table('bot_llm_logs', function (Blueprint $table): void {
            // Восстанавливаем legacy-поля для безопасного rollback.
            if (! Schema::hasColumn('bot_llm_logs', 'assistant_content')) {
                $table->longText('assistant_content')->nullable()->after('response_json');
            }
            if (! Schema::hasColumn('bot_llm_logs', 'parsed_json')) {
                $table->json('parsed_json')->nullable()->after('assistant_content');
            }
            if (! Schema::hasColumn('bot_llm_logs', 'error_text')) {
                $table->text('error_text')->nullable()->after('parsed_json');
            }
            if (! Schema::hasColumn('bot_llm_logs', 'prompt_preview')) {
                $table->text('prompt_preview')->nullable()->after('error_text');
            }
            if (! Schema::hasColumn('bot_llm_logs', 'prompt_sha1')) {
                $table->string('prompt_sha1', 40)->nullable()->after('prompt_preview');
            }
            if (! Schema::hasColumn('bot_llm_logs', 'retention_days')) {
                $table->unsignedInteger('retention_days')->nullable()->after('prompt_sha1');
            }
        });
    }
};
