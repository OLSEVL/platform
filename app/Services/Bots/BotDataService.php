<?php

namespace App\Services\Bots;

use App\Models\InstrumentCandle;
use App\Services\CurrentCandleCacheService;

class BotDataService
{
    public function __construct(
        private readonly CurrentCandleCacheService $currentCandleCache,
    ) {}

    /**
     * @return array{closed:list<array<string,mixed>>,partial:array<string,mixed>|null,series:list<array<string,mixed>>}
     */
    public function series(string $instrument, int $count = 120, bool $includePartial = true, int $staleAfterSec = 30): array
    {
        $rows = InstrumentCandle::query()
            ->forInstrument($instrument)
            ->forTimeframe(1)
            ->orderByDesc('time')
            ->limit($count)
            ->get(['time', 'open', 'high', 'low', 'close', 'volume']);

        $closed = $rows->map(fn (InstrumentCandle $r) => [
            'time' => (int) $r->time,
            'open' => (float) $r->open,
            'high' => (float) $r->high,
            'low' => (float) $r->low,
            'close' => (float) $r->close,
            'volume' => (float) ($r->volume ?? 0),
            'is_partial' => false,
        ])->sortBy('time')->values()->all();

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
}
