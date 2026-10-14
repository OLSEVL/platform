<?php

namespace App\Support;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Прогресс bots:scan для sidebar бота.
 *
 * Храним состояние в database cache (не file): scan идёт из artisan/cron,
 * а sidebar опрашивается через php-fpm — file cache между ними часто не виден.
 *
 * При scan_workers>1 каждый шард пишет в свой ключ (без гонок read-modify-write),
 * snapshot агрегирует remaining / analyzed / currents для UI «6 потоков».
 *
 * Состояния точки у пары:
 * - gray  — ожидает проверки (по умолчанию и в очереди scan);
 * - red   — анализируется сейчас (любым шардом);
 * - green — уже проверена в текущем проходе scan.
 */
final class BotScanProgress
{
    /** Запасной TTL: длинный scan не должен терять состояние mid-run. */
    private const TTL_SECONDS = 7200;

    /** Макс. шардов в Impulse (см. ImpulseLvl1minConfig::ALLOWED_SCAN_WORKERS). */
    private const MAX_SHARDS = 8;

    private static function cacheKey(int $botId): string
    {
        return "bot_scan_progress:{$botId}";
    }

    private static function shardCacheKey(int $botId, int $shardIndex): string
    {
        return "bot_scan_progress:{$botId}:shard:{$shardIndex}";
    }

    /**
     * Database cache общий для artisan и php-fpm; file cache — fallback.
     */
    private static function cache(): Repository
    {
        static $store = null;
        static $boundAppId = null;

        // PHPUnit / новый request: не держим Cache repository от предыдущего контейнера приложения.
        $appId = spl_object_id(app());
        if ($store instanceof Repository && $boundAppId === $appId) {
            return $store;
        }
        $store = null;
        $boundAppId = $appId;

        try {
            if (Schema::hasTable('cache')) {
                $store = Cache::store('database');
            } else {
                $store = Cache::store(config('cache.default'));
            }
        } catch (\Throwable) {
            $store = Cache::store(config('cache.default'));
        }

        return $store;
    }

    /**
     * @return array{
     *     run_id: ?string,
     *     scan_active: bool,
     *     current: ?string,
     *     current_since: ?int,
     *     analyzed: list<string>,
     *     total: int,
     *     mode: ?string,
     *     shard_count: int,
     *     currents: list<string>,
     *     shards: list<array{index:int,current:?string,remaining:?int,total:int,analyzed_count:int,active:bool}>
     * }
     */
    private static function defaultState(): array
    {
        return [
            'run_id' => null,
            'scan_active' => false,
            'current' => null,
            'current_since' => null,
            'analyzed' => [],
            'total' => 0,
            'mode' => null,
            'shard_count' => 1,
            'currents' => [],
            'shards' => [],
        ];
    }

    /**
     * @return array{
     *     run_id: ?string,
     *     scan_active: bool,
     *     current: ?string,
     *     current_since: ?int,
     *     analyzed: list<string>,
     *     total: int,
     *     mode: ?string,
     *     shard_count: int
     * }
     */
    private static function normalizeShardRaw(mixed $raw): array
    {
        if (is_string($raw) && $raw !== '') {
            return [
                'run_id' => null,
                'scan_active' => true,
                'current' => $raw,
                'current_since' => null,
                'analyzed' => [],
                'total' => 0,
                'mode' => null,
                'shard_count' => 1,
            ];
        }

        if (! is_array($raw)) {
            return [
                'run_id' => null,
                'scan_active' => false,
                'current' => null,
                'current_since' => null,
                'analyzed' => [],
                'total' => 0,
                'mode' => null,
                'shard_count' => 1,
            ];
        }

        $analyzed = [];
        foreach ((array) ($raw['analyzed'] ?? []) as $item) {
            if (is_string($item) && $item !== '') {
                $analyzed[] = $item;
            }
        }

        $current = $raw['current'] ?? null;
        $runId = $raw['run_id'] ?? null;
        $mode = $raw['mode'] ?? null;

        return [
            'run_id' => is_string($runId) && $runId !== '' ? $runId : null,
            'scan_active' => (bool) ($raw['scan_active'] ?? false),
            'current' => is_string($current) && $current !== '' ? $current : null,
            'current_since' => isset($raw['current_since']) ? (int) $raw['current_since'] : null,
            'analyzed' => $analyzed,
            'total' => max(0, (int) ($raw['total'] ?? 0)),
            'mode' => is_string($mode) && $mode !== '' ? $mode : null,
            'shard_count' => max(1, (int) ($raw['shard_count'] ?? 1)),
        ];
    }

