{{--
  Широкий попап слоёв графика: чекбокс + ⚙ + краткое описание + «?» (детальная справка).
  Ожидает: $indicatorBot (Bot|null), $showLlmLevels (bool, optional).
--}}
@php
    use App\Support\ChartIndicatorHelp;

    $helpCatalog = ChartIndicatorHelp::catalog();
    $settingsBot = $indicatorBot ?? null;
    $showLlmLevels = $showLlmLevels ?? false;
    // Слои, которыми этот бот торгует — метка «торговля» в списке.
    $tradingLayerIds = $tradingLayerIds ?? [];

    /**
     * Строка слоя: id чекбокса, ключ help, route name (nullable), заголовок UI.
     * @var list<array{id:string, help:string, route:?string, label:string, section:?string}>
     */
    $layerRows = [
        ['id' => 'layer-ob', 'help' => 'ob', 'route' => 'bots.indicators.order-blocks.edit', 'label' => 'Ордерблоки', 'section' => null],
        ['id' => 'layer-fvg', 'help' => 'fvg', 'route' => 'bots.indicators.fvg.edit', 'label' => 'Имбалансы', 'section' => null],
        ['id' => 'layer-pa-dgt', 'help' => 'pa-dgt', 'route' => 'bots.indicators.price-action-dgt.edit', 'label' => 'Price Action (DGT)', 'section' => null],
        ['id' => 'layer-liquidity-swings', 'help' => 'liquidity-swings', 'route' => 'bots.indicators.liquidity-swings.edit', 'label' => 'Liquidity Swings', 'section' => null],
        ['id' => 'layer-sweep-raid-liquidity', 'help' => 'sweep-raid-liquidity', 'route' => 'bots.indicators.sweep-raid-liquidity.edit', 'label' => 'Sweep / Raid Liquidity', 'section' => null],
        ['id' => 'layer-smc-flow', 'help' => 'smc-flow', 'route' => 'bots.indicators.smc-flow.edit', 'label' => 'SMC Flow (UT Bot)', 'section' => null],

        ['id' => 'layer-bollinger', 'help' => 'bollinger', 'route' => 'bots.indicators.bollinger.edit', 'label' => 'Bollinger', 'section' => null],
        ['id' => 'layer-keltner', 'help' => 'keltner', 'route' => 'bots.indicators.keltner.edit', 'label' => 'Keltner', 'section' => null],
        ['id' => 'layer-envelops', 'help' => 'envelops', 'route' => 'bots.indicators.envelops.edit', 'label' => 'Envelops', 'section' => null],
        ['id' => 'layer-price-channel', 'help' => 'price-channel', 'route' => 'bots.indicators.price-channel.edit', 'label' => 'Price Channel', 'section' => null],

        ['id' => 'layer-sma', 'help' => 'sma', 'route' => 'bots.indicators.sma.edit', 'label' => 'SMA', 'section' => null],
        ['id' => 'layer-ema', 'help' => 'ema', 'route' => 'bots.indicators.ema.edit', 'label' => 'EMA', 'section' => null],
        ['id' => 'layer-vwma', 'help' => 'vwma', 'route' => 'bots.indicators.vwma.edit', 'label' => 'VWMA', 'section' => null],
        ['id' => 'layer-hma', 'help' => 'hma', 'route' => 'bots.indicators.hma.edit', 'label' => 'HMA', 'section' => null],
        ['id' => 'layer-alligator', 'help' => 'alligator', 'route' => 'bots.indicators.alligator.edit', 'label' => 'Alligator', 'section' => null],

        ['id' => 'layer-supertrend', 'help' => 'supertrend', 'route' => 'bots.indicators.supertrend.edit', 'label' => 'SuperTrend', 'section' => null],
        ['id' => 'layer-nrtr', 'help' => 'nrtr', 'route' => 'bots.indicators.nrtr.edit', 'label' => 'NRTR', 'section' => null],
        ['id' => 'layer-parabolic-sar', 'help' => 'parabolic-sar', 'route' => 'bots.indicators.parabolic-sar.edit', 'label' => 'Parabolic SAR', 'section' => null],
        ['id' => 'layer-ichimoku', 'help' => 'ichimoku', 'route' => 'bots.indicators.ichimoku.edit', 'label' => 'Ichimoku', 'section' => null],

        ['id' => 'layer-adx', 'help' => 'adx', 'route' => 'bots.indicators.adx.edit', 'label' => 'ADX', 'section' => null],
        ['id' => 'layer-aroon', 'help' => 'aroon', 'route' => 'bots.indicators.aroon.edit', 'label' => 'Aroon', 'section' => null],

        ['id' => 'layer-rsi', 'help' => 'rsi', 'route' => 'bots.indicators.rsi.edit', 'label' => 'RSI', 'section' => null],
        ['id' => 'layer-stochastic', 'help' => 'stochastic', 'route' => 'bots.indicators.stochastic.edit', 'label' => 'Stochastic', 'section' => null],
        ['id' => 'layer-cci', 'help' => 'cci', 'route' => 'bots.indicators.cci.edit', 'label' => 'CCI', 'section' => null],
        ['id' => 'layer-williams', 'help' => 'williams', 'route' => 'bots.indicators.williams.edit', 'label' => 'Williams %R', 'section' => null],

        ['id' => 'layer-macd', 'help' => 'macd', 'route' => 'bots.indicators.macd.edit', 'label' => 'MACD', 'section' => null],
        ['id' => 'layer-ao', 'help' => 'ao', 'route' => 'bots.indicators.ao.edit', 'label' => 'AO', 'section' => null],
        ['id' => 'layer-atr', 'help' => 'atr', 'route' => 'bots.indicators.atr.edit', 'label' => 'ATR', 'section' => null],

        // Дополнительные классические индикаторы.
        ['id' => 'layer-momentum', 'help' => 'momentum', 'route' => 'bots.indicators.momentum.edit', 'label' => 'Momentum', 'section' => null],
        ['id' => 'layer-roc', 'help' => 'roc', 'route' => 'bots.indicators.roc.edit', 'label' => 'ROC', 'section' => null],
        ['id' => 'layer-osma', 'help' => 'osma', 'route' => 'bots.indicators.osma.edit', 'label' => 'OsMA', 'section' => null],
        ['id' => 'layer-trix', 'help' => 'trix', 'route' => 'bots.indicators.trix.edit', 'label' => 'TRIX', 'section' => null],
        ['id' => 'layer-cmo', 'help' => 'cmo', 'route' => 'bots.indicators.cmo.edit', 'label' => 'CMO', 'section' => null],
        ['id' => 'layer-demarker', 'help' => 'demarker', 'route' => 'bots.indicators.demarker.edit', 'label' => 'DeMarker', 'section' => null],
        ['id' => 'layer-rvi', 'help' => 'rvi', 'route' => 'bots.indicators.rvi.edit', 'label' => 'RVI', 'section' => null],
        ['id' => 'layer-ultimate', 'help' => 'ultimate', 'route' => 'bots.indicators.ultimate.edit', 'label' => 'Ultimate Oscillator', 'section' => null],
        ['id' => 'layer-smi', 'help' => 'smi', 'route' => 'bots.indicators.smi.edit', 'label' => 'SMI', 'section' => null],
        ['id' => 'layer-stddev', 'help' => 'stddev', 'route' => 'bots.indicators.stddev.edit', 'label' => 'StdDev', 'section' => null],
        ['id' => 'layer-sma-channel', 'help' => 'sma-channel', 'route' => 'bots.indicators.sma-channel.edit', 'label' => 'SMA Channel', 'section' => null],
        ['id' => 'layer-ivashov', 'help' => 'ivashov', 'route' => 'bots.indicators.ivashov.edit', 'label' => 'Ivashov Range', 'section' => null],
        ['id' => 'layer-linreg', 'help' => 'linreg', 'route' => 'bots.indicators.linreg.edit', 'label' => 'Linear Regression', 'section' => null],
        ['id' => 'layer-linreg-channel', 'help' => 'linreg-channel', 'route' => 'bots.indicators.linreg-channel.edit', 'label' => 'Linear Reg. Channel', 'section' => null],
        ['id' => 'layer-kalman', 'help' => 'kalman', 'route' => 'bots.indicators.kalman.edit', 'label' => 'Kalman Filter', 'section' => null],
        ['id' => 'layer-fractal', 'help' => 'fractal', 'route' => 'bots.indicators.fractal.edit', 'label' => 'Fractal', 'section' => null],
        ['id' => 'layer-donchian', 'help' => 'donchian', 'route' => 'bots.indicators.donchian.edit', 'label' => 'Donchian', 'section' => null],
        ['id' => 'layer-stoch-rsi', 'help' => 'stoch-rsi', 'route' => 'bots.indicators.stoch-rsi.edit', 'label' => 'Stoch RSI', 'section' => null],
        ['id' => 'layer-ac', 'help' => 'ac', 'route' => 'bots.indicators.ac.edit', 'label' => 'AC', 'section' => null],
        ['id' => 'layer-bulls-power', 'help' => 'bulls-power', 'route' => 'bots.indicators.bulls-power.edit', 'label' => 'Bulls Power', 'section' => null],
        ['id' => 'layer-bears-power', 'help' => 'bears-power', 'route' => 'bots.indicators.bears-power.edit', 'label' => 'Bears Power', 'section' => null],
        ['id' => 'layer-dpo', 'help' => 'dpo', 'route' => 'bots.indicators.dpo.edit', 'label' => 'DPO', 'section' => null],
        ['id' => 'layer-qstick', 'help' => 'qstick', 'route' => 'bots.indicators.qstick.edit', 'label' => 'QStick', 'section' => null],
        ['id' => 'layer-vh-filter', 'help' => 'vh-filter', 'route' => 'bots.indicators.vh-filter.edit', 'label' => 'VHFilter', 'section' => null],
        ['id' => 'layer-efficiency-ratio', 'help' => 'efficiency-ratio', 'route' => 'bots.indicators.efficiency-ratio.edit', 'label' => 'Efficiency Ratio', 'section' => null],
        ['id' => 'layer-ravi', 'help' => 'ravi', 'route' => 'bots.indicators.ravi.edit', 'label' => 'RAVI', 'section' => null],
        ['id' => 'layer-cog', 'help' => 'cog', 'route' => 'bots.indicators.cog.edit', 'label' => 'COG', 'section' => null],
        ['id' => 'layer-pivot-floor', 'help' => 'pivot-floor', 'route' => 'bots.indicators.pivot-floor.edit', 'label' => 'Pivot Floor', 'section' => null],
        ['id' => 'layer-mass-index', 'help' => 'mass-index', 'route' => 'bots.indicators.mass-index.edit', 'label' => 'Mass Index', 'section' => null],
        ['id' => 'layer-nrtr-watr', 'help' => 'nrtr-watr', 'route' => 'bots.indicators.nrtr-watr.edit', 'label' => 'NRTR WATR', 'section' => null],
        ['id' => 'layer-price-channel-adaptive', 'help' => 'price-channel-adaptive', 'route' => 'bots.indicators.price-channel-adaptive.edit', 'label' => 'PC Adaptive', 'section' => null],
        ['id' => 'layer-price-channel-offset', 'help' => 'price-channel-offset', 'route' => 'bots.indicators.price-channel-offset.edit', 'label' => 'PC Offset', 'section' => null],
        ['id' => 'layer-parabolic-bollinger', 'help' => 'parabolic-bollinger', 'route' => 'bots.indicators.parabolic-bollinger.edit', 'label' => 'Parabolic BB', 'section' => null],
        ['id' => 'layer-parabolic-price-channel', 'help' => 'parabolic-price-channel', 'route' => 'bots.indicators.parabolic-price-channel.edit', 'label' => 'Parabolic PC', 'section' => null],
        ['id' => 'layer-atr-channel', 'help' => 'atr-channel', 'route' => 'bots.indicators.atr-channel.edit', 'label' => 'ATR Channel', 'section' => null],
        ['id' => 'layer-offset-sma', 'help' => 'offset-sma', 'route' => 'bots.indicators.offset-sma.edit', 'label' => 'Offset SMA', 'section' => null],
        ['id' => 'layer-offset-ema', 'help' => 'offset-ema', 'route' => 'bots.indicators.offset-ema.edit', 'label' => 'Offset EMA', 'section' => null],
        ['id' => 'layer-offset-ssma', 'help' => 'offset-ssma', 'route' => 'bots.indicators.offset-ssma.edit', 'label' => 'Offset SSMA', 'section' => null],
        ['id' => 'layer-volatility-average', 'help' => 'volatility-average', 'route' => 'bots.indicators.volatility-average.edit', 'label' => 'Vol Average', 'section' => null],
        ['id' => 'layer-volatility-average-channel', 'help' => 'volatility-average-channel', 'route' => 'bots.indicators.volatility-average-channel.edit', 'label' => 'Vol Avg Channel', 'section' => null],
        ['id' => 'layer-price-oscillator', 'help' => 'price-oscillator', 'route' => 'bots.indicators.price-oscillator.edit', 'label' => 'Price Oscillator', 'section' => null],
        ['id' => 'layer-dynamic-trend-detector', 'help' => 'dynamic-trend-detector', 'route' => 'bots.indicators.dynamic-trend-detector.edit', 'label' => 'DTD', 'section' => null],
        ['id' => 'layer-adaptive-look-back', 'help' => 'adaptive-look-back', 'route' => 'bots.indicators.adaptive-look-back.edit', 'label' => 'Adaptive Look Back', 'section' => null],
        ['id' => 'layer-linreg-channel-fast', 'help' => 'linreg-channel-fast', 'route' => 'bots.indicators.linreg-channel-fast.edit', 'label' => 'LR Channel Fast', 'section' => null],
        ['id' => 'layer-volatility-candles', 'help' => 'volatility-candles', 'route' => 'bots.indicators.volatility-candles.edit', 'label' => 'Volatility Candles', 'section' => null],
        ['id' => 'layer-zigzag', 'help' => 'zigzag', 'route' => 'bots.indicators.zigzag.edit', 'label' => 'ZigZag', 'section' => null],
        ['id' => 'layer-volatility-average-twice', 'help' => 'volatility-average-twice', 'route' => 'bots.indicators.volatility-average-twice.edit', 'label' => 'Vol Avg Twice', 'section' => null],
        ['id' => 'layer-volatility-stages-aw', 'help' => 'volatility-stages-aw', 'route' => 'bots.indicators.volatility-stages-aw.edit', 'label' => 'Vol Stages AW', 'section' => null],
    ];

    if ($showLlmLevels) {
        $layerRows[] = ['id' => 'layer-llm-levels', 'help' => 'llm-levels', 'route' => null, 'label' => 'Уровни LLM', 'section' => null];
    }

    // Единый алфавитный список по названию в UI (без группировки по секциям).
    $collator = class_exists(\Collator::class) ? new \Collator('ru_RU') : null;
    usort($layerRows, static function (array $a, array $b) use ($collator): int {
        $left = (string) ($a['label'] ?? '');
        $right = (string) ($b['label'] ?? '');
        if ($collator instanceof \Collator) {
            return $collator->compare($left, $right);
        }

        return strnatcasecmp($left, $right);
    });
    foreach ($layerRows as &$layerRow) {
        $layerRow['section'] = null;
    }
    unset($layerRow);

    $gearSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3.5 w-3.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.281Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>';
