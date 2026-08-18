<?php

namespace Tests\Unit;

use App\Models\InstrumentCandle;
use App\Services\CandleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CandleServiceSeriesLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_s5_fetch_limit_scales_with_max_bars(): void
    {
        $svc = app(CandleService::class);

        // 120 S30 = 6 S5 на бар + запас, далеко не 15000.
        $this->assertSame(960, $svc->s5FetchLimit(30, 120));
        $this->assertSame(15000, $svc->s5FetchLimit(30, null));
        $this->assertSame(15000, $svc->s5FetchLimit(60, 5000));
    }

    public function test_s30_series_does_not_select_15000_s5_rows(): void
    {
        $instrument = 'EURUSD_otc';
        $base = (int) (floor(time() / 5) * 5) - (5 * 400);
        $now = now();
        $rows = [];
        for ($i = 0; $i < 400; $i++) {
            $rows[] = [
                'instrument' => $instrument,
                'timeframe' => CandleService::TF_S5,
                'time' => $base + ($i * 5),
                'open' => 1.0,
                'high' => 1.1,
                'low' => 0.9,
                'close' => 1.0,
                'volume' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        InstrumentCandle::query()->insert($rows);

        DB::enableQueryLog();
        $series = app(CandleService::class)->seriesForDashboard(
            $instrument,
            CandleService::TF_S30,
            null,
            null,
            20,
        );
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(20, $series);

        $candleQuery = collect($log)->first(
            fn (array $q): bool => str_contains((string) $q['query'], 'from "candles"')
                || str_contains((string) $q['query'], 'from `candles`')
        );
        $this->assertNotNull($candleQuery);
        $expectedLimit = app(CandleService::class)->s5FetchLimit(30, 20);
        $sql = strtolower((string) $candleQuery['query']);
        $bindings = array_map('strval', $candleQuery['bindings']);
        $this->assertTrue(
            str_contains($sql, 'limit '.$expectedLimit)
            || in_array((string) $expectedLimit, $bindings, true),
            'S5 query should use scaled LIMIT, got SQL='.$candleQuery['query'].' bindings='.json_encode($candleQuery['bindings']),
        );
        $this->assertFalse(str_contains($sql, 'limit 15000'));
    }

    public function test_series_bundle_loads_s5_once_for_m1_and_mtf(): void
    {
        $instrument = 'EURUSD_otc';
        $base = (int) (floor(time() / 5) * 5) - (5 * 400);
        $now = now();
        $rows = [];
        for ($i = 0; $i < 400; $i++) {
            $rows[] = [
                'instrument' => $instrument,
                'timeframe' => CandleService::TF_S5,
                'time' => $base + ($i * 5),
                'open' => 1.0,
                'high' => 1.1,
                'low' => 0.9,
                'close' => 1.0,
                'volume' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        InstrumentCandle::query()->insert($rows);

        DB::enableQueryLog();
        $bundle = app(CandleService::class)->seriesBundle($instrument, [
            1 => 20,
            5 => 10,
            15 => 8,
            60 => 6,
        ]);
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $candleQueries = collect($log)->filter(
            fn (array $q): bool => str_contains((string) $q['query'], 'from "candles"')
                || str_contains((string) $q['query'], 'from `candles`')
        );
        $this->assertCount(1, $candleQueries);
        $this->assertCount(20, $bundle['1'] ?? []);
        $this->assertNotEmpty($bundle['5'] ?? []);
        $this->assertNotEmpty($bundle['15'] ?? []);
        $this->assertArrayHasKey('60', $bundle);
    }
}