    /**
     * Агрегат: один процесс (legacy) или сумма шардов.
     *
     * @return array{
     *     run_id: ?string,
     *     scan_active: bool,
     *     current: ?string,
     *     current_since: ?int,
     *     analyzed: list<string>,
     *     total: int,
     *     mode: ?string,
     *     shard_count: int,
     *     currents: list<string>,
     *     shards: list<array{index:int,current:?string,remaining:?int,total:int,analyzed_count:int,active:bool}>
     * }
     */
    private static function readState(int $botId): array
    {
        $meta = self::normalizeShardRaw(self::cache()->get(self::cacheKey($botId)));

        // Параллельный Impulse: meta.mode=sharded + отдельные ключи на поток.
        if (($meta['mode'] ?? null) === 'sharded') {
            $shardCount = max(2, min(self::MAX_SHARDS, (int) ($meta['shard_count'] ?? 2)));
            $shards = [];
            $analyzed = [];
            $currents = [];
            $total = 0;
            $anyActive = (bool) ($meta['scan_active'] ?? false);
            $firstCurrent = null;
            $firstSince = null;

            for ($i = 0; $i < $shardCount; $i++) {
                $raw = self::normalizeShardRaw(self::cache()->get(self::shardCacheKey($botId, $i)));
                $active = self::isScanActiveState($raw);
                $shardTotal = (int) ($raw['total'] ?? 0);
                $analyzedCount = count($raw['analyzed']);
                $remaining = null;
                if ($active && $shardTotal > 0) {
                    $remaining = max(0, $shardTotal - $analyzedCount);
                }
                if ($active) {
                    $anyActive = true;
                    $total += $shardTotal;
                    foreach ($raw['analyzed'] as $inst) {
                        $analyzed[$inst] = true;
                    }
                    if ($raw['current'] !== null) {
                        $currents[] = $raw['current'];
                        if ($firstCurrent === null) {
                            $firstCurrent = $raw['current'];
                            $firstSince = $raw['current_since'];
                        }
                    }
                }
                $shards[] = [
                    'index' => $i,
                    'current' => $raw['current'],
                    'remaining' => $remaining,
                    'total' => $shardTotal,
                    'analyzed_count' => $analyzedCount,
                    'active' => $active,
                ];
            }

            return [
                'run_id' => $meta['run_id'],
                'scan_active' => $anyActive,
                'current' => $firstCurrent,
                'current_since' => $firstSince,
                'analyzed' => array_keys($analyzed),
                'total' => $total,
                'mode' => 'sharded',
                'shard_count' => $shardCount,
                'currents' => $currents,
                'shards' => $shards,
            ];
        }

        // Одиночный bots:scan — всё в одном ключе, как раньше.
        $currents = $meta['current'] !== null ? [$meta['current']] : [];

        return [
            'run_id' => $meta['run_id'],
            'scan_active' => $meta['scan_active'],
            'current' => $meta['current'],
            'current_since' => $meta['current_since'],
            'analyzed' => $meta['analyzed'],
            'total' => $meta['total'],
            'mode' => null,
            'shard_count' => 1,
            'currents' => $currents,
            'shards' => [[
                'index' => 0,
                'current' => $meta['current'],
                'remaining' => self::isScanActiveState($meta) && $meta['total'] > 0
                    ? max(0, $meta['total'] - count($meta['analyzed']))
                    : null,
                'total' => $meta['total'],
                'analyzed_count' => count($meta['analyzed']),
                'active' => self::isScanActiveState($meta),
            ]],
        ];
    }

