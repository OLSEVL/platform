@extends('layouts.app')
@section('title', 'Графики')
@section('content')
<div class="flex gap-4 h-[calc(100vh-10rem)] min-h-[400px]">
    {{-- Список инструментов слева --}}
    <aside class="w-56 shrink-0 bg-white rounded-lg shadow overflow-hidden flex flex-col">
        <div class="p-3 border-b border-gray-200 bg-gray-50">
            <h2 class="font-semibold text-gray-800">Инструменты</h2>
        </div>
        <ul class="flex-1 overflow-y-auto divide-y divide-gray-100">
            @forelse($instruments as $inst)
            <li>
                <button type="button"
                    class="instrument-btn w-full text-left px-4 py-3 hover:bg-blue-50 focus:bg-blue-50 focus:outline-none transition-colors"
                    data-instrument="{{ $inst->instrument }}"
                    data-display-name="{{ e($inst->display_name ?? $inst->instrument) }}">
                    <span class="font-medium text-gray-800">{{ $inst->display_name ?? $inst->instrument }}</span>
                    <span class="block text-xs text-gray-500 font-mono mt-0.5">{{ $inst->instrument }}</span>
                </button>
            </li>
            @empty
            <li class="px-4 py-6 text-sm text-gray-500">Нет активных инструментов.</li>
            @endforelse
        </ul>
    </aside>

    {{-- Область графика --}}
    <div class="flex-1 flex flex-col bg-white rounded-lg shadow overflow-hidden min-w-0">
        <div class="p-3 border-b border-gray-200 bg-gray-50 flex items-center justify-between">
            <h2 id="chart-title" class="font-semibold text-gray-800">Выберите инструмент</h2>
            <span id="chart-loading" class="text-sm text-gray-500 hidden">Загрузка…</span>
        </div>
        <div id="chart-container" class="flex-1 min-h-[300px] relative">
            <div id="chart-placeholder" class="absolute inset-0 flex items-center justify-center text-gray-400 bg-gray-50/80">
                Нажмите на инструмент слева, чтобы показать минутный график
            </div>
            <div id="chart-wrapper" class="absolute inset-0 hidden"></div>
        </div>
    </div>
</div>

<script src="https://unpkg.com/lightweight-charts@4.1.0/dist/lightweight-charts.standalone.production.js"></script>
<script>
(function() {
    const CANDLES_URL = '{{ route("candles.data") }}';
    const LIMIT = 300;
    const chartContainer = document.getElementById('chart-container');
    const chartPlaceholder = document.getElementById('chart-placeholder');
    const chartWrapper = document.getElementById('chart-wrapper');
    const chartTitle = document.getElementById('chart-title');
    const chartLoading = document.getElementById('chart-loading');

    let chart = null;
    let candlestickSeries = null;
    let currentInstrument = null;
    let oldestLoadedTime = null;
    let allChartData = [];
    let loadingMore = false;

    function setLoading(show) {
        chartLoading.classList.toggle('hidden', !show);
    }

    function fetchCandles(instrument, before) {
        const params = new URLSearchParams({ instrument, timeframe: 1, limit: LIMIT });
        if (before != null) params.set('before', before);
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

    function createChart() {
        if (chart) chart.remove();
        chartWrapper.innerHTML = '';
        chartWrapper.classList.remove('hidden');
        chartPlaceholder.classList.add('hidden');

        chart = LightweightCharts.createChart(chartWrapper, {
            layout: {
                background: { type: 'solid', color: '#ffffff' },
                textColor: '#333'
            },
            grid: { vertLines: { color: '#eef' }, horzLines: { color: '#eef' } },
            rightPriceScale: { borderColor: '#ccc', scaleMargins: { top: 0.1, bottom: 0.2 } },
            timeScale: {
                borderColor: '#ccc',
                timeVisible: true,
                secondsVisible: false
            },
            crosshair: { mode: 1 }
        });

        candlestickSeries = chart.addCandlestickSeries({
            upColor: '#26a69a',
            downColor: '#ef5350',
            borderVisible: false
        });

        chart.timeScale().fitContent();

        chart.timeScale().subscribeVisibleLogicalRangeChange(() => {
            const range = chart.timeScale().getVisibleLogicalRange();
            if (!range || loadingMore || oldestLoadedTime == null) return;
            if (range.from < 2) loadMoreCandles();
        });
    }

    function loadMoreCandles() {
        if (!currentInstrument || loadingMore || oldestLoadedTime == null) return;
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
            })
            .catch(() => { loadingMore = false; setLoading(false); });
    }

    function selectInstrument(instrument, displayName) {
        if (currentInstrument === instrument) return;
        currentInstrument = instrument;
        chartTitle.textContent = displayName + ' (M1)';
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
                }
                setLoading(false);
            })
            .catch(() => setLoading(false));
    }

    document.querySelectorAll('.instrument-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.instrument-btn').forEach(b => b.classList.remove('bg-blue-100'));
            btn.classList.add('bg-blue-100');
            selectInstrument(btn.dataset.instrument, btn.dataset.displayName);
        });
    });
})();
</script>
@endsection
