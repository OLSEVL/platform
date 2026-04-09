<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InstrumentCandle;
use App\Models\PocketInstrument;
use App\Services\CurrentCandleCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProxyInstrumentController extends Controller
{
    public function instruments(): JsonResponse
    {
        $list = PocketInstrument::active()->orderByPriority()->get()
            ->map(fn (PocketInstrument $r) => [
                'instrument' => $r->instrument,
                'display_name' => $r->display_name,
                'priority' => $r->priority,
                'session_token' => $r->session_token,
                'uid' => $r->uid,
                'is_demo' => $r->is_demo,
                'proxy_url' => $r->proxy_url,
                'current_payout' => $r->current_payout,
                'last_payout_at' => optional($r->last_payout_at)?->toIso8601String(),
                'payout_source' => $r->payout_source,
            ]);
        return response()->json($list->values()->all());
    }

    public function ingestCandles(Request $request): JsonResponse
    {
        $request->validate([
            'instrument' => 'required|string|max:63',
            'timeframe' => 'required|integer|in:1',
            'candles' => 'required|array',
            'candles.*.time' => 'required|numeric',
            'candles.*.open' => 'required|numeric',
            'candles.*.high' => 'required|numeric',
            'candles.*.low' => 'required|numeric',
            'candles.*.close' => 'required|numeric',
            'candles.*.volume' => 'nullable|numeric',
        ]);
        $instrument = $request->input('instrument');
        $timeframe = (int) $request->input('timeframe');
        foreach ($request->input('candles') as $c) {
            $time = (int) $c['time'];
            InstrumentCandle::updateOrCreate(
                ['instrument' => $instrument, 'timeframe' => $timeframe, 'time' => $time],
                ['open' => (float) $c['open'], 'high' => (float) $c['high'], 'low' => (float) $c['low'], 'close' => (float) $c['close'], 'volume' => (float) ($c['volume'] ?? 0)]
            );
        }
        return response()->json(['ok' => true, 'count' => count($request->input('candles'))]);
    }

    public function ingestPayout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'instrument' => 'required|string|max:63',
            'current_payout' => 'nullable|numeric|min:0|max:100',
            'last_payout_at' => 'nullable|date',
            'payout_source' => 'nullable|string|max:32',
            'is_stale' => 'nullable|boolean',
        ]);

        $instrument = PocketInstrument::query()->where('instrument', $data['instrument'])->first();
        if (!$instrument) {
            return response()->json(['ok' => false, 'error' => 'instrument_not_found'], 404);
        }

        $instrument->update([
            'current_payout' => $data['current_payout'] ?? null,
            'last_payout_at' => $data['last_payout_at'] ?? null,
            'payout_source' => $data['payout_source'] ?? null,
        ]);

        return response()->json([
            'ok' => true,
            'instrument' => $instrument->instrument,
            'is_stale' => (bool)($data['is_stale'] ?? false),
        ]);
    }

    public function ingestCurrentCandle(Request $request, CurrentCandleCacheService $cache): JsonResponse
    {
        $data = $request->validate([
            'instrument' => 'required|string|max:63',
            'time' => 'required|integer|min:1',
            'open' => 'required|numeric',
            'high' => 'required|numeric',
            'low' => 'required|numeric',
            'close' => 'required|numeric',
            'volume' => 'nullable|numeric',
            'updated_at' => 'nullable|integer|min:1',
            'ttl_sec' => 'nullable|integer|min:30|max:600',
        ]);

        $ttlSec = (int) ($data['ttl_sec'] ?? 180);
        $cache->putPartial($data['instrument'], $data, $ttlSec);

        return response()->json([
            'ok' => true,
            'instrument' => $data['instrument'],
            'time' => (int) $data['time'],
            'is_partial' => true,
        ]);
    }
}
