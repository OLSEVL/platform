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
<span class="sr-only pointer-events-none bg-blue-900/40 bg-blue-900/50" aria-hidden="true"></span>
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
                    class="instrument-btn w-full text-left px-4 py-3 hover:bg-zinc-800 focus:bg-zinc-800 focus:outline-none transition-colors"
                    data-instrument="{{ $inst->instrument }}"
                    data-display-name="{{ e($inst->display_name ?? $inst->instrument) }}"
                    data-search="{{ mb_strtolower(($inst->display_name ?? $inst->instrument).' '.$inst->instrument) }}">
                    <div class="flex items-center justify-between gap-2">
                        <span class="font-medium text-zinc-100 truncate">{{ $inst->display_name ?? $inst->instrument }}</span>
                        @if($inst->current_payout !== null)
                            @php
                                $payout = (float) $inst->current_payout;
                                $payoutClass = $payout >= 80
                                    ? 'text-emerald-400'
                                    : ($payout >= 60 ? 'text-amber-400' : 'text-rose-400');
                            @endphp
                            <span
                                class="instrument-payout text-xs font-medium {{ $payoutClass }}"
                                data-instrument="{{ $inst->instrument }}"
                                data-payout="{{ $payout }}"
                            >{{ number_format($payout, 0) }}%</span>
                        @else
                            <span
                                class="instrument-payout text-xs text-zinc-500"
                                data-instrument="{{ $inst->instrument }}"
                                data-payout=""
                            >—</span>
                        @endif
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
                    <div id="layers-dropdown-panel"
                        class="hidden absolute left-0 top-full mt-1 z-20 bg-zinc-900 border border-zinc-700 rounded shadow-lg shadow-black/40 py-2 px-3 min-w-[11rem] space-y-2">
                        <label class="flex items-center gap-2 text-sm text-zinc-300 cursor-pointer whitespace-nowrap">
                            <input type="checkbox" id="layer-ob" class="rounded border-zinc-600 bg-zinc-800 text-blue-500" checked>
                            Ордерблоки
                        </label>
                        <label class="flex items-center gap-2 text-sm text-zinc-300 cursor-pointer whitespace-nowrap">
                            <input type="checkbox" id="layer-fvg" class="rounded border-zinc-600 bg-zinc-800 text-blue-500" checked>
                            Имбалансы
                        </label>
                    </div>
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
        </div>
    </div>
</div>

