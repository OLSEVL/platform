@extends('layouts.app')
@section('title', 'Графики')
@section('main_class', 'w-full max-w-none flex-1 min-h-0 p-0 m-0 overflow-hidden flex flex-col')
@section('content')
<style>
    #instrument-list {
        scrollbar-gutter: stable;
        scrollbar-width: thin;
        scrollbar-color: #52525b #27272a;
    }
    #instrument-list::-webkit-scrollbar {
        width: 8px;
    }
    #instrument-list::-webkit-scrollbar-track {
        background: #27272a;
    }
    #instrument-list::-webkit-scrollbar-thumb {
        background: #52525b;
        border-radius: 4px;
    }
</style>
{{-- Классы ниже нужны Tailwind CDN (выставляются из JS) --}}
<span class="sr-only pointer-events-none bg-blue-900/40 bg-blue-900/50 bg-stone-800/45 text-stone-200" aria-hidden="true"></span>
<div class="flex min-h-0 flex-1 w-full gap-0 overflow-hidden">
    {{-- Список инструментов слева --}}
    <aside class="w-56 shrink-0 bg-zinc-900 border-r border-zinc-800 overflow-hidden flex flex-col min-h-0">
        <div class="shrink-0 p-3 border-b border-zinc-800 bg-zinc-900/80">
            <div class="relative">
                <label for="instrument-search" class="sr-only">Поиск инструмента</label>
                <input
                    id="instrument-search"
                    type="text"
                    placeholder="Поиск инструмента..."
                    autocomplete="off"
                    class="w-full rounded border border-zinc-700 bg-zinc-800 px-3 py-2 pr-8 text-sm text-zinc-100 placeholder-zinc-500 focus:outline-none focus:ring-2 focus:ring-blue-500"
                >
                <button
                    id="instrument-search-clear"
                    type="button"
                    aria-label="Очистить поиск"
                    class="hidden absolute right-2 top-1/2 -translate-y-1/2 text-zinc-400 hover:text-zinc-200 focus:outline-none"
                >
                    ✕
                </button>
            </div>
        </div>
        <ul id="instrument-list" class="min-h-0 flex-1 overflow-y-auto overscroll-y-contain divide-y divide-zinc-800">
            @forelse($instruments as $inst)
                <li>
                    <button type="button"
                        class="instrument-btn w-full text-left px-4 py-3 hover:bg-zinc-800 focus:bg-zinc-800 focus:outline-none transition-colors {{ $openTradeInstruments->contains($inst->instrument) ? 'bg-stone-800/45' : '' }}"
                        data-instrument="{{ $inst->instrument }}"
                        data-display-name="{{ e($inst->display_name ?? $inst->instrument) }}"
                        data-search="{{ mb_strtolower(($inst->display_name ?? $inst->instrument).' '.$inst->instrument) }}"
                        data-open-trade="{{ $openTradeInstruments->contains($inst->instrument) ? '1' : '0' }}">
                        <div class="flex items-center justify-between gap-2">
                            <div class="flex min-w-0 flex-1 items-center gap-1.5">
                                <span class="font-medium text-zinc-100 truncate">{{ $inst->display_name ?? $inst->instrument }}</span>
                                @if($openTradeInstruments->contains($inst->instrument) && isset($nearestExpiryByInstrument[$inst->instrument]))
                                    <span class="instrument-trade-timer shrink-0 text-xs font-mono tabular-nums text-stone-200" data-expiry-ts="{{ (int) $nearestExpiryByInstrument[$inst->instrument] }}">{{ str_pad((string) intdiv(max(0, (int) $nearestExpiryByInstrument[$inst->instrument] - now()->timestamp), 60), 2, '0', STR_PAD_LEFT) }}:{{ str_pad((string) (max(0, (int) $nearestExpiryByInstrument[$inst->instrument] - now()->timestamp) % 60), 2, '0', STR_PAD_LEFT) }}</span>
                                @endif
                            </div>
                        <span
                            class="instrument-payout text-xs font-medium {{ $inst->current_payout === null ? 'text-zinc-500' : (((float) $inst->current_payout >= 80) ? 'text-emerald-400' : (((float) $inst->current_payout >= 60) ? 'text-amber-400' : 'text-rose-400')) }}"
                            data-instrument="{{ $inst->instrument }}"
                            data-payout="{{ $inst->current_payout !== null ? (string) ((float) $inst->current_payout) : '' }}"
                        >{{ $inst->current_payout !== null ? number_format((float) $inst->current_payout, 0).'%' : '—' }}</span>
                    </div>
                </button>
            </li>
            @empty
            <li class="px-4 py-6 text-sm text-zinc-500">Нет активных инструментов.</li>
            @endforelse
        </ul>
    </aside>

    {{-- Область графика --}}
    <div class="flex-1 flex flex-col bg-zinc-950 overflow-hidden min-w-0 min-h-0">
        <div class="p-3 border-b border-zinc-800 bg-zinc-900/80 flex items-center justify-between gap-2">
            <div class="flex items-center gap-3 min-w-0 flex-wrap">
                <h2
                    id="chart-title"
                    class="font-semibold text-zinc-100 truncate cursor-pointer"
                    title="Кликните, чтобы скопировать название инструмента"
                >Выберите инструмент</h2>
                <div class="relative shrink-0" id="tf-dropdown-wrap">
                    <button type="button" id="tf-dropdown-btn"
                        class="text-sm px-2 py-1 rounded border border-zinc-600 bg-zinc-800 text-zinc-200 hover:bg-zinc-700">
                        <span id="tf-dropdown-label">1м</span> ▾
                    </button>
                    <div id="tf-dropdown-panel"
                        class="hidden absolute left-0 top-full mt-1 z-20 bg-zinc-900 border border-zinc-700 rounded shadow-lg shadow-black/40 py-1 min-w-[5.5rem]">
                        @foreach($timeframes as $tf)
                        <button type="button"
                            class="tf-option w-full text-left text-sm px-3 py-1.5 text-zinc-200 hover:bg-zinc-800"
                            data-minutes="{{ $tf['minutes'] }}"
                            data-label="{{ $tf['label'] }}">{{ $tf['label'] }}</button>
                        @endforeach
                    </div>
                </div>
                <div class="relative shrink-0" id="layers-dropdown-wrap">
                    <button type="button" id="layers-dropdown-btn"
                        class="text-sm px-2 py-1 rounded border border-zinc-600 bg-zinc-800 text-zinc-200 hover:bg-zinc-700">
                        Индикаторы ▾
                    </button>
                    {{-- Как на странице бота: у каждого слоя своя шестерёнка → те же маршруты настроек (бот-якорь из контроллера). --}}
                    @include('partials.chart-indicators-popup', [
                        'indicatorBot' => $chartIndicatorBot ?? null,
                        'showLlmLevels' => false,
                    ])
                </div>
            </div>
            <span id="chart-loading" class="text-sm text-zinc-500 hidden shrink-0">Загрузка…</span>
        </div>
        <div id="chart-container" class="flex-1 min-h-0 relative">
            <div id="chart-placeholder" class="absolute inset-0 flex items-center justify-center text-zinc-500 bg-zinc-950/90 z-0">
                Нажмите на инструмент слева, чтобы показать минутный график
            </div>
            <div id="chart-wrapper" class="absolute inset-0 hidden z-[1]"></div>
            <div id="structure-overlay" class="absolute inset-0 z-[5] pointer-events-none overflow-visible hidden"></div>
            {{-- Liquidity Swings: между OB/FVG и DGT (как на референсе TradingView / Screenshot_4). --}}
            <div id="liquidity-swings-overlay" class="absolute inset-0 z-[6] pointer-events-none overflow-visible hidden"></div>
            {{-- Оверлей индикатора DGT выше зон OB/FVG (как на странице бота). --}}
            <div id="price-action-overlay" class="absolute inset-0 z-[7] pointer-events-none overflow-visible hidden"></div>
            {{-- SMC Flow (UT Bot): поверх DGT, отдельный слой — не смешиваем с Price Action. --}}
            <div id="smc-flow-overlay" class="absolute inset-0 z-[8] pointer-events-none overflow-visible hidden"></div>
            <div id="candle-timer-overlay"
                class="pointer-events-none absolute z-[6] hidden h-0 w-0 overflow-visible"
                title="До закрытия текущей минутной свечи (по времени открытия бара с графика, мм:сс)">
                <span id="candle-tf-label"
                    class="absolute left-1/2 z-[6] -translate-x-1/2 whitespace-nowrap text-[11px] font-medium leading-none text-zinc-400">M1</span>
                <span id="candle-close-timer"
                    class="absolute left-1/2 z-[6] -translate-x-1/2 whitespace-nowrap font-mono text-[11px] tabular-nums leading-none text-zinc-400"
                    aria-live="polite"></span>
            </div>
        </div>
    </div>
</div>

