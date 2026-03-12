<?php

namespace App\Http\Controllers;

use App\Models\InstrumentCandle;
use App\Models\PocketInstrument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $instruments = PocketInstrument::active()->orderByPriority()->get(['id', 'instrument', 'display_name']);
        return view('dashboard', ['instruments' => $instruments]);
    }

    public function candles(Request $request): JsonResponse
    {
        $request->validate([
            'instrument' => 'required|string|max:63',
            'timeframe' => 'nullable|integer|in:1',
            'limit' => 'nullable|integer|min:1|max:1000',
            'before' => 'nullable|integer', // unix time, load candles before this (for scroll back)
        ]);
        $instrument = $request->input('instrument');
        $timeframe = (int) ($request->input('timeframe') ?? 1);
        $limit = (int) ($request->input('limit') ?? 200);
        $before = $request->input('before');

        $query = InstrumentCandle::forInstrument($instrument)
            ->forTimeframe($timeframe)
            ->orderByDesc('time');

        if ($before !== null) {
            $query->where('time', '<', (int) $before);
        }

        $candles = $query->limit($limit)->get(['time', 'open', 'high', 'low', 'close', 'volume']);

        $data = $candles->map(fn ($c) => [
            'time' => $c->time,
            'open' => round($c->open, 5),
            'high' => round($c->high, 5),
            'low' => round($c->low, 5),
            'close' => round($c->close, 5),
            'volume' => round($c->volume ?? 0, 2),
        ])->values()->all();

        return response()->json(['candles' => $data]);
    }
}