@endphp

{{-- Оверлей списка индикаторов (широкий попап со скроллом). --}}
<div id="layers-dropdown-panel"
    class="hidden fixed inset-0 z-[80] flex items-start justify-center sm:items-center p-3 sm:p-6"
    role="dialog"
    aria-modal="true"
    aria-labelledby="layers-popup-title">
    {{-- Затемнение; клик закрывает попап (обрабатывается в JS). --}}
    <div class="absolute inset-0 bg-black/60 backdrop-blur-[1px]" data-layers-overlay-close></div>

    {{-- Шире окна под подробные short-описания; размер шрифта short = text-xs. --}}
    <div class="relative z-[1] flex w-full max-w-6xl max-h-[min(90vh,900px)] flex-col overflow-hidden rounded-xl border border-zinc-600 bg-[#13151a] shadow-2xl shadow-black/60"
        data-layers-popup-card>
        <div class="flex items-center justify-between gap-3 border-b border-zinc-700 bg-[#1e2028] px-4 py-3 shrink-0">
            <div>
                <h2 id="layers-popup-title" class="text-base font-semibold text-zinc-100">Индикаторы на графике</h2>
                <p class="text-[11px] text-zinc-500 mt-0.5">Включите слой · клик по описанию или «?» — простая справка с картинками · ⚙ настройки@if (! empty($tradingLayerIds)) · метка «торговля» — те же настройки, что у бота на вход@endif</p>
            </div>
            <button type="button"
                class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-zinc-600 bg-zinc-800 text-zinc-300 hover:bg-zinc-700 hover:text-white"
                data-layers-overlay-close
                aria-label="Закрыть">✕</button>
        </div>

        {{-- Прокручиваемый список: слева контролы, справа описание (кликабельно → справка). --}}
        <div class="flex-1 min-h-0 overflow-y-auto overscroll-contain px-2 py-2 sm:px-3">
            @foreach ($layerRows as $row)
                @php
                    $help = $helpCatalog[$row['help']] ?? null;
                    $short = $help['short'] ?? '';
                    $title = $help['title'] ?? $row['label'];
                    $isTradingLayer = in_array($row['id'], $tradingLayerIds, true);
                @endphp

                @if (! empty($row['section']))
                    <div class="sticky top-0 z-[1] bg-[#13151a]/90 backdrop-blur-sm px-2 pt-3 pb-1.5 text-[10px] font-semibold uppercase tracking-wider text-zinc-500">
                        {{ $row['section'] }}
                    </div>
                @endif

                <div class="grid grid-cols-1 gap-2 rounded-lg px-2 py-2 hover:bg-zinc-800/50 sm:grid-cols-[minmax(11rem,14rem)_minmax(0,1fr)_auto] sm:items-start sm:gap-3 {{ $isTradingLayer ? 'bg-emerald-950/25 ring-1 ring-emerald-800/40' : '' }}">
                    <label for="{{ $row['id'] }}" class="flex min-w-0 items-center gap-2 text-sm text-zinc-200 cursor-pointer sm:pt-0.5">
                        <input type="checkbox"
                            id="{{ $row['id'] }}"
                            class="rounded border-zinc-600 bg-zinc-800 text-blue-500 shrink-0"
                            @if(($row['id'] ?? '') === 'layer-llm-levels') checked @endif>
                        <span class="truncate font-medium">{{ $row['label'] }}</span>
                        @if ($isTradingLayer)
                            <span class="shrink-0 rounded border border-emerald-700/60 bg-emerald-950/70 px-1 py-px text-[9px] font-semibold uppercase tracking-wide text-emerald-400" title="Бот входит по этому индикатору с теми же настройками">торговля</span>
                        @endif
                    </label>

                    {{-- Клик по тексту открывает ту же справку, что и «?». --}}
                    <button type="button"
                        class="text-left text-xs leading-snug text-zinc-400 sm:pr-1 hover:text-sky-300 underline decoration-zinc-600 decoration-dotted underline-offset-2 transition-colors"
                        data-indicator-help="{{ $row['help'] }}"
                        title="Открыть простую справку: {{ $title }}">{{ $short }}</button>

                    <div class="flex items-center gap-1.5 shrink-0 justify-end">
                        @if ($row['route'])
                            @if ($settingsBot)
                                <a href="{{ route($row['route'], $settingsBot) }}"
                                    class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-zinc-700 bg-zinc-800/90 text-zinc-400 hover:bg-zinc-700 hover:text-zinc-100"
                                    data-indicator-settings-modal
                                    data-indicator-settings-title="{{ $title }}"
                                    title="Настройки: {{ $title }}"
                                    aria-label="Настройки {{ $title }}">{!! $gearSvg !!}</a>
                            @else
                                <a href="{{ route('bots.index') }}"
                                    class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-zinc-700 bg-zinc-800/40 text-zinc-500 hover:bg-zinc-800 hover:text-zinc-300"
                                    title="Создайте бота — настройки привязаны к боту"
                                    aria-label="К списку ботов">{!! $gearSvg !!}</a>
                            @endif
                        @else
                            <span class="inline-flex h-8 w-8" aria-hidden="true"></span>
                        @endif

                        <button type="button"
                            class="inline-flex h-8 min-w-[2rem] items-center justify-center rounded-md border border-sky-800/60 bg-sky-950/50 px-2 text-xs font-semibold text-sky-300 hover:bg-sky-900/60 hover:text-sky-100"
                            data-indicator-help="{{ $row['help'] }}"
                            title="Как пользоваться: {{ $title }}"
                            aria-label="Справка по {{ $title }}">?</button>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>

