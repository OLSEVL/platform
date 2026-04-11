<?php

namespace App\Http\Controllers;

use App\Models\Bot;
use App\Models\BotTrade;
use App\Models\PocketInstrument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class BotController extends Controller
{
    public function index(): View
    {
        $bots = Bot::query()
            ->withCount([
                'trades as trades_total',
                'trades as wins_count' => fn ($q) => $q->where('result', BotTrade::RESULT_WIN),
                'trades as losses_count' => fn ($q) => $q->where('result', BotTrade::RESULT_LOSS),
                'trades as open_count' => fn ($q) => $q->where('status', BotTrade::STATUS_OPEN),
            ])
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        return view('bots.index', ['bots' => $bots]);
    }

    public function analytics(Request $request): View
    {
        $hours = (int) ($request->input('hours', 24));
        $hours = max(1, min(168, $hours));
        $from = now()->subHours($hours);

        $raw = DB::table('bot_trades as t')
            ->join('bots as b', 'b.id', '=', 't.bot_id')
            ->selectRaw("
                b.id as bot_id,
                b.name as bot_name,
                DATE_FORMAT(t.entry_time, '%Y-%m-%d %H:00:00') as bucket,
                COUNT(*) as trades_total,
                SUM(CASE WHEN t.result = 'win' THEN 1 ELSE 0 END) as wins,
                SUM(CASE WHEN t.result = 'loss' THEN 1 ELSE 0 END) as losses
            ")
            ->where('t.entry_time', '>=', $from)
            ->groupBy('b.id', 'b.name', 'bucket')
            ->orderBy('bucket')
            ->get();

        return view('bots.analytics', [
            'hours' => $hours,
            'rows' => $raw,
        ]);
    }

    public function trades(Request $request): View
    {
        $botId = $request->integer('bot_id');
        $result = (string) $request->input('result', '');

        $q = BotTrade::query()->with('bot')->latest('entry_time');
        if ($botId) {
            $q->where('bot_id', $botId);
        }
        if (in_array($result, [BotTrade::RESULT_WIN, BotTrade::RESULT_LOSS, BotTrade::RESULT_DRAW, BotTrade::RESULT_PENDING], true)) {
            $q->where('result', $result);
        }

        return view('bots.trades', [
            'trades' => $q->paginate(50)->withQueryString(),
            'bots' => Bot::query()->orderBy('name')->get(['id', 'name']),
            'selectedBotId' => $botId,
            'selectedResult' => $result,
        ]);
    }

    public function liveTrades(): View
    {
        return view('bots.live-trades');
    }

    public function liveTradesData(): JsonResponse
    {
        $from = now()->subHours(2);
        $trades = BotTrade::query()
            ->with('bot')
            ->where('entry_time', '>=', $from)
            ->latest('entry_time')
            ->limit(200)
            ->get()
            ->map(fn (BotTrade $t) => [
                'id' => $t->id,
                'entry_time' => optional($t->entry_time)->format('Y-m-d H:i:s'),
                'bot_name' => $t->bot?->name ?? '—',
                'instrument' => $t->instrument,
                'side' => $t->side,
                'status' => $t->status,
                'result' => $t->result ?? 'pending',
                'entry_price' => $t->entry_price !== null ? number_format((float) $t->entry_price, 5, '.', '') : null,
                'expiry_time' => optional($t->expiry_time)->format('Y-m-d H:i:s'),
                'expiry_ts' => optional($t->expiry_time)?->timestamp,
                'pnl' => $t->pnl !== null ? number_format((float) $t->pnl, 4, '.', '') : null,
            ])
            ->values();

        return response()->json([
            'from' => $from->toIso8601String(),
            'count' => $trades->count(),
            'trades' => $trades,
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function activity(Bot $bot): View
    {
        $signalsWithTrades = $bot->signals()
            ->with('trade')
            ->latest('signal_at')
            ->limit(50)
            ->get();

        return view('bots.activity', [
            'bot' => $bot,
            'signalsWithTrades' => $signalsWithTrades,
        ]);
    }

    public function instrumentsSidebarData(Bot $bot): JsonResponse
    {
        $sidebar = $this->sidebarInstrumentsPayload($bot);

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

    public function openTradesData(Bot $bot, Request $request): JsonResponse
    {
        $instrument = (string) $request->input('instrument', '');

        $q = $bot->trades()
            ->where('status', BotTrade::STATUS_OPEN)
            ->where('expiry_time', '>', now())
            ->orderBy('expiry_time');

        if ($instrument !== '') {
            $q->where('instrument', $instrument);
        }

        $trades = $q->limit(50)->get([
            'id',
            'instrument',
            'side',
            'entry_price',
            'entry_time',
            'expiry_time',
            'status',
        ])->map(fn (BotTrade $t) => [
            'id' => $t->id,
            'instrument' => $t->instrument,
            'side' => $t->side,
            'status' => $t->status,
            'entry_price' => $t->entry_price !== null ? number_format((float) $t->entry_price, 5, '.', '') : null,
            'entry_ts' => optional($t->entry_time)?->timestamp,
            'expiry_ts' => optional($t->expiry_time)?->timestamp,
        ])->values();

        return response()->json([
            'count' => $trades->count(),
            'trades' => $trades,
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function show(Bot $bot): View
    {
        $chartSignals = $bot->signals()
            ->with('trade')
            ->latest('signal_at')
            ->limit(200)
            ->get()
            ->sortBy('signal_at')
            ->values();
        $signalInstruments = $chartSignals->pluck('instrument')->unique()->values();
        $sidebar = $this->sidebarInstrumentsPayload($bot);
        $instruments = $sidebar['instruments'];
        $openTradeInstruments = collect(array_keys($sidebar['open_trade_set']))->values();
        $initialInstrument = $signalInstruments->first() ?: optional($instruments->first())->instrument;
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
        $stats = [
            'total' => $bot->trades()->count(),
            'wins' => $bot->trades()->where('result', BotTrade::RESULT_WIN)->count(),
            'losses' => $bot->trades()->where('result', BotTrade::RESULT_LOSS)->count(),
            'open' => $bot->trades()->where('status', BotTrade::STATUS_OPEN)->count(),
        ];
        $stats['winrate'] = ($stats['wins'] + $stats['losses']) > 0
            ? round(($stats['wins'] * 100) / ($stats['wins'] + $stats['losses']), 2)
            : null;

        return view('bots.show', [
            'bot' => $bot,
            'chartSignals' => $chartSignals,
            'instruments' => $instruments,
            'timeframes' => $timeframes,
            'signalInstruments' => $signalInstruments,
            'openTradeInstruments' => $openTradeInstruments,
            'nearestExpiryByInstrument' => $sidebar['nearest_expiry_ts'],
            'initialInstrument' => $initialInstrument,
            'stats' => $stats,
        ]);
    }

    /**
     * @return array{instruments: Collection<int, PocketInstrument>, open_trade_set: array<string, true>, nearest_expiry_ts: array<string, int>}
     */
    private function sidebarInstrumentsPayload(Bot $bot): array
    {
        $nearestExpiryTs = DB::table('bot_trades')
            ->where('bot_id', $bot->id)
            ->where('status', BotTrade::STATUS_OPEN)
            ->where('expiry_time', '>', now())
            ->selectRaw('instrument, MIN(expiry_time) as min_expiry')
            ->groupBy('instrument')
            ->pluck('min_expiry', 'instrument')
            ->map(fn ($v) => \Carbon\Carbon::parse($v)->timestamp)
            ->all();

        $openTradeInstruments = $bot->trades()
            ->where('status', BotTrade::STATUS_OPEN)
            ->where('expiry_time', '>', now())
            ->pluck('instrument')
            ->filter()
            ->unique()
            ->values();
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
        $instruments = $instruments->sort(function ($a, $b) use ($openTradeSet) {
            $aOpen = isset($openTradeSet[$a->instrument]) ? 1 : 0;
            $bOpen = isset($openTradeSet[$b->instrument]) ? 1 : 0;
            if ($aOpen !== $bOpen) {
                return $bOpen <=> $aOpen;
            }
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
}
