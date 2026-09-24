{{--
  Boot config for dashboard chart Vite entry.
  Must appear BEFORE @vite('resources/js/chart/dashboard.js').
--}}
@php
    use App\Support\ChartBootFlags;
    $chartFlags = ChartBootFlags::forClient();
    $clientIndicatorParams = [];
@endphp
<script>
    // PR5: page-specific + общие флаги (live/heal/tradeLite) из ChartBootFlags.
    window.__CHART_DASHBOARD_BOOT__ = Object.assign({
        candlesUrl: @json(route('candles.data')),
        structuresUrl: @json(route('structures.data')),
        paIndicatorUrl: @json(route('dashboard.indicators.price-action-dgt')),
        lsIndicatorUrl: @json(route('dashboard.indicators.liquidity-swings')),
        smcIndicatorUrl: @json(route('dashboard.indicators.smc-flow')),
        classicIndicatorsUrl: @json(route('dashboard.indicators.classic')),
        instrumentsSidebarUrl: @json(route('dashboard.instruments.data')),
        chartTradesUrl: @json(route('dashboard.chart-trades.data')),
        liveTradesUrl: @json(route('bots.live-trades.data')),
        liveTradesActivityTmpl: @json(route('bots.activity', ['bot' => '__BOT_ID__'])),
        liveTradesPageUrl: @json(route('bots.live-trades')),
        chartHistoryMaxSeconds: {{ (int) ($chartHistoryMaxSeconds ?? 86400) }},
        // Клиентские индикаторы (LS/PA/SMC/classics) — без тяжёлых poll-эндпоинтов.
        clientIndicatorParams: @json($clientIndicatorParams),
    }, @json($chartFlags));
</script>
@vite(['resources/js/chart/dashboard.js'])
