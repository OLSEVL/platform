<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Тип инструмента в pocket_instruments:
 * 1 — валютные пары (всё, что было до крипто),
 * 2 — криптовалюты (добавленные OTC/spot crypto).
 */
return new class extends Migration
{
    /** Символы крипто, которые уже заведены в БД (OTC + закрытые spot). */
    private const CRYPTO_INSTRUMENTS = [
        'ADA-USD_otc',
        'BNB-USD_otc',
        'BTCUSD_otc',
        'MATIC_otc',
        'SOL-USD_otc',
        'TON-USD_otc',
        'TRX-USD_otc',
        'LINK_otc',
        'LTCUSD_otc',
        'AVAX_otc',
        'BITB_otc',
        'DOGE_otc',
        'ETHUSD_otc',
        'DOTUSD_otc',
        'ETHUSD',
        'DASH_USD',
        'BCHEUR',
        'BCHGBP',
        'BCHJPY',
        'BTCGBP',
        'BTCJPY',
        'BTCUSD',
        'LNKUSD',
    ];

    public function up(): void
    {
        Schema::table('pocket_instruments', function (Blueprint $table) {
            // tinyInteger: 1=валютные пары, 2=криптовалюты; default 1 для старых записей.
            $table->unsignedTinyInteger('type')
                ->default(1)
                ->after('display_name')
                ->index();
        });

        // Все текущие строки считаем валютными (type=1), затем помечаем известные crypto.
        DB::table('pocket_instruments')->update(['type' => 1]);

        DB::table('pocket_instruments')
            ->whereIn('instrument', self::CRYPTO_INSTRUMENTS)
            ->update(['type' => 2]);
    }

    public function down(): void
    {
        Schema::table('pocket_instruments', function (Blueprint $table) {
            $table->dropIndex(['type']);
            $table->dropColumn('type');
        });
    }
};
