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
    /** Длинный scan не должен терять lock mid-run (до 6 ч). */
    public const LOCK_TTL_SEC = 21600;

    /** Lock для ручного прохода всех ботов в одном процессе (без --bot_id). */
    public const ALL_BOTS_LOCK = 'bots:scan:all';

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

        // «Полный» scan без --bot_id (legacy / ручной проход всех ботов в одном процессе).
        return $cmdBotId === null;
    }

    /**
     * @return Collection<int, Bot>
     */
    public static function scannableBots(): Collection
    {
        return Bot::query()
            ->where('is_active', true)
            ->where('type', '!=', 'openrouter_llm_analyst')
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
        return ArtisanProcessFinder::spawnBackground(
            [ArtisanProcessFinder::cliPhpBinary(), 'artisan', 'bots:scan', '--bot_id='.$botId],
            self::logPathForBot($botId),
        );
    }

    public static function spawnDispatchAll(): int
    {
        $started = 0;
        foreach (self::scannableBots() as $bot) {
            if (self::isScanRunning($bot->id)) {
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
}
