<?php

namespace App\Support;

use App\Models\Bot;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-bot mutex и обнаружение процессов bots:scan.
 * Каждый активный бот может сканироваться параллельно в своём PHP-процессе.
 */
final class BotScanCoordinator
{
    /** Ключ app_settings: порог wall-time (сек) для bots:scan-watchdog. */
    public const SETTING_KEY_SCAN_WATCHDOG_MAX_ELAPSED_SEC = 'bots_scan_watchdog_max_elapsed_sec';

    /** Длинный scan не должен терять lock mid-run (до 6 ч). */
    public const LOCK_TTL_SEC = 21600;

    /**
     * Дефолтный потолок wall-time одного bots:scan --bot_id=N (если нет app_settings).
     * Нормальный цикл Strong Level ~2–10 мин; 60+ мин = зависший процесс.
     * Важно: isScanRunning() смотрит только на живой PID — без reap dispatch
     * вечно пропускает бота (кейс #17: 31ч при 70%+ CPU).
     */
    public const SCAN_MAX_ELAPSED_SEC = 3600;

    /** Допустимые пороги для UI /settings (минуты → секунды). */
    public const ALLOWED_WATCHDOG_MAX_ELAPSED_SEC = [
        900,   // 15 мин
        1800,  // 30 мин
        3600,  // 60 мин
        5400,  // 90 мин
        7200,  // 2 ч
    ];

    /** Lock для ручного прохода всех ботов в одном процессе (без --bot_id). */
    public const ALL_BOTS_LOCK = 'bots:scan:all';

    /** Как в bootstrap/app.php: bots:scan-dispatch → everyFiveSeconds (MaxBot live на M1). */
    public const DISPATCH_INTERVAL_SEC = 5;

    /** Интервал скана бота (секунды). */
    public const SCAN_INTERVAL_S5 = 5;

    public const SCAN_INTERVAL_S15 = 15;

    public const SCAN_INTERVAL_S30 = 30;

    public const SCAN_INTERVAL_M1 = 60;

    /** Нет ключа в config → M1 для большинства ботов. */
    public const DEFAULT_SCAN_INTERVAL_SEC = self::SCAN_INTERVAL_M1;

    /** @var list<int> */
    public const ALLOWED_SCAN_INTERVALS_SEC = [
        self::SCAN_INTERVAL_S5,
        self::SCAN_INTERVAL_S15,
        self::SCAN_INTERVAL_S30,
        self::SCAN_INTERVAL_M1,
    ];

    /**
     * Unix-время следующего тика dispatch (границы каждые DISPATCH_INTERVAL_SEC).
     * На самой границе возвращаем «сейчас»: команда как раз должна стартовать.
     */
    public static function nextDispatchUnix(?int $now = null): int
    {
        $now = $now ?? time();
        $interval = self::DISPATCH_INTERVAL_SEC;
        $mod = $now % $interval;

        return $mod === 0 ? $now : $now + ($interval - $mod);
    }

    /** 5 / 15 / 30 / 60; неизвестное → M1. */
    public static function normalizeScanIntervalSec(mixed $raw): int
    {
        $value = (int) $raw;

        return in_array($value, self::ALLOWED_SCAN_INTERVALS_SEC, true)
            ? $value
            : self::SCAN_INTERVAL_M1;
    }

    /** Дефолт интервала скана для типа бота (MaxBot — 5 с live). */
    public static function defaultScanIntervalSecForType(string $botType): int
    {
        return in_array($botType, [
            \App\Support\MaxBotConfig::BOT_TYPE,
            \App\Support\ImpulseLvl1minConfig::BOT_TYPE,
        ], true)
            ? 5
            : self::DEFAULT_SCAN_INTERVAL_SEC;
    }

    public static function scanIntervalSecForBot(Bot $bot): int
    {
        $cfg = is_array($bot->config) ? $bot->config : [];
        if (! array_key_exists('scan_interval_sec', $cfg) || $cfg['scan_interval_sec'] === null || $cfg['scan_interval_sec'] === '') {
            return self::defaultScanIntervalSecForType((string) $bot->type);
        }

        return self::normalizeScanIntervalSec($cfg['scan_interval_sec']);
    }

    /** Можно ли стартовать новый scan: прошло ≥ интервала с прошлого старта. */
    public static function isDueToSpawn(Bot $bot, ?int $now = null): bool
    {
        $now = $now ?? time();
        $last = BotScanProgress::lastStartedAt((int) $bot->id);
        if ($last === null) {
            return true;
        }

        return ($now - $last) >= self::scanIntervalSecForBot($bot);
    }

