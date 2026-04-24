<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proxy_endpoints', function (Blueprint $table) {
            $table->id();
            $table->string('url')->unique();
            $table->string('status', 32)->default('active')->index();
            $table->unsignedInteger('failure_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('last_failed_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('cooldown_until')->nullable();
            $table->timestamps();
        });

        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 191)->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::table('pocket_instruments', function (Blueprint $table) {
            $table->foreignId('proxy_endpoint_id')
                ->nullable()
                ->after('uid')
                ->constrained('proxy_endpoints')
                ->nullOnDelete();
        });

        // Миграция данных со старого поля proxy_url в новый пул proxy_endpoints.
        // Делается пакетно SQL-запросами, чтобы безопасно пройти большой объём инструментов.
        $rows = DB::table('pocket_instruments')
            ->select('id', 'proxy_url')
            ->whereNotNull('proxy_url')
            ->where('proxy_url', '!=', '')
            ->get();

        $endpointIdByUrl = [];
        foreach ($rows as $row) {
            $url = (string) $row->proxy_url;
            if (! isset($endpointIdByUrl[$url])) {
                $existingId = DB::table('proxy_endpoints')->where('url', $url)->value('id');
                if (! $existingId) {
                    $existingId = DB::table('proxy_endpoints')->insertGetId([
                        'url' => $url,
                        'status' => 'active',
                        'failure_count' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
                $endpointIdByUrl[$url] = $existingId;
            }

            DB::table('pocket_instruments')
                ->where('id', $row->id)
                ->update(['proxy_endpoint_id' => $endpointIdByUrl[$url]]);
        }

        DB::table('app_settings')->updateOrInsert(
            ['key' => 'proxy_auto_rotation_enabled'],
            ['value' => '0', 'created_at' => now(), 'updated_at' => now()]
        );

        Schema::table('pocket_instruments', function (Blueprint $table) {
            $table->dropColumn('proxy_url');
        });
    }

    public function down(): void
    {
        Schema::table('pocket_instruments', function (Blueprint $table) {
            $table->string('proxy_url')->nullable()->after('uid');
        });

        // Возвращаем URL обратно в pocket_instruments для обратной совместимости отката.
        $rows = DB::table('pocket_instruments')
            ->leftJoin('proxy_endpoints', 'proxy_endpoints.id', '=', 'pocket_instruments.proxy_endpoint_id')
            ->select('pocket_instruments.id as instrument_id', 'proxy_endpoints.url as proxy_url')
            ->whereNotNull('pocket_instruments.proxy_endpoint_id')
            ->get();

        foreach ($rows as $row) {
            DB::table('pocket_instruments')
                ->where('id', $row->instrument_id)
                ->update(['proxy_url' => $row->proxy_url]);
        }

        Schema::table('pocket_instruments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('proxy_endpoint_id');
        });

        Schema::dropIfExists('app_settings');
        Schema::dropIfExists('proxy_endpoints');
    }
};

