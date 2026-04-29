<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InstrumentCandle;
use App\Services\CandleService;
use App\Services\CurrentCandleCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BotMarketDataController extends Controller
{
    public function series(
        Request $request,
        CurrentCandleCacheService $currentCandleCache,
        CandleService $candleService
    ): JsonResponse
    {
        $data = $request->validate([
            'instrument' => 'required|string|max:63',
            'count' => 'nullable|integer|min:5|max:500',
            'stale_after_sec' => 'nullable|integer|min:5|max:300',
        ]);

        $instrument = $data['instrument'];
        $count = (int) ($data['count'] ?? 120);
        $staleAfterSec = (int) ($data['stale_after_sec'] ?? 30);

        // Единый M1-источник через CandleService: приоритетно из S5, fallback на legacy M1.
        $rows = $candleService->seriesForDashboard($instrument, 1, null, null, $count);
        $closed = array_map(static fn (array $r) => [
            // UTC timeline без ручных поправок:
            // отдаём точное время свечи в том виде, в котором оно хранится в БД.
            'time' => (int) ($r['time'] ?? 0),
            'open' => (float) ($r['open'] ?? 0),
            'high' => (float) ($r['high'] ?? 0),
            'low' => (float) ($r['low'] ?? 0),
            'close' => (float) ($r['close'] ?? 0),
            'volume' => (float) ($r['volume'] ?? 0),
            'is_partial' => false,
        ], $rows);

        $partial = $currentCandleCache->getPartial($instrument);
        if ($partial && !$currentCandleCache->isFresh($partial, $staleAfterSec)) {
            $partial = null;
        }

        if ($partial && !empty($closed)) {
            $lastClosedTime = (int) end($closed)['time'];
            $liveM1Partial = $this->buildLiveM1PartialFromS5($instrument, $partial);
            if ($liveM1Partial === null) {
                $partial = null;
            } else {
                $liveTime = (int) $liveM1Partial['time'];
                if ($liveTime > $lastClosedTime) {
                    $partial = $liveM1Partial;
                } elseif ($liveTime === $lastClosedTime) {
                    // Режим replace: текущая минута уже есть в closed-ряду, поэтому
                    // обновляем её live-partial'ом вместо создания "новой" свечи.
                    $closed[array_key_last($closed)] = $liveM1Partial;
                    $partial = null;
                } else {
                    $partial = null;
                }
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

    /**
     * M1 partial как "живая минутка": агрегируем закрытые S5 в текущей минуте
     * и обновляем их текущим S5 partial.
     *
     * @param  array<string,mixed>  $partial
     * @return array{time:int,open:float,high:float,low:float,close:float,volume:float,is_partial:bool}|null
     */
    private function buildLiveM1PartialFromS5(string $instrument, array $partial): ?array
    {
        $partialRawTime = (int) ($partial['time'] ?? 0);
        if ($partialRawTime <= 0) {
            return null;
        }
        $minuteStart = (int) (floor($partialRawTime / 60) * 60);
        $minuteEnd = $minuteStart + 60;
        $s5Rows = InstrumentCandle::query()
            ->forInstrument($instrument)
            ->forTimeframe(CandleService::TF_S5)
            ->where('time', '>=', $minuteStart)
            ->where('time', '<', $minuteEnd)
            ->orderBy('time')
            ->get(['time', 'open', 'high', 'low', 'close', 'volume']);

        $barsByTs = [];
        foreach ($s5Rows as $row) {
            $barsByTs[(int) $row->time] = [
                'time' => (int) $row->time,
                'open' => (float) $row->open,
                'high' => (float) $row->high,
                'low' => (float) $row->low,
                'close' => (float) $row->close,
                'volume' => (float) ($row->volume ?? 0),
            ];
        }
        $barsByTs[$partialRawTime] = [
            'time' => $partialRawTime,
            'open' => (float) ($partial['open'] ?? 0),
            'high' => (float) ($partial['high'] ?? 0),
            'low' => (float) ($partial['low'] ?? 0),
            'close' => (float) ($partial['close'] ?? 0),
            'volume' => (float) ($partial['volume'] ?? 0),
        ];
        if ($barsByTs === []) {
            return null;
        }
        ksort($barsByTs);
        $bars = array_values($barsByTs);
        $open = (float) $bars[0]['open'];
        $high = max(array_map(static fn (array $b): float => (float) $b['high'], $bars));
        $low = min(array_map(static fn (array $b): float => (float) $b['low'], $bars));
        $close = (float) $bars[array_key_last($bars)]['close'];
        $volume = array_sum(array_map(static fn (array $b): float => (float) ($b['volume'] ?? 0), $bars));

        return [
            'time' => $minuteStart,
            'open' => $open,
            'high' => $high,
            'low' => $low,
            'close' => $close,
            'volume' => $volume,
            'is_partial' => true,
        ];
    }
}

