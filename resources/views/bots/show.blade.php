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
    /* Панель анализа над графиком: длинный ответ LLM прокручивается, график остаётся видимым. */
    #bot-analysis-body {
        scrollbar-gutter: stable;
        scrollbar-width: thin;
        scrollbar-color: #52525b #27272a;
    }
    #bot-analysis-body::-webkit-scrollbar { width: 8px; }
    #bot-analysis-body::-webkit-scrollbar-track { background: #27272a; }
    #bot-analysis-body::-webkit-scrollbar-thumb { background: #52525b; border-radius: 4px; }
    /* Свернутая панель: только шапка (summary + meta), график получает больше высоты. */
    #bot-analysis-panel.bot-analysis-collapsed {
        max-height: none;
    }
    #bot-analysis-panel.bot-analysis-collapsed #bot-analysis-body {
        display: none;
    }
    #bot-analysis-panel.bot-analysis-collapsed #bot-analysis-meta {
        display: -webkit-box;
        -webkit-box-orient: vertical;
        -webkit-line-clamp: 1;
        overflow: hidden;
    }
    #bot-analysis-panel.bot-analysis-collapsed #bot-analysis-header {
        cursor: pointer;
    }
    /*
     * Слои внутри #chart-wrapper (снизу вверх):
     * 1) canvas LW Charts (свечи),
     * 2) DOM-оверлеи анализа (уровни LLM, OB/FVG, SMC…),
     * 3) HTML-кроссхейр — всегда поверх оверлеев, подписи цены/времени не перекрываются.
     */
    #chart-wrapper {
        isolation: isolate;
    }
    /* LWC v5: корень .tv-lightweight-charts; старые сборки могли класть table сразу в wrapper. */
    #chart-wrapper > .tv-lightweight-charts,
    #chart-wrapper > table {
        position: relative;
        z-index: 1;
    }
    #chart-wrapper .chart-dom-overlay {
        z-index: 2;
    }
    /* Карандаш/× на подписях индикаторов: выше анализа, ниже кроссхейра. */
    #chart-wrapper #classic-indicator-labels {
        z-index: 4;
    }
    #bot-crosshair-overlay {
        z-index: 5;
    }
    #bot-time-ruler-overlay {
        z-index: 4;
    }
    #bot-crosshair-hline {
        /* Как нативный кроссхейр LWC на главной: прерывистая линия. */
        border-top: 1px dashed #94a3b8;
    }
    #bot-crosshair-vline {
        border-left: 1px dashed #94a3b8;
    }
    .bot-crosshair-axis-label {
        font-size: 11px;
        line-height: 1.2;
        color: #f4f4f5;
        background: #334155;
        padding: 2px 6px;
        border-radius: 2px;
        text-align: center;
        white-space: nowrap;
        pointer-events: none;
    }
    /* Оверлей сделок не перехватывает мышь — кроссхейр LWC и клик идут на canvas. */
    #trade-duration-overlay,
    #trade-duration-overlay * {
        pointer-events: none !important;
    }
