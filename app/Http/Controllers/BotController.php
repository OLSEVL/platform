<?php

namespace App\Http\Controllers;

use App\Models\Bot;
use App\Models\BotTrade;
use App\Models\PocketInstrument;
use App\Services\Bots\BotEngineService;
use App\Services\Bots\OpenRouterClient;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Throwable;

class BotController extends Controller
{
    public function index(): View
    {
        $bots = Bot::query()
            ->where('user_id', auth()->id())
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
        // Один «якорь» времени: иначе $from и конец периода слегка расходятся между запросами.
        $now = now();
        $from = $now->copy()->subHours($hours);

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

        // Общий winrate за выбранный период по всем ботам (учитываем только win/loss).
        $overall = DB::table('bot_trades as t')
            ->where('t.entry_time', '>=', $from)
            ->whereIn('t.result', [BotTrade::RESULT_WIN, BotTrade::RESULT_LOSS])
            ->selectRaw("
                SUM(CASE WHEN t.result = 'win' THEN 1 ELSE 0 END) as wins,
                SUM(CASE WHEN t.result = 'loss' THEN 1 ELSE 0 END) as losses
            ")
            ->first();
        $overallWins = (int) ($overall->wins ?? 0);
        $overallLosses = (int) ($overall->losses ?? 0);
        $overallTotal = $overallWins + $overallLosses;
        $overallWinrate = $overallTotal > 0 ? round(($overallWins * 100) / $overallTotal, 2) : null;

        // Ширина интервала для графика: короче период — мельче бакеты (ось времени читабельнее).
        $bucketSeconds = match (true) {
            $hours <= 1 => 300,
            $hours <= 6 => 900,
            $hours <= 48 => 3600,
            default => 21600,
        };

        // Внутренний уровень — по одной строке на сделку с бакетом; внешний SUM + GROUP BY совместимы с sql_mode=only_full_group_by.
        $tradesPerBucket = DB::table('bot_trades as t')
            ->join('bots as b', 'b.id', '=', 't.bot_id')
            ->where('t.entry_time', '>=', $from)
            ->whereIn('t.result', [BotTrade::RESULT_WIN, BotTrade::RESULT_LOSS])
            ->selectRaw(
                'b.id as bot_id, b.name as bot_name, FLOOR(UNIX_TIMESTAMP(t.entry_time) / ?) * ? as bucket_ts',
                [$bucketSeconds, $bucketSeconds]
            )
            ->selectRaw(
                'CASE WHEN t.result = ? THEN 1 ELSE 0 END as trade_win',
                [BotTrade::RESULT_WIN]
            )
            ->selectRaw(
                'CASE WHEN t.result = ? THEN 1 ELSE 0 END as trade_loss',
                [BotTrade::RESULT_LOSS]
            );

        $netByBucket = DB::query()
            ->fromSub($tradesPerBucket, 's')
            ->selectRaw('s.bot_id as bot_id, s.bot_name as bot_name, s.bucket_ts as bucket_ts, SUM(s.trade_win) as bucket_wins, SUM(s.trade_loss) as bucket_losses')
            ->groupBy('s.bot_id', 's.bot_name', 's.bucket_ts')
            ->orderBy('s.bucket_ts')
            ->get();

        // Границы бакетов должны совпадать с FLOOR(UNIX_TIMESTAMP(entry_time)/step) в MySQL.
        // PHP->timestamp — это UTC; UNIX_TIMESTAMP в MySQL считает в зоне сессии БД — смещение ломало ключи бакетов (график пустой на любом периоде).
        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $fromTs = (int) DB::selectOne(
                'SELECT FLOOR(UNIX_TIMESTAMP(?) / ?) * ? AS ts',
                [$from, $bucketSeconds, $bucketSeconds]
            )->ts;
            $endTs = (int) DB::selectOne(
                'SELECT FLOOR(UNIX_TIMESTAMP(?) / ?) * ? AS ts',
                [$now, $bucketSeconds, $bucketSeconds]
            )->ts;
        } else {
            $fromTs = (int) (floor($from->getTimestamp() / $bucketSeconds) * $bucketSeconds);
            $endTs = (int) (floor($now->getTimestamp() / $bucketSeconds) * $bucketSeconds);
        }
        if ($fromTs > $endTs) {
            $timeline = [];
        } else {
            $timeline = [];
            for ($ts = $fromTs; $ts <= $endTs; $ts += $bucketSeconds) {
                $timeline[] = $ts;
            }
        }
        // Если по какой-то причине шкала пустая, но агрегаты есть — строим ось X по фактическим bucket_ts из БД.
        if ($timeline === [] && $netByBucket->isNotEmpty()) {
            $minBk = (int) $netByBucket->min('bucket_ts');
            $maxBk = (int) $netByBucket->max('bucket_ts');
            for ($ts = $minBk; $ts <= $maxBk; $ts += $bucketSeconds) {
                $timeline[] = $ts;
            }
        }

        // Группируем win/loss по бакетам для расчёта winrate в каждом интервале времени.
        $bucketStats = [];
        foreach ($netByBucket as $row) {
            $bid = (int) $row->bot_id;
            $bk = (int) $row->bucket_ts;
            if (! isset($bucketStats[$bid])) {
                $bucketStats[$bid] = ['name' => (string) $row->bot_name, 'by_ts' => []];
            }
            $bucketStats[$bid]['by_ts'][$bk] = [
                'w' => (int) $row->bucket_wins,
                'l' => (int) $row->bucket_losses,
            ];
        }

        $tz = config('app.timezone');
        $chartLabels = [];
        foreach ($timeline as $bk) {
            $chartLabels[] = Carbon::createFromTimestamp($bk)->timezone($tz)->format('d.m H:i');
        }

        // Ось Y: winrate в процентах по каждому бакету (0..100), без кумулятивного накопления.
        $chartSeries = [];
        foreach ($bucketStats as $botId => $payload) {
            $values = [];
            foreach ($timeline as $bk) {
                $cell = $payload['by_ts'][$bk] ?? ['w' => 0, 'l' => 0];
                $total = $cell['w'] + $cell['l'];
                $values[] = $total > 0
                    ? round(100.0 * $cell['w'] / $total, 2)
                    : null;
            }
            $chartSeries[] = [
                'bot_id' => $botId,
                'name' => $payload['name'],
                'values' => $values,
            ];
        }

        return view('bots.analytics', [
            'hours' => $hours,
            'rows' => $raw,
            'chartLabels' => $chartLabels,
            'chartSeries' => $chartSeries,
            'bucketSeconds' => $bucketSeconds,
            'overallWins' => $overallWins,
            'overallLosses' => $overallLosses,
            'overallWinrate' => $overallWinrate,
        ]);
    }

