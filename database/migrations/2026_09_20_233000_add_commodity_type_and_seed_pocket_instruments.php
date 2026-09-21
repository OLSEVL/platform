<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Тип 4 — сырьевые товары (commodities) в pocket_instruments.
 *
 * Список совпадает с блоком «Сырьевые товары» в Pocket Option:
 * OTC (торгуются сейчас) — is_active=true;
 * spot / закрытый рынок (N/A) — is_active=false.
 */
return new class extends Migration
{
    /**
     * Символ => [display_name, is_active].
     * Приоритет у всех 1 — как у акций/крипто после первичного заведения.
     */
    private const COMMODITIES = [
        // OTC (активные в UI брокера)
        'UKBrent_otc' => ['Brent Oil OTC', true],
        'USCrude_otc' => ['WTI Crude Oil OTC', true],
        'XAGUSD_otc' => ['Silver OTC', true],
        'XAUUSD_otc' => ['Gold OTC', true],
        'XNGUSD_otc' => ['Natural Gas OTC', true],
        'XPDUSD_otc' => ['Palladium spot OTC', true],
        'XPTUSD_otc' => ['Platinum spot OTC', true],
        // Spot / пары с EUR (рынок закрыт → N/A)
        'UKBrent' => ['Brent Oil', false],
        'USCrude' => ['WTI Crude Oil', false],
        'XAGEUR' => ['XAG/EUR', false],
        'XAGUSD' => ['Silver', false],
        'XAUEUR' => ['XAU/EUR', false],
        'XAUUSD' => ['Gold', false],
        'XNGUSD' => ['Natural Gas', false],
        'XPDUSD' => ['Palladium spot', false],
        'XPTUSD' => ['Platinum spot', false],
    ];

    public function up(): void
    {
        $now = now();
        $typeCommodity = 4;

        // Без session_token ingest_stream пропускает пару («skip … no session_token»).
        // Берём тот же SSID/uid, что уже крутят остальные активные инструменты.
        $donor = DB::table('pocket_instruments')
            ->where('is_active', true)
            ->whereNotNull('session_token')
            ->where('session_token', '!=', '')
            ->orderByDesc('priority')
            ->orderBy('instrument')
            ->first(['session_token', 'uid', 'is_demo']);
        $donorToken = $donor->session_token ?? null;
        $donorUid = $donor->uid ?? null;
        $donorIsDemo = (bool) ($donor->is_demo ?? true);

        foreach (self::COMMODITIES as $instrument => [$displayName, $isActive]) {
            $existing = DB::table('pocket_instruments')
                ->where('instrument', $instrument)
                ->first();

            if ($existing) {
                // Уже есть (мог лежать как валюта type=1) — только помечаем типом «сырьё».
                // Auth не затираем, если у записи уже свой токен.
                $patch = [
                    'type' => $typeCommodity,
                    'display_name' => $existing->display_name ?: $displayName,
                    'updated_at' => $now,
                ];
                if ($isActive && $donorToken && empty($existing->session_token)) {
                    $patch['session_token'] = $donorToken;
                    $patch['uid'] = $donorUid;
                    $patch['is_demo'] = $donorIsDemo;
                }
                DB::table('pocket_instruments')
                    ->where('id', $existing->id)
                    ->update($patch);

                continue;
            }

            DB::table('pocket_instruments')->insert([
                'instrument' => $instrument,
                'display_name' => $displayName,
                'type' => $typeCommodity,
                'priority' => 1,
                // Auth только для активных OTC — spot остаются выключенными без стрима.
                'session_token' => $isActive ? $donorToken : null,
                'uid' => $isActive ? $donorUid : null,
                'is_demo' => $donorIsDemo,
                'is_active' => $isActive,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Удаляем только те строки, которые эта миграция могла создать с type=4.
        // Существующие до миграции записи не трогаем (type просто оставляем).
        DB::table('pocket_instruments')
            ->where('type', 4)
            ->whereIn('instrument', array_keys(self::COMMODITIES))
            ->delete();
    }
};