{{-- Второе попап-окно: простая справка + схемы «что происходит». --}}
<div id="indicator-help-modal"
    class="hidden fixed inset-0 z-[90] flex items-start justify-center sm:items-center p-3 sm:p-6"
    role="dialog"
    aria-modal="true"
    aria-labelledby="indicator-help-title">
    <div class="absolute inset-0 bg-black/70" data-help-overlay-close></div>
    <div class="relative z-[1] flex w-full max-w-3xl max-h-[min(92vh,880px)] flex-col overflow-hidden rounded-xl border border-zinc-500 bg-[#0f1117] shadow-2xl"
        data-help-popup-card>
        <div class="flex items-start justify-between gap-3 border-b border-zinc-700 bg-[#1a1d26] px-4 py-3 shrink-0">
            <div class="min-w-0">
                <p class="text-[10px] uppercase tracking-wider text-zinc-500" id="indicator-help-group"></p>
                <h3 id="indicator-help-title" class="text-lg font-semibold text-zinc-50 truncate"></h3>
            </div>
            <button type="button"
                class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-zinc-600 bg-zinc-800 text-zinc-300 hover:bg-zinc-700"
                data-help-overlay-close
                aria-label="Закрыть справку">✕</button>
        </div>
        <div class="flex-1 min-h-0 overflow-y-auto px-4 py-4 space-y-5 text-sm text-zinc-300">
            <section>
                <h4 class="text-xs font-semibold uppercase tracking-wider text-emerald-400/90 mb-1.5">Простыми словами</h4>
                <p id="indicator-help-shows" class="leading-relaxed text-zinc-200"></p>
            </section>
            <section>
                <h4 class="text-xs font-semibold uppercase tracking-wider text-sky-400/90 mb-1.5">На что смотреть</h4>
                <p id="indicator-help-how" class="leading-relaxed text-zinc-200"></p>
            </section>
            {{-- Скрыта для остальных индикаторов; появляется только если в каталоге
                 есть glossary — например, чтобы расшифровать метки оверлея SRL. --}}
            <section id="indicator-help-glossary-wrap" class="hidden">
                <h4 class="text-xs font-semibold uppercase tracking-wider text-amber-400/90 mb-2">Легенда оверлея</h4>
                <div class="overflow-x-auto rounded-lg border border-zinc-700">
                    <table class="w-full min-w-[32rem] text-left text-xs">
                        <thead class="bg-zinc-800/80 text-zinc-300">
                            <tr>
                                <th scope="col" class="px-3 py-2 font-semibold">Метка</th>
                                <th scope="col" class="px-3 py-2 font-semibold">Что означает</th>
                            </tr>
                        </thead>
                        <tbody id="indicator-help-glossary" class="divide-y divide-zinc-800 text-zinc-200"></tbody>
                    </table>
                </div>
            </section>
            {{-- Схемы-картинки (SVG), объясняют сценарий без жаргона. --}}
            <section id="indicator-help-diagrams-wrap" class="hidden">
                <h4 class="text-xs font-semibold uppercase tracking-wider text-violet-400/90 mb-2">Картинки: что происходит</h4>
                <div id="indicator-help-diagrams" class="grid gap-3 sm:grid-cols-2"></div>
            </section>
            <section>
                <h4 class="text-xs font-semibold uppercase tracking-wider text-amber-400/90 mb-1.5">Житейские примеры</h4>
                <ul id="indicator-help-examples" class="list-disc space-y-2 pl-5 leading-relaxed text-zinc-300"></ul>
            </section>
        </div>
    </div>
