<?php

namespace App\Http\Controllers;

use App\Models\Bot;
use App\Models\BotAnalysisRun;
use App\Models\BotLlmLog;
use App\Models\BotTrade;
use App\Models\IndicatorSetting;
use App\Models\PocketInstrument;
use App\Services\Bots\BotEngineService;
use App\Services\Bots\Levels\StrongLevelCardPresenter;
use App\Services\Bots\OpenRouterClient;
use App\Services\CandleService;
use App\Services\CandleStructureZoneDetector;
use App\Services\DataCleanupService;
use App\Services\Indicators\LiquiditySwingsIndicatorService;
use App\Services\Indicators\PriceActionDgtIndicatorService;
use App\Services\Indicators\SmcFlowIndicatorService;
use App\Services\Telegram\StrongLevelTelegramLlmButton;
use App\Services\Telegram\StrongLevelTelegramProcessManager;
use App\Services\Telegram\TelegramAlertService;
use App\Support\BotScanProgress;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
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

        // Интервал обобщения статистики (график + таблица): 1/5/15/30 мин или 1 час.
        $intervalPresets = [
            60 => '1 минута',
            300 => '5 минут',
            900 => '15 минут',
            1800 => '30 минут',
            3600 => '1 час',
        ];
        $requestedInterval = (int) $request->input('interval', 0);
        $bucketSeconds = array_key_exists($requestedInterval, $intervalPresets)
            ? $requestedInterval
            : match (true) {
                $hours <= 1 => 300,
                $hours <= 6 => 900,
                $hours <= 48 => 3600,
                default => 3600,
            };

        $raw = DB::table('bot_trades as t')
            ->join('bots as b', 'b.id', '=', 't.bot_id')
            ->selectRaw("
                b.id as bot_id,
                b.name as bot_name,
                FLOOR(UNIX_TIMESTAMP(t.entry_time) / ?) * ? as bucket_ts,
                COUNT(*) as trades_total,
                SUM(CASE WHEN t.result = 'win' THEN 1 ELSE 0 END) as wins,
                SUM(CASE WHEN t.result = 'loss' THEN 1 ELSE 0 END) as losses
            ", [$bucketSeconds, $bucketSeconds])
            ->where('t.entry_time', '>=', $from)
            ->groupBy('b.id', 'b.name', 'bucket_ts')
            ->orderByDesc('bucket_ts')
            ->orderBy('b.name')
            ->get();
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
        // Слишком мелкий интервал на длинном периоде — на графике оставляем только последние 600 точек.
        $maxChartBuckets = 600;
        if (count($timeline) > $maxChartBuckets) {
            $timeline = array_slice($timeline, -$maxChartBuckets);
        }

        // Группируем win/loss по бакетам для расчёта winrate в каждого интервала времени.
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

        // Все включённые боты пользователя — график для каждого, даже без сделок за период.
        $activeBots = Bot::query()
            ->where('user_id', auth()->id())
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        // Winrate за период по каждому боту (для подписи под заголовком графика).
        $botPeriodStats = DB::table('bot_trades as t')
            ->join('bots as b', 'b.id', '=', 't.bot_id')
            ->where('b.user_id', auth()->id())
            ->where('b.is_active', true)
            ->where('t.entry_time', '>=', $from)
            ->whereIn('t.result', [BotTrade::RESULT_WIN, BotTrade::RESULT_LOSS])
            ->groupBy('b.id', 'b.name')
            ->selectRaw("
                b.id as bot_id,
                SUM(CASE WHEN t.result = 'win' THEN 1 ELSE 0 END) as wins,
                SUM(CASE WHEN t.result = 'loss' THEN 1 ELSE 0 END) as losses
            ")
            ->get()
            ->keyBy('bot_id');

        $tz = config('app.timezone');
        $chartLabels = [];
        foreach ($timeline as $bk) {
            $chartLabels[] = Carbon::createFromTimestamp($bk)->timezone($tz)->format('d.m H:i');
        }

        // Ось Y: winrate в процентах по каждому бакету (0..100), без кумулятивного накопления.
        $chartSeries = [];
        foreach ($activeBots as $bot) {
            $botId = (int) $bot->id;
            $byTs = $bucketStats[$botId]['by_ts'] ?? [];
            $values = [];
            $bucketWins = [];
            $bucketLosses = [];
            foreach ($timeline as $bk) {
                $cell = $byTs[$bk] ?? ['w' => 0, 'l' => 0];
                $w = (int) $cell['w'];
                $l = (int) $cell['l'];
                $total = $w + $l;
                $bucketWins[] = $w;
                $bucketLosses[] = $l;
                $values[] = $total > 0
                    ? round(100.0 * $w / $total, 2)
                    : null;
            }

            $periodRow = $botPeriodStats->get($botId);
            $botWins = (int) ($periodRow->wins ?? 0);
            $botLosses = (int) ($periodRow->losses ?? 0);
            $botTotal = $botWins + $botLosses;

            $chartSeries[] = [
                'bot_id' => $botId,
                'name' => (string) $bot->name,
                'values' => $values,
                'bucket_wins' => $bucketWins,
                'bucket_losses' => $bucketLosses,
                'wins' => $botWins,
                'losses' => $botLosses,
                'winrate' => $botTotal > 0 ? round(($botWins * 100) / $botTotal, 2) : null,
            ];
        }

        return view('bots.analytics', [
            'hours' => $hours,
            'interval' => $bucketSeconds,
            'intervalPresets' => $intervalPresets,
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
            // Лента «последние 2 ч» — по фактическому появлению записи, не по плановому entry_time (бар).
            ->where('created_at', '>=', $from)
            ->latest('created_at')
            ->limit(200)
            ->get()
            ->map(function (BotTrade $t) {
                $meta = is_array($t->meta_json) ? $t->meta_json : [];
                $mirroredAt = isset($meta['mirrored_at']) ? (string) $meta['mirrored_at'] : null;
                if ($mirroredAt !== null && strlen($mirroredAt) > 19) {
                    try {
                        $mirroredAt = Carbon::parse($mirroredAt)->format('Y-m-d H:i:s');
                    } catch (\Throwable) {
                        $mirroredAt = substr($mirroredAt, 0, 19);
                    }
                }

                return [
                    'id' => $t->id,
                    'bot_id' => (int) $t->bot_id,
                    'entry_time' => optional($t->entry_time)->format('Y-m-d H:i:s'),
                    'created_at' => optional($t->created_at)->format('Y-m-d H:i:s'),
                    'mirrored_at' => $mirroredAt,
                    'bot_name' => $t->bot?->name ?? '—',
                    'instrument' => $t->instrument,
                    'side' => $t->side,
                    'status' => $t->status,
                    'result' => $t->result ?? 'pending',
                    'entry_price' => $t->entry_price !== null ? number_format((float) $t->entry_price, 5, '.', '') : null,
                    'entry_amount' => BotTrade::formatMirrorAmountLabel(BotTrade::mirrorAmountFromMeta($meta)),
                    'expiry_price' => $t->expiry_price !== null ? number_format((float) $t->expiry_price, 5, '.', '') : null,
                    'expiry_time' => optional($t->expiry_time)->format('Y-m-d H:i:s'),
                    'settled_at' => optional($t->settled_at)->format('Y-m-d H:i:s'),
                    'expiry_ts' => optional($t->expiry_time)?->timestamp,
                    'pnl' => $t->pnl !== null ? number_format((float) $t->pnl, 4, '.', '') : null,
                ];
            })
            ->values();

        return response()->json([
            'from' => $from->toIso8601String(),
            'count' => $trades->count(),
            'trades' => $trades,
            'server_time' => now()->toIso8601String(),
        ]);
    }

    /**
     * Сетка карточек открытых сделок Strong Level (poll + модалка с анализом).
     */
    public function cards(Bot $bot): View
    {
        $this->ensureBotOwner($bot);
        abort_unless($bot->type === 'strong_level', 404);

        return view('bots.cards', ['bot' => $bot]);
    }

    /**
     * JSON для live-обновления карточек: только open-сделки с неистёкшей экспирацией.
     */
    public function cardsData(Bot $bot, StrongLevelCardPresenter $presenter): JsonResponse
    {
        $this->ensureBotOwner($bot);
        abort_unless($bot->type === 'strong_level', 404);

        $now = now();

        $trades = $bot->trades()
            ->where('status', BotTrade::STATUS_OPEN)
            ->where('expiry_time', '>', $now)
            ->with('signal')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $instruments = $trades->pluck('instrument')->unique()->values()->all();
        $payouts = PocketInstrument::query()
            ->whereIn('instrument', $instruments)
            ->pluck('current_payout', 'instrument');

        $priceCache = [];
        $cards = $trades->map(function (BotTrade $trade) use ($presenter, $payouts, &$priceCache, $bot, $now) {
            $instrument = (string) $trade->instrument;
            if (! array_key_exists($instrument, $priceCache)) {
                $priceCache[$instrument] = $presenter->currentPriceForInstrument($instrument);
            }
            $payout = $payouts->get($instrument);
            $currentPayout = $payout !== null ? (float) $payout : null;

            $payload = $presenter->cardPayload($trade, $priceCache[$instrument], $currentPayout, $now);
            $payload = array_merge($payload, $presenter->chartUrlsForTrade($bot, $trade));
            try {
                $payload['chart_thumb_data_uri'] = $presenter->chartThumbDataUri($trade);
            } catch (\Throwable $e) {
                Log::warning('cards_chart_thumb_failed', [
                    'trade_id' => $trade->id,
                    'instrument' => $trade->instrument,
                    'error' => $e->getMessage(),
                ]);
                $payload['chart_thumb_data_uri'] = null;
            }
            $payload['show_url'] = route('bots.show', ['bot' => $bot->id, 'trade_id' => $trade->id], absolute: false);

            return $payload;
        })->values();

        return response()->json([
            'bot_id' => $bot->id,
            'bot_name' => $bot->name,
            'count' => $cards->count(),
            'cards' => $cards,
            'server_time' => $now->toIso8601String(),
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    /**
     * PNG-график для модалки карточки (тот же рендер, что в Telegram-алерте Strong Level).
     */
    public function cardsChart(Bot $bot, BotTrade $trade, Request $request, StrongLevelCardPresenter $presenter): Response
    {
        $this->ensureBotOwner($bot);
        abort_unless($bot->type === 'strong_level', 404);
        abort_unless((int) $trade->bot_id === (int) $bot->id, 404);

        $timeframe = (int) $request->query('tf', 1);
        if (! in_array($timeframe, [1, 15], true)) {
            abort(400, 'invalid_timeframe');
        }

        $size = (string) $request->query('size', 'full');
        if (! in_array($size, ['full', 'thumb'], true)) {
            $size = 'full';
        }

        try {
            $pngContents = $presenter->chartPngContents($trade, $timeframe, $size);
        } catch (\Throwable $e) {
            Log::warning('cards_chart_failed', [
                'trade_id' => $trade->id,
                'tf' => $timeframe,
                'size' => $size,
                'error' => $e->getMessage(),
            ]);
            abort(404, 'chart_unavailable');
        }

        if ($pngContents === null || $pngContents === '') {
            abort(404, 'chart_unavailable');
        }

        return response($pngContents, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=30',
        ]);
    }

    public function settings(Bot $bot, OpenRouterClient $openRouterClient): View
    {
        $this->ensureBotOwner($bot);

        // Для LLM-ботов каталог моделей тянем напрямую с OpenRouter (без кэша — заходы редкие).
        $openrouterModels = in_array($bot->type, ['openrouter_llm', 'openrouter_llm_analyst'], true)
            ? $this->openRouterModelsForSettings($openRouterClient)
            : [];

        return view('bots.settings', [
            'bot' => $bot,
            'openrouterModels' => $openrouterModels,
            'chartAnalystBots' => in_array($bot->type, ['strong_level', 'strong_trend_telegram'], true)
                ? Bot::query()
                    ->where('user_id', $bot->user_id)
                    ->where('type', 'openrouter_llm_analyst')
                    ->orderBy('id')
                    ->get(['id', 'name', 'is_active'])
                : collect(),
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
            // Включает запись технических логов прохода движка в bot_analysis_runs.
            // Поле опциональное, т.к. выключенный checkbox отсутствует в HTTP payload.
            'analysis_runs_logging_enabled' => ['sometimes', 'boolean'],
        ]);

        $isActive = $request->boolean('is_active');

        if ($bot->type === 'three_candles_in_row') {
            $extra = $request->validate([
                'count' => ['required', 'integer', 'min:10', 'max:5000'],
                'expiry_sec' => ['required', 'integer', 'min:30', 'max:86400'],
                'min_confidence' => ['required', 'numeric', 'min:0', 'max:1'],
                // Пары с payout ниже порога не анализируются (экономический фильтр).
                'min_payout' => ['required', 'numeric', 'min:0', 'max:100'],
                'stale_after_sec' => ['required', 'integer', 'min:5', 'max:600'],
            ]);
            $config = array_merge($bot->config ?? [], [
                'count' => (int) $extra['count'],
                'expiry_sec' => (int) $extra['expiry_sec'],
                'min_confidence' => (float) $extra['min_confidence'],
                'min_payout' => (float) $extra['min_payout'],
                'stale_after_sec' => (int) $extra['stale_after_sec'],
            ]);
            unset($config['streak_len'], $config['confirm_streak_len']);
        } elseif ($bot->type === 'akim_trade_1m') {
            // Самостоятельный профиль AKIM 1M: отдельная ветка, чтобы не смешивать с legacy three_candles.
            $extra = $request->validate([
                'count' => ['required', 'integer', 'min:10', 'max:5000'],
                'expiry_sec' => ['required', 'integer', 'min:30', 'max:86400'],
                'min_confidence' => ['required', 'numeric', 'min:0', 'max:1'],
                // Пары с payout ниже порога не анализируются (экономический фильтр).
                'min_payout' => ['required', 'numeric', 'min:0', 'max:100'],
                'stale_after_sec' => ['required', 'integer', 'min:5', 'max:600'],
            ]);
            $config = array_merge($bot->config ?? [], [
                'count' => (int) $extra['count'],
                'expiry_sec' => (int) $extra['expiry_sec'],
                'min_confidence' => (float) $extra['min_confidence'],
                'min_payout' => (float) $extra['min_payout'],
                'stale_after_sec' => (int) $extra['stale_after_sec'],
                'version' => 'akim_trade_1m_v1',
            ]);
        } elseif ($bot->type === 'candle_reversal_confirm') {
            // MTF-разворот:
            // 1) сторона определяется на M5 по 3 закрытым свечам (impulse->reversal->confirm),
            // 2) вход подтверждается на M1 по 3 закрытым свечам в ту же сторону и по теням.
            $extra = $request->validate([
                'count' => ['required', 'integer', 'min:10', 'max:5000'],
                'min_confidence' => ['required', 'numeric', 'min:0', 'max:1'],
                // Пары с payout ниже порога не анализируются (экономический фильтр).
                'min_payout' => ['required', 'numeric', 'min:0', 'max:100'],
                'stale_after_sec' => ['required', 'integer', 'min:5', 'max:600'],
                // M5-блок (разворотная свеча + confirm).
                'm5_reversal_min_wick_ratio' => ['required', 'numeric', 'min:0.12', 'max:0.75'],
                'm5_reversal_max_body_ratio' => ['required', 'numeric', 'min:0.15', 'max:0.9'],
                // M1-блок (3 закрытых свечи подтверждения в сторону M5).
                'm1_min_wick_ratio' => ['required', 'numeric', 'min:0.10', 'max:0.70'],
                'm1_min_body_ratio' => ['required', 'numeric', 'min:0.05', 'max:0.80'],
                // last = тени только у последней из 3 M1 (рекомендуется); all = у всех трёх (очень редкие сигналы).
                'm1_wick_requirement_scope' => ['required', 'string', Rule::in(['all', 'last'])],
            ]);
            $config = array_merge($bot->config ?? [], [
                'count' => (int) $extra['count'],
                // По ТЗ фиксируем вход на 5 минут.
                'expiry_sec' => 300,
                'min_confidence' => (float) $extra['min_confidence'],
                'min_payout' => (float) $extra['min_payout'],
                'stale_after_sec' => (int) $extra['stale_after_sec'],
                'm5_reversal_min_wick_ratio' => (float) $extra['m5_reversal_min_wick_ratio'],
                'm5_reversal_max_body_ratio' => (float) $extra['m5_reversal_max_body_ratio'],
                'm5_require_break_rev_extreme' => $request->boolean('m5_require_break_rev_extreme'),
                'm1_min_wick_ratio' => (float) $extra['m1_min_wick_ratio'],
                'm1_min_body_ratio' => (float) $extra['m1_min_body_ratio'],
                'm1_require_wick_dominance' => $request->boolean('m1_require_wick_dominance'),
                'm1_wick_requirement_scope' => (string) $extra['m1_wick_requirement_scope'],
                'version' => 'candle_reversal_confirm_v2_mtf',
            ]);
        } elseif ($bot->type === 'smc_flow') {
            // SMC Flow: UT flip + 3BR confirm + LuxAlgo OB filter; экспирация по умолчанию 3 мин.
            $extra = $request->validate([
                'count' => ['required', 'integer', 'min:80', 'max:5000'],
                'expiry_sec' => ['required', 'integer', 'min:120', 'max:600'],
                'min_confidence' => ['required', 'numeric', 'min:0', 'max:1'],
                'min_payout' => ['required', 'numeric', 'min:0', 'max:100'],
                'stale_after_sec' => ['required', 'integer', 'min:5', 'max:600'],
                'smc_three_br_confirm_max_bars' => ['required', 'integer', 'min:1', 'max:120'],
                'smc_require_ob' => ['sometimes', 'boolean'],
                'smc_three_br_allow_pattern' => ['sometimes', 'boolean'],
                'smc_ob_touch_max_bars' => ['sometimes', 'integer', 'min:0', 'max:12'],
            ]);
            $config = array_merge($bot->config ?? [], [
                'count' => (int) $extra['count'],
                'expiry_sec' => (int) $extra['expiry_sec'],
                'min_confidence' => (float) $extra['min_confidence'],
                'min_payout' => (float) $extra['min_payout'],
                'stale_after_sec' => (int) $extra['stale_after_sec'],
                'smc_three_br_confirm_max_bars' => (int) $extra['smc_three_br_confirm_max_bars'],
                'smc_require_ob' => $request->boolean('smc_require_ob'),
                'smc_three_br_allow_pattern' => $request->boolean('smc_three_br_allow_pattern'),
                'smc_ob_touch_max_bars' => (int) ($extra['smc_ob_touch_max_bars'] ?? $request->input('smc_ob_touch_max_bars', 3)),
                'version' => 'smc_flow_ut_3br_ob_v1',
            ]);
        } elseif ($bot->type === 'strong_trend_telegram') {
            // Алерт сильного тренда M1 → Telegram; без paper-сделок в платформе.
            $extra = $request->validate([
                'count' => ['required', 'integer', 'min:10', 'max:5000'],
                'streak_len' => ['required', 'integer', 'min:2', 'max:30'],
                'min_confidence' => ['required', 'numeric', 'min:0', 'max:1'],
                'min_payout' => ['required', 'numeric', 'min:0', 'max:100'],
                'stale_after_sec' => ['required', 'integer', 'min:5', 'max:600'],
                'dedupe_bucket_sec' => ['required', 'integer', 'min:60', 'max:3600'],
                'candle_strictness' => ['required', 'string', Rule::in(['strict', 'with_doji'])],
                'min_body_to_range_ratio' => ['required', 'numeric', 'min:0', 'max:1'],
                'telegram_enabled' => ['sometimes', 'boolean'],
                'telegram_bot_token' => ['nullable', 'string', 'max:128'],
                'telegram_chat_id' => ['nullable', 'string', 'max:64'],
                'telegram_analyst_llm_enabled' => ['sometimes', 'boolean'],
                'telegram_analyst_bot_id' => ['nullable', 'integer', 'min:1'],
                'telegram_analyst_llm_question' => ['nullable', 'string', 'max:500'],
                'telegram_send_chart' => ['required', 'string', Rule::in(['1', '0'])],
            ]);
            $existingToken = trim((string) (($bot->config ?? [])['telegram_bot_token'] ?? ''));
            $tokenInput = trim((string) ($extra['telegram_bot_token'] ?? ''));
            $config = array_merge($bot->config ?? [], [
                'count' => (int) $extra['count'],
                'streak_len' => (int) $extra['streak_len'],
                'min_confidence' => (float) $extra['min_confidence'],
                'min_payout' => (float) $extra['min_payout'],
                'stale_after_sec' => (int) $extra['stale_after_sec'],
                'dedupe_bucket_sec' => (int) $extra['dedupe_bucket_sec'],
                'candle_strictness' => (string) $extra['candle_strictness'],
                'min_body_to_range_ratio' => (float) $extra['min_body_to_range_ratio'],
                'telegram_enabled' => $request->boolean('telegram_enabled'),
                // Пустое поле token при сохранении не затирает уже сохранённый token.
                'telegram_bot_token' => $tokenInput !== '' ? $tokenInput : $existingToken,
                'telegram_chat_id' => trim((string) ($extra['telegram_chat_id'] ?? '')),
                'telegram_analyst_llm_enabled' => $request->boolean('telegram_analyst_llm_enabled'),
                'telegram_analyst_bot_id' => ! empty($extra['telegram_analyst_bot_id'])
                    ? (int) $extra['telegram_analyst_bot_id']
                    : null,
                'telegram_analyst_llm_question' => StrongLevelTelegramLlmButton::llmQuestionFromConfig([
                    'telegram_analyst_llm_question' => $extra['telegram_analyst_llm_question'] ?? null,
                ]),
                'telegram_send_chart' => ($extra['telegram_send_chart'] ?? '1') === '1',
                'notify_only' => true,
                'version' => 'strong_trend_telegram_v1',
            ]);
        } elseif ($bot->type === 'strong_level') {
            // Strong Level: конфлюенс уровней DGT+SMC, вход на отскок или пробой.
            $extra = $request->validate([
                'count' => ['required', 'integer', 'min:200', 'max:5000'],
                'min_closed_bars' => ['required', 'integer', 'min:80', 'max:2000'],
                'min_confidence' => ['required', 'numeric', 'min:0', 'max:1'],
                'min_payout' => ['required', 'numeric', 'min:0', 'max:100'],
                'stale_after_sec' => ['required', 'integer', 'min:5', 'max:600'],
                'entry_mode' => ['required', 'string', Rule::in(['bounce', 'breakout', 'both'])],
                'min_level_strength' => ['required', 'numeric', 'min:40', 'max:95'],
                'min_sources' => ['required', 'integer', 'min:1', 'max:6'],
                'cluster_atr_mult_m5' => ['required', 'numeric', 'min:0.05', 'max:1'],
                'touch_atr_mult_m1' => ['required', 'numeric', 'min:0.05', 'max:1.5'],
                'bounce_min_wick_ratio_m5' => ['required', 'numeric', 'min:0.15', 'max:0.8'],
                'h1_flat_strength_extra' => ['required', 'numeric', 'min:0', 'max:15'],
                'expiry_sec_bounce' => ['required', 'integer', 'min:120', 'max:600'],
                'expiry_sec_breakout' => ['required', 'integer', 'min:120', 'max:600'],
                'm5_take' => ['required', 'integer', 'min:20', 'max:300'],
                'm15_take' => ['required', 'integer', 'min:20', 'max:300'],
                'h1_take' => ['required', 'integer', 'min:10', 'max:200'],
                'telegram_enabled' => ['sometimes', 'boolean'],
                'telegram_bot_token' => ['nullable', 'string', 'max:128'],
                'telegram_chat_id' => ['nullable', 'string', 'max:64'],
                'telegram_notify_on' => ['required', 'string', Rule::in(['signal_trade', 'off'])],
                'telegram_alert_format' => ['required', 'string', Rule::in(['brief', 'detailed', 'card'])],
                'telegram_send_chart' => ['required', 'string', Rule::in(['1', '0'])],
                'telegram_analyst_llm_enabled' => ['sometimes', 'boolean'],
                'telegram_analyst_bot_id' => ['nullable', 'integer', 'min:1'],
                'telegram_analyst_llm_question' => ['nullable', 'string', 'max:500'],
            ]);
            $existingToken = trim((string) (($bot->config ?? [])['telegram_bot_token'] ?? ''));
            $tokenInput = trim((string) ($extra['telegram_bot_token'] ?? ''));
            $config = array_merge($bot->config ?? [], [
                'count' => (int) $extra['count'],
                'min_closed_bars' => (int) $extra['min_closed_bars'],
                'min_confidence' => (float) $extra['min_confidence'],
                'min_payout' => (float) $extra['min_payout'],
                'stale_after_sec' => (int) $extra['stale_after_sec'],
                'entry_mode' => (string) $extra['entry_mode'],
                'min_level_strength' => (float) $extra['min_level_strength'],
                'min_sources' => (int) $extra['min_sources'],
                'cluster_atr_mult_m5' => (float) $extra['cluster_atr_mult_m5'],
                'touch_atr_mult_m1' => (float) $extra['touch_atr_mult_m1'],
                'bounce_min_wick_ratio_m5' => (float) $extra['bounce_min_wick_ratio_m5'],
                'h1_flat_strength_extra' => (float) $extra['h1_flat_strength_extra'],
                'expiry_sec_bounce' => (int) $extra['expiry_sec_bounce'],
                'expiry_sec_breakout' => (int) $extra['expiry_sec_breakout'],
                'm5_take' => (int) $extra['m5_take'],
                'm15_take' => (int) $extra['m15_take'],
                'h1_take' => (int) $extra['h1_take'],
                'require_h1_alignment' => $request->boolean('require_h1_alignment'),
                'bounce_require_m1_confirm' => $request->boolean('bounce_require_m1_confirm'),
                'breakout_require_retest' => $request->boolean('breakout_require_retest'),
                'breakout_vol_above_sma' => $request->boolean('breakout_vol_above_sma'),
                'telegram_enabled' => $request->boolean('telegram_enabled'),
                'telegram_bot_token' => $tokenInput !== '' ? $tokenInput : $existingToken,
                'telegram_chat_id' => trim((string) ($extra['telegram_chat_id'] ?? '')),
                'telegram_notify_on' => (string) $extra['telegram_notify_on'],
                'telegram_alert_format' => (string) $extra['telegram_alert_format'],
                'telegram_send_chart' => ($extra['telegram_send_chart'] ?? '1') === '1',
                'telegram_analyst_llm_enabled' => $request->boolean('telegram_analyst_llm_enabled'),
                'telegram_analyst_bot_id' => ! empty($extra['telegram_analyst_bot_id'])
                    ? (int) $extra['telegram_analyst_bot_id']
                    : null,
                'telegram_analyst_llm_question' => StrongLevelTelegramLlmButton::llmQuestionFromConfig([
                    'telegram_analyst_llm_question' => $extra['telegram_analyst_llm_question'] ?? null,
                ]),
                'version' => 'strong_level_v1',
            ]);
        } elseif ($bot->type === 'openrouter_llm') {
            // OpenRouter LLM-бот: решение по сделке формируется нейросетью по свечному контексту.
            // Все параметры валидируем явно, чтобы конфиг оставался консистентным и безопасным.
            $extra = $request->validate([
                'count' => ['required', 'integer', 'min:20', 'max:500'],
                'expiry_sec' => ['required', 'integer', 'min:60', 'max:900'],
                'min_confidence' => ['required', 'numeric', 'min:0', 'max:1'],
                'min_payout' => ['required', 'numeric', 'min:0', 'max:100'],
                'stale_after_sec' => ['required', 'integer', 'min:5', 'max:600'],
                // Модель в формате provider/model, например openai/gpt-5.2.
                'model' => ['required', 'string', 'min:3', 'max:128'],
                'temperature' => ['required', 'numeric', 'min:0', 'max:2'],
                'max_tokens' => ['required', 'integer', 'min:32', 'max:4096'],
                'openrouter_timeout_sec' => [
                    'required',
                    'integer',
                    'min:30',
                    'max:'.(int) config('services.openrouter.timeout_sec_max', 600),
                ],
                // Системный промпт ограничиваем по длине, чтобы не раздувать latency/стоимость запроса.
                'system_prompt' => ['required', 'string', 'min:20', 'max:12000'],
                // Флаг и лимиты MTF-контекста для LLM (M5/M15/H1).
                // Храним как настройки бота, чтобы можно было гибко тюнить стратегию без деплоя.
                'mtf_enabled' => ['sometimes', 'boolean'],
                'm5_take' => ['required', 'integer', 'min:20', 'max:300'],
                'm15_take' => ['required', 'integer', 'min:20', 'max:300'],
                'h1_take' => ['required', 'integer', 'min:10', 'max:200'],
            ]);
            $config = \App\Support\OpenRouterLlmConfig::withoutLegacyCandlesTake(array_merge($bot->config ?? [], [
                'count' => (int) $extra['count'],
                'expiry_sec' => (int) $extra['expiry_sec'],
                'min_confidence' => (float) $extra['min_confidence'],
                'min_payout' => (float) $extra['min_payout'],
                'stale_after_sec' => (int) $extra['stale_after_sec'],
                'model' => trim((string) $extra['model']),
                'temperature' => (float) $extra['temperature'],
                'max_tokens' => (int) $extra['max_tokens'],
                'openrouter_timeout_sec' => (int) $extra['openrouter_timeout_sec'],
                'system_prompt' => trim((string) $extra['system_prompt']),
                'mtf_enabled' => $request->boolean('mtf_enabled'),
                'm5_take' => (int) $extra['m5_take'],
                'm15_take' => (int) $extra['m15_take'],
                'h1_take' => (int) $extra['h1_take'],
                'version' => 'openrouter_llm_v1',
            ]));
        } elseif ($bot->type === 'openrouter_llm_analyst') {
            // Бот-аналитик: LLM + MTF; Telegram polling для интерактивного анализа по кнопкам.
            $extra = $request->validate([
                'count' => ['required', 'integer', 'min:1', 'max:500'],
                's5_take' => ['required', 'integer', 'min:1', 'max:1500'],
                'model' => ['required', 'string', 'min:3', 'max:128'],
                'temperature' => ['required', 'numeric', 'min:0', 'max:2'],
                'max_tokens' => ['required', 'integer', 'min:64', 'max:8192'],
                'openrouter_timeout_sec' => [
                    'required',
                    'integer',
                    'min:30',
                    'max:'.(int) config('services.openrouter.timeout_sec_max', 600),
                ],
                'system_prompt' => ['required', 'string', 'min:20', 'max:12000'],
                'mtf_enabled' => ['sometimes', 'boolean'],
                'm5_take' => ['required', 'integer', 'min:1', 'max:300'],
                'm15_take' => ['required', 'integer', 'min:1', 'max:300'],
                'h1_take' => ['required', 'integer', 'min:1', 'max:200'],
                'min_payout' => ['required', 'numeric', 'min:0', 'max:100'],
                'allowed_instruments_text' => ['nullable', 'string', 'max:8000'],
                'blocked_instruments_text' => ['nullable', 'string', 'max:8000'],
                'telegram_enabled' => ['sometimes', 'boolean'],
                'telegram_bot_token' => ['nullable', 'string', 'max:128'],
                'telegram_chat_id' => ['nullable', 'string', 'max:64'],
            ]);
            $existingToken = trim((string) (($bot->config ?? [])['telegram_bot_token'] ?? ''));
            $tokenInput = trim((string) ($extra['telegram_bot_token'] ?? ''));
            $allowedList = \App\Services\Telegram\TelegramAnalystInstrumentService::parseInstrumentListInput(
                $extra['allowed_instruments_text'] ?? null
            );
            $blockedList = \App\Services\Telegram\TelegramAnalystInstrumentService::parseInstrumentListInput(
                $extra['blocked_instruments_text'] ?? null
            );
            $config = \App\Support\OpenRouterLlmConfig::withoutLegacyCandlesTake(array_merge($bot->config ?? [], [
                'count' => (int) $extra['count'],
                's5_take' => (int) $extra['s5_take'],
                'model' => trim((string) $extra['model']),
                'temperature' => (float) $extra['temperature'],
                'max_tokens' => (int) $extra['max_tokens'],
                'openrouter_timeout_sec' => (int) $extra['openrouter_timeout_sec'],
                'system_prompt' => trim((string) $extra['system_prompt']),
                'mtf_enabled' => $request->boolean('mtf_enabled'),
                'm5_take' => (int) $extra['m5_take'],
                'm15_take' => (int) $extra['m15_take'],
                'h1_take' => (int) $extra['h1_take'],
                'min_payout' => (float) $extra['min_payout'],
                'allowed_instruments' => $allowedList,
                'blocked_instruments' => $blockedList,
                'telegram_enabled' => $request->boolean('telegram_enabled'),
                'telegram_bot_token' => $tokenInput !== '' ? $tokenInput : $existingToken,
                'telegram_chat_id' => trim((string) ($extra['telegram_chat_id'] ?? '')),
                'version' => 'openrouter_llm_analyst_v1',
            ]));
            // Плановый scan для аналитика не используется — всегда выключаем флаг активности.
            $isActive = false;
        } else {
            $json = $request->validate([
                'config_json' => ['required', 'string', 'max:65535'],
            ])['config_json'];
            $decoded = json_decode($json, true);
            if (! is_array($decoded)) {
                return back()->withInput()->withErrors(['config_json' => 'Некорректный JSON в поле конфигурации.']);
            }
            // price_action_dgt вынесен в таблицу indicator_settings (отдельное поле формы).
            unset($decoded[IndicatorSetting::KEY_PRICE_ACTION_DGT], $decoded[IndicatorSetting::KEY_LIQUIDITY_SWINGS], $decoded[IndicatorSetting::KEY_ORDER_BLOCKS]);
            $config = $decoded;
        }

        // Настройка UI графика хранится в config бота:
        // true  -> свободный кроссхейр как в TradingView (по умолчанию),
        // false -> более простой режим без TV-поведения.
        // Всегда записываем явное boolean-значение, чтобы поведение не зависело от отсутствия ключа.
        $config['tv_crosshair_enabled'] = $request->boolean('tv_crosshair_enabled');
        // Логика логирования analysis-runs:
        // true  -> для каждого инструмента сохраняем run (status/skip_reason/decision/...),
        // false -> движок не пишет bot_analysis_runs, чтобы уменьшить шум/нагрузку на БД у конкретного бота.
        // Всегда сохраняем явное boolean-значение, чтобы поведение было детерминированным
        // и не зависело от "отсутствующего ключа" в старых конфигах.
        $config['analysis_runs_logging_enabled'] = $request->boolean('analysis_runs_logging_enabled');

        // price_action_dgt хранится только в indicator_settings (страница индикатора), из config убираем при любом сохранении общих настроек.
        unset($config[IndicatorSetting::KEY_PRICE_ACTION_DGT], $config[IndicatorSetting::KEY_LIQUIDITY_SWINGS], $config[IndicatorSetting::KEY_ORDER_BLOCKS]);

        $bot->update([
            'name' => $base['name'],
            'is_active' => $isActive,
            'config' => $config,
        ]);

        return redirect()->route('bots.settings', $bot)->with('status', 'Настройки сохранены.');
    }

    /**
     * Тестовое сообщение в Telegram из настроек бота (strong_trend_telegram, strong_level, analyst).
     */
    public function telegramTest(Bot $bot, TelegramAlertService $telegram): JsonResponse
    {
        $this->ensureBotOwner($bot);
        if (! in_array($bot->type, ['strong_trend_telegram', 'strong_level', 'openrouter_llm_analyst'], true)) {
            return response()->json(['ok' => false, 'error' => 'unsupported_bot_type'], 422);
        }

        $result = $telegram->sendTestMessage($bot);

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    /**
     * Получить chat_id из getUpdates (пользователь должен сначала написать боту /start).
     */
    public function telegramDiscoverChat(Bot $bot, TelegramAlertService $telegram): JsonResponse
    {
        $this->ensureBotOwner($bot);
        if (! in_array($bot->type, ['strong_trend_telegram', 'strong_level', 'openrouter_llm_analyst'], true)) {
            return response()->json(['ok' => false, 'error' => 'unsupported_bot_type'], 422);
        }

        $cfg = is_array($bot->config) ? $bot->config : [];
        $token = trim((string) ($cfg['telegram_bot_token'] ?? ''));
        if ($token === '') {
            return response()->json([
                'ok' => false,
                'error' => 'telegram_token_missing',
                'hint' => 'Сохраните token бота в настройках, затем напишите боту /start в Telegram и повторите.',
            ], 422);
        }

        $result = $telegram->discoverChatIds($token);
        if (! ($result['ok'] ?? false)) {
            return response()->json($result, 422);
        }

        $chats = $result['chats'] ?? [];
        $botUsernameHint = match ($bot->type) {
            'strong_level' => '@strong_level_bot',
            'openrouter_llm_analyst' => '@chart_analyct_smc_flow_bot',
            default => '@trend1123_bot',
        };
        if ($chats === []) {
            return response()->json([
                'ok' => true,
                'chats' => [],
                'hint' => 'Список пуст. Откройте '.$botUsernameHint.' в Telegram, нажмите Start (/start) и повторите.',
            ]);
        }

        return response()->json([
            'ok' => true,
            'chats' => $chats,
        ]);
    }

    /**
     * Статус фоновых процессов Telegram Chart Analyst (poll + queue worker).
     */
    public function telegramAnalystStatus(Bot $bot, \App\Services\Telegram\TelegramAnalystProcessManager $processes): JsonResponse
    {
        $this->ensureBotOwner($bot);
        if ($bot->type !== 'openrouter_llm_analyst') {
            return response()->json(['ok' => false, 'error' => 'unsupported_bot_type'], 422);
        }

        return response()->json($processes->status($bot->id));
    }

    /**
     * Перезапуск poll и queue worker — подхватывают свежий config из БД.
     */
    public function telegramAnalystRestart(Bot $bot, \App\Services\Telegram\TelegramAnalystProcessManager $processes): JsonResponse
    {
        $this->ensureBotOwner($bot);
        if ($bot->type !== 'openrouter_llm_analyst') {
            return response()->json(['ok' => false, 'error' => 'unsupported_bot_type'], 422);
        }

        $result = $processes->restart($bot);

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    /**
     * Статус poll Strong Level / Strong Trend Telegram + queue telegram-analyst.
     */
    public function telegramStrongLevelStatus(Bot $bot, StrongLevelTelegramProcessManager $processes): JsonResponse
    {
        $this->ensureBotOwner($bot);
        if (! StrongLevelTelegramLlmButton::supportsBotType($bot->type)) {
            return response()->json(['ok' => false, 'error' => 'unsupported_bot_type'], 422);
        }

        return response()->json($processes->status($bot->id));
    }

    /**
     * Перезапуск poll Strong Level / Strong Trend Telegram и queue worker Chart Analyst.
     */
    public function telegramStrongLevelRestart(Bot $bot, StrongLevelTelegramProcessManager $processes): JsonResponse
    {
        $this->ensureBotOwner($bot);
        if (! StrongLevelTelegramLlmButton::supportsBotType($bot->type)) {
            return response()->json(['ok' => false, 'error' => 'unsupported_bot_type'], 422);
        }

        $result = $processes->restart($bot);

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    public function cleanupRecent(Bot $bot, DataCleanupService $dataCleanup): RedirectResponse
    {
        $this->ensureBotOwner($bot);

        $result = $dataCleanup->purgeOlderThan(DataCleanupService::DEFAULT_RETENTION_HOURS, $bot->id);

        return redirect()
            ->route('bots.settings', $bot)
            ->with('status', $dataCleanup->formatResultMessage($result, includeCandles: false));
    }

    public function activity(Bot $bot): View|RedirectResponse
    {
        $this->ensureBotOwner($bot);

        if ($bot->type === 'openrouter_llm_analyst') {
            return redirect()
                ->route('bots.show', $bot)
                ->with('status', 'У бота-аналитика нет сигналов и сделок — используйте «Сделать анализ» на графике.');
        }

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

    public function logs(Bot $bot, Request $request): View
    {
        $this->ensureBotOwner($bot);

        // Бот-аналитик пишет только bot_llm_logs (без bot_analysis_runs и сделок).
        if ($bot->type === 'openrouter_llm_analyst') {
            return $this->logsForChartAnalyst($bot, $request);
        }

        $status = (string) $request->query('status', '');
        $instrument = trim((string) $request->query('instrument', ''));
        $skipReason = trim((string) $request->query('skip_reason', ''));

        // Если миграции логирования ещё не применены, не падаем 500-ошибкой:
        // возвращаем пустой экран с меткой disabled.
        if (! Schema::hasTable('bot_analysis_runs')) {
            /** @var LengthAwarePaginator<int, mixed> $emptyRuns */
            $emptyRuns = new LengthAwarePaginator(
                items: collect(),
                total: 0,
                perPage: 50,
                currentPage: 1,
                options: ['path' => request()->url(), 'query' => request()->query()]
            );

            return view('bots.logs', [
                'bot' => $bot,
                'runs' => $emptyRuns,
                'llmLogsByRun' => [],
                'analysisMetrics' => ['enabled' => false],
                'statusOptions' => [
                    BotAnalysisRun::STATUS_OK,
                    BotAnalysisRun::STATUS_SKIPPED,
                    BotAnalysisRun::STATUS_ERROR,
                    BotAnalysisRun::STATUS_RUNNING,
                ],
                'skipReasonOptions' => [],
                'instrumentOptions' => [],
                'selectedStatus' => $status,
                'selectedInstrument' => $instrument,
                'selectedSkipReason' => $skipReason,
            ]);
        }

        $runsQuery = BotAnalysisRun::query()
            ->where('bot_id', $bot->id)
            ->latest('started_at');

        if (in_array($status, [BotAnalysisRun::STATUS_OK, BotAnalysisRun::STATUS_SKIPPED, BotAnalysisRun::STATUS_ERROR, BotAnalysisRun::STATUS_RUNNING], true)) {
            $runsQuery->where('status', $status);
        }
        if ($instrument !== '') {
            $runsQuery->where('instrument', $instrument);
        }
        if ($skipReason !== '') {
            $runsQuery->where('skip_reason', $skipReason);
        }

        $runs = $runsQuery->paginate(50)->withQueryString();
        $runIds = $runs->getCollection()->pluck('id')->map(fn ($v) => (int) $v)->all();

        $llmLogsByRun = [];
        if ($runIds !== [] && Schema::hasTable('bot_llm_logs')) {
            $llmLogs = BotLlmLog::query()
                ->where('bot_id', $bot->id)
                ->whereIn('bot_analysis_run_id', $runIds)
                ->orderByDesc('id')
                ->get([
                    'id',
                    'bot_analysis_run_id',
                    'instrument',
                    'model',
                    'status',
                    'latency_ms',
                    'request_json',
                    'request_text',
                    'response_text',
                    'error_message',
                    'created_at',
                ]);
            $llmLogsByRun = $llmLogs->groupBy('bot_analysis_run_id')->all();
        }

        $analysisMetrics = $this->analysisMetricsAllTime($bot);
        $statusOptions = [
            BotAnalysisRun::STATUS_OK,
            BotAnalysisRun::STATUS_SKIPPED,
            BotAnalysisRun::STATUS_ERROR,
            BotAnalysisRun::STATUS_RUNNING,
        ];
        $skipReasonOptions = Schema::hasTable('bot_analysis_runs')
            ? DB::table('bot_analysis_runs')
                ->where('bot_id', $bot->id)
                ->whereNotNull('skip_reason')
                ->where('skip_reason', '!=', '')
                ->distinct()
                ->orderBy('skip_reason')
                ->pluck('skip_reason')
                ->map(fn ($v) => (string) $v)
                ->values()
                ->all()
            : [];

        $instrumentOptions = Schema::hasTable('bot_analysis_runs')
            ? DB::table('bot_analysis_runs')
                ->where('bot_id', $bot->id)
                ->distinct()
                ->orderBy('instrument')
                ->pluck('instrument')
                ->map(fn ($v) => (string) $v)
                ->values()
                ->all()
            : [];

        return view('bots.logs', [
            'bot' => $bot,
            'runs' => $runs,
            'llmLogsByRun' => $llmLogsByRun,
            'analysisMetrics' => $analysisMetrics,
            'statusOptions' => $statusOptions,
            'skipReasonOptions' => $skipReasonOptions,
            'instrumentOptions' => $instrumentOptions,
            'selectedStatus' => $status,
            'selectedInstrument' => $instrument,
            'selectedSkipReason' => $skipReason,
        ]);
    }

    /**
     * Страница логов для бота-аналитика: только история запросов к OpenRouter (bot_llm_logs).
     */
    private function logsForChartAnalyst(Bot $bot, Request $request): View
    {
        $status = (string) $request->query('status', '');
        $instrument = trim((string) $request->query('instrument', ''));

        $llmLogs = collect();
        $instrumentOptions = [];
        $avgLatency = null;
        $totalOk = 0;
        $totalError = 0;

        if (Schema::hasTable('bot_llm_logs')) {
            $q = BotLlmLog::query()
                ->where('bot_id', $bot->id)
                ->latest('id');
            if (in_array($status, ['ok', 'error'], true)) {
                $q->where('status', $status);
            }
            if ($instrument !== '') {
                $q->where('instrument', $instrument);
            }
            $llmLogs = $q->paginate(50)->withQueryString();

            $instrumentOptions = BotLlmLog::query()
                ->where('bot_id', $bot->id)
                ->distinct()
                ->orderBy('instrument')
                ->pluck('instrument')
                ->map(fn ($v) => (string) $v)
                ->values()
                ->all();

            $totalOk = (int) BotLlmLog::query()->where('bot_id', $bot->id)->where('status', 'ok')->count();
            $totalError = (int) BotLlmLog::query()->where('bot_id', $bot->id)->where('status', 'error')->count();
            $avgLatency = BotLlmLog::query()
                ->where('bot_id', $bot->id)
                ->whereNotNull('latency_ms')
                ->avg('latency_ms');
        }

        return view('bots.logs-analyst', [
            'bot' => $bot,
            'llmLogs' => $llmLogs,
            'instrumentOptions' => $instrumentOptions,
            'selectedStatus' => $status,
            'selectedInstrument' => $instrument,
            'totalOk' => $totalOk,
            'totalError' => $totalError,
            'avgLatencyMs' => $avgLatency !== null ? (float) $avgLatency : null,
        ]);
    }

    public function instrumentsSidebarData(Bot $bot, BotEngineService $engine): JsonResponse
    {
        $this->ensureBotOwner($bot);

        $sidebar = $this->sidebarInstrumentsPayload($bot);
        $scanMinPayout = (float) $engine->runtimeSettings($bot)['min_payout'];

        $expiryMap = $sidebar['nearest_expiry_ts'];
        $scanProgress = BotScanProgress::snapshot($bot->id, $scanMinPayout);

        return response()->json([
            'instruments' => $sidebar['instruments']->map(fn (PocketInstrument $inst) => [
                'instrument' => $inst->instrument,
                'display_name' => $inst->display_name ?? $inst->instrument,
                'current_payout' => $inst->current_payout !== null ? (float) $inst->current_payout : null,
                'open_trade' => isset($sidebar['open_trade_set'][$inst->instrument]),
                'expiry_ts' => $expiryMap[$inst->instrument] ?? null,
                'scan_dot' => BotScanProgress::dotColor(
                    $bot->id,
                    $inst->instrument,
                    $inst->current_payout !== null ? (float) $inst->current_payout : null,
                    $scanMinPayout
                ),
            ])->values(),
            'scan_active' => $scanProgress['scan_active'],
            'analyzing_instrument' => $scanProgress['analyzing_instrument'],
            'analyzed_instruments' => $scanProgress['analyzed_instruments'],
            'scan_total' => $scanProgress['scan_total'],
            'scan_remaining' => $scanProgress['scan_remaining'],
            'scan_min_payout' => $scanMinPayout,
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
        $validated = $request->validate([
            'instrument' => ['required', 'string', 'max:64'],
            'user_question' => ['nullable', 'string', 'max:2000'],
        ]);
        $instrument = (string) $validated['instrument'];
        $userQuestion = trim((string) ($validated['user_question'] ?? ''));

        if ($bot->type === 'openrouter_llm_analyst') {
            $analysis = $engine->runManualChartAnalysis($bot, $instrument, $userQuestion);
        } else {
            $analysis = $engine->explainInstrumentDecision($bot, $instrument);
        }

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
            'analysis_metrics' => $scope === 'hour'
                ? $this->analysisMetricsLastHour($bot)
                : $this->analysisMetricsAllTime($bot),
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

    /**
     * @return array<string,mixed>
     */
    private function analysisMetricsAllTime(Bot $bot): array
    {
        if (! Schema::hasTable('bot_analysis_runs')) {
            return ['enabled' => false];
        }

        $row = DB::table('bot_analysis_runs')
            ->where('bot_id', $bot->id)
            ->selectRaw("
                COUNT(*) as analysis_count,
                SUM(CASE WHEN status = 'ok' THEN 1 ELSE 0 END) as ok_count,
                SUM(CASE WHEN status = 'skipped' THEN 1 ELSE 0 END) as skipped_count,
                SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as error_count,
                AVG(latency_ms) as avg_latency_ms
            ")
            ->first();
        $analysisCount = (int) ($row->analysis_count ?? 0);
        $okCount = (int) ($row->ok_count ?? 0);
        $skippedCount = (int) ($row->skipped_count ?? 0);
        $errorCount = (int) ($row->error_count ?? 0);
        $signalCount = (int) $bot->signals()->count();
        $tradeCount = (int) $bot->trades()->count();

        $skipBreakdown = DB::table('bot_analysis_runs')
            ->where('bot_id', $bot->id)
            ->where('status', 'skipped')
            ->selectRaw('skip_reason, COUNT(*) as c')
            ->groupBy('skip_reason')
            ->orderByDesc('c')
            ->get()
            ->mapWithKeys(fn ($r) => [(string) ($r->skip_reason ?? 'unknown') => (int) $r->c])
            ->all();

        return [
            'enabled' => true,
            'analysis_count' => $analysisCount,
            'ok_count' => $okCount,
            'skipped_count' => $skippedCount,
            'error_count' => $errorCount,
            'avg_latency_ms' => $row?->avg_latency_ms !== null ? round((float) $row->avg_latency_ms, 2) : null,
            'signal_count' => $signalCount,
            'trade_count' => $tradeCount,
            'conversion_analysis_to_signal' => $analysisCount > 0 ? round($signalCount / $analysisCount, 4) : null,
            'conversion_signal_to_trade' => $signalCount > 0 ? round($tradeCount / $signalCount, 4) : null,
            'conversion_analysis_to_trade' => $analysisCount > 0 ? round($tradeCount / $analysisCount, 4) : null,
            'skip_breakdown' => $skipBreakdown,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function analysisMetricsLastHour(Bot $bot): array
    {
        if (! Schema::hasTable('bot_analysis_runs')) {
            return ['enabled' => false];
        }
        $since = now()->subHour();
        $row = DB::table('bot_analysis_runs')
            ->where('bot_id', $bot->id)
            ->where('started_at', '>=', $since)
            ->selectRaw("
                COUNT(*) as analysis_count,
                SUM(CASE WHEN status = 'ok' THEN 1 ELSE 0 END) as ok_count,
                SUM(CASE WHEN status = 'skipped' THEN 1 ELSE 0 END) as skipped_count,
                SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as error_count,
                AVG(latency_ms) as avg_latency_ms
            ")
            ->first();
        $analysisCount = (int) ($row->analysis_count ?? 0);
        $signalCount = (int) $bot->signals()->where('signal_at', '>=', $since)->count();
        $tradeCount = (int) $bot->trades()->where('entry_time', '>=', $since)->count();

        $skipBreakdown = DB::table('bot_analysis_runs')
            ->where('bot_id', $bot->id)
            ->where('started_at', '>=', $since)
            ->where('status', 'skipped')
            ->selectRaw('skip_reason, COUNT(*) as c')
            ->groupBy('skip_reason')
            ->orderByDesc('c')
            ->get()
            ->mapWithKeys(fn ($r) => [(string) ($r->skip_reason ?? 'unknown') => (int) $r->c])
            ->all();

        return [
            'enabled' => true,
            'analysis_count' => $analysisCount,
            'ok_count' => (int) ($row->ok_count ?? 0),
            'skipped_count' => (int) ($row->skipped_count ?? 0),
            'error_count' => (int) ($row->error_count ?? 0),
            'avg_latency_ms' => $row?->avg_latency_ms !== null ? round((float) $row->avg_latency_ms, 2) : null,
            'signal_count' => $signalCount,
            'trade_count' => $tradeCount,
            'conversion_analysis_to_signal' => $analysisCount > 0 ? round($signalCount / $analysisCount, 4) : null,
            'conversion_signal_to_trade' => $signalCount > 0 ? round($tradeCount / $signalCount, 4) : null,
            'conversion_analysis_to_trade' => $analysisCount > 0 ? round($tradeCount / $analysisCount, 4) : null,
            'skip_breakdown' => $skipBreakdown,
        ];
    }

    public function show(Bot $bot, BotEngineService $engine): View
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
            // Секундные таймфреймы используют отдельные ID (4xxx), чтобы не конфликтовать
            // с минутными значениями 5/15/30, которые уже давно живут в UI/API.
            ['minutes' => 4005, 'label' => 'S5'],
            ['minutes' => 4015, 'label' => 'S15'],
            ['minutes' => 4030, 'label' => 'S30'],
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

        $scanMinPayout = (float) $engine->runtimeSettings($bot)['min_payout'];

        return view('bots.show', [
            'bot' => $bot,
            'isChartAnalystBot' => $bot->type === 'openrouter_llm_analyst',
            'analystSmcFlowEnabled' => $bot->type === 'openrouter_llm_analyst'
                && \App\Support\OpenRouterLlmConfig::analystSmcFlowEnabled(is_array($bot->config) ? $bot->config : []),
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
            'scanMinPayout' => $scanMinPayout,
            'scanProgress' => BotScanProgress::snapshot($bot->id, $scanMinPayout),
        ]);
    }

    /**
     * История сигналов для графика (entry + итог на экспирации).
     * Нужен отдельный endpoint, чтобы UI подхватывал закрытие сделок без перезагрузки страницы.
     */
    /**
     * Индикатор Price Action (DGT): расчёт на сервере по тем же свечам, что и график.
     * Параметры: таблица indicator_settings (ключ price_action_dgt), иначе legacy из config бота, иначе дефолты сервиса.
     */
    public function indicatorsData(
        Request $request,
        Bot $bot,
        CandleService $candleService,
        PriceActionDgtIndicatorService $pa,
        LiquiditySwingsIndicatorService $liquiditySwings,
        SmcFlowIndicatorService $smcFlow,
    ): JsonResponse {
        $this->ensureBotOwner($bot);

        $validated = $request->validate([
            'instrument' => ['required', 'string', 'max:64'],
            'timeframe' => ['nullable', 'integer', Rule::in(CandleService::DASHBOARD_TIMEFRAMES)],
        ]);

        $instrument = (string) $validated['instrument'];
        $timeframe = (int) ($validated['timeframe'] ?? 1);

        // Тот же горизонт, что у /structures: последние сутки + до 5000 баров (как max_bars_back в Pine).
        $since = time() - 86400;
        $rows = $candleService->seriesForDashboard($instrument, $timeframe, $since, null, 5000);

        $params = IndicatorSetting::mergedPriceActionDgtParams($bot);

        $barSeconds = PriceActionDgtIndicatorService::barSecondsForTimeframe($timeframe);
        $payload = $pa->compute($rows, $params, $barSeconds);
        // Один проход по свечам: Liquidity Swings лёгкий относительно DGT, отдаём в том же JSON (слой на графике включается отдельно).
        $lsParams = IndicatorSetting::mergedLiquiditySwingsParams($bot);
        $liquidityPayload = $liquiditySwings->compute($rows, $lsParams);
        $smcParams = IndicatorSetting::mergedSmcFlowParams($bot);
        $smcPayload = $smcFlow->compute($rows, $smcParams);

        return response()->json([
            'indicator' => $payload,
            'liquidity_swings' => $liquidityPayload,
            'smc_flow' => $smcPayload,
            'instrument' => $instrument,
            'timeframe' => $timeframe,
            'server_time' => now()->toIso8601String(),
        ]);
    }

    /**
     * Ордерблоки и FVG для графика бота — параметры из indicator_settings этого бота.
     */
    public function structuresData(
        Request $request,
        Bot $bot,
        CandleStructureZoneDetector $detector,
        CandleService $candleService,
    ): JsonResponse {
        $this->ensureBotOwner($bot);

        $validated = $request->validate([
            'instrument' => ['required', 'string', 'max:63'],
            'timeframe' => ['nullable', 'integer', Rule::in(CandleService::DASHBOARD_TIMEFRAMES)],
        ]);

        $instrument = (string) $validated['instrument'];
        $timeframe = (int) ($validated['timeframe'] ?? 1);
        $since = time() - 86400;
        $rows = $candleService->seriesForDashboard($instrument, $timeframe, $since, null, null);
        $obParams = IndicatorSetting::mergedOrderBlocksParams($bot);
        $zones = $detector->activeZones($rows, $obParams);

        return response()->json(['zones' => $zones]);
    }

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
     * @return list<array{id:string,name:string,pricing?:array{prompt:float|null,completion:float|null}|null,pricing_label?:string}>
     */
    private function openRouterModelsForSettings(OpenRouterClient $openRouterClient): array
    {
        try {
            return $this->normalizeOpenRouterModelsForSettings($openRouterClient->listModels());
        } catch (Throwable $e) {
            Log::warning('openrouter_models_for_settings_failed', [
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Дополняет pricing_label, если в записи есть pricing, но нет готовой подписи.
     *
     * @param  list<array<string,mixed>>  $models
     * @return list<array<string,mixed>>
     */
    private function normalizeOpenRouterModelsForSettings(array $models): array
    {
        $out = [];
        foreach ($models as $model) {
            if (! is_array($model)) {
                continue;
            }
            $pricingLabel = trim((string) ($model['pricing_label'] ?? ''));
            if ($pricingLabel === '') {
                $rawPricing = $model['pricing'] ?? null;
                $normalized = is_array($rawPricing)
                    ? OpenRouterClient::normalizePricing($rawPricing)
                    : null;
                $model['pricing_label'] = OpenRouterClient::formatPricingLabel($normalized);
            }
            $out[] = $model;
        }

        return $out;
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
