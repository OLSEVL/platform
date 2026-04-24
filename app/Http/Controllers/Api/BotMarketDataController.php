<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InstrumentCandle;
use App\Services\CurrentCandleCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BotMarketDataController extends Controller
{
    public function series(Request $request, CurrentCandleCacheService $currentCandleCache): JsonResponse
    {
        $data = $request->validate([
            'instrument' => 'required|string|max:63',
            'count' => 'nullable|integer|min:5|max:500',
            'stale_after_sec' => 'nullable|integer|min:5|max:300',
        ]);

        $instrument = $data['instrument'];
        $count = (int) ($data['count'] ?? 120);
        $staleAfterSec = (int) ($data['stale_after_sec'] ?? 30);

        $rows = InstrumentCandle::query()
            ->forInstrument($instrument)
            ->forTimeframe(1)
            ->orderByDesc('time')
            ->limit($count)
            ->get(['time', 'open', 'high', 'low', 'close', 'volume']);

        $closed = $rows->map(fn (InstrumentCandle $r) => [
            // UTC timeline без ручных поправок:
            // отдаём точное время свечи в том виде, в котором оно хранится в БД.
            'time' => (int) $r->time,
            'open' => (float) $r->open,
            'high' => (float) $r->high,
            'low' => (float) $r->low,
            'close' => (float) $r->close,
            'volume' => (float) ($r->volume ?? 0),
            'is_partial' => false,
        ])->sortBy('time')->values()->all();

        $partial = $currentCandleCache->getPartial($instrument);
        if ($partial && !$currentCandleCache->isFresh($partial, $staleAfterSec)) {
            $partial = null;
        }

        if ($partial && !empty($closed)) {
            $lastClosedTime = (int) end($closed)['time'];
            // Для partial свечи применяем ту же UTC-политику: без корректировок timestamp.
            $partialTime = (int) ($partial['time'] ?? 0);
            if ($partialTime <= $lastClosedTime) {
                $partial = null;
            } else {
                $partial['time'] = $partialTime;
            }
        }

        $series = $closed;
        if ($partial) {
            $series[] = $partial;
        }

        return response()->json([
            'instrument' => $instrument,
            'closed_count' => count($closed),
            'has_partial' => $partial !== null,
            'partial' => $partial,
            'series' => $series,
        ]);
    }
}