</div>

{{-- Единый контейнер настроек: iframe открывает уже существующие формы всех
     индикаторов. Поэтому не требуется дублировать 80+ форм, CSRF и правила
     Laravel-валидации только ради другого способа показа. --}}
<div id="indicator-settings-modal"
    class="hidden fixed inset-0 z-[95] flex items-start justify-center p-2 sm:items-center sm:p-5"
    role="dialog"
    aria-modal="true"
    aria-labelledby="indicator-settings-title">
    <div class="absolute inset-0 bg-black/75" data-indicator-settings-close></div>
    <div class="relative z-[1] flex h-[min(94vh,920px)] w-full max-w-5xl flex-col overflow-hidden rounded-xl border border-zinc-600 bg-[#0f1117] shadow-2xl">
        <div class="flex items-center justify-between gap-3 border-b border-zinc-700 bg-[#1a1d26] px-4 py-3 shrink-0">
            <div class="min-w-0">
                <p class="text-[10px] uppercase tracking-wider text-zinc-500">Настройки индикатора</p>
                <h3 id="indicator-settings-title" class="truncate text-lg font-semibold text-zinc-50">Загрузка…</h3>
            </div>
            <button type="button"
                class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-zinc-600 bg-zinc-800 text-zinc-300 hover:bg-zinc-700"
                data-indicator-settings-close
                aria-label="Закрыть настройки">✕</button>
        </div>
        <iframe id="indicator-settings-frame"
            class="min-h-0 w-full flex-1 border-0 bg-[#1c202e]"
            title="Настройки индикатора"></iframe>
    </div>
</div>

{{-- Каталог справки для JS (второй попап). --}}
<script type="application/json" id="indicator-help-catalog">@json($helpCatalog)</script>
@php
    // Мета слоёв для лейблов на графике (настройки / снять слой) — как иконки у подписи на Pocket Option.
    $layerMetaForJs = [];
    foreach ($layerRows as $row) {
        $layerMetaForJs[$row['id']] = [
            'label' => $row['label'],
            'help' => $row['help'],
            'settingsUrl' => ($row['route'] && $settingsBot)
                ? route($row['route'], $settingsBot)
                : null,
        ];
    }
@endphp
<script>
    // Глобальная карта: id чекбокса слоя → URL настроек (для карандаша на лейбле графика).
    window.PLATFORM_INDICATOR_LAYER_META = @json($layerMetaForJs);
</script>
