@extends('layouts.app')
@section('title', $bot->name)
@section('main_class', 'w-full max-w-none flex-1 min-h-0 p-0 m-0 overflow-hidden flex flex-col')
@section('content')
<style>
    #instrument-list {
        scrollbar-gutter: stable;
        scrollbar-width: thin;
        scrollbar-color: #52525b #27272a;
    }
    #instrument-list::-webkit-scrollbar { width: 8px; }
    #instrument-list::-webkit-scrollbar-track { background: #27272a; }
    #instrument-list::-webkit-scrollbar-thumb { background: #52525b; border-radius: 4px; }
</style>
<span class="sr-only pointer-events-none bg-blue-900/40 bg-blue-900/50" aria-hidden="true"></span>
<div class="flex min-h-0 flex-1 w-full gap-0 overflow-hidden">
    <aside class="w-56 shrink-0 bg-zinc-900 border-r border-zinc-800 overflow-hidden flex flex-col min-h-0">
        <div class="shrink-0 p-3 border-b border-zinc-800 bg-zinc-900/80">
            <div class="relative">
                <label for="instrument-search" class="sr-only">Поиск инструмента</label>
                <input id="instrument-search" type="text" placeholder="Поиск инструмента..." autocomplete="off"
                    class="w-full rounded border border-zinc-700 bg-zinc-800 px-3 py-2 pr-8 text-sm text-zinc-100 placeholder-zinc-500 focus:outline-none focus:ring-2 focus:ring-blue-500">
                <button id="instrument-search-clear" type="button" aria-label="Очистить поиск"
                    class="hidden absolute right-2 top-1/2 -translate-y-1/2 text-zinc-400 hover:text-zinc-200 focus:outline-none">✕</button>
            </div>
        </div>
        <ul id="instrument-list" class="min-h-0 flex-1 overflow-y-auto overscroll-y-contain divide-y divide-zinc-800">
            @forelse($instruments as $inst)
                @php($isOpenTradeInstrument = $openTradeInstruments->contains($inst->instrument))
                <li>
                    <button type="button"
                        class="instrument-btn w-full text-left px-4 py-3 hover:bg-zinc-800 focus:bg-zinc-800 focus:outline-none transition-colors {{ $isOpenTradeInstrument ? 'bg-amber-950/30' : '' }}"
                        data-instrument="{{ $inst->instrument }}"
                        data-display-name="{{ e($inst->display_name ?? $inst->instrument) }}"
                        data-search="{{ mb_strtolower(($inst->display_name ?? $inst->instrument).' '.$inst->instrument) }}"
                        data-open-trade="{{ $isOpenTradeInstrument ? '1' : '0' }}">
                        <div class="flex items-center justify-between gap-2">
                            <div class="flex min-w-0 flex-1 items-center gap-1.5">
                                <span class="font-medium text-zinc-100 truncate">{{ $inst->display_name ?? $inst->instrument }}</span>
                                @if($isOpenTradeInstrument && isset($nearestExpiryByInstrument[$inst->instrument]))
                                    @php($expTs = (int) $nearestExpiryByInstrument[$inst->instrument])
                                    @php($secLeft = max(0, $expTs - now()->timestamp))
                                    <span class="instrument-trade-timer shrink-0 text-xs font-mono tabular-nums text-amber-400/90" data-expiry-ts="{{ $expTs }}">{{ str_pad((string) intdiv($secLeft, 60), 2, '0', STR_PAD_LEFT) }}:{{ str_pad((string) ($secLeft % 60), 2, '0', STR_PAD_LEFT) }}</span>
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

    <div class="flex-1 flex flex-col bg-zinc-950 overflow-hidden min-w-0 min-h-0">
        <div class="p-3 border-b border-zinc-800 bg-zinc-900/80 flex items-center justify-between gap-2">
            <div class="flex items-center gap-3 min-w-0 flex-wrap">
                <h2 id="chart-title" class="font-semibold text-zinc-100 truncate">{{ $bot->name }}</h2>
                <div class="relative shrink-0" id="tf-dropdown-wrap">
                    <button type="button" id="tf-dropdown-btn" class="text-sm px-2 py-1 rounded border border-zinc-600 bg-zinc-800 text-zinc-200 hover:bg-zinc-700">
                        <span id="tf-dropdown-label">1м</span> ▾
                    </button>
                    <div id="tf-dropdown-panel" class="hidden absolute left-0 top-full mt-1 z-20 bg-zinc-900 border border-zinc-700 rounded shadow-lg shadow-black/40 py-1 min-w-[5.5rem]">
                        @foreach($timeframes as $tf)
                            <button type="button" class="tf-option w-full text-left text-sm px-3 py-1.5 text-zinc-200 hover:bg-zinc-800"
                                data-minutes="{{ $tf['minutes'] }}" data-label="{{ $tf['label'] }}">{{ $tf['label'] }}</button>
                        @endforeach
                    </div>
                </div>
                <div class="relative shrink-0" id="layers-dropdown-wrap">
                    <button type="button" id="layers-dropdown-btn" class="text-sm px-2 py-1 rounded border border-zinc-600 bg-zinc-800 text-zinc-200 hover:bg-zinc-700">Индикаторы ▾</button>
                    <div id="layers-dropdown-panel" class="hidden absolute left-0 top-full mt-1 z-20 bg-zinc-900 border border-zinc-700 rounded shadow-lg shadow-black/40 py-2 px-3 min-w-[11rem] space-y-2">
                        <label class="flex items-center gap-2 text-sm text-zinc-300 cursor-pointer whitespace-nowrap">
                            <input type="checkbox" id="layer-ob" class="rounded border-zinc-600 bg-zinc-800 text-blue-500"> Ордерблоки
                        </label>
                        <label class="flex items-center gap-2 text-sm text-zinc-300 cursor-pointer whitespace-nowrap">
                            <input type="checkbox" id="layer-fvg" class="rounded border-zinc-600 bg-zinc-800 text-blue-500"> Имбалансы
                        </label>
                    </div>
                </div>
                <a href="{{ route('bots.activity', $bot) }}" class="text-sm text-blue-400 hover:text-blue-300">Сигналы и сделки</a>
            </div>
            <div class="flex items-center gap-3 shrink-0">
                <span id="bot-name-badge" class="text-2xl font-semibold text-emerald-400">{{ $bot->name }}</span>
                <span id="chart-loading" class="text-sm text-zinc-500 hidden">Загрузка…</span>
            </div>
        </div>
        <div id="chart-container" class="flex-1 min-h-0 relative">
            <div id="chart-placeholder" class="absolute inset-0 flex items-center justify-center text-zinc-500 bg-zinc-950/90 z-0">
                Нажмите на инструмент слева, чтобы показать минутный график
            </div>
            <div id="chart-wrapper" class="absolute inset-0 hidden z-[1]"></div>
            <div id="structure-overlay" class="absolute inset-0 z-[5] pointer-events-none overflow-visible hidden"></div>
            <div id="candle-timer-overlay"
                class="pointer-events-none absolute z-[6] hidden h-0 w-0 overflow-visible"
                title="До закрытия текущей минутной свечи (мм:сс)">
                <span id="candle-tf-label"
                    class="absolute left-1/2 z-[6] -translate-x-1/2 whitespace-nowrap text-[11px] font-medium leading-none text-zinc-400">M1</span>
                <span id="candle-close-timer"
                    class="absolute left-1/2 z-[6] -translate-x-1/2 whitespace-nowrap font-mono text-[11px] tabular-nums leading-none text-zinc-400"
                    aria-live="polite"></span>
            </div>
            <div id="open-trades-overlay" class="absolute left-2 top-2 z-[8] pointer-events-none flex flex-col gap-1"></div>
        </div>
    </div>

    <aside class="w-52 shrink-0 border-l border-zinc-800 bg-zinc-950/40 overflow-hidden min-h-0 flex flex-col">
        <div class="px-3 py-2.5 bg-zinc-900 text-xs font-medium text-zinc-400 uppercase tracking-wide">Статистика</div>
        <div class="p-3 flex flex-col gap-2 overflow-y-auto min-h-0">
            <div class="rounded border border-zinc-800 bg-zinc-900/50 p-3"><div class="text-xs text-zinc-400">Всего</div><div class="text-lg tabular-nums">{{ $stats['total'] }}</div></div>
            <div class="rounded border border-zinc-800 bg-zinc-900/50 p-3"><div class="text-xs text-zinc-400">Wins</div><div class="text-lg tabular-nums text-emerald-400">{{ $stats['wins'] }}</div></div>
            <div class="rounded border border-zinc-800 bg-zinc-900/50 p-3"><div class="text-xs text-zinc-400">Losses</div><div class="text-lg tabular-nums text-rose-400">{{ $stats['losses'] }}</div></div>
            <div class="rounded border border-zinc-800 bg-zinc-900/50 p-3"><div class="text-xs text-zinc-400">Открыто</div><div class="text-lg tabular-nums">{{ $stats['open'] }}</div></div>
            <div class="rounded border border-zinc-800 bg-zinc-900/50 p-3"><div class="text-xs text-zinc-400">Winrate</div><div class="text-lg tabular-nums">{{ $stats['winrate'] !== null ? $stats['winrate'].'%' : '—' }}</div></div>
            <a href="{{ route('bots.index') }}" class="text-sm text-blue-400 hover:text-blue-300 pt-1">← К списку ботов</a>
        </div>
    </aside>
