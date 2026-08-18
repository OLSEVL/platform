<?php

namespace App\Services\Bots;

use App\Services\CandleService;
use App\Services\CurrentCandleCacheService;

class BotDataService
{
    public function __construct(
        private readonly CurrentCandleCacheService $currentCandleCache,
        private readonly CandleService $candleService,
    ) {}

    /**
     * @return array{closed:list<array<string,mixed>>,partial:array<string,mixed>|null,series:list<array<string,mixed>>}
     */
    public function series(
        string $instrument,
        int $count = 120,
        bool $includePartial = true,
        int $staleAfterSec = 30,
        int $timeframe = 1
    ): array
    {
        // Таймфрейм задается ботом через runtime-настройку:
        // - 1    => классический M1 поток,
        // - 4005 => секундный S5 поток для быстрых стратегий.
        // Источник единый через CandleService, чтобы график и бот видели одинаковые свечи.
        $rows = $this->candleService->seriesForDashboard($instrument, $timeframe, null, null, $count);

        $closed = array_map(static fn (array $r) => [
            'time' => (int) ($r['time'] ?? 0),
            'open' => (float) ($r['open'] ?? 0),
            'high' => (float) ($r['high'] ?? 0),
            'low' => (float) ($r['low'] ?? 0),
            'close' => (float) ($r['close'] ?? 0),
            'volume' => (float) ($r['volume'] ?? 0),
            'is_partial' => false,
        ], $rows);

        $partial = null;
        if ($includePartial) {
            $partialCandidate = $this->currentCandleCache->getPartial($instrument);
            if ($partialCandidate && $this->currentCandleCache->isFresh($partialCandidate, $staleAfterSec)) {
                $lastClosedTime = ! empty($closed) ? (int) end($closed)['time'] : 0;
                if ((int) $partialCandidate['time'] > $lastClosedTime) {
                    $partial = $partialCandidate;
                }
            }
        }

        $series = $closed;
        if ($partial) {
            $series[] = $partial;
        }

        return [
            'closed' => $closed,
            'partial' => $partial,
            'series' => $series,
        ];
    }

    /**
     * Секундный ряд S5 (timeframe=4005) из БД + живая S5-свеча из Redis (ingest-current-candle).
     *
     * @return array{closed:list<array<string,mixed>>,partial:array<string,mixed>|null,series:list<array<string,mixed>>}
     */
    public function s5Series(
        string $instrument,
        int $count = 240,
        bool $includePartial = true,
        int $staleAfterSec = 30,
    ): array {
        $rows = $this->candleService->seriesForDashboard(
            $instrument,
            CandleService::TF_S5,
            null,
            null,
            $count,
        );

        $closed = array_map(static fn (array $r) => [
            'time' => (int) ($r['time'] ?? 0),
            'open' => (float) ($r['open'] ?? 0),
            'high' => (float) ($r['high'] ?? 0),
            'low' => (float) ($r['low'] ?? 0),
            'close' => (float) ($r['close'] ?? 0),
            'volume' => (float) ($r['volume'] ?? 0),
            'is_partial' => false,
        ], $rows);

        $partial = null;
        if ($includePartial) {
            $partialCandidate = $this->currentCandleCache->getPartial($instrument);
            if ($partialCandidate && $this->currentCandleCache->isFresh($partialCandidate, $staleAfterSec)) {
                $normalized = $this->normalizePartialBar($partialCandidate);
                if ($closed === []) {
                    $partial = $normalized;
                } else {
                    $lastClosedTime = (int) end($closed)['time'];
                    $partialTime = (int) $normalized['time'];
                    if ($partialTime > $lastClosedTime) {
                        $partial = $normalized;
                    } elseif ($partialTime === $lastClosedTime) {
                        // Текущий S5-бакет уже попал в БД — обновляем OHLC живым partial.
                        $closed[array_key_last($closed)] = $normalized;
                    }
                }
            }
        }

        $series = $closed;
        if ($partial) {
            $series[] = $partial;
        }

        return [
            'closed' => $closed,
            'partial' => $partial,
            'series' => $series,
        ];
    }

