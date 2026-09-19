<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\InstrumentCandle;
use App\Models\PocketInstrument;
use App\Models\User;
use App\Services\CandleRangeCache;
use App\Services\CandleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class BotBacktestIndexCandleRangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_cached_s5_range_not_table_scan_payload(): void
    {
        Cache::flush();
        $user = User::factory()->create();
        $bot = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'BT',
            'type' => 'strong_level',
            'is_active' => true,
            'config' => [],
        ]);
        PocketInstrument::query()->create([
            'instrument' => 'EURUSD_otc',
            'display_name' => 'EURUSD',
            'is_active' => true,
        ]);
        InstrumentCandle::query()->create([
            'instrument' => 'EURUSD_otc',
            'timeframe' => CandleService::TF_S5,
            'time' => 1_777_000_000,
            'open' => 1, 'high' => 1, 'low' => 1, 'close' => 1, 'volume' => 0,
        ]);
        InstrumentCandle::query()->create([
            'instrument' => 'EURUSD_otc',
            'timeframe' => CandleService::TF_S5,
            'time' => 1_777_000_500,
            'open' => 1, 'high' => 1, 'low' => 1, 'close' => 1, 'volume' => 0,
        ]);

        $this->actingAs($user)
            ->getJson(route('bots.backtests.index', $bot))
            ->assertOk()
            ->assertJsonPath('candle_range.min_ts', 1_777_000_000)
            ->assertJsonPath('candle_range.max_ts', 1_777_000_500)
            ->assertJsonPath('runs', []);

        $this->assertNotNull(app(CandleRangeCache::class)->read());
    }
}
