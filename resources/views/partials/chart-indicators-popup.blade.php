{{--
  Широкий попап слоёв графика: чекбокс + ⚙ + краткое описание + «?» (детальная справка).
  Ожидает: $indicatorBot (Bot|null), $showLlmLevels (bool, optional).
--}}
@php
    use App\Support\ChartIndicatorHelp;

    $helpCatalog = ChartIndicatorHelp::catalog();
    $settingsBot = $indicatorBot ?? null;
    $showLlmLevels = $showLlmLevels ?? false;

    /**
     * Строка слоя: id чекбокса, ключ help, route name (nullable), заголовок UI.
     * @var list<array{id:string, help:string, route:?string, label:string, section:?string}>
     */
    $layerRows = [
        ['id' => 'layer-ob', 'help' => 'ob', 'route' => 'bots.indicators.order-blocks.edit', 'label' => 'Ордерблоки', 'section' => 'Структура рынка'],
        ['id' => 'layer-fvg', 'help' => 'fvg', 'route' => 'bots.indicators.fvg.edit', 'label' => 'Имбалансы', 'section' => null],
        ['id' => 'layer-pa-dgt', 'help' => 'pa-dgt', 'route' => 'bots.indicators.price-action-dgt.edit', 'label' => 'Price Action (DGT)', 'section' => null],
        ['id' => 'layer-liquidity-swings', 'help' => 'liquidity-swings', 'route' => 'bots.indicators.liquidity-swings.edit', 'label' => 'Liquidity Swings', 'section' => null],
        ['id' => 'layer-smc-flow', 'help' => 'smc-flow', 'route' => 'bots.indicators.smc-flow.edit', 'label' => 'SMC Flow (UT Bot)', 'section' => null],

        ['id' => 'layer-bollinger', 'help' => 'bollinger', 'route' => 'bots.indicators.bollinger.edit', 'label' => 'Bollinger', 'section' => 'Классика OsEngine'],
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

        // 16 новых классических индикаторов OsEngine.
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
    ];

    if ($showLlmLevels) {
        $layerRows[] = ['id' => 'layer-llm-levels', 'help' => 'llm-levels', 'route' => null, 'label' => 'Уровни LLM', 'section' => 'Аналитика'];
    }

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

    <div class="relative z-[1] flex w-full max-w-4xl max-h-[min(88vh,820px)] flex-col overflow-hidden rounded-xl border border-zinc-600 bg-[#13151a] shadow-2xl shadow-black/60"
        data-layers-popup-card>
        <div class="flex items-center justify-between gap-3 border-b border-zinc-700 bg-[#1e2028] px-4 py-3 shrink-0">
            <div>
                <h2 id="layers-popup-title" class="text-base font-semibold text-zinc-100">Индикаторы на графике</h2>
                <p class="text-[11px] text-zinc-500 mt-0.5">Включите слой · ⚙ настройки · ? подробная справка</p>
            </div>
            <button type="button"
                class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-zinc-600 bg-zinc-800 text-zinc-300 hover:bg-zinc-700 hover:text-white"
                data-layers-overlay-close
                aria-label="Закрыть">✕</button>
        </div>

        {{-- Прокручиваемый список: слева контролы, справа краткое описание. --}}
        <div class="flex-1 min-h-0 overflow-y-auto overscroll-contain px-2 py-2 sm:px-3">
            @foreach ($layerRows as $row)
                @php
                    $help = $helpCatalog[$row['help']] ?? null;
                    $short = $help['short'] ?? '';
                    $title = $help['title'] ?? $row['label'];
                @endphp

                @if (! empty($row['section']))
                    <div class="sticky top-0 z-[1] bg-[#13151a]/90 backdrop-blur-sm px-2 pt-3 pb-1.5 text-[10px] font-semibold uppercase tracking-wider text-zinc-500">
                        {{ $row['section'] }}
                    </div>
                @endif

                <div class="grid grid-cols-1 gap-2 rounded-lg px-2 py-2 hover:bg-zinc-800/50 sm:grid-cols-[minmax(11rem,14rem)_minmax(0,1fr)_auto] sm:items-center sm:gap-3">
                    <label for="{{ $row['id'] }}" class="flex min-w-0 items-center gap-2 text-sm text-zinc-200 cursor-pointer">
                        <input type="checkbox"
                            id="{{ $row['id'] }}"
                            class="rounded border-zinc-600 bg-zinc-800 text-blue-500 shrink-0"
                            @if(($row['id'] ?? '') === 'layer-llm-levels') checked @endif>
                        <span class="truncate font-medium">{{ $row['label'] }}</span>
                    </label>

                    <p class="text-xs leading-snug text-zinc-400 sm:pr-1">{{ $short }}</p>

                    <div class="flex items-center gap-1.5 shrink-0 justify-end">
                        @if ($row['route'])
                            @if ($settingsBot)
                                <a href="{{ route($row['route'], $settingsBot) }}"
                                    class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-zinc-700 bg-zinc-800/90 text-zinc-400 hover:bg-zinc-700 hover:text-zinc-100"
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

{{-- Второе попап-окно: подробная справка по выбранному индикатору. --}}
<div id="indicator-help-modal"
    class="hidden fixed inset-0 z-[90] flex items-start justify-center sm:items-center p-3 sm:p-6"
    role="dialog"
    aria-modal="true"
    aria-labelledby="indicator-help-title">
    <div class="absolute inset-0 bg-black/70" data-help-overlay-close></div>
    <div class="relative z-[1] flex w-full max-w-2xl max-h-[min(90vh,760px)] flex-col overflow-hidden rounded-xl border border-zinc-500 bg-[#0f1117] shadow-2xl"
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
        <div class="flex-1 min-h-0 overflow-y-auto px-4 py-4 space-y-4 text-sm text-zinc-300">
            <section>
                <h4 class="text-xs font-semibold uppercase tracking-wider text-emerald-400/90 mb-1.5">Что показывает</h4>
                <p id="indicator-help-shows" class="leading-relaxed text-zinc-300"></p>
            </section>
            <section>
                <h4 class="text-xs font-semibold uppercase tracking-wider text-sky-400/90 mb-1.5">Как пользоваться</h4>
                <p id="indicator-help-how" class="leading-relaxed text-zinc-300"></p>
            </section>
            <section>
                <h4 class="text-xs font-semibold uppercase tracking-wider text-amber-400/90 mb-1.5">Примеры на графике</h4>
                <ul id="indicator-help-examples" class="list-disc space-y-2 pl-5 leading-relaxed text-zinc-300"></ul>
            </section>
        </div>
    </div>
</div>

{{-- Каталог справки для JS (второй попап). --}}
<script type="application/json" id="indicator-help-catalog">@json($helpCatalog)</script>