@include('partials.chart-markers-helper')
<script src="https://unpkg.com/lightweight-charts@5.2.0/dist/lightweight-charts.standalone.production.js"></script>
<script>
(function() {
    const CANDLES_URL = '{{ route("candles.data") }}';
    const STRUCTURES_URL = '{{ route("structures.data") }}';
    const PA_INDICATOR_URL = '{{ route("dashboard.indicators.price-action-dgt") }}';
    const LS_INDICATOR_URL = '{{ route("dashboard.indicators.liquidity-swings") }}';
    const SMC_INDICATOR_URL = '{{ route("dashboard.indicators.smc-flow") }}';
    // Классика OsEngine: BB/RSI/SMA/EMA/ATR (params с якорного бота).
    const CLASSIC_INDICATORS_URL = '{{ route("dashboard.indicators.classic") }}';
    const INSTRUMENTS_SIDEBAR_URL = '{{ route("dashboard.instruments.data") }}';
    // Сделки всех ботов пользователя по текущему инструменту → маркеры WIN/LOSE/OPEN.
    const CHART_TRADES_URL = '{{ route("dashboard.chart-trades.data") }}';

    // Окна загрузки разделены на три режима, чтобы переключение между инструментами
    // было быстрым, но при этом сохранялись lazy-догрузка истории и live-обновление.
    //
    // 1) INITIAL — что грузим при первом показе инструмента (selectInstrument / reloadChartData).
    //    Минутные TF: 2 часа (~110 M1-баров). Секундные (S5/S15/S30): окно привязано к viewport
    //    (~130 баров), чтобы не тянуть 1440 S5-свечей на каждый клик.
    // 2) MAX — максимальная глубина истории, до которой можно догружать через loadMoreCandles
    //    при скролле графика влево. Сохраняем прежние 24 часа как «потолок».
    // 3) POLL — узкое окно для регулярного auto-refresh: достаточно последних минут, чтобы
    //    стабильно получить последний закрытый бар + текущую partial. Поллингу не нужна
    //    вся история — наоборот, при широком окне он перетирает уже догруженные пользователем
    //    бары слева в applyIncrementalChartUpdate.
    const INITIAL_WINDOW_SEC = 2 * 3600;
    const MAX_WINDOW_SEC = 24 * 3600;
    const POLL_WINDOW_SEC = 5 * 60;

    // Лимиты баров в одном ответе:
    // - INITIAL_LIMIT — для минутных TF; секундные считаются через initialLimitForTf().
    // - LOAD_MORE_LIMIT используется при ленивой подгрузке старшей истории.
    // - POLL_LIMIT мал и нужен только для апдейта хвоста.
    const INITIAL_LIMIT = 1500;
    const LOAD_MORE_LIMIT = 1440;
    const POLL_LIMIT = 32;
    /** Запас баров поверх CHART_DEFAULT_VISIBLE_BARS для initial-окна секундных TF. */
    const INITIAL_VISIBLE_BUFFER_BARS = 20;

    // Секундные ID (4005/4015/4030) и минутные (1,2,3...) — единая шкала для poll/таймера/PA.
    function timeframeSeconds(tf) {
        if (tf === 4005) return 5;
        if (tf === 4015) return 15;
        if (tf === 4030) return 30;
        return Math.max(1, Number(tf) || 1) * 60;
    }
    function timeframeLabel(tf) {
        if (tf === 4005) return 'S5';
        if (tf === 4015) return 'S15';
        if (tf === 4030) return 'S30';
        return 'M' + String(tf);
    }
    function isSecondTimeframe(tf) {
        return tf === 4005 || tf === 4015 || tf === 4030;
    }

    const M1_COUNTDOWN_SECONDS = 60;
    /**
     * Таймер M1: сервер в каждом ответе /candles отдаёт m1_seconds_to_close (учёт смещения шкалы time свечей vs PHP time).
     * Между опросами убываем локально по performance.now(), без смешивания «часов» на клиенте.
     */
    let m1CloseCountdownAnchorSec = null;
    let m1CloseCountdownAnchorPerfMs = null;
    let tfCloseCountdownAnchorSec = null;
    let tfCloseCountdownAnchorPerfMs = null;

    function applyM1CountdownFromPayload(payload) {
        if (!payload || typeof payload.m1_seconds_to_close !== 'number' || !Number.isFinite(payload.m1_seconds_to_close)) {
            m1CloseCountdownAnchorSec = null;
            m1CloseCountdownAnchorPerfMs = null;
            return;
        }
        m1CloseCountdownAnchorSec = Math.max(0, Math.min(M1_COUNTDOWN_SECONDS, payload.m1_seconds_to_close));
        m1CloseCountdownAnchorPerfMs = performance.now();
    }

    function m1CountdownLeftNow() {
        if (m1CloseCountdownAnchorSec === null || m1CloseCountdownAnchorPerfMs === null) {
            return null;
        }
        const elapsed = (performance.now() - m1CloseCountdownAnchorPerfMs) / 1000;
        return Math.max(0, Math.min(M1_COUNTDOWN_SECONDS, m1CloseCountdownAnchorSec - elapsed));
    }

    /** Обратный отсчёт до закрытия бара для S5/S15/S30 и прочих не-M1 TF (по server_unix из /candles). */
    function applyGenericCountdownFromPayload(payload, tf) {
        const periodSec = timeframeSeconds(tf);
        if (
            !payload ||
            !Array.isArray(payload.candles) ||
            payload.candles.length === 0 ||
            typeof payload.server_unix !== 'number' ||
            !Number.isFinite(payload.server_unix)
        ) {
            tfCloseCountdownAnchorSec = null;
            tfCloseCountdownAnchorPerfMs = null;
            return;
        }
        const last = payload.candles[payload.candles.length - 1];
        const lastOpen = Number(last && last.time);
        if (!Number.isFinite(lastOpen)) {
            tfCloseCountdownAnchorSec = null;
            tfCloseCountdownAnchorPerfMs = null;
            return;
        }
        const closeTs = lastOpen + periodSec;
        const left = Math.max(0, Math.min(periodSec, closeTs - Number(payload.server_unix)));
        tfCloseCountdownAnchorSec = left;
        tfCloseCountdownAnchorPerfMs = performance.now();
    }

    function genericCountdownLeftNow(tf) {
        const periodSec = timeframeSeconds(tf);
        if (tfCloseCountdownAnchorSec === null || tfCloseCountdownAnchorPerfMs === null) {
            return null;
        }
        const elapsed = (performance.now() - tfCloseCountdownAnchorPerfMs) / 1000;
        return Math.max(0, Math.min(periodSec, tfCloseCountdownAnchorSec - elapsed));
    }
    /**
     * Горизонталь таймера: xCenter = x0 + (x1 - x0) * t, где x0/x1 — якорь последнего бара и слот справа (≈ barSpacing).
     * t = 0 — у x0; t = 1 — на один интервал вправо; t = 1.5 / 2 — ещё дальше (полтора/два интервала). Только нижняя граница 0.
     */
    const CANDLE_TIMER_GAP_T = 3;

    const chartPlaceholder = document.getElementById('chart-placeholder');
    const chartWrapper = document.getElementById('chart-wrapper');
    const structureOverlay = document.getElementById('structure-overlay');
    const candleTimerOverlay = document.getElementById('candle-timer-overlay');
    const chartTitle = document.getElementById('chart-title');
    const chartLoading = document.getElementById('chart-loading');
    const tfBtn = document.getElementById('tf-dropdown-btn');
    const tfPanel = document.getElementById('tf-dropdown-panel');
    const tfLabelEl = document.getElementById('tf-dropdown-label');
    const candleCloseTimerEl = document.getElementById('candle-close-timer');
    const candleTfLabelEl = document.getElementById('candle-tf-label');
    const layersBtn = document.getElementById('layers-dropdown-btn');
    const layersPanel = document.getElementById('layers-dropdown-panel');
    const layerOb = document.getElementById('layer-ob');
    const layerFvg = document.getElementById('layer-fvg');
    const layerPa = document.getElementById('layer-pa-dgt');
    const layerLiquidity = document.getElementById('layer-liquidity-swings');
    const layerSmc = document.getElementById('layer-smc-flow');
    const layerBollinger = document.getElementById('layer-bollinger');
    const layerRsi = document.getElementById('layer-rsi');
    const layerSma = document.getElementById('layer-sma');
    const layerEma = document.getElementById('layer-ema');
    const layerVwma = document.getElementById('layer-vwma');
    const layerAtr = document.getElementById('layer-atr');
    const layerMacd = document.getElementById('layer-macd');
    const layerStochastic = document.getElementById('layer-stochastic');
    const layerCci = document.getElementById('layer-cci');
    const layerKeltner = document.getElementById('layer-keltner');
    const layerEnvelops = document.getElementById('layer-envelops');
    const layerParabolicSar = document.getElementById('layer-parabolic-sar');
    const layerSuperTrend = document.getElementById('layer-supertrend');
    const layerAdx = document.getElementById('layer-adx');
    const layerWilliams = document.getElementById('layer-williams');
    const layerAo = document.getElementById('layer-ao');
    const layerAlligator = document.getElementById('layer-alligator');
    const layerIchimoku = document.getElementById('layer-ichimoku');
    const layerPriceChannel = document.getElementById('layer-price-channel');
    const layerNrtr = document.getElementById('layer-nrtr');
    const layerHma = document.getElementById('layer-hma');
    const layerAroon = document.getElementById('layer-aroon');
    // 16 новых классических индикаторов OsEngine.
    const layerMomentum = document.getElementById('layer-momentum');
    const layerRoc = document.getElementById('layer-roc');
    const layerOsma = document.getElementById('layer-osma');
    const layerTrix = document.getElementById('layer-trix');
    const layerCmo = document.getElementById('layer-cmo');
    const layerDemarker = document.getElementById('layer-demarker');
    const layerRvi = document.getElementById('layer-rvi');
    const layerUltimate = document.getElementById('layer-ultimate');
    const layerSmi = document.getElementById('layer-smi');
    const layerStdDev = document.getElementById('layer-stddev');
    const layerSmaChannel = document.getElementById('layer-sma-channel');
    const layerIvashov = document.getElementById('layer-ivashov');
    const layerLinReg = document.getElementById('layer-linreg');
    const layerLinRegChannel = document.getElementById('layer-linreg-channel');
    const layerKalman = document.getElementById('layer-kalman');
    const layerFractal = document.getElementById('layer-fractal');
    const priceActionOverlay = document.getElementById('price-action-overlay');
    const liquiditySwingsOverlay = document.getElementById('liquidity-swings-overlay');
    const smcFlowOverlay = document.getElementById('smc-flow-overlay');
    const instrumentSearchInput = document.getElementById('instrument-search');
    const instrumentSearchClearBtn = document.getElementById('instrument-search-clear');
    const instrumentListEl = document.getElementById('instrument-list');

    // v4: classic OsEngine (bollinger/rsi/sma/ema/atr). При отсутствии ключа тянем v3 без потери старых prefs.
    const LAYER_PREFS_KEY = 'platform.dashboard.chartLayers.v5';

    function loadLayerPrefs() {
        try {
            let raw = localStorage.getItem(LAYER_PREFS_KEY);
            if (raw == null) {
                raw = localStorage.getItem('platform.dashboard.chartLayers.v4');
            }
            if (raw == null) {
                raw = localStorage.getItem('platform.dashboard.chartLayers.v3');
            }
            if (raw == null) {
                raw = localStorage.getItem('platform.dashboard.chartLayers.v2');
            }
            if (raw == null) {
                try {
                    const legacy = localStorage.getItem('platform.dashboard.chartLayers');
                    if (legacy == null) return;
                    const o = JSON.parse(legacy);
                    if (typeof o.orderBlocks === 'boolean') layerOb.checked = o.orderBlocks;
                    if (typeof o.imbalances === 'boolean') layerFvg.checked = o.imbalances;
                } catch (_) { /* ignore */ }
                return;
            }
            const o = JSON.parse(raw);
            if (typeof o.orderBlocks === 'boolean') layerOb.checked = o.orderBlocks;
            if (typeof o.imbalances === 'boolean') layerFvg.checked = o.imbalances;
            if (layerPa && typeof o.paDgt === 'boolean') layerPa.checked = o.paDgt;
            if (layerLiquidity && typeof o.liquiditySwings === 'boolean') layerLiquidity.checked = o.liquiditySwings;
            if (layerSmc && typeof o.smcFlow === 'boolean') layerSmc.checked = o.smcFlow;
            if (layerBollinger && typeof o.bollinger === 'boolean') layerBollinger.checked = o.bollinger;
            if (layerRsi && typeof o.rsi === 'boolean') layerRsi.checked = o.rsi;
            if (layerSma && typeof o.sma === 'boolean') layerSma.checked = o.sma;
            if (layerEma && typeof o.ema === 'boolean') layerEma.checked = o.ema;
            if (layerVwma && typeof o.vwma === 'boolean') layerVwma.checked = o.vwma;
            if (layerAtr && typeof o.atr === 'boolean') layerAtr.checked = o.atr;
            if (layerMacd && typeof o.macd === 'boolean') layerMacd.checked = o.macd;
            if (layerStochastic && typeof o.stochastic === 'boolean') layerStochastic.checked = o.stochastic;
            if (layerCci && typeof o.cci === 'boolean') layerCci.checked = o.cci;
            if (layerKeltner && typeof o.keltner === 'boolean') layerKeltner.checked = o.keltner;
            if (layerEnvelops && typeof o.envelops === 'boolean') layerEnvelops.checked = o.envelops;
            if (layerParabolicSar && typeof o.parabolicSar === 'boolean') layerParabolicSar.checked = o.parabolicSar;
            if (layerSuperTrend && typeof o.supertrend === 'boolean') layerSuperTrend.checked = o.supertrend;
            if (layerAdx && typeof o.adx === 'boolean') layerAdx.checked = o.adx;
            if (layerWilliams && typeof o.williams === 'boolean') layerWilliams.checked = o.williams;
            if (layerAo && typeof o.ao === 'boolean') layerAo.checked = o.ao;
            if (layerAlligator && typeof o.alligator === 'boolean') layerAlligator.checked = o.alligator;
            if (layerIchimoku && typeof o.ichimoku === 'boolean') layerIchimoku.checked = o.ichimoku;
            if (layerPriceChannel && typeof o.price_channel === 'boolean') layerPriceChannel.checked = o.price_channel;
            if (layerNrtr && typeof o.nrtr === 'boolean') layerNrtr.checked = o.nrtr;
            if (layerHma && typeof o.hma === 'boolean') layerHma.checked = o.hma;
            if (layerAroon && typeof o.aroon === 'boolean') layerAroon.checked = o.aroon;
            if (layerMomentum && typeof o.momentum === 'boolean') layerMomentum.checked = o.momentum;
            if (layerRoc && typeof o.roc === 'boolean') layerRoc.checked = o.roc;
            if (layerOsma && typeof o.osma === 'boolean') layerOsma.checked = o.osma;
            if (layerTrix && typeof o.trix === 'boolean') layerTrix.checked = o.trix;
            if (layerCmo && typeof o.cmo === 'boolean') layerCmo.checked = o.cmo;
            if (layerDemarker && typeof o.demarker === 'boolean') layerDemarker.checked = o.demarker;
            if (layerRvi && typeof o.rvi === 'boolean') layerRvi.checked = o.rvi;
            if (layerUltimate && typeof o.ultimate === 'boolean') layerUltimate.checked = o.ultimate;
            if (layerSmi && typeof o.smi === 'boolean') layerSmi.checked = o.smi;
            if (layerStdDev && typeof o.stddev === 'boolean') layerStdDev.checked = o.stddev;
            if (layerSmaChannel && typeof o.smaChannel === 'boolean') layerSmaChannel.checked = o.smaChannel;
            if (layerIvashov && typeof o.ivashov === 'boolean') layerIvashov.checked = o.ivashov;
            if (layerLinReg && typeof o.linreg === 'boolean') layerLinReg.checked = o.linreg;
            if (layerLinRegChannel && typeof o.linregChannel === 'boolean') layerLinRegChannel.checked = o.linregChannel;
            if (layerKalman && typeof o.kalman === 'boolean') layerKalman.checked = o.kalman;
            if (layerFractal && typeof o.fractal === 'boolean') layerFractal.checked = o.fractal;
        } catch (_) { /* ignore */ }
    }

    function saveLayerPrefs() {
        try {
            localStorage.setItem(LAYER_PREFS_KEY, JSON.stringify({
                orderBlocks: layerOb.checked,
                imbalances: layerFvg.checked,
                paDgt: layerPa ? layerPa.checked : false,
                liquiditySwings: layerLiquidity ? layerLiquidity.checked : false,
                smcFlow: layerSmc ? layerSmc.checked : false,
                bollinger: layerBollinger ? layerBollinger.checked : false,
                rsi: layerRsi ? layerRsi.checked : false,
                sma: layerSma ? layerSma.checked : false,
                ema: layerEma ? layerEma.checked : false,
                vwma: layerVwma ? layerVwma.checked : false,
                atr: layerAtr ? layerAtr.checked : false,
                macd: layerMacd ? layerMacd.checked : false,
                stochastic: layerStochastic ? layerStochastic.checked : false,
                cci: layerCci ? layerCci.checked : false,
                keltner: layerKeltner ? layerKeltner.checked : false,
                envelops: layerEnvelops ? layerEnvelops.checked : false,
                parabolicSar: layerParabolicSar ? layerParabolicSar.checked : false,
                supertrend: layerSuperTrend ? layerSuperTrend.checked : false,
                adx: layerAdx ? layerAdx.checked : false,
                williams: layerWilliams ? layerWilliams.checked : false,
                ao: layerAo ? layerAo.checked : false,
                alligator: layerAlligator ? layerAlligator.checked : false,
                ichimoku: layerIchimoku ? layerIchimoku.checked : false,
                price_channel: layerPriceChannel ? layerPriceChannel.checked : false,
                nrtr: layerNrtr ? layerNrtr.checked : false,
                hma: layerHma ? layerHma.checked : false,
                aroon: layerAroon ? layerAroon.checked : false,
                momentum: layerMomentum ? layerMomentum.checked : false,
                roc: layerRoc ? layerRoc.checked : false,
                osma: layerOsma ? layerOsma.checked : false,
                trix: layerTrix ? layerTrix.checked : false,
                cmo: layerCmo ? layerCmo.checked : false,
                demarker: layerDemarker ? layerDemarker.checked : false,
                rvi: layerRvi ? layerRvi.checked : false,
                ultimate: layerUltimate ? layerUltimate.checked : false,
                smi: layerSmi ? layerSmi.checked : false,
                stddev: layerStdDev ? layerStdDev.checked : false,
                smaChannel: layerSmaChannel ? layerSmaChannel.checked : false,
                ivashov: layerIvashov ? layerIvashov.checked : false,
                linreg: layerLinReg ? layerLinReg.checked : false,
                linregChannel: layerLinRegChannel ? layerLinRegChannel.checked : false,
                kalman: layerKalman ? layerKalman.checked : false,
                fractal: layerFractal ? layerFractal.checked : false,
            }));
        } catch (_) { /* ignore */ }
    }

    loadLayerPrefs();

    let chart = null;
    let candlestickSeries = null;
    /**
     * Единый markers-plugin v5 (как на странице бота).
     * Сейчас dashboard не рисует торговые маркеры, но держим ту же точку расширения:
     * при добавлении markers достаточно вызвать PlatformChartMarkers.applyMarkers(...).
     */
    let candlestickMarkers = null;
    let currentInstrument = null;
    let currentDisplayName = '';
    let currentTimeframeMinutes = 1;
    let oldestLoadedTime = null;
    let allChartData = [];
    let loadingMore = false;
    let refreshInterval = null;
    // Отдельный таймер для тяжёлых оверлеев (структуры и Price Action DGT).
    // Они меняются редко, но каждый запрос /structures и /indicators/price-action-dgt
    // заметно нагружает сервер (последний при дефолтных параметрах тянет до 5000 свечей
    // и ~46 KB ответа). Если опрашивать их в общем 500-мс цикле, fetch-pool браузера
    // быстро переполняется (net::ERR_INSUFFICIENT_RESOURCES), запросы /candles стоят
    // в очереди и при переключении инструмента график «висит» на «Загрузка…», даже
    // если бэкенд готов отдать данные мгновенно.
    let overlaysRefreshInterval = null;
    // Базовый шаг обновления оверлеев — 15 сек. Зоны/индикатор за такое время
    // успевают сменить статус, а нагрузка на БД и сеть кратно ниже.
    const OVERLAYS_REFRESH_MS = 15000;
    let instrumentListRefreshInterval = null;
    let sidebarTimerInterval = null;
    let lastZones = [];
    let lastPaPayload = null;
    /** JSON с сервера: boxes / lines / labels для Liquidity Swings. */
    let lastLsPayload = null;
    let lastSmcPayload = null;
    /** Сигналы/сделки для маркеров (формат как на bots/show). */
    let lastChartSignals = [];
    let lastOpenTrades = [];
    let chartTradesFetchSeq = 0;
    /** Payloads классики OsEngine (BB/RSI/SMA/EMA/VWMA/ATR). */
    let lastBollingerPayload = null;
    let lastRsiPayload = null;
    let lastSmaPayload = null;
    let lastEmaPayload = null;
    let lastVwmaPayload = null;
    let lastAtrPayload = null;
    let lastMacdPayload = null;
    let lastStochasticPayload = null;
    let lastCciPayload = null;
    let lastKeltnerPayload = null;
    let lastEnvelopsPayload = null;
    let lastParabolicSarPayload = null;
    let lastSuperTrendPayload = null;
    let lastAdxPayload = null;
    let lastWilliamsPayload = null;
    let lastAoPayload = null;
    let lastAlligatorPayload = null;
    let lastIchimokuPayload = null;
    let lastPriceChannelPayload = null;
    let lastNrtrPayload = null;
    let lastHmaPayload = null;
    let lastAroonPayload = null;
    // 16 новых классических индикаторов OsEngine.
    let lastMomentumPayload = null;
    let lastRocPayload = null;
    let lastOsmaPayload = null;
    let lastTrixPayload = null;
    let lastCmoPayload = null;
    let lastDemarkerPayload = null;
    let lastRviPayload = null;
    let lastUltimatePayload = null;
    let lastSmiPayload = null;
    let lastStdDevPayload = null;
    let lastSmaChannelPayload = null;
    let lastIvashovPayload = null;
    let lastLinRegPayload = null;
    let lastLinRegChannelPayload = null;
    let lastKalmanPayload = null;
    let lastFractalPayload = null;
    let classicLineSeries = {};
    let rsiLevelLines = [];
    let classicOscPaneCount = 0;
    let classicIndicatorsFetchSeq = 0;

    function payoutClassByValue(value) {
        if (value == null || Number.isNaN(value)) return 'text-zinc-500';
        if (value >= 80) return 'text-emerald-400';
        if (value >= 60) return 'text-amber-400';
        return 'text-rose-400';
    }

    function formatMmSs(totalSeconds) {
        const sec = Math.max(0, Math.floor(totalSeconds));
        const mm = Math.floor(sec / 60);
        const ss = sec % 60;
        return String(mm).padStart(2, '0') + ':' + String(ss).padStart(2, '0');
    }

    function tickSidebarTradeTimers() {
        const nowSec = Math.floor(Date.now() / 1000);
        document.querySelectorAll('.instrument-trade-timer[data-expiry-ts]').forEach((el) => {
            const ts = Number(el.dataset.expiryTs);
            if (!Number.isFinite(ts)) return;
            el.textContent = formatMmSs(ts - nowSec);
        });
    }

    function positionCandleTimerOverlay() {
        if (!candleTimerOverlay || !chart || !candlestickSeries || !allChartData.length) {
            if (candleTimerOverlay) {
                candleTimerOverlay.classList.add('hidden');
            }
            return;
        }
        const last = allChartData[allChartData.length - 1];
        const lastT = Number(last.time);
        if (!Number.isFinite(lastT)) {
            candleTimerOverlay.classList.add('hidden');
            return;
        }
        const yPrice = candlestickSeries.priceToCoordinate(last.close);
        if (yPrice == null || Number.isNaN(yPrice)) {
            candleTimerOverlay.classList.add('hidden');
            return;
        }
        const ts = chart.timeScale();
        const lastIdx = allChartData.length - 1;
        const t = Math.max(0, CANDLE_TIMER_GAP_T);
        const bs = typeof ts.barSpacing === 'function' ? ts.barSpacing() : 6;

        let x0 = null;
        let x1 = null;
        if (typeof ts.logicalToCoordinate === 'function') {
            x0 = ts.logicalToCoordinate(lastIdx);
            x1 = ts.logicalToCoordinate(lastIdx + 1);
        }
        if (x0 == null || Number.isNaN(x0)) {
            x0 = ts.timeToCoordinate(lastT);
        }
        if (x0 == null || Number.isNaN(x0)) {
            candleTimerOverlay.classList.add('hidden');
            return;
        }
        // Часто x1 === x0 или |x1-x0|≈0 — тогда (x1-x0)*t всегда 0 и CANDLE_TIMER_GAP_T «не работает».
        if (x1 == null || Number.isNaN(x1) || Math.abs(x1 - x0) < 0.5) {
            x1 = x0 + bs;
        }
        const xCenter = x0 + (x1 - x0) * t;
        candleTimerOverlay.classList.remove('hidden');
        const gapPx = 3;
        candleTimerOverlay.style.left = xCenter + 'px';
        candleTimerOverlay.style.top = Math.max(0, yPrice) + 'px';
        candleTimerOverlay.style.transform = '';
        if (candleTfLabelEl) {
            candleTfLabelEl.style.bottom = gapPx + 'px';
            candleTfLabelEl.style.top = 'auto';
        }
        if (candleCloseTimerEl) {
            candleCloseTimerEl.style.top = gapPx + 'px';
            candleCloseTimerEl.style.bottom = 'auto';
        }
    }

    function tickCandleCloseTimer() {
        if (!candleCloseTimerEl) return;
        if (!currentInstrument || !candlestickSeries || !allChartData.length) {
            if (candleTimerOverlay) candleTimerOverlay.classList.add('hidden');
            candleCloseTimerEl.textContent = '';
            return;
        }
        const lastT = Number(allChartData[allChartData.length - 1].time);
        if (!Number.isFinite(lastT)) {
            if (candleTimerOverlay) candleTimerOverlay.classList.add('hidden');
            candleCloseTimerEl.textContent = '';
            return;
        }
        const left = currentTimeframeMinutes === 1
            ? m1CountdownLeftNow()
            : genericCountdownLeftNow(currentTimeframeMinutes);
        if (left === null) {
            if (candleTimerOverlay) candleTimerOverlay.classList.add('hidden');
            candleCloseTimerEl.textContent = '';
            return;
        }
        if (candleTfLabelEl) {
            candleTfLabelEl.textContent = timeframeLabel(currentTimeframeMinutes);
        }
        candleCloseTimerEl.textContent = formatMmSs(left);
        requestAnimationFrame(positionCandleTimerOverlay);
    }

    function renderInstrumentSidebarRows(instruments) {
        if (!instrumentListEl) return;
        instrumentListEl.replaceChildren();
        if (!instruments.length) {
            const li = document.createElement('li');
            li.className = 'px-4 py-6 text-sm text-zinc-500';
            li.textContent = 'Нет активных инструментов.';
            instrumentListEl.appendChild(li);
            return;
        }
        instruments.forEach((inst) => {
            const li = document.createElement('li');
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'instrument-btn w-full text-left px-4 py-3 hover:bg-zinc-800 focus:bg-zinc-800 focus:outline-none transition-colors' + (inst.open_trade ? ' bg-stone-800/45' : '');
            btn.dataset.instrument = inst.instrument;
            btn.dataset.displayName = inst.display_name;
            btn.dataset.search = normalizeText(`${inst.display_name || ''} ${inst.instrument || ''}`);
            btn.dataset.openTrade = inst.open_trade ? '1' : '0';
            const row = document.createElement('div');
            row.className = 'flex items-center justify-between gap-2';
            const nameWrap = document.createElement('div');
            nameWrap.className = 'flex min-w-0 flex-1 items-center gap-1.5';
            const name = document.createElement('span');
            name.className = 'font-medium text-zinc-100 truncate';
            name.textContent = inst.display_name || inst.instrument;
            nameWrap.appendChild(name);
            if (inst.open_trade && inst.expiry_ts) {
                const timer = document.createElement('span');
                timer.className = 'instrument-trade-timer shrink-0 text-xs font-mono tabular-nums text-stone-200';
                timer.dataset.expiryTs = String(inst.expiry_ts);
                const nowSec = Math.floor(Date.now() / 1000);
                timer.textContent = formatMmSs(Number(inst.expiry_ts) - nowSec);
                nameWrap.appendChild(timer);
            }
            const payout = document.createElement('span');
            const pv = inst.current_payout;
            const n = (pv === null || pv === undefined) ? null : Number(pv);
            payout.className = 'instrument-payout text-xs font-medium ' + payoutClassByValue(n);
            payout.dataset.instrument = inst.instrument;
            payout.dataset.payout = (n == null || Number.isNaN(n)) ? '' : String(n);
            payout.textContent = (n == null || Number.isNaN(n)) ? '—' : `${Math.round(n)}%`;
            row.appendChild(nameWrap);
            row.appendChild(payout);
            btn.appendChild(row);
            li.appendChild(btn);
            instrumentListEl.appendChild(li);
        });
    }

    function refreshInstrumentSidebar() {
        return fetch(INSTRUMENTS_SIDEBAR_URL, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then((r) => r.json())
            .then((d) => {
                renderInstrumentSidebarRows(d.instruments || []);
                if (instrumentSearchInput) {
                    applyInstrumentFilter(instrumentSearchInput.value);
                }
                syncSearchClearButton();
                if (currentInstrument) {
                    document.querySelectorAll('.instrument-btn').forEach((b) => b.classList.toggle('bg-blue-900/40', b.dataset.instrument === currentInstrument));
                }
            })
            .catch(() => {});
    }

    function normalizeText(s) {
        return (s || '').toString().toLowerCase().trim();
    }

    function applyInstrumentFilter(rawQuery) {
        const query = normalizeText(rawQuery);
        let firstVisibleBtn = null;
        document.querySelectorAll('.instrument-btn').forEach((btn) => {
            const haystack = normalizeText(btn.dataset.search || `${btn.dataset.displayName || ''} ${btn.dataset.instrument || ''}`);
            const visible = query === '' || haystack.includes(query);
            const li = btn.closest('li');
            if (li) {
                li.classList.toggle('hidden', !visible);
            }
            if (visible && !firstVisibleBtn) {
                firstVisibleBtn = btn;
            }
        });
        return firstVisibleBtn;
    }

    function findInstrumentBtnByExactName(rawValue) {
        const value = normalizeText(rawValue);
        if (!value) return null;
        return Array.from(document.querySelectorAll('.instrument-btn')).find((btn) => {
            const displayName = normalizeText(btn.dataset.displayName);
            const instrument = normalizeText(btn.dataset.instrument);
            return displayName === value || instrument === value;
        }) || null;
    }

    function syncSearchClearButton() {
        if (!instrumentSearchInput || !instrumentSearchClearBtn) return;
        const hasValue = normalizeText(instrumentSearchInput.value).length > 0;
        instrumentSearchClearBtn.classList.toggle('hidden', !hasValue);
    }

    /**
     * Нижняя граница «потолка» доступной истории графика.
     * Используется в loadMoreCandles как стоп для ленивой подгрузки влево
     * (`oldestLoadedTime <= candlesSince()` => дальше не идём).
     * Это НЕ окно первичной загрузки: первичный показ ограничен INITIAL_WINDOW_SEC.
     */
    function candlesSince() {
        return Math.floor(Date.now() / 1000) - MAX_WINDOW_SEC;
    }

    /** ~кол-во свечей по горизонтали и «воздух» справа (в долях бара), как на референсе */
    const CHART_DEFAULT_VISIBLE_BARS = 110;
    const CHART_RIGHT_GAP_BARS = 22;

    /** Initial-окно для секундных TF: ~viewport + буфер, без 2ч / 1440 S5-баров. */
    function initialWindowSecForTf(tf) {
        if (!isSecondTimeframe(tf)) {
            return INITIAL_WINDOW_SEC;
        }
        return timeframeSeconds(tf) * (CHART_DEFAULT_VISIBLE_BARS + INITIAL_VISIBLE_BUFFER_BARS);
    }

    function initialLimitForTf(tf) {
        if (!isSecondTimeframe(tf)) {
            return INITIAL_LIMIT;
        }
        return Math.ceil(initialWindowSecForTf(tf) / timeframeSeconds(tf)) + 10;
    }

    function applyDefaultChartViewport() {
        if (!chart || allChartData.length === 0) return;
        const n = allChartData.length;
        const lastIdx = n - 1;
        const visible = Math.min(CHART_DEFAULT_VISIBLE_BARS, n);
        const from = Math.max(0, lastIdx - visible + 1);
        const to = lastIdx + CHART_RIGHT_GAP_BARS;
        requestAnimationFrame(() => {
            if (!chart) return;
            chart.timeScale().setVisibleLogicalRange({ from, to });
        });
    }

    /**
     * Пользователь смотрит «живой» хвост: правая граница логического диапазона не левее последнего бара.
     * Раньше было lastIdx−3 — при отступе всего на пару баров от края всё равно вызывали scrollToRealTime(),
     * и график резко перескакивал к правому краю при закрытии новой свечи.
     */
    function isViewNearRightEdge() {
        if (!chart || allChartData.length === 0) return true;
        const range = chart.timeScale().getVisibleLogicalRange();
        if (!range) return true;
        const lastIdx = allChartData.length - 1;
        const edgeEps = 1e-3;
        return range.to >= lastIdx - edgeEps;
    }

    /**
     * DOM-зоны рисуются поверх canvas LW Charts: если time_end = текущий бар,
     * полупрозрачный прямоугольник перекрывает формирующуюся свечу.
     * Обрезаем правый край до левой границы колонки последнего бара.
     */
    function zoneEndXClipped(ts, tEnd, candles) {
        if (!candles?.length) return null;
        const lastBarTime = candles[candles.length - 1].time;
        const endTime = tEnd ?? lastBarTime;
        let x2 = ts.timeToCoordinate(endTime);
        if (x2 == null) return null;
        if (endTime < lastBarTime || candles.length < 2) return x2;
        const xLast = ts.timeToCoordinate(lastBarTime);
        const xPrev = ts.timeToCoordinate(candles[candles.length - 2].time);
        if (xLast == null || xPrev == null) return x2;
        const halfBar = Math.max(2, Math.abs(xLast - xPrev) / 2);
        return Math.min(x2, xLast - halfBar);
    }

    function redrawStructureOverlay() {
        if (!chart || !candlestickSeries || !structureOverlay) return;
        if (!allChartData.length) {
            structureOverlay.innerHTML = '';
            requestAnimationFrame(redrawPriceActionOverlay);
            return;
        }
        if (lastZones.length === 0) {
            structureOverlay.innerHTML = '';
            requestAnimationFrame(redrawPriceActionOverlay);
            return;
        }
        const lastBarTime = allChartData[allChartData.length - 1].time;

        const showOb = layerOb.checked;
        const showFvg = layerFvg.checked;
        structureOverlay.innerHTML = '';

        /* rgba — явные стили: Tailwind CDN не подхватывает классы из строк JS */
        const zonePaint = {
            order_block_bull: { background: 'rgba(33, 87, 243, 0.2)', border: '1px solid rgba(33, 87, 243, 0.85)' },
            order_block_bull_break: { background: 'rgba(255, 17, 0, 0.2)', border: '1px dashed rgba(255, 17, 0, 0.85)' },
            order_block_bear: { background: 'rgba(255, 93, 0, 0.2)', border: '1px solid rgba(255, 93, 0, 0.85)' },
            order_block_bear_break: { background: 'rgba(12, 181, 26, 0.2)', border: '1px dashed rgba(12, 181, 26, 0.85)' },
            imbalance_bull: { background: 'rgba(14, 165, 233, 0.2)', border: '1px solid rgba(2, 132, 199, 0.45)' },
            imbalance_bear: { background: 'rgba(14, 165, 233, 0.2)', border: '1px solid rgba(2, 132, 199, 0.45)' },
            default: { background: 'rgba(156, 163, 175, 0.15)', border: '1px solid rgba(107, 114, 128, 0.35)' }
        };

        /** Подпись типа ордерблока внутри зоны (текст приходит с API на русском). */
        function appendOrderBlockLabel(parent, z) {
            if (!z.label || !String(z.type || '').startsWith('order_block')) return;
            const lbl = document.createElement('span');
            lbl.textContent = z.label;
            lbl.style.position = 'absolute';
            lbl.style.left = '4px';
            lbl.style.top = '3px';
            lbl.style.fontSize = '10px';
            lbl.style.fontWeight = '600';
            lbl.style.lineHeight = '1.2';
            lbl.style.color = z.label_color || '#f4f4f5';
            lbl.style.textShadow = '0 1px 2px rgba(0,0,0,0.9)';
            lbl.style.pointerEvents = 'none';
            lbl.style.whiteSpace = 'nowrap';
            lbl.style.zIndex = '1';
            parent.appendChild(lbl);
        }

        lastZones.forEach(z => {
            const isOb = z.type.startsWith('order_block');
            const isFvg = z.type.startsWith('imbalance');
            if (isOb && !showOb) return;
            if (isFvg && !showFvg) return;

            if (lastBarTime < z.time_start) return;
            const tEnd = z.time_end ?? lastBarTime;

            const x1 = chart.timeScale().timeToCoordinate(z.time_start);
            const x2 = zoneEndXClipped(chart.timeScale(), tEnd, allChartData);
            if (x1 == null || x2 == null) return;
            if (Math.abs(x2 - x1) < 1) return;

            const y1 = candlestickSeries.priceToCoordinate(z.price_high);
            const y2 = candlestickSeries.priceToCoordinate(z.price_low);
            if (y1 == null || y2 == null) return;

            const left = Math.min(x1, x2);
            const width = Math.max(1, Math.abs(x2 - x1));
            const top = Math.min(y1, y2);
            const height = Math.max(1, Math.abs(y2 - y1));

            const paint = zonePaint[z.type] || zonePaint.default;
            const div = document.createElement('div');
            div.style.position = 'absolute';
            div.style.boxSizing = 'border-box';
            div.style.pointerEvents = 'none';
            div.style.left = left + 'px';
            div.style.top = top + 'px';
            div.style.width = width + 'px';
            div.style.height = height + 'px';
            div.style.background = z.fill || paint.background;
            div.style.border = z.border || paint.border;
            appendOrderBlockLabel(div, z);
            structureOverlay.appendChild(div);
        });
        requestAnimationFrame(redrawPriceActionOverlay);
    }

    function paCssColor(c) {
        if (c == null || c === '') return 'rgba(148,163,184,0.5)';
        const s = String(c);
        if (s.startsWith('#') && s.length === 9) return s;
        return s;
    }

    /** Отрезок оверлея между двумя координатами (ломаная UT-трейла SMC Flow). */
    function appendOverlaySegment(parent, x1, y1, x2, y2, color, lineWidth) {
        if (!parent || ![x1, y1, x2, y2].every(Number.isFinite)) return;
        const dx = x2 - x1;
        const dy = y2 - y1;
        const length = Math.hypot(dx, dy);
        if (length < 0.5) return;
        const div = document.createElement('div');
        div.style.position = 'absolute';
        div.style.left = x1 + 'px';
        div.style.top = y1 + 'px';
        div.style.width = length + 'px';
        div.style.height = Math.max(1, lineWidth) + 'px';
        div.style.background = color;
        div.style.pointerEvents = 'none';
        div.style.transformOrigin = '0 50%';
        div.style.transform = 'rotate(' + Math.atan2(dy, dx) + 'rad)';
        parent.appendChild(div);
    }

    function fetchPriceActionIndicator() {
        if (!currentInstrument || !layerPa || !layerPa.checked) {
            lastPaPayload = null;
            redrawPriceActionOverlay();
            applyPaBarColors({ fullResync: true });
            return;
        }
        const params = new URLSearchParams({ instrument: currentInstrument, timeframe: String(currentTimeframeMinutes) });
        fetch(PA_INDICATOR_URL + '?' + params.toString(), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then((r) => r.json())
            .then((d) => {
                try {
                    lastPaPayload = d && d.indicator ? d.indicator : null;
                    redrawPriceActionOverlay();
                    applyPaBarColors({ fullResync: true });
                    // PA отдаёт собственные markers (v_spike/atr) — пересобираем серию маркеров.
                    renderChartMarkers();
                } catch (e) {
                    console.error('[PriceAction DGT] dashboard apply', e);
                    lastPaPayload = null;
                    try {
                        redrawPriceActionOverlay();
                        applyPaBarColors({ fullResync: true });
                        renderChartMarkers();
                    } catch (_) {}
                }
            })
            .catch(() => {});
    }

    /**
     * Тянем сделки пользователя по текущему инструменту и рисуем маркеры
     * тем же helper, что и на странице бота (ВВЕРХ/ВНИЗ/WIN/LOSE/OPEN).
     */
    function fetchChartTrades() {
        if (!currentInstrument) {
            lastChartSignals = [];
            lastOpenTrades = [];
            renderChartMarkers();
            return;
        }
        const seq = ++chartTradesFetchSeq;
        const params = new URLSearchParams({ instrument: currentInstrument });
        fetch(CHART_TRADES_URL + '?' + params.toString(), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then((r) => r.json())
            .then((d) => {
                if (seq !== chartTradesFetchSeq) return;
                lastChartSignals = Array.isArray(d && d.signals) ? d.signals : [];
                lastOpenTrades = Array.isArray(d && d.open_trades) ? d.open_trades : [];
                renderChartMarkers();
            })
            .catch(() => {
                if (seq !== chartTradesFetchSeq) return;
                // При ошибке сети не стираем уже показанные маркеры.
            });
    }

    /**
     * Собирает и применяет маркеры: история сделок + открытые + PA (если слой включён).
     * Через PlatformChartMarkers — тот же код, что на bots/show.
     */
    function renderChartMarkers() {
        if (!candlestickSeries || !currentInstrument) return;
        const PCM = window.PlatformChartMarkers;
        if (!PCM) return;

        const history = typeof PCM.buildHistoryMarkers === 'function'
            ? PCM.buildHistoryMarkers(lastChartSignals, { instrument: currentInstrument })
            : [];
        const open = typeof PCM.buildOpenTradeMarkers === 'function'
            ? PCM.buildOpenTradeMarkers(lastOpenTrades, {
                instrument: currentInstrument,
                nowSec: Math.floor(Date.now() / 1000),
            })
            : [];
        const paMk = (layerPa && layerPa.checked && lastPaPayload && Array.isArray(lastPaPayload.markers))
            ? lastPaPayload.markers
            : [];
        const all = typeof PCM.mergeMarkers === 'function'
            ? PCM.mergeMarkers(history, open, paMk)
            : [...history, ...open, ...paMk].sort((a, b) => a.time - b.time);

        if (typeof PCM.applyMarkers === 'function') {
            PCM.applyMarkers(candlestickMarkers, all);
        }
    }

    /** Включён ли хотя бы один classic-слой OsEngine. */
    function anyClassicLayerOn() {
        return !!(
            (layerBollinger && layerBollinger.checked) ||
            (layerRsi && layerRsi.checked) ||
            (layerSma && layerSma.checked) ||
            (layerEma && layerEma.checked) ||
            (layerVwma && layerVwma.checked) ||
            (layerAtr && layerAtr.checked) ||
            (layerMacd && layerMacd.checked) ||
            (layerStochastic && layerStochastic.checked) ||
            (layerCci && layerCci.checked) ||
            (layerKeltner && layerKeltner.checked) ||
            (layerEnvelops && layerEnvelops.checked) ||
            (layerParabolicSar && layerParabolicSar.checked) ||
            (layerSuperTrend && layerSuperTrend.checked) ||
            (layerAdx && layerAdx.checked) ||
            (layerWilliams && layerWilliams.checked) ||
            (layerAo && layerAo.checked) ||
            (layerAlligator && layerAlligator.checked) ||
            (layerIchimoku && layerIchimoku.checked) ||
            (layerPriceChannel && layerPriceChannel.checked) ||
            (layerNrtr && layerNrtr.checked) ||
            (layerHma && layerHma.checked) ||
            (layerAroon && layerAroon.checked) ||
            (layerMomentum && layerMomentum.checked) ||
            (layerRoc && layerRoc.checked) ||
            (layerOsma && layerOsma.checked) ||
            (layerTrix && layerTrix.checked) ||
            (layerCmo && layerCmo.checked) ||
            (layerDemarker && layerDemarker.checked) ||
            (layerRvi && layerRvi.checked) ||
            (layerUltimate && layerUltimate.checked) ||
            (layerSmi && layerSmi.checked) ||
            (layerStdDev && layerStdDev.checked) ||
            (layerSmaChannel && layerSmaChannel.checked) ||
            (layerIvashov && layerIvashov.checked) ||
            (layerLinReg && layerLinReg.checked) ||
            (layerLinRegChannel && layerLinRegChannel.checked) ||
            (layerKalman && layerKalman.checked) ||
            (layerFractal && layerFractal.checked)
        );
    }

    /**
     * Удаляет line-series классики и лишние pane осцилляторов (LWC v5).
     * Логика 1:1 с bots/show — иначе RSI останется поверх свечей.
     */
    function clearClassicOsEngineSeries() {
        if (!chart) {
            classicLineSeries = {};
            rsiLevelLines = [];
            classicOscPaneCount = 0;
            return;
        }
        Object.keys(classicLineSeries).forEach((k) => {
            try { chart.removeSeries(classicLineSeries[k]); } catch (_) {}
        });
        classicLineSeries = {};
        rsiLevelLines = [];
        try {
            while (typeof chart.panes === 'function' && chart.panes().length > 1) {
                chart.removePane(chart.panes().length - 1);
            }
        } catch (_) {}
        classicOscPaneCount = 0;
        try {
            chart.priceScale('right').applyOptions({
                scaleMargins: { top: 0.08, bottom: 0.05 },
            });
        } catch (_) {}
    }

    /** Рисует BB/SMA/EMA/VWMA на pane 0; RSI и ATR — в отдельных addPane(). */
    function applyClassicOsEngineSeries() {
        if (!chart || !candlestickSeries) return;
        clearClassicOsEngineSeries();

        const LineSeries = LightweightCharts.LineSeries;
        const HistogramSeries = LightweightCharts.HistogramSeries;
        if (!LineSeries || typeof chart.addSeries !== 'function') {
            console.error('[dashboard indicators] LWC v5 addSeries/LineSeries недоступны');
            return;
        }

        const wantBb = layerBollinger && layerBollinger.checked && lastBollingerPayload;
        const wantRsi = layerRsi && layerRsi.checked && lastRsiPayload;
        const wantSma = layerSma && layerSma.checked && lastSmaPayload;
        const wantEma = layerEma && layerEma.checked && lastEmaPayload;
        const wantVwma = layerVwma && layerVwma.checked && lastVwmaPayload;
        const wantAtr = layerAtr && layerAtr.checked && lastAtrPayload;
        const wantMacd = layerMacd && layerMacd.checked && lastMacdPayload;
        const wantStochastic = layerStochastic && layerStochastic.checked && lastStochasticPayload;
        const wantCci = layerCci && layerCci.checked && lastCciPayload;
        const wantKeltner = layerKeltner && layerKeltner.checked && lastKeltnerPayload;
        const wantEnvelops = layerEnvelops && layerEnvelops.checked && lastEnvelopsPayload;
        const wantParabolicSar = layerParabolicSar && layerParabolicSar.checked && lastParabolicSarPayload;
        const wantSuperTrend = layerSuperTrend && layerSuperTrend.checked && lastSuperTrendPayload;
        const wantAdx = layerAdx && layerAdx.checked && lastAdxPayload;
        const wantWilliams = layerWilliams && layerWilliams.checked && lastWilliamsPayload;
        const wantAo = layerAo && layerAo.checked && lastAoPayload;
        const wantAlligator = layerAlligator && layerAlligator.checked && lastAlligatorPayload;
        const wantIchimoku = layerIchimoku && layerIchimoku.checked && lastIchimokuPayload;
        const wantPriceChannel = layerPriceChannel && layerPriceChannel.checked && lastPriceChannelPayload;
        const wantNrtr = layerNrtr && layerNrtr.checked && lastNrtrPayload;
        const wantHma = layerHma && layerHma.checked && lastHmaPayload;
        const wantAroon = layerAroon && layerAroon.checked && lastAroonPayload;
        const wantMomentum = layerMomentum && layerMomentum.checked && lastMomentumPayload;
        const wantRoc = layerRoc && layerRoc.checked && lastRocPayload;
        const wantOsma = layerOsma && layerOsma.checked && lastOsmaPayload;
        const wantTrix = layerTrix && layerTrix.checked && lastTrixPayload;
        const wantCmo = layerCmo && layerCmo.checked && lastCmoPayload;
        const wantDemarker = layerDemarker && layerDemarker.checked && lastDemarkerPayload;
        const wantRvi = layerRvi && layerRvi.checked && lastRviPayload;
        const wantUltimate = layerUltimate && layerUltimate.checked && lastUltimatePayload;
        const wantSmi = layerSmi && layerSmi.checked && lastSmiPayload;
        const wantStdDev = layerStdDev && layerStdDev.checked && lastStdDevPayload;
        const wantSmaChannel = layerSmaChannel && layerSmaChannel.checked && lastSmaChannelPayload;
        const wantIvashov = layerIvashov && layerIvashov.checked && lastIvashovPayload;
        const wantLinReg = layerLinReg && layerLinReg.checked && lastLinRegPayload;
        const wantLinRegChannel = layerLinRegChannel && layerLinRegChannel.checked && lastLinRegChannelPayload;
        const wantKalman = layerKalman && layerKalman.checked && lastKalmanPayload;
        const wantFractal = layerFractal && layerFractal.checked && lastFractalPayload;

        const addMainLine = (key, data, color, width) => {
            if (!Array.isArray(data) || !data.length) return;
            const s = chart.addSeries(LineSeries, {
                color: color || '#22c55e',
                lineWidth: width || 1,
                priceLineVisible: false,
                lastValueVisible: false,
                crosshairMarkerVisible: false,
            }, 0);
            s.setData(data.map((p) => ({ time: p.time, value: p.value })));
            classicLineSeries[key] = s;
        };

        if (wantBb) {
            const st = lastBollingerPayload.style || {};
            const ser = lastBollingerPayload.series || {};
            const w = st.line_width || 1;
            addMainLine('bb_up', ser.up, st.color_up, w);
            addMainLine('bb_mid', ser.mid, st.color_mid, w);
            addMainLine('bb_down', ser.down, st.color_down, w);
        }
        if (wantSma) {
            const st = lastSmaPayload.style || {};
            addMainLine('sma', (lastSmaPayload.series || {}).ma, st.color, st.line_width);
        }
        if (wantEma) {
            const st = lastEmaPayload.style || {};
            addMainLine('ema', (lastEmaPayload.series || {}).ema, st.color, st.line_width);
        }
        if (wantVwma) {
            const st = lastVwmaPayload.style || {};
            addMainLine('vwma', (lastVwmaPayload.series || {}).vwma, st.color, st.line_width);
        }

        if (wantKeltner) {
            const st = lastKeltnerPayload.style || {};
            const ser = lastKeltnerPayload.series || {};
            const w = st.line_width || 1;
            addMainLine('keltner_up', ser.up, st.color_up, w);
            addMainLine('keltner_mid', ser.mid, st.color_mid, w);
            addMainLine('keltner_down', ser.down, st.color_down, w);
        }
        if (wantEnvelops) {
            const st = lastEnvelopsPayload.style || {};
            const ser = lastEnvelopsPayload.series || {};
            const w = st.line_width || 1;
            addMainLine('envelops_up', ser.up, st.color_up, w);
            addMainLine('envelops_mid', ser.mid, st.color_mid, w);
            addMainLine('envelops_down', ser.down, st.color_down, w);
        }
        if (wantParabolicSar) {
            const st = lastParabolicSarPayload.style || {};
            const data = (lastParabolicSarPayload.series || {}).sar;
            if (Array.isArray(data) && data.length) {
                const s = chart.addSeries(LineSeries, {
                    color: st.color || '#1e90ff',
                    lineWidth: 1,
                    lineVisible: false,
                    pointMarkersVisible: true,
                    pointMarkersRadius: st.point_radius || 2,
                    priceLineVisible: false,
                    lastValueVisible: false,
                    crosshairMarkerVisible: false,
                }, 0);
                s.setData(data.map((p) => ({ time: p.time, value: p.value })));
                classicLineSeries['parabolic_sar'] = s;
            }
        }
        if (wantSuperTrend) {
            const st = lastSuperTrendPayload.style || {};
            const ser = lastSuperTrendPayload.series || {};
            const w = st.line_width || 2;
            addMainLine('supertrend_center', ser.center, st.color_center, w);
            addMainLine('supertrend_upper', ser.upper, st.color_upper, w);
            addMainLine('supertrend_lower', ser.lower, st.color_lower, w);
        }
        if (wantAlligator) {
            const st = lastAlligatorPayload.style || {};
            const ser = lastAlligatorPayload.series || {};
            const w = st.line_width || 1;
            addMainLine('alligator_jaw', ser.jaw, st.color_jaw, w);
            addMainLine('alligator_teeth', ser.teeth, st.color_teeth, w);
            addMainLine('alligator_lips', ser.lips, st.color_lips, w);
        }
        if (wantIchimoku) {
            const st = lastIchimokuPayload.style || {};
            const ser = lastIchimokuPayload.series || {};
            const w = st.line_width || 1;
            addMainLine('ichimoku_tenkan', ser.tenkan, st.color_tenkan, w);
            addMainLine('ichimoku_kijun', ser.kijun, st.color_kijun, w);
            addMainLine('ichimoku_senkou_a', ser.senkou_a, st.color_senkou_a, w);
            addMainLine('ichimoku_senkou_b', ser.senkou_b, st.color_senkou_b, w);
            const chinkou = ser.chinkou;
            if (Array.isArray(chinkou) && chinkou.length) {
                const s = chart.addSeries(LineSeries, {
                    color: st.color_chinkou || '#8b0000',
                    lineWidth: 1,
                    lineVisible: false,
                    pointMarkersVisible: true,
                    pointMarkersRadius: st.chinkou_point_radius || 2,
                    priceLineVisible: false,
                    lastValueVisible: false,
                    crosshairMarkerVisible: false,
                }, 0);
                s.setData(chinkou.map((p) => ({ time: p.time, value: p.value })));
                classicLineSeries['ichimoku_chinkou'] = s;
            }
        }
        if (wantPriceChannel) {
            const st = lastPriceChannelPayload.style || {};
            const ser = lastPriceChannelPayload.series || {};
            const w = st.line_width || 1;
            addMainLine('price_channel_up', ser.up, st.color_up, w);
            addMainLine('price_channel_down', ser.down, st.color_down, w);
        }
        if (wantNrtr) {
            const st = lastNrtrPayload.style || {};
            const ser = lastNrtrPayload.series || {};
            const w = st.line_width || 1;
            addMainLine('nrtr_center', ser.center, st.color_center, w);
            addMainLine('nrtr_up', ser.up, st.color_up, w);
            addMainLine('nrtr_down', ser.down, st.color_down, w);
        }
        if (wantHma) {
            const st = lastHmaPayload.style || {};
            addMainLine('hma', (lastHmaPayload.series || {}).hma, st.color, st.line_width);
        }
        if (wantSmaChannel) {
            const st = lastSmaChannelPayload.style || {};
            const ser = lastSmaChannelPayload.series || {};
            const w = st.line_width || 1;
            addMainLine('sma_channel_up', ser.up, st.color_up, w);
            addMainLine('sma_channel_mid', ser.mid, st.color_mid, w);
            addMainLine('sma_channel_down', ser.down, st.color_down, w);
        }
        if (wantLinReg) {
            const st = lastLinRegPayload.style || {};
            addMainLine('linreg', (lastLinRegPayload.series || {}).linreg, st.color, st.line_width);
        }
        if (wantLinRegChannel) {
            const st = lastLinRegChannelPayload.style || {};
            const ser = lastLinRegChannelPayload.series || {};
            const w = st.line_width || 1;
            addMainLine('linreg_channel_up', ser.up, st.color_up, w);
            addMainLine('linreg_channel_mid', ser.mid, st.color_mid, w);
            addMainLine('linreg_channel_down', ser.down, st.color_down, w);
        }
        if (wantKalman) {
            const st = lastKalmanPayload.style || {};
            addMainLine('kalman', (lastKalmanPayload.series || {}).kalman, st.color, st.line_width);
        }
        if (wantFractal) {
            const st = lastFractalPayload.style || {};
            const ser = lastFractalPayload.series || {};
            // OsEngine Point: фрактал — отдельные точки без соединяющей линии (как Parabolic SAR).
            const addFractalPoints = (key, data, color) => {
                if (!Array.isArray(data) || !data.length) return;
                const s = chart.addSeries(LineSeries, {
                    color: color || '#00bfff',
                    lineWidth: 1,
                    lineVisible: false,
                    pointMarkersVisible: true,
                    pointMarkersRadius: st.point_radius || 3,
                    priceLineVisible: false,
                    lastValueVisible: false,
                    crosshairMarkerVisible: false,
                }, 0);
                s.setData(data.map((p) => ({ time: p.time, value: p.value })));
                classicLineSeries[key] = s;
            };
            addFractalPoints('fractal_up', ser.up, st.color_up || '#00bfff');
            addFractalPoints('fractal_down', ser.down, st.color_down || '#ff4500');
        }

        const oscCount = (wantRsi ? 1 : 0) + (wantMacd ? 1 : 0) + (wantStochastic ? 1 : 0) + (wantCci ? 1 : 0) + (wantAtr ? 1 : 0) + (wantAdx ? 1 : 0) + (wantWilliams ? 1 : 0) + (wantAo ? 1 : 0) + (wantAroon ? 1 : 0)
            + (wantMomentum ? 1 : 0) + (wantRoc ? 1 : 0) + (wantOsma ? 1 : 0) + (wantTrix ? 1 : 0) + (wantCmo ? 1 : 0) + (wantDemarker ? 1 : 0) + (wantRvi ? 1 : 0) + (wantUltimate ? 1 : 0) + (wantSmi ? 1 : 0) + (wantStdDev ? 1 : 0) + (wantIvashov ? 1 : 0);
        if (oscCount > 0) {
            try {
                const mainPane = chart.panes()[0];
                if (mainPane && typeof mainPane.setStretchFactor === 'function') {
                    mainPane.setStretchFactor(1);
                }
            } catch (_) {}
        }

        if (wantRsi) {
            const st = lastRsiPayload.style || {};
            const levels = lastRsiPayload.levels || {};
            let paneIndex = 1;
            let rsiPane = null;
            try {
                rsiPane = chart.addPane(false);
                if (rsiPane && typeof rsiPane.setStretchFactor === 'function') {
                    rsiPane.setStretchFactor(0.28);
                }
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[dashboard indicators] addPane RSI', e);
            }
            const s = chart.addSeries(LineSeries, {
                color: st.color || '#1e90ff',
                lineWidth: st.line_width || 1,
                priceLineVisible: false,
                baseLineVisible: false,
                // Показываем текущее значение RSI справа, чтобы видеть актуальный уровень.
                lastValueVisible: true,
                crosshairMarkerVisible: false,
                // RSI — осциллятор в шкале 0..100, отображаем целые значения.
                priceFormat: { type: 'price', precision: 0, minMove: 1 },
                // Жёсткий диапазон нужен, чтобы уровни 70/30 всегда были видимыми.
                autoscaleInfoProvider: () => ({ priceRange: { minValue: 0, maxValue: 100 } }),
            }, paneIndex);
            const data = ((lastRsiPayload.series || {}).rsi) || [];
            s.setData(data.map((p) => ({ time: p.time, value: p.value })));
            classicLineSeries['rsi'] = s;
            // Небольшие внешние отступы для более аккуратного расположения линии RSI.
            try {
                s.priceScale().applyOptions({
                    scaleMargins: { top: 0.08, bottom: 0.08 },
                    borderVisible: false,
                    entireTextOnly: true,
                });
            } catch (_) {}

            // Накладываем однотонный фон именно на RSI-pane, чтобы визуально погасить сетку.
            try {
                const paneApi = rsiPane || (typeof chart.panes === 'function' ? chart.panes()[paneIndex] : null);
                if (paneApi && typeof paneApi.attachPrimitive === 'function'
                    && window.PlatformChartMarkers
                    && typeof window.PlatformChartMarkers.createPaneSolidBackdrop === 'function') {
                    paneApi.attachPrimitive(window.PlatformChartMarkers.createPaneSolidBackdrop('#0e1018'));
                }
            } catch (e) {
                console.error('[dashboard indicators] RSI pane backdrop', e);
            }

            // Подписи 70/30 на правой шкале для быстрого чтения перекупленности/перепроданности.
            try {
                const lines = (window.PlatformChartMarkers
                    && typeof window.PlatformChartMarkers.attachRsiLevelLines === 'function')
                    ? window.PlatformChartMarkers.attachRsiLevelLines(s, levels)
                    : [];
                rsiLevelLines.push(...lines);
            } catch (_) {}
        }

        // MACD pane: histogram + lines + нулевая линия (как на bots/show).
        if (wantMacd) {
            const st = lastMacdPayload.style || {};
            const ser = lastMacdPayload.series || {};
            const histColor = st.color_histogram || '#1e90ff';
            const histNeg = st.color_histogram_neg || '#f43f5e';
            const lineW = st.line_width || 1;
            let paneIndex = 1;
            try {
                const pane = chart.addPane(false);
                if (pane && typeof pane.setStretchFactor === 'function') {
                    pane.setStretchFactor(0.32);
                }
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[dashboard indicators] addPane MACD', e);
            }

            if (HistogramSeries && Array.isArray(ser.histogram) && ser.histogram.length) {
                try {
                    const hs = chart.addSeries(HistogramSeries, {
                        priceLineVisible: false,
                        lastValueVisible: false,
                        baseLineVisible: true,
                        baseLineColor: 'rgba(161,161,170,0.45)',
                        baseLineWidth: 1,
                        baseLineStyle: 2,
                    }, paneIndex);
                    hs.setData(ser.histogram.map((p) => ({
                        time: p.time,
                        value: p.value,
                        color: Number(p.value) >= 0 ? histColor : histNeg,
                    })));
                    classicLineSeries['macd_hist'] = hs;
                } catch (e) {
                    console.error('[dashboard indicators] MACD histogram', e);
                }
            }

            const addMacdLine = (key, data, color) => {
                if (!Array.isArray(data) || !data.length) return;
                const s = chart.addSeries(LineSeries, {
                    color: color || '#006400',
                    lineWidth: lineW,
                    priceLineVisible: false,
                    lastValueVisible: key === 'macd_line',
                    crosshairMarkerVisible: false,
                }, paneIndex);
                s.setData(data.map((p) => ({ time: p.time, value: p.value })));
                classicLineSeries[key] = s;
                return s;
            };
            const macdLine = addMacdLine('macd_line', ser.macd, st.color_macd || '#006400');
            addMacdLine('macd_signal', ser.signal, st.color_signal || '#8b0000');

            try {
                const anchor = macdLine || classicLineSeries['macd_signal'] || classicLineSeries['macd_hist'];
                if (anchor && typeof anchor.createPriceLine === 'function') {
                    rsiLevelLines.push(anchor.createPriceLine({
                        price: 0,
                        color: 'rgba(161,161,170,0.55)',
                        lineWidth: 1,
                        lineStyle: 2,
                        axisLabelVisible: true,
                        title: '0',
                    }));
                }
            } catch (_) {}
        }

        // Stochastic pane 0..100 (%K/%D + уровни 80/20).
        if (wantStochastic) {
            const st = lastStochasticPayload.style || {};
            const levels = lastStochasticPayload.levels || {};
            const ser = lastStochasticPayload.series || {};
            const lineW = st.line_width || 1;
            let paneIndex = 1;
            let stochPane = null;
            try {
                stochPane = chart.addPane(false);
                if (stochPane && typeof stochPane.setStretchFactor === 'function') {
                    stochPane.setStretchFactor(0.28);
                }
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[dashboard indicators] addPane Stochastic', e);
            }

            const addStochLine = (key, data, color, showLast) => {
                if (!Array.isArray(data) || !data.length) return null;
                const s = chart.addSeries(LineSeries, {
                    color: color || '#1e90ff',
                    lineWidth: lineW,
                    priceLineVisible: false,
                    baseLineVisible: false,
                    lastValueVisible: !!showLast,
                    crosshairMarkerVisible: false,
                    priceFormat: { type: 'price', precision: 0, minMove: 1 },
                    autoscaleInfoProvider: () => ({ priceRange: { minValue: 0, maxValue: 100 } }),
                }, paneIndex);
                s.setData(data.map((p) => ({ time: p.time, value: p.value })));
                classicLineSeries[key] = s;
                return s;
            };
            const kLine = addStochLine('stoch_k', ser.k, st.color_k || '#1e90ff', true);
            addStochLine('stoch_d', ser.d, st.color_d || '#8b0000', false);

            try {
                const anchor = kLine || classicLineSeries['stoch_d'];
                if (anchor && typeof anchor.priceScale === 'function') {
                    anchor.priceScale().applyOptions({
                        scaleMargins: { top: 0.08, bottom: 0.08 },
                        borderVisible: false,
                        entireTextOnly: true,
                    });
                }
            } catch (_) {}

            try {
                const paneApi = stochPane || (typeof chart.panes === 'function' ? chart.panes()[paneIndex] : null);
                if (paneApi && typeof paneApi.attachPrimitive === 'function'
                    && window.PlatformChartMarkers
                    && typeof window.PlatformChartMarkers.createPaneSolidBackdrop === 'function') {
                    paneApi.attachPrimitive(window.PlatformChartMarkers.createPaneSolidBackdrop('#0e1018'));
                }
            } catch (e) {
                console.error('[dashboard indicators] Stochastic pane backdrop', e);
            }

            try {
                const anchor = kLine || classicLineSeries['stoch_d'];
                if (anchor && window.PlatformChartMarkers
                    && typeof window.PlatformChartMarkers.attachRsiLevelLines === 'function') {
                    const lines = window.PlatformChartMarkers.attachRsiLevelLines(anchor, levels);
                    rsiLevelLines.push(...lines);
                }
            } catch (_) {}
        }

        // CCI — отдельный pane без фиксированной шкалы 0..100 (значения не ограничены).
        if (wantCci) {
            const st = lastCciPayload.style || {};
            const levels = lastCciPayload.levels || {};
            const ser = lastCciPayload.series || {};
            const lineW = st.line_width || 1;
            let paneIndex = 1;
            let cciPane = null;
            try {
                cciPane = chart.addPane(false);
                if (cciPane && typeof cciPane.setStretchFactor === 'function') {
                    cciPane.setStretchFactor(0.28);
                }
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[dashboard indicators] addPane CCI', e);
            }

            const cciData = ser.cci;
            let cciLine = null;
            if (Array.isArray(cciData) && cciData.length) {
                // Обычный autoscale — CCI может уходить далеко за ±100.
                cciLine = chart.addSeries(LineSeries, {
                    color: st.color || '#5f9ea0',
                    lineWidth: lineW,
                    priceLineVisible: false,
                    baseLineVisible: false,
                    lastValueVisible: true,
                    crosshairMarkerVisible: false,
                    priceFormat: { type: 'price', precision: 2, minMove: 0.01 },
                }, paneIndex);
                cciLine.setData(cciData.map((p) => ({ time: p.time, value: p.value })));
                classicLineSeries['cci'] = cciLine;
            }

            try {
                const paneApi = cciPane || (typeof chart.panes === 'function' ? chart.panes()[paneIndex] : null);
                if (paneApi && typeof paneApi.attachPrimitive === 'function'
                    && window.PlatformChartMarkers
                    && typeof window.PlatformChartMarkers.createPaneSolidBackdrop === 'function') {
                    paneApi.attachPrimitive(window.PlatformChartMarkers.createPaneSolidBackdrop('#0e1018'));
                }
            } catch (e) {
                console.error('[dashboard indicators] CCI pane backdrop', e);
            }

            try {
                if (cciLine && window.PlatformChartMarkers
                    && typeof window.PlatformChartMarkers.attachRsiLevelLines === 'function') {
                    const lines = window.PlatformChartMarkers.attachRsiLevelLines(cciLine, levels);
                    rsiLevelLines.push(...lines);
                }
            } catch (_) {}

            // Нулевая линия CCI (как у MACD — priceLine на основной серии).
            try {
                if (cciLine && typeof cciLine.createPriceLine === 'function') {
                    rsiLevelLines.push(cciLine.createPriceLine({
                        price: 0,
                        color: 'rgba(161,161,170,0.55)',
                        lineWidth: 1,
                        lineStyle: 2,
                        axisLabelVisible: true,
                        title: '0',
                    }));
                }
            } catch (_) {}
        }


        // ADX — отдельный pane (ADX / +DI / −DI), уровни 20/25.
        if (wantAdx) {
            const st = lastAdxPayload.style || {};
            const levels = lastAdxPayload.levels || {};
            const ser = lastAdxPayload.series || {};
            const lineW = st.line_width || 1;
            let paneIndex = 1;
            let adxPane = null;
            try {
                adxPane = chart.addPane(false);
                if (adxPane && typeof adxPane.setStretchFactor === 'function') {
                    adxPane.setStretchFactor(0.28);
                }
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[indicators] addPane ADX', e);
            }

            const mk = (key, data, color) => {
                if (!Array.isArray(data) || !data.length) return null;
                const s = chart.addSeries(LineSeries, {
                    color: color || '#228b22',
                    lineWidth: lineW,
                    priceLineVisible: false,
                    lastValueVisible: true,
                    crosshairMarkerVisible: false,
                    priceFormat: { type: 'price', precision: 2, minMove: 0.01 },
                }, paneIndex);
                s.setData(data.map((p) => ({ time: p.time, value: p.value })));
                classicLineSeries[key] = s;
                return s;
            };
            const adxLine = mk('adx', ser.adx, st.color_adx);
            mk('adx_plus', ser.plus, st.color_plus);
            mk('adx_minus', ser.minus, st.color_minus);

            try {
                const paneApi = adxPane || (typeof chart.panes === 'function' ? chart.panes()[paneIndex] : null);
                if (paneApi && typeof paneApi.attachPrimitive === 'function'
                    && window.PlatformChartMarkers
                    && typeof window.PlatformChartMarkers.createPaneSolidBackdrop === 'function') {
                    paneApi.attachPrimitive(window.PlatformChartMarkers.createPaneSolidBackdrop('#0e1018'));
                }
            } catch (e) {
                console.error('[indicators] ADX pane backdrop', e);
            }

            try {
                if (adxLine && levels.show && window.PlatformChartMarkers
                    && typeof window.PlatformChartMarkers.attachRsiLevelLines === 'function') {
                    const lines = window.PlatformChartMarkers.attachRsiLevelLines(adxLine, levels);
                    rsiLevelLines.push(...lines);
                }
            } catch (_) {}
        }

        if (wantWilliams) {
            const st = lastWilliamsPayload.style || {};
            const levels = lastWilliamsPayload.levels || {};
            const ser = lastWilliamsPayload.series || {};
            let paneIndex = 1;
            let wrPane = null;
            try {
                wrPane = chart.addPane(false);
                if (wrPane && typeof wrPane.setStretchFactor === 'function') wrPane.setStretchFactor(0.28);
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) { console.error('[dashboard] addPane Williams', e); }
            const data = ser.wr || [];
            let wrLine = null;
            if (Array.isArray(data) && data.length) {
                wrLine = chart.addSeries(LineSeries, {
                    color: st.color || '#1e90ff',
                    lineWidth: st.line_width || 1,
                    priceLineVisible: false,
                    baseLineVisible: false,
                    lastValueVisible: true,
                    crosshairMarkerVisible: false,
                    priceFormat: { type: 'price', precision: 2, minMove: 0.01 },
                    autoscaleInfoProvider: () => ({ priceRange: { minValue: -100, maxValue: 0 } }),
                }, paneIndex);
                wrLine.setData(data.map((p) => ({ time: p.time, value: p.value })));
                classicLineSeries['williams'] = wrLine;
            }
            try {
                if (wrLine && levels.show && window.PlatformChartMarkers?.attachRsiLevelLines) {
                    rsiLevelLines.push(...window.PlatformChartMarkers.attachRsiLevelLines(wrLine, levels));
                }
            } catch (_) {}
        }

        if (wantAo) {
            const st = lastAoPayload.style || {};
            const ser = lastAoPayload.series || {};
            let paneIndex = 1;
            try {
                const pane = chart.addPane(false);
                if (pane?.setStretchFactor) pane.setStretchFactor(0.32);
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) { console.error('[dashboard] addPane AO', e); }
            if (HistogramSeries && Array.isArray(ser.ao) && ser.ao.length) {
                const hs = chart.addSeries(HistogramSeries, {
                    priceLineVisible: false,
                    lastValueVisible: false,
                    baseLineVisible: true,
                    baseLineColor: 'rgba(161,161,170,0.45)',
                    baseLineWidth: 1,
                    baseLineStyle: 2,
                }, paneIndex);
                hs.setData(ser.ao.map((p) => ({
                    time: p.time,
                    value: p.value,
                    color: Number(p.value) >= 0 ? (st.color_histogram || '#006400') : (st.color_histogram_neg || '#f43f5e'),
                })));
                classicLineSeries['ao_hist'] = hs;
            }
        }

        if (wantAroon) {
            const st = lastAroonPayload.style || {};
            const levels = lastAroonPayload.levels || {};
            const ser = lastAroonPayload.series || {};
            const lineW = st.line_width || 1;
            let paneIndex = 1;
            try {
                const pane = chart.addPane(false);
                if (pane?.setStretchFactor) pane.setStretchFactor(0.28);
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) { console.error('[dashboard] addPane Aroon', e); }
            const mkAroon = (key, data, color) => {
                if (!Array.isArray(data) || !data.length) return null;
                const s = chart.addSeries(LineSeries, {
                    color: color || '#008000',
                    lineWidth: lineW,
                    priceLineVisible: false,
                    lastValueVisible: true,
                    crosshairMarkerVisible: false,
                    priceFormat: { type: 'price', precision: 0, minMove: 1 },
                    autoscaleInfoProvider: () => ({ priceRange: { minValue: 0, maxValue: 100 } }),
                }, paneIndex);
                s.setData(data.map((p) => ({ time: p.time, value: p.value })));
                classicLineSeries[key] = s;
                return s;
            };
            const upLine = mkAroon('aroon_up', ser.up, st.color_up);
            mkAroon('aroon_down', ser.down, st.color_down);
            try {
                const anchor = upLine || classicLineSeries['aroon_down'];
                if (anchor && levels.show && window.PlatformChartMarkers?.attachRsiLevelLines) {
                    rsiLevelLines.push(...window.PlatformChartMarkers.attachRsiLevelLines(anchor, levels));
                }
            } catch (_) {}
        }

        // Momentum — pane вокруг «нейтрали» 100.
        if (wantMomentum) {
            const st = lastMomentumPayload.style || {};
            const levels = lastMomentumPayload.levels || {};
            let paneIndex = 1;
            let momentumPane = null;
            try {
                momentumPane = chart.addPane(false);
                if (momentumPane && typeof momentumPane.setStretchFactor === 'function') {
                    momentumPane.setStretchFactor(0.28);
                }
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[dashboard indicators] addPane Momentum', e);
            }
            const data = (lastMomentumPayload.series || {}).momentum || [];
            let momentumLine = null;
            if (Array.isArray(data) && data.length) {
                momentumLine = chart.addSeries(LineSeries, {
                    color: st.color || '#00ffff',
                    lineWidth: st.line_width || 1,
                    priceLineVisible: false,
                    baseLineVisible: false,
                    lastValueVisible: true,
                    crosshairMarkerVisible: false,
                    priceFormat: { type: 'price', precision: 2, minMove: 0.01 },
                }, paneIndex);
                momentumLine.setData(data.map((p) => ({ time: p.time, value: p.value })));
                classicLineSeries['momentum'] = momentumLine;
            }
            try {
                const paneApi = momentumPane || (typeof chart.panes === 'function' ? chart.panes()[paneIndex] : null);
                if (paneApi?.attachPrimitive && window.PlatformChartMarkers?.createPaneSolidBackdrop) {
                    paneApi.attachPrimitive(window.PlatformChartMarkers.createPaneSolidBackdrop('#0e1018'));
                }
            } catch (e) {
                console.error('[dashboard indicators] Momentum pane backdrop', e);
            }
            try {
                if (momentumLine && levels.show && window.PlatformChartMarkers?.attachRsiLevelLines) {
                    rsiLevelLines.push(...window.PlatformChartMarkers.attachRsiLevelLines(momentumLine, levels));
                }
            } catch (_) {}
        }

        // ROC — pane вокруг нулевой линии.
        if (wantRoc) {
            const st = lastRocPayload.style || {};
            const levels = lastRocPayload.levels || {};
            let paneIndex = 1;
            let rocPane = null;
            try {
                rocPane = chart.addPane(false);
                if (rocPane && typeof rocPane.setStretchFactor === 'function') {
                    rocPane.setStretchFactor(0.28);
                }
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[dashboard indicators] addPane ROC', e);
            }
            const data = (lastRocPayload.series || {}).roc || [];
            let rocLine = null;
            if (Array.isArray(data) && data.length) {
                rocLine = chart.addSeries(LineSeries, {
                    color: st.color || '#00ffff',
                    lineWidth: st.line_width || 1,
                    priceLineVisible: false,
                    baseLineVisible: false,
                    lastValueVisible: true,
                    crosshairMarkerVisible: false,
                    priceFormat: { type: 'price', precision: 2, minMove: 0.01 },
                }, paneIndex);
                rocLine.setData(data.map((p) => ({ time: p.time, value: p.value })));
                classicLineSeries['roc'] = rocLine;
            }
            try {
                const paneApi = rocPane || (typeof chart.panes === 'function' ? chart.panes()[paneIndex] : null);
                if (paneApi?.attachPrimitive && window.PlatformChartMarkers?.createPaneSolidBackdrop) {
                    paneApi.attachPrimitive(window.PlatformChartMarkers.createPaneSolidBackdrop('#0e1018'));
                }
            } catch (e) {
                console.error('[dashboard indicators] ROC pane backdrop', e);
            }
            try {
                if (rocLine && levels.show && window.PlatformChartMarkers?.attachRsiLevelLines) {
                    rsiLevelLines.push(...window.PlatformChartMarkers.attachRsiLevelLines(rocLine, levels));
                }
            } catch (_) {}
        }

        // OsMA — как MACD: histogram + osma line + signal (+ нулевая линия).
        if (wantOsma) {
            const st = lastOsmaPayload.style || {};
            const ser = lastOsmaPayload.series || {};
            const histColor = st.color_histogram || '#1e90ff';
            const histNeg = st.color_histogram_neg || '#f43f5e';
            const lineW = st.line_width || 1;
            let paneIndex = 1;
            try {
                const pane = chart.addPane(false);
                if (pane && typeof pane.setStretchFactor === 'function') {
                    pane.setStretchFactor(0.32);
                }
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[dashboard indicators] addPane OsMA', e);
            }
            if (HistogramSeries && Array.isArray(ser.histogram) && ser.histogram.length) {
                try {
                    const hs = chart.addSeries(HistogramSeries, {
                        priceLineVisible: false,
                        lastValueVisible: false,
                        baseLineVisible: true,
                        baseLineColor: 'rgba(161,161,170,0.45)',
                        baseLineWidth: 1,
                        baseLineStyle: 2,
                    }, paneIndex);
                    hs.setData(ser.histogram.map((p) => ({
                        time: p.time,
                        value: p.value,
                        color: Number(p.value) >= 0 ? histColor : histNeg,
                    })));
                    classicLineSeries['osma_hist'] = hs;
                } catch (e) {
                    console.error('[dashboard indicators] OsMA histogram', e);
                }
            }
            const addOsmaLine = (key, data, color, showLast) => {
                if (!Array.isArray(data) || !data.length) return null;
                const s = chart.addSeries(LineSeries, {
                    color: color || '#ffff00',
                    lineWidth: lineW,
                    priceLineVisible: false,
                    lastValueVisible: !!showLast,
                    crosshairMarkerVisible: false,
                }, paneIndex);
                s.setData(data.map((p) => ({ time: p.time, value: p.value })));
                classicLineSeries[key] = s;
                return s;
            };
            const osmaLine = addOsmaLine('osma_line', ser.osma, st.color_osma || '#ffff00', true);
            addOsmaLine('osma_signal', ser.signal, st.color_signal || '#ff0000', false);
            try {
                const anchor = osmaLine || classicLineSeries['osma_signal'] || classicLineSeries['osma_hist'];
                if (anchor && typeof anchor.createPriceLine === 'function') {
                    rsiLevelLines.push(anchor.createPriceLine({
                        price: 0,
                        color: 'rgba(161,161,170,0.55)',
                        lineWidth: 1,
                        lineStyle: 2,
                        axisLabelVisible: true,
                        title: '0',
                    }));
                }
            } catch (_) {}
        }

        // TRIX — pane вокруг нулевой линии.
        if (wantTrix) {
            const st = lastTrixPayload.style || {};
            const levels = lastTrixPayload.levels || {};
            let paneIndex = 1;
            let trixPane = null;
            try {
                trixPane = chart.addPane(false);
                if (trixPane && typeof trixPane.setStretchFactor === 'function') {
                    trixPane.setStretchFactor(0.28);
                }
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[dashboard indicators] addPane TRIX', e);
            }
            const data = (lastTrixPayload.series || {}).trix || [];
            let trixLine = null;
            if (Array.isArray(data) && data.length) {
                trixLine = chart.addSeries(LineSeries, {
                    color: st.color || '#00ffff',
                    lineWidth: st.line_width || 1,
                    priceLineVisible: false,
                    baseLineVisible: false,
                    lastValueVisible: true,
                    crosshairMarkerVisible: false,
                    priceFormat: { type: 'price', precision: 2, minMove: 0.01 },
                }, paneIndex);
                trixLine.setData(data.map((p) => ({ time: p.time, value: p.value })));
                classicLineSeries['trix'] = trixLine;
            }
            try {
                const paneApi = trixPane || (typeof chart.panes === 'function' ? chart.panes()[paneIndex] : null);
                if (paneApi?.attachPrimitive && window.PlatformChartMarkers?.createPaneSolidBackdrop) {
                    paneApi.attachPrimitive(window.PlatformChartMarkers.createPaneSolidBackdrop('#0e1018'));
                }
            } catch (e) {
                console.error('[dashboard indicators] TRIX pane backdrop', e);
            }
            try {
                if (trixLine && levels.show && window.PlatformChartMarkers?.attachRsiLevelLines) {
                    rsiLevelLines.push(...window.PlatformChartMarkers.attachRsiLevelLines(trixLine, levels));
                }
            } catch (_) {}
        }

        // CMO — pane с уровнями ±50.
        if (wantCmo) {
            const st = lastCmoPayload.style || {};
            const levels = lastCmoPayload.levels || {};
            let paneIndex = 1;
            let cmoPane = null;
            try {
                cmoPane = chart.addPane(false);
                if (cmoPane && typeof cmoPane.setStretchFactor === 'function') {
                    cmoPane.setStretchFactor(0.28);
                }
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[dashboard indicators] addPane CMO', e);
            }
            const data = (lastCmoPayload.series || {}).cmo || [];
            let cmoLine = null;
            if (Array.isArray(data) && data.length) {
                cmoLine = chart.addSeries(LineSeries, {
                    color: st.color || '#ffa500',
                    lineWidth: st.line_width || 1,
                    priceLineVisible: false,
                    baseLineVisible: false,
                    lastValueVisible: true,
                    crosshairMarkerVisible: false,
                    priceFormat: { type: 'price', precision: 2, minMove: 0.01 },
                }, paneIndex);
                cmoLine.setData(data.map((p) => ({ time: p.time, value: p.value })));
                classicLineSeries['cmo'] = cmoLine;
            }
            try {
                const paneApi = cmoPane || (typeof chart.panes === 'function' ? chart.panes()[paneIndex] : null);
                if (paneApi?.attachPrimitive && window.PlatformChartMarkers?.createPaneSolidBackdrop) {
                    paneApi.attachPrimitive(window.PlatformChartMarkers.createPaneSolidBackdrop('#0e1018'));
                }
            } catch (e) {
                console.error('[dashboard indicators] CMO pane backdrop', e);
            }
            try {
                if (cmoLine && levels.show && window.PlatformChartMarkers?.attachRsiLevelLines) {
                    rsiLevelLines.push(...window.PlatformChartMarkers.attachRsiLevelLines(cmoLine, levels));
                }
            } catch (_) {}
        }

        // DeMarker — pane в шкале 0..1 с уровнями 0.7/0.3.
        if (wantDemarker) {
            const st = lastDemarkerPayload.style || {};
            const levels = lastDemarkerPayload.levels || {};
            let paneIndex = 1;
            let demPane = null;
            try {
                demPane = chart.addPane(false);
                if (demPane && typeof demPane.setStretchFactor === 'function') {
                    demPane.setStretchFactor(0.28);
                }
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[dashboard indicators] addPane DeMarker', e);
            }
            const data = (lastDemarkerPayload.series || {}).dem || [];
            let demLine = null;
            if (Array.isArray(data) && data.length) {
                demLine = chart.addSeries(LineSeries, {
                    color: st.color || '#00ffff',
                    lineWidth: st.line_width || 1,
                    priceLineVisible: false,
                    baseLineVisible: false,
                    lastValueVisible: true,
                    crosshairMarkerVisible: false,
                    // DeMarker всегда в диапазоне 0..1 — фиксируем шкалу под уровни 0.7/0.3.
                    priceFormat: { type: 'price', precision: 2, minMove: 0.01 },
                    autoscaleInfoProvider: () => ({ priceRange: { minValue: 0, maxValue: 1 } }),
                }, paneIndex);
                demLine.setData(data.map((p) => ({ time: p.time, value: p.value })));
                classicLineSeries['demarker'] = demLine;
            }
            try {
                const paneApi = demPane || (typeof chart.panes === 'function' ? chart.panes()[paneIndex] : null);
                if (paneApi?.attachPrimitive && window.PlatformChartMarkers?.createPaneSolidBackdrop) {
                    paneApi.attachPrimitive(window.PlatformChartMarkers.createPaneSolidBackdrop('#0e1018'));
                }
            } catch (e) {
                console.error('[dashboard indicators] DeMarker pane backdrop', e);
            }
            try {
                if (demLine && levels.show && window.PlatformChartMarkers?.attachRsiLevelLines) {
                    rsiLevelLines.push(...window.PlatformChartMarkers.attachRsiLevelLines(demLine, levels));
                }
            } catch (_) {}
        }

        // RVI — pane: линия + сигнал (без фиксированных уровней).
        if (wantRvi) {
            const st = lastRviPayload.style || {};
            const ser = lastRviPayload.series || {};
            const lineW = st.line_width || 1;
            let paneIndex = 1;
            let rviPane = null;
            try {
                rviPane = chart.addPane(false);
                if (rviPane && typeof rviPane.setStretchFactor === 'function') {
                    rviPane.setStretchFactor(0.28);
                }
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[dashboard indicators] addPane RVI', e);
            }
            const addRviLine = (key, data, color, showLast) => {
                if (!Array.isArray(data) || !data.length) return null;
                const s = chart.addSeries(LineSeries, {
                    color: color || '#8b0000',
                    lineWidth: lineW,
                    priceLineVisible: false,
                    baseLineVisible: false,
                    lastValueVisible: !!showLast,
                    crosshairMarkerVisible: false,
                    priceFormat: { type: 'price', precision: 2, minMove: 0.01 },
                }, paneIndex);
                s.setData(data.map((p) => ({ time: p.time, value: p.value })));
                classicLineSeries[key] = s;
                return s;
            };
            addRviLine('rvi', ser.rvi, st.color_rvi || '#8b0000', true);
            addRviLine('rvi_signal', ser.signal, st.color_signal || '#1e90ff', false);
            try {
                const paneApi = rviPane || (typeof chart.panes === 'function' ? chart.panes()[paneIndex] : null);
                if (paneApi?.attachPrimitive && window.PlatformChartMarkers?.createPaneSolidBackdrop) {
                    paneApi.attachPrimitive(window.PlatformChartMarkers.createPaneSolidBackdrop('#0e1018'));
                }
            } catch (e) {
                console.error('[dashboard indicators] RVI pane backdrop', e);
            }
        }

        // Ultimate Oscillator — pane 0..100 с уровнями 70/30.
        if (wantUltimate) {
            const st = lastUltimatePayload.style || {};
            const levels = lastUltimatePayload.levels || {};
            let paneIndex = 1;
            let uoPane = null;
            try {
                uoPane = chart.addPane(false);
                if (uoPane && typeof uoPane.setStretchFactor === 'function') {
                    uoPane.setStretchFactor(0.28);
                }
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[dashboard indicators] addPane Ultimate', e);
            }
            const data = (lastUltimatePayload.series || {}).uo || [];
            let uoLine = null;
            if (Array.isArray(data) && data.length) {
                uoLine = chart.addSeries(LineSeries, {
                    color: st.color || '#1e90ff',
                    lineWidth: st.line_width || 1,
                    priceLineVisible: false,
                    baseLineVisible: false,
                    lastValueVisible: true,
                    crosshairMarkerVisible: false,
                    priceFormat: { type: 'price', precision: 2, minMove: 0.01 },
                }, paneIndex);
                uoLine.setData(data.map((p) => ({ time: p.time, value: p.value })));
                classicLineSeries['uo'] = uoLine;
            }
            try {
                const paneApi = uoPane || (typeof chart.panes === 'function' ? chart.panes()[paneIndex] : null);
                if (paneApi?.attachPrimitive && window.PlatformChartMarkers?.createPaneSolidBackdrop) {
                    paneApi.attachPrimitive(window.PlatformChartMarkers.createPaneSolidBackdrop('#0e1018'));
                }
            } catch (e) {
                console.error('[dashboard indicators] Ultimate pane backdrop', e);
            }
            try {
                if (uoLine && levels.show && window.PlatformChartMarkers?.attachRsiLevelLines) {
                    rsiLevelLines.push(...window.PlatformChartMarkers.attachRsiLevelLines(uoLine, levels));
                }
            } catch (_) {}
        }

        // SMI — pane: линия + сигнал + уровни ±40.
        if (wantSmi) {
            const st = lastSmiPayload.style || {};
            const levels = lastSmiPayload.levels || {};
            const ser = lastSmiPayload.series || {};
            const lineW = st.line_width || 1;
            let paneIndex = 1;
            let smiPane = null;
            try {
                smiPane = chart.addPane(false);
                if (smiPane && typeof smiPane.setStretchFactor === 'function') {
                    smiPane.setStretchFactor(0.28);
                }
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[dashboard indicators] addPane SMI', e);
            }
            const addSmiLine = (key, data, color, showLast) => {
                if (!Array.isArray(data) || !data.length) return null;
                const s = chart.addSeries(LineSeries, {
                    color: color || '#8a2be2',
                    lineWidth: lineW,
                    priceLineVisible: false,
                    baseLineVisible: false,
                    lastValueVisible: !!showLast,
                    crosshairMarkerVisible: false,
                    priceFormat: { type: 'price', precision: 2, minMove: 0.01 },
                }, paneIndex);
                s.setData(data.map((p) => ({ time: p.time, value: p.value })));
                classicLineSeries[key] = s;
                return s;
            };
            const smiLine = addSmiLine('smi', ser.smi, st.color_smi || '#8a2be2', true);
            addSmiLine('smi_signal', ser.signal, st.color_signal || '#008000', false);
            try {
                const paneApi = smiPane || (typeof chart.panes === 'function' ? chart.panes()[paneIndex] : null);
                if (paneApi?.attachPrimitive && window.PlatformChartMarkers?.createPaneSolidBackdrop) {
                    paneApi.attachPrimitive(window.PlatformChartMarkers.createPaneSolidBackdrop('#0e1018'));
                }
            } catch (e) {
                console.error('[dashboard indicators] SMI pane backdrop', e);
            }
            try {
                if (smiLine && levels.show && window.PlatformChartMarkers?.attachRsiLevelLines) {
                    rsiLevelLines.push(...window.PlatformChartMarkers.attachRsiLevelLines(smiLine, levels));
                }
            } catch (_) {}
        }

        // StdDev — ещё один pane волатильности (без уровней).
        if (wantStdDev) {
            const st = lastStdDevPayload.style || {};
            let paneIndex = 1;
            try {
                const pane = chart.addPane(false);
                if (pane && typeof pane.setStretchFactor === 'function') {
                    pane.setStretchFactor(0.28);
                }
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[dashboard indicators] addPane StdDev', e);
            }
            const s = chart.addSeries(LineSeries, {
                color: st.color || '#008000',
                lineWidth: st.line_width || 1,
                priceLineVisible: false,
                lastValueVisible: true,
                crosshairMarkerVisible: false,
            }, paneIndex);
            const data = ((lastStdDevPayload.series || {}).stddev) || [];
            s.setData(data.map((p) => ({ time: p.time, value: p.value })));
            classicLineSeries['stddev'] = s;
        }

        // Ivashov Range — ещё один pane волатильности (без уровней).
        if (wantIvashov) {
            const st = lastIvashovPayload.style || {};
            let paneIndex = 1;
            try {
                const pane = chart.addPane(false);
                if (pane && typeof pane.setStretchFactor === 'function') {
                    pane.setStretchFactor(0.28);
                }
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[dashboard indicators] addPane Ivashov', e);
            }
            const s = chart.addSeries(LineSeries, {
                color: st.color || '#0000ff',
                lineWidth: st.line_width || 1,
                priceLineVisible: false,
                lastValueVisible: true,
                crosshairMarkerVisible: false,
            }, paneIndex);
            const data = ((lastIvashovPayload.series || {}).ivashov) || [];
            s.setData(data.map((p) => ({ time: p.time, value: p.value })));
            classicLineSeries['ivashov'] = s;
        }

        if (wantAtr) {
            const st = lastAtrPayload.style || {};
            let paneIndex = 1;
            try {
                const pane = chart.addPane(false);
                if (pane && typeof pane.setStretchFactor === 'function') {
                    pane.setStretchFactor(0.28);
                }
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[dashboard indicators] addPane ATR', e);
            }
            const s = chart.addSeries(LineSeries, {
                color: st.color || '#1e90ff',
                lineWidth: st.line_width || 1,
                priceLineVisible: false,
                lastValueVisible: true,
                crosshairMarkerVisible: false,
            }, paneIndex);
            const data = ((lastAtrPayload.series || {}).atr) || [];
            s.setData(data.map((p) => ({ time: p.time, value: p.value })));
            classicLineSeries['atr'] = s;
        }
    }

    /** Тянем только включённые classic-слои и рисуем series/panes. */
    function fetchClassicIndicators() {
        if (!currentInstrument || !anyClassicLayerOn()) {
            lastBollingerPayload = null;
            lastRsiPayload = null;
            lastSmaPayload = null;
            lastEmaPayload = null;
            lastVwmaPayload = null;
            lastAtrPayload = null;
            lastMacdPayload = null;
            lastStochasticPayload = null;
            lastCciPayload = null;
            lastKeltnerPayload = null;
            lastEnvelopsPayload = null;
            lastParabolicSarPayload = null;
            lastSuperTrendPayload = null;
            lastAdxPayload = null;
            lastWilliamsPayload = null;
            lastAoPayload = null;
            lastAlligatorPayload = null;
            lastIchimokuPayload = null;
            lastPriceChannelPayload = null;
            lastNrtrPayload = null;
            lastHmaPayload = null;
            lastAroonPayload = null;
            lastMomentumPayload = null;
            lastRocPayload = null;
            lastOsmaPayload = null;
            lastTrixPayload = null;
            lastCmoPayload = null;
            lastDemarkerPayload = null;
            lastRviPayload = null;
            lastUltimatePayload = null;
            lastSmiPayload = null;
            lastStdDevPayload = null;
            lastSmaChannelPayload = null;
            lastIvashovPayload = null;
            lastLinRegPayload = null;
            lastLinRegChannelPayload = null;
            lastKalmanPayload = null;
            lastFractalPayload = null;
            applyClassicOsEngineSeries();
            return;
        }
        const wantBb = layerBollinger && layerBollinger.checked;
        const wantRsi = layerRsi && layerRsi.checked;
        const wantSma = layerSma && layerSma.checked;
        const wantEma = layerEma && layerEma.checked;
        const wantVwma = layerVwma && layerVwma.checked;
        const wantAtr = layerAtr && layerAtr.checked;
        const wantMacd = layerMacd && layerMacd.checked;
        const wantStochastic = layerStochastic && layerStochastic.checked;
        const wantCci = layerCci && layerCci.checked;
        const wantKeltner = layerKeltner && layerKeltner.checked;
        const wantEnvelops = layerEnvelops && layerEnvelops.checked;
        const wantParabolicSar = layerParabolicSar && layerParabolicSar.checked;
        const wantSuperTrend = layerSuperTrend && layerSuperTrend.checked;
        const wantAdx = layerAdx && layerAdx.checked;
        const wantWilliams = layerWilliams && layerWilliams.checked;
        const wantAo = layerAo && layerAo.checked;
        const wantAlligator = layerAlligator && layerAlligator.checked;
        const wantIchimoku = layerIchimoku && layerIchimoku.checked;
        const wantPriceChannel = layerPriceChannel && layerPriceChannel.checked;
        const wantNrtr = layerNrtr && layerNrtr.checked;
        const wantHma = layerHma && layerHma.checked;
        const wantAroon = layerAroon && layerAroon.checked;
        const wantMomentum = layerMomentum && layerMomentum.checked;
        const wantRoc = layerRoc && layerRoc.checked;
        const wantOsma = layerOsma && layerOsma.checked;
        const wantTrix = layerTrix && layerTrix.checked;
        const wantCmo = layerCmo && layerCmo.checked;
        const wantDemarker = layerDemarker && layerDemarker.checked;
        const wantRvi = layerRvi && layerRvi.checked;
        const wantUltimate = layerUltimate && layerUltimate.checked;
        const wantSmi = layerSmi && layerSmi.checked;
        const wantStdDev = layerStdDev && layerStdDev.checked;
        const wantSmaChannel = layerSmaChannel && layerSmaChannel.checked;
        const wantIvashov = layerIvashov && layerIvashov.checked;
        const wantLinReg = layerLinReg && layerLinReg.checked;
        const wantLinRegChannel = layerLinRegChannel && layerLinRegChannel.checked;
        const wantKalman = layerKalman && layerKalman.checked;
        const wantFractal = layerFractal && layerFractal.checked;
        const layers = [];
        if (wantBb) layers.push('bollinger');
        if (wantRsi) layers.push('rsi');
        if (wantSma) layers.push('sma');
        if (wantEma) layers.push('ema');
        if (wantVwma) layers.push('vwma');
        if (wantAtr) layers.push('atr');
        if (wantMacd) layers.push('macd');
        if (wantStochastic) layers.push('stochastic');
        if (wantCci) layers.push('cci');
        if (wantKeltner) layers.push('keltner');
        if (wantEnvelops) layers.push('envelops');
        if (wantParabolicSar) layers.push('parabolic_sar');
        if (wantSuperTrend) layers.push('supertrend');
        if (wantAdx) layers.push('adx');
        if (wantWilliams) layers.push('williams');
        if (wantAo) layers.push('ao');
        if (wantAlligator) layers.push('alligator');
        if (wantIchimoku) layers.push('ichimoku');
        if (wantPriceChannel) layers.push('price_channel');
        if (wantNrtr) layers.push('nrtr');
        if (wantHma) layers.push('hma');
        if (wantAroon) layers.push('aroon');
        if (wantMomentum) layers.push('momentum');
        if (wantRoc) layers.push('roc');
        if (wantOsma) layers.push('osma');
        if (wantTrix) layers.push('trix');
        if (wantCmo) layers.push('cmo');
        if (wantDemarker) layers.push('demarker');
        if (wantRvi) layers.push('rvi');
        if (wantUltimate) layers.push('ultimate');
        if (wantSmi) layers.push('smi');
        if (wantStdDev) layers.push('stddev');
        if (wantSmaChannel) layers.push('sma_channel');
        if (wantIvashov) layers.push('ivashov');
        if (wantLinReg) layers.push('linreg');
        if (wantLinRegChannel) layers.push('linreg_channel');
        if (wantKalman) layers.push('kalman');
        if (wantFractal) layers.push('fractal');

        const seq = ++classicIndicatorsFetchSeq;
        const params = new URLSearchParams({
            instrument: currentInstrument,
            timeframe: String(currentTimeframeMinutes),
        });
        layers.forEach((l) => params.append('layers[]', l));

        fetch(CLASSIC_INDICATORS_URL + '?' + params.toString(), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then((r) => r.json())
            .then((d) => {
                if (seq !== classicIndicatorsFetchSeq) return;
                try {
                    lastBollingerPayload = wantBb && d && d.bollinger ? d.bollinger : null;
                    lastRsiPayload = wantRsi && d && d.rsi ? d.rsi : null;
                    lastSmaPayload = wantSma && d && d.sma ? d.sma : null;
                    lastEmaPayload = wantEma && d && d.ema ? d.ema : null;
                    lastVwmaPayload = wantVwma && d && d.vwma ? d.vwma : null;
                    lastAtrPayload = wantAtr && d && d.atr ? d.atr : null;
                    lastMacdPayload = wantMacd && d && d.macd ? d.macd : null;
                    lastStochasticPayload = wantStochastic && d && d.stochastic ? d.stochastic : null;
                    lastCciPayload = wantCci && d && d.cci ? d.cci : null;
                    lastKeltnerPayload = wantKeltner && d && d.keltner ? d.keltner : null;
                    lastEnvelopsPayload = wantEnvelops && d && d.envelops ? d.envelops : null;
                    lastParabolicSarPayload = wantParabolicSar && d && d.parabolic_sar ? d.parabolic_sar : null;
                    lastSuperTrendPayload = wantSuperTrend && d && d.supertrend ? d.supertrend : null;
                    lastAdxPayload = wantAdx && d && d.adx ? d.adx : null;
                    lastWilliamsPayload = wantWilliams && d && d.williams ? d.williams : null;
                    lastAoPayload = wantAo && d && d.ao ? d.ao : null;
                    lastAlligatorPayload = wantAlligator && d && d.alligator ? d.alligator : null;
                    lastIchimokuPayload = wantIchimoku && d && d.ichimoku ? d.ichimoku : null;
                    lastPriceChannelPayload = wantPriceChannel && d && d.price_channel ? d.price_channel : null;
                    lastNrtrPayload = wantNrtr && d && d.nrtr ? d.nrtr : null;
                    lastHmaPayload = wantHma && d && d.hma ? d.hma : null;
                    lastAroonPayload = wantAroon && d && d.aroon ? d.aroon : null;
                    lastMomentumPayload = wantMomentum && d && d.momentum ? d.momentum : null;
                    lastRocPayload = wantRoc && d && d.roc ? d.roc : null;
                    lastOsmaPayload = wantOsma && d && d.osma ? d.osma : null;
                    lastTrixPayload = wantTrix && d && d.trix ? d.trix : null;
                    lastCmoPayload = wantCmo && d && d.cmo ? d.cmo : null;
                    lastDemarkerPayload = wantDemarker && d && d.demarker ? d.demarker : null;
                    lastRviPayload = wantRvi && d && d.rvi ? d.rvi : null;
                    lastUltimatePayload = wantUltimate && d && d.ultimate ? d.ultimate : null;
                    lastSmiPayload = wantSmi && d && d.smi ? d.smi : null;
                    lastStdDevPayload = wantStdDev && d && d.stddev ? d.stddev : null;
                    lastSmaChannelPayload = wantSmaChannel && d && d.sma_channel ? d.sma_channel : null;
                    lastIvashovPayload = wantIvashov && d && d.ivashov ? d.ivashov : null;
                    lastLinRegPayload = wantLinReg && d && d.linreg ? d.linreg : null;
                    lastLinRegChannelPayload = wantLinRegChannel && d && d.linreg_channel ? d.linreg_channel : null;
                    lastKalmanPayload = wantKalman && d && d.kalman ? d.kalman : null;
                    lastFractalPayload = wantFractal && d && d.fractal ? d.fractal : null;
                    applyClassicOsEngineSeries();
                } catch (e) {
                    console.error('[dashboard classic indicators] apply', e);
                    lastBollingerPayload = null;
                    lastRsiPayload = null;
                    lastSmaPayload = null;
                    lastEmaPayload = null;
                    lastVwmaPayload = null;
                    lastAtrPayload = null;
                    lastMacdPayload = null;
                    lastStochasticPayload = null;
                    lastCciPayload = null;
                    lastKeltnerPayload = null;
                    lastEnvelopsPayload = null;
                    lastParabolicSarPayload = null;
                    lastSuperTrendPayload = null;
                    lastAdxPayload = null;
                    lastWilliamsPayload = null;
                    lastAoPayload = null;
                    lastAlligatorPayload = null;
                    lastIchimokuPayload = null;
                    lastPriceChannelPayload = null;
                    lastNrtrPayload = null;
                    lastHmaPayload = null;
                    lastAroonPayload = null;
                    lastMomentumPayload = null;
                    lastRocPayload = null;
                    lastOsmaPayload = null;
                    lastTrixPayload = null;
                    lastCmoPayload = null;
                    lastDemarkerPayload = null;
                    lastRviPayload = null;
                    lastUltimatePayload = null;
                    lastSmiPayload = null;
                    lastStdDevPayload = null;
                    lastSmaChannelPayload = null;
                    lastIvashovPayload = null;
                    lastLinRegPayload = null;
                    lastLinRegChannelPayload = null;
                    lastKalmanPayload = null;
                    lastFractalPayload = null;
                    try { applyClassicOsEngineSeries(); } catch (_) {}
                }
            })
            .catch(() => {});
    }

    /**
     * Тянем Liquidity Swings с отдельного эндпоинта (как DGT), чтобы не смешивать тяжёлые ответы.
     * Отрисовка — в redrawLiquiditySwingsOverlay(), вызывается перед PA-оверлеем.
     */
    function fetchLiquiditySwingsIndicator() {
        if (!currentInstrument || !layerLiquidity || !layerLiquidity.checked) {
            lastLsPayload = null;
            redrawLiquiditySwingsOverlay();
            return;
        }
        const params = new URLSearchParams({ instrument: currentInstrument, timeframe: String(currentTimeframeMinutes) });
        fetch(LS_INDICATOR_URL + '?' + params.toString(), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then((r) => r.json())
            .then((d) => {
                try {
                    lastLsPayload = d && d.liquidity_swings ? d.liquidity_swings : null;
                    redrawLiquiditySwingsOverlay();
                } catch (e) {
                    console.error('[Liquidity Swings] dashboard apply', e);
                    lastLsPayload = null;
                    try { redrawLiquiditySwingsOverlay(); } catch (_) {}
                }
            })
            .catch(() => {});
    }

    /**
     * SMC Flow — отдельный эндпоинт (как Liquidity Swings), чтобы не раздувать ответ DGT.
     * Отрисовка в redrawSmcFlowOverlay(); вызывается из redrawPriceActionOverlay().
     */
    function fetchSmcFlowIndicator() {
        if (!currentInstrument || !layerSmc || !layerSmc.checked) {
            lastSmcPayload = null;
            redrawSmcFlowOverlay();
            return;
        }
        const params = new URLSearchParams({ instrument: currentInstrument, timeframe: String(currentTimeframeMinutes) });
        fetch(SMC_INDICATOR_URL + '?' + params.toString(), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then((r) => r.json())
            .then((d) => {
                try {
                    lastSmcPayload = d && d.smc_flow ? d.smc_flow : null;
                    redrawSmcFlowOverlay();
                } catch (e) {
                    console.error('[SMC Flow] dashboard apply', e);
                    lastSmcPayload = null;
                    try { redrawSmcFlowOverlay(); } catch (_) {}
                }
            })
            .catch(() => {});
    }

    /** Размер подписей объёма: как Labels Size в LuxAlgo (tiny / small / normal). */
    function lsLabelFontPx(sz) {
        const s = (sz || 'tiny').toString().toLowerCase();
        if (s === 'small') return '11px';
        if (s === 'normal') return '13px';
        return '9px';
    }

    /**
     * DOM-оверлей зон / линий / подписей объёма (аналог отображения в TradingView).
     */
    function redrawLiquiditySwingsOverlay() {
        if (!liquiditySwingsOverlay || !chart || !candlestickSeries) return;
        liquiditySwingsOverlay.innerHTML = '';
        if (!layerLiquidity || !layerLiquidity.checked || !lastLsPayload || !allChartData.length) return;
        const ts = chart.timeScale();
        const lastBarTime = allChartData[allChartData.length - 1].time;
        try {
            const boxes = Array.isArray(lastLsPayload.boxes) ? lastLsPayload.boxes : [];
            boxes.forEach((z) => {
                if (!z || lastBarTime < z.time_start) return;
                const tEnd = Math.min(lastBarTime, z.time_end);
                const x1 = ts.timeToCoordinate(z.time_start);
                const x2 = ts.timeToCoordinate(tEnd);
                const y1 = candlestickSeries.priceToCoordinate(z.price_high);
                const y2 = candlestickSeries.priceToCoordinate(z.price_low);
                if (x1 == null || x2 == null || y1 == null || y2 == null) return;
                const div = document.createElement('div');
                div.style.position = 'absolute';
                div.style.boxSizing = 'border-box';
                div.style.pointerEvents = 'none';
                div.style.left = Math.min(x1, x2) + 'px';
                div.style.top = Math.min(y1, y2) + 'px';
                div.style.width = Math.max(1, Math.abs(x2 - x1)) + 'px';
                div.style.height = Math.max(1, Math.abs(y2 - y1)) + 'px';
                div.style.background = z.fill || 'rgba(239,68,68,0.25)';
                div.style.border = z.border && z.border !== 'none' ? z.border : 'none';
                liquiditySwingsOverlay.appendChild(div);
            });
            const lines = Array.isArray(lastLsPayload.lines) ? lastLsPayload.lines : [];
            lines.forEach((ln) => {
                if (!ln || typeof ln !== 'object') return;
                const x1 = ts.timeToCoordinate(Number(ln.time_start));
                const x2 = ts.timeToCoordinate(Number(ln.time_end));
                const y = candlestickSeries.priceToCoordinate(Number(ln.price));
                if (x1 == null || x2 == null || y == null || Number.isNaN(x1) || Number.isNaN(x2) || Number.isNaN(y)) return;
                const left = Math.min(x1, x2);
                const w = Math.max(1, Math.abs(x2 - x1));
                const st = (ln.style || 'Solid').toLowerCase();
                const col = paCssColor(ln.color);
                const lw = Math.max(1, Number(ln.width) || 1);
                const div = document.createElement('div');
                div.style.position = 'absolute';
                div.style.left = left + 'px';
                div.style.top = (y - lw / 2) + 'px';
                div.style.width = w + 'px';
                div.style.height = lw + 'px';
                div.style.pointerEvents = 'none';
                if (st === 'dotted' || st === 'dashed') {
                    div.style.background = 'transparent';
                    div.style.height = '0';
                    div.style.borderTop = (st === 'dotted' ? `${lw}px dotted ` : `${lw}px dashed `) + col;
                } else {
                    div.style.background = col;
                }
                liquiditySwingsOverlay.appendChild(div);
            });
            const labels = Array.isArray(lastLsPayload.labels) ? lastLsPayload.labels : [];
            labels.forEach((lb) => {
                if (!lb || typeof lb !== 'object') return;
                const x = ts.timeToCoordinate(Number(lb.time));
                const y = candlestickSeries.priceToCoordinate(Number(lb.price));
                if (x == null || y == null || Number.isNaN(x) || Number.isNaN(y)) return;
                const span = document.createElement('span');
                span.textContent = String(lb.text || '');
                span.style.position = 'absolute';
                span.style.left = x + 'px';
                span.style.top = y + 'px';
                span.style.transform = (lb.position === 'below')
                    ? 'translate(-50%, 6px)'
                    : 'translate(-50%, calc(-100% - 4px))';
                span.style.fontSize = lsLabelFontPx(lastLsPayload.label_size);
                span.style.fontWeight = '600';
                span.style.lineHeight = '1';
                span.style.color = paCssColor(lb.color);
                span.style.pointerEvents = 'none';
                span.style.whiteSpace = 'nowrap';
                liquiditySwingsOverlay.appendChild(span);
            });
        } catch (e) {
            console.error('[Liquidity Swings] dashboard redraw', e);
        }
    }

    /** Mxwll BoS/CHoCH — горизонтальный пунктир структуры. */
    function smcIsStructureLine(ln) {
        if (!ln || typeof ln !== 'object') return false;
        const kind = String(ln.kind || '');
        if (kind === 'mxwll_main_leg') return true;
        const st = String(ln.style || 'Solid').toLowerCase();
        const p0 = Number(ln.price_start ?? ln.price);
        const p1 = Number(ln.price_end ?? ln.price);
        return st === 'dashed' && Math.abs(p0 - p1) < 1e-10;
    }

    function smcIsStructureLabel(lb) {
        if (!lb || typeof lb !== 'object') return false;
        return String(lb.kind || '') === 'mxwll_structure'
            || /^(I-)?(BoS|CHoCH)$/.test(String(lb.text || ''));
    }

    function smcAppendOverlayLine(fragment, ts, ln, viewportW) {
        const x1 = ts.timeToCoordinate(Number(ln.time_start));
        const x2 = ts.timeToCoordinate(Number(ln.time_end));
        const priceStart = Number(ln.price_start ?? ln.price);
        const priceEnd = Number(ln.price_end ?? ln.price);
        const y1 = candlestickSeries.priceToCoordinate(priceStart);
        const y2 = candlestickSeries.priceToCoordinate(priceEnd);
        if (x1 == null || x2 == null || y1 == null || y2 == null) return false;
        if ([x1, x2, y1, y2].some((v) => Number.isNaN(v))) return false;
        if (Math.max(x1, x2) < -60 || Math.min(x1, x2) > viewportW + 60) return false;
        const st = (ln.style || 'Solid').toLowerCase();
        const col = paCssColor(ln.color);
        const lw = Math.max(1, Number(ln.width) || 2);
        const isHoriz = Math.abs(priceStart - priceEnd) < 1e-10;
        if (isHoriz && (st === 'dotted' || st === 'dashed')) {
            const left = Math.min(x1, x2);
            const w = Math.max(1, Math.abs(x2 - x1));
            const div = document.createElement('div');
            div.style.position = 'absolute';
            div.style.left = left + 'px';
            div.style.top = (y1 - lw / 2) + 'px';
            div.style.width = w + 'px';
            div.style.height = '0';
            div.style.pointerEvents = 'none';
            div.style.background = 'transparent';
            div.style.borderTop = (st === 'dotted' ? `${lw}px dotted ` : `${lw}px dashed `) + col;
            fragment.appendChild(div);
        } else {
            appendOverlaySegment(fragment, x1, y1, x2, y2, col, lw);
        }
        return true;
    }

    function smcAppendOverlayLabel(fragment, ts, lb, viewportW) {
        if (!lb || typeof lb !== 'object') return;
        const isStructureLabel = smcIsStructureLabel(lb);
        const x = ts.timeToCoordinate(Number(lb.time));
        const y = candlestickSeries.priceToCoordinate(Number(lb.price));
        if (y == null || Number.isNaN(y)) return;
        const cullPad = isStructureLabel ? 140 : 80;
        if (x == null || Number.isNaN(x) || x < -cullPad || x > viewportW + cullPad) return;
        const pos = String(lb.position || '');
        let transform = 'translate(-50%, calc(-100% - 4px))';
        if (pos === 'below') {
            transform = 'translate(-50%, 8px)';
        } else if (pos === 'center') {
            transform = 'translate(-50%, -50%)';
        } else if (pos === 'above') {
            transform = 'translate(-50%, calc(-100% - 8px))';
        } else if (pos === 'left') {
            transform = 'translate(8px, -50%)';
        }
        const span = document.createElement('span');
        span.textContent = String(lb.text || '');
        span.style.position = 'absolute';
        span.style.left = x + 'px';
        span.style.top = y + 'px';
        span.style.transform = transform;
        span.style.fontSize = isStructureLabel
            ? (lastSmcPayload.label_size === 'normal' ? '13px' : (lastSmcPayload.label_size === 'small' ? '12px' : '11px'))
            : lsLabelFontPx(lastSmcPayload.label_size);
        span.style.fontWeight = isStructureLabel ? '700' : '600';
        span.style.lineHeight = '1.1';
        span.style.color = paCssColor(lb.color || '#ffffff');
        if (isStructureLabel) {
            span.style.textShadow = '0 0 4px rgba(0,0,0,0.95), 0 1px 2px rgba(0,0,0,0.9)';
            span.style.zIndex = '3';
            span.style.letterSpacing = '0.02em';
        }
        const bg = String(lb.background || '');
        const transparentBg = bg === '' || bg === 'transparent' || bg.toLowerCase() === '#00000000';
        if (!transparentBg) {
            span.style.background = paCssColor(bg || '#22c55e');
            span.style.padding = '2px 5px';
            span.style.borderRadius = '3px';
        }
        span.style.pointerEvents = 'none';
        span.style.whiteSpace = 'nowrap';
        fragment.appendChild(span);
    }

    /** Зоны, линии и лейблы SMC Flow (UT Bot) — отдельный DOM-слой поверх DGT. */
    function redrawSmcFlowOverlay() {
        if (!smcFlowOverlay || !chart || !candlestickSeries) return;
        smcFlowOverlay.innerHTML = '';
        if (!layerSmc || !layerSmc.checked || !lastSmcPayload || !allChartData.length) return;
        const ts = chart.timeScale();
        try {
            const areas = Array.isArray(lastSmcPayload.areas) ? lastSmcPayload.areas : [];
            const lines = Array.isArray(lastSmcPayload.lines) ? lastSmcPayload.lines : [];
            const labels = Array.isArray(lastSmcPayload.labels) ? lastSmcPayload.labels : [];
            const structureLines = [];
            const otherLines = [];
            lines.forEach((ln) => {
                if (smcIsStructureLine(ln)) structureLines.push(ln);
                else otherLines.push(ln);
            });
            const fragment = document.createDocumentFragment();
            const viewportW = Math.max(1, chartWrapper?.clientWidth || 0);
            const maxRenderable = Math.max(250, Math.min(900, Math.floor(viewportW * 0.9)));
            let rendered = 0;
            areas.forEach((ar) => {
                if (rendered >= maxRenderable) return;
                if (!ar || typeof ar !== 'object') return;
                const x1 = ts.timeToCoordinate(Number(ar.time_start));
                const x2 = zoneEndXClipped(ts, Number(ar.time_end), allChartData);
                const y1 = candlestickSeries.priceToCoordinate(Number(ar.price_high));
                const y2 = candlestickSeries.priceToCoordinate(Number(ar.price_low));
                if (x1 == null || x2 == null || y1 == null || y2 == null) return;
                if (Math.abs(x2 - x1) < 1) return;
                if ([x1, x2, y1, y2].some((v) => Number.isNaN(v))) return;
                if (Math.max(x1, x2) < -80 || Math.min(x1, x2) > viewportW + 80) return;
                const div = document.createElement('div');
                div.style.position = 'absolute';
                div.style.pointerEvents = 'none';
                div.style.boxSizing = 'border-box';
                div.style.left = Math.min(x1, x2) + 'px';
                div.style.top = Math.min(y1, y2) + 'px';
                div.style.width = Math.max(1, Math.abs(x2 - x1)) + 'px';
                div.style.height = Math.max(1, Math.abs(y2 - y1)) + 'px';
                div.style.background = paCssColor(ar.fill || 'rgba(41,98,255,0.24)');
                div.style.border = (ar.border && ar.border !== 'none') ? String(ar.border) : 'none';
                fragment.appendChild(div);
                rendered++;
            });
            // Пунктиры BoS/CHoCH — в приоритете, иначе их вытесняют сегменты UT/Hull.
            structureLines.forEach((ln) => {
                if (smcAppendOverlayLine(fragment, ts, ln, viewportW)) rendered++;
            });
            otherLines.forEach((ln) => {
                if (rendered >= maxRenderable) return;
                if (smcAppendOverlayLine(fragment, ts, ln, viewportW)) rendered++;
            });
            labels.forEach((lb) => smcAppendOverlayLabel(fragment, ts, lb, viewportW));
            smcFlowOverlay.appendChild(fragment);
        } catch (e) {
            console.error('[SMC Flow] dashboard redraw', e);
        }
    }

    function redrawPriceActionOverlay() {
        redrawLiquiditySwingsOverlay();
        // SMC Flow не зависит от слоя DGT — перерисовываем всегда при sync оверлеев.
        redrawSmcFlowOverlay();
        if (!priceActionOverlay || !chart || !candlestickSeries) return;
        priceActionOverlay.innerHTML = '';
        if (!layerPa || !layerPa.checked || !lastPaPayload || !allChartData.length) return;
        const ts = chart.timeScale();
        const vpPanelMaxHistPx = 100;
        const vpPanelMaxDomPx = 26;
        const vpPanelGapPx = 2;
        /** Правый край колонки VP = левый край области подписей цен (ширина шкалы меняется при зуме и формате чисел). */
        const computeVpAnchorRight = () => {
            const cw = chartWrapper.clientWidth || 0;
            if (!Number.isFinite(cw) || cw < 40) return null;
            let scaleW = 0;
            try {
                if (candlestickSeries && typeof candlestickSeries.priceScale === 'function') {
                    scaleW = Number(candlestickSeries.priceScale().width());
                }
            } catch (_) {
                scaleW = 0;
            }
            if (!Number.isFinite(scaleW) || scaleW < 0) scaleW = 0;
            // На первом кадре width() иногда 0 — не даём VP уехать вправо за холст (старый запас ~78px).
            const effectiveScale = scaleW > 0 ? scaleW : 78;
            const borderGapPx = 1;
            const anchor = cw - effectiveScale - borderGapPx;
            return anchor > 8 ? anchor : null;
        };
        const appendVpLevelBar = (leftPx, topPx, widthPx, heightPx, background, opacity) => {
            if (!Number.isFinite(leftPx) || !Number.isFinite(topPx) || !Number.isFinite(widthPx) || !Number.isFinite(heightPx)) return;
            const div = document.createElement('div');
            div.style.position = 'absolute';
            div.style.left = leftPx + 'px';
            div.style.top = topPx + 'px';
            div.style.width = Math.max(1, widthPx) + 'px';
            div.style.height = Math.max(1, heightPx) + 'px';
            div.style.background = paCssColor(background);
            div.style.opacity = opacity;
            div.style.pointerEvents = 'none';
            priceActionOverlay.appendChild(div);
        };
        try {
            const lines = lastPaPayload.lines || [];
            lines.forEach((ln) => {
                if (!ln || typeof ln !== 'object') return;
                const x1 = ts.timeToCoordinate(Number(ln.time_start));
                const x2 = ts.timeToCoordinate(Number(ln.time_end));
                const y = candlestickSeries.priceToCoordinate(Number(ln.price));
                if (x1 == null || x2 == null || y == null || Number.isNaN(x1) || Number.isNaN(x2) || Number.isNaN(y)) return;
                const left = Math.min(x1, x2);
                const w = Math.max(1, Math.abs(x2 - x1));
                const st = (ln.style || 'Solid').toLowerCase();
                const col = paCssColor(ln.color);
                const lw = Math.max(1, Number(ln.width) || 1);
                const div = document.createElement('div');
                div.style.position = 'absolute';
                div.style.left = left + 'px';
                div.style.top = (y - lw / 2) + 'px';
                div.style.width = w + 'px';
                div.style.height = lw + 'px';
                div.style.pointerEvents = 'none';
                if (st === 'dotted' || st === 'dashed') {
                    div.style.background = 'transparent';
                    div.style.height = '0';
                    div.style.borderTop = (st === 'dotted' ? `${lw}px dotted ` : `${lw}px dashed `) + col;
                } else {
                    div.style.background = col;
                }
                priceActionOverlay.appendChild(div);
            });
            const vp = lastPaPayload.volume_profile || {};
            const boxes = Array.isArray(vp.boxes) ? vp.boxes : [];
            let boxCount = 0;
            const anchorRight = computeVpAnchorRight();
            let vpDividerY1 = null;
            let vpDividerY2 = null;
            for (let i = 0; i < boxes.length; i++) {
                if (boxCount > 400) break;
                const b = boxes[i];
                if (!b || typeof b !== 'object') continue;
                if (b.kind === 'vp_hist' && boxes[i + 1] && boxes[i + 1].kind === 'vp_dom') {
                    const domBox = boxes[i + 1];
                    const y1 = candlestickSeries.priceToCoordinate(Number(b.price_high));
                    const y2 = candlestickSeries.priceToCoordinate(Number(b.price_low));
                    if (y1 == null || y2 == null || anchorRight == null || !Number.isFinite(anchorRight)) {
                        i += 1;
                        continue;
                    }
                    const wrH = typeof b.width_ratio === 'number' ? b.width_ratio : 0.5;
                    const spanHist = Math.max(8, Math.min(vpPanelMaxHistPx, wrH * (vpPanelMaxHistPx + 8)));
                    const sr = typeof domBox.strength_ratio === 'number' ? domBox.strength_ratio : 0;
                    const spanDom = Math.max(4, Math.min(vpPanelMaxDomPx, 5 + sr * (vpPanelMaxDomPx - 2)));
                    const top = Math.min(y1, y2);
                    const h = Math.max(1, Math.abs(y2 - y1));
                    const leftDom = anchorRight - spanDom;
                    const leftHist = leftDom - vpPanelGapPx - spanHist;
                    appendVpLevelBar(leftHist, top, spanHist, h, b.background, '0.78');
                    appendVpLevelBar(leftDom, top, spanDom, h, domBox.background, '0.88');
                    if (vpDividerY1 == null) { vpDividerY1 = top; vpDividerY2 = top + h; }
                    else { vpDividerY1 = Math.min(vpDividerY1, top); vpDividerY2 = Math.max(vpDividerY2, top + h); }
                    boxCount += 2;
                    i += 1;
                    continue;
                }
                if (b.kind === 'vp_hist' || b.kind === 'vp_dom') {
                    const y1 = candlestickSeries.priceToCoordinate(Number(b.price_high));
                    const y2 = candlestickSeries.priceToCoordinate(Number(b.price_low));
                    if (y1 == null || y2 == null || anchorRight == null || !Number.isFinite(anchorRight)) continue;
                    const wr = typeof b.width_ratio === 'number' ? b.width_ratio : 0.5;
                    const spanMax = b.kind === 'vp_dom' ? vpPanelMaxDomPx : vpPanelMaxHistPx;
                    const span = Math.max(6, Math.min(spanMax, wr * (spanMax + 6)));
                    const top = Math.min(y1, y2);
                    const h = Math.max(1, Math.abs(y2 - y1));
                    appendVpLevelBar(anchorRight - span, top, span, h, b.background, b.kind === 'vp_dom' ? '0.88' : '0.78');
                    boxCount += 1;
                    continue;
                }
                const x1 = ts.timeToCoordinate(Number(b.time_start));
                const x2 = ts.timeToCoordinate(Number(b.time_end));
                const yTop = candlestickSeries.priceToCoordinate(Number(b.price_high));
                const yBot = candlestickSeries.priceToCoordinate(Number(b.price_low));
                if (x1 == null || x2 == null || yTop == null || yBot == null) continue;
                const div = document.createElement('div');
                div.style.position = 'absolute';
                div.style.boxSizing = 'border-box';
                div.style.pointerEvents = 'none';
                if (b.kind === 'vp_frame' && anchorRight != null && Number.isFinite(anchorRight)) {
                    const panelW = vpPanelMaxHistPx + vpPanelGapPx + vpPanelMaxDomPx + 4;
                    const yA = Math.min(yTop, yBot);
                    const yB = Math.max(yTop, yBot);
                    div.style.left = (anchorRight - panelW) + 'px';
                    div.style.top = yA + 'px';
                    div.style.width = Math.max(8, panelW) + 'px';
                    div.style.height = Math.max(4, Math.abs(yB - yA)) + 'px';
                    div.style.border = '1px dashed ' + (b.border || 'rgba(156,163,175,0.35)');
                    div.style.background = 'transparent';
                } else {
                    div.style.left = Math.min(x1, x2) + 'px';
                    div.style.top = Math.min(yTop, yBot) + 'px';
                    div.style.width = Math.max(1, Math.abs(x2 - x1)) + 'px';
                    div.style.height = Math.max(1, Math.abs(yBot - yTop)) + 'px';
                    if (b.kind === 'vp_frame') {
                        div.style.border = '1px dashed ' + (b.border || 'rgba(156,163,175,0.35)');
                        div.style.background = 'transparent';
                    } else {
                        div.style.background = paCssColor(b.background);
                        div.style.border = '1px solid rgba(255,255,255,0.08)';
                    }
                }
                priceActionOverlay.appendChild(div);
                boxCount++;
            }
            if (anchorRight != null && vpDividerY1 != null && vpDividerY2 != null && vpDividerY2 > vpDividerY1) {
                const splitX = anchorRight - vpPanelMaxDomPx - vpPanelGapPx * 0.5;
                const line = document.createElement('div');
                line.style.position = 'absolute';
                line.style.left = splitX + 'px';
                line.style.top = vpDividerY1 + 'px';
                line.style.width = '1px';
                line.style.height = Math.max(2, vpDividerY2 - vpDividerY1) + 'px';
                line.style.background = 'rgba(226,232,240,0.4)';
                line.style.pointerEvents = 'none';
                priceActionOverlay.appendChild(line);
            }
        } catch (e) {
            console.error('[PriceAction DGT] dashboard redraw', e);
        }
    }

    /**
     * Окраска свечей DGT. На poll НЕ делаем setData всего ряда — только update хвоста;
     * иначе каждые 500ms LightweightCharts пересобирает серию и появляются «ломаные» бары.
     * fullResync: true — при первой загрузке, смене TF и выключении слоя PA.
     */
    function applyPaBarColors(opts = {}) {
        const fullResync = opts && opts.fullResync === true;
        if (!candlestickSeries || !allChartData.length) return;
        const plainBar = (c) => ({ time: c.time, open: c.open, high: c.high, low: c.low, close: c.close });
        const plain = allChartData.map(plainBar);
        const bc = lastPaPayload && lastPaPayload.bar_colors ? lastPaPayload.bar_colors : null;
        const paOn = layerPa && layerPa.checked && bc && Object.keys(bc).length > 0;
        if (!paOn) {
            if (fullResync) {
                try { candlestickSeries.setData(plain); } catch (e) { console.error('[PA] setData plain', e); }
            }
            return;
        }
        const paintBar = (c) => {
            const o = bc[String(c.time)];
            if (!o) return plainBar(c);
            return {
                time: c.time, open: c.open, high: c.high, low: c.low, close: c.close,
                color: o.color, borderColor: o.borderColor || o.color, wickColor: o.wickColor || o.color,
            };
        };
        if (fullResync) {
            try {
                candlestickSeries.setData(allChartData.map(paintBar));
            } catch (e) {
                try { candlestickSeries.setData(plain); } catch (e2) { console.error('[PA] fallback', e2); }
            }
            return;
        }
        const barSec = timeframeSeconds(currentTimeframeMinutes);
        const tailFrom = allChartData[allChartData.length - 1].time - barSec * 2;
        for (const c of allChartData) {
            if (c.time >= tailFrom) {
                try { candlestickSeries.update(paintBar(c)); } catch (e) { console.error('[PA] update', e); }
            }
        }
    }

    function updateTfOptionStyles() {
        document.querySelectorAll('.tf-option').forEach(btn => {
            const on = parseInt(btn.dataset.minutes, 10) === currentTimeframeMinutes;
            btn.classList.toggle('bg-blue-900/50', on);
            btn.classList.toggle('font-medium', on);
        });
        const cur = document.querySelector('.tf-option[data-minutes="' + currentTimeframeMinutes + '"]');
        if (tfLabelEl && cur) tfLabelEl.textContent = cur.dataset.label || currentTimeframeMinutes + 'м';
    }

    function updateChartTitle() {
        if (!currentDisplayName) {
            chartTitle.textContent = 'Выберите инструмент';
            return;
        }
        const cur = document.querySelector('.tf-option[data-minutes="' + currentTimeframeMinutes + '"]');
        const lab = cur && cur.dataset.label ? cur.dataset.label : (currentTimeframeMinutes + 'м');
        chartTitle.textContent = currentDisplayName + ' (' + lab + ')';
    }

    function copyCurrentInstrumentName() {
        if (!currentDisplayName || !chartTitle) return;

        const textToCopy = currentDisplayName;
        const defaultTitle = 'Кликните, чтобы скопировать название инструмента';
        const showCopyStatus = (statusText) => {
            chartTitle.setAttribute('title', statusText);
            setTimeout(() => {
                chartTitle.setAttribute('title', defaultTitle);
            }, 1200);
        };

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(textToCopy)
                .then(() => showCopyStatus('Скопировано'))
                .catch(() => showCopyStatus('Не удалось скопировать'));
            return;
        }

        try {
            const textarea = document.createElement('textarea');
            textarea.value = textToCopy;
            textarea.setAttribute('readonly', '');
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.focus();
            textarea.select();
            const ok = document.execCommand('copy');
            document.body.removeChild(textarea);
            showCopyStatus(ok ? 'Скопировано' : 'Не удалось скопировать');
        } catch (_) {
            showCopyStatus('Не удалось скопировать');
        }
    }

    function fetchStructures() {
        if (!currentInstrument) return;
        const params = new URLSearchParams({
            instrument: currentInstrument,
            timeframe: String(currentTimeframeMinutes)
        });
        fetch(STRUCTURES_URL + '?' + params.toString(), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(r => r.json())
            .then(data => {
                lastZones = data.zones || [];
                requestAnimationFrame(redrawStructureOverlay);
            })
            .catch(() => {});
    }

    function startAutoRefresh() {
        if (refreshInterval) clearInterval(refreshInterval);
        // Тот же шаг, что и на странице бота: 500ms для всех TF — максимально частое обновление свечного графика через REST.
        refreshInterval = setInterval(() => {
            if (!currentInstrument || loadingMore || !candlestickSeries) return;
            // Поллингу важен только хвост: запрашиваем узкое окно последних минут.
            // Это и снимает нагрузку с БД, и предохраняет от перетирания истории,
            // догруженной пользователем влево через loadMoreCandles.
            fetchCandles(currentInstrument, { kind: 'poll' })
                .then(data => {
                    const candles = data.candles || [];
                    if (candles.length === 0) return;
                    const newData = buildChartData(candles);
                    const newLastT = newData[newData.length - 1].time;
                    const prevLastT = allChartData.length ? allChartData[allChartData.length - 1].time : null;
                    // Снимаем диапазон сразу перед setData/update, чтобы не ловить рассинхрон после await fetch.
                    const followTail = isViewNearRightEdge();
                    const rangeBefore = followTail && chart ? chart.timeScale().getVisibleLogicalRange() : null;
                    applyIncrementalChartUpdate(newData);
                    tickCandleCloseTimer();
                    // Вместо scrollToRealTime(): он пересобирает отступ справа по правилам библиотеки и «прыгает».
                    // Сдвигаем тот же логический viewport на число новых баров — картинка для «режима следования» остаётся плавной.
                    if (followTail && rangeBefore && chart && prevLastT !== null && newLastT > prevLastT) {
                        const addedBars = newData.filter(c => c.time > prevLastT).length;
                        if (addedBars > 0 && Number.isFinite(rangeBefore.from) && Number.isFinite(rangeBefore.to)) {
                            chart.timeScale().setVisibleLogicalRange({
                                from: rangeBefore.from + addedBars,
                                to: rangeBefore.to + addedBars,
                            });
                        }
                    }
                    // Перерисовываем DOM-оверлей структуры на основании уже имеющихся
                    // в памяти зон (lastZones). Сами зоны при этом НЕ перезапрашиваем —
                    // это делает отдельный медленный таймер ниже. Раньше этот блок
                    // на каждом тике посылал тяжёлые fetchStructures() и
                    // fetchPriceActionIndicator() (~46 KB) каждые 500мс, переполнял
                    // браузерный fetch-pool и блокировал клик-инициированные
                    // initial-запросы /candles при выборе инструмента.
                    requestAnimationFrame(redrawStructureOverlay);
                })
                .catch(console.error);
        }, 500);

        startOverlaysRefresh();
    }

    /**
     * Раз в OVERLAYS_REFRESH_MS подтягиваем тяжёлые оверлеи: зоны структуры и
     * Price Action DGT. Дополнительно один раз сразу запускаем (ниже),
     * чтобы при выборе инструмента оверлеи появились без 15-секундной задержки.
     */
    function startOverlaysRefresh() {
        if (overlaysRefreshInterval) clearInterval(overlaysRefreshInterval);
        overlaysRefreshInterval = setInterval(() => {
            if (!currentInstrument || loadingMore || !candlestickSeries) return;
            // Структуры обновляем всегда (легче по объёму ответа), DGT — только если слой включён.
            fetchStructures();
            if (layerPa && layerPa.checked) fetchPriceActionIndicator();
            if (layerLiquidity && layerLiquidity.checked) fetchLiquiditySwingsIndicator();
            if (layerSmc && layerSmc.checked) fetchSmcFlowIndicator();
            // Маркеры сделок: лёгкий JSON, обновляем вместе с оверлеями (не в 500мс poll свечей).
            fetchChartTrades();
            // Классика OsEngine — только если чекбоксы включены.
            if (anyClassicLayerOn()) fetchClassicIndicators();
        }, OVERLAYS_REFRESH_MS);
    }

    function stopOverlaysRefresh() {
        if (overlaysRefreshInterval) {
            clearInterval(overlaysRefreshInterval);
            overlaysRefreshInterval = null;
        }
    }

    function setLoading(show) {
        chartLoading.classList.toggle('hidden', !show);
    }

    /**
     * Загрузка свечей для графика с учётом режима запроса.
     *
     * Раньше каждый клик по инструменту тянул сразу 24 часа M1 (limit=1440, since=now-86400),
     * из-за чего сервер на каждый запрос сканировал из БД до 15000 S5-строк. Теперь окно
     * выбирается под конкретный сценарий:
     *   - 'initial' (выбор инструмента / смена TF): малое окно для мгновенного отклика;
     *   - 'more'    (ленивая догрузка влево при скролле): расширяется до MAX_WINDOW_SEC;
     *   - 'poll'    (auto-refresh каждые 500мс): узкое окно — только последние минуты,
     *               чтобы поллинг не перезатирал уже догруженную историю.
     *
     * Параметры:
     *   instrument — символ инструмента;
     *   opts.before — нижняя граница диапазона (для догрузки влево);
     *   opts.kind   — режим запроса: initial | more | poll (см. выше).
     */
    function fetchCandles(instrument, opts = {}) {
        const before = opts.before != null ? opts.before : null;
        // По умолчанию ведём себя как первичная загрузка — это безопасный fallback
        // для случаев, когда вызывающий код не указал режим явно.
        const kind = opts.kind || 'initial';

        let since;
        let limit;
        if (kind === 'more') {
            // Догрузка влево опирается на «потолок» истории (24ч), чтобы пользователь
            // мог пролистать на максимально возможную глубину.
            since = Math.floor(Date.now() / 1000) - MAX_WINDOW_SEC;
            limit = LOAD_MORE_LIMIT;
        } else if (kind === 'poll') {
            // Авто-обновление: достаточно ровно «хвоста» последних минут.
            // Минимизирует чтение из БД и payload.
            since = Math.floor(Date.now() / 1000) - POLL_WINDOW_SEC;
            limit = POLL_LIMIT;
        } else {
            // Первичный показ: минутные TF — 2ч; секундные — окно под viewport (~130 баров).
            since = Math.floor(Date.now() / 1000) - initialWindowSecForTf(currentTimeframeMinutes);
            limit = initialLimitForTf(currentTimeframeMinutes);
        }

        const params = new URLSearchParams({
            instrument,
            timeframe: String(currentTimeframeMinutes),
            limit: String(limit),
            since: String(since),
        });
        if (before != null) params.set('before', before);
        // Live partial для всех TF (как на странице бота): S5/S15 обновляются каждые 500ms, не раз в 5с.
        if (before == null) {
            params.set('include_partial', '1');
        }
        return fetch(CANDLES_URL + '?' + params.toString(), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then((r) => r.json())
            .then((payload) => {
                applyM1CountdownFromPayload(payload);
                if (currentTimeframeMinutes !== 1) {
                    applyGenericCountdownFromPayload(payload, currentTimeframeMinutes);
                } else {
                    tfCloseCountdownAnchorSec = null;
                    tfCloseCountdownAnchorPerfMs = null;
                }
                return payload;
            });
    }

    function buildChartData(candles) {
        return candles.map(c => ({
            time: c.time,
            open: c.open,
            high: c.high,
            low: c.low,
            close: c.close
        })).sort((a, b) => a.time - b.time);
    }

    /**
     * Подмешивает свечи из poll/initial в allChartData, не затирая историю слева.
     * Poll (POLL_WINDOW_SEC ≈ 5 мин) раньше при совпадении первого бара с oldest делал
     * allChartData = newData и через applyPaBarColors() setData() — график «схлопывался»
     * и ломался примерно через 5–10 минут простоя на одном инструменте.
     */
    function applyIncrementalChartUpdate(newData) {
        if (!candlestickSeries || !Array.isArray(newData) || newData.length === 0) return;

        if (allChartData.length === 0) {
            allChartData = newData;
            oldestLoadedTime = Math.min(...allChartData.map(c => c.time));
            candlestickSeries.setData(allChartData);
            tickCandleCloseTimer();
            applyPaBarColors({ fullResync: true });
            requestAnimationFrame(redrawPriceActionOverlay);
            return;
        }

        const prevOldest = allChartData[0].time;
        const prevLast = allChartData[allChartData.length - 1].time;

        const byTime = new Map();
        for (const c of allChartData) byTime.set(c.time, c);
        for (const c of newData) byTime.set(c.time, c);
        const merged = Array.from(byTime.values()).sort((a, b) => a.time - b.time);

        const prependedOlder = merged.length > 0 && merged[0].time < prevOldest;
        // LightweightCharts.update() допускает только последний бар или новый бар после него.
        // Нельзя update() «середину» ряда — иначе Cannot update oldest data и артефакты на графике.
        let updateFailed = false;
        for (const c of newData) {
            if (c.time >= prevLast) {
                try {
                    candlestickSeries.update(c);
                } catch (e) {
                    updateFailed = true;
                    console.error('[chart] candle update', e);
                }
            }
        }

        allChartData = merged;
        oldestLoadedTime = oldestLoadedTime == null
            ? merged[0].time
            : Math.min(oldestLoadedTime, merged[0].time);

        if (prependedOlder || updateFailed) {
            candlestickSeries.setData(allChartData);
            applyPaBarColors({ fullResync: true });
        } else {
            applyPaBarColors();
        }

        tickCandleCloseTimer();
        requestAnimationFrame(redrawPriceActionOverlay);
    }

    /**
     * Синхронизация HTML-оверлеев с canvas при любом изменении viewport.
     *
     * Проблема: subscribeVisibleLogicalRangeChange срабатывает только когда меняется горизонтальный
     * логический диапазон. Зум/сдвиг только по цене (правая шкала, колёсико на области цены)
     * не вызывает этот колбэк, а timeToCoordinate/priceToCoordinate при этом меняются — оверлей
     * остаётся со старыми пикселями и «отлипает» от свечей.
     *
     * Решение: attachPrimitive с пустым paneView. У примитива updateAllViews() вызывается
     * библиотекой при любом пересчёте вида (время + цена + размеры). Здесь только планируем
     * перерисовку DOM; на canvas ничего не рисуем.
     *
     * rAF: за кадр сливаем много вызовов updateAllViews в одну перерисовку оверлеев.
     */
    let chartDomOverlaySyncRaf = null;
    function scheduleChartDomOverlaySync() {
        if (chartDomOverlaySyncRaf !== null) return;
        chartDomOverlaySyncRaf = requestAnimationFrame(() => {
            chartDomOverlaySyncRaf = null;
            try {
                redrawStructureOverlay();
            } catch (e) {
                console.error('[chart] dom overlay sync', e);
            }
            requestAnimationFrame(() => {
                try {
                    positionCandleTimerOverlay();
                } catch (e2) {
                    console.error('[chart] candle timer overlay sync', e2);
                }
            });
        });
    }
    function createChartDomOverlaySyncPrimitive() {
        const paneView = {
            renderer() {
                return {
                    draw() {
                        /* Нам нужен только updateAllViews; draw оставляем пустым. */
                    },
                };
            },
        };
        const paneViewsStable = [paneView];
        return {
            updateAllViews() {
                scheduleChartDomOverlaySync();
            },
            paneViews() {
                return paneViewsStable;
            },
        };
    }

    function createChart() {
        if (chart) chart.remove();
        chartWrapper.innerHTML = '';
        chartWrapper.classList.remove('hidden');
        structureOverlay.classList.remove('hidden');
        if (liquiditySwingsOverlay) liquiditySwingsOverlay.classList.remove('hidden');
        if (priceActionOverlay) priceActionOverlay.classList.remove('hidden');
        if (smcFlowOverlay) smcFlowOverlay.classList.remove('hidden');
        chartPlaceholder.classList.add('hidden');

        chart = LightweightCharts.createChart(chartWrapper, {
            layout: {
                background: { type: 'solid', color: '#191c30' },
                textColor: '#a1a1aa',
                // Скрываем логотип TradingView в углу графика (licensing: attributionLogo).
                attributionLogo: false,
            },
            grid: { vertLines: { color: '#27272a' }, horzLines: { color: '#27272a' } },
            rightPriceScale: { borderColor: '#3f3f46', scaleMargins: { top: 0.1, bottom: 0.2 } },
            timeScale: {
                borderColor: '#3f3f46',
                timeVisible: true,
                secondsVisible: false
            },
            crosshair: { mode: 1 },
            width: chartWrapper.clientWidth,
            height: chartWrapper.clientHeight
        });

        candlestickSeries = chart.addSeries(LightweightCharts.CandlestickSeries, {
            upColor: '#26a69a',
            downColor: '#ef5350',
            borderVisible: false,
            wickUpColor: '#26a69a',
            wickDownColor: '#ef5350',
            priceFormat: {
                type: 'price',
                precision: 5,
                minMove: 0.00001
            }
        });
        // Инициализируем markers-plugin единообразно с bots/show.
        candlestickMarkers = null;
        try {
            if (window.PlatformChartMarkers && typeof window.PlatformChartMarkers.createPluginForSeries === 'function') {
                candlestickMarkers = window.PlatformChartMarkers.createPluginForSeries(candlestickSeries, []);
            } else if (typeof LightweightCharts.createSeriesMarkers === 'function') {
                candlestickMarkers = LightweightCharts.createSeriesMarkers(candlestickSeries, []);
            }
        } catch (e) {
            console.error('[dashboard chart] createSeriesMarkers', e);
        }
        classicLineSeries = {};
        rsiLevelLines = [];
        classicOscPaneCount = 0;

        try {
            candlestickSeries.attachPrimitive(createChartDomOverlaySyncPrimitive());
        } catch (e) {
            console.error('[chart] attachPrimitive(dom overlay sync)', e);
        }

        chart.timeScale().subscribeVisibleLogicalRangeChange(() => {
            const range = chart.timeScale().getVisibleLogicalRange();
            if (range && currentTimeframeMinutes === 1 && !loadingMore && oldestLoadedTime != null && range.from < 2) {
                loadMoreCandles();
            }
            redrawStructureOverlay();
            requestAnimationFrame(redrawPriceActionOverlay);
            requestAnimationFrame(positionCandleTimerOverlay);
        });
    }

    function loadMoreCandles() {
        if (currentTimeframeMinutes !== 1) return;
        if (!currentInstrument || loadingMore || oldestLoadedTime == null) return;
        const sinceT = candlesSince();
        if (oldestLoadedTime <= sinceT) return;

        loadingMore = true;
        setLoading(true);
        // Догрузка более старой истории: расширяем окно до MAX_WINDOW_SEC и используем
        // увеличенный лимит баров, чтобы за один запрос покрыть пролистывание влево.
        fetchCandles(currentInstrument, { before: oldestLoadedTime, kind: 'more' })
            .then(data => {
                const candles = data.candles || [];
                if (candles.length === 0) { loadingMore = false; setLoading(false); return; }
                const newBars = buildChartData(candles);
                const existingTimes = new Set(allChartData.map(c => c.time));
                const toAdd = newBars.filter(c => !existingTimes.has(c.time));
                if (toAdd.length === 0) { loadingMore = false; setLoading(false); return; }
                allChartData = [...toAdd, ...allChartData].sort((a, b) => a.time - b.time);
                oldestLoadedTime = Math.min(...allChartData.map(c => c.time));
                candlestickSeries.setData(allChartData);
                loadingMore = false;
                setLoading(false);
                tickCandleCloseTimer();
                applyPaBarColors({ fullResync: true });
                requestAnimationFrame(redrawStructureOverlay);
                fetchStructures();
                if (layerPa && layerPa.checked) fetchPriceActionIndicator();
                if (layerLiquidity && layerLiquidity.checked) fetchLiquiditySwingsIndicator();
                if (anyClassicLayerOn()) fetchClassicIndicators();
            })
            .catch(() => { loadingMore = false; setLoading(false); });
    }

    function reloadChartData() {
        if (!currentInstrument || !candlestickSeries) return;
        setLoading(true);
        // Смена таймфрейма / явный reload — это «как заново выбрали инструмент»:
        // тянем только initial-окно, остальное пользователь догрузит скроллом.
        fetchCandles(currentInstrument, { kind: 'initial' })
            .then(data => {
                const candles = data.candles || [];
                allChartData = buildChartData(candles);
                if (allChartData.length) {
                    oldestLoadedTime = Math.min(...allChartData.map(c => c.time));
                    candlestickSeries.setData(allChartData);
                    applyDefaultChartViewport();
                    fetchStructures();
                    fetchPriceActionIndicator();
                    fetchLiquiditySwingsIndicator();
                    fetchSmcFlowIndicator();
                    fetchChartTrades();
                    fetchClassicIndicators();
                } else {
                    candlestickSeries.setData([]);
                    lastZones = [];
                    structureOverlay.innerHTML = '';
                    lastPaPayload = null;
                    lastLsPayload = null;
                    lastSmcPayload = null;
                    lastChartSignals = [];
                    lastOpenTrades = [];
                    lastBollingerPayload = null;
                    lastRsiPayload = null;
                    lastSmaPayload = null;
                    lastEmaPayload = null;
                    lastVwmaPayload = null;
                    lastAtrPayload = null;
                    lastMacdPayload = null;
                    lastStochasticPayload = null;
                    lastCciPayload = null;
                    lastKeltnerPayload = null;
                    lastEnvelopsPayload = null;
                    lastParabolicSarPayload = null;
                    lastSuperTrendPayload = null;
                    lastAdxPayload = null;
                    lastWilliamsPayload = null;
                    lastAoPayload = null;
                    lastAlligatorPayload = null;
                    lastIchimokuPayload = null;
                    lastPriceChannelPayload = null;
                    lastNrtrPayload = null;
                    lastHmaPayload = null;
                    lastAroonPayload = null;
                    if (priceActionOverlay) priceActionOverlay.innerHTML = '';
                    if (liquiditySwingsOverlay) liquiditySwingsOverlay.innerHTML = '';
                    if (smcFlowOverlay) smcFlowOverlay.innerHTML = '';
                    renderChartMarkers();
                    applyClassicOsEngineSeries();
                }
                updateChartTitle();
                tickCandleCloseTimer();
                requestAnimationFrame(redrawStructureOverlay);
                setLoading(false);
            })
            .catch(() => {
                setLoading(false);
                tickCandleCloseTimer();
            });
    }

    function selectInstrument(instrument, displayName) {
        if (currentInstrument === instrument) return;
        currentInstrument = instrument;
        currentDisplayName = displayName;
        document.querySelectorAll('.instrument-btn').forEach((b) => b.classList.toggle('bg-blue-900/40', b.dataset.instrument === instrument));
        lastZones = [];
        structureOverlay.innerHTML = '';
        lastPaPayload = null;
        lastLsPayload = null;
        lastSmcPayload = null;
        lastChartSignals = [];
        lastOpenTrades = [];
        lastBollingerPayload = null;
        lastRsiPayload = null;
        lastSmaPayload = null;
        lastEmaPayload = null;
        lastVwmaPayload = null;
        lastAtrPayload = null;
        lastMacdPayload = null;
        lastStochasticPayload = null;
        lastCciPayload = null;
        lastKeltnerPayload = null;
        lastEnvelopsPayload = null;
        lastParabolicSarPayload = null;
        lastSuperTrendPayload = null;
        lastAdxPayload = null;
        lastWilliamsPayload = null;
        lastAoPayload = null;
        lastAlligatorPayload = null;
        lastIchimokuPayload = null;
        lastPriceChannelPayload = null;
        lastNrtrPayload = null;
        lastHmaPayload = null;
        lastAroonPayload = null;
        if (priceActionOverlay) priceActionOverlay.innerHTML = '';
        if (liquiditySwingsOverlay) liquiditySwingsOverlay.innerHTML = '';
        if (smcFlowOverlay) smcFlowOverlay.innerHTML = '';
        updateChartTitle();
        updateTfOptionStyles();
        tickCandleCloseTimer();
        setLoading(true);
        createChart();

        // Первичная загрузка свечей при выборе инструмента: только initial-окно,
        // чтобы переключение между парами происходило быстро. Остальное
        // (старая история) подтянется по требованию через loadMoreCandles.
        fetchCandles(instrument, { kind: 'initial' })
            .then(data => {
                const candles = data.candles || [];
                allChartData = buildChartData(candles);
                if (allChartData.length) {
                    oldestLoadedTime = Math.min(...allChartData.map(c => c.time));
                    candlestickSeries.setData(allChartData);
                    applyDefaultChartViewport();
                    // startAutoRefresh внутри уже стартует и медленный таймер оверлеев.
                    startAutoRefresh();
                    requestAnimationFrame(() => {
                        redrawStructureOverlay();
                        // Один раз дёргаем «тяжёлые» эндпоинты сразу при выборе инструмента,
                        // чтобы оверлеи появились без 15-секундной задержки таймера.
                        fetchStructures();
                        fetchPriceActionIndicator();
                        fetchLiquiditySwingsIndicator();
                        fetchSmcFlowIndicator();
                        fetchChartTrades();
                        fetchClassicIndicators();
                    });
                } else {
                    if (refreshInterval) clearInterval(refreshInterval);
                    stopOverlaysRefresh();
                    renderChartMarkers();
                    applyClassicOsEngineSeries();
                }
                updateChartTitle();
                tickCandleCloseTimer();
                setLoading(false);
            })
            .catch(() => {
                setLoading(false);
                if (refreshInterval) clearInterval(refreshInterval);
                stopOverlaysRefresh();
                tickCandleCloseTimer();
            });
    }

    tfBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        if (window.PlatformIndicatorsPopup) window.PlatformIndicatorsPopup.close();
        else layersPanel.classList.add('hidden');
        tfPanel.classList.toggle('hidden');
    });
    tfPanel.addEventListener('click', (e) => e.stopPropagation());
    document.querySelectorAll('.tf-option').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const m = parseInt(btn.dataset.minutes, 10);
            if (!Number.isFinite(m) || m === currentTimeframeMinutes) {
                tfPanel.classList.add('hidden');
                return;
            }
            currentTimeframeMinutes = m;
            updateTfOptionStyles();
            tfPanel.classList.add('hidden');
            if (currentInstrument && candlestickSeries) {
                reloadChartData();
            }
        });
    });

    // Попап «Индикаторы» открывается в chart-indicators-popup-script (не дублируем click).
    document.addEventListener('click', () => {
        tfPanel.classList.add('hidden');
    });

    layerOb.addEventListener('change', () => {
        saveLayerPrefs();
        requestAnimationFrame(redrawStructureOverlay);
    });
    layerFvg.addEventListener('change', () => {
        saveLayerPrefs();
        requestAnimationFrame(redrawStructureOverlay);
    });
    if (layerPa) {
        layerPa.addEventListener('change', () => {
            saveLayerPrefs();
            if (!layerPa.checked) lastPaPayload = null;
            fetchPriceActionIndicator();
            applyPaBarColors({ fullResync: true });
            renderChartMarkers();
            requestAnimationFrame(redrawStructureOverlay);
        });
    }
    if (layerLiquidity) {
        layerLiquidity.addEventListener('change', () => {
            saveLayerPrefs();
            if (!layerLiquidity.checked) lastLsPayload = null;
            fetchLiquiditySwingsIndicator();
            requestAnimationFrame(redrawStructureOverlay);
        });
    }
    if (layerSmc) {
        layerSmc.addEventListener('change', () => {
            saveLayerPrefs();
            if (!layerSmc.checked) lastSmcPayload = null;
            fetchSmcFlowIndicator();
            requestAnimationFrame(redrawPriceActionOverlay);
        });
    }
    // Classic OsEngine: пересчитываем только при смене чекбокса (как на bots/show).
    [
        [layerBollinger, () => { lastBollingerPayload = null; }],
        [layerRsi, () => { lastRsiPayload = null; }],
        [layerSma, () => { lastSmaPayload = null; }],
        [layerEma, () => { lastEmaPayload = null; }],
        [layerVwma, () => { lastVwmaPayload = null; }],
        [layerAtr, () => { lastAtrPayload = null; }],
        [layerMacd, () => { lastMacdPayload = null; }],
        [layerStochastic, () => { lastStochasticPayload = null; }],
        [layerCci, () => { lastCciPayload = null; }],
        [layerKeltner, () => { lastKeltnerPayload = null; }],
        [layerEnvelops, () => { lastEnvelopsPayload = null; }],
        [layerParabolicSar, () => { lastParabolicSarPayload = null; }],
        [layerSuperTrend, () => { lastSuperTrendPayload = null; }],
        [layerAdx, () => { lastAdxPayload = null; }],
        [layerWilliams, () => { lastWilliamsPayload = null; }],
        [layerAo, () => { lastAoPayload = null; }],
        [layerAlligator, () => { lastAlligatorPayload = null; }],
        [layerIchimoku, () => { lastIchimokuPayload = null; }],
        [layerPriceChannel, () => { lastPriceChannelPayload = null; }],
        [layerNrtr, () => { lastNrtrPayload = null; }],
        [layerHma, () => { lastHmaPayload = null; }],
        [layerAroon, () => { lastAroonPayload = null; }],
        [layerMomentum, () => { lastMomentumPayload = null; }],
        [layerRoc, () => { lastRocPayload = null; }],
        [layerOsma, () => { lastOsmaPayload = null; }],
        [layerTrix, () => { lastTrixPayload = null; }],
        [layerCmo, () => { lastCmoPayload = null; }],
        [layerDemarker, () => { lastDemarkerPayload = null; }],
        [layerRvi, () => { lastRviPayload = null; }],
        [layerUltimate, () => { lastUltimatePayload = null; }],
        [layerSmi, () => { lastSmiPayload = null; }],
        [layerStdDev, () => { lastStdDevPayload = null; }],
        [layerSmaChannel, () => { lastSmaChannelPayload = null; }],
        [layerIvashov, () => { lastIvashovPayload = null; }],
        [layerLinReg, () => { lastLinRegPayload = null; }],
        [layerLinRegChannel, () => { lastLinRegChannelPayload = null; }],
        [layerKalman, () => { lastKalmanPayload = null; }],
        [layerFractal, () => { lastFractalPayload = null; }],
    ].forEach(([el, clearFn]) => {
        if (!el) return;
        el.addEventListener('change', () => {
            saveLayerPrefs();
            clearFn();
            fetchClassicIndicators();
        });
    });

    new ResizeObserver(() => {
        if (!chart || !chartWrapper.clientWidth) return;
        chart.resize(chartWrapper.clientWidth, chartWrapper.clientHeight);
        requestAnimationFrame(() => {
            redrawStructureOverlay();
            redrawPriceActionOverlay();
            positionCandleTimerOverlay();
        });
    }).observe(chartWrapper);

    if (instrumentListEl) {
        instrumentListEl.addEventListener('click', (e) => {
            const btn = e.target.closest('.instrument-btn');
            if (!btn) return;
            selectInstrument(btn.dataset.instrument, btn.dataset.displayName);
        });
    }

    if (chartTitle) {
        chartTitle.addEventListener('click', copyCurrentInstrumentName);
    }

    if (instrumentSearchInput) {
        instrumentSearchInput.addEventListener('input', () => {
            applyInstrumentFilter(instrumentSearchInput.value);
            syncSearchClearButton();
        });

        instrumentSearchInput.addEventListener('change', () => {
            const exact = findInstrumentBtnByExactName(instrumentSearchInput.value);
            if (exact) {
                exact.click();
                return;
            }
            const first = applyInstrumentFilter(instrumentSearchInput.value);
            if (first) first.click();
        });

        instrumentSearchInput.addEventListener('keydown', (e) => {
            if (e.key !== 'Enter') return;
            e.preventDefault();
            const exact = findInstrumentBtnByExactName(instrumentSearchInput.value);
            if (exact) {
                exact.click();
                return;
            }
            const first = applyInstrumentFilter(instrumentSearchInput.value);
            if (first) first.click();
        });
    }

    if (instrumentSearchClearBtn && instrumentSearchInput) {
        instrumentSearchClearBtn.addEventListener('click', () => {
            instrumentSearchInput.value = '';
            applyInstrumentFilter('');
            syncSearchClearButton();
            instrumentSearchInput.focus();
        });
    }

    updateTfOptionStyles();
    syncSearchClearButton();

    instrumentListRefreshInterval = setInterval(refreshInstrumentSidebar, 1000);
    sidebarTimerInterval = setInterval(() => {
        tickSidebarTradeTimers();
        tickCandleCloseTimer();
    }, 1000);
    tickSidebarTradeTimers();
    refreshInstrumentSidebar().then(() => {
        tickCandleCloseTimer();
        const first = document.querySelector('#instrument-list .instrument-btn');
        if (first) first.click();
    });
})();
</script>
@include('partials.chart-indicators-popup-script')
@endsection
