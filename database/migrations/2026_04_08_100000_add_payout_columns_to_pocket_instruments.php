<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pocket_instruments', function (Blueprint $table) {
            $table->decimal('current_payout', 5, 2)->nullable()->after('proxy_url');
            $table->timestamp('last_payout_at')->nullable()->after('current_payout');
            $table->string('payout_source', 32)->nullable()->after('last_payout_at');
            $table->index('last_payout_at');
        });
    }

    public function down(): void
    {
        Schema::table('pocket_instruments', function (Blueprint $table) {
            $table->dropIndex(['last_payout_at']);
            $table->dropColumn(['current_payout', 'last_payout_at', 'payout_source']);
        });
    }
};
