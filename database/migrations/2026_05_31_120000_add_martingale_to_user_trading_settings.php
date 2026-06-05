<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_trading_settings', function (Blueprint $table) {
            $table->json('order_amounts')->nullable()->after('order_amount');
            $table->unsignedSmallInteger('martingale_step')->default(0)->after('order_amounts');
        });

        if (Schema::hasTable('user_trading_settings')) {
            foreach (DB::table('user_trading_settings')->orderBy('id')->get() as $row) {
                $amount = (float) ($row->order_amount ?? 1);
                if ($amount <= 0) {
                    $amount = 1.0;
                }
                DB::table('user_trading_settings')->where('id', $row->id)->update([
                    'order_amounts' => json_encode([$amount]),
                    'martingale_step' => 0,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('user_trading_settings', function (Blueprint $table) {
            $table->dropColumn(['order_amounts', 'martingale_step']);
        });
    }
};
