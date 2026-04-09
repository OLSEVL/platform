<?php

namespace App\Http\Controllers;

use App\Models\PocketInstrument;
use App\Services\CandleService;
use App\Services\CandleStructureZoneDetector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $instruments = PocketInstrument::active()
            ->orderByDesc('current_payout')
            ->orderByDesc('priority')
            ->orderBy('instrument')
            ->get([
            'id',
            'instrument',
            'display_name',
            'current_payout',
            ]);
        $timeframes = [
            ['minutes' => 1, 'label' => '1м'],
            ['minutes' => 2, 'label' => '2м'],
            ['minutes' => 3, 'label' => '3м'],
            ['minutes' => 5, 'label' => '5м'],
            ['minutes' => 10, 'label' => '10м'],
            ['minutes' => 15, 'label' => '15м'],
            ['minutes' => 30, 'label' => '30м'],
            ['minutes' => 60, 'label' => '1ч'],
            ['minutes' => 240, 'label' => '4ч'],
        ];

        return view('dashboard', [
            'instruments' => $instruments,
            'timeframes' => $timeframes,
        ]);
    }

    public function candles(Request $request, CandleService $candleService): JsonResponse
    {
        $request->validate([
            'instrument' => 'required|string|max:63',
            'timeframe' => ['nullable', 'integer', Rule::in(CandleService::DASHBOARD_TIMEFRAMES)],
            'limit' => 'nullable|integer|min:1|max:2000',
            'before' => 'nullable|integer',
            'since' => 'nullable|integer|min:0',
        ]);
        $instrument = $request->input('instrument');
        $timeframe = (int) ($request->input('timeframe') ?? 1);
        $limit = (int) ($request->input('limit') ?? 1440);
        $before = $request->input('before') !== null ? (int) $request->input('before') : null;
        $since = $request->input('since') !== null ? (int) $request->input('since') : null;

        if ($timeframe !== 1 && $before !== null) {
            return response()->json(['candles' => []]);
        }

        $rows = $candleService->seriesForDashboard(
            $instrument,
            $timeframe,
            $since,
            $before,
            $limit,
        );

        $data = array_map(fn (array $c) => [
            'time' => $c['time'],
            'open' => round($c['open'], 5),
            'high' => round($c['high'], 5),
            'low' => round($c['low'], 5),
            'close' => round($c['close'], 5),
            'volume' => round($c['volume'] ?? 0, 2),
        ], $rows);

        return response()->json(['candles' => $data]);
    }

    public function structures(Request $request, CandleStructureZoneDetector $detector, CandleService $candleService): JsonResponse
    {
        $request->validate([
            'instrument' => 'required|string|max:63',
            'timeframe' => ['nullable', 'integer', Rule::in(CandleService::DASHBOARD_TIMEFRAMES)],
        ]);
        $instrument = $request->input('instrument');
        $timeframe = (int) ($request->input('timeframe') ?? 1);
        $since = time() - 86400;

        $rows = $candleService->seriesForDashboard($instrument, $timeframe, $since, null, null);

        $zones = $detector->activeZones($rows);

        return response()->json(['zones' => $zones]);
    }

    public function payouts(): JsonResponse
    {
        $rows = PocketInstrument::active()
            ->get(['instrument', 'current_payout'])
            ->map(fn (PocketInstrument $r) => [
                'instrument' => $r->instrument,
                'current_payout' => $r->current_payout !== null ? (float) $r->current_payout : null,
            ]);

        return response()->json(['payouts' => $rows->values()->all()]);
    }
}