</div>

<script src="https://unpkg.com/lightweight-charts@4.1.0/dist/lightweight-charts.standalone.production.js"></script>
<script>
(function() {
    const CANDLES_URL = '{{ route("candles.data") }}';
    const STRUCTURES_URL = '{{ route("structures.data") }}';
    const INSTRUMENTS_SIDEBAR_URL = '{{ route("bots.instruments.sidebar-data", $bot) }}';
    const OPEN_TRADES_URL = '{{ route("bots.open-trades.data", $bot) }}';
    const LIMIT = 1440;
    const WINDOW_SEC = 86400;
    const M1_COUNTDOWN_SECONDS = 60;
    let m1CloseCountdownAnchorSec = null;
    let m1CloseCountdownAnchorPerfMs = null;

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
        if (m1CloseCountdownAnchorSec === null || m1CloseCountdownAnchorPerfMs === null) return null;
        const elapsed = (performance.now() - m1CloseCountdownAnchorPerfMs) / 1000;
        return Math.max(0, Math.min(M1_COUNTDOWN_SECONDS, m1CloseCountdownAnchorSec - elapsed));
    }

    const CANDLE_TIMER_GAP_T = 3;

    const signals = @json($chartSignals);
    const initialInstrument = @json($initialInstrument);
    const botName = @json($bot->name);

    const chartPlaceholder = document.getElementById('chart-placeholder');
    const chartWrapper = document.getElementById('chart-wrapper');
    const structureOverlay = document.getElementById('structure-overlay');
    const candleTimerOverlay = document.getElementById('candle-timer-overlay');
    const candleCloseTimerEl = document.getElementById('candle-close-timer');
    const candleTfLabelEl = document.getElementById('candle-tf-label');
    const openTradesOverlay = document.getElementById('open-trades-overlay');
    const chartTitle = document.getElementById('chart-title');
    const chartLoading = document.getElementById('chart-loading');
    const tfBtn = document.getElementById('tf-dropdown-btn');
    const tfPanel = document.getElementById('tf-dropdown-panel');
    const tfLabelEl = document.getElementById('tf-dropdown-label');
    const layersBtn = document.getElementById('layers-dropdown-btn');
    const layersPanel = document.getElementById('layers-dropdown-panel');
    const layerOb = document.getElementById('layer-ob');
    const layerFvg = document.getElementById('layer-fvg');
    const instrumentSearchInput = document.getElementById('instrument-search');
    const instrumentSearchClearBtn = document.getElementById('instrument-search-clear');
    const LAYER_PREFS_KEY = 'platform.bot.show.chartLayers.v2';

    let chart = null;
    let candlestickSeries = null;
    let currentInstrument = null;
    let currentDisplayName = '';
    let currentTimeframeMinutes = 1;
    let oldestLoadedTime = null;
    let allChartData = [];
    let loadingMore = false;
    let refreshInterval = null;
    let instrumentListRefreshInterval = null;
    let openTradesFetchInterval = null;
    let openTradesTickInterval = null;
    let lastZones = [];
    let openTrades = [];
    let entryPriceLineHandles = [];

    function loadLayerPrefs() {
        try {
            const raw = localStorage.getItem(LAYER_PREFS_KEY);
            if (raw == null) return;
            const obj = JSON.parse(raw);
            if (typeof obj.orderBlocks === 'boolean') layerOb.checked = obj.orderBlocks;
            if (typeof obj.imbalances === 'boolean') layerFvg.checked = obj.imbalances;
        } catch (_) {}
    }

    function saveLayerPrefs() {
        try {
            localStorage.setItem(LAYER_PREFS_KEY, JSON.stringify({
                orderBlocks: layerOb.checked,
                imbalances: layerFvg.checked,
            }));
        } catch (_) {}
    }

    function toBucketTime(unixTs) {
        const t = Number(unixTs || 0);
        return t > 0 ? Math.floor(t / 60) * 60 : null;
    }
    function markersForInstrument(instrument) {
        const markers = [];
        signals.forEach((s) => {
            if (s.instrument !== instrument) return;
            const signalTime = toBucketTime(new Date(s.signal_at).getTime() / 1000);
            if (signalTime) {
                const isCall = s.side === 'CALL';
                markers.push({ time: signalTime, position: isCall ? 'belowBar' : 'aboveBar', color: isCall ? '#10b981' : '#f43f5e', shape: isCall ? 'arrowUp' : 'arrowDown', text: `entry ${s.side}` });
            }
            if (s.trade && s.trade.expiry_time) {
                const expiryTime = toBucketTime(new Date(s.trade.expiry_time).getTime() / 1000);
                if (expiryTime) {
                    const result = (s.trade.result || 'pending');
                    const color = result === 'win' ? '#10b981' : (result === 'loss' ? '#f43f5e' : '#a1a1aa');
                    markers.push({ time: expiryTime, position: 'inBar', color, shape: 'circle', text: `exp ${result}` });
                }
            }
        });
        return markers.sort((a, b) => a.time - b.time);
    }
    function activeTradeMarkersForCurrentInstrument() {
        if (!currentInstrument) return [];
        const nowSec = Math.floor(Date.now() / 1000);
        const lastBarTime = allChartData.length ? Number(allChartData[allChartData.length - 1].time) : null;
        const active = (openTrades || [])
            .filter((t) => t.instrument === currentInstrument && Number(t.expiry_ts || 0) > nowSec);
        const markers = [];
        active.forEach((t) => {
            const side = (t.side || '').toUpperCase();
            const isCall = side === 'CALL';
            const left = Number(t.expiry_ts || 0) - nowSec;
            const entryTime = toBucketTime(Number(t.entry_ts || 0));
            if (entryTime) {
                markers.push({
                    time: entryTime,
                    position: isCall ? 'belowBar' : 'aboveBar',
                    color: isCall ? '#10b981' : '#f43f5e',
                    shape: 'circle',
                    text: `● OPEN ${formatMmSs(left)}`,
                });
            }
            if (lastBarTime) {
                markers.push({
                    time: lastBarTime,
                    position: isCall ? 'belowBar' : 'aboveBar',
                    color: isCall ? '#10b981' : '#f43f5e',
                    shape: 'square',
                    text: `${side} ${formatMmSs(left)}`,
                });
            }
        });
        return markers;
    }
    function renderChartMarkers() {
        if (!candlestickSeries || !currentInstrument) return;
        const base = markersForInstrument(currentInstrument);
        const active = activeTradeMarkersForCurrentInstrument();
        candlestickSeries.setMarkers([...base, ...active].sort((a, b) => a.time - b.time));
    }
    function clearEntryPriceLines() {
        if (!candlestickSeries) {
            entryPriceLineHandles = [];
            return;
        }
        entryPriceLineHandles.forEach((h) => {
            try { candlestickSeries.removePriceLine(h); } catch (_) {}
        });
        entryPriceLineHandles = [];
    }
    function renderEntryPriceLines() {
        clearEntryPriceLines();
        if (!candlestickSeries || !currentInstrument) return;
        const nowSec = Math.floor(Date.now() / 1000);
        const active = (openTrades || [])
            .filter((t) => t.instrument === currentInstrument && Number(t.expiry_ts || 0) > nowSec);
        if (!active.length) return;

        const LS = window.LightweightCharts && window.LightweightCharts.LineStyle;
        const lineStyleDashed = LS != null ? LS.Dashed : 2;
        const groups = new Map();
        active.forEach((t) => {
            const raw = t.entry_price;
            if (raw == null || raw === '') return;
            const price = Number(raw);
            if (!Number.isFinite(price)) return;
            const key = price.toFixed(5);
            if (!groups.has(key)) groups.set(key, { price, trades: [] });
            groups.get(key).trades.push(t);
        });
        groups.forEach(({ price, trades }) => {
            const sides = trades.map((x) => (x.side || '').toUpperCase());
            const allCall = sides.every((s) => s === 'CALL');
            const allPut = sides.every((s) => s === 'PUT');
            const color = allCall ? '#10b981' : (allPut ? '#f43f5e' : '#f59e0b');
            const priceLabel = trades[0].entry_price;
            const title = trades.length === 1
                ? `Вход ${priceLabel}`
                : `Вход ${priceLabel} ×${trades.length}`;
            const h = candlestickSeries.createPriceLine({
                price,
                color,
                lineWidth: 2,
                lineStyle: lineStyleDashed,
                axisLabelVisible: true,
                title,
            });
            entryPriceLineHandles.push(h);
        });
    }
    function candlesSince() { return Math.floor(Date.now() / 1000) - WINDOW_SEC; }
    function setLoading(show) { chartLoading.classList.toggle('hidden', !show); }
    function normalizeText(s) { return (s || '').toString().toLowerCase().trim(); }
    const CHART_DEFAULT_VISIBLE_BARS = 110;
    const CHART_RIGHT_GAP_BARS = 22;

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

    function isViewNearRightEdge() {
        if (!chart || allChartData.length === 0) return true;
        const range = chart.timeScale().getVisibleLogicalRange();
        if (!range) return true;
        return range.to >= (allChartData.length - 1) - 3;
    }
    function payoutClassByValue(value) {
        if (value == null || Number.isNaN(value)) return 'text-zinc-500';
        if (value >= 80) return 'text-emerald-400';
        if (value >= 60) return 'text-amber-400';
        return 'text-rose-400';
    }
    const instrumentListEl = document.getElementById('instrument-list');
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
            btn.className = 'instrument-btn w-full text-left px-4 py-3 hover:bg-zinc-800 focus:bg-zinc-800 focus:outline-none transition-colors' + (inst.open_trade ? ' bg-amber-950/30' : '');
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
                timer.className = 'instrument-trade-timer shrink-0 text-xs font-mono tabular-nums text-amber-400/90';
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
                applyInstrumentFilter(instrumentSearchInput.value);
                syncSearchClearButton();
                if (currentInstrument) {
                    document.querySelectorAll('.instrument-btn').forEach((b) => b.classList.toggle('bg-blue-900/40', b.dataset.instrument === currentInstrument));
                }
            })
            .catch(() => {});
    }
    function applyInstrumentFilter(rawQuery) {
        const query = normalizeText(rawQuery);
        let first = null;
        document.querySelectorAll('.instrument-btn').forEach((btn) => {
            const hay = normalizeText(btn.dataset.search || `${btn.dataset.displayName || ''} ${btn.dataset.instrument || ''}`);
            const visible = query === '' || hay.includes(query);
            btn.closest('li')?.classList.toggle('hidden', !visible);
            if (visible && !first) first = btn;
        });
        return first;
    }
    function syncSearchClearButton() {
        instrumentSearchClearBtn.classList.toggle('hidden', normalizeText(instrumentSearchInput.value).length === 0);
    }
    function updateTfOptionStyles() {
        document.querySelectorAll('.tf-option').forEach((btn) => {
            const on = parseInt(btn.dataset.minutes, 10) === currentTimeframeMinutes;
            btn.classList.toggle('bg-blue-900/50', on);
            btn.classList.toggle('font-medium', on);
        });
        const cur = document.querySelector('.tf-option[data-minutes="' + currentTimeframeMinutes + '"]');
        if (tfLabelEl && cur) tfLabelEl.textContent = cur.dataset.label || (currentTimeframeMinutes + 'м');
    }
    function updateChartTitle() {
        if (!currentDisplayName) { chartTitle.textContent = 'Выберите инструмент'; return; }
        const cur = document.querySelector('.tf-option[data-minutes="' + currentTimeframeMinutes + '"]');
        const lab = cur && cur.dataset.label ? cur.dataset.label : (currentTimeframeMinutes + 'м');
        chartTitle.textContent = `${currentDisplayName} (${lab})`;
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
        if (currentTimeframeMinutes !== 1) {
            if (candleTimerOverlay) candleTimerOverlay.classList.add('hidden');
            return;
        }
        if (!candleTimerOverlay || !chart || !candlestickSeries || !allChartData.length) {
            if (candleTimerOverlay) candleTimerOverlay.classList.add('hidden');
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
        if (x0 == null || Number.isNaN(x0)) x0 = ts.timeToCoordinate(lastT);
        if (x0 == null || Number.isNaN(x0)) {
            candleTimerOverlay.classList.add('hidden');
            return;
        }
        if (x1 == null || Number.isNaN(x1) || Math.abs(x1 - x0) < 0.5) x1 = x0 + bs;
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
        if (currentTimeframeMinutes !== 1) {
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
        const left = m1CountdownLeftNow();
        if (left === null) {
            if (candleTimerOverlay) candleTimerOverlay.classList.add('hidden');
            candleCloseTimerEl.textContent = '';
            return;
        }
        if (candleTfLabelEl) candleTfLabelEl.textContent = 'M' + String(currentTimeframeMinutes);
        candleCloseTimerEl.textContent = formatMmSs(left);
        requestAnimationFrame(positionCandleTimerOverlay);
    }

    function renderOpenTradesOverlay() {
        if (!openTradesOverlay) return;
        if (!currentInstrument) {
            openTradesOverlay.innerHTML = '';
            renderChartMarkers();
            renderEntryPriceLines();
            return;
        }
        const nowSec = Math.floor(Date.now() / 1000);
        const rows = (openTrades || [])
            .filter((t) => t.instrument === currentInstrument && Number(t.expiry_ts || 0) > nowSec)
            .sort((a, b) => Number(a.expiry_ts || 0) - Number(b.expiry_ts || 0));
        if (!rows.length) {
            openTradesOverlay.innerHTML = '';
            renderChartMarkers();
            renderEntryPriceLines();
            return;
        }
        openTradesOverlay.innerHTML = rows.map((t) => {
            const left = Number(t.expiry_ts || 0) - nowSec;
            const side = (t.side || '').toUpperCase();
            const sideClass = side === 'CALL' ? 'text-emerald-300' : 'text-rose-300';
            return `<div class="rounded border border-zinc-700/80 bg-zinc-900/85 px-2 py-1 text-xs shadow-sm shadow-black/30">
                <span class="font-medium ${sideClass}">● OPEN ${side || 'TRADE'}</span>
                <span class="text-zinc-400"> #${t.id}</span>
                <span class="text-zinc-200 font-mono ml-2">${formatMmSs(left)}</span>
            </div>`;
        }).join('');
        renderChartMarkers();
    }
    function refreshOpenTrades() {
        if (!currentInstrument) return;
        const params = new URLSearchParams({ instrument: currentInstrument });
        fetch(`${OPEN_TRADES_URL}?${params.toString()}`, {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then((r) => r.json())
            .then((d) => {
                openTrades = d.trades || [];
                renderOpenTradesOverlay();
                renderEntryPriceLines();
            })
            .catch(() => {});
    }
    function buildChartData(candles) {
        return (candles || []).map((c) => ({ time: c.time, open: c.open, high: c.high, low: c.low, close: c.close }))
            .sort((a, b) => a.time - b.time);
    }
    function fetchCandles(instrument, before) {
        const params = new URLSearchParams({ instrument, timeframe: String(currentTimeframeMinutes), limit: LIMIT, since: String(candlesSince()) });
        if (before != null) params.set('before', before);
        if (currentTimeframeMinutes === 1 && before == null) params.set('include_partial', '1');
        return fetch(`${CANDLES_URL}?${params.toString()}`, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then((r) => r.json())
            .then((payload) => {
                applyM1CountdownFromPayload(payload);
                return payload;
            });
    }
    function fetchStructures() {
        if (!currentInstrument) return;
        const params = new URLSearchParams({ instrument: currentInstrument, timeframe: String(currentTimeframeMinutes) });
        fetch(`${STRUCTURES_URL}?${params.toString()}`, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json()).then(d => { lastZones = d.zones || []; redrawStructureOverlay(); }).catch(() => {});
    }
    function redrawStructureOverlay() {
        if (!chart || !candlestickSeries || !structureOverlay) return;
        structureOverlay.innerHTML = '';
        if (!allChartData.length || !lastZones.length) return;
        const lastBarTime = allChartData[allChartData.length - 1].time;
        const showOb = layerOb.checked;
        const showFvg = layerFvg.checked;
        const zonePaint = {
            order_block_bull: { background: 'rgba(212, 184, 150, 0.35)', border: '1px solid rgba(180, 140, 100, 0.55)' },
            order_block_bear: { background: 'rgba(212, 184, 150, 0.35)', border: '1px solid rgba(180, 140, 100, 0.55)' },
            imbalance_bull: { background: 'rgba(14, 165, 233, 0.2)', border: '1px solid rgba(2, 132, 199, 0.45)' },
            imbalance_bear: { background: 'rgba(14, 165, 233, 0.2)', border: '1px solid rgba(2, 132, 199, 0.45)' },
            default: { background: 'rgba(156, 163, 175, 0.15)', border: '1px solid rgba(107, 114, 128, 0.35)' }
        };
        lastZones.forEach((z) => {
            if (z.type.startsWith('order_block') && !showOb) return;
            if (z.type.startsWith('imbalance') && !showFvg) return;
            if (lastBarTime < z.time_start) return;
            const x1 = chart.timeScale().timeToCoordinate(z.time_start);
            const x2 = chart.timeScale().timeToCoordinate(lastBarTime);
            const y1 = candlestickSeries.priceToCoordinate(z.price_high);
            const y2 = candlestickSeries.priceToCoordinate(z.price_low);
            if (x1 == null || x2 == null || y1 == null || y2 == null) return;
            const paint = zonePaint[z.type] || zonePaint.default;
            const div = document.createElement('div');
            div.style.position = 'absolute';
            div.style.pointerEvents = 'none';
            div.style.boxSizing = 'border-box';
            div.style.left = Math.min(x1, x2) + 'px';
            div.style.top = Math.min(y1, y2) + 'px';
            div.style.width = Math.max(1, Math.abs(x2 - x1)) + 'px';
            div.style.height = Math.max(1, Math.abs(y2 - y1)) + 'px';
            div.style.background = paint.background;
            div.style.border = paint.border;
            structureOverlay.appendChild(div);
        });
    }
    function applyIncrementalChartUpdate(newData) {
        if (!candlestickSeries || !newData.length) return;
        if (!allChartData.length) {
            allChartData = newData;
            oldestLoadedTime = Math.min(...allChartData.map(c => c.time));
            candlestickSeries.setData(allChartData);
            renderChartMarkers();
            renderEntryPriceLines();
            tickCandleCloseTimer();
            return;
        }
        const oldFirst = allChartData[0]?.time;
        const newFirst = newData[0]?.time;
        if (oldFirst !== newFirst) {
            allChartData = newData;
            oldestLoadedTime = Math.min(...allChartData.map(c => c.time));
            candlestickSeries.setData(allChartData);
            renderChartMarkers();
            renderEntryPriceLines();
            tickCandleCloseTimer();
            return;
        }
        const lastKnown = allChartData[allChartData.length - 1]?.time ?? 0;
        newData.slice(-2).filter(c => c.time >= lastKnown).forEach(c => candlestickSeries.update(c));
        allChartData = newData;
        oldestLoadedTime = Math.min(...allChartData.map(c => c.time));
        renderChartMarkers();
        renderEntryPriceLines();
        tickCandleCloseTimer();
    }
    function createChart() {
        entryPriceLineHandles = [];
        if (chart) chart.remove();
        chartWrapper.classList.remove('hidden');
        structureOverlay.classList.remove('hidden');
        chartPlaceholder.classList.add('hidden');
        chart = LightweightCharts.createChart(chartWrapper, {
            layout: { background: { type: 'solid', color: '#191c30' }, textColor: '#a1a1aa' },
            grid: { vertLines: { color: '#27272a' }, horzLines: { color: '#27272a' } },
            rightPriceScale: { borderColor: '#3f3f46', scaleMargins: { top: 0.1, bottom: 0.2 } },
            timeScale: { borderColor: '#3f3f46', timeVisible: true, secondsVisible: false },
            crosshair: { mode: 1 },
            width: chartWrapper.clientWidth,
            height: chartWrapper.clientHeight
        });
        candlestickSeries = chart.addCandlestickSeries({
            upColor: '#26a69a', downColor: '#ef5350', borderVisible: false,
            priceFormat: { type: 'price', precision: 5, minMove: 0.00001 }
        });
        chart.timeScale().subscribeVisibleLogicalRangeChange(() => {
            const range = chart.timeScale().getVisibleLogicalRange();
            if (range && currentTimeframeMinutes === 1 && !loadingMore && oldestLoadedTime != null && range.from < 2) loadMoreCandles();
            redrawStructureOverlay();
            requestAnimationFrame(positionCandleTimerOverlay);
        });
    }
    function loadMoreCandles() {
        if (currentTimeframeMinutes !== 1 || !currentInstrument || loadingMore || oldestLoadedTime == null) return;
        if (oldestLoadedTime <= candlesSince()) return;
        loadingMore = true; setLoading(true);
        fetchCandles(currentInstrument, oldestLoadedTime).then((data) => {
            const newBars = buildChartData(data.candles || []);
            const existingTimes = new Set(allChartData.map(c => c.time));
            const toAdd = newBars.filter(c => !existingTimes.has(c.time));
            if (!toAdd.length) return;
            allChartData = [...toAdd, ...allChartData].sort((a, b) => a.time - b.time);
            oldestLoadedTime = Math.min(...allChartData.map(c => c.time));
            candlestickSeries.setData(allChartData);
            renderChartMarkers();
            renderEntryPriceLines();
            redrawStructureOverlay();
            fetchStructures();
        }).finally(() => {
            loadingMore = false;
            setLoading(false);
            tickCandleCloseTimer();
        });
    }
    function startAutoRefresh() {
        if (refreshInterval) clearInterval(refreshInterval);
        refreshInterval = setInterval(() => {
            if (!currentInstrument || loadingMore || !candlestickSeries) return;
            const stick = isViewNearRightEdge();
            const prevLast = allChartData.length ? allChartData[allChartData.length - 1].time : null;
            fetchCandles(currentInstrument).then((data) => {
                const newData = buildChartData(data.candles || []);
                if (!newData.length) return;
                const newLast = newData[newData.length - 1].time;
                applyIncrementalChartUpdate(newData);
                if (stick && chart && prevLast !== null && newLast > prevLast) chart.timeScale().scrollToRealTime();
                redrawStructureOverlay();
                fetchStructures();
            }).catch(() => {});
        }, 2000);
    }
    function reloadChartData() {
        if (!currentInstrument || !candlestickSeries) return;
        setLoading(true);
        fetchCandles(currentInstrument).then((data) => {
            allChartData = buildChartData(data.candles || []);
            if (allChartData.length) {
                oldestLoadedTime = Math.min(...allChartData.map(c => c.time));
                candlestickSeries.setData(allChartData);
                renderChartMarkers();
                renderEntryPriceLines();
                applyDefaultChartViewport();
                fetchStructures();
            } else {
                candlestickSeries.setData([]);
                lastZones = [];
                structureOverlay.innerHTML = '';
            }
            updateChartTitle();
            redrawStructureOverlay();
            renderEntryPriceLines();
            tickCandleCloseTimer();
        }).finally(() => setLoading(false));
    }
    function selectInstrument(instrument, displayName) {
        if (currentInstrument === instrument) return;
        currentInstrument = instrument;
        currentDisplayName = displayName;
        openTrades = [];
        renderOpenTradesOverlay();
        document.querySelectorAll('.instrument-btn').forEach((b) => b.classList.toggle('bg-blue-900/40', b.dataset.instrument === instrument));
        lastZones = [];
        structureOverlay.innerHTML = '';
        updateChartTitle();
        updateTfOptionStyles();
        setLoading(true);
        createChart();
        fetchCandles(instrument).then((data) => {
            allChartData = buildChartData(data.candles || []);
            if (allChartData.length) {
                oldestLoadedTime = Math.min(...allChartData.map(c => c.time));
                candlestickSeries.setData(allChartData);
                renderChartMarkers();
                renderEntryPriceLines();
                applyDefaultChartViewport();
                startAutoRefresh();
                redrawStructureOverlay();
                fetchStructures();
                refreshOpenTrades();
            } else if (refreshInterval) {
                clearInterval(refreshInterval);
            }
            updateChartTitle();
            tickCandleCloseTimer();
        }).finally(() => setLoading(false));
    }

    tfBtn.addEventListener('click', (e) => { e.stopPropagation(); layersPanel.classList.add('hidden'); tfPanel.classList.toggle('hidden'); });
    tfPanel.addEventListener('click', (e) => e.stopPropagation());
    document.querySelectorAll('.tf-option').forEach((btn) => btn.addEventListener('click', (e) => {
        e.stopPropagation();
        const m = parseInt(btn.dataset.minutes, 10);
        if (!Number.isFinite(m) || m === currentTimeframeMinutes) { tfPanel.classList.add('hidden'); return; }
        currentTimeframeMinutes = m;
        updateTfOptionStyles();
        tfPanel.classList.add('hidden');
        if (currentInstrument && candlestickSeries) reloadChartData();
    }));
    layersBtn.addEventListener('click', (e) => { e.stopPropagation(); tfPanel.classList.add('hidden'); layersPanel.classList.toggle('hidden'); });
    layersPanel.addEventListener('click', (e) => e.stopPropagation());
    document.addEventListener('click', () => { layersPanel.classList.add('hidden'); tfPanel.classList.add('hidden'); });
    layerOb.addEventListener('change', () => { saveLayerPrefs(); redrawStructureOverlay(); });
    layerFvg.addEventListener('change', () => { saveLayerPrefs(); redrawStructureOverlay(); });
    new ResizeObserver(() => {
        if (chart && chartWrapper.clientWidth) {
            chart.resize(chartWrapper.clientWidth, chartWrapper.clientHeight);
            redrawStructureOverlay();
            requestAnimationFrame(positionCandleTimerOverlay);
        }
    }).observe(chartWrapper);
    instrumentListEl.addEventListener('click', (e) => {
        const btn = e.target.closest('.instrument-btn');
        if (!btn) return;
        selectInstrument(btn.dataset.instrument, btn.dataset.displayName);
    });

    instrumentSearchInput.addEventListener('input', () => { applyInstrumentFilter(instrumentSearchInput.value); syncSearchClearButton(); });
    instrumentSearchInput.addEventListener('keydown', (e) => {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        const first = applyInstrumentFilter(instrumentSearchInput.value);
        if (first) first.click();
    });
    instrumentSearchClearBtn.addEventListener('click', () => {
        instrumentSearchInput.value = '';
        applyInstrumentFilter('');
        syncSearchClearButton();
        instrumentSearchInput.focus();
    });

    loadLayerPrefs();
    updateTfOptionStyles();
    syncSearchClearButton();
    instrumentListRefreshInterval = setInterval(refreshInstrumentSidebar, 1000);
    openTradesTickInterval = setInterval(() => {
        renderOpenTradesOverlay();
        tickSidebarTradeTimers();
        tickCandleCloseTimer();
    }, 1000);
    renderOpenTradesOverlay();
    tickSidebarTradeTimers();
    tickCandleCloseTimer();
    openTradesFetchInterval = setInterval(refreshOpenTrades, 3000);
    refreshInstrumentSidebar().then(() => {
        let ib = null;
        document.querySelectorAll('.instrument-btn').forEach((b) => {
            if (b.dataset.instrument === initialInstrument) ib = b;
        });
        if (!ib) ib = document.querySelector('.instrument-btn');
        if (ib) ib.click();
    });
})();
</script>
@endsection