    /**
     * @param  array{run_id:?string,scan_active:bool,current:?string,current_since:?int,analyzed:list<string>,total:int}  $state
     */
    private static function writeLegacyState(int $botId, array $state, bool $replace = false): void
    {
        if (! $replace) {
            $existing = self::normalizeShardRaw(self::cache()->get(self::cacheKey($botId)));

            // Защита от «битого» read-modify-write: не теряем total и уже проверенные пары.
            if ((int) ($state['total'] ?? 0) <= 0 && (int) ($existing['total'] ?? 0) > 0) {
                $state['total'] = (int) $existing['total'];
            }
            if (($state['run_id'] ?? null) === null && ($existing['run_id'] ?? null) !== null) {
                $state['run_id'] = $existing['run_id'];
            }
            if (($state['analyzed'] ?? []) === [] && ($existing['analyzed'] ?? []) !== []) {
                $state['analyzed'] = $existing['analyzed'];
            }
            if (! ($state['scan_active'] ?? false) && ($existing['scan_active'] ?? false)) {
                $state['scan_active'] = true;
            }
        }

        self::cache()->put(self::cacheKey($botId), $state, self::TTL_SECONDS);
        self::publishScanSafe($botId);
    }

    /**
     * @param  array{run_id:?string,scan_active:bool,current:?string,current_since:?int,analyzed:list<string>,total:int}  $state
     */
    private static function writeShardState(int $botId, int $shardIndex, array $state, bool $replace = false): void
    {
        $key = self::shardCacheKey($botId, $shardIndex);
        if (! $replace) {
            $existing = self::normalizeShardRaw(self::cache()->get($key));
            if ((int) ($state['total'] ?? 0) <= 0 && (int) ($existing['total'] ?? 0) > 0) {
                $state['total'] = (int) $existing['total'];
            }
            if (($state['run_id'] ?? null) === null && ($existing['run_id'] ?? null) !== null) {
                $state['run_id'] = $existing['run_id'];
            }
            if (($state['analyzed'] ?? []) === [] && ($existing['analyzed'] ?? []) !== []) {
                $state['analyzed'] = $existing['analyzed'];
            }
            if (! ($state['scan_active'] ?? false) && ($existing['scan_active'] ?? false)) {
                $state['scan_active'] = true;
            }
        }

        self::cache()->put($key, $state, self::TTL_SECONDS);
        self::publishScanSafe($botId);
    }

    private static function publishScanSafe(int $botId): void
    {
        try {
            app(\App\Services\BotLiveEventsBroadcast::class)->publishScan($botId);
        } catch (\Throwable) {
            // ignore
        }
    }

    /** Scan активен, если явный флаг или есть current/analyzed (защита от потери scan_active). */
    private static function isScanActiveState(array $state): bool
    {
        return (bool) ($state['scan_active'] ?? false)
            || ($state['current'] ?? null) !== null
            || (($state['analyzed'] ?? []) !== []);
    }

    private static function lastStartCacheKey(int $botId): string
    {
        return "bot_scan_last_start:{$botId}";
    }

    /** Unix старта последнего scan (для интервала dispatch). Не сбрасывается в endScan. */
    public static function markStarted(int $botId, ?int $at = null): void
    {
        self::cache()->put(self::lastStartCacheKey($botId), $at ?? time(), self::TTL_SECONDS);
    }

    public static function lastStartedAt(int $botId): ?int
    {
        $raw = self::cache()->get(self::lastStartCacheKey($botId));
        if (! is_numeric($raw)) {
            return null;
        }
        $t = (int) $raw;

        return $t > 0 ? $t : null;
    }

    public static function forgetLastStarted(int $botId): void
    {
        self::cache()->forget(self::lastStartCacheKey($botId));
    }

    /**
     * Parent Impulse: объявляет волну из N шардов до spawn (UI сразу видит 6 слотов).
     */
    public static function beginShardedWave(int $botId, int $shardCount): string
    {
        $shardCount = max(2, min(self::MAX_SHARDS, $shardCount));
        $waveId = bin2hex(random_bytes(8));

        for ($i = 0; $i < self::MAX_SHARDS; $i++) {
            self::cache()->forget(self::shardCacheKey($botId, $i));
        }

        self::cache()->put(self::cacheKey($botId), [
            'mode' => 'sharded',
            'scan_active' => true,
            'shard_count' => $shardCount,
            'wave_id' => $waveId,
            'run_id' => $waveId,
            'current' => null,
            'current_since' => null,
            'analyzed' => [],
            'total' => 0,
        ], self::TTL_SECONDS);

        // wave_id фиксирует слоты UI; markStarted — в spawn / endShardedWave (не здесь),
        // иначе mid-warm lastStarted уезжает вперёд и idle-таймер врёт.
        self::publishScanSafe($botId);

        return $waveId;
    }

