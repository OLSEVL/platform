<?php

namespace Tests\Unit;

use App\Models\InstrumentCandle;
use App\Services\CandleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Регрессия нагрузки на догрузку истории (loadMore).
 *
 * Лёгкий фикс сдвига viewport — только фронт. Бэкенд должен остаться как до правок:
 * один SELECT по candles, LIMIT ≤ hardCap 15000, без чанков/prefetch.
 */
class CandleServiceLoadMoreLoadRegressionTest extends TestCase
{
    use RefreshDatabase;

    /** Базовый hardCap до «тяжёлых» экспериментов с историей. */
    private const BASELINE_HARD_CAP = 15000;

    /**
     * @return list<array{instrument:string,timeframe:int,time:int,open:float,high:float,low:float,close:float,volume:int,created_at:\Illuminate\Support\Carbon,updated_at:\Illuminate\Support\Carbon}>
     */
    private function seedS5(string $instrument, int $count, int $endUnix): array
    {
        $now = now();
        $start = (int) (floor($endUnix / 5) * 5) - (($count - 1) * 5);
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'instrument' => $instrument,
                'timeframe' => CandleService::TF_S5,
                'time' => $start + ($i * 5),
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

        return $rows;
    }

    /**
     * @param  list<array{query:string,bindings:array<int,mixed>}>  $log
     * @return list<array{query:string,bindings:array<int,mixed>}>
     */
    private function candleQueries(array $log): array
    {
        return collect($log)
            ->filter(
                fn (array $q): bool => str_contains((string) $q['query'], 'from "candles"')
                    || str_contains((string) $q['query'], 'from `candles`')
            )
            ->values()
            ->all();
    }

    /** @param  array{query:string,bindings:array<int,mixed>}  $query */
    private function extractLimit(array $query): ?int
    {
        $sql = strtolower((string) $query['query']);
        if (preg_match('/\blimit\s+(\d+)\b/', $sql, $m)) {
            return (int) $m[1];
        }
        foreach (array_reverse($query['bindings']) as $binding) {
            if (is_int($binding) || (is_string($binding) && ctype_digit($binding))) {
                $n = (int) $binding;
                if ($n >= 120 && $n <= self::BASELINE_HARD_CAP) {
                    return $n;
                }
            }
        }

        return null;
    }

    public function test_baseline_s5_hard_cap_unchanged(): void
    {
        $svc = app(CandleService::class);

        $this->assertSame(self::BASELINE_HARD_CAP, $svc->s5FetchLimit(60, null));
        $this->assertSame(self::BASELINE_HARD_CAP, $svc->s5FetchLimit(60, 5000));
        // loadMore M1 limit=1440 → 1440*12+240=17520, но режется hardCap 15000 (как до правок).
        $this->assertSame(self::BASELINE_HARD_CAP, $svc->s5FetchLimit(60, 1440));
        $this->assertSame(960, $svc->s5FetchLimit(30, 120));
    }

    public function test_load_more_m1_uses_single_capped_query(): void
    {
        $instrument = 'EURUSD_otc';
        $end = time();
        $this->seedS5($instrument, 3000, $end);

        $before = $end - 3600;
        $since = $end - 86400;
        $svc = app(CandleService::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $peakBefore = memory_get_peak_usage(true);
        $t0 = hrtime(true);
        $series = $svc->seriesForDashboard($instrument, 1, $since, $before, 1440);
        $elapsedMs = (hrtime(true) - $t0) / 1e6;
        $log = DB::getQueryLog();
        DB::disableQueryLog();
        $peakAfter = memory_get_peak_usage(true);

        $candleQs = $this->candleQueries($log);
        $this->assertCount(1, $candleQs, 'loadMore M1 must hit candles exactly once (no chunked loop)');
        $limit = $this->extractLimit($candleQs[0]);
        $this->assertNotNull($limit);
        $this->assertLessThanOrEqual(self::BASELINE_HARD_CAP, $limit);
        $this->assertSame($svc->s5FetchLimit(60, 1440), $limit);
        $this->assertNotEmpty($series);
        // Sanity: лёгкий фикс не должен разгонять память/время на синтетике.
        $this->assertLessThan(50_000_000, $peakAfter - $peakBefore, 'unexpected memory delta on tiny fixture');
        $this->assertLessThan(2000, $elapsedMs, 'unexpectedly slow on tiny fixture');
    }

    public function test_load_more_h4_still_one_query_within_hard_cap(): void
    {
        $instrument = 'EURUSD_otc';
        $end = time();
        // Достаточно S5, чтобы упереться в hardCap при агрегации в H4.
        $this->seedS5($instrument, 16000, $end);

        $before = $end - 4 * 3600;
        $since = $end - 30 * 86400;
        $svc = app(CandleService::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $series = $svc->seriesForDashboard($instrument, 240, $since, $before, 1440);
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $candleQs = $this->candleQueries($log);
        // Как до тяжёлых правок: один fetchRecentS5, не десятки чанков.
        $this->assertCount(1, $candleQs, 'H4 loadMore must not use chunked multi-query path');
        $limit = $this->extractLimit($candleQs[0]);
        $this->assertNotNull($limit);
        $this->assertSame(self::BASELINE_HARD_CAP, $limit);
        $this->assertNotEmpty($series);
        // При hardCap 15k S5 H4 даёт порядка единиц–десятков баров, не сотни тысяч.
        $this->assertLessThanOrEqual(100, count($series));
    }

    public function test_initial_and_more_do_not_multiply_queries(): void
    {
        $instrument = 'EURUSD_otc';
        $end = time();
        $this->seedS5($instrument, 5000, $end);
        $svc = app(CandleService::class);
        $since = $end - 86400;

        DB::flushQueryLog();
        DB::enableQueryLog();
        $initial = $svc->seriesForDashboard($instrument, 1, $since, null, 140);
        $before = $initial[0]['time'] ?? $end;
        $more = $svc->seriesForDashboard($instrument, 1, $since, $before, 1440);
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $candleQs = $this->candleQueries($log);
        // initial + more = ровно 2 запроса (как два отдельных HTTP /candles), без prefetch-пачек.
        $this->assertCount(2, $candleQs);
        foreach ($candleQs as $q) {
            $limit = $this->extractLimit($q);
            $this->assertNotNull($limit);
            $this->assertLessThanOrEqual(self::BASELINE_HARD_CAP, $limit);
        }
        $this->assertNotEmpty($initial);
        $this->assertIsArray($more);
    }
}
