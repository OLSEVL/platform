{{--
  Boot config for bot chart Vite entry.
  Must appear BEFORE @vite('resources/js/chart/bot.js').
--}}
@php
    use App\Support\ChartIndicatorInstanceRegistry;
    use App\Support\ChartBootFlags;
    use App\Services\ChartIndicatorInstanceService;
    $instanceRenderSpecs = ChartIndicatorInstanceRegistry::renderSpecsForJs();
    $instanceTypeKeys = ChartIndicatorInstanceRegistry::supportedHelpKeys();
    $chartInstancesSyncTradingUrl = ChartIndicatorInstanceService::needsTradingSync($bot)
        ? route('bots.chart-instances.sync-trading', $bot)
        : '';
    $chartFlags = ChartBootFlags::forClient();
    $stratAssistantParams = \App\Models\IndicatorSetting::mergedStratAssistantParams($bot);
    $clientIndicatorParams = [
        'strat_assistant' => $stratAssistantParams,
        'liquidity_swings' => \App\Models\IndicatorSetting::mergedLiquiditySwingsParams($bot),
        'price_action_dgt' => \App\Models\IndicatorSetting::mergedPriceActionDgtParams($bot),
        'smc_flow' => \App\Models\IndicatorSetting::mergedSmcFlowParams($bot),
        'sma' => \App\Models\IndicatorSetting::mergedSmaParams($bot),
        'ema' => \App\Models\IndicatorSetting::mergedEmaParams($bot),
        'rsi' => \App\Models\IndicatorSetting::mergedRsiParams($bot),
        'bollinger' => \App\Models\IndicatorSetting::mergedBollingerParams($bot),
        'atr' => \App\Models\IndicatorSetting::mergedAtrParams($bot),
        'macd' => \App\Models\IndicatorSetting::mergedMacdParams($bot),
        'vwma' => \App\Models\IndicatorSetting::mergedVwmaParams($bot),
        'stochastic' => \App\Models\IndicatorSetting::mergedStochasticParams($bot),
    ];
@endphp
<script>
    // PR5: page-specific + общие флаги (live/heal/tradeLite) из ChartBootFlags.
    window.__CHART_BOT_BOOT__ = Object.assign({
        candlesUrl: @json(route('candles.data')),
        structuresUrl: @json(route('bots.structures.data', $bot)),
        paIndicatorUrl: @json(route('bots.indicators.data', $bot)),
        instrumentsSidebarUrl: @json(route('bots.instruments.sidebar-data', $bot)),
        openTradesUrl: @json(route('bots.open-trades.data', $bot)),
        signalsUrl: @json(route('bots.signals.data', $bot)),
        tradesHistoryUrl: @json(route('bots.trades')),
        botAnalysisUrl: @json(route('bots.analysis.data', $bot)),
        backtestSupported: @json($backtestSupported ?? false),
        backtestsUrl: @json(route('bots.backtests.index', $bot)),
        isChartAnalystBot: @json($isChartAnalystBot ?? false),
        analystSmcFlowEnabled: @json($analystSmcFlowEnabled ?? false),
        analystSrlFlowEnabled: @json($analystSrlFlowEnabled ?? false),
        analystEngulfFlowEnabled: @json($analystEngulfFlowEnabled ?? false),
        analystAuthorFlowEnabled: @json($analystAuthorFlowEnabled ?? false),
        tradingChartLayerIds: @json($tradingChartLayerIds ?? []),
        botId: @json($bot->id),
        tradingChartTimeframe: {{ (int) ($tradingChartTimeframe ?? 1) }},
        chartInstancesSyncTradingUrl: @json($chartInstancesSyncTradingUrl),
        chartIndicatorInstances: @json($chartIndicatorInstances ?? []),
        botStatsUrl: @json(route('bots.stats.data', $bot)),
        statsHour: @json($statsHour ?? null),
        statsAll: @json($statsAll ?? null),
        tvCrosshairEnabled: @json($tvCrosshairEnabled ?? true),
        chartSignals: @json($chartSignals ?? []),
        initialInstrument: @json($initialInstrument ?? null),
        botName: @json($bot->name),
        activeTradeSidebar: @json($activeTradeSidebar ?? null),
        targetTradeBootstrap: @json($targetTradeBootstrap ?? null),
        sweepRaidLiquiditySettingsUrl: @json(route('bots.indicators.sweep-raid-liquidity.edit', $bot)),
        engulfingLiquiditySettingsUrl: @json(route('bots.indicators.engulfing-liquidity.edit', $bot)),
        chartInstancesIndexUrl: @json(route('bots.chart-instances.index', $bot)),
        chartInstancesStoreUrl: @json(route('bots.chart-instances.store', $bot)),
        chartInstanceItemUrlTmpl: @json(url('/bots/'.$bot->id.'/chart-instances/__ID__')),
        instanceTypeKeys: @json($instanceTypeKeys),
        instanceRenderSpecs: @json($instanceRenderSpecs),
        // Клиентские индикаторы (без тяжёлого /indicators/data для этих слоёв).
        stratAssistantParams: @json($stratAssistantParams),
        clientIndicatorParams: @json($clientIndicatorParams),
    }, @json($chartFlags));
</script>
@vite(['resources/js/chart/bot.js'])