    /** Parent после join всех шардов: гасит meta + ключи потоков. */
    public static function endShardedWave(int $botId): void
    {
        self::clearAllProgress($botId);
        self::publishScanSafe($botId);
    }

    private static function clearAllProgress(int $botId): void
    {
        for ($i = 0; $i < self::MAX_SHARDS; $i++) {
            self::cache()->forget(self::shardCacheKey($botId, $i));
        }
        self::cache()->forget(self::cacheKey($botId));
    }

    /**
     * Новый проход scan. При $shardCount>1 пишет только свой шард (не затирает соседние).
     */
    public static function beginScan(
        int $botId,
        int $totalInstruments,
        int $shardIndex = 0,
        int $shardCount = 1
    ): string {
        $runId = bin2hex(random_bytes(8));
        $shardCount = max(1, min(self::MAX_SHARDS, $shardCount));
        $shardIndex = max(0, min($shardCount - 1, $shardIndex));

        $payload = [
            'run_id' => $runId,
            'scan_active' => true,
            'current' => null,
            'current_since' => null,
            'analyzed' => [],
            'total' => max(0, $totalInstruments),
        ];

        if ($shardCount > 1) {
            // Meta могла уже быть от beginShardedWave; если нет — создаём сами.
            $meta = self::normalizeShardRaw(self::cache()->get(self::cacheKey($botId)));
            if (($meta['mode'] ?? null) !== 'sharded') {
                self::cache()->put(self::cacheKey($botId), [
                    'mode' => 'sharded',
                    'scan_active' => true,
                    'shard_count' => $shardCount,
                    'wave_id' => $runId,
                    'run_id' => $runId,
                    'current' => null,
                    'current_since' => null,
                    'analyzed' => [],
                    'total' => 0,
                ], self::TTL_SECONDS);
            }
            self::writeShardState($botId, $shardIndex, $payload, true);
        } else {
            self::writeLegacyState($botId, $payload, true);
        }

        // Запоминаем старт отдельно от progress: endScan чистит progress, интервал dispatch — нет.
        self::markStarted($botId);

        return $runId;
    }

    /**
     * Конец scan. Без $runId — полная очистка (watchdog/orphan).
     * С шардом — гасим только свой ключ; meta живёт до endShardedWave.
     */
    public static function endScan(
        int $botId,
        ?string $runId = null,
        int $shardIndex = 0,
        int $shardCount = 1
    ): void {
        $shardCount = max(1, min(self::MAX_SHARDS, $shardCount));

        if ($runId === null) {
            self::clearAllProgress($botId);
            self::publishScanSafe($botId);

            return;
        }

        if ($shardCount > 1) {
            $key = self::shardCacheKey($botId, $shardIndex);
            $state = self::normalizeShardRaw(self::cache()->get($key));
            if (($state['run_id'] ?? null) !== $runId) {
                return;
            }
            self::cache()->forget($key);
            self::publishScanSafe($botId);

            return;
        }

        $state = self::normalizeShardRaw(self::cache()->get(self::cacheKey($botId)));
        if (($state['run_id'] ?? null) !== $runId) {
            return;
        }

        self::cache()->forget(self::cacheKey($botId));
        self::publishScanSafe($botId);
    }

    public static function setCurrentInstrument(
        int $botId,
        string $instrument,
        int $shardIndex = 0,
        int $shardCount = 1
    ): void {
        $shardCount = max(1, min(self::MAX_SHARDS, $shardCount));
        if ($shardCount > 1) {
            $state = self::normalizeShardRaw(self::cache()->get(self::shardCacheKey($botId, $shardIndex)));
            $state['scan_active'] = true;
            if ($state['current'] !== $instrument) {
                $state['current_since'] = time();
            }
            $state['current'] = $instrument;
            self::writeShardState($botId, $shardIndex, $state);

            return;
        }

        $state = self::normalizeShardRaw(self::cache()->get(self::cacheKey($botId)));
        $state['scan_active'] = true;
        if ($state['current'] !== $instrument) {
            $state['current_since'] = time();
        }
        $state['current'] = $instrument;
        self::writeLegacyState($botId, $state);
    }

