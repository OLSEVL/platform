<?php

namespace App\Services\Bots;

/**
 * In-process кэш закрытых M1+MTF для Impulse live (как SymbolState у updateBotM1).
 *
 * seriesBundle ~1–2 с/пару из MySQL. Здесь закрытые бары живут в RAM процесса;
 * между тиками подменяется только Redis-partial; на ролловере M1 — promote без БД.
 */
final class ImpulseSeriesMemoryCache
{
    /** Полный reload не чаще этого TTL (подтянуть HTF). */
    private const HARD_TTL_SEC = 300;

    /**
     * @var array<string, array{
     *     closed: list<array<string,mixed>>,
     *     mtf: array<string, list<array<string,mixed>>>,
     *     last_closed_time: int,
     *     loaded_at: int,
     *     last_partial: array<string,mixed>|null
     * }>
     */
    private array $entries = [];

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
        callable $loader,
        ?array $partialOverride = null,
        int $m1PeriodSec = 60,
        ?int $now = null,
        int $count = 400,
    ): array {
        $now ??= time();
        $m1PeriodSec = max(1, $m1PeriodSec);
        $count = max(1, $count);
        $entry = $this->entries[$instrument] ?? null;
        $promoted = false;

        if ($entry !== null) {
            [$entry, $promoted] = $this->promoteClosedIfNeeded($entry, $partialOverride, $m1PeriodSec, $now, $count);
            if ($promoted) {
                $this->entries[$instrument] = $entry;
            }
        }

        // closedBucketOverdue: для S15/S30 без Redis-partial (promote выключен) иначе
        // ряд обновлялся бы только раз в HARD_TTL (5 мин) → почти ноль сигналов на last bar.
        $needReload = $entry === null
            || ($now - (int) $entry['loaded_at']) >= self::HARD_TTL_SEC
            || $this->missedClosedBuckets($entry, $partialOverride, $m1PeriodSec)
            || $this->closedBucketOverdue($entry, $m1PeriodSec, $now);

        if ($needReload) {
            $packed = $loader();
            $closed = is_array($packed['closed'] ?? null) ? $packed['closed'] : [];
            $mtf = is_array($packed['mtf'] ?? null) ? $packed['mtf'] : [];
            $partial = $partialOverride ?? ($packed['partial'] ?? null);
            $lastClosed = $closed !== [] ? (int) end($closed)['time'] : 0;
            $this->entries[$instrument] = [
                'closed' => $closed,
                'mtf' => $mtf,
                'last_closed_time' => $lastClosed,
                'loaded_at' => $now,
                'last_partial' => is_array($partial) ? $this->normalizeBar($partial, true) : null,
            ];

            return $this->pack($closed, $mtf, is_array($partial) ? $partial : null, false, false);
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
                $this->entries[$instrument] = $entry;
            }
        }

        return $this->pack($closed, $mtf, is_array($partial) ? $partial : null, true, $promoted);
    }

    /** Последний сложенный forming-бар пары (для склейки S5→S15 между poll'ами). */
    public function lastPartial(string $instrument): ?array
    {
        $bar = $this->entries[$instrument]['last_partial'] ?? null;

        return is_array($bar) ? $bar : null;
    }

    public function forget(string $instrument): void
    {
        unset($this->entries[$instrument]);
    }

    public function clear(): void
    {
        $this->entries = [];
    }

    public function size(): int
    {
        return count($this->entries);
    }

    /**
     * @param  array<string,mixed>  $entry
     * @param  array<string,mixed>|null  $partial
     * @return array{0: array<string,mixed>, 1: bool}
     */
    private function promoteClosedIfNeeded(array $entry, ?array $partial, int $m1PeriodSec, int $now, int $count): array
    {
        $lastPartial = is_array($entry['last_partial'] ?? null) ? $entry['last_partial'] : null;
        if ($lastPartial === null) {
            return [$entry, false];
        }
        $lpTime = (int) ($lastPartial['time'] ?? 0);
        if ($lpTime <= 0) {
            return [$entry, false];
        }
        // Бакет last_partial уже закрыт по wall-clock → append в closed.
        if ($now < $lpTime + $m1PeriodSec) {
            return [$entry, false];
        }
        $lastClosed = (int) ($entry['last_closed_time'] ?? 0);
        if ($lpTime <= $lastClosed) {
            $entry['last_partial'] = null;

            return [$entry, false];
        }

        $bar = $this->normalizeBar($lastPartial, false);
        $closed = $entry['closed'];
        $closed[] = $bar;
        if (count($closed) > $count) {
            $closed = array_slice($closed, -$count);
        }
        $entry['closed'] = $closed;
        $entry['last_closed_time'] = $lpTime;
        $entry['last_partial'] = null;

        return [$entry, true];
    }

    /**
     * @param  array<string,mixed>  $entry
     * @param  array<string,mixed>|null  $partial
     */
    private function missedClosedBuckets(array $entry, ?array $partial, int $m1PeriodSec): bool
    {
        if (! is_array($partial)) {
            return false;
        }
        $pTime = (int) ($partial['time'] ?? 0);
        $lastClosed = (int) ($entry['last_closed_time'] ?? 0);
        if ($pTime <= 0 || $lastClosed <= 0) {
            return false;
        }
        // Пропущен ≥2 M1-бакета — нужен reload из БД.
        return ($pTime - $lastClosed) >= (2 * $m1PeriodSec);
    }

    /**
     * Wall-clock: после last_closed уже должен существовать ещё один полностью
     * закрытый бакет (open = last_closed + period). Порог = last + 2*period,
     * иначе внутри текущего формирующегося бара был бы reload на каждом тике.
     * Нужен для S15/S30, где Redis-partial не совпадает с периодом стратегии.
     *
     * @param  array<string,mixed>  $entry
     */
    private function closedBucketOverdue(array $entry, int $periodSec, int $now): bool
    {
        $lastClosed = (int) ($entry['last_closed_time'] ?? 0);
        if ($lastClosed <= 0 || $periodSec <= 0) {
            return false;
        }

        return $now >= ($lastClosed + (2 * $periodSec));
    }

    /**
     * @param  array<string,mixed>  $bar
     * @return array<string,mixed>
     */
    private function normalizeBar(array $bar, bool $partial): array
    {
        return [
            'time' => (int) ($bar['time'] ?? 0),
            'open' => (float) ($bar['open'] ?? 0),
            'high' => (float) ($bar['high'] ?? 0),
            'low' => (float) ($bar['low'] ?? 0),
            'close' => (float) ($bar['close'] ?? 0),
            'volume' => (float) ($bar['volume'] ?? 0),
            'is_partial' => $partial,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $closed
     * @param  array<string, list<array<string,mixed>>>  $mtf
     * @param  array<string,mixed>|null  $partial
     * @return array{
     *     closed: list<array<string,mixed>>,
     *     partial: array<string,mixed>|null,
     *     series: list<array<string,mixed>>,
     *     mtf: array<string, list<array<string,mixed>>>,
     *     cache_hit: bool,
     *     promoted: bool
     * }
     */
    private function pack(array $closed, array $mtf, ?array $partial, bool $hit, bool $promoted): array
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
            'cache_hit' => $hit,
            'promoted' => $promoted,
        ];
    }
}