    /** Unix следующего разрешённого старта (для отсчёта на странице бота). */
    public static function nextDueUnix(Bot $bot, ?int $now = null): int
    {
        $now = $now ?? time();
        $interval = self::scanIntervalSecForBot($bot);
        $last = BotScanProgress::lastStartedAt((int) $bot->id);
        if ($last !== null) {
            $earliest = $last + $interval;
            if ($earliest > $now) {
                return $earliest;
            }
        }
        $mod = $now % $interval;

        return $mod === 0 ? $now : $now + ($interval - $mod);
    }

    public static function botLockName(int $botId): string
    {
        return 'bots:scan:bot:'.$botId;
    }

    public static function lockStorageKey(string $lockName): string
    {
        return config('cache.prefix').$lockName;
    }

    /**
     * @return list<int>
     */
    public static function findScanPids(?int $botId = null, ?int $exceptPid = null): array
    {
        return ArtisanProcessFinder::findArtisanPids(
            static fn (string $cmd): bool => self::cmdlineMatchesScan($cmd, $botId),
            $exceptPid
        );
    }

    public static function isScanRunning(?int $botId = null, ?int $exceptPid = null): bool
    {
        return self::findScanPids($botId, $exceptPid) !== [];
    }

    /**
     * @return list<array{pid:int,bot_id:?int,cmdline:?string}>
     */
    public static function listRunningScans(?int $exceptPid = null): array
    {
        $pids = self::findScanPids(null, $exceptPid);
        $rows = [];
        foreach ($pids as $pid) {
            $cmdline = @file_get_contents("/proc/{$pid}/cmdline");
            $cmdline = $cmdline !== false ? str_replace("\0", ' ', $cmdline) : null;
            $rows[] = [
                'pid' => $pid,
                'bot_id' => $cmdline !== null ? self::parseBotIdFromCmdline($cmdline) : null,
                'cmdline' => $cmdline,
            ];
        }

        return $rows;
    }