    public function trades(Request $request): View
    {
        $botId = $request->integer('bot_id');
        $result = (string) $request->input('result', '');
        $tradeId = $request->integer('trade_id');

        $q = BotTrade::query()->with('bot')->latest('entry_time');
        if ($tradeId > 0) {
            // Точечный переход из графика: показываем именно выбранную сделку и не теряем её в пагинации.
            $q->whereKey($tradeId);
        }
        if ($botId) {
            $q->where('bot_id', $botId);
        }
        if (in_array($result, [BotTrade::RESULT_WIN, BotTrade::RESULT_LOSS, BotTrade::RESULT_DRAW, BotTrade::RESULT_PENDING], true)) {
            $q->where('result', $result);
        }

        return view('bots.trades', [
            'trades' => $q->paginate(50)->withQueryString(),
            'bots' => Bot::query()->where('user_id', auth()->id())->orderBy('name')->get(['id', 'name']),
            'selectedBotId' => $botId,
            'selectedResult' => $result,
            'selectedTradeId' => $tradeId > 0 ? $tradeId : null,
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
                // bot_id нужен фронту, чтобы построить прямую ссылку на "Сигналы и сделки"
                // именно того бота, которому принадлежит текущая строка ленты.
                'bot_id' => (int) $t->bot_id,
                'entry_time' => optional($t->entry_time)->format('Y-m-d H:i:s'),
                'bot_name' => $t->bot?->name ?? '—',
                'instrument' => $t->instrument,
                'side' => $t->side,
                'status' => $t->status,
                'result' => $t->result ?? 'pending',
                'entry_price' => $t->entry_price !== null ? number_format((float) $t->entry_price, 5, '.', '') : null,
                'expiry_price' => $t->expiry_price !== null ? number_format((float) $t->expiry_price, 5, '.', '') : null,
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

    public function settings(Bot $bot, OpenRouterClient $openRouterClient): View
    {
        $this->ensureBotOwner($bot);

        // Для openrouter_llm пробуем загрузить актуальный список моделей от OpenRouter.
        // Результат кэшируем, чтобы не делать внешний HTTP-запрос на каждый заход в настройки.
        $openrouterModels = $bot->type === 'openrouter_llm'
            ? $this->openRouterModelsForSettings($openRouterClient)
            : [];

        return view('bots.settings', [
            'bot' => $bot,
            'openrouterModels' => $openrouterModels,
        ]);
    }

    public function updateSettings(Request $request, Bot $bot): RedirectResponse
    {
        $this->ensureBotOwner($bot);

        $base = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            // UI-настройка графика: включает/выключает tradingview-подобный кроссхейр (линии X/Y + подписи цены/времени).
            // Поле опциональное, потому что HTML-checkbox не отправляется в payload, когда выключен.
            'tv_crosshair_enabled' => ['sometimes', 'boolean'],
        ]);

        $isActive = $request->boolean('is_active');

        if ($bot->type === 'three_candles_in_row') {
            $extra = $request->validate([
                'count' => ['required', 'integer', 'min:10', 'max:5000'],
                'expiry_sec' => ['required', 'integer', 'min:30', 'max:86400'],
                'min_confidence' => ['required', 'numeric', 'min:0', 'max:1'],
                'stale_after_sec' => ['required', 'integer', 'min:5', 'max:600'],
            ]);
            $config = array_merge($bot->config ?? [], [
                'count' => (int) $extra['count'],
                'expiry_sec' => (int) $extra['expiry_sec'],
                'min_confidence' => (float) $extra['min_confidence'],
                'stale_after_sec' => (int) $extra['stale_after_sec'],
            ]);
            unset($config['streak_len'], $config['confirm_streak_len']);
        } elseif ($bot->type === 'feshan') {
            // Feshan v2: вход только по снятию ликвидности (liquidity sweep) на M1.
            $extra = $request->validate([
                'count' => ['required', 'integer', 'min:10', 'max:5000'],
                'expiry_sec' => ['required', 'integer', 'min:60', 'max:900'],
                'min_confidence' => ['required', 'numeric', 'min:0', 'max:1'],
                // Экономический фильтр: бот торгует только пары с payout не ниже указанного порога.
                'min_payout' => ['required', 'numeric', 'min:0', 'max:100'],
                'liquidity_lookback' => ['required', 'integer', 'min:5', 'max:50'],
                'sweep_min_wick_ratio' => ['required', 'numeric', 'min:0.05', 'max:0.8'],
                'sweep_min_reclaim_ratio' => ['required', 'numeric', 'min:0.05', 'max:0.8'],
                'entry_extrema_distance' => ['required', 'numeric', 'min:0.02', 'max:0.4'],
                'stale_after_sec' => ['required', 'integer', 'min:5', 'max:600'],
            ]);
            $config = array_merge($bot->config ?? [], [
                'count' => (int) $extra['count'],
                'expiry_sec' => (int) $extra['expiry_sec'],
                'min_confidence' => (float) $extra['min_confidence'],
                'min_payout' => (float) $extra['min_payout'],
                'liquidity_lookback' => (int) $extra['liquidity_lookback'],
                'sweep_min_wick_ratio' => (float) $extra['sweep_min_wick_ratio'],
                'sweep_min_reclaim_ratio' => (float) $extra['sweep_min_reclaim_ratio'],
                'entry_extrema_distance' => (float) $extra['entry_extrema_distance'],
                'stale_after_sec' => (int) $extra['stale_after_sec'],
                'version' => 'feshan_liquidity_v2',
            ]);
        } elseif ($bot->type === 'openrouter_llm') {
            // OpenRouter LLM-бот: решение по сделке формируется нейросетью по свечному контексту.
            // Все параметры валидируем явно, чтобы конфиг оставался консистентным и безопасным.
            $extra = $request->validate([
                'count' => ['required', 'integer', 'min:20', 'max:5000'],
                'expiry_sec' => ['required', 'integer', 'min:60', 'max:900'],
                'min_confidence' => ['required', 'numeric', 'min:0', 'max:1'],
                'min_payout' => ['required', 'numeric', 'min:0', 'max:100'],
                'stale_after_sec' => ['required', 'integer', 'min:5', 'max:600'],
                // Модель в формате provider/model, например openai/gpt-5.2.
                'model' => ['required', 'string', 'min:3', 'max:128'],
                'temperature' => ['required', 'numeric', 'min:0', 'max:2'],
                'max_tokens' => ['required', 'integer', 'min:32', 'max:4096'],
                // Сколько последних закрытых свечей отправлять в LLM на анализ.
                'candles_take' => ['required', 'integer', 'min:20', 'max:500'],
                // Системный промпт ограничиваем по длине, чтобы не раздувать latency/стоимость запроса.
                'system_prompt' => ['required', 'string', 'min:20', 'max:12000'],
            ]);
            $config = array_merge($bot->config ?? [], [
                'count' => (int) $extra['count'],
                'expiry_sec' => (int) $extra['expiry_sec'],
                'min_confidence' => (float) $extra['min_confidence'],
                'min_payout' => (float) $extra['min_payout'],
                'stale_after_sec' => (int) $extra['stale_after_sec'],
                'model' => trim((string) $extra['model']),
                'temperature' => (float) $extra['temperature'],
                'max_tokens' => (int) $extra['max_tokens'],
                'candles_take' => (int) $extra['candles_take'],
                'system_prompt' => trim((string) $extra['system_prompt']),
                'version' => 'openrouter_llm_v1',
            ]);
        } else {
            $json = $request->validate([
                'config_json' => ['required', 'string', 'max:65535'],
            ])['config_json'];
            $decoded = json_decode($json, true);
            if (! is_array($decoded)) {
                return back()->withInput()->withErrors(['config_json' => 'Некорректный JSON в поле конфигурации.']);
            }
            $config = $decoded;
        }

        // Настройка UI графика хранится в config бота:
        // true  -> свободный кроссхейр как в TradingView (по умолчанию),
        // false -> более простой режим без TV-поведения.
        // Всегда записываем явное boolean-значение, чтобы поведение не зависело от отсутствия ключа.
        $config['tv_crosshair_enabled'] = $request->boolean('tv_crosshair_enabled');

        $bot->update([
            'name' => $base['name'],
            'is_active' => $isActive,
            'config' => $config,
        ]);

        return redirect()->route('bots.settings', $bot)->with('status', 'Настройки сохранены.');
    }

    public function activity(Bot $bot): View
    {
        $this->ensureBotOwner($bot);

        $targetTradeId = request()->integer('trade_id');

        $q = $bot->signals()
            ->with('trade')
            ->latest('signal_at');

        if ($targetTradeId > 0) {
            // Точечный переход из "Ленты"/"Истории": показываем конкретную сделку с анализом,
            // чтобы пользователь сразу попал в нужную запись и не искал её в общем списке.
            $q->whereHas('trade', fn ($tq) => $tq->whereKey($targetTradeId));
        } else {
            $q->limit(50);
        }

        $signalsWithTrades = $q->get();

        return view('bots.activity', [
            'bot' => $bot,
            'signalsWithTrades' => $signalsWithTrades,
            'targetTradeId' => $targetTradeId > 0 ? $targetTradeId : null,
        ]);
    }

    public function instrumentsSidebarData(Bot $bot): JsonResponse
    {
        $this->ensureBotOwner($bot);

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
        $this->ensureBotOwner($bot);

        $instrument = (string) $request->input('instrument', '');

        $q = $bot->trades()
            ->where('status', BotTrade::STATUS_OPEN)
            ->where('expiry_time', '>', now())
            ->orderBy('expiry_time');

        if ($instrument !== '') {
            $q->where('instrument', $instrument);
        }

        $trades = $q->with('signal')->limit(50)->get([
            'id',
            'bot_signal_id',
            'instrument',
            'side',
            'entry_price',
            'entry_time',
            'expiry_time',
            'status',
            'result',
            'pnl',
        ])->map(fn (BotTrade $t) => $this->openTradeSidebarRow($t))->values();

        return response()->json([
            'count' => $trades->count(),
            'trades' => $trades,
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function analysisData(Bot $bot, Request $request, BotEngineService $engine): JsonResponse
    {
        $this->ensureBotOwner($bot);

        // Явно требуем инструмент: анализ всегда делаем для выбранной на графике пары.
        $instrument = (string) $request->validate([
            'instrument' => ['required', 'string', 'max:64'],
        ])['instrument'];

        $analysis = $engine->explainInstrumentDecision($bot, $instrument);

        return response()->json($analysis + [
            'bot_id' => $bot->id,
            'bot_name' => $bot->name,
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function statsData(Bot $bot, Request $request): JsonResponse
    {
        $this->ensureBotOwner($bot);

        // scope=hour — скользящий час; scope=all — как в общей аналитике бота (вся история).
        $scope = $request->query('scope', 'all');
        if ($scope !== 'hour' && $scope !== 'all') {
            $scope = 'all';
        }
        // Опциональная пара для отдельного winrate в блоке "Статистика".
        // Если не передана, считаем метрику как "нет выбранного инструмента".
        $instrument = (string) $request->query('instrument', '');
        if ($instrument !== '' && mb_strlen($instrument) > 64) {
            $instrument = mb_substr($instrument, 0, 64);
        }

        $stats = $scope === 'hour'
            ? $this->botStatisticsForLastHour($bot, $instrument !== '' ? $instrument : null)
            : $this->botStatisticsAllTime($bot, $instrument !== '' ? $instrument : null);

        return response()->json([
            'stats' => $stats,
            'scope' => $scope,
            'instrument' => $instrument !== '' ? $instrument : null,
            'server_time' => now()->toIso8601String(),
        ]);
    }

    /**
     * Статистика по всем сделкам бота (прежнее поведение правой панели до фильтра по часу).
     *
     * @return array{total: int, open: int, wins: int, losses: int, winrate: float|null, instrument_winrate: float|null}
     */
    private function botStatisticsAllTime(Bot $bot, ?string $instrument = null): array
    {
        $raw = $bot->trades()
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as open,
                SUM(CASE WHEN result = ? THEN 1 ELSE 0 END) as wins,
                SUM(CASE WHEN result = ? THEN 1 ELSE 0 END) as losses
            ', [
                BotTrade::STATUS_OPEN,
                BotTrade::RESULT_WIN,
                BotTrade::RESULT_LOSS,
            ])
            ->first();

        $total = (int) ($raw?->total ?? 0);
        $open = (int) ($raw?->open ?? 0);
        $wins = (int) ($raw?->wins ?? 0);
        $losses = (int) ($raw?->losses ?? 0);
        $decisiveTrades = $wins + $losses;
        $winrate = $decisiveTrades > 0 ? round(($wins * 100) / $decisiveTrades, 2) : null;
        $instrumentWinrate = $this->instrumentWinrateAllTime($bot, $instrument);

        return [
            'total' => $total,
            'open' => $open,
            'wins' => $wins,
            'losses' => $losses,
            'winrate' => $winrate,
            'instrument_winrate' => $instrumentWinrate,
        ];
    }

    /**
     * Метрики блока «Статистика» на странице бота: скользящее окно 60 минут от текущего момента.
     * Всего / открыто — по времени входа; wins/losses — по settled_at (момент фиксации итога на сервере).
     *
     * @return array{total: int, open: int, wins: int, losses: int, winrate: float|null, instrument_winrate: float|null}
     */
    private function botStatisticsForLastHour(Bot $bot, ?string $instrument = null): array
    {
        $since = now()->subHour();

        $total = (int) $bot->trades()->where('entry_time', '>=', $since)->count();
        $open = (int) $bot->trades()
            ->where('status', BotTrade::STATUS_OPEN)
            ->where('entry_time', '>=', $since)
            ->count();
        $wins = (int) $bot->trades()
            ->where('result', BotTrade::RESULT_WIN)
            ->where('settled_at', '>=', $since)
            ->count();
        $losses = (int) $bot->trades()
            ->where('result', BotTrade::RESULT_LOSS)
            ->where('settled_at', '>=', $since)
            ->count();

        $decisiveTrades = $wins + $losses;
        $winrate = $decisiveTrades > 0 ? round(($wins * 100) / $decisiveTrades, 2) : null;
        $instrumentWinrate = $this->instrumentWinrateLastHour($bot, $instrument, $since);

        return [
            'total' => $total,
            'open' => $open,
            'wins' => $wins,
            'losses' => $losses,
            'winrate' => $winrate,
            'instrument_winrate' => $instrumentWinrate,
        ];
    }

    private function instrumentWinrateAllTime(Bot $bot, ?string $instrument): ?float
    {
        if ($instrument === null || $instrument === '') {
            return null;
        }

        $row = $bot->trades()
            ->where('instrument', $instrument)
            ->whereIn('result', [BotTrade::RESULT_WIN, BotTrade::RESULT_LOSS])
            ->selectRaw('
                SUM(CASE WHEN result = ? THEN 1 ELSE 0 END) as wins,
                SUM(CASE WHEN result = ? THEN 1 ELSE 0 END) as losses
            ', [
                BotTrade::RESULT_WIN,
                BotTrade::RESULT_LOSS,
            ])
            ->first();

        $wins = (int) ($row?->wins ?? 0);
        $losses = (int) ($row?->losses ?? 0);
        $total = $wins + $losses;

        return $total > 0 ? round(($wins * 100) / $total, 2) : null;
    }

    private function instrumentWinrateLastHour(Bot $bot, ?string $instrument, Carbon $since): ?float
    {
        if ($instrument === null || $instrument === '') {
            return null;
        }

        $row = $bot->trades()
            ->where('instrument', $instrument)
            ->where('settled_at', '>=', $since)
            ->whereIn('result', [BotTrade::RESULT_WIN, BotTrade::RESULT_LOSS])
            ->selectRaw('
                SUM(CASE WHEN result = ? THEN 1 ELSE 0 END) as wins,
                SUM(CASE WHEN result = ? THEN 1 ELSE 0 END) as losses
            ', [
                BotTrade::RESULT_WIN,
                BotTrade::RESULT_LOSS,
            ])
            ->first();

        $wins = (int) ($row?->wins ?? 0);
        $losses = (int) ($row?->losses ?? 0);
        $total = $wins + $losses;

        return $total > 0 ? round(($wins * 100) / $total, 2) : null;
    }

    public function show(Bot $bot): View
    {
        $this->ensureBotOwner($bot);

        $chartSignals = $bot->signals()
            ->with('trade')
            ->latest('signal_at')
            ->limit(1000)
            ->get()
            ->sortBy('signal_at')
            ->values();
        $chartSignalsPayload = $this->chartSignalsPayload($chartSignals);
        $signalInstruments = $chartSignals->pluck('instrument')->unique()->values();
        $sidebar = $this->sidebarInstrumentsPayload($bot);
        $instruments = $sidebar['instruments'];
        $openTradeInstruments = collect(array_keys($sidebar['open_trade_set']))->values();
        $botConfig = is_array($bot->config) ? $bot->config : [];
        // Если ключ еще не сохранен (старые записи), считаем настройку включенной по умолчанию.
        $tvCrosshairEnabled = array_key_exists('tv_crosshair_enabled', $botConfig)
            ? (bool) $botConfig['tv_crosshair_enabled']
            : true;
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
        $statsHour = $this->botStatisticsForLastHour($bot);
        $statsAll = $this->botStatisticsAllTime($bot);
        // Активная сделка справа — только по паре, которая станет выбранной при загрузке (как initialInstrument в JS), иначе при переключении пары анализ «залипал» бы на другом инструменте до перезагрузки.
        $activeTrade = null;
        if ($initialInstrument !== null && $initialInstrument !== '') {
            $activeTrade = $bot->trades()
                ->with('signal')
                ->where('status', BotTrade::STATUS_OPEN)
                ->where('expiry_time', '>', now())
                ->where('instrument', $initialInstrument)
                ->orderBy('expiry_time')
                ->first();
        }

        $activeTradeSecondsLeft = null;
        $activeTradeSidebar = null;
        if ($activeTrade !== null) {
            $activeTradeSecondsLeft = $activeTrade->expiry_time !== null
                ? max(0, now()->diffInSeconds($activeTrade->expiry_time, false))
                : null;
            $activeTradeSidebar = $this->openTradeSidebarRow($activeTrade);
        }

        return view('bots.show', [
            'bot' => $bot,
            'chartSignals' => $chartSignalsPayload,
            'instruments' => $instruments,
            'timeframes' => $timeframes,
            'signalInstruments' => $signalInstruments,
            'openTradeInstruments' => $openTradeInstruments,
            'nearestExpiryByInstrument' => $sidebar['nearest_expiry_ts'],
            'initialInstrument' => $initialInstrument,
            'statsHour' => $statsHour,
            'statsAll' => $statsAll,
            'activeTrade' => $activeTrade,
            'activeTradeSecondsLeft' => $activeTradeSecondsLeft,
            'activeTradeSidebar' => $activeTradeSidebar,
            'tvCrosshairEnabled' => $tvCrosshairEnabled,
        ]);
    }

    /**
     * История сигналов для графика (entry + итог на экспирации).
     * Нужен отдельный endpoint, чтобы UI подхватывал закрытие сделок без перезагрузки страницы.
     */
    public function signalsData(Bot $bot): JsonResponse
    {
        $this->ensureBotOwner($bot);

        $instrument = request()->string('instrument')->toString();

        $q = $bot->signals()
            ->with('trade')
            ->latest('signal_at');
        if ($instrument !== '') {
            // Для графика важнее глубокая история по текущей паре, чем общий короткий срез по всем инструментам.
            $q->where('instrument', $instrument);
        }
        $signals = $q->limit($instrument !== '' ? 2000 : 1000)
            ->get()
            ->sortBy('signal_at')
            ->values();

        return response()->json([
            'signals' => $this->chartSignalsPayload($signals),
            'count' => $signals->count(),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    /**
     * Поля открытой сделки для правой панели на графике бота и для JSON open-trades (синхронно с JS).
     *
     * @return array<string, mixed>
     */
    private function openTradeSidebarRow(BotTrade $t): array
    {
        $sig = $t->relationLoaded('signal') ? $t->signal : $t->signal()->first();
        $confidence = $sig !== null ? (float) $sig->confidence : null;
        $reasons = is_array($sig?->reasons_json) ? $sig->reasons_json : [];
        $reasons = array_values(array_filter(array_map(static fn ($x) => is_string($x) ? $x : (is_scalar($x) ? (string) $x : ''), $reasons), static fn ($s) => $s !== ''));

        $pnl = $t->pnl;
        $statusRu = match ($t->status) {
            BotTrade::STATUS_OPEN => 'открыта',
            BotTrade::STATUS_SETTLED => 'закрыта',
            default => (string) $t->status,
        };

        return [
            'id' => $t->id,
            'instrument' => $t->instrument,
            'side' => $t->side,
            'status' => $t->status,
            'confidence' => $confidence,
            'confidence_fmt' => $confidence !== null ? number_format($confidence, 2, '.', '') : '—',
            'signal_at_fmt' => optional($sig?->signal_at)->format('Y-m-d H:i:s') ?: optional($t->entry_time)->format('Y-m-d H:i:s'),
            'entry_at_fmt' => optional($t->entry_time)->format('Y-m-d H:i:s'),
            'trade_result' => $t->result,
            'trade_status' => $t->status,
            'status_ru' => $statusRu,
            'state_line' => (string) ($t->result ?? BotTrade::RESULT_PENDING).' '.$statusRu,
            'pnl' => $pnl !== null ? (float) $pnl : null,
            'pnl_display' => $pnl !== null ? number_format((float) $pnl, 2, '.', '') : null,
            'analysis_text' => (string) ($sig?->analysis_text ?? ''),
            'reasons_short' => $reasons,
            'entry_price' => $t->entry_price !== null ? number_format((float) $t->entry_price, 5, '.', '') : null,
            'entry_ts' => optional($t->entry_time)?->timestamp,
            'expiry_ts' => optional($t->expiry_time)?->timestamp,
        ];
    }

    private function ensureBotOwner(Bot $bot): void
    {
        abort_unless((int) $bot->user_id === (int) auth()->id(), 403);
    }

    /**
     * Получаем модели OpenRouter для выпадающего списка в настройках LLM-бота.
     * Если OpenRouter временно недоступен, возвращаем пустой список, а UI позволит
     * ввести model id вручную (fallback без блокировки страницы).
     *
     * @return list<array{id:string,name:string}>
     */
    private function openRouterModelsForSettings(OpenRouterClient $openRouterClient): array
    {
        try {
            /** @var list<array{id:string,name:string}> $models */
            $models = Cache::remember(
                'openrouter_models_for_settings_v1',
                now()->addMinutes(15),
                fn () => $openRouterClient->listModels()
            );

            return $models;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  Collection<int, \App\Models\BotSignal>  $signals
     * @return array<int, array<string, mixed>>
     */
    private function chartSignalsPayload(Collection $signals): array
    {
        return $signals->map(function ($s): array {
            $trade = $s->relationLoaded('trade') ? $s->trade : null;

            return [
                'id' => (int) $s->id,
                'instrument' => (string) $s->instrument,
                'side' => (string) $s->side,
                'signal_at' => optional($s->signal_at)->toIso8601String(),
                // Дублируем expiry/result из сигнала как fallback для маркеров истории, если relation trade временно не подгружен.
                'expiry_time' => optional($s->expiry_time)->toIso8601String(),
                'result' => $trade ? (string) ($trade->result ?? '') : '',
                'trade' => $trade ? [
                    'id' => (int) $trade->id,
                    'expiry_time' => optional($trade->expiry_time)->toIso8601String(),
                    'result' => (string) ($trade->result ?? ''),
                    // Цены нужны фронту, чтобы рисовать полосу длительности сделки
                    // на уровне самой сделки, а не "таймлайном" внизу графика.
                    'entry_price' => $trade->entry_price !== null ? (float) $trade->entry_price : null,
                    'expiry_price' => $trade->expiry_price !== null ? (float) $trade->expiry_price : null,
                ] : null,
            ];
        })->values()->all();
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