<script src="https://unpkg.com/lightweight-charts@4.1.0/dist/lightweight-charts.standalone.production.js"></script>
<script>
(function() {
    const CANDLES_URL = '{{ route("candles.data") }}';
    const STRUCTURES_URL = '{{ route("structures.data") }}';
    const PAYOUTS_URL = '{{ route("payouts.data") }}';
    const LIMIT = 1440;
    const WINDOW_SEC = 86400;

    const chartPlaceholder = document.getElementById('chart-placeholder');
    const chartWrapper = document.getElementById('chart-wrapper');
    const structureOverlay = document.getElementById('structure-overlay');
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

    const LAYER_PREFS_KEY = 'platform.dashboard.chartLayers';

    function loadLayerPrefs() {
        try {
            const raw = localStorage.getItem(LAYER_PREFS_KEY);
            if (raw == null) return;
            const o = JSON.parse(raw);
            if (typeof o.orderBlocks === 'boolean') layerOb.checked = o.orderBlocks;
            if (typeof o.imbalances === 'boolean') layerFvg.checked = o.imbalances;
        } catch (_) { /* ignore */ }
    }

    function saveLayerPrefs() {
        try {
            localStorage.setItem(LAYER_PREFS_KEY, JSON.stringify({
                orderBlocks: layerOb.checked,
                imbalances: layerFvg.checked,
            }));
        } catch (_) { /* ignore */ }
    }

    loadLayerPrefs();

    let chart = null;
    let candlestickSeries = null;
    let currentInstrument = null;
    let currentDisplayName = '';
    let currentTimeframeMinutes = 1;
    let oldestLoadedTime = null;
    let allChartData = [];
    let loadingMore = false;
    let refreshInterval = null;
    let payoutRefreshInterval = null;
    let lastZones = [];

    function payoutClassByValue(value) {
        if (value == null || Number.isNaN(value)) return 'text-zinc-500';
        if (value >= 80) return 'text-emerald-400';
        if (value >= 60) return 'text-amber-400';
        return 'text-rose-400';
    }

    function updateInstrumentPayoutsUI(payouts) {
        if (!Array.isArray(payouts)) return;
        const map = new Map();
        payouts.forEach((item) => {
            if (!item || !item.instrument) return;
            map.set(item.instrument, item.current_payout);
        });

        document.querySelectorAll('.instrument-payout').forEach((el) => {
            const instrument = el.dataset.instrument;
            if (!instrument || !map.has(instrument)) return;
            const val = map.get(instrument);
            const numeric = (val === null || val === undefined) ? null : Number(val);
            el.classList.remove('text-zinc-500', 'text-emerald-400', 'text-amber-400', 'text-rose-400');
            el.classList.add(payoutClassByValue(numeric));
            if (numeric == null || Number.isNaN(numeric)) {
                el.textContent = '—';
                el.dataset.payout = '';
            } else {
                el.textContent = `${Math.round(numeric)}%`;
                el.dataset.payout = String(numeric);
            }
        });

        sortInstrumentListByPayout();
    }

    function sortInstrumentListByPayout() {
        const list = document.getElementById('instrument-list');
        if (!list) return;

        const items = Array.from(list.querySelectorAll('li'));
        items.sort((a, b) => {
            const aPayout = a.querySelector('.instrument-payout')?.dataset.payout ?? '';
            const bPayout = b.querySelector('.instrument-payout')?.dataset.payout ?? '';
            const aVal = aPayout === '' ? Number.NEGATIVE_INFINITY : Number(aPayout);
            const bVal = bPayout === '' ? Number.NEGATIVE_INFINITY : Number(bPayout);

            if (bVal !== aVal) return bVal - aVal;

            const aBtn = a.querySelector('.instrument-btn');
            const bBtn = b.querySelector('.instrument-btn');
            const aInstrument = (aBtn?.dataset.instrument ?? '').toLowerCase();
            const bInstrument = (bBtn?.dataset.instrument ?? '').toLowerCase();
            return aInstrument.localeCompare(bInstrument);
        });

        items.forEach((item) => list.appendChild(item));
    }

    function refreshInstrumentPayouts() {
        fetch(PAYOUTS_URL, {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(r => r.json())
            .then(data => updateInstrumentPayoutsUI(data.payouts || []))
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

    function candlesSince() {
        return Math.floor(Date.now() / 1000) - WINDOW_SEC;
    }

    /** true, если видимый диапазон у правого края данных (смотрим «живой» хвост) */
    function isViewNearRightEdge() {
        if (!chart || allChartData.length === 0) return true;
        const range = chart.timeScale().getVisibleLogicalRange();
        if (!range) return true;
        const lastIdx = allChartData.length - 1;
        const barsTolerance = 3;
        return range.to >= lastIdx - barsTolerance;
    }

    function redrawStructureOverlay() {
        if (!chart || !candlestickSeries || !structureOverlay) return;
        if (!allChartData.length) {
            structureOverlay.innerHTML = '';
            return;
        }
        if (lastZones.length === 0) {
            structureOverlay.innerHTML = '';
            return;
        }
        const lastBarTime = allChartData[allChartData.length - 1].time;

        const showOb = layerOb.checked;
        const showFvg = layerFvg.checked;
        structureOverlay.innerHTML = '';

        /* rgba — явные стили: Tailwind CDN не подхватывает классы из строк JS */
        const zonePaint = {
            order_block_bull: { background: 'rgba(212, 184, 150, 0.35)', border: '1px solid rgba(180, 140, 100, 0.55)' },
            order_block_bear: { background: 'rgba(212, 184, 150, 0.35)', border: '1px solid rgba(180, 140, 100, 0.55)' },
            imbalance_bull: { background: 'rgba(14, 165, 233, 0.2)', border: '1px solid rgba(2, 132, 199, 0.45)' },
            imbalance_bear: { background: 'rgba(14, 165, 233, 0.2)', border: '1px solid rgba(2, 132, 199, 0.45)' },
            default: { background: 'rgba(156, 163, 175, 0.15)', border: '1px solid rgba(107, 114, 128, 0.35)' }
        };

        lastZones.forEach(z => {
            const isOb = z.type.startsWith('order_block');
            const isFvg = z.type.startsWith('imbalance');
            if (isOb && !showOb) return;
            if (isFvg && !showFvg) return;

            if (lastBarTime < z.time_start) return;
            const tEnd = lastBarTime;

            const x1 = chart.timeScale().timeToCoordinate(z.time_start);
            const x2 = chart.timeScale().timeToCoordinate(tEnd);
            if (x1 == null || x2 == null) return;

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
            div.style.background = paint.background;
            div.style.border = paint.border;
            structureOverlay.appendChild(div);
        });
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
        refreshInterval = setInterval(() => {
            if (!currentInstrument || loadingMore || !candlestickSeries) return;
            const stickToLatest = isViewNearRightEdge();
            const prevLastT = allChartData.length ? allChartData[allChartData.length - 1].time : null;
            fetchCandles(currentInstrument)
                .then(data => {
                    const candles = data.candles || [];
                    if (candles.length === 0) return;
                    const newData = buildChartData(candles);
                    const newLastT = newData[newData.length - 1].time;
                    applyIncrementalChartUpdate(newData);
                    if (stickToLatest && chart && prevLastT !== null && newLastT > prevLastT) {
                        chart.timeScale().scrollToRealTime();
                    }
                    requestAnimationFrame(redrawStructureOverlay);
                    fetchStructures();
                })
                .catch(console.error);
        }, 2000);
    }

    function setLoading(show) {
        chartLoading.classList.toggle('hidden', !show);
    }

    function fetchCandles(instrument, before) {
        const params = new URLSearchParams({
            instrument,
            timeframe: String(currentTimeframeMinutes),
            limit: LIMIT,
            since: String(candlesSince())
        });
        if (before != null) params.set('before', before);
        if (currentTimeframeMinutes === 1 && before == null) {
            params.set('include_partial', '1');
        }
        return fetch(CANDLES_URL + '?' + params.toString(), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        }).then(r => r.json());
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

    function applyIncrementalChartUpdate(newData) {
        if (!candlestickSeries || !Array.isArray(newData) || newData.length === 0) return;

        if (allChartData.length === 0) {
            allChartData = newData;
            oldestLoadedTime = Math.min(...allChartData.map(c => c.time));
            candlestickSeries.setData(allChartData);
            return;
        }

        const oldFirstTime = allChartData[0]?.time ?? null;
        const newFirstTime = newData[0]?.time ?? null;
        if (oldFirstTime == null || newFirstTime == null || oldFirstTime !== newFirstTime) {
            allChartData = newData;
            oldestLoadedTime = Math.min(...allChartData.map(c => c.time));
            candlestickSeries.setData(allChartData);
            return;
        }

        // LightweightCharts.update() умеет менять только последний бар или добавлять новый.
        // Поэтому обновляем только безопасный "хвост": последний и, при смене минуты, следующий за ним.
        const lastKnownTime = allChartData[allChartData.length - 1]?.time ?? 0;
        const tailCandles = newData.slice(-2).filter(c => c.time >= lastKnownTime);
        tailCandles.forEach(c => candlestickSeries.update(c));

        allChartData = newData;
        oldestLoadedTime = Math.min(...allChartData.map(c => c.time));
    }

    function createChart() {
        if (chart) chart.remove();
        chartWrapper.innerHTML = '';
        chartWrapper.classList.remove('hidden');
        structureOverlay.classList.remove('hidden');
        chartPlaceholder.classList.add('hidden');

        chart = LightweightCharts.createChart(chartWrapper, {
            layout: {
                background: { type: 'solid', color: '#09090b' },
                textColor: '#a1a1aa'
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

        candlestickSeries = chart.addCandlestickSeries({
            upColor: '#26a69a',
            downColor: '#ef5350',
            borderVisible: false,
            priceFormat: {
                type: 'price',
                precision: 5,
                minMove: 0.00001
            }
        });

        chart.timeScale().fitContent();

        chart.timeScale().subscribeVisibleLogicalRangeChange(() => {
            const range = chart.timeScale().getVisibleLogicalRange();
            if (range && currentTimeframeMinutes === 1 && !loadingMore && oldestLoadedTime != null && range.from < 2) {
                loadMoreCandles();
            }
            redrawStructureOverlay();
        });
    }

    function loadMoreCandles() {
        if (currentTimeframeMinutes !== 1) return;
        if (!currentInstrument || loadingMore || oldestLoadedTime == null) return;
        const sinceT = candlesSince();
        if (oldestLoadedTime <= sinceT) return;

        loadingMore = true;
        setLoading(true);
        fetchCandles(currentInstrument, oldestLoadedTime)
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
                requestAnimationFrame(redrawStructureOverlay);
                fetchStructures();
            })
            .catch(() => { loadingMore = false; setLoading(false); });
    }

    function reloadChartData() {
        if (!currentInstrument || !candlestickSeries) return;
        setLoading(true);
        fetchCandles(currentInstrument)
            .then(data => {
                const candles = data.candles || [];
                allChartData = buildChartData(candles);
                if (allChartData.length) {
                    oldestLoadedTime = Math.min(...allChartData.map(c => c.time));
                    candlestickSeries.setData(allChartData);
                    chart.timeScale().fitContent();
                    fetchStructures();
                } else {
                    candlestickSeries.setData([]);
                    lastZones = [];
                    structureOverlay.innerHTML = '';
                }
                updateChartTitle();
                requestAnimationFrame(redrawStructureOverlay);
                setLoading(false);
            })
            .catch(() => { setLoading(false); });
    }

    function selectInstrument(instrument, displayName) {
        if (currentInstrument === instrument) return;
        currentInstrument = instrument;
        currentDisplayName = displayName;
        lastZones = [];
        structureOverlay.innerHTML = '';
        updateChartTitle();
        updateTfOptionStyles();
        setLoading(true);
        createChart();

        fetchCandles(instrument)
            .then(data => {
                const candles = data.candles || [];
                allChartData = buildChartData(candles);
                if (allChartData.length) {
                    oldestLoadedTime = Math.min(...allChartData.map(c => c.time));
                    candlestickSeries.setData(allChartData);
                    chart.timeScale().fitContent();
                    startAutoRefresh();
                    requestAnimationFrame(() => {
                        redrawStructureOverlay();
                        fetchStructures();
                    });
                } else {
                    if (refreshInterval) clearInterval(refreshInterval);
                }
                updateChartTitle();
                setLoading(false);
            })
            .catch(() => {
                setLoading(false);
                if (refreshInterval) clearInterval(refreshInterval);
            });
    }

    tfBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        layersPanel.classList.add('hidden');
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

    layersBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        tfPanel.classList.add('hidden');
        layersPanel.classList.toggle('hidden');
    });
    layersPanel.addEventListener('click', (e) => e.stopPropagation());
    document.addEventListener('click', () => {
        layersPanel.classList.add('hidden');
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

    new ResizeObserver(() => {
        if (!chart || !chartWrapper.clientWidth) return;
        chart.resize(chartWrapper.clientWidth, chartWrapper.clientHeight);
        requestAnimationFrame(redrawStructureOverlay);
    }).observe(chartWrapper);

    document.querySelectorAll('.instrument-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.instrument-btn').forEach(b => b.classList.remove('bg-blue-900/40'));
            btn.classList.add('bg-blue-900/40');
            selectInstrument(btn.dataset.instrument, btn.dataset.displayName);
        });
    });

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

    const firstInstrumentBtn = document.querySelector('.instrument-btn');
    if (firstInstrumentBtn) {
        firstInstrumentBtn.click();
    }
    syncSearchClearButton();

    refreshInstrumentPayouts();
    payoutRefreshInterval = setInterval(refreshInstrumentPayouts, 15000);
})();
</script>
@endsection
