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
 * Состояния точки у пары:
 * - gray  — ожидает проверки (по умолчанию и в очереди scan);
 * - red   — анализируется сейчас;
 * - green — уже проверена в текущем проходе scan.
 */
final class BotScanProgress
{
    /** Запасной TTL: длинный scan не должен терять состояние mid-run. */
    private const TTL_SECONDS = 7200;

    private static function cacheKey(int $botId): string
    {
        return "bot_scan_progress:{$botId}";
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
     * @return array{run_id: ?string, scan_active: bool, current: ?string, current_since: ?int, analyzed: list<string>, total: int}
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
        ];
    }

    /**
     * @return array{run_id: ?string, scan_active: bool, current: ?string, analyzed: list<string>, total: int}
     */
    private static function normalizeState(mixed $raw): array
    {
        // Совместимость со старым форматом: в cache лежала только строка instrument.
        if (is_string($raw) && $raw !== '') {
            return [
                'run_id' => null,
                'scan_active' => true,
                'current' => $raw,
                'analyzed' => [],
                'total' => 0,
            ];
        }

        if (! is_array($raw)) {
            return self::defaultState();
        }

        $analyzed = [];
        foreach ((array) ($raw['analyzed'] ?? []) as $item) {
            if (is_string($item) && $item !== '') {
                $analyzed[] = $item;
            }
        }

        $current = $raw['current'] ?? null;
        $runId = $raw['run_id'] ?? null;

        return [
            'run_id' => is_string($runId) && $runId !== '' ? $runId : null,
            'scan_active' => (bool) ($raw['scan_active'] ?? false),
            'current' => is_string($current) && $current !== '' ? $current : null,
            'current_since' => isset($raw['current_since']) ? (int) $raw['current_since'] : null,
            'analyzed' => $analyzed,
            'total' => max(0, (int) ($raw['total'] ?? 0)),
        ];
    }

    /**
     * @return array{scan_active: bool, current: ?string, analyzed: list<string>, total: int}
     */
    private static function readState(int $botId): array
    {
        return self::normalizeState(self::cache()->get(self::cacheKey($botId)));
    }

    /**
     * @param  array{scan_active: bool, current: ?string, analyzed: list<string>, total: int}  $state
     */
    private static function writeState(int $botId, array $state, bool $replace = false): void
    {
        if (! $replace) {
            $existing = self::readState($botId);

            // Защита от «битого» read-modify-write: не теряем total и уже проверенные пары.
            if ((int) ($state['total'] ?? 0) <= 0 && (int) ($existing['total'] ?? 0) > 0) {
                $state['total'] = (int) $existing['total'];
            }
            if (($state['run_id'] ?? null) === null && ($existing['run_id'] ?? null) !== null) {
                $state['run_id'] = $existing['run_id'];
            }
            if ($state['analyzed'] === [] && $existing['analyzed'] !== []) {
                $state['analyzed'] = $existing['analyzed'];
            }
            if (! ($state['scan_active'] ?? false) && ($existing['scan_active'] ?? false)) {
                $state['scan_active'] = true;
            }
        }

        self::cache()->put(self::cacheKey($botId), $state, self::TTL_SECONDS);

        // WS: сайдбар обновляет scan_dot на каждый writeState (без throttle).
        try {
            app(\App\Services\BotLiveEventsBroadcast::class)->publishScan($botId);
        } catch (\Throwable) {
            // ignore
        }
    }

    /** Scan активен, если явный флаг или есть current/analyzed (защита от потери scan_active). */
    private static function isScanActiveState(array $state): bool
    {
        return $state['scan_active']
            || $state['current'] !== null
            || $state['analyzed'] !== [];
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

    /** Новый проход scan: все пары снова серые, список проверенных пуст. */
    public static function beginScan(int $botId, int $totalInstruments): string
    {
        $runId = bin2hex(random_bytes(8));
        self::writeState($botId, [
            'run_id' => $runId,
            'scan_active' => true,
            'current' => null,
            'current_since' => null,
            'analyzed' => [],
            'total' => max(0, $totalInstruments),
        ], true);

        // Запоминаем старт отдельно от progress: endScan чистит progress, интервал dispatch — нет.
        self::markStarted($botId);

        return $runId;
    }

    /**
     * Конец scan. Если передан $runId, сбрасываем только свой проход
     * (старый параллельный scan не должен гасить счётчик нового).
     */
    public static function endScan(int $botId, ?string $runId = null): void
    {
        if ($runId !== null) {
            $state = self::readState($botId);
            if (($state['run_id'] ?? null) !== $runId) {
                return;
            }
        }

        self::cache()->forget(self::cacheKey($botId));

        // Финальный снимок — UI сразу гасит точки после endScan.
        try {
            app(\App\Services\BotLiveEventsBroadcast::class)->publishScan($botId);
        } catch (\Throwable) {
            // ignore
        }
    }

    public static function setCurrentInstrument(int $botId, string $instrument): void
    {
        $state = self::readState($botId);
        $state['scan_active'] = true;
        if ($state['current'] !== $instrument) {
            $state['current_since'] = time();
        }
        $state['current'] = $instrument;
        self::writeState($botId, $state);
    }

    /** Пара обработана: red → green в sidebar текущего scan. */
    public static function finishInstrument(int $botId, string $instrument): void
    {
        $state = self::readState($botId);
        $state['scan_active'] = true;
        if ($state['current'] === $instrument) {
            $state['current'] = null;
            $state['current_since'] = null;
        }
        if (! in_array($instrument, $state['analyzed'], true)) {
            $state['analyzed'][] = $instrument;
        }
        self::writeState($botId, $state);
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
            if ($state['current'] === $instrument) {
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
     *     analyzed_instruments: list<string>,
     *     scan_total: int,
     *     scan_remaining: ?int,
     *     scan_min_payout: ?float,
     *     analyzing_since: ?int
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
            'analyzing_since' => $state['current_since'] ?? null,
            'analyzed_instruments' => $state['analyzed'],
            'scan_total' => $total,
            'scan_remaining' => $remaining,
            'scan_min_payout' => $minPayout,
        ];
    }
}
