<?php

namespace App\Services\Bots;

use App\Models\Bot;
use App\Models\PocketInstrument;
use App\Support\BotInstrumentMarketScope;
use App\Support\ImpulseLvl1minConfig;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Redis-кэш закрытых M1+MTF для Impulse LVL (общий для 6 scan-шардов).
 *
 * seriesBundle ~1–2 с/пару из MySQL S5. Кэш: hit + Redis-partial ≈ десятки мс.
 * На ролловере M1: last_partial → append в closed без БД (фаза D).
 * HARD_TTL / пропуск бакетов → полный reload.
 */
final class ImpulseCandleSeriesCache
{
    /** Полный reload не чаще этого TTL (подтянуть HTF). Должен быть ≫ warm+scan (~60–90 с). */
    public const HARD_TTL_SEC = 300;

    private const KEY_PREFIX = 'impulse:series:';

    /**
     * @param  callable(): array{
     *     closed: list<array<string,mixed>>,
     *     partial: array<string,mixed>|null,
     *     series: list<array<string,mixed>>,
     *     mtf: array<string, list<array<string,mixed>>>
     * }  $loader
     * @param  array<string,mixed>|null  $partialOverride
     * @return array{
     *     closed: list<array<string,mixed>>,
     *     partial: array<string,mixed>|null,
     *     series: list<array<string,mixed>>,
     *     mtf: array<string, list<array<string,mixed>>>,
     *     cache_hit: bool,
     *     promoted: bool
     * }
     */
    public function resolve(
        string $instrument,
        int $count,
        array $tfToMaxBars,
        callable $loader,
        ?array $partialOverride = null,
        int $m1PeriodSec = 60,
        ?int $now = null,
    ): array {
        $now ??= time();
        $m1PeriodSec = max(1, $m1PeriodSec);
        $count = max(1, $count);
        $key = $this->key($instrument, $count, $tfToMaxBars);
        $entry = $this->read($key);
        $promoted = false;

        if ($entry !== null) {
            [$entry, $promoted] = $this->promoteClosedIfNeeded($entry, $partialOverride, $m1PeriodSec, $now, $count);
            if ($promoted) {
                $this->write($key, $entry);
            }
        }

        $needReload = $entry === null
            || ($now - (int) $entry['loaded_at']) >= self::HARD_TTL_SEC
            || $this->missedClosedBuckets($entry, $partialOverride, $m1PeriodSec);

        if ($needReload) {
            $packed = $loader();
            $closed = is_array($packed['closed'] ?? null) ? $packed['closed'] : [];
            $mtf = is_array($packed['mtf'] ?? null) ? $packed['mtf'] : [];
            $partial = $partialOverride ?? ($packed['partial'] ?? null);
            $lastClosed = $closed !== [] ? (int) end($closed)['time'] : 0;
            $entry = [
                'closed' => $closed,
                'mtf' => $mtf,
                'last_closed_time' => $lastClosed,
                'loaded_at' => $now,
                'last_partial' => is_array($partial) ? $this->normalizeBar($partial, true) : null,
                'count' => $count,
            ];
            $this->write($key, $entry);

            return $this->packResult($closed, $mtf, is_array($partial) ? $partial : null, false, false);
        }

        $closed = $entry['closed'];
        $mtf = $entry['mtf'];
        $partial = $partialOverride;
        if (is_array($partial)) {
            $lastClosed = (int) $entry['last_closed_time'];
            $pTime = (int) ($partial['time'] ?? 0);
            if ($pTime <= $lastClosed) {
                $partial = null;
            } else {
                $entry['last_partial'] = $this->normalizeBar($partial, true);
                $this->write($key, $entry);
            }
        }

        return $this->packResult($closed, $mtf, is_array($partial) ? $partial : null, true, $promoted);
    }