</style>
<span class="sr-only pointer-events-none bg-blue-900/40 bg-blue-900/50 bg-stone-800/45 text-stone-200" aria-hidden="true"></span>
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
                        class="instrument-btn w-full text-left px-4 py-3 hover:bg-zinc-800 focus:bg-zinc-800 focus:outline-none transition-colors {{ $isOpenTradeInstrument ? 'bg-stone-800/45' : '' }}"
                        data-instrument="{{ $inst->instrument }}"
                        data-display-name="{{ e($inst->display_name ?? $inst->instrument) }}"
                        data-search="{{ mb_strtolower(($inst->display_name ?? $inst->instrument).' '.$inst->instrument) }}"
                        data-open-trade="{{ $isOpenTradeInstrument ? '1' : '0' }}">
                        <div class="flex items-center justify-between gap-2">
                            <div class="flex min-w-0 flex-1 items-center gap-1.5">
                                @php($instPayout = $inst->current_payout !== null ? (float) $inst->current_payout : null)
                                @php($scanDot = \App\Support\BotScanProgress::dotColor($bot->id, $inst->instrument, $instPayout, (float) ($scanMinPayout ?? 80)))
                                @if($scanDot !== 'none')
                                <span
                                    class="instrument-scan-dot shrink-0 inline-block h-2 w-2 rounded-full {{ $scanDot === 'red' ? 'bg-red-500' : ($scanDot === 'green' ? 'bg-emerald-500' : 'bg-zinc-500') }}"
                                    title="{{ $scanDot === 'red' ? 'Идёт анализ' : ($scanDot === 'green' ? 'Проверено' : 'Ожидает проверки') }}"
                                    aria-hidden="true"
                                ></span>
                                @endif
                                <span class="font-medium text-zinc-100 truncate">{{ $inst->display_name ?? $inst->instrument }}</span>
                                @if($isOpenTradeInstrument && isset($nearestExpiryByInstrument[$inst->instrument]))
                                    @php($expTs = (int) $nearestExpiryByInstrument[$inst->instrument])
                                    @php($secLeft = max(0, $expTs - now()->timestamp))
                                    <span class="instrument-trade-timer shrink-0 text-xs font-mono tabular-nums text-stone-200" data-expiry-ts="{{ $expTs }}">{{ str_pad((string) intdiv($secLeft, 60), 2, '0', STR_PAD_LEFT) }}:{{ str_pad((string) ($secLeft % 60), 2, '0', STR_PAD_LEFT) }}</span>
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
        <div class="p-3 border-b border-zinc-800 bg-zinc-900/80 flex items-center gap-2">
            <div class="flex items-center gap-3 min-w-0 flex-wrap">
                <div class="flex items-center gap-2 min-w-0">
                    <h2 id="chart-title" class="font-semibold text-zinc-100 truncate">{{ $bot->name }}</h2>
                    {{-- Payout выбранной пары: те же % и цвет, что в списке инструментов слева. --}}
                    <span id="chart-title-payout" class="hidden text-sm font-medium tabular-nums shrink-0" aria-label="Процент выплаты"></span>
                </div>
                {{-- Индикатор загрузки свечей: раньше стоял рядом с крупным именем бота в шапке. --}}
                <span id="chart-loading" class="text-sm text-zinc-500 hidden">Загрузка…</span>
                {{-- Свободная шкала: общий ключ с dashboard (platform.chart.freePriceScale). --}}
                <label
                    for="bot-free-price-scale-toggle"
                    class="inline-flex items-center gap-2 cursor-pointer select-none shrink-0"
                    title="Свободная шкала цены: можно двигать график вверх/вниз мышкой. Выключите, если при уменьшении зума свечи пропадают."
                >
                    <span id="bot-free-price-scale-label" class="text-xs text-zinc-500">Своб. шкала</span>
                    <span class="relative inline-block h-5 w-9 shrink-0">
                        <input type="checkbox" id="bot-free-price-scale-toggle" class="peer sr-only">
                        <span class="block h-5 w-9 rounded-full bg-zinc-700 transition-colors peer-checked:bg-sky-600 peer-focus-visible:ring-2 peer-focus-visible:ring-sky-500/50"></span>
                        <span class="pointer-events-none absolute left-0.5 top-0.5 h-4 w-4 rounded-full bg-zinc-100 shadow transition-transform peer-checked:translate-x-4"></span>
                    </span>
                </label>
                {{-- Как на dashboard: скрыть маркеры/лучи/полосы сделок бота — чистый график. BT — отдельный чекбокс. --}}
                <label
                    for="bot-show-trades-toggle"
                    class="inline-flex items-center gap-2 cursor-pointer select-none shrink-0"
                    title="Показывать сделки этого бота на графике и в списке пар. Выключите — только свечи и индикаторы."
                >
                    <span id="bot-show-trades-label" class="text-xs text-zinc-400">Торговля</span>
                    <span class="relative inline-block h-5 w-9 shrink-0">
                        <input type="checkbox" id="bot-show-trades-toggle" class="peer sr-only" checked>
                        <span class="block h-5 w-9 rounded-full bg-zinc-700 transition-colors peer-checked:bg-emerald-600 peer-focus-visible:ring-2 peer-focus-visible:ring-emerald-500/50"></span>
                        <span class="pointer-events-none absolute left-0.5 top-0.5 h-4 w-4 rounded-full bg-zinc-100 shadow transition-transform peer-checked:translate-x-4"></span>
                    </span>
                </label>
                <div class="relative shrink-0" id="tf-dropdown-wrap">
                    <button type="button" id="tf-dropdown-btn" class="text-sm px-2 py-1 rounded border border-zinc-600 bg-zinc-800 text-zinc-200 hover:bg-zinc-700">
                        <span id="tf-dropdown-label">{{ $tradingChartTimeframeLabel ?? '1м' }}</span> ▾
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
                    {{-- У каждого слоя своя шестерёнка → отдельный якорь на странице настроек (per-indicator). --}}
                    @include('partials.chart-indicators-popup', [
                        'indicatorBot' => $bot,
                        'showLlmLevels' => (bool) ($isChartAnalystBot ?? false),
                        'tradingLayerIds' => $tradingChartLayerIds ?? [],
                    ])
                </div>
                <button type="button" id="bot-chart-preset-btn"
                    class="text-sm px-2 py-1 rounded border border-emerald-800/70 bg-emerald-950/40 text-emerald-200 hover:bg-emerald-900/50 hover:text-emerald-50 shrink-0"
                    title="Сбросить индикаторы и таймфрейм к тем, по которым бот входит в сделку">Сделать как у бота</button>
                @php($isChartAnalystBot = $isChartAnalystBot ?? false)
                <div class="flex flex-wrap items-center gap-1.5 shrink-0">
                    @if($isChartAnalystBot)
                        <label for="bot-analysis-user-question" class="sr-only">Дополнительный вопрос к анализу</label>
                        <input type="text" id="bot-analysis-user-question" maxlength="2000" autocomplete="off"
                            placeholder="Доп. вопрос (необязательно)…"
                            class="w-44 sm:w-56 rounded border border-zinc-600 bg-zinc-800 px-2 py-1 text-xs text-zinc-100 placeholder-zinc-500 focus:outline-none focus:ring-2 focus:ring-blue-500/50">
                    @endif
                    <button type="button" id="bot-analysis-btn"
                        class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">{{ $isChartAnalystBot ? 'Сделать анализ' : 'Анализ' }}</button>
                    @if($backtestSupported ?? false)
                    {{-- Отдельная вкладка /bots/{id}/backtest; маркеры BT — на этом графике (?backtest_run=). --}}
                    <a href="{{ route('bots.backtest', $bot) }}"
                        target="_blank"
                        rel="noopener noreferrer"
                        id="bot-backtest-link"
                        class="inline-flex items-center rounded border border-cyan-800/70 bg-cyan-950/40 px-2.5 py-1 text-xs font-medium text-cyan-100 shadow-sm transition-colors hover:bg-cyan-900/50 hover:text-cyan-50 focus:outline-none focus:ring-2 focus:ring-cyan-500/40"
                        title="Открыть страницу бэктеста в новой вкладке">Бэктест</a>
                    <label class="inline-flex items-center gap-1 rounded border border-zinc-700 bg-zinc-900/80 px-2 py-1 text-[11px] text-zinc-300"
                        title="Показывать маркеры сделок последнего загруженного бэктеста на текущей паре">
                        <input type="checkbox" id="bot-backtest-show-markers" class="rounded border-zinc-600 bg-zinc-800" checked>
                        BT
                    </label>
                    {{-- Выбор прогона без ухода на страницу бэктеста. --}}
                    <select id="bot-backtest-run-select"
                        class="max-w-[9rem] rounded border border-zinc-700 bg-zinc-900 px-1.5 py-1 text-[11px] text-zinc-200"
                        title="Загрузить маркеры выбранного прогона">
                        <option value="">Прогон…</option>
                    </select>
                    <span id="bot-backtest-markers-meta" class="hidden text-[11px] tabular-nums text-cyan-400/90"></span>
                    @endif
                    <a href="{{ route('bots.cards', $bot) }}"
                        class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">Карточки</a>
                    {{-- Activity доступна и аналитику (hybrid paper/signals могут появляться). --}}
                    <a href="{{ route('bots.activity', $bot) }}"
                        class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">Сигналы и сделки</a>
                    <a href="{{ route('bots.logs', $bot) }}"
                        class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">Логи и метрики</a>
                    <a href="{{ route('bots.settings', $bot) }}"
                        class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">Настройки</a>
                </div>
                {{-- Справа от кнопок: слот всегда занят (остаток пар или отсчёт до dispatch), кнопки не прыгают. --}}
                @unless($isChartAnalystBot)
                @php($scanRemaining = ($scanProgress['scan_active'] ?? false) ? ($scanProgress['scan_remaining'] ?? null) : null)
                @php($scanMinPayoutDisplay = rtrim(rtrim(number_format((float) ($scanMinPayout ?? 80), 1, '.', ''), '0'), '.'))
                @php($scanNextAt = (int) ($scanProgress['next_scan_at'] ?? 0))
                @php($scanIdle = $scanRemaining === null)
                @php($scanNextLeft = $scanNextAt > 0 ? max(0, $scanNextAt - time()) : null)
                <span
                    id="bot-scan-remaining-wrap"
                    class="shrink-0 text-xs text-zinc-400 tabular-nums"
                    aria-live="polite"
                    data-next-scan-at="{{ $scanNextAt > 0 ? $scanNextAt : '' }}"
                >
                    <span id="bot-scan-active-msg" class="{{ $scanIdle ? 'hidden' : '' }}">
                        Осталось для анализа пар (для payout &gt; <span id="bot-scan-min-payout" class="font-medium text-zinc-300">{{ $scanMinPayoutDisplay }}</span>%):
                        <span id="bot-scan-remaining-count" class="font-medium text-zinc-200">{{ $scanRemaining ?? 0 }}</span>
                    </span>
                    <span id="bot-scan-idle-msg" class="{{ $scanIdle ? '' : 'hidden' }}">
                        До следующего сканирования:
                        <span id="bot-scan-next-countdown" class="font-medium font-mono text-zinc-200">@if($scanNextLeft === null)—@else{{ sprintf('%02d:%02d', intdiv($scanNextLeft, 60), $scanNextLeft % 60) }}@endif</span>
                    </span>
                </span>
                @endunless
            </div>
        </div>
        {{-- Панель анализа над графиком: шапка фиксирована, тело с overflow-y (как в правом сайдбаре). --}}
        <div id="bot-analysis-panel" class="hidden shrink-0 flex min-h-0 max-h-[min(50vh,32rem)] flex-col overflow-hidden border-b border-zinc-800 bg-zinc-900/95 text-xs">
                <div id="bot-analysis-header" class="flex shrink-0 items-start justify-between gap-3 px-3 pt-2 pb-1">
                    <div class="min-w-0 flex-1">
                        <div id="bot-analysis-summary" class="font-medium text-zinc-200">Анализ не запрошен.</div>
                        <div id="bot-analysis-meta" class="mt-0.5 text-zinc-500 break-words"></div>
                    </div>
                    <div class="flex shrink-0 items-center gap-1.5">
                        <button type="button" id="bot-analysis-collapse-btn"
                            class="rounded border border-zinc-700 bg-zinc-800 px-2 py-0.5 text-[11px] text-zinc-300 hover:bg-zinc-700"
                            aria-expanded="true"
                            title="Свернуть текст анализа, оставить график">Свернуть</button>
                        <button type="button" id="bot-analysis-close" class="rounded border border-zinc-700 bg-zinc-800 px-2 py-0.5 text-[11px] text-zinc-300 hover:bg-zinc-700">Закрыть</button>
                    </div>
                </div>
                <div id="bot-analysis-body" class="min-h-0 flex-1 overflow-y-auto overscroll-y-contain px-3 pb-2">
                    <div id="bot-analysis-text" class="whitespace-pre-wrap break-words text-zinc-300"></div>
                    @unless($isChartAnalystBot)
                    <div id="bot-analysis-reasons-wrap" class="mt-2 hidden">
                        <div class="text-[10px] uppercase tracking-wide text-zinc-500">Причины / триггеры</div>
                        <ul id="bot-analysis-reasons" class="mt-1 list-disc space-y-1 pl-4 text-zinc-300"></ul>
                    </div>
                    <div id="bot-analysis-blockers-wrap" class="mt-2 hidden">
                        <div class="text-[10px] uppercase tracking-wide text-rose-400">Что блокирует вход</div>
                        <ul id="bot-analysis-blockers" class="mt-1 list-disc space-y-1 pl-4 text-rose-300"></ul>
                    </div>
                    @endunless
                </div>
            </div>
        @if(\App\Support\TimeDebugPanelSettings::isEnabled())
            {{-- Отладочная плашка времени: включается на /settings (app_settings), не через .env.
                 Помогает увидеть рассинхрон сервер / браузер / feed / маркеры на графике. --}}
            <div id="time-debug-panel" class="border-b border-zinc-800 bg-zinc-950/80 px-3 py-1.5 text-[11px] text-zinc-400">
                <span class="font-medium text-zinc-300">Панель отладки времени:</span>
                <span id="time-debug-server" class="ml-2">Серверное время (по последнему ответу API): —</span>
                <span id="time-debug-client" class="ml-2">Клиентское время (часы браузера): —</span>
                <span id="time-debug-feed-raw" class="ml-2">Время feed (сырое, как пришло от источника): —</span>
                <span id="time-debug-feed-effective" class="ml-2">Время feed (используемое в системе): —</span>
                <span id="time-debug-feed-ingest" class="ml-2">Время приема feed на backend (ingest): —</span>
                <span id="time-debug-feed-closed" class="ml-2">Время последней закрытой свечи из БД: —</span>
                <span id="time-debug-feed-delta" class="ml-2">Смещение feed ↔ сервер (в секундах): —</span>
                <span id="time-debug-platform" class="ml-2">Время платформы (по последнему маркеру/сигналу): —</span>
                <span id="time-debug-marker" class="ml-2">Время последнего маркера на графике: —</span>
            </div>
        @endif
        <div id="chart-container" class="flex-1 min-h-0 relative">
            <div id="chart-placeholder" class="absolute inset-0 flex items-center justify-center text-zinc-500 bg-zinc-950/90 z-0">
                Нажмите на инструмент слева, чтобы показать минутный график
            </div>
            {{-- Canvas LW Charts и DOM-оверлеи в одном контейнере: оверлеи z-1, table/canvas z-2 (кроссхейр сверху). --}}
            <div id="chart-wrapper" class="absolute inset-0 hidden z-[1]">
                <div id="trade-duration-overlay" class="chart-dom-overlay absolute inset-0 pointer-events-none overflow-hidden hidden"></div>
                <div id="structure-overlay" class="chart-dom-overlay absolute inset-0 pointer-events-none overflow-visible hidden"></div>
                <div id="liquidity-swings-overlay" class="chart-dom-overlay absolute inset-0 pointer-events-none overflow-visible hidden"></div>
                <div id="sweep-raid-liquidity-overlay" class="chart-dom-overlay absolute inset-0 pointer-events-none overflow-visible hidden"></div>
                <div id="engulfing-liquidity-overlay" class="chart-dom-overlay absolute inset-0 pointer-events-none overflow-visible hidden"></div>
                <div id="price-action-overlay" class="chart-dom-overlay absolute inset-0 pointer-events-none overflow-visible hidden"></div>
                <div id="smc-flow-overlay" class="chart-dom-overlay absolute inset-0 pointer-events-none overflow-visible hidden"></div>
                <div id="llm-levels-overlay" class="chart-dom-overlay absolute inset-0 pointer-events-none overflow-visible hidden"></div>
                {{-- PO-стиль: горизонтальные лучи открытых сделок (точка входа + линия до экспирации). --}}
                <div id="po-open-trades-overlay" class="chart-dom-overlay absolute inset-0 pointer-events-none overflow-hidden hidden"></div>
                {{-- Названия включённых индикаторов (оверлеи + лейблы pane осцилляторов). --}}
                <div id="classic-indicator-labels" class="chart-dom-overlay absolute inset-0 pointer-events-none overflow-hidden hidden"></div>
                {{-- Линейка секунд на оси времени (деления :SS при zoom). --}}
                <div id="bot-time-ruler-overlay" class="chart-dom-overlay absolute inset-0 pointer-events-none overflow-visible hidden"></div>
                {{-- Кроссхейр поверх всех DOM-оверлеев: линии + подписи цены/времени не перекрываются уровнями LLM. --}}
                <div id="bot-crosshair-overlay" class="absolute inset-0 pointer-events-none overflow-visible hidden">
                    <div id="bot-crosshair-hline" class="absolute left-0 hidden h-0 w-0"></div>
                    <div id="bot-crosshair-vline" class="absolute top-0 hidden h-0 w-0"></div>
                    <span id="bot-crosshair-price" class="bot-crosshair-axis-label absolute hidden"></span>
                    <span id="bot-crosshair-time" class="bot-crosshair-axis-label absolute hidden"></span>
                </div>
            </div>
            {{-- Binodex-style: тёмный pill с оранжевой обводкой «13s» справа от текущей свечи. --}}
            <div id="candle-timer-overlay"
                class="pointer-events-none absolute z-[6] hidden h-0 w-0 overflow-visible"
                title="До закрытия текущей свечи выбранного таймфрейма">
                <span id="candle-tf-label" class="hidden" aria-hidden="true"></span>
                <span id="candle-close-timer"
                    class="absolute left-0 top-0 z-[6] whitespace-nowrap rounded-md border border-[#ff9a3c] bg-zinc-950/95 px-1.5 py-0.5 text-[11px] font-semibold leading-none tabular-nums text-[#ff9a3c] shadow-[0_0_0_1px_rgba(0,0,0,0.35)]"
                    style="font-family: ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif;"
                    aria-live="polite"></span>
            </div>
            <div id="open-trades-overlay" class="absolute left-2 top-2 z-[8] pointer-events-none flex flex-col gap-1"></div>
        </div>
    </div>

    <aside class="w-52 shrink-0 border-l border-zinc-800 bg-zinc-950/40 overflow-hidden min-h-0 flex flex-col">
        <div class="border-b border-zinc-800 bg-zinc-900 px-2 py-2">
            {{-- Имя бота над статистикой: тот же кегль, что у «Статистика», чтобы не спорило с шапкой графика. --}}
            <div id="bot-name-badge" class="text-[10px] font-medium uppercase tracking-wide text-emerald-400 truncate" title="{{ $bot->name }}">{{ $bot->name }}</div>
            <div class="mt-1.5 text-[10px] font-medium uppercase tracking-wide text-zinc-400">Статистика</div>
            {{-- Два режима: скользящий час vs вся история; выбор хранится в localStorage на ключ бота. --}}
            <div class="mt-1.5 flex rounded border border-zinc-700 bg-zinc-950 p-0.5 text-[10px] font-medium">
                <button type="button" id="bot-stats-scope-hour" class="flex-1 rounded px-1 py-1 transition">Час</button>
                <button type="button" id="bot-stats-scope-all" class="flex-1 rounded px-1 py-1 transition">Всё время</button>
            </div>
        </div>
        <div class="p-3 flex flex-col gap-2 overflow-y-auto min-h-0">
            {{-- Компактная статистика: SSR — «всё время»; при сохранённом выборе «час» скрипт сразу подменит цифры. --}}
            <div class="grid grid-cols-2 gap-1.5">
                <div class="rounded border border-zinc-800 bg-zinc-900/50 px-2 py-1.5"><div class="text-[10px] text-zinc-400 uppercase">Всего</div><div id="bot-stat-total" class="text-sm tabular-nums">{{ $statsAll['total'] }}</div></div>
                <div class="rounded border border-zinc-800 bg-zinc-900/50 px-2 py-1.5"><div class="text-[10px] text-zinc-400 uppercase">Открыто</div><div id="bot-stat-open" class="text-sm tabular-nums">{{ $statsAll['open'] }}</div></div>
                <div class="rounded border border-zinc-800 bg-zinc-900/50 px-2 py-1.5"><div class="text-[10px] text-zinc-400 uppercase">Wins</div><div id="bot-stat-wins" class="text-sm tabular-nums text-emerald-400">{{ $statsAll['wins'] }}</div></div>
                <div class="rounded border border-zinc-800 bg-zinc-900/50 px-2 py-1.5"><div class="text-[10px] text-zinc-400 uppercase">Losses</div><div id="bot-stat-losses" class="text-sm tabular-nums text-rose-400">{{ $statsAll['losses'] }}</div></div>
                <div class="col-span-2 rounded border border-zinc-800 bg-zinc-900/50 px-2 py-1.5"><div class="text-[10px] text-zinc-400 uppercase">Winrate</div><div id="bot-stat-winrate" class="text-sm tabular-nums">{{ $statsAll['winrate'] !== null ? $statsAll['winrate'].'%' : '—' }}</div></div>
                <div class="col-span-2 rounded border border-zinc-800 bg-zinc-900/50 px-2 py-1.5">
                    <div class="text-[10px] text-zinc-400 uppercase">Winrate инструмента</div>
                    <div id="bot-stat-instrument-winrate" class="text-sm tabular-nums">{{ $statsAll['instrument_winrate'] !== null ? $statsAll['instrument_winrate'].'%' : '—' }}</div>
                </div>
            </div>
            {{-- Панель синхронизируется с выбранной парой через JS (updateSidebarActiveTrade); поля совпадают с openTradeSidebarRow() в BotController. --}}
            <div id="sidebar-active-trade-panel" class="rounded border border-amber-700/40 bg-amber-950/20 px-2 py-2 text-xs @unless($activeTradeSidebar) hidden @endunless">
                <div class="flex items-center justify-between gap-2">
                    <span class="font-medium text-amber-300">Сделка в работе</span>
                    <span id="sidebar-trade-timer" class="font-mono text-amber-200 @unless($activeTrade && $activeTradeSecondsLeft !== null) hidden @endunless">@if($activeTrade && $activeTradeSecondsLeft !== null){{ str_pad((string) intdiv($activeTradeSecondsLeft, 60), 2, '0', STR_PAD_LEFT) }}:{{ str_pad((string) ($activeTradeSecondsLeft % 60), 2, '0', STR_PAD_LEFT) }}@endif</span>
                </div>
                <div id="sidebar-trade-detail" class="mt-2 space-y-1.5 text-[11px] leading-snug text-zinc-300">
                    <div id="sidebar-trade-headline" class="font-medium text-zinc-200">@if($activeTradeSidebar){{ $activeTradeSidebar['instrument'] }} · {{ strtoupper((string) $activeTradeSidebar['side']) }} · {{ $activeTradeSidebar['confidence_fmt'] }} confidence @endif</div>
                    <div id="sidebar-trade-times" class="text-zinc-400">@if($activeTradeSidebar)Сигнал: {{ $activeTradeSidebar['signal_at_fmt'] }} · Сделка: {{ $activeTradeSidebar['entry_at_fmt'] }}@endif</div>
                    <div id="sidebar-trade-state">@if($activeTradeSidebar){{ $activeTradeSidebar['state_line'] }}@endif</div>
                    <div id="sidebar-trade-pnl">@if($activeTradeSidebar)PnL: {{ $activeTradeSidebar['pnl_display'] ?? '—' }}@endif</div>
                    {{-- @class: условия видимости вынесены из inline-@unless, иначе Blade путается в кавычках/скобках внутри class="…". --}}
                    <div id="sidebar-trade-analysis-wrap" @class([
                        'rounded border border-zinc-800 bg-zinc-900/60 px-2 py-1.5 text-[11px] leading-relaxed',
                        'hidden' => ! $activeTradeSidebar || trim((string) ($activeTradeSidebar['analysis_text'] ?? '')) === '',
                    ])>
                        <div class="mb-1 text-[10px] uppercase tracking-wide text-zinc-500">Анализ (модель)</div>
                        <div id="sidebar-trade-analysis-text" class="whitespace-pre-wrap break-words">@if($activeTradeSidebar){{ $activeTradeSidebar['analysis_text'] }}@endif</div>
                    </div>
                    <div id="sidebar-trade-reasons-wrap" @class([
                        'text-zinc-400',
                        'hidden' => ! $activeTradeSidebar || empty($activeTradeSidebar['reasons_short'] ?? []),
                    ])>
                        <div id="sidebar-trade-reasons-text">@if($activeTradeSidebar && ! empty($activeTradeSidebar['reasons_short'])){{ implode(' · ', $activeTradeSidebar['reasons_short']) }}@endif</div>
                    </div>
                </div>
            </div>
            <a href="{{ route('bots.index') }}" class="text-sm text-blue-400 hover:text-blue-300 pt-1">← К списку ботов</a>
        </div>
    </aside>
</div>

{{-- LWC v5: addSeries(CandlestickSeries|LineSeries), createSeriesMarkers, реальные panes() для RSI/ATR --}}
@include('partials.chart-bot-vite')

@endsection

