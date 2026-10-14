<?php

namespace Tests\Unit;

use App\Services\Bots\ImpulseCandleSeriesCache;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class ImpulseCandleSeriesCacheTest extends TestCase
{
    private ImpulseCandleSeriesCache $cache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cache = new ImpulseCandleSeriesCache;
        try {
            $keys = Redis::keys('impulse:series:*');
            if (is_array($keys) && $keys !== []) {
                Redis::del($keys);
            }
        } catch (\Throwable) {
            $this->markTestSkipped('Redis unavailable');
        }
    }

    public function test_second_resolve_is_cache_hit_without_loader(): void
    {
        $loads = 0;
        $loader = function () use (&$loads): array {
            $loads++;

            return [
                'closed' => [[
                    'time' => 1_700_000_000,
                    'open' => 1.0,
                    'high' => 1.1,
                    'low' => 0.9,
                    'close' => 1.05,
                    'volume' => 1.0,
                    'is_partial' => false,
                ]],
                'partial' => null,
                'series' => [],
                'mtf' => ['5' => []],
            ];
        };

        $now = 1_700_000_030;
        $partial = [
            'time' => 1_700_000_060,
            'open' => 1.05,
            'high' => 1.08,
            'low' => 1.04,
            'close' => 1.07,
            'volume' => 0.5,
        ];

        $a = $this->cache->resolve('EURUSD_otc', 100, [5 => 40], $loader, $partial, 60, $now);
        $b = $this->cache->resolve('EURUSD_otc', 100, [5 => 40], $loader, $partial, 60, $now + 5);

        $this->assertFalse($a['cache_hit']);
        $this->assertTrue($b['cache_hit']);
        $this->assertSame(1, $loads);
        $this->assertSame(1.08, (float) ($b['partial']['high'] ?? 0));
    }

    public function test_promote_partial_to_closed_on_rollover_without_loader(): void
    {
        $loads = 0;
        $loader = function () use (&$loads): array {
            $loads++;

            return [
                'closed' => [[
                    'time' => 1_700_000_000,
                    'open' => 1.0,
                    'high' => 1.1,
                    'low' => 0.9,
                    'close' => 1.05,
                    'volume' => 1.0,
                    'is_partial' => false,
                ]],
                'partial' => null,
                'series' => [],
                'mtf' => ['5' => []],
            ];
        };

        $t0 = 1_700_000_060;
        $partial1 = [
            'time' => $t0,
            'open' => 1.05,
            'high' => 1.2,
            'low' => 1.0,
            'close' => 1.1,
            'volume' => 1.0,
        ];
        // Загрузка внутри открытого бакета.
        $this->cache->resolve('GBPUSD_otc', 100, [5 => 40], $loader, $partial1, 60, $t0 + 10);

        $partial2 = [
            'time' => $t0 + 60,
            'open' => 1.1,
            'high' => 1.11,
            'low' => 1.09,
            'close' => 1.105,
            'volume' => 0.2,
        ];
        // Ролловер: предыдущий partial закрыт → promote без второго loader.
        $after = $this->cache->resolve('GBPUSD_otc', 100, [5 => 40], $loader, $partial2, 60, $t0 + 65);

        $this->assertSame(1, $loads);
        $this->assertTrue($after['cache_hit']);
        $this->assertTrue($after['promoted']);
        $this->assertSame($t0, (int) end($after['closed'])['time']);
        $this->assertSame(1.2, (float) end($after['closed'])['high']);
    }
}
