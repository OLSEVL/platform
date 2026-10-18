<?php

namespace App\Services\Bots;

use App\Models\Bot;
use App\Models\PocketInstrument;
use App\Services\CandleService;
use App\Support\ArtisanProcessFinder;
use App\Support\BotInstrumentMarketScope;
use App\Support\BotScanCoordinator;
use App\Support\BotScanProgress;
use App\Support\AuthorFlowConfig;
use App\Support\EngulfingLiquidityConfig;
use App\Support\ImpulseLvl1minConfig;
use App\Support\ImpulseLvl1minLiveWick;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Live-скан Impulse LVL по модели updateBotM1_v1_04:
 *
 * Parent (poll): каждые scan_interval_sec MGET current_candle по ВСЕМ eligible
 *   → пишет snapshot в Redis (как history(1) на все пары). Не ждёт анализ.
 * Worker (analyze): читает latest snapshot; если gen новый — гоняет свой шард
 *   всех пар из снимка (не dirty-only). Устаревший gen пропускается.
 *
 * Итог: ритм poll ≈ 5 с как у оригинала; анализ не блокирует следующий poll.
 */
final class ImpulseLiveScanService
{
    private const SNAP_KEY_PREFIX = 'impulse:live:snap:';

    private ImpulseSeriesMemoryCache $seriesCache;

    public function __construct(
        private readonly BotEngineService $engine,
        private readonly BotDataService $botData,
        private readonly BotVirtualTradeSettlementService $virtualSettlement,
    ) {
        $this->seriesCache = new ImpulseSeriesMemoryCache;
    }

