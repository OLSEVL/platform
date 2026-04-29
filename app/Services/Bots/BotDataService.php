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
    public function series(string $instrument, int $count = 120, bool $includePartial = true, int $staleAfterSec = 30): array
    {
        // Для ботов M1 должен быть тем же, что видит график: источник единый через CandleService.
        // CandleService сам выберет S5->M1 агрегацию или legacy fallback.
        $rows = $this->candleService->seriesForDashboard($instrument, 1, null, null, $count);

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
     * Агрегированные свечи для MTF-стратегий (из M1 в БД). Ключи — минуты таймфрейма.
     *
     * @param  array<int, int>  $tfToMaxBars
     * @return array<string, list<array{time:int,open:float,high:float,low:float,close:float,volume:float}>>
     */
    public function mtfSeries(string $instrument, array $tfToMaxBars): array
    {
        $out = [];
        foreach ($tfToMaxBars as $minutes => $maxBars) {
            $minutes = (int) $minutes;
            if (! CandleService::isDashboardTimeframe($minutes)) {
                continue;
            }
            $rows = $this->candleService->seriesForDashboard($instrument, $minutes, null, null, (int) $maxBars);
            $out[(string) $minutes] = array_map(static fn (array $c) => [
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