    /**
     * @param  array<string, mixed>  $partial
     * @return array{time:int,open:float,high:float,low:float,close:float,volume:float,is_partial:bool}
     */
    private function normalizePartialBar(array $partial): array
    {
        return [
            'time' => (int) ($partial['time'] ?? 0),
            'open' => (float) ($partial['open'] ?? 0),
            'high' => (float) ($partial['high'] ?? 0),
            'low' => (float) ($partial['low'] ?? 0),
            'close' => (float) ($partial['close'] ?? 0),
            'volume' => (float) ($partial['volume'] ?? 0),
            'is_partial' => true,
        ];
    }

    /**
     * M1 (или другой входной ТФ) плюс M5/M15/H1 из одной выборки S5.
     *
     * @param  array<int, int>  $tfToMaxBars
     * @return array{
     *     closed:list<array<string,mixed>>,
     *     partial:array<string,mixed>|null,
     *     series:list<array<string,mixed>>,
     *     mtf:array<string, list<array{time:int,open:float,high:float,low:float,close:float,volume:float}>>
     * }
     */
    public function seriesWithMtf(
        string $instrument,
        int $count,
        array $tfToMaxBars,
        bool $includePartial = true,
        int $staleAfterSec = 30,
        int $timeframe = 1,
    ): array {
        $map = $tfToMaxBars;
        $map[$timeframe] = $count;
        $bundle = $this->candleService->seriesBundle($instrument, $map);
        $rows = $bundle[(string) $timeframe] ?? [];

        $closed = array_map(static fn (array $r) => [
            'time' => (int) ($r['time'] ?? 0),
            'open' => (float) ($r['open'] ?? 0),
            'high' => (float) ($r['high'] ?? 0),
            'low' => (float) ($r['low'] ?? 0),
            'close' => (float) ($r['close'] ?? 0),
            'volume' => (float) ($r['volume'] ?? 0),
            'is_partial' => false,
        ], $rows);

        $partial = null;
        if ($includePartial) {
            $partialCandidate = $this->currentCandleCache->getPartial($instrument);
            if ($partialCandidate && $this->currentCandleCache->isFresh($partialCandidate, $staleAfterSec)) {
                $lastClosedTime = ! empty($closed) ? (int) end($closed)['time'] : 0;
                if ((int) $partialCandidate['time'] > $lastClosedTime) {
                    $partial = $partialCandidate;
                }
            }
        }

        $series = $closed;
        if ($partial) {
            $series[] = $partial;
        }

        return [
            'closed' => $closed,
            'partial' => $partial,
            'series' => $series,
            'mtf' => $this->normalizeMtfBundle($bundle),
        ];
    }

    /**
     * Агрегированные свечи для MTF-стратегий. Все ТФ — из одной выборки S5.
     *
     * @param  array<int, int>  $tfToMaxBars
     * @return array<string, list<array{time:int,open:float,high:float,low:float,close:float,volume:float}>>
     */
    public function mtfSeries(string $instrument, array $tfToMaxBars): array
    {
        return $this->normalizeMtfBundle($this->candleService->seriesBundle($instrument, $tfToMaxBars));
    }

    /**
     * @param  array<string, list<array{time:int,open:float,high:float,low:float,close:float,volume:float}>>  $bundle
     * @return array<string, list<array{time:int,open:float,high:float,low:float,close:float,volume:float}>>
     */
    private function normalizeMtfBundle(array $bundle): array
    {
        $out = [];
        foreach ($bundle as $tf => $rows) {
            $out[(string) $tf] = array_map(static fn (array $c) => [
                'time' => (int) $c['time'],
                'open' => (float) $c['open'],
                'high' => (float) $c['high'],
                'low' => (float) $c['low'],
                'close' => (float) $c['close'],
                'volume' => (float) ($c['volume'] ?? 0),
            ], $rows);
        }

        return $out;
    }
}
