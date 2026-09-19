<?php

namespace Tests\Unit;

use App\Models\InstrumentCandle;
use App\Models\PocketInstrument;
use App\Services\CandleRangeCache;
use App\Services\CandleService;
use App\Services\DataCleanupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CandleRangeCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_rebuild_uses_per_instrument_s5_edges(): void
    {
        Cache::flush();
        PocketInstrument::query()->create([
            'instrument' => 'EURUSD_otc',
            'display_name' => 'EURUSD',
            'is_active' => true,
        ]);
        PocketInstrument::query()->create([
            'instrument' => 'GBPUSD_otc',
            'display_name' => 'GBPUSD',
            'is_active' => true,
        ]);

        $this->insertS5('EURUSD_otc', 1_700_000_000);
        $this->insertS5('EURUSD_otc', 1_700_000_100);
        $this->insertS5('GBPUSD_otc', 1_699_000_000);
        $this->insertS5('GBPUSD_otc', 1_700_000_050);
        // M1 не должен влиять на S5-диапазон бэктеста.
        InstrumentCandle::query()->create([
            'instrument' => 'EURUSD_otc',
            'timeframe' => 1,
            'time' => 1_600_000_000,
            'open' => 1, 'high' => 1, 'low' => 1, 'close' => 1, 'volume' => 0,
        ]);

        $range = app(CandleRangeCache::class)->rebuild();

        $this->assertSame(1_699_000_000, $range['min_ts']);
        $this->assertSame(1_700_000_100, $range['max_ts']);
        $this->assertSame($range, app(CandleRangeCache::class)->s5Range());
    }

    public function test_expand_s5_is_noop_until_cache_is_warm(): void
    {
        Cache::flush();
        app(CandleRangeCache::class)->expandS5(1_800_000_000, 1_800_000_005);
        $this->assertNull(app(CandleRangeCache::class)->read());
    }

    public function test_expand_s5_widens_warm_cache(): void
    {
        Cache::flush();
        app(CandleRangeCache::class)->rebuild(); // пустая таблица → min/max null, ключ есть
        app(CandleRangeCache::class)->expandS5(1_800_000_000, 1_800_000_010);
        app(CandleRangeCache::class)->expandS5(1_800_000_020, 1_800_000_020);

        $range = app(CandleRangeCache::class)->s5Range();
        $this->assertSame(1_800_000_000, $range['min_ts']);
        $this->assertSame(1_800_000_020, $range['max_ts']);
    }

    public function test_cleanup_rebuilds_min_after_deleting_old_s5(): void
    {
        Cache::flush();
        PocketInstrument::query()->create([
            'instrument' => 'EURUSD_otc',
            'display_name' => 'EURUSD',
            'is_active' => true,
        ]);
        $old = now()->subHours(48)->getTimestamp();
        $fresh = now()->subMinutes(10)->getTimestamp();
        $this->insertS5('EURUSD_otc', $old);
        $this->insertS5('EURUSD_otc', $fresh);
        app(CandleRangeCache::class)->rebuild();
        $this->assertSame($old, app(CandleRangeCache::class)->s5Range()['min_ts']);

        (new DataCleanupService)->purgeCandlesOlderThan(24);

        $range = app(CandleRangeCache::class)->s5Range();
        $this->assertSame($fresh, $range['min_ts']);
        $this->assertSame($fresh, $range['max_ts']);
        $this->assertSame(1, InstrumentCandle::query()->count());
    }

    public function test_storage_stats_use_s5_cache_and_exact_count_on_sqlite(): void
    {
        Cache::flush();
        PocketInstrument::query()->create([
            'instrument' => 'EURUSD_otc',
            'display_name' => 'EURUSD',
            'is_active' => true,
        ]);
        $this->insertS5('EURUSD_otc', 1_700_000_000);
        $this->insertS5('EURUSD_otc', 1_700_003_600);

        $stats = (new DataCleanupService)->getCandlesStorageStats();

        $this->assertSame(1_700_000_000, $stats['oldest_at']);
        $this->assertSame(1_700_003_600, $stats['newest_at']);
        $this->assertSame(2, $stats['count']);
        $this->assertFalse($stats['count_is_approximate']);
        $this->assertSame(1.0, $stats['span_hours']);
    }

    private function insertS5(string $instrument, int $time): void
    {
        InstrumentCandle::query()->create([
            'instrument' => $instrument,
            'timeframe' => CandleService::TF_S5,
            'time' => $time,
            'open' => 1, 'high' => 1, 'low' => 1, 'close' => 1, 'volume' => 0,
        ]);
    }
}
