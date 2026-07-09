<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX_NAME = 'bot_llm_logs_created_at_id_index';

    public function up(): void
    {
        if (! Schema::hasTable('bot_llm_logs')) {
            return;
        }

        // Индекс под глобальную очистку:
        // DELETE FROM bot_llm_logs WHERE created_at < ...
        // created_at первым полем помогает range-скану по времени,
        // id вторым — стабильному проходу/батчингу по PK без filesort.
        if (! $this->indexExists('bot_llm_logs', self::INDEX_NAME)) {
            Schema::table('bot_llm_logs', function (Blueprint $table): void {
                $table->index(['created_at', 'id'], self::INDEX_NAME);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('bot_llm_logs')) {
            return;
        }

        if ($this->indexExists('bot_llm_logs', self::INDEX_NAME)) {
            Schema::table('bot_llm_logs', function (Blueprint $table): void {
                $table->dropIndex(self::INDEX_NAME);
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $dbName = (string) DB::getDatabaseName();

        $count = DB::table('information_schema.statistics')
            ->where('table_schema', $dbName)
            ->where('table_name', $table)
            ->where('index_name', $indexName)
            ->count();

        return $count > 0;
    }
};