    /**
     * Parent: poll-цикл + spawn/watch воркеров (как SSID collector у 1min).
     *
     * @param  callable(): bool  $shouldStop
     * @return array{processed:int,signals:int,trades:int,cycles:int,cache_hits:int,db_loads:int}
     */
    public function runParent(Bot $bot, callable $shouldStop, ?callable $onTick = null): array
    {
        if (! ImpulseLvl1minConfig::is((string) $bot->type)
            && ! EngulfingLiquidityConfig::is((string) $bot->type)
            && ! AuthorFlowConfig::is((string) $bot->type)) {
            throw new \InvalidArgumentException(
                'ImpulseLiveScanService только для impulse_lvl_1min / impulse_lvl_s15 / engulfing_liquidity / author_flow'
            );
        }

        $totals = [
            'processed' => 0,
            'signals' => 0,
            'trades' => 0,
            'cycles' => 0,
            'cache_hits' => 0,
            'db_loads' => 0,
        ];

        $botId = (int) $bot->id;
        $config = is_array($bot->config) ? $bot->config : [];
        $interval = max(1, BotScanCoordinator::scanIntervalSecForBot($bot));
        $workers = $this->liveScanWorkers($bot, $config);
        $eligibleRefreshSec = 30;
        $settleEverySec = 15;
        $heartbeatEverySec = 10;
        $dbReconnectEverySec = 60;

        /** @var array<string, true> $eligibleSet */
        $eligibleSet = [];
        /** @var array<string, float|null> $payoutByInstrument */
        $payoutByInstrument = [];

        $lastEligibleAt = 0;
        $lastSettleAt = 0;
        $lastHeartbeatAt = 0;
        $lastDbReconnectAt = time();
        $gen = 0;

        BotScanProgress::beginShardedWave($botId, $workers);
        $processes = $this->spawnWorkers($botId, $workers);

        Log::info('impulse_live_scan.parent_start', [
            'bot_id' => $botId,
            'interval' => $interval,
            'workers' => $workers,
        ]);

        try {
            while (! $shouldStop()) {
                // Граница как у updateBotM1: sleep до следующего кратного interval.
                $now = time();
                $nextBoundary = (intdiv($now, $interval) + 1) * $interval;
                $sleepUs = (int) max(0, ($nextBoundary - microtime(true)) * 1_000_000);
                if ($sleepUs > 0) {
                    usleep(min($sleepUs, $interval * 1_000_000));
                }
                if ($shouldStop()) {
                    break;
                }

                $now = time();
                $requestStartedAt = microtime(true);

                if (($now - $lastDbReconnectAt) >= $dbReconnectEverySec) {
                    try {
                        DB::reconnect();
                    } catch (Throwable) {
                        // ignore
                    }
                    $lastDbReconnectAt = $now;
                }

                if (($now - $lastEligibleAt) >= $eligibleRefreshSec || $eligibleSet === []) {
                    $n = $this->refreshEligible($botId, $eligibleSet, $payoutByInstrument, $config);
                    $lastEligibleAt = $now;
                    if ($n < 0) {
                        break;
                    }
                    $workers = $this->liveScanWorkers($bot, $config);
                    $interval = max(1, (int) ($config['scan_interval_sec'] ?? $interval));
                }

                if (($now - $lastSettleAt) >= $settleEverySec) {
                    try {
                        $this->virtualSettlement->settleDueVirtualTrades($botId);
                    } catch (Throwable $e) {
                        Log::warning('impulse_live_scan.settle_failed', [
                            'bot_id' => $botId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                    $lastSettleAt = $now;
                }

                if (($now - $lastHeartbeatAt) >= $heartbeatEverySec) {
                    BotScanProgress::markStarted($botId);
                    $lastHeartbeatAt = $now;
                }

                $instruments = array_keys($eligibleSet);
                if ($instruments === []) {
                    continue;
                }

                // Poll: history(1) аналог — один MGET на все пары.
                $fetchStarted = hrtime(true);
                $partials = $this->mgetPartials($instruments);
                $fetchMs = (int) round((hrtime(true) - $fetchStarted) / 1e6);

                $gen++;
                $this->writeSnapshot($botId, [
                    'gen' => $gen,
                    'at' => $now,
                    'request_started_at' => $requestStartedAt,
                    'fetch_ms' => $fetchMs,
                    'instruments' => $instruments,
                    'payouts' => $payoutByInstrument,
                    'partials' => $partials,
                    'workers' => $workers,
                ]);

                $totals['cycles']++;
                $processes = $this->ensureWorkers($botId, $workers, $processes);

                if ($onTick !== null) {
                    $onTick($totals, count($instruments), $fetchMs, $gen);
                }
            }
        } finally {
            $this->stopWorkers($processes);
            BotScanProgress::endShardedWave($botId);
            BotScanProgress::markStarted($botId);
            $this->forgetSnapshot($botId);
            Log::info('impulse_live_scan.parent_stop', [
                'bot_id' => $botId,
                'totals' => $totals,
            ]);
        }

        return $totals;
    }

    /**
     * Worker: анализ шарда всех пар из latest snapshot (как process_worker_cycle).
     *
     * @param  callable(): bool  $shouldStop
     * @return array{processed:int,signals:int,trades:int,cycles:int,cache_hits:int,db_loads:int}
     */
    public function runWorker(
        Bot $bot,
        int $shardIndex,
        int $shardCount,
        callable $shouldStop,
        ?callable $onTick = null,
    ): array {
        $totals = [
            'processed' => 0,
            'signals' => 0,
            'trades' => 0,
            'cycles' => 0,
            'cache_hits' => 0,
            'db_loads' => 0,
        ];

        $botId = (int) $bot->id;
        $lastGen = 0;
        $idleSleepUs = 50_000;

        Log::info('impulse_live_scan.worker_start', [
            'bot_id' => $botId,
            'shard' => $shardIndex.'/'.$shardCount,
        ]);

        while (! $shouldStop()) {
            $snap = $this->readSnapshot($botId);
            if ($snap === null) {
                usleep($idleSleepUs);
                continue;
            }
            $gen = (int) ($snap['gen'] ?? 0);
            if ($gen <= 0 || $gen === $lastGen) {
                usleep($idleSleepUs);
                continue;
            }

            // Дренируем: берём только самый свежий gen (как queue maxsize=1 у 1min).
            $fresh = $this->readSnapshot($botId);
            if ($fresh !== null && (int) ($fresh['gen'] ?? 0) > $gen) {
                $snap = $fresh;
                $gen = (int) $snap['gen'];
            }

            $bot = Bot::query()->find($botId);
            if (! $bot instanceof Bot || ! $bot->is_active) {
                break;
            }
            $config = is_array($bot->config) ? $bot->config : [];
            if (! $this->continuousScanEnabled($bot, $config)) {
                break;
            }

            $instruments = is_array($snap['instruments'] ?? null) ? $snap['instruments'] : [];
            $payouts = is_array($snap['payouts'] ?? null) ? $snap['payouts'] : [];
            $partials = is_array($snap['partials'] ?? null) ? $snap['partials'] : [];
            $shardInstruments = BotScanCoordinator::takeShard($instruments, $shardIndex, $shardCount);

            // Новый gen → свой beginScan (total шарда), как worker cycle у 1min.
            BotScanProgress::beginScan($botId, count($shardInstruments), $shardIndex, $shardCount);

            $passSignals = 0;
            $passTrades = 0;
            foreach ($shardInstruments as $instrument) {
                if ($shouldStop()) {
                    break 2;
                }
                if (! is_string($instrument) || $instrument === '') {
                    continue;
                }
                $partial = is_array($partials[$instrument] ?? null) ? $partials[$instrument] : null;
                $payout = isset($payouts[$instrument]) && $payouts[$instrument] !== null
                    ? (float) $payouts[$instrument]
                    : null;

                BotScanProgress::setCurrentInstrument($botId, $instrument, $shardIndex, $shardCount);
                try {
                    $r = $this->evaluateOne($bot, $instrument, $payout, $partial, $config, $totals);
                    $passSignals += $r['signals'];
                    $passTrades += $r['trades'];
                    BotScanProgress::finishInstrument($botId, $instrument, $shardIndex, $shardCount);
                } catch (Throwable $e) {
                    Log::warning('impulse_live_scan.eval_failed', [
                        'bot_id' => $botId,
                        'instrument' => $instrument,
                        'shard' => $shardIndex,
                        'error' => $e->getMessage(),
                    ]);
                    $this->seriesCache->forget($instrument);
                    BotScanProgress::finishInstrument($botId, $instrument, $shardIndex, $shardCount);
                }
            }

            $lastGen = $gen;
            $totals['cycles']++;
            if ($onTick !== null) {
                $onTick($totals, count($shardInstruments), $passSignals, $passTrades);
            }
        }

        Log::info('impulse_live_scan.worker_stop', [
            'bot_id' => $botId,
            'shard' => $shardIndex.'/'.$shardCount,
            'totals' => $totals,
            'cache_size' => $this->seriesCache->size(),
        ]);

        return $totals;
    }

    /**
     * @param  array<string, true>  $eligibleSet
     * @param  array<string, float|null>  $payoutByInstrument
     * @param  array<string, mixed>  $config
     */
    private function refreshEligible(
        int $botId,
        array &$eligibleSet,
        array &$payoutByInstrument,
        array &$config,
    ): int {
        $bot = Bot::query()->find($botId);
        if (! $bot instanceof Bot || ! $bot->is_active) {
            return -1;
        }
        $config = is_array($bot->config) ? $bot->config : [];
        if (! $this->continuousScanEnabled($bot, $config)) {
            return -1;
        }

        $runtime = $this->engine->runtimeSettings($bot);
        $minPayout = (float) $runtime['min_payout'];
        $allowed = [];
        if (is_array($config['allowed_instruments'] ?? null)) {
            foreach ($config['allowed_instruments'] as $raw) {
                $k = trim((string) $raw);
                if ($k !== '') {
                    $allowed[$k] = true;
                }
            }
        }
        $blocked = [];
        if (is_array($config['blocked_instruments'] ?? null)) {
            foreach ($config['blocked_instruments'] as $raw) {
                $k = trim((string) $raw);
                if ($k !== '') {
                    $blocked[$k] = true;
                }
            }
        }

        $rows = PocketInstrument::active()
            ->orderByDesc('current_payout')
            ->orderByDesc('priority')
            ->orderBy('instrument')
            ->get(['instrument', 'current_payout']);

        $marketScope = BotInstrumentMarketScope::fromConfig($config);
        $nextSet = [];
        $nextPayout = [];
        foreach ($rows as $row) {
            $key = (string) $row->instrument;
            if ($allowed !== [] && ! isset($allowed[$key])) {
                continue;
            }
            if (isset($blocked[$key])) {
                continue;
            }
            // market_scope: all / только OTC (_otc) / только non-OTC.
            if (! BotInstrumentMarketScope::allows($key, $marketScope)) {
                continue;
            }
            $payout = $row->current_payout !== null ? (float) $row->current_payout : null;
            if ($payout === null || $payout < $minPayout) {
                continue;
            }
            $nextSet[$key] = true;
            $nextPayout[$key] = $payout;
        }

        $eligibleSet = $nextSet;
        $payoutByInstrument = $nextPayout;

        return count($nextSet);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, int>  $totals
     * @return array{signals:int,trades:int,cache_hit:bool}
     */
    private function evaluateOne(
        Bot $bot,
        string $instrument,
        ?float $payout,
        ?array $partial,
        array $config,
        array &$totals,
    ): array {
        $count = max(110, (int) ($config['count'] ?? 400));
        $staleAfterSec = max(5, (int) ($config['stale_after_sec'] ?? 30));
        // Engulfing / AuthorFlow: dashboard TF из config.timeframe (без MTF Impulse).
        $isDashboardTfBot = EngulfingLiquidityConfig::is((string) $bot->type)
            || AuthorFlowConfig::is((string) $bot->type);
        $keepForming = $isDashboardTfBot
            ? true
            : ImpulseLvl1minConfig::flag($config, 'keep_stale_forming_m1', true);
        $mtfTake = $isDashboardTfBot
            ? []
            : ImpulseLvl1minConfig::mtfTakeMap($config);
        // dashboard TF: 1=M1, 4005=S5, 4015=S15, 4030=S30 — НЕ путать ID с секундами бара.
        $defaultTf = AuthorFlowConfig::is((string) $bot->type)
            ? CandleService::TF_S15
            : 1;
        $timeframe = $isDashboardTfBot
            ? max(1, (int) ($config['timeframe'] ?? $defaultTf))
            : ImpulseLvl1minConfig::dashboardTimeframeId(
                ImpulseLvl1minConfig::barPeriodSec($config)
            );
        $periodSec = CandleService::periodSecondsForDashboardTf($timeframe) ?? 60;

        // M1: Redis current_candle как раньше. S15: сложить S5 в текущий 15с бакет (RAM).
        // Сырой S5 в ряд S15 не кладём — иначе live-фитиль и LVL смотрят чужой период.
        $partialForCache = null;
        $loaderIncludePartial = $timeframe === 1;
        if ($timeframe === 1) {
            $partialForCache = $partial;
        } elseif (! $isDashboardTfBot && ($timeframe === CandleService::TF_S15 || $timeframe === CandleService::TF_S30) && is_array($partial)) {
            $partialForCache = ImpulseLvl1minLiveWick::foldS5IntoBarPeriod(
                $partial,
                $periodSec,
                $this->seriesCache->lastPartial($instrument),
            );
        }

        $packed = $this->seriesCache->resolve(
            $instrument,
            function () use ($instrument, $count, $mtfTake, $staleAfterSec, $keepForming, $timeframe, $loaderIncludePartial) {
                return $this->botData->seriesWithMtf(
                    $instrument,
                    $count,
                    $mtfTake,
                    $loaderIncludePartial,
                    $staleAfterSec,
                    $timeframe,
                    $keepForming,
                );
            },
            $partialForCache,
            $periodSec,
            null,
            $count,
        );

        if ($packed['cache_hit']) {
            $totals['cache_hits']++;
        } else {
            $totals['db_loads']++;
        }

        $row = (object) [
            'instrument' => $instrument,
            'current_payout' => $payout,
        ];

        $r = $this->engine->scanBot($bot, [
            'only_instruments' => [$instrument],
            'instrument_rows' => [$row],
            'series_resolver' => static function () use ($packed): array {
                return [
                    'closed' => $packed['closed'],
                    'partial' => $packed['partial'],
                    'series' => $packed['series'],
                    'mtf' => $packed['mtf'],
                ];
            },
            'skip_progress_begin_end' => true,
            'disable_analysis_runs' => true,
        ]);

        $signals = (int) ($r['signals'] ?? 0);
        $trades = (int) ($r['trades'] ?? 0);
        $totals['processed'] += (int) ($r['processed'] ?? 0);
        $totals['signals'] += $signals;
        $totals['trades'] += $trades;

        return [
            'signals' => $signals,
            'trades' => $trades,
            'cache_hit' => (bool) $packed['cache_hit'],
        ];
    }

    /**
     * Live daemon включён для Impulse LVL / Engulfing / AuthorFlow.
     *
     * @param  array<string, mixed>  $config
     */
    private function continuousScanEnabled(Bot $bot, array $config): bool
    {
        if (AuthorFlowConfig::is((string) $bot->type)) {
            return AuthorFlowConfig::continuousScanEnabled($config);
        }
        if (EngulfingLiquidityConfig::is((string) $bot->type)) {
            return EngulfingLiquidityConfig::continuousScanEnabled($config);
        }

        return ImpulseLvl1minConfig::continuousScanEnabled($config);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function liveScanWorkers(Bot $bot, array $config): int
    {
        if (AuthorFlowConfig::is((string) $bot->type)) {
            return AuthorFlowConfig::scanWorkers($config);
        }
        if (EngulfingLiquidityConfig::is((string) $bot->type)) {
            return EngulfingLiquidityConfig::scanWorkers($config);
        }

        return ImpulseLvl1minConfig::scanWorkers($config);
    }

    /**
     * @param  list<string>  $instruments
     * @return array<string, array<string,mixed>|null>
     */
    private function mgetPartials(array $instruments): array
    {
        $out = array_fill_keys($instruments, null);
        if ($instruments === []) {
            return $out;
        }
        try {
            $keys = array_map(
                static fn (string $i): string => 'current_candle:'.$i,
                $instruments
            );
            $values = Redis::mget($keys);
        } catch (Throwable) {
            return $out;
        }
        if (! is_array($values)) {
            return $out;
        }
        foreach ($instruments as $i => $instrument) {
            $raw = $values[$i] ?? null;
            if (! is_string($raw) || $raw === '') {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (! is_array($decoded) || ! isset($decoded['time'])) {
                continue;
            }
            $out[$instrument] = [
                'instrument' => (string) ($decoded['instrument'] ?? $instrument),
                'time' => (int) ($decoded['time'] ?? 0),
                'raw_feed_time' => (int) ($decoded['raw_feed_time'] ?? 0),
                'effective_feed_time' => (int) ($decoded['effective_feed_time'] ?? ($decoded['time'] ?? 0)),
                'open' => (float) ($decoded['open'] ?? 0),
                'high' => (float) ($decoded['high'] ?? 0),
                'low' => (float) ($decoded['low'] ?? 0),
                'close' => (float) ($decoded['close'] ?? 0),
                'volume' => (float) ($decoded['volume'] ?? 0),
                'is_partial' => true,
                'updated_at' => (int) ($decoded['updated_at'] ?? 0),
                'ingest_received_at' => (int) ($decoded['ingest_received_at'] ?? 0),
            ];
        }

        return $out;
    }

    /** @param  array<string, mixed>  $snap */
    private function writeSnapshot(int $botId, array $snap): void
    {
        try {
            $json = json_encode($snap, JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                return;
            }
            Redis::setex(self::SNAP_KEY_PREFIX.$botId, 120, $json);
        } catch (Throwable) {
            // ignore
        }
    }

    /** @return array<string, mixed>|null */
    private function readSnapshot(int $botId): ?array
    {
        try {
            $raw = Redis::get(self::SNAP_KEY_PREFIX.$botId);
        } catch (Throwable) {
            return null;
        }
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function forgetSnapshot(int $botId): void
    {
        try {
            Redis::del(self::SNAP_KEY_PREFIX.$botId);
        } catch (Throwable) {
            // ignore
        }
    }

    /**
     * @return list<array{proc:resource,shard:int}>
     */
    private function spawnWorkers(int $botId, int $workers): array
    {
        $php = ArtisanProcessFinder::cliPhpBinary();
        $artisan = base_path('artisan');
        $out = [];
        for ($i = 0; $i < $workers; $i++) {
            $cmd = sprintf(
                '%s %s bots:impulse-live-worker --bot_id=%d --scan-shard=%d/%d',
                escapeshellarg($php),
                escapeshellarg($artisan),
                $botId,
                $i,
                $workers
            );
            // www-data часто не может писать в log, созданный CLI (user o) —
            // без writable fd proc_open падает → parent крутится без анализа.
            $log = ArtisanProcessFinder::ensureDaemonLogWritable(
                storage_path("logs/impulse-live-{$botId}-w{$i}.log")
            );
            $proc = @proc_open($cmd, [
                0 => ['pipe', 'r'],
                1 => ['file', $log, 'a'],
                2 => ['file', $log, 'a'],
            ], $pipes, base_path());
            if (! is_resource($proc)) {
                Log::warning('impulse_live_worker_spawn_failed', [
                    'bot_id' => $botId,
                    'shard' => $i,
                    'log' => $log,
                ]);
                continue;
            }
            if (isset($pipes[0]) && is_resource($pipes[0])) {
                fclose($pipes[0]);
            }
            $out[] = ['proc' => $proc, 'shard' => $i];
        }

        return $out;
    }

    /**
     * @param  list<array{proc:resource,shard:int}>  $processes
     * @return list<array{proc:resource,shard:int}>
     */
    private function ensureWorkers(int $botId, int $workers, array $processes): array
    {
        $alive = [];
        $aliveShards = [];
        foreach ($processes as $row) {
            $status = proc_get_status($row['proc']);
            if (! empty($status['running'])) {
                $alive[] = $row;
                $aliveShards[(int) $row['shard']] = true;
            } else {
                proc_close($row['proc']);
            }
        }
        if (count($alive) >= $workers) {
            return $alive;
        }
        $php = ArtisanProcessFinder::cliPhpBinary();
        $artisan = base_path('artisan');
        for ($i = 0; $i < $workers; $i++) {
            if (isset($aliveShards[$i])) {
                continue;
            }
            $cmd = sprintf(
                '%s %s bots:impulse-live-worker --bot_id=%d --scan-shard=%d/%d',
                escapeshellarg($php),
                escapeshellarg($artisan),
                $botId,
                $i,
                $workers
            );
            $log = ArtisanProcessFinder::ensureDaemonLogWritable(
                storage_path("logs/impulse-live-{$botId}-w{$i}.log")
            );
            $proc = @proc_open($cmd, [
                0 => ['pipe', 'r'],
                1 => ['file', $log, 'a'],
                2 => ['file', $log, 'a'],
            ], $pipes, base_path());
            if (! is_resource($proc)) {
                Log::warning('impulse_live_worker_respawn_failed', [
                    'bot_id' => $botId,
                    'shard' => $i,
                    'log' => $log,
                ]);
                continue;
            }
            if (isset($pipes[0]) && is_resource($pipes[0])) {
                fclose($pipes[0]);
            }
            $alive[] = ['proc' => $proc, 'shard' => $i];
        }

        return $alive;
    }

    /** @param  list<array{proc:resource,shard:int}>  $processes */
    private function stopWorkers(array $processes): void
    {
        foreach ($processes as $row) {
            $status = proc_get_status($row['proc']);
            if (! empty($status['running']) && isset($status['pid'])) {
                $pid = (int) $status['pid'];
                if ($pid > 1) {
                    @posix_kill($pid, SIGTERM);
                }
            }
            proc_close($row['proc']);
        }
    }
}
