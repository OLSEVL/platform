<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pocket_instruments', function (Blueprint $table) {
            $table->timestamp('last_ws_connected_at')->nullable()->after('payout_source');
            $table->string('last_ws_error', 1000)->nullable()->after('last_ws_connected_at');
            $table->timestamp('last_ws_error_at')->nullable()->after('last_ws_error');
            $table->index('last_ws_connected_at');
        });
    }

    public function down(): void
    {
        Schema::table('pocket_instruments', function (Blueprint $table) {
            $table->dropIndex(['last_ws_connected_at']);
            $table->dropColumn(['last_ws_connected_at', 'last_ws_error', 'last_ws_error_at']);
        });
    }
};
