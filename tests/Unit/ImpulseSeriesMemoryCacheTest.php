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
}