    public static function parseBotIdFromCmdline(string $cmdline): ?int
    {
        if (preg_match('#\bbots:scan\b.*--bot_id=(\d+)#', $cmdline, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /** Имя lock `bots:scan:bot:47` → 47. */
    public static function parseBotIdFromLockName(string $lockName): ?int
    {
        if (preg_match('#bots:scan:bot:(\d+)$#', $lockName, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * Снять mutex бота, если процесса bots:scan уже нет (сирота после FatalError).
     *
     * @return list<array{bot_id:int,key:string}>
     */
    public static function clearOrphanScanLocks(): array
    {
        $cleared = [];
        foreach (self::listLockRows() as $row) {
            $botId = self::parseBotIdFromLockName((string) $row['key']);
            if ($botId === null || self::isScanRunning($botId)) {
                continue;
            }
            self::clearLock(self::botLockName($botId));
            BotScanProgress::endScan($botId);
            $cleared[] = ['bot_id' => $botId, 'key' => (string) $row['key']];
        }

        return $cleared;
    }

    public static function cmdlineMatchesScan(string $cmdline, ?int $botId): bool
    {
        if (! preg_match('#(?:^|/)(php\d*(?:\.\d+)?)\s+artisan\s+bots:scan\b#', $cmdline)) {
            return false;
        }

        if (str_contains($cmdline, 'bots:scan-dispatch')) {
            return false;
        }

        $cmdBotId = self::parseBotIdFromCmdline($cmdline);

        if ($botId !== null) {
            return $cmdBotId === $botId;
        }

        // Watchdog / список всех scan: и per-bot (--bot_id=N), и legacy без --bot_id.
        // Раньше при null возвращали только legacy → зависшие --bot_id=N никогда не reap'ились
        // (isScanRunning(N)=true → dispatch skip, сделки на платформе останавливались).
        return true;
    }

    /**
     * @return Collection<int, Bot>
     */
    public static function scannableBots(): Collection
    {
        return Bot::query()
            ->where('is_active', true)
            ->whereNotIn('type', \App\Support\ChartAnalystBotTypes::all())
            ->orderBy('id')
            ->get();
    }

    public static function logPathForBot(int $botId): string
    {
        return storage_path('logs/bots-scan-'.$botId.'.log');
    }

    public static function sharedLogPath(): string
    {
        return storage_path('logs/bots-scan.log');
    }

    /**
     * @return array{acquired:bool,lock:?Lock,lock_name:string}
     */
    public static function acquireLock(string $lockName, ?int $exceptPid = null): array
    {
        $lockStore = Schema::hasTable('cache') ? Cache::store('database') : Cache::store();
        $fullKey = self::lockStorageKey($lockName);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $lock = $lockStore->lock($lockName, self::LOCK_TTL_SEC);
            if ($lock->get()) {
                return ['acquired' => true, 'lock' => $lock, 'lock_name' => $lockName];
            }

            if ($attempt === 0 && Schema::hasTable('cache_locks')) {
                $row = DB::table('cache_locks')->where('key', $fullKey)->first();
                if ($row && (int) ($row->expiration ?? 0) <= time()) {
                    DB::table('cache_locks')->where('key', $fullKey)->delete();

                    continue;
                }
                // FatalError / kill -9: finally не отпускает lock (TTL до 6 ч), PID уже нет.
                // Без съёма dispatch каждые 5 с пишет «пропуск (lock)» и бот молчит.
                $orphanBotId = self::parseBotIdFromLockName($lockName);
                if ($orphanBotId !== null && ! self::isScanRunning($orphanBotId, $exceptPid)) {
                    DB::table('cache_locks')->where('key', $fullKey)->delete();
                    BotScanProgress::endScan($orphanBotId);

                    continue;
                }
            }

            break;
        }

        return ['acquired' => false, 'lock' => null, 'lock_name' => $lockName];
    }

    public static function releaseLock(?Lock $lock): void
    {
        $lock?->release();
    }

    public static function clearLock(string $lockName): void
    {
        if (! Schema::hasTable('cache_locks')) {
            return;
        }

        DB::table('cache_locks')->where('key', self::lockStorageKey($lockName))->delete();
    }

    public static function clearAllScanLocks(): void
    {
        if (! Schema::hasTable('cache_locks')) {
            return;
        }

        $prefix = config('cache.prefix').'bots:scan:';
        DB::table('cache_locks')->where('key', 'like', $prefix.'%')->delete();
    }

    public static function spawnScan(int $botId): bool
    {
        $ok = ArtisanProcessFinder::spawnBackground(
            [ArtisanProcessFinder::cliPhpBinary(), 'artisan', 'bots:scan', '--bot_id='.$botId],
            self::logPathForBot($botId),
        );
        if ($ok) {
            // Фиксируем старт сразу: иначе следующий 30с-тик успеет запустить второй процесс до beginScan.
            BotScanProgress::markStarted($botId);
        }

        return $ok;
    }

    public static function spawnDispatchAll(): int
    {
        $started = 0;
        foreach (self::scannableBots() as $bot) {
            if (self::isScanRunning($bot->id)) {
                continue;
            }
            if (! self::isDueToSpawn($bot)) {
                continue;
            }
            if (self::spawnScan($bot->id)) {
                $started++;
            }
        }

        return $started;
    }

    /**
     * @return list<array{key:string,owner:?string,expiration:?int,expired:bool}>
     */
    public static function listLockRows(): array
    {
        if (! Schema::hasTable('cache_locks')) {
            return [];
        }

        $prefix = config('cache.prefix').'bots:scan:';
        $rows = DB::table('cache_locks')
            ->where('key', 'like', $prefix.'%')
            ->orderBy('key')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $expiration = isset($row->expiration) ? (int) $row->expiration : null;
            $out[] = [
                'key' => (string) $row->key,
                'owner' => $row->owner ?? null,
                'expiration' => $expiration,
                'expired' => $expiration !== null && $expiration <= time(),
            ];
        }

        return $out;
    }

    /**
     * Порог watchdog из app_settings (или SCAN_MAX_ELAPSED_SEC).
     */
    public static function getScanMaxElapsedSec(): int
    {
        if (! Schema::hasTable('app_settings')) {
            return self::SCAN_MAX_ELAPSED_SEC;
        }

        $raw = DB::table('app_settings')
            ->where('key', self::SETTING_KEY_SCAN_WATCHDOG_MAX_ELAPSED_SEC)
            ->value('value');

        if ($raw === null || trim((string) $raw) === '') {
            return self::SCAN_MAX_ELAPSED_SEC;
        }

        $sec = (int) $raw;

        return in_array($sec, self::ALLOWED_WATCHDOG_MAX_ELAPSED_SEC, true)
            ? $sec
            : self::SCAN_MAX_ELAPSED_SEC;
    }

    public static function setScanMaxElapsedSec(int $seconds): void
    {
        $value = in_array($seconds, self::ALLOWED_WATCHDOG_MAX_ELAPSED_SEC, true)
            ? $seconds
            : self::SCAN_MAX_ELAPSED_SEC;

        DB::table('app_settings')->updateOrInsert(
            ['key' => self::SETTING_KEY_SCAN_WATCHDOG_MAX_ELAPSED_SEC],
            [
                'value' => (string) $value,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    /** Подпись для UI: «60 мин». */
    public static function scanMaxElapsedLabel(?int $seconds = null): string
    {
        $sec = $seconds ?? self::getScanMaxElapsedSec();
        $min = (int) round($sec / 60);

        return $min.' мин';
    }

    /**
     * Снимок живых scan для страницы /settings.
     *
     * @return list<array{pid:int,bot_id:?int,elapsed_sec:?int,cpu_percent:?float,stale:bool}>
     */
    public static function runningScanStatusRows(): array
    {
        $maxElapsed = self::getScanMaxElapsedSec();
        $rows = [];
        foreach (self::listRunningScans() as $row) {
            $pid = (int) $row['pid'];
            $metrics = ArtisanProcessFinder::readPidMetrics($pid);
            $elapsed = $metrics['elapsed_sec'] ?? null;
            $rows[] = [
                'pid' => $pid,
                'bot_id' => $row['bot_id'] ?? null,
                'elapsed_sec' => $elapsed,
                'cpu_percent' => $metrics['cpu_percent'] ?? null,
                'stale' => is_int($elapsed) && $elapsed >= $maxElapsed,
            ];
        }

        return $rows;
    }

    /**
     * Убивает зависшие scan-процессы (wall-time > $maxElapsedSec) и чистит их locks.
     *
     * @return list<array{pid:int,bot_id:?int,elapsed_sec:int,reason:string}>
     */
    public static function reapStaleScans(?int $maxElapsedSec = null): array
    {
        $maxElapsedSec ??= self::getScanMaxElapsedSec();
        $reaped = [];

        foreach (self::listRunningScans() as $row) {
            $pid = (int) $row['pid'];
            $metrics = ArtisanProcessFinder::readPidMetrics($pid);
            $elapsed = (int) ($metrics['elapsed_sec'] ?? 0);
            if ($elapsed < $maxElapsedSec) {
                continue;
            }

            $botId = $row['bot_id'] ?? null;
            ArtisanProcessFinder::terminatePids([$pid]);
            if (is_int($botId)) {
                self::clearLock(self::botLockName($botId));
            } else {
                self::clearLock(self::ALL_BOTS_LOCK);
            }

            $reaped[] = [
                'pid' => $pid,
                'bot_id' => $botId,
                'elapsed_sec' => $elapsed,
                'reason' => 'max_elapsed',
            ];
        }

        return $reaped;
    }

    /**
     * @param  list<mixed>  $items
     * @return list<mixed>
     */
    public static function takeShard(array $items, int $shardIndex, int $shardCount): array
    {
        $shardCount = max(1, $shardCount);
        $shardIndex = max(0, $shardIndex % $shardCount);
        if ($shardCount === 1) {
            return array_values($items);
        }
        $out = [];
        foreach (array_values($items) as $i => $item) {
            if ($i % $shardCount === $shardIndex) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * @return array{index:int,count:int}|null
     */
    public static function parseScanShard(?string $raw): ?array
    {
        $raw = trim((string) $raw);
        if (! preg_match('/^(\d+)\/(\d+)$/', $raw, $m)) {
            return null;
        }
        $count = (int) $m[2];
        $index = (int) $m[1];
        if ($count < 2 || $index < 0 || $index >= $count) {
            return null;
        }

        return ['index' => $index, 'count' => $count];
    }

    /**
     * Параллельный обход пар Impulse LVL: N процессов как SSID у 1min.
     *
     * @return array{processed:int,signals:int,trades:int}
     */
    public static function runImpulseShardedScan(int $botId, int $workers): array
    {
        $workers = max(2, min(8, $workers));
        $php = self::cliPhpForScan();
        $artisan = base_path('artisan');
        $processes = [];
        for ($i = 0; $i < $workers; $i++) {
            $cmd = sprintf(
                '%s %s bots:scan --bot_id=%d --scan-shard=%d/%d --active-only=1',
                escapeshellarg($php),
                escapeshellarg($artisan),
                $botId,
                $i,
                $workers
            );
            $proc = proc_open($cmd, [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes, base_path());
            if (! is_resource($proc)) {
                continue;
            }
            fclose($pipes[0]);
            $processes[] = ['proc' => $proc, 'out' => $pipes[1], 'err' => $pipes[2]];
        }

        $summary = ['processed' => 0, 'signals' => 0, 'trades' => 0];
        foreach ($processes as $row) {
            $stdout = stream_get_contents($row['out']) ?: '';
            fclose($row['out']);
            fclose($row['err']);
            proc_close($row['proc']);
            if (preg_match('/processed=(\d+)\s+signals=(\d+)\s+trades=(\d+)/', $stdout, $m)) {
                $summary['processed'] += (int) $m[1];
                $summary['signals'] += (int) $m[2];
                $summary['trades'] += (int) $m[3];
            }
        }

        return $summary;
    }

    private static function cliPhpForScan(): string
    {
        return ArtisanProcessFinder::cliPhpBinary();
    }
}
