<?php

namespace App\Http\Controllers;

use App\Models\BotTrade;
use App\Models\PocketInstrument;
use App\Services\CandleService;
use App\Services\CandleStructureZoneDetector;
use App\Services\CurrentCandleCacheService;
use App\Support\M1CandleCountdown;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $sidebar = $this->dashboardSidebarInstrumentsPayload((int) auth()->id());
        $instruments = $sidebar['instruments'];
        $openTradeInstruments = collect(array_keys($sidebar['open_trade_set']))->values();
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
            'openTradeInstruments' => $openTradeInstruments,
            'nearestExpiryByInstrument' => $sidebar['nearest_expiry_ts'],
            'timeframes' => $timeframes,
        ]);
    }

    public function instrumentsSidebarData(): JsonResponse
    {
        $sidebar = $this->dashboardSidebarInstrumentsPayload((int) auth()->id());
        $expiryMap = $sidebar['nearest_expiry_ts'];

        return response()->json([
            'instruments' => $sidebar['instruments']->map(fn (PocketInstrument $inst) => [
                'instrument' => $inst->instrument,
                'display_name' => $inst->display_name ?? $inst->instrument,
                'current_payout' => $inst->current_payout !== null ? (float) $inst->current_payout : null,
                'open_trade' => isset($sidebar['open_trade_set'][$inst->instrument]),
                'expiry_ts' => $expiryMap[$inst->instrument] ?? null,
            ])->values(),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    /**
     * @return array{instruments: \Illuminate\Support\Collection<int, PocketInstrument>, open_trade_set: array<string, true>, nearest_expiry_ts: array<string, int>}
     */
    private function dashboardSidebarInstrumentsPayload(int $userId): array
    {
        if ($userId <= 0) {
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

            return [
                'instruments' => $instruments,
                'open_trade_set' => [],
                'nearest_expiry_ts' => [],
            ];
        }

        $nearestExpiryTs = DB::table('bot_trades as t')
            ->join('bots as b', 'b.id', '=', 't.bot_id')
            ->where('b.user_id', $userId)
            ->where('t.status', BotTrade::STATUS_OPEN)
            ->where('t.expiry_time', '>', now())
            ->selectRaw('t.instrument, MIN(t.expiry_time) as min_expiry')
            ->groupBy('t.instrument')
            ->pluck('min_expiry', 'instrument')
            ->map(fn ($v) => \Carbon\Carbon::parse($v)->timestamp)
            ->all();

        $openTradeInstruments = DB::table('bot_trades as t')
            ->join('bots as b', 'b.id', '=', 't.bot_id')
            ->where('b.user_id', $userId)
            ->where('t.status', BotTrade::STATUS_OPEN)
            ->where('t.expiry_time', '>', now())
            ->distinct()
            ->pluck('t.instrument');
        $openTradeSet = array_fill_keys($openTradeInstruments->all(), true);

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
        $instruments = $instruments->sort(function ($a, $b) {
            $aPayout = $a->current_payout !== null ? (float) $a->current_payout : -INF;
            $bPayout = $b->current_payout !== null ? (float) $b->current_payout : -INF;
            if ($aPayout !== $bPayout) {
                return $bPayout <=> $aPayout;
            }

            return strcmp((string) $a->instrument, (string) $b->instrument);
        })->values();

        return [
            'instruments' => $instruments,
            'open_trade_set' => $openTradeSet,
            'nearest_expiry_ts' => $nearestExpiryTs,
        ];
    }

    public function candles(
        Request $request,
        CandleService $candleService,
        CurrentCandleCacheService $currentCandleCache
    ): JsonResponse {
        $request->validate([
            'instrument' => 'required|string|max:63',
            'timeframe' => ['nullable', 'integer', Rule::in(CandleService::DASHBOARD_TIMEFRAMES)],
            'limit' => 'nullable|integer|min:1|max:2000',
            'before' => 'nullable|integer',
            'since' => 'nullable|integer|min:0',
            'include_partial' => 'nullable|boolean',
        ]);
        $instrument = $request->input('instrument');
        $timeframe = (int) ($request->input('timeframe') ?? 1);
        $limit = (int) ($request->input('limit') ?? 1440);
        $before = $request->input('before') !== null ? (int) $request->input('before') : null;
        $since = $request->input('since') !== null ? (int) $request->input('since') : null;
        $includePartial = (bool) $request->boolean('include_partial', false);

        $serverUnix = time();

        if ($timeframe !== 1 && $before !== null) {
            return response()->json([
                'candles' => [],
                'server_unix' => $serverUnix,
                'm1_seconds_to_close' => null,
            ]);
        }

        $rows = $candleService->seriesForDashboard(
            $instrument,
            $timeframe,
            $since,
            $before,
            $limit,
        );
        // Нормализуем временную ось свечей для графика:
        // если источник прислал series с часовым сдвигом (например, +2/+3 часа),
        // приводим её к серверному now, чтобы маркеры сделок не "уезжали" влево/вправо.
        [$rows, $timeShiftSec] = $this->normalizeChartSeriesTimezoneDrift($rows, $serverUnix);

        $data = array_map(fn (array $c) => [
            'time' => $c['time'],
            'open' => round($c['open'], 5),
            'high' => round($c['high'], 5),
            'low' => round($c['low'], 5),
            'close' => round($c['close'], 5),
            'volume' => round($c['volume'] ?? 0, 2),
            'is_partial' => false,
        ], $rows);

        if ($includePartial && $timeframe === 1 && $before === null) {
            $partial = $currentCandleCache->getPartial($instrument);
            if ($partial && $currentCandleCache->isFresh($partial, 30)) {
                $lastClosedTime = ! empty($data) ? (int) end($data)['time'] : 0;
                $partialTime = (int) ($partial['time'] ?? 0) - $timeShiftSec;
                if ($partialTime > $lastClosedTime) {
                    $data[] = [
                        'time' => $partialTime,
                        'open' => round((float) ($partial['open'] ?? 0), 5),
                        'high' => round((float) ($partial['high'] ?? 0), 5),
                        'low' => round((float) ($partial['low'] ?? 0), 5),
                        'close' => round((float) ($partial['close'] ?? 0), 5),
                        'volume' => round((float) ($partial['volume'] ?? 0), 2),
                        'is_partial' => true,
                    ];
                }
            }
        }

        $lastOpen = null;
        if ($timeframe === 1 && $data !== []) {
            $lastOpen = (int) end($data)['time'];
        }

        $m1SecondsToClose = ($lastOpen !== null && $lastOpen > 0)
            ? M1CandleCountdown::secondsToClose($lastOpen, $serverUnix)
            : null;

        return response()->json([
            'candles' => $data,
            'server_unix' => $serverUnix,
            'time_shift_sec' => $timeShiftSec,
            'm1_seconds_to_close' => $m1SecondsToClose,
        ]);
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
        [$rows] = $this->normalizeChartSeriesTimezoneDrift($rows, time());

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

    /**
     * Для UI-графика корректирует постоянный часовой drift у свечей.
     *
     * @param  array<int, array{time:int, open:float, high:float, low:float, close:float, volume:float}>  $rows
     * @return array{0: array<int, array{time:int, open:float, high:float, low:float, close:float, volume:float}>, 1: int}
     */
    private function normalizeChartSeriesTimezoneDrift(array $rows, int $serverUnix): array
    {
        if ($rows === []) {
            return [$rows, 0];
        }
        $last = end($rows);
        $lastTs = is_array($last) ? (int) ($last['time'] ?? 0) : 0;
        if ($lastTs <= 0) {
            return [$rows, 0];
        }

        // Если последняя свеча слишком далеко от now, считаем это timezone-drift источника.
        $deltaSec = $lastTs - $serverUnix;
        if (abs($deltaSec) < 90 * 60) {
            return [$rows, 0];
        }
        $hours = (int) round($deltaSec / 3600);
        if ($hours === 0 || abs($hours) > 12) {
            return [$rows, 0];
        }
        $shiftSec = $hours * 3600;

        $normalized = array_map(function (array $c) use ($shiftSec): array {
            $c['time'] = max(1, (int) $c['time'] - $shiftSec);
            return $c;
        }, $rows);

        return [$normalized, $shiftSec];
    }
}