    /**
     * Прогрев кэша eligible-пар бота до spawn шардов (фаза C).
     * Если кэш уже горячий (≥80% hit на выборке) — пропускаем полный проход.
     *
     * @return array{warmed:int,hits:int,loads:int,ms:int,skipped:bool}
     */
    public function warmForBot(Bot $bot, BotDataService $botData): array
    {
        $t0 = hrtime(true);
        $config = is_array($bot->config) ? $bot->config : [];
        if (! ImpulseLvl1minConfig::is((string) $bot->type)) {
            return ['warmed' => 0, 'hits' => 0, 'loads' => 0, 'ms' => 0, 'skipped' => true];
        }

        $count = max(110, (int) ($config['count'] ?? 400));
        $staleAfterSec = max(5, (int) ($config['stale_after_sec'] ?? 30));
        $keepForming = ImpulseLvl1minConfig::flag($config, 'keep_stale_forming_m1', true);
        $mtfTake = ImpulseLvl1minConfig::mtfTakeMap($config);
        $minPayout = (float) ($config['min_payout'] ?? 80);

        $instruments = $this->eligibleInstruments($config, $minPayout);
        if ($instruments === []) {
            return ['warmed' => 0, 'hits' => 0, 'loads' => 0, 'ms' => 0, 'skipped' => true];
        }

        // Быстрая проба: 5 пар. Если все hit — кэш горячий, warm не нужен.
        $probe = array_slice($instruments, 0, min(5, count($instruments)));
        $probeHits = 0;
        foreach ($probe as $instrument) {
            $packed = $botData->impulseSeriesWithMtf(
                $instrument,
                $count,
                $mtfTake,
                true,
                $staleAfterSec,
                1,
                $keepForming,
            );
            if (! empty($packed['cache_hit'])) {
                $probeHits++;
            }
        }
        if ($probeHits === count($probe)) {
            $ms = (int) round((hrtime(true) - $t0) / 1e6);
            Log::info('impulse_series_cache.warm_skip_hot', [
                'bot_id' => $bot->id,
                'probe' => count($probe),
                'ms' => $ms,
            ]);

            return ['warmed' => 0, 'hits' => $probeHits, 'loads' => 0, 'ms' => $ms, 'skipped' => true];
        }

        $hits = $probeHits;
        $loads = count($probe) - $probeHits;
        $warmed = count($probe);
        $probed = array_fill_keys($probe, true);
        foreach ($instruments as $instrument) {
            if (isset($probed[$instrument])) {
                continue;
            }
            $packed = $botData->impulseSeriesWithMtf(
                $instrument,
                $count,
                $mtfTake,
                true,
                $staleAfterSec,
                1,
                $keepForming,
            );
            $warmed++;
            if (! empty($packed['cache_hit'])) {
                $hits++;
            } else {
                $loads++;
            }
        }

        $ms = (int) round((hrtime(true) - $t0) / 1e6);
        Log::info('impulse_series_cache.warm', [
            'bot_id' => $bot->id,
            'warmed' => $warmed,
            'hits' => $hits,
            'loads' => $loads,
            'ms' => $ms,
        ]);

        return ['warmed' => $warmed, 'hits' => $hits, 'loads' => $loads, 'ms' => $ms, 'skipped' => false];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function eligibleInstruments(array $config, float $minPayout): array
    {
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
        $out = [];
        foreach ($rows as $row) {
            $instrument = (string) $row->instrument;
            if ($allowed !== [] && ! isset($allowed[$instrument])) {
                continue;
            }
            if (isset($blocked[$instrument])) {
                continue;
            }
            if (! BotInstrumentMarketScope::allows($instrument, $marketScope)) {
                continue;
            }
            $payout = $row->current_payout !== null ? (float) $row->current_payout : null;
            if ($payout === null || $payout < $minPayout) {
                continue;
            }
            $out[] = $instrument;
        }

        return $out;
    }

    public function forget(string $instrument, int $count, array $tfToMaxBars): void
    {
        try {
            Redis::del($this->key($instrument, $count, $tfToMaxBars));
        } catch (Throwable) {
            // ignore
        }
    }

    /**
     * @param  array<int, int>  $tfToMaxBars
     */
    public function key(string $instrument, int $count, array $tfToMaxBars): string
    {
        ksort($tfToMaxBars);

        return self::KEY_PREFIX.$instrument.':'.$count.':'.md5((string) json_encode($tfToMaxBars));
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{0: array<string, mixed>, 1: bool}
     */
    private function promoteClosedIfNeeded(
        array $entry,
        ?array $partialOverride,
        int $m1PeriodSec,
        int $now,
        int $maxClosedBars,
    ): array {
        $prevPartial = is_array($entry['last_partial'] ?? null) ? $entry['last_partial'] : null;
        if ($prevPartial === null) {
            return [$entry, false];
        }

        $prevTime = (int) ($prevPartial['time'] ?? 0);
        $lastClosed = (int) ($entry['last_closed_time'] ?? 0);
        if ($prevTime <= 0 || $prevTime <= $lastClosed) {
            return [$entry, false];
        }

        // Бакет ещё открыт — не промоутим.
        if (($prevTime + $m1PeriodSec) > $now) {
            return [$entry, false];
        }

        $newPartialTime = is_array($partialOverride) ? (int) ($partialOverride['time'] ?? 0) : 0;
        // Если override всё ещё на том же бакете и он «закрыт» по wall — всё равно фиксируем.
        if ($newPartialTime > 0 && $newPartialTime < $prevTime) {
            return [$entry, false];
        }

        $closedBar = $this->normalizeBar($prevPartial, false);
        $closed = is_array($entry['closed'] ?? null) ? $entry['closed'] : [];
        $closed[] = $closedBar;
        if (count($closed) > $maxClosedBars) {
            $closed = array_values(array_slice($closed, -$maxClosedBars));
        }

        $entry['closed'] = $closed;
        $entry['last_closed_time'] = $prevTime;
        $entry['last_partial'] = is_array($partialOverride) && (int) ($partialOverride['time'] ?? 0) > $prevTime
            ? $this->normalizeBar($partialOverride, true)
            : null;

        return [$entry, true];
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function missedClosedBuckets(array $entry, ?array $partialOverride, int $m1PeriodSec): bool
    {
        if (! is_array($partialOverride)) {
            return false;
        }
        $pTime = (int) ($partialOverride['time'] ?? 0);
        $lastClosed = (int) ($entry['last_closed_time'] ?? 0);
        if ($pTime <= 0 || $lastClosed <= 0) {
            return false;
        }

        // Partial ушёл вперёд больше чем на 2 бакета — кэш пропустил закрытия.
        return ($pTime - $lastClosed) > ($m1PeriodSec * 2);
    }

    /**
     * @param  list<array<string,mixed>>  $closed
     * @param  array<string, list<array<string,mixed>>>  $mtf
     * @return array{
     *     closed: list<array<string,mixed>>,
     *     partial: array<string,mixed>|null,
     *     series: list<array<string,mixed>>,
     *     mtf: array<string, list<array<string,mixed>>>,
     *     cache_hit: bool,
     *     promoted: bool
     * }
     */
    private function packResult(array $closed, array $mtf, ?array $partial, bool $cacheHit, bool $promoted): array
    {
        $series = $closed;
        if (is_array($partial)) {
            $series[] = $partial;
        }

        return [
            'closed' => $closed,
            'partial' => $partial,
            'series' => $series,
            'mtf' => $mtf,
            'cache_hit' => $cacheHit,
            'promoted' => $promoted,
        ];
    }

    /**
     * @param  array<string, mixed>  $bar
     * @return array{time:int,open:float,high:float,low:float,close:float,volume:float,is_partial:bool}
     */
    private function normalizeBar(array $bar, bool $isPartial): array
    {
        return [
            'time' => (int) ($bar['time'] ?? 0),
            'open' => (float) ($bar['open'] ?? 0),
            'high' => (float) ($bar['high'] ?? 0),
            'low' => (float) ($bar['low'] ?? 0),
            'close' => (float) ($bar['close'] ?? 0),
            'volume' => (float) ($bar['volume'] ?? 0),
            'is_partial' => $isPartial,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function read(string $key): ?array
    {
        try {
            $raw = Redis::get($key);
        } catch (Throwable) {
            return null;
        }
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded) || ! isset($decoded['closed'], $decoded['mtf'], $decoded['loaded_at'])) {
            return null;
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function write(string $key, array $entry): void
    {
        try {
            $json = json_encode($entry, JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                return;
            }
            // TTL > HARD_TTL: запись живёт до следующего reload/miss.
            Redis::setex($key, self::HARD_TTL_SEC + 60, $json);
        } catch (Throwable) {
            // ignore
        }
    }
}
