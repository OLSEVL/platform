<?php

namespace Tests\Unit;

use App\Services\Bots\ImpulseSeriesMemoryCache;
use App\Services\CandleService;
use Tests\TestCase;

class ImpulseSeriesMemoryCacheTest extends TestCase
{
    public function test_hit_reuses_closed_and_applies_partial(): void
    {
        $cache = new ImpulseSeriesMemoryCache;
        $loads = 0;
        $loader = function () use (&$loads): array {
            $loads++;

            return [
                'closed' => [
                    ['time' => 1000, 'open' => 1.0, 'high' => 1.1, 'low' => 0.9, 'close' => 1.05],
                ],
                'partial' => null,
                'series' => [],
                'mtf' => ['m5' => []],
            ];
        };

        $a = $cache->resolve('EURUSD_otc', $loader, [
            'time' => 1060, 'open' => 1.05, 'high' => 1.06, 'low' => 1.04, 'close' => 1.055,
        ], 60, 1070, 100);
        $this->assertFalse($a['cache_hit']);
        $this->assertSame(1, $loads);
        $this->assertNotNull($a['partial']);

        $b = $cache->resolve('EURUSD_otc', $loader, [
            'time' => 1060, 'open' => 1.05, 'high' => 1.07, 'low' => 1.04, 'close' => 1.06,
        ], 60, 1080, 100);
        $this->assertTrue($b['cache_hit']);
        $this->assertSame(1, $loads);
        $this->assertSame(1.07, (float) $b['partial']['high']);
    }

    public function test_s15_dashboard_tf_maps_to_15_seconds_not_4015(): void
    {
        // Регрессия: ImpulseLiveScanService раньше делал max(5, 4015)=4015.
        $this->assertSame(15, CandleService::periodSecondsForDashboardTf(CandleService::TF_S15));
        $this->assertSame(30, CandleService::periodSecondsForDashboardTf(CandleService::TF_S30));
        $this->assertSame(5, CandleService::periodSecondsForDashboardTf(CandleService::TF_S5));
        $this->assertSame(60, CandleService::periodSecondsForDashboardTf(1));
    }

    public function test_s15_reloads_when_next_closed_bucket_is_due_without_partial(): void
    {
        $cache = new ImpulseSeriesMemoryCache;
        $loads = 0;
        $loader = function () use (&$loads): array {
            $loads++;

            return [
                'closed' => [
                    ['time' => 1000, 'open' => 1.0, 'high' => 1.1, 'low' => 0.9, 'close' => 1.05],
                ],
                'partial' => null,
                'series' => [],
                'mtf' => [],
            ];
        };

        // last_closed=1000, period=15 → новый closed только при now >= 1030.
        $cache->resolve('EURUSD_otc', $loader, null, 15, 1010, 100);
        $this->assertSame(1, $loads);

        $mid = $cache->resolve('EURUSD_otc', $loader, null, 15, 1025, 100);
        $this->assertTrue($mid['cache_hit']);
        $this->assertSame(1, $loads);

        $due = $cache->resolve('EURUSD_otc', $loader, null, 15, 1030, 100);
        $this->assertFalse($due['cache_hit']);
        $this->assertSame(2, $loads);
    }

    public function test_s15_folded_partial_merges_across_polls_without_reload(): void
    {
        $cache = new ImpulseSeriesMemoryCache;
        $loads = 0;
        $loader = function () use (&$loads): array {
            $loads++;

            return [
                'closed' => [
                    ['time' => 990, 'open' => 1.0, 'high' => 1.1, 'low' => 0.9, 'close' => 1.05],
                ],
                'partial' => null,
                'series' => [],
                'mtf' => [],
            ];
        };

        $first = \App\Support\ImpulseLvl1minLiveWick::foldS5IntoBarPeriod([
            'time' => 1005, 'open' => 1.05, 'high' => 1.06, 'low' => 1.04, 'close' => 1.055,
        ], 15, $cache->lastPartial('EURUSD_otc'));
        $a = $cache->resolve('EURUSD_otc', $loader, $first, 15, 1008, 100);
        $this->assertFalse($a['cache_hit']);
        $this->assertSame(1005, (int) $a['partial']['time']);

        $second = \App\Support\ImpulseLvl1minLiveWick::foldS5IntoBarPeriod([
            'time' => 1010, 'open' => 1.055, 'high' => 1.08, 'low' => 1.03, 'close' => 1.07,
        ], 15, $cache->lastPartial('EURUSD_otc'));
        $b = $cache->resolve('EURUSD_otc', $loader, $second, 15, 1012, 100);
        $this->assertTrue($b['cache_hit']);
        $this->assertSame(1, $loads);
        $this->assertEqualsWithDelta(1.05, (float) $b['partial']['open'], 1e-12);
        $this->assertEqualsWithDelta(1.08, (float) $b['partial']['high'], 1e-12);
        $this->assertEqualsWithDelta(1.03, (float) $b['partial']['low'], 1e-12);
    }
}
