<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InstrumentCandle;
use App\Models\PocketInstrument;
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
}
