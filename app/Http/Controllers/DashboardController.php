<?php

namespace App\Http\Controllers;

use App\Models\Bot;
use App\Models\BotTrade;
use App\Models\IndicatorSetting;
use App\Models\PocketInstrument;
use App\Services\CandleService;
use App\Services\CandleStructureZoneDetector;
use App\Services\CurrentCandleCacheService;
use App\Services\Indicators\LiquiditySwingsIndicatorService;
use App\Services\Indicators\PriceActionDgtIndicatorService;
use App\Services\Indicators\SmcFlowIndicatorService;
use App\Support\M1CandleCountdown;
use App\Support\PriceScale;
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
            // Секундные таймфреймы держим на выделенных ID, чтобы избежать конфликта
            // с историческими минутными TF (5/15/30 минут).
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

        return view('dashboard', [
            'instruments' => $instruments,
            'openTradeInstruments' => $openTradeInstruments,
            'nearestExpiryByInstrument' => $sidebar['nearest_expiry_ts'],
            'timeframes' => $timeframes,
            // Первый бот пользователя — якорь для шестерёнок «Индикаторы» (те же страницы, что у графика бота).
            'chartIndicatorBot' => $this->chartIndicatorBotForUser(),
        ]);
    }

    /** Бот для ссылок настройки оверлеев главного графика (если ботов нет — в шаблоне ведём на список ботов). */
    private function chartIndicatorBotForUser(): ?Bot
    {
        return Bot::query()
            ->where('user_id', (int) auth()->id())
            ->orderBy('id')
            ->first();
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
            // Разрешаем более длинную историю, чтобы на графике было видно больше свечей.
            'limit' => 'nullable|integer|min:1|max:5000',
            'before' => 'nullable|integer',
            'since' => 'nullable|integer|min:0',
            'include_partial' => 'nullable|boolean',
        ]);
        $instrument = $request->input('instrument');
        $timeframe = (int) ($request->input('timeframe') ?? 1);
        $limit = (int) ($request->input('limit') ?? 3000);
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
        // Строгая UTC-политика без ручных сдвигов:
        // сериализуем данные графика в том же unix-time, что хранится в БД.
        // Любое локальное время (например Europe/Kyiv) должно строиться только на UI-слое
        // при форматировании подписи, а не через модификацию timestamp в API.

        $data = array_map(fn (array $c) => [
            'time' => $c['time'],
            'open' => (float) $c['open'],
            'high' => (float) $c['high'],
            'low' => (float) $c['low'],
            'close' => (float) $c['close'],
            'volume' => round($c['volume'] ?? 0, 2),
            'is_partial' => false,
        ], $rows);

        // Partial-свечу поддерживаем для ВСЕХ таймфреймов графика.
        // Подход единый:
        // 1) базовый live-source всегда S5 partial из current-candle cache;
        // 2) для выбранного TF вычисляем bucket_start;
        // 3) если последний бар уже тот же bucket -> replace (живое обновление без "новой свечи");
        // 4) если bucket новый -> append.
        //
        // Это устраняет одинаковую проблему на всех TF, когда "живая" свеча фактически
        // обновлялась только шагом закрытых S5-баров (каждые 5 сек).
        if ($includePartial && $before === null) {
            $partial = $currentCandleCache->getPartial($instrument);
            if ($partial && $currentCandleCache->isFresh($partial, 30)) {
                $livePartial = $candleService->liveBarFromS5Partial($instrument, $timeframe, $partial);
                if ($livePartial !== null) {
                    $liveTime = (int) $livePartial['time'];
                    $lastClosedTime = ! empty($data) ? (int) end($data)['time'] : 0;
                    if ($liveTime > $lastClosedTime) {
                        $data[] = $livePartial;
                    } elseif ($liveTime === $lastClosedTime && $data !== []) {
                        $data[array_key_last($data)] = $livePartial;
                    }
                }
            }
        }
        // Feed debug метаданные:
        // - raw_feed_time: timestamp "как пришёл" от источника;
        // - effective_feed_time: timestamp, который реально используем в платформе;
        // - ingest_received_at: когда backend принял partial.
        // Эти поля помогают понять, где именно возникает рассинхрон (feed vs ingest vs UI).
        $lastClosedCandleTime = $data !== [] ? (int) end($data)['time'] : null;
        $feedDebug = [
            'raw_feed_time' => null,
            'effective_feed_time' => null,
            'ingest_received_at' => null,
            'last_closed_candle_time' => $lastClosedCandleTime,
        ];
        if (isset($partial) && is_array($partial)) {
            $rawFeedTime = (int) ($partial['raw_feed_time'] ?? 0);
            $effectiveFeedTime = (int) ($partial['effective_feed_time'] ?? ($partial['time'] ?? 0));
            $ingestReceivedAt = (int) ($partial['ingest_received_at'] ?? ($partial['updated_at'] ?? 0));
            $feedDebug['raw_feed_time'] = $rawFeedTime > 0 ? $rawFeedTime : null;
            $feedDebug['effective_feed_time'] = $effectiveFeedTime > 0 ? $effectiveFeedTime : null;
            $feedDebug['ingest_received_at'] = $ingestReceivedAt > 0 ? $ingestReceivedAt : null;
        }

        $lastOpen = null;
        if ($timeframe === 1 && $data !== []) {
            $lastOpen = (int) end($data)['time'];
        }

        $m1SecondsToClose = ($lastOpen !== null && $lastOpen > 0)
            ? M1CandleCountdown::secondsToClose($lastOpen, $serverUnix)
            : null;

        // LBP/USD OTC и другие микро-цены: адаптивная точность вместо фиксированных 5 знаков.
        $priceDecimals = PriceScale::resolveFromCandles($data);
        $data = array_map(static fn (array $c) => [
            'time' => $c['time'],
            'open' => PriceScale::roundPrice((float) $c['open'], $priceDecimals),
            'high' => PriceScale::roundPrice((float) $c['high'], $priceDecimals),
            'low' => PriceScale::roundPrice((float) $c['low'], $priceDecimals),
            'close' => PriceScale::roundPrice((float) $c['close'], $priceDecimals),
            'volume' => round((float) ($c['volume'] ?? 0), 2),
            'is_partial' => (bool) ($c['is_partial'] ?? false),
        ], $data);

        return response()->json([
            'candles' => $data,
            'server_unix' => $serverUnix,
            'feed_debug' => $feedDebug,
            'm1_seconds_to_close' => $m1SecondsToClose,
            'price_scale' => PriceScale::chartFormat($priceDecimals),
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

        // Структуры должны использовать ту же временную ось, что и candles endpoint:
        // без повторного timezone-преобразования.
        $rows = $candleService->seriesForDashboard($instrument, $timeframe, $since, null, null);

        $anchorBot = $this->chartIndicatorBotForUser();
        $obParams = $anchorBot !== null
            ? IndicatorSetting::mergedOrderBlocksParams($anchorBot)
            : null;

        $zones = $detector->activeZones($rows, $obParams);

        return response()->json(['zones' => $zones]);
    }

    /**
     * Price Action (DGT) для главного графика: те же свечи, что у /structures.
     * Параметры — как у первого бота пользователя (шестерёнка ведёт на ту же страницу сохранения), иначе дефолты сервиса.
     */
    public function priceActionDgtIndicatorData(
        Request $request,
        CandleService $candleService,
        PriceActionDgtIndicatorService $pa,
    ): JsonResponse {
        $request->validate([
            'instrument' => ['required', 'string', 'max:64'],
            'timeframe' => ['nullable', 'integer', Rule::in(CandleService::DASHBOARD_TIMEFRAMES)],
        ]);
        $instrument = (string) $request->input('instrument');
        $timeframe = (int) ($request->input('timeframe') ?? 1);
        $since = time() - 86400;
        $rows = $candleService->seriesForDashboard($instrument, $timeframe, $since, null, 5000);
        $anchorBot = $this->chartIndicatorBotForUser();
        $params = $anchorBot !== null
            ? IndicatorSetting::mergedPriceActionDgtParams($anchorBot)
            : PriceActionDgtIndicatorService::defaultParams();
        $barSeconds = PriceActionDgtIndicatorService::barSecondsForTimeframe($timeframe);
        $payload = $pa->compute($rows, $params, $barSeconds);

        return response()->json([
            'indicator' => $payload,
            'instrument' => $instrument,
            'timeframe' => $timeframe,
            'server_time' => now()->toIso8601String(),
        ]);
    }

    /**
     * Оверлей Liquidity Swings (по мотивам LuxAlgo / indicator2.txt): те же свечи и горизонт, что у /structures.
     */
    public function liquiditySwingsIndicatorData(
        Request $request,
        CandleService $candleService,
        LiquiditySwingsIndicatorService $liquiditySwings,
    ): JsonResponse {
        $request->validate([
            'instrument' => ['required', 'string', 'max:64'],
            'timeframe' => ['nullable', 'integer', Rule::in(CandleService::DASHBOARD_TIMEFRAMES)],
        ]);
        $instrument = (string) $request->input('instrument');
        $timeframe = (int) ($request->input('timeframe') ?? 1);
        $since = time() - 86400;
        $rows = $candleService->seriesForDashboard($instrument, $timeframe, $since, null, 5000);
        $anchorBot = $this->chartIndicatorBotForUser();
        $lsParams = $anchorBot !== null
            ? IndicatorSetting::mergedLiquiditySwingsParams($anchorBot)
            : LiquiditySwingsIndicatorService::defaultParams();
        $payload = $liquiditySwings->compute($rows, $lsParams);

        return response()->json([
            'liquidity_swings' => $payload,
            'instrument' => $instrument,
            'timeframe' => $timeframe,
            'server_time' => now()->toIso8601String(),
        ]);
    }

    /**
     * Оверлей SMC Flow (UT Bot, зоны/линии/лейблы): те же свечи, что у /candles и /structures.
     * Параметры берутся из настроек «якорного» бота пользователя (chartIndicatorBotForUser).
     */
    public function smcFlowIndicatorData(
        Request $request,
        CandleService $candleService,
        SmcFlowIndicatorService $smcFlow,
    ): JsonResponse {
        $request->validate([
            'instrument' => ['required', 'string', 'max:64'],
            'timeframe' => ['nullable', 'integer', Rule::in(CandleService::DASHBOARD_TIMEFRAMES)],
        ]);
        $instrument = (string) $request->input('instrument');
        $timeframe = (int) ($request->input('timeframe') ?? 1);
        $since = time() - 86400;
        $rows = $candleService->seriesForDashboard($instrument, $timeframe, $since, null, 5000);
        $anchorBot = $this->chartIndicatorBotForUser();
        $smcParams = $anchorBot !== null
            ? IndicatorSetting::mergedSmcFlowParams($anchorBot)
            : SmcFlowIndicatorService::defaultParams();
        $payload = $smcFlow->compute($rows, $smcParams);

        return response()->json([
            'smc_flow' => $payload,
            'instrument' => $instrument,
            'timeframe' => $timeframe,
            'server_time' => now()->toIso8601String(),
        ]);
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