    /** Пара обработана: red → green в sidebar текущего scan. */
    public static function finishInstrument(
        int $botId,
        string $instrument,
        int $shardIndex = 0,
        int $shardCount = 1
    ): void {
        $shardCount = max(1, min(self::MAX_SHARDS, $shardCount));
        if ($shardCount > 1) {
            $state = self::normalizeShardRaw(self::cache()->get(self::shardCacheKey($botId, $shardIndex)));
            $state['scan_active'] = true;
            if ($state['current'] === $instrument) {
                $state['current'] = null;
                $state['current_since'] = null;
            }
            if (! in_array($instrument, $state['analyzed'], true)) {
                $state['analyzed'][] = $instrument;
            }
            self::writeShardState($botId, $shardIndex, $state);

            return;
        }

        $state = self::normalizeShardRaw(self::cache()->get(self::cacheKey($botId)));
        $state['scan_active'] = true;
        if ($state['current'] === $instrument) {
            $state['current'] = null;
            $state['current_since'] = null;
        }
        if (! in_array($instrument, $state['analyzed'], true)) {
            $state['analyzed'][] = $instrument;
        }
        self::writeLegacyState($botId, $state);
    }

    public static function currentInstrument(int $botId): ?string
    {
        return self::readState($botId)['current'];
    }

    /** @return list<string> */
    public static function analyzedInstruments(int $botId): array
    {
        return self::readState($botId)['analyzed'];
    }

    public static function isScanActive(int $botId): bool
    {
        return self::isScanActiveState(self::readState($botId));
    }

    /** Сколько пар ещё не проверено в текущем scan (включая анализируемую сейчас). */
    public static function remainingCount(int $botId): ?int
    {
        $state = self::readState($botId);
        if (! self::isScanActiveState($state)) {
            return null;
        }

        $total = (int) ($state['total'] ?? 0);
        if ($total <= 0) {
            // Волна объявлена, шарды ещё не сообщили total — остаток неизвестен.
            return null;
        }

        return max(0, $total - count($state['analyzed']));
    }

    /** Пара участвует в scan только при известном payout не ниже порога бота. */
    public static function isEligibleForScan(?float $payout, float $minPayout): bool
    {
        return $payout !== null && $payout >= $minPayout;
    }

    /** @return 'green'|'red'|'gray'|'none' */
    public static function dotColor(int $botId, string $instrument, ?float $payout = null, ?float $minPayout = null): string
    {
        // Ниже min_payout точку не рисуем — пара не входит в анализ текущего scan.
        if ($minPayout !== null && ! self::isEligibleForScan($payout, $minPayout)) {
            return 'none';
        }

        $state = self::readState($botId);
        if (self::isScanActiveState($state)) {
            if (in_array($instrument, $state['currents'] ?? [], true) || $state['current'] === $instrument) {
                return 'red';
            }
            if (in_array($instrument, $state['analyzed'], true)) {
                return 'green';
            }

            return 'gray';
        }

        return 'gray';
    }

    /**
     * @return array{
     *     scan_active: bool,
     *     analyzing_instrument: ?string,
     *     analyzing_instruments: list<string>,
     *     analyzed_instruments: list<string>,
     *     scan_total: int,
     *     scan_remaining: ?int,
     *     scan_min_payout: ?float,
     *     analyzing_since: ?int,
     *     scan_shard_count: int,
     *     scan_shards: list<array{index:int,current:?string,remaining:?int,total:int,analyzed_count:int,active:bool}>
     * }
     */
    public static function snapshot(int $botId, ?float $minPayout = null): array
    {
        $state = self::readState($botId);
        $scanActive = self::isScanActiveState($state);
        $total = (int) ($state['total'] ?? 0);
        $remaining = null;
        if ($scanActive && $total > 0) {
            $remaining = max(0, $total - count($state['analyzed']));
        }

        return [
            'scan_active' => $scanActive,
            'analyzing_instrument' => $state['current'],
            'analyzing_instruments' => $state['currents'] ?? [],
            'analyzing_since' => $state['current_since'] ?? null,
            'analyzed_instruments' => $state['analyzed'],
            'scan_total' => $total,
            'scan_remaining' => $remaining,
            'scan_min_payout' => $minPayout,
            'scan_shard_count' => (int) ($state['shard_count'] ?? 1),
            'scan_shards' => $state['shards'] ?? [],
        ];
    }
}
