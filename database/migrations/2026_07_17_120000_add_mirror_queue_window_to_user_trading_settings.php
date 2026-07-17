<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_trading_settings', function (Blueprint $table) {
            // Окно и лимит таблицы /profile/mirror-queue (настраивается на самой странице).
            $table->unsignedTinyInteger('mirror_queue_hours')->default(6)->after('mirror_wait_previous_settled');
            $table->unsignedSmallInteger('mirror_queue_limit')->default(80)->after('mirror_queue_hours');
        });
    }

    public function down(): void
    {
        Schema::table('user_trading_settings', function (Blueprint $table) {
            $table->dropColumn(['mirror_queue_hours', 'mirror_queue_limit']);
        });
    }
};
