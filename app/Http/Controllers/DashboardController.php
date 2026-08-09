<?php

namespace App\Http\Controllers;

use App\Models\Bot;
use App\Models\BotTrade;
use App\Models\IndicatorSetting;
use App\Models\PocketInstrument;
use App\Services\CandleService;
use App\Services\CandleStructureZoneDetector;
use App\Services\CurrentCandleCacheService;
use App\Services\Trading\ManualPocketOrderService;
use App\Services\Indicators\AtrIndicatorService;
use App\Services\Indicators\BollingerIndicatorService;
use App\Services\Indicators\EmaIndicatorService;
use App\Services\Indicators\LiquiditySwingsIndicatorService;
use App\Services\Indicators\MacdIndicatorService;
use App\Services\Indicators\PriceActionDgtIndicatorService;
use App\Services\Indicators\RsiIndicatorService;
use App\Services\Indicators\SmaIndicatorService;
use App\Services\Indicators\SmcFlowIndicatorService;
use App\Services\Indicators\CciIndicatorService;
use App\Services\Indicators\StochasticIndicatorService;
use App\Services\Indicators\VwmaIndicatorService;
use App\Services\Indicators\KeltnerIndicatorService;
use App\Services\Indicators\EnvelopsIndicatorService;
use App\Services\Indicators\ParabolicSarIndicatorService;
use App\Services\Indicators\SuperTrendIndicatorService;
use App\Services\Indicators\AdxIndicatorService;
use App\Services\Indicators\AlligatorIndicatorService;
use App\Services\Indicators\AoIndicatorService;
use App\Services\Indicators\AroonIndicatorService;
use App\Services\Indicators\HmaIndicatorService;
use App\Services\Indicators\IchimokuIndicatorService;
use App\Services\Indicators\NrtrIndicatorService;
use App\Services\Indicators\PriceChannelIndicatorService;
use App\Services\Indicators\WilliamsRangeIndicatorService;
use App\Services\Indicators\CmoIndicatorService;
use App\Services\Indicators\DemarkerIndicatorService;
use App\Services\Indicators\FractalIndicatorService;
use App\Services\Indicators\IvashovRangeIndicatorService;
use App\Services\Indicators\KalmanIndicatorService;
use App\Services\Indicators\LinRegChannelIndicatorService;
use App\Services\Indicators\LinRegIndicatorService;
use App\Services\Indicators\MomentumIndicatorService;
use App\Services\Indicators\OsmaIndicatorService;
use App\Services\Indicators\RocIndicatorService;
use App\Services\Indicators\RviIndicatorService;
use App\Services\Indicators\SmaChannelIndicatorService;
use App\Services\Indicators\SmiIndicatorService;
use App\Services\Indicators\StdDevIndicatorService;
use App\Services\Indicators\TrixIndicatorService;
use App\Services\Indicators\UltimateIndicatorService;
use App\Services\Indicators\DonchianIndicatorService;
use App\Services\Indicators\StochRsiIndicatorService;
use App\Services\Indicators\AcIndicatorService;
use App\Services\Indicators\BullsPowerIndicatorService;
use App\Services\Indicators\BearsPowerIndicatorService;
use App\Services\Indicators\DpoIndicatorService;
use App\Services\Indicators\QstickIndicatorService;
use App\Services\Indicators\VhFilterIndicatorService;
use App\Services\Indicators\EfficiencyRatioIndicatorService;
use App\Services\Indicators\RaviIndicatorService;
use App\Services\Indicators\CogIndicatorService;
use App\Services\Indicators\PivotFloorIndicatorService;
use App\Services\Indicators\MassIndexIndicatorService;
use App\Services\Indicators\NrtrWatrIndicatorService;
use App\Services\Indicators\PriceChannelAdaptiveIndicatorService;
use App\Services\Indicators\PriceChannelOffsetIndicatorService;
use App\Services\Indicators\ParabolicBollingerIndicatorService;
use App\Services\Indicators\ParabolicPriceChannelIndicatorService;
use App\Services\Indicators\AtrChannelIndicatorService;
use App\Services\Indicators\OffsetSmaIndicatorService;
use App\Services\Indicators\OffsetEmaIndicatorService;
use App\Services\Indicators\OffsetSsmaIndicatorService;
use App\Services\Indicators\VolatilityAverageIndicatorService;
use App\Services\Indicators\VolatilityAverageChannelIndicatorService;
use App\Services\Indicators\PriceOscillatorIndicatorService;
use App\Services\Indicators\DynamicTrendDetectorIndicatorService;
use App\Services\Indicators\AdaptiveLookBackIndicatorService;
use App\Services\Indicators\LinRegChannelFastIndicatorService;
use App\Services\Indicators\VolatilityCandlesIndicatorService;
use App\Services\Indicators\ZigZagIndicatorService;
use App\Services\Indicators\VolatilityAverageTwiceIndicatorService;
use App\Services\Indicators\VolatilityStagesAWIndicatorService;
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
            // Дефолты правой панели CALL/PUT: сумма и готовность SSID из профиля.
            'manualTrade' => app(ManualPocketOrderService::class)->panelDefaults(auth()->user()),
        ]);
    }

    /** Бот для ссылок настройки оверлеев главного графика (если ботов нет — в шаблоне ведём на список ботов). */
    private function chartIndicatorBotForUser(): ?Bot
    {
        return Bot::query()
            ->where('user_id', (int) auth()->id())
            // Контейнер ручных сделок не используем как якорь индикаторов.
            ->where('type', '!=', \App\Support\ManualTradeConfig::BOT_TYPE)
            ->orderBy('id')
            ->first();
    }

    /**
     * Окно и лимит баров для оверлеев (structures / PA / LS / SMC).
     * На S5/S15/S30 не тянем 24ч — достаточно ~500 баров для зон и индикаторов.
     *
     * @return array{0: int, 1: int|null} [sinceUnix, maxBars]
     */
    private function overlayHistoryWindow(int $timeframe): array
    {
        if (in_array($timeframe, CandleService::DASHBOARD_SECOND_TIMEFRAMES, true)) {
            $barSec = PriceActionDgtIndicatorService::barSecondsForTimeframe($timeframe);
            $maxBars = 500;

            return [time() - ($maxBars * $barSec), $maxBars];
        }

        return [time() - 86400, 5000];
    }

    /**
     * Окно свечей именно для /indicators/classic.
     *
     * Общий overlayHistoryWindow(24ч / до 5000 баров) на M1 даёт ~1200+ минут + тяжёлые
     * VolStages/ZigZag → ответ 10–20с. Фронт параллельно каждые 15с инвалидирует in-flight
     * через classicIndicatorsFetchSeq — ответ никогда не применяется, нижняя pane «не появляется».
     * Режем до ~500 баров: SMA/RSI/каналам хватает, ответ укладывается в 1–2с.
     *
     * @return array{0:int,1:int} [sinceUnix, maxBars]
     */
    private function classicIndicatorsHistoryWindow(int $timeframe): array
    {
        if (in_array($timeframe, CandleService::DASHBOARD_SECOND_TIMEFRAMES, true)) {
            return $this->overlayHistoryWindow($timeframe);
        }

        $maxBars = 500;
        // timeframe на дашборде — минуты (1, 5, 15…); секунды бара = minutes * 60.
        $barSec = max(60, $timeframe * 60);

        return [time() - ($maxBars * $barSec), $maxBars];
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

        // before поддерживаем на всех TF: фронт догружает историю скроллом влево (loadMore).
        // Раньше для TF≠1 отдавали [] — на M5/M15 нельзя было набрать больше initial-окна.

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

        // Микро-цены (NGN/LBP OTC и т.п.): единые 8 знаков вместо фиксированных 5.
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
        [$since, $maxBars] = $this->overlayHistoryWindow($timeframe);

        // Структуры должны использовать ту же временную ось, что и candles endpoint:
        // без повторного timezone-преобразования.
        $rows = $candleService->seriesForDashboard($instrument, $timeframe, $since, null, $maxBars);

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
        [$since, $maxBars] = $this->overlayHistoryWindow($timeframe);
        $rows = $candleService->seriesForDashboard($instrument, $timeframe, $since, null, $maxBars);
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
        [$since, $maxBars] = $this->overlayHistoryWindow($timeframe);
        $rows = $candleService->seriesForDashboard($instrument, $timeframe, $since, null, $maxBars);
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
        [$since, $maxBars] = $this->overlayHistoryWindow($timeframe);
        $rows = $candleService->seriesForDashboard($instrument, $timeframe, $since, null, $maxBars);
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

    /**
     * Классика OsEngine (BB/RSI/SMA/EMA/VWMA/ATR/MACD/Stochastic/CCI) для главного графика.
     * Параметры — с якорного бота (как PA/SMC); без бота — defaultParams сервисов.
     * Query layers[] — считаем только включённые слои.
     */
    public function classicIndicatorsData(
        Request $request,
        CandleService $candleService,
        BollingerIndicatorService $bollinger,
        RsiIndicatorService $rsi,
        SmaIndicatorService $sma,
        EmaIndicatorService $ema,
        AtrIndicatorService $atr,
        MacdIndicatorService $macd,
        StochasticIndicatorService $stochastic,
        CciIndicatorService $cci,
        VwmaIndicatorService $vwma,
        KeltnerIndicatorService $keltner,
        EnvelopsIndicatorService $envelops,
        ParabolicSarIndicatorService $parabolicSar,
        SuperTrendIndicatorService $superTrend,
        AdxIndicatorService $adx,
        WilliamsRangeIndicatorService $williams,
        AoIndicatorService $ao,
        AlligatorIndicatorService $alligator,
        IchimokuIndicatorService $ichimoku,
        PriceChannelIndicatorService $priceChannel,
        NrtrIndicatorService $nrtr,
        HmaIndicatorService $hma,
        AroonIndicatorService $aroon,
        MomentumIndicatorService $momentum,
        RocIndicatorService $roc,
        OsmaIndicatorService $osma,
        TrixIndicatorService $trix,
        CmoIndicatorService $cmo,
        DemarkerIndicatorService $demarker,
        RviIndicatorService $rvi,
        UltimateIndicatorService $ultimate,
        SmiIndicatorService $smi,
        StdDevIndicatorService $stddev,
        SmaChannelIndicatorService $smaChannel,
        IvashovRangeIndicatorService $ivashov,
        LinRegIndicatorService $linreg,
        LinRegChannelIndicatorService $linregChannel,
        KalmanIndicatorService $kalman,
        FractalIndicatorService $fractal,
        DonchianIndicatorService $donchian,
        StochRsiIndicatorService $stochRsi,
        AcIndicatorService $ac,
        BullsPowerIndicatorService $bullsPower,
        BearsPowerIndicatorService $bearsPower,
        DpoIndicatorService $dpo,
        QstickIndicatorService $qstick,
        VhFilterIndicatorService $vhFilter,
        EfficiencyRatioIndicatorService $efficiencyRatio,
        RaviIndicatorService $ravi,
        CogIndicatorService $cog,
        PivotFloorIndicatorService $pivotFloor,
        MassIndexIndicatorService $massIndex,
        NrtrWatrIndicatorService $nrtrWatr,
        PriceChannelAdaptiveIndicatorService $priceChannelAdaptive,
        PriceChannelOffsetIndicatorService $priceChannelOffset,
        ParabolicBollingerIndicatorService $parabolicBollinger,
        ParabolicPriceChannelIndicatorService $parabolicPriceChannel,
        AtrChannelIndicatorService $atrChannel,
        OffsetSmaIndicatorService $offsetSma,
        OffsetEmaIndicatorService $offsetEma,
        OffsetSsmaIndicatorService $offsetSsma,
        VolatilityAverageIndicatorService $volatilityAverage,
        VolatilityAverageChannelIndicatorService $volatilityAverageChannel,
        PriceOscillatorIndicatorService $priceOscillator,
        DynamicTrendDetectorIndicatorService $dynamicTrendDetector,
        AdaptiveLookBackIndicatorService $adaptiveLookBack,
        LinRegChannelFastIndicatorService $linRegChannelFast,
        VolatilityCandlesIndicatorService $volatilityCandles,
        ZigZagIndicatorService $zigZag,
        VolatilityAverageTwiceIndicatorService $volatilityAverageTwice,
        VolatilityStagesAWIndicatorService $volatilityStagesAw,
    ): JsonResponse {
        $validated = $request->validate([
            'instrument' => ['required', 'string', 'max:64'],
            'timeframe' => ['nullable', 'integer', Rule::in(CandleService::DASHBOARD_TIMEFRAMES)],
            'layers' => ['nullable', 'array'],
            // OHLC-пакет (Donchian…Mass Index) — те же ключи, что на bots/show.
            'layers.*' => ['string', Rule::in(['bollinger', 'rsi', 'sma', 'ema', 'vwma', 'atr', 'macd', 'stochastic', 'cci', 'keltner', 'envelops', 'parabolic_sar', 'supertrend', 'adx', 'williams', 'ao', 'alligator', 'ichimoku', 'price_channel', 'nrtr', 'hma', 'aroon', 'momentum', 'roc', 'osma', 'trix', 'cmo', 'demarker', 'rvi', 'ultimate', 'smi', 'stddev', 'sma_channel', 'ivashov', 'linreg', 'linreg_channel', 'kalman', 'fractal', 'donchian', 'stoch_rsi', 'ac', 'bulls_power', 'bears_power', 'dpo', 'qstick', 'vh_filter', 'efficiency_ratio', 'ravi', 'cog', 'pivot_floor', 'mass_index', 'nrtr_watr', 'price_channel_adaptive', 'price_channel_offset', 'parabolic_bollinger', 'parabolic_price_channel', 'atr_channel', 'offset_sma', 'offset_ema', 'offset_ssma', 'volatility_average', 'volatility_average_channel', 'price_oscillator', 'dynamic_trend_detector', 'adaptive_look_back', 'linreg_channel_fast', 'volatility_candles', 'zigzag', 'volatility_average_twice', 'volatility_stages_aw'])],
        ]);

        $instrument = (string) $validated['instrument'];
        $timeframe = (int) ($validated['timeframe'] ?? 1);
        $layers = $validated['layers'] ?? [];
        $want = array_fill_keys($layers, true);

        // Пустой layers[] — ничего не считаем (фронт так и шлёт, когда все сняты).
        if ($want === []) {
            return response()->json([
                'instrument' => $instrument,
                'timeframe' => $timeframe,
                'server_time' => now()->toIso8601String(),
            ]);
        }

        // Classic — отдельное «короткое» окно: см. classicIndicatorsHistoryWindow().
        [$since, $maxBars] = $this->classicIndicatorsHistoryWindow($timeframe);
        $rows = $candleService->seriesForDashboard($instrument, $timeframe, $since, null, $maxBars);
        $anchorBot = $this->chartIndicatorBotForUser();

        $json = [
            'instrument' => $instrument,
            'timeframe' => $timeframe,
            'server_time' => now()->toIso8601String(),
        ];

        if (! empty($want['bollinger'])) {
            $json['bollinger'] = $bollinger->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedBollingerParams($anchorBot)
                    : BollingerIndicatorService::defaultParams()
            );
        }
        if (! empty($want['rsi'])) {
            $json['rsi'] = $rsi->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedRsiParams($anchorBot)
                    : RsiIndicatorService::defaultParams()
            );
        }
        if (! empty($want['sma'])) {
            $json['sma'] = $sma->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedSmaParams($anchorBot)
                    : SmaIndicatorService::defaultParams()
            );
        }
        if (! empty($want['ema'])) {
            $json['ema'] = $ema->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedEmaParams($anchorBot)
                    : EmaIndicatorService::defaultParams()
            );
        }
        if (! empty($want['vwma'])) {
            $json['vwma'] = $vwma->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedVwmaParams($anchorBot)
                    : VwmaIndicatorService::defaultParams()
            );
        }
        if (! empty($want['atr'])) {
            $json['atr'] = $atr->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedAtrParams($anchorBot)
                    : AtrIndicatorService::defaultParams()
            );
        }
        if (! empty($want['macd'])) {
            $json['macd'] = $macd->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedMacdParams($anchorBot)
                    : MacdIndicatorService::defaultParams()
            );
        }
        if (! empty($want['stochastic'])) {
            $json['stochastic'] = $stochastic->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedStochasticParams($anchorBot)
                    : StochasticIndicatorService::defaultParams()
            );
        }
        if (! empty($want['cci'])) {
            $json['cci'] = $cci->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedCciParams($anchorBot)
                    : CciIndicatorService::defaultParams()
            );
        }
        if (! empty($want['keltner'])) {
            $json['keltner'] = $keltner->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedKeltnerParams($anchorBot)
                    : KeltnerIndicatorService::defaultParams()
            );
        }
        if (! empty($want['envelops'])) {
            $json['envelops'] = $envelops->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedEnvelopsParams($anchorBot)
                    : EnvelopsIndicatorService::defaultParams()
            );
        }
        if (! empty($want['parabolic_sar'])) {
            $json['parabolic_sar'] = $parabolicSar->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedParabolicSarParams($anchorBot)
                    : ParabolicSarIndicatorService::defaultParams()
            );
        }
        if (! empty($want['supertrend'])) {
            $json['supertrend'] = $superTrend->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedSuperTrendParams($anchorBot)
                    : SuperTrendIndicatorService::defaultParams()
            );
        }
        if (! empty($want['adx'])) {
            $json['adx'] = $adx->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedAdxParams($anchorBot)
                    : AdxIndicatorService::defaultParams()
            );
        }
        if (! empty($want['williams'])) {
            $json['williams'] = $williams->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedWilliamsParams($anchorBot)
                    : WilliamsRangeIndicatorService::defaultParams()
            );
        }
        if (! empty($want['ao'])) {
            $json['ao'] = $ao->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedAoParams($anchorBot)
                    : AoIndicatorService::defaultParams()
            );
        }
        if (! empty($want['alligator'])) {
            $json['alligator'] = $alligator->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedAlligatorParams($anchorBot)
                    : AlligatorIndicatorService::defaultParams()
            );
        }
        if (! empty($want['ichimoku'])) {
            $json['ichimoku'] = $ichimoku->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedIchimokuParams($anchorBot)
                    : IchimokuIndicatorService::defaultParams()
            );
        }
        if (! empty($want['price_channel'])) {
            $json['price_channel'] = $priceChannel->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedPriceChannelParams($anchorBot)
                    : PriceChannelIndicatorService::defaultParams()
            );
        }
        if (! empty($want['nrtr'])) {
            $json['nrtr'] = $nrtr->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedNrtrParams($anchorBot)
                    : NrtrIndicatorService::defaultParams()
            );
        }
        if (! empty($want['hma'])) {
            $json['hma'] = $hma->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedHmaParams($anchorBot)
                    : HmaIndicatorService::defaultParams()
            );
        }
        if (! empty($want['aroon'])) {
            $json['aroon'] = $aroon->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedAroonParams($anchorBot)
                    : AroonIndicatorService::defaultParams()
            );
        }
        if (! empty($want['momentum'])) {
            $json['momentum'] = $momentum->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedMomentumParams($anchorBot)
                    : MomentumIndicatorService::defaultParams()
            );
        }
        if (! empty($want['roc'])) {
            $json['roc'] = $roc->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedRocParams($anchorBot)
                    : RocIndicatorService::defaultParams()
            );
        }
        if (! empty($want['osma'])) {
            $json['osma'] = $osma->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedOsmaParams($anchorBot)
                    : OsmaIndicatorService::defaultParams()
            );
        }
        if (! empty($want['trix'])) {
            $json['trix'] = $trix->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedTrixParams($anchorBot)
                    : TrixIndicatorService::defaultParams()
            );
        }
        if (! empty($want['cmo'])) {
            $json['cmo'] = $cmo->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedCmoParams($anchorBot)
                    : CmoIndicatorService::defaultParams()
            );
        }
        if (! empty($want['demarker'])) {
            $json['demarker'] = $demarker->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedDemarkerParams($anchorBot)
                    : DemarkerIndicatorService::defaultParams()
            );
        }
        if (! empty($want['rvi'])) {
            $json['rvi'] = $rvi->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedRviParams($anchorBot)
                    : RviIndicatorService::defaultParams()
            );
        }
        if (! empty($want['ultimate'])) {
            $json['ultimate'] = $ultimate->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedUltimateParams($anchorBot)
                    : UltimateIndicatorService::defaultParams()
            );
        }
        if (! empty($want['smi'])) {
            $json['smi'] = $smi->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedSmiParams($anchorBot)
                    : SmiIndicatorService::defaultParams()
            );
        }
        if (! empty($want['stddev'])) {
            $json['stddev'] = $stddev->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedStdDevParams($anchorBot)
                    : StdDevIndicatorService::defaultParams()
            );
        }
        if (! empty($want['sma_channel'])) {
            $json['sma_channel'] = $smaChannel->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedSmaChannelParams($anchorBot)
                    : SmaChannelIndicatorService::defaultParams()
            );
        }
        if (! empty($want['ivashov'])) {
            $json['ivashov'] = $ivashov->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedIvashovParams($anchorBot)
                    : IvashovRangeIndicatorService::defaultParams()
            );
        }
        if (! empty($want['linreg'])) {
            $json['linreg'] = $linreg->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedLinRegParams($anchorBot)
                    : LinRegIndicatorService::defaultParams()
            );
        }
        if (! empty($want['linreg_channel'])) {
            $json['linreg_channel'] = $linregChannel->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedLinRegChannelParams($anchorBot)
                    : LinRegChannelIndicatorService::defaultParams()
            );
        }
        if (! empty($want['kalman'])) {
            $json['kalman'] = $kalman->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedKalmanParams($anchorBot)
                    : KalmanIndicatorService::defaultParams()
            );
        }
        if (! empty($want['fractal'])) {
            $json['fractal'] = $fractal->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedFractalParams($anchorBot)
                    : FractalIndicatorService::defaultParams()
            );
        }
        // --- OHLC-пакет OsEngine (без объёма): те же merge/default, что на графике бота ---
        if (! empty($want['donchian'])) {
            $json['donchian'] = $donchian->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedDonchianParams($anchorBot)
                    : DonchianIndicatorService::defaultParams()
            );
        }
        if (! empty($want['stoch_rsi'])) {
            $json['stoch_rsi'] = $stochRsi->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedStochRsiParams($anchorBot)
                    : StochRsiIndicatorService::defaultParams()
            );
        }
        if (! empty($want['ac'])) {
            $json['ac'] = $ac->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedAcParams($anchorBot)
                    : AcIndicatorService::defaultParams()
            );
        }
        if (! empty($want['bulls_power'])) {
            $json['bulls_power'] = $bullsPower->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedBullsPowerParams($anchorBot)
                    : BullsPowerIndicatorService::defaultParams()
            );
        }
        if (! empty($want['bears_power'])) {
            $json['bears_power'] = $bearsPower->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedBearsPowerParams($anchorBot)
                    : BearsPowerIndicatorService::defaultParams()
            );
        }
        if (! empty($want['dpo'])) {
            $json['dpo'] = $dpo->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedDpoParams($anchorBot)
                    : DpoIndicatorService::defaultParams()
            );
        }
        if (! empty($want['qstick'])) {
            $json['qstick'] = $qstick->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedQstickParams($anchorBot)
                    : QstickIndicatorService::defaultParams()
            );
        }
        if (! empty($want['vh_filter'])) {
            $json['vh_filter'] = $vhFilter->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedVhFilterParams($anchorBot)
                    : VhFilterIndicatorService::defaultParams()
            );
        }
        if (! empty($want['efficiency_ratio'])) {
            $json['efficiency_ratio'] = $efficiencyRatio->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedEfficiencyRatioParams($anchorBot)
                    : EfficiencyRatioIndicatorService::defaultParams()
            );
        }
        if (! empty($want['ravi'])) {
            $json['ravi'] = $ravi->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedRaviParams($anchorBot)
                    : RaviIndicatorService::defaultParams()
            );
        }
        if (! empty($want['cog'])) {
            $json['cog'] = $cog->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedCogParams($anchorBot)
                    : CogIndicatorService::defaultParams()
            );
        }
        if (! empty($want['pivot_floor'])) {
            $json['pivot_floor'] = $pivotFloor->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedPivotFloorParams($anchorBot)
                    : PivotFloorIndicatorService::defaultParams()
            );
        }
        if (! empty($want['mass_index'])) {
            $json['mass_index'] = $massIndex->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedMassIndexParams($anchorBot)
                    : MassIndexIndicatorService::defaultParams()
            );
        }
        if (! empty($want['nrtr_watr'])) {
            $json['nrtr_watr'] = $nrtrWatr->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedNrtrWatrParams($anchorBot)
                    : NrtrWatrIndicatorService::defaultParams()
            );
        }
        if (! empty($want['price_channel_adaptive'])) {
            $json['price_channel_adaptive'] = $priceChannelAdaptive->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedPriceChannelAdaptiveParams($anchorBot)
                    : PriceChannelAdaptiveIndicatorService::defaultParams()
            );
        }
        if (! empty($want['price_channel_offset'])) {
            $json['price_channel_offset'] = $priceChannelOffset->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedPriceChannelOffsetParams($anchorBot)
                    : PriceChannelOffsetIndicatorService::defaultParams()
            );
        }
        if (! empty($want['parabolic_bollinger'])) {
            $json['parabolic_bollinger'] = $parabolicBollinger->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedParabolicBollingerParams($anchorBot)
                    : ParabolicBollingerIndicatorService::defaultParams()
            );
        }
        if (! empty($want['parabolic_price_channel'])) {
            $json['parabolic_price_channel'] = $parabolicPriceChannel->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedParabolicPriceChannelParams($anchorBot)
                    : ParabolicPriceChannelIndicatorService::defaultParams()
            );
        }
        if (! empty($want['atr_channel'])) {
            $json['atr_channel'] = $atrChannel->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedAtrChannelParams($anchorBot)
                    : AtrChannelIndicatorService::defaultParams()
            );
        }
        if (! empty($want['offset_sma'])) {
            $json['offset_sma'] = $offsetSma->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedOffsetSmaParams($anchorBot)
                    : OffsetSmaIndicatorService::defaultParams()
            );
        }
        if (! empty($want['offset_ema'])) {
            $json['offset_ema'] = $offsetEma->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedOffsetEmaParams($anchorBot)
                    : OffsetEmaIndicatorService::defaultParams()
            );
        }
        if (! empty($want['offset_ssma'])) {
            $json['offset_ssma'] = $offsetSsma->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedOffsetSsmaParams($anchorBot)
                    : OffsetSsmaIndicatorService::defaultParams()
            );
        }
        if (! empty($want['volatility_average'])) {
            $json['volatility_average'] = $volatilityAverage->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedVolatilityAverageParams($anchorBot)
                    : VolatilityAverageIndicatorService::defaultParams()
            );
        }
        if (! empty($want['volatility_average_channel'])) {
            $json['volatility_average_channel'] = $volatilityAverageChannel->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedVolatilityAverageChannelParams($anchorBot)
                    : VolatilityAverageChannelIndicatorService::defaultParams()
            );
        }
        if (! empty($want['price_oscillator'])) {
            $json['price_oscillator'] = $priceOscillator->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedPriceOscillatorParams($anchorBot)
                    : PriceOscillatorIndicatorService::defaultParams()
            );
        }
        if (! empty($want['dynamic_trend_detector'])) {
            $json['dynamic_trend_detector'] = $dynamicTrendDetector->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedDynamicTrendDetectorParams($anchorBot)
                    : DynamicTrendDetectorIndicatorService::defaultParams()
            );
        }
        if (! empty($want['adaptive_look_back'])) {
            $json['adaptive_look_back'] = $adaptiveLookBack->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedAdaptiveLookBackParams($anchorBot)
                    : AdaptiveLookBackIndicatorService::defaultParams()
            );
        }
        if (! empty($want['linreg_channel_fast'])) {
            $json['linreg_channel_fast'] = $linRegChannelFast->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedLinRegChannelFastParams($anchorBot)
                    : LinRegChannelFastIndicatorService::defaultParams()
            );
        }
        if (! empty($want['volatility_candles'])) {
            $json['volatility_candles'] = $volatilityCandles->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedVolatilityCandlesParams($anchorBot)
                    : VolatilityCandlesIndicatorService::defaultParams()
            );
        }
        if (! empty($want['zigzag'])) {
            $json['zigzag'] = $zigZag->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedZigZagParams($anchorBot)
                    : ZigZagIndicatorService::defaultParams()
            );
        }
        if (! empty($want['volatility_average_twice'])) {
            $json['volatility_average_twice'] = $volatilityAverageTwice->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedVolatilityAverageTwiceParams($anchorBot)
                    : VolatilityAverageTwiceIndicatorService::defaultParams()
            );
        }
        if (! empty($want['volatility_stages_aw'])) {
            $json['volatility_stages_aw'] = $volatilityStagesAw->compute(
                $rows,
                $anchorBot !== null
                    ? IndicatorSetting::mergedVolatilityStagesAWParams($anchorBot)
                    : VolatilityStagesAWIndicatorService::defaultParams()
            );
        }

        // Заголовки для лейблов на графике («RSI 14», «MACD 12 26 9») — как на Pocket Option.
        $json = \App\Support\ClassicIndicatorChartTitle::enrich($json, $anchorBot);

        return response()->json($json);
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
     * Сделки пользователя по инструменту для маркеров на главном графике.
     * Берём все боты текущего юзера: открытые + недавняя история (24ч / до 500).
     * Формат signals совместим с bots/show (chartSignalsPayload).
     */
    public function chartTradesData(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'instrument' => ['required', 'string', 'max:64'],
        ]);
        $instrument = (string) $validated['instrument'];
        $userId = (int) auth()->id();

        if ($userId <= 0) {
            return response()->json([
                'signals' => [],
                'open_trades' => [],
                'count' => 0,
                'server_time' => now()->toIso8601String(),
            ]);
        }

        $base = BotTrade::query()
            ->where('instrument', $instrument)
            ->whereHas('bot', static fn ($q) => $q->where('user_id', $userId));

        // Открытые позиции — для маркеров ● OPEN.
        $openTrades = (clone $base)
            ->where('status', BotTrade::STATUS_OPEN)
            ->where('expiry_time', '>', now())
            ->orderBy('expiry_time')
            ->limit(50)
            ->get([
                'id',
                'instrument',
                'side',
                'entry_price',
                'entry_time',
                'expiry_time',
                'status',
                'result',
            ]);

        // История для ВВЕРХ/ВНИЗ + WIN/LOSE: открытые + закрытые за сутки.
        $historyTrades = (clone $base)
            ->with('signal')
            ->where(function ($q) {
                $q->where(function ($open) {
                    $open->where('status', BotTrade::STATUS_OPEN)
                        ->where('expiry_time', '>', now());
                })->orWhere(function ($settled) {
                    $settled->where('status', BotTrade::STATUS_SETTLED)
                        ->where('entry_time', '>=', now()->subDay());
                });
            })
            ->orderBy('entry_time')
            ->limit(500)
            ->get();

        $signals = $historyTrades->map(function (BotTrade $t): array {
            $sig = $t->relationLoaded('signal') ? $t->signal : null;

            return [
                'id' => $sig !== null ? (int) $sig->id : 0,
                'instrument' => (string) $t->instrument,
                'side' => (string) $t->side,
                // Время входа: signal_at предпочтительнее entry_time (как на bots/show).
                'signal_at' => optional($sig?->signal_at ?? $t->entry_time)->toIso8601String(),
                'expiry_time' => optional($t->expiry_time)->toIso8601String(),
                'result' => (string) ($t->result ?? ''),
                'trade' => [
                    'id' => (int) $t->id,
                    'expiry_time' => optional($t->expiry_time)->toIso8601String(),
                    'result' => (string) ($t->result ?? ''),
                    'entry_price' => $t->entry_price !== null ? (float) $t->entry_price : null,
                    'expiry_price' => $t->expiry_price !== null ? (float) $t->expiry_price : null,
                ],
            ];
        })->values()->all();

        $openPayload = $openTrades->map(static fn (BotTrade $t): array => [
            'id' => (int) $t->id,
            'instrument' => (string) $t->instrument,
            'side' => (string) $t->side,
            'entry_price' => $t->entry_price !== null ? (float) $t->entry_price : null,
            'entry_ts' => optional($t->entry_time)?->timestamp,
            'expiry_ts' => optional($t->expiry_time)?->timestamp,
        ])->values()->all();

        return response()->json([
            'signals' => $signals,
            'open_trades' => $openPayload,
            'count' => count($signals),
            'server_time' => now()->toIso8601String(),
        ]);
    }

}
