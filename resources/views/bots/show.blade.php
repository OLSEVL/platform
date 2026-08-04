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
    #chart-wrapper > table {
        position: relative;
        z-index: 1;
    }
    #chart-wrapper .chart-dom-overlay {
        z-index: 2;
    }
    #bot-crosshair-overlay {
        z-index: 3;
    }
    #bot-crosshair-hline {
        border-top: 1px solid #94a3b8;
    }
    #bot-crosshair-vline {
        border-left: 1px solid #94a3b8;
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
                    {{-- У каждого слоя своя шестерёнка → отдельный якорь на странице настроек (per-indicator). --}}
                    @include('partials.chart-indicators-popup', [
                        'indicatorBot' => $bot,
                        'showLlmLevels' => (bool) ($isChartAnalystBot ?? false),
                    ])
                </div>
                @php($isChartAnalystBot = $isChartAnalystBot ?? false)
                @unless($isChartAnalystBot)
                @php($scanRemaining = ($scanProgress['scan_active'] ?? false) ? ($scanProgress['scan_remaining'] ?? null) : null)
                @php($scanMinPayoutDisplay = rtrim(rtrim(number_format((float) ($scanMinPayout ?? 80), 1, '.', ''), '0'), '.'))
                <span
                    id="bot-scan-remaining-wrap"
                    class="{{ $scanRemaining === null ? 'hidden' : '' }} shrink-0 text-xs text-zinc-400 tabular-nums"
                    aria-live="polite"
                >
                    Осталось для анализа пар (для payout &gt; <span id="bot-scan-min-payout" class="font-medium text-zinc-300">{{ $scanMinPayoutDisplay }}</span>%):
                    <span id="bot-scan-remaining-count" class="font-medium text-zinc-200">{{ $scanRemaining ?? 0 }}</span>
                </span>
                @endunless
                <div class="flex flex-wrap items-center gap-1.5 shrink-0">
                    @if($isChartAnalystBot)
                        <label for="bot-analysis-user-question" class="sr-only">Дополнительный вопрос к анализу</label>
                        <input type="text" id="bot-analysis-user-question" maxlength="2000" autocomplete="off"
                            placeholder="Доп. вопрос (необязательно)…"
                            class="w-44 sm:w-56 rounded border border-zinc-600 bg-zinc-800 px-2 py-1 text-xs text-zinc-100 placeholder-zinc-500 focus:outline-none focus:ring-2 focus:ring-blue-500/50">
                    @endif
                    <button type="button" id="bot-analysis-btn"
                        class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">{{ $isChartAnalystBot ? 'Сделать анализ' : 'Анализ' }}</button>
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
            </div>
            <div class="flex items-center gap-3 shrink-0">
                <span id="bot-name-badge" class="text-2xl font-semibold text-emerald-400">{{ $bot->name }}</span>
                <span id="chart-loading" class="text-sm text-zinc-500 hidden">Загрузка…</span>
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
        @if(config('app.time_debug_panel'))
            {{-- Отладочная плашка времени: помогает быстро увидеть рассинхрон между серверным временем,
                 локальными часами браузера и "временем платформы" (последний маркер/событие на графике). --}}
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
                <div id="price-action-overlay" class="chart-dom-overlay absolute inset-0 pointer-events-none overflow-visible hidden"></div>
                <div id="smc-flow-overlay" class="chart-dom-overlay absolute inset-0 pointer-events-none overflow-visible hidden"></div>
                <div id="llm-levels-overlay" class="chart-dom-overlay absolute inset-0 pointer-events-none overflow-visible hidden"></div>
                {{-- Кроссхейр поверх всех DOM-оверлеев: линии + подписи цены/времени не перекрываются уровнями LLM. --}}
                <div id="bot-crosshair-overlay" class="absolute inset-0 pointer-events-none overflow-visible hidden">
                    <div id="bot-crosshair-hline" class="absolute left-0 hidden h-0 w-0"></div>
                    <div id="bot-crosshair-vline" class="absolute top-0 hidden h-0 w-0"></div>
                    <span id="bot-crosshair-price" class="bot-crosshair-axis-label absolute hidden"></span>
                    <span id="bot-crosshair-time" class="bot-crosshair-axis-label absolute hidden"></span>
                </div>
            </div>
            <div id="candle-timer-overlay"
                class="pointer-events-none absolute z-[6] hidden h-0 w-0 overflow-visible"
                title="До закрытия текущей свечи выбранного таймфрейма (мм:сс)">
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
        <div class="border-b border-zinc-800 bg-zinc-900 px-2 py-2">
            <div class="text-[10px] font-medium uppercase tracking-wide text-zinc-400">Статистика</div>
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
@include('partials.chart-markers-helper')
@include('partials.chart-candle-smooth-helper')
<script src="https://unpkg.com/lightweight-charts@5.2.0/dist/lightweight-charts.standalone.production.js"></script>
<script>
(function() {
    const CANDLES_URL = '{{ route("candles.data") }}';
    const STRUCTURES_URL = '{{ route("bots.structures.data", $bot) }}';
    const PA_INDICATOR_URL = '{{ route("bots.indicators.data", $bot) }}';
    const INSTRUMENTS_SIDEBAR_URL = '{{ route("bots.instruments.sidebar-data", $bot) }}';
    const OPEN_TRADES_URL = '{{ route("bots.open-trades.data", $bot) }}';
    const SIGNALS_URL = '{{ route("bots.signals.data", $bot) }}';
    const TRADES_HISTORY_URL = '{{ route("bots.trades") }}';
    const BOT_ANALYSIS_URL = '{{ route("bots.analysis.data", $bot) }}';
    const BOT_IS_CHART_ANALYST = @json($isChartAnalystBot ?? false);
    const BOT_ANALYST_SMC_FLOW_ENABLED = @json($analystSmcFlowEnabled ?? false);
    const BOT_STATS_URL = '{{ route("bots.stats.data", $bot) }}';
    // Два среза для правой панели: подгружаем оба с сервера при SSR, переключатель шлёт scope= в polling.
    const STATS_SCOPE_STORAGE_KEY = 'platform.bot.show.statsScope.v1.{{ $bot->id }}';
    let STATS_BOOTSTRAP = { hour: @json($statsHour), all: @json($statsAll) };
    function readStatsScopeFromStorage() {
        try {
            const v = localStorage.getItem(STATS_SCOPE_STORAGE_KEY);
            if (v === 'hour' || v === 'all') {
                return v;
            }
        } catch (_) {}
        return 'all';
    }
    let botStatsScope = readStatsScopeFromStorage();
    // Флаг приходит из настроек бота и управляет tradingview-подобным кроссхейром на графике.
    // По умолчанию true (если ключа еще нет в config), чтобы сохранить текущее UX-поведение.
    const TV_CROSSHAIR_ENABLED = @json($tvCrosshairEnabled);
    const SELECTED_INSTRUMENT_KEY = 'platform.bot.show.selectedInstrument.{{ $bot->id }}';
    // Увеличенное окно истории:
    // - LIMIT: сколько свечей максимум запрашиваем у API за один вызов;
    // - WINDOW_SEC: базовый диапазон времени (в секундах), который показываем даже без сигналов.
    const LIMIT = 3000;
    const WINDOW_SEC = 259200;
    // Poll — узкое окно хвоста (как на dashboard), чтобы не перетирать историю и не гонять 3000 баров каждые 500ms.
    const POLL_WINDOW_SEC = 5 * 60;
    const POLL_LIMIT = 32;
    const M1_COUNTDOWN_SECONDS = 60;
    let m1CloseCountdownAnchorSec = null;
    let m1CloseCountdownAnchorPerfMs = null;
    let tfCloseCountdownAnchorSec = null;
    let tfCloseCountdownAnchorPerfMs = null;
    // Таймфреймы в UI: legacy минуты (1,2,3...), и секундные ID (S5/S15/S30 на 4xxx).
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

    function applyM1CountdownFromPayload(payload) {
        if (!payload || typeof payload.m1_seconds_to_close !== 'number' || !Number.isFinite(payload.m1_seconds_to_close)) {
            m1CloseCountdownAnchorSec = null;
            m1CloseCountdownAnchorPerfMs = null;
            return;
        }
        m1CloseCountdownAnchorSec = Math.max(0, Math.min(M1_COUNTDOWN_SECONDS, payload.m1_seconds_to_close));
        m1CloseCountdownAnchorPerfMs = performance.now();
    }

    /** Микро-инструменты (LBP/USD OTC ~0.0000057): precision/minMove с API, иначе свечи схлопываются. */
    function applyPriceScaleFromPayload(payload) {
        if (!candlestickSeries || !payload || !payload.price_scale) return;
        const precision = Number(payload.price_scale.precision);
        const minMove = Number(payload.price_scale.min_move);
        if (!Number.isFinite(precision) || !Number.isFinite(minMove) || precision <= 0 || minMove <= 0) return;
        botChartPricePrecision = precision;
        try {
            candlestickSeries.applyOptions({
                priceFormat: { type: 'price', precision, minMove },
            });
        } catch (e) {
            console.error('[bot chart] price scale', e);
        }
    }

    function m1CountdownLeftNow() {
        if (m1CloseCountdownAnchorSec === null || m1CloseCountdownAnchorPerfMs === null) return null;
        const elapsed = (performance.now() - m1CloseCountdownAnchorPerfMs) / 1000;
        return Math.max(0, Math.min(M1_COUNTDOWN_SECONDS, m1CloseCountdownAnchorSec - elapsed));
    }
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

    const CANDLE_TIMER_GAP_T = 3;

    let signals = @json($chartSignals);
    const initialInstrument = @json($initialInstrument);
    const botName = @json($bot->name);
    // Серверный seed активной сделки для стартового кадра:
    // если /open-trades/data временно вернул 0, но на SSR есть "Сделка в работе",
    // используем этот объект как fallback для визуализации голубой полосы.
    const ssrActiveTradeSeed = @json($activeTradeSidebar);
    // targetTradeId приходит из страницы "Сигналы и сделки" (?trade_id=...).
    // Используем его, чтобы открыть правильный инструмент и визуально выделить эту сделку на графике.
    const targetTradeId = Number(new URLSearchParams(window.location.search).get('trade_id') || 0) || null;

    const chartPlaceholder = document.getElementById('chart-placeholder');
    const chartWrapper = document.getElementById('chart-wrapper');
    const tradeDurationOverlay = document.getElementById('trade-duration-overlay');
    const structureOverlay = document.getElementById('structure-overlay');
    const liquiditySwingsOverlay = document.getElementById('liquidity-swings-overlay');
    const priceActionOverlay = document.getElementById('price-action-overlay');
    const smcFlowOverlay = document.getElementById('smc-flow-overlay');
    const llmLevelsOverlay = document.getElementById('llm-levels-overlay');
    const botCrosshairOverlay = document.getElementById('bot-crosshair-overlay');
    const botCrosshairHLine = document.getElementById('bot-crosshair-hline');
    const botCrosshairVLine = document.getElementById('bot-crosshair-vline');
    const botCrosshairPrice = document.getElementById('bot-crosshair-price');
    const botCrosshairTime = document.getElementById('bot-crosshair-time');
    const layerLlmLevels = document.getElementById('layer-llm-levels');
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
    // 13 новых OsEngine OHLC-индикаторов (Donchian … Mass Index).
    const layerDonchian = document.getElementById('layer-donchian');
    const layerStochRsi = document.getElementById('layer-stoch-rsi');
    const layerAc = document.getElementById('layer-ac');
    const layerBullsPower = document.getElementById('layer-bulls-power');
    const layerBearsPower = document.getElementById('layer-bears-power');
    const layerDpo = document.getElementById('layer-dpo');
    const layerQstick = document.getElementById('layer-qstick');
    const layerVhFilter = document.getElementById('layer-vh-filter');
    const layerEfficiencyRatio = document.getElementById('layer-efficiency-ratio');
    const layerRavi = document.getElementById('layer-ravi');
    const layerCog = document.getElementById('layer-cog');
    const layerPivotFloor = document.getElementById('layer-pivot-floor');
    const layerMassIndex = document.getElementById('layer-mass-index');
    // Priority-1 OsEngine индикаторы.
    const layerNrtrWatr = document.getElementById('layer-nrtr-watr');
    const layerPriceChannelAdaptive = document.getElementById('layer-price-channel-adaptive');
    const layerPriceChannelOffset = document.getElementById('layer-price-channel-offset');
    const layerParabolicBollinger = document.getElementById('layer-parabolic-bollinger');
    const layerParabolicPriceChannel = document.getElementById('layer-parabolic-price-channel');
    const layerAtrChannel = document.getElementById('layer-atr-channel');
    const layerOffsetSma = document.getElementById('layer-offset-sma');
    const layerOffsetEma = document.getElementById('layer-offset-ema');
    const layerOffsetSsma = document.getElementById('layer-offset-ssma');
    const layerVolatilityAverage = document.getElementById('layer-volatility-average');
    const layerVolatilityAverageChannel = document.getElementById('layer-volatility-average-channel');
    const layerPriceOscillator = document.getElementById('layer-price-oscillator');
    const layerDynamicTrendDetector = document.getElementById('layer-dynamic-trend-detector');
    const layerAdaptiveLookBack = document.getElementById('layer-adaptive-look-back');
    const layerLinRegChannelFast = document.getElementById('layer-linreg-channel-fast');
    const layerVolatilityCandles = document.getElementById('layer-volatility-candles');
    const layerZigZag = document.getElementById('layer-zigzag');
    const layerVolatilityAverageTwice = document.getElementById('layer-volatility-average-twice');
    const layerVolatilityStagesAw = document.getElementById('layer-volatility-stages-aw');
    const instrumentSearchInput = document.getElementById('instrument-search');
    const instrumentSearchClearBtn = document.getElementById('instrument-search-clear');
    const botAnalysisBtn = document.getElementById('bot-analysis-btn');
    const botAnalysisPanel = document.getElementById('bot-analysis-panel');
    const botAnalysisBody = document.getElementById('bot-analysis-body');
    const botAnalysisHeader = document.getElementById('bot-analysis-header');
    const botAnalysisCollapseBtn = document.getElementById('bot-analysis-collapse-btn');
    const botAnalysisCloseBtn = document.getElementById('bot-analysis-close');
    const botAnalysisSummary = document.getElementById('bot-analysis-summary');
    const botAnalysisMeta = document.getElementById('bot-analysis-meta');
    const botAnalysisText = document.getElementById('bot-analysis-text');
    const botAnalysisReasonsWrap = document.getElementById('bot-analysis-reasons-wrap');
    const botAnalysisReasons = document.getElementById('bot-analysis-reasons');
    const botAnalysisBlockersWrap = document.getElementById('bot-analysis-blockers-wrap');
    const botAnalysisBlockers = document.getElementById('bot-analysis-blockers');
    const timeDebugPanel = document.getElementById('time-debug-panel');
    const timeDebugServer = document.getElementById('time-debug-server');
    const timeDebugClient = document.getElementById('time-debug-client');
    const timeDebugFeedRaw = document.getElementById('time-debug-feed-raw');
    const timeDebugFeedEffective = document.getElementById('time-debug-feed-effective');
    const timeDebugFeedIngest = document.getElementById('time-debug-feed-ingest');
    const timeDebugFeedClosed = document.getElementById('time-debug-feed-closed');
    const timeDebugFeedDelta = document.getElementById('time-debug-feed-delta');
    const timeDebugPlatform = document.getElementById('time-debug-platform');
    const timeDebugMarker = document.getElementById('time-debug-marker');
    // Настройки слоёв графика — отдельно для каждого бота (общий ключ v5 тянул smcFlow с другого бота).
    const LAYER_PREFS_KEY = 'platform.bot.show.chartLayers.v7.bot.{{ $bot->id }}';
    let lastPaPayload = null;
    let lastLsPayload = null;
    let lastSmcPayload = null;
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
    let lastDonchianPayload = null;
    let lastStochRsiPayload = null;
    let lastAcPayload = null;
    let lastBullsPowerPayload = null;
    let lastBearsPowerPayload = null;
    let lastDpoPayload = null;
    let lastQstickPayload = null;
    let lastVhFilterPayload = null;
    let lastEfficiencyRatioPayload = null;
    let lastRaviPayload = null;
    let lastCogPayload = null;
    let lastPivotFloorPayload = null;
    let lastMassIndexPayload = null;
    // Priority-1 payloads.
    let lastNrtrWatrPayload = null;
    let lastPriceChannelAdaptivePayload = null;
    let lastPriceChannelOffsetPayload = null;
    let lastParabolicBollingerPayload = null;
    let lastParabolicPriceChannelPayload = null;
    let lastAtrChannelPayload = null;
    let lastOffsetSmaPayload = null;
    let lastOffsetEmaPayload = null;
    let lastOffsetSsmaPayload = null;
    let lastVolatilityAveragePayload = null;
    let lastVolatilityAverageChannelPayload = null;
    let lastPriceOscillatorPayload = null;
    let lastDynamicTrendDetectorPayload = null;
    let lastAdaptiveLookBackPayload = null;
    let lastLinRegChannelFastPayload = null;
    let lastVolatilityCandlesPayload = null;
    let lastZigZagPayload = null;
    let lastVolatilityAverageTwicePayload = null;
    let lastVolatilityStagesAwPayload = null;
    /** Line-series handles для классики OsEngine (пересоздаём при apply). */
    let classicLineSeries = {};
    let rsiLevelLines = [];
    /** Индексы pane осцилляторов (после pane 0 = свечи); сбрасываем при clear. */
    let classicOscPaneCount = 0;

    let chart = null;
    let candlestickSeries = null;
    /** LWC v5: маркеры через createSeriesMarkers, не series.setMarkers. */
    let candlestickMarkers = null;
    /**
     * Плавный close последней свечи (как на dashboard): target из poll, визуал — RAF lerp.
     */
    let candleSmooth = null;
    /** Точность подписи цены кроссхейра (синхронизируется с price_scale API). */
    let botChartPricePrecision = 5;
    let currentInstrument = null;
    let currentDisplayName = '';
    let currentTimeframeMinutes = 1;
    let oldestLoadedTime = null;
    let allChartData = [];
    let loadingMore = false;
    let refreshInterval = null;
    let instrumentListRefreshInterval = null;
    // Счётчики отменяют устаревшие ответы /candles при быстрой смене инструмента и при наложении poll-запросов.
    let chartLoadSeq = 0;
    let pollSeq = 0;
    // То же для /indicators/data: расчёт тяжёлый, без seq гонки clear+redraw оставляли пустой график.
    let indicatorFetchSeq = 0;
    // Не чаще одного пересчёта индикаторов в этот интервал (кроме явного toggle слоя).
    let lastIndicatorFetchAt = 0;
    const INDICATOR_MIN_REFETCH_MS = 2000;
    let openTradesFetchInterval = null;
    let openTradesTickInterval = null;
    let signalsRefreshInterval = null;
    let botStatsRefreshInterval = null;
    let botAnalysisLoading = false;
    /** Интервал секундного таймера на кнопке «Сделать анализ» / «Анализ». */
    let botAnalysisTimerId = null;
    // Последний ручной анализ по кнопке "Анализ" для выбранной пары; используем в правой панели при отсутствии сделки.
    let latestManualAnalysis = null;
    let lastZones = [];
    let openTrades = [];
    let entryPriceLineHandles = [];
    /** Последние уровни LLM-аналитика для текущей пары (chart_levels из API). */
    let lastLlmChartLevels = null;
    let lastLlmChartLevelsInstrument = null;
    // Кэш серверного времени из разных API-ответов (signals/open-trades/instruments/stats),
    // чтобы в debug-плашке видеть фактический "опорный" серверный timestamp.
    let lastServerTimes = { signals: null, openTrades: null, instruments: null, stats: null };
    // Последний feed-debug, полученный из /candles для выбранного инструмента.
    let lastFeedDebug = { rawFeedTime: null, effectiveFeedTime: null, ingestReceivedAt: null, lastClosedCandleTime: null };
    // Временные маркеры закрытых сделок: используем как мост между open-trades и полной историей сигналов.
    let syntheticClosedSignals = [];
    let targetTradeFocusDone = false;
    // Кэш исторических интервалов (entry -> expiry) по инструменту.
    const historyDurationCache = new Map();
    // Кэш карточек open-trades в overlay (tradeId -> refs), чтобы обновлять только таймер.
    const openTradeOverlayCardMap = new Map();

    // Кэш замороженных параметров экспирации: tradeId -> { expiryTime, price, frozenAt }
    // Нужен чтобы маркер экспирации не "прыгал" при обновлении незакрытой свечи.
    // Храним И время (bucket), И цену — оба параметра фиксируются навсегда.
    const frozenExpiryParams = new Map();

    /**
     * Замораживает параметры экспирации для сделки после её завершения.
     */
    function freezeExpiryParams(tradeId, expiryTime, price) {
        if (!Number.isFinite(Number(tradeId)) || !Number.isFinite(expiryTime)) return;
        const key = String(tradeId);
        // Не перезаписываем уже замороженные параметры — они зафиксированы навсегда.
        if (frozenExpiryParams.has(key)) return;
        frozenExpiryParams.set(key, {
            expiryTime: Math.floor(expiryTime / 60) * 60,
            price: Number(price),
            frozenAt: Date.now(),
        });
    }

    /**
     * Возвращает замороженные параметры экспирации, если они есть.
     */
    function getFrozenExpiryParams(tradeId) {
        if (!Number.isFinite(Number(tradeId))) return null;
        return frozenExpiryParams.get(String(tradeId)) || null;
    }

    /**
     * Обновляет кэш замороженных параметров на основе текущих данных сигналов.
     * Замораживает, если:
     * - сделка settled (win/loss/draw),
     * - время экспирации уже прошло (wall clock >= bucket + 60),
     * - бар экспирации больше не последний в серии.
     */
    function updateFrozenExpiryParams() {
        const nowSec = Math.floor(Date.now() / 1000);
        const lastBarTs = allChartData.length ? Number(allChartData[allChartData.length - 1].time) : null;

        mergedSignals().forEach((s) => {
            const tradeId = (s.trade && s.trade.id) ? Number(s.trade.id) : null;
            if (!Number.isFinite(tradeId) || tradeId <= 0) return;

            const expiryRaw = (s.trade && s.trade.expiry_time) ? s.trade.expiry_time : s.expiry_time;
            const expiryTs = toUnixSec(expiryRaw);
            if (!Number.isFinite(expiryTs) || expiryTs <= 0) return;

            const expiryBucket = Math.floor(expiryTs / 60) * 60;
            const result = ((s.trade && s.trade.result) || s.result || 'pending');
            const isSettled = result === 'win' || result === 'loss' || result === 'draw';

            // Цена для заморозки: основной источник — expiry_price из БД (сервер уже рассчитал).
            // Фронтенд НЕ должен пересчитывать цену экспирации — он должен использовать то, что записал сервер.
            let freezePrice = null;
            let priceFromCandle = false;

            // Приоритет №1: expiry_price из сделки (сервер уже рассчитал по своей логике)
            if (s.trade && s.trade.expiry_price != null && Number.isFinite(Number(s.trade.expiry_price))) {
                freezePrice = Number(s.trade.expiry_price);
            }
            // Приоритет №2: fallback — close свечи экспирации (синхронизировано с сервером)
            if ((freezePrice == null || !Number.isFinite(freezePrice))) {
                const expCandle = allChartData.find((c) => Number(c.time) === expiryBucket);
                if (expCandle && Number.isFinite(Number(expCandle.close))) {
                    freezePrice = Number(expCandle.close);
                    priceFromCandle = true;
                }
            }

            // Условия заморозки:
            // 1) сделка settled — замораживаем сразу, даже если цена только из сделки
            // 2) время прошло — замораживаем
            // 3) бар экспирации не последний — замораживаем
            const shouldFreeze = isSettled
                || nowSec >= expiryBucket + 60
                || (lastBarTs != null && expiryBucket < lastBarTs);

            // Для settled-сделок разрешаем заморозку даже с ценой из сделки (без свечи).
            // Для не-settled — только если есть цена из свечи.
            const canFreeze = shouldFreeze && (freezePrice != null && Number.isFinite(freezePrice))
                && (isSettled || priceFromCandle);

            if (canFreeze) {
                freezeExpiryParams(tradeId, expiryBucket, freezePrice);
            }
        });
    }

    function saveSelectedInstrument(instrument) {
        try {
            if (!instrument) return;
            localStorage.setItem(SELECTED_INSTRUMENT_KEY, String(instrument));
        } catch (_) {}
    }
    function loadSelectedInstrument() {
        try {
            const v = localStorage.getItem(SELECTED_INSTRUMENT_KEY);
            return v && v.trim() !== '' ? v : null;
        } catch (_) {
            return null;
        }
    }

    function loadLayerPrefs() {
        try {
            const raw = localStorage.getItem(LAYER_PREFS_KEY);
            if (raw == null) return;
            const obj = JSON.parse(raw);
            if (typeof obj.orderBlocks === 'boolean') layerOb.checked = obj.orderBlocks;
            if (typeof obj.imbalances === 'boolean') layerFvg.checked = obj.imbalances;
            if (layerPa && typeof obj.paDgt === 'boolean') layerPa.checked = obj.paDgt;
            if (layerLiquidity && typeof obj.liquiditySwings === 'boolean') layerLiquidity.checked = obj.liquiditySwings;
            // Классический Chart Analyst без SMC в LLM — слой SMC не включаем из сохранённых prefs.
            if (layerSmc && typeof obj.smcFlow === 'boolean') {
                layerSmc.checked = (BOT_IS_CHART_ANALYST && !BOT_ANALYST_SMC_FLOW_ENABLED) ? false : obj.smcFlow;
            }
            if (layerBollinger && typeof obj.bollinger === 'boolean') layerBollinger.checked = obj.bollinger;
            if (layerRsi && typeof obj.rsi === 'boolean') layerRsi.checked = obj.rsi;
            if (layerSma && typeof obj.sma === 'boolean') layerSma.checked = obj.sma;
            if (layerEma && typeof obj.ema === 'boolean') layerEma.checked = obj.ema;
            if (layerVwma && typeof obj.vwma === 'boolean') layerVwma.checked = obj.vwma;
            if (layerAtr && typeof obj.atr === 'boolean') layerAtr.checked = obj.atr;
            if (layerMacd && typeof obj.macd === 'boolean') layerMacd.checked = obj.macd;
            if (layerStochastic && typeof obj.stochastic === 'boolean') layerStochastic.checked = obj.stochastic;
            if (layerCci && typeof obj.cci === 'boolean') layerCci.checked = obj.cci;
            if (layerKeltner && typeof obj.keltner === 'boolean') layerKeltner.checked = obj.keltner;
            if (layerEnvelops && typeof obj.envelops === 'boolean') layerEnvelops.checked = obj.envelops;
            if (layerParabolicSar && typeof obj.parabolicSar === 'boolean') layerParabolicSar.checked = obj.parabolicSar;
            if (layerSuperTrend && typeof obj.supertrend === 'boolean') layerSuperTrend.checked = obj.supertrend;
            if (layerAdx && typeof obj.adx === 'boolean') layerAdx.checked = obj.adx;
            if (layerWilliams && typeof obj.williams === 'boolean') layerWilliams.checked = obj.williams;
            if (layerAo && typeof obj.ao === 'boolean') layerAo.checked = obj.ao;
            if (layerAlligator && typeof obj.alligator === 'boolean') layerAlligator.checked = obj.alligator;
            if (layerIchimoku && typeof obj.ichimoku === 'boolean') layerIchimoku.checked = obj.ichimoku;
            if (layerPriceChannel && typeof obj.price_channel === 'boolean') layerPriceChannel.checked = obj.price_channel;
            if (layerNrtr && typeof obj.nrtr === 'boolean') layerNrtr.checked = obj.nrtr;
            if (layerHma && typeof obj.hma === 'boolean') layerHma.checked = obj.hma;
            if (layerAroon && typeof obj.aroon === 'boolean') layerAroon.checked = obj.aroon;
            if (layerMomentum && typeof obj.momentum === 'boolean') layerMomentum.checked = obj.momentum;
            if (layerRoc && typeof obj.roc === 'boolean') layerRoc.checked = obj.roc;
            if (layerOsma && typeof obj.osma === 'boolean') layerOsma.checked = obj.osma;
            if (layerTrix && typeof obj.trix === 'boolean') layerTrix.checked = obj.trix;
            if (layerCmo && typeof obj.cmo === 'boolean') layerCmo.checked = obj.cmo;
            if (layerDemarker && typeof obj.demarker === 'boolean') layerDemarker.checked = obj.demarker;
            if (layerRvi && typeof obj.rvi === 'boolean') layerRvi.checked = obj.rvi;
            if (layerUltimate && typeof obj.ultimate === 'boolean') layerUltimate.checked = obj.ultimate;
            if (layerSmi && typeof obj.smi === 'boolean') layerSmi.checked = obj.smi;
            if (layerStdDev && typeof obj.stddev === 'boolean') layerStdDev.checked = obj.stddev;
            if (layerSmaChannel && typeof obj.smaChannel === 'boolean') layerSmaChannel.checked = obj.smaChannel;
            if (layerIvashov && typeof obj.ivashov === 'boolean') layerIvashov.checked = obj.ivashov;
            if (layerLinReg && typeof obj.linreg === 'boolean') layerLinReg.checked = obj.linreg;
            if (layerLinRegChannel && typeof obj.linregChannel === 'boolean') layerLinRegChannel.checked = obj.linregChannel;
            if (layerKalman && typeof obj.kalman === 'boolean') layerKalman.checked = obj.kalman;
            if (layerFractal && typeof obj.fractal === 'boolean') layerFractal.checked = obj.fractal;
            if (layerDonchian && typeof obj.donchian === 'boolean') layerDonchian.checked = obj.donchian;
            if (layerStochRsi && typeof obj.stoch_rsi === 'boolean') layerStochRsi.checked = obj.stoch_rsi;
            if (layerAc && typeof obj.ac === 'boolean') layerAc.checked = obj.ac;
            if (layerBullsPower && typeof obj.bulls_power === 'boolean') layerBullsPower.checked = obj.bulls_power;
            if (layerBearsPower && typeof obj.bears_power === 'boolean') layerBearsPower.checked = obj.bears_power;
            if (layerDpo && typeof obj.dpo === 'boolean') layerDpo.checked = obj.dpo;
            if (layerQstick && typeof obj.qstick === 'boolean') layerQstick.checked = obj.qstick;
            if (layerVhFilter && typeof obj.vh_filter === 'boolean') layerVhFilter.checked = obj.vh_filter;
            if (layerEfficiencyRatio && typeof obj.efficiency_ratio === 'boolean') layerEfficiencyRatio.checked = obj.efficiency_ratio;
            if (layerRavi && typeof obj.ravi === 'boolean') layerRavi.checked = obj.ravi;
            if (layerCog && typeof obj.cog === 'boolean') layerCog.checked = obj.cog;
            if (layerPivotFloor && typeof obj.pivot_floor === 'boolean') layerPivotFloor.checked = obj.pivot_floor;
            if (layerMassIndex && typeof obj.mass_index === 'boolean') layerMassIndex.checked = obj.mass_index;
            if (layerNrtrWatr && typeof obj.nrtr_watr === 'boolean') layerNrtrWatr.checked = obj.nrtr_watr;
            if (layerPriceChannelAdaptive && typeof obj.price_channel_adaptive === 'boolean') layerPriceChannelAdaptive.checked = obj.price_channel_adaptive;
            if (layerPriceChannelOffset && typeof obj.price_channel_offset === 'boolean') layerPriceChannelOffset.checked = obj.price_channel_offset;
            if (layerParabolicBollinger && typeof obj.parabolic_bollinger === 'boolean') layerParabolicBollinger.checked = obj.parabolic_bollinger;
            if (layerParabolicPriceChannel && typeof obj.parabolic_price_channel === 'boolean') layerParabolicPriceChannel.checked = obj.parabolic_price_channel;
            if (layerAtrChannel && typeof obj.atr_channel === 'boolean') layerAtrChannel.checked = obj.atr_channel;
            if (layerOffsetSma && typeof obj.offset_sma === 'boolean') layerOffsetSma.checked = obj.offset_sma;
            if (layerOffsetEma && typeof obj.offset_ema === 'boolean') layerOffsetEma.checked = obj.offset_ema;
            if (layerOffsetSsma && typeof obj.offset_ssma === 'boolean') layerOffsetSsma.checked = obj.offset_ssma;
            if (layerVolatilityAverage && typeof obj.volatility_average === 'boolean') layerVolatilityAverage.checked = obj.volatility_average;
            if (layerVolatilityAverageChannel && typeof obj.volatility_average_channel === 'boolean') layerVolatilityAverageChannel.checked = obj.volatility_average_channel;
            if (layerPriceOscillator && typeof obj.price_oscillator === 'boolean') layerPriceOscillator.checked = obj.price_oscillator;
            if (layerDynamicTrendDetector && typeof obj.dynamic_trend_detector === 'boolean') layerDynamicTrendDetector.checked = obj.dynamic_trend_detector;
            if (layerAdaptiveLookBack && typeof obj.adaptive_look_back === 'boolean') layerAdaptiveLookBack.checked = obj.adaptive_look_back;
            if (layerLinRegChannelFast && typeof obj.linreg_channel_fast === 'boolean') layerLinRegChannelFast.checked = obj.linreg_channel_fast;
            if (layerVolatilityCandles && typeof obj.volatility_candles === 'boolean') layerVolatilityCandles.checked = obj.volatility_candles;
            if (layerZigZag && typeof obj.zigzag === 'boolean') layerZigZag.checked = obj.zigzag;
            if (layerVolatilityAverageTwice && typeof obj.volatility_average_twice === 'boolean') layerVolatilityAverageTwice.checked = obj.volatility_average_twice;
            if (layerVolatilityStagesAw && typeof obj.volatility_stages_aw === 'boolean') layerVolatilityStagesAw.checked = obj.volatility_stages_aw;
            if (BOT_IS_CHART_ANALYST && layerLlmLevels && typeof obj.llmLevels === 'boolean') layerLlmLevels.checked = obj.llmLevels;
        } catch (_) {}
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
                donchian: layerDonchian ? layerDonchian.checked : false,
                stoch_rsi: layerStochRsi ? layerStochRsi.checked : false,
                ac: layerAc ? layerAc.checked : false,
                bulls_power: layerBullsPower ? layerBullsPower.checked : false,
                bears_power: layerBearsPower ? layerBearsPower.checked : false,
                dpo: layerDpo ? layerDpo.checked : false,
                qstick: layerQstick ? layerQstick.checked : false,
                vh_filter: layerVhFilter ? layerVhFilter.checked : false,
                efficiency_ratio: layerEfficiencyRatio ? layerEfficiencyRatio.checked : false,
                ravi: layerRavi ? layerRavi.checked : false,
                cog: layerCog ? layerCog.checked : false,
                pivot_floor: layerPivotFloor ? layerPivotFloor.checked : false,
                mass_index: layerMassIndex ? layerMassIndex.checked : false,
                nrtr_watr: layerNrtrWatr ? layerNrtrWatr.checked : false,
                price_channel_adaptive: layerPriceChannelAdaptive ? layerPriceChannelAdaptive.checked : false,
                price_channel_offset: layerPriceChannelOffset ? layerPriceChannelOffset.checked : false,
                parabolic_bollinger: layerParabolicBollinger ? layerParabolicBollinger.checked : false,
                parabolic_price_channel: layerParabolicPriceChannel ? layerParabolicPriceChannel.checked : false,
                atr_channel: layerAtrChannel ? layerAtrChannel.checked : false,
                offset_sma: layerOffsetSma ? layerOffsetSma.checked : false,
                offset_ema: layerOffsetEma ? layerOffsetEma.checked : false,
                offset_ssma: layerOffsetSsma ? layerOffsetSsma.checked : false,
                volatility_average: layerVolatilityAverage ? layerVolatilityAverage.checked : false,
                volatility_average_channel: layerVolatilityAverageChannel ? layerVolatilityAverageChannel.checked : false,
                price_oscillator: layerPriceOscillator ? layerPriceOscillator.checked : false,
                dynamic_trend_detector: layerDynamicTrendDetector ? layerDynamicTrendDetector.checked : false,
                adaptive_look_back: layerAdaptiveLookBack ? layerAdaptiveLookBack.checked : false,
                linreg_channel_fast: layerLinRegChannelFast ? layerLinRegChannelFast.checked : false,
                volatility_candles: layerVolatilityCandles ? layerVolatilityCandles.checked : false,
                zigzag: layerZigZag ? layerZigZag.checked : false,
                volatility_average_twice: layerVolatilityAverageTwice ? layerVolatilityAverageTwice.checked : false,
                volatility_stages_aw: layerVolatilityStagesAw ? layerVolatilityStagesAw.checked : false,
                llmLevels: (BOT_IS_CHART_ANALYST && layerLlmLevels) ? layerLlmLevels.checked : false,
            }));
        } catch (_) {}
    }

    /** Сбрасывает оверлей уровней LLM (смена пары, новый анализ, закрытие панели). */
    function clearLlmChartLevels() {
        lastLlmChartLevels = null;
        lastLlmChartLevelsInstrument = null;
        if (llmLevelsOverlay) llmLevelsOverlay.innerHTML = '';
    }

    function llmLevelKindColor(kind, side) {
        const k = (kind || 'level').toString().toLowerCase();
        if (k === 'entry') {
            if (side === 'CALL') return '#22c55e';
            if (side === 'PUT') return '#ef4444';
            return '#38bdf8';
        }
        const map = {
            support: '#22c55e',
            resistance: '#ef4444',
            pivot: '#eab308',
            range_high: '#a855f7',
            range_low: '#a855f7',
            level: '#94a3b8',
        };
        return map[k] || map.level;
    }

    function llmLevelDefaultStyle(kind) {
        const k = (kind || '').toString().toLowerCase();
        return (k === 'resistance' || k === 'range_high') ? 'dashed' : 'solid';
    }

    function llmZoneFillColor(timeframe) {
        const tf = (timeframe || '').toString().toUpperCase();
        if (tf === 'H1') return 'rgba(168, 85, 247, 0.18)';
        if (tf === 'M15') return 'rgba(59, 130, 246, 0.16)';
        if (tf === 'M5') return 'rgba(34, 197, 94, 0.14)';
        if (tf === 'M1') return 'rgba(245, 158, 11, 0.14)';
        return 'rgba(148, 163, 184, 0.14)';
    }

    /** Рисует горизонтали/зоны из chart_levels поверх графика (только openrouter_llm_analyst). */
    function redrawLlmLevelsOverlay() {
        if (!BOT_IS_CHART_ANALYST || !llmLevelsOverlay || !chart || !candlestickSeries) return;
        llmLevelsOverlay.innerHTML = '';
        if (!layerLlmLevels || !layerLlmLevels.checked) return;
        if (!lastLlmChartLevels || !allChartData.length) return;
        if (lastLlmChartLevelsInstrument && currentInstrument && lastLlmChartLevelsInstrument !== currentInstrument) return;

        const chartW = Math.max(1, chartWrapper ? chartWrapper.clientWidth : 0);
        const labelLanes = new Map();

        function appendHLine(y, color, style, lineWidth) {
            const div = document.createElement('div');
            div.style.position = 'absolute';
            div.style.left = '0';
            div.style.top = (y - lineWidth / 2) + 'px';
            div.style.width = chartW + 'px';
            div.style.pointerEvents = 'none';
            const st = (style || 'solid').toLowerCase();
            if (st === 'dashed' || st === 'dotted') {
                div.style.height = '0';
                div.style.borderTop = `${lineWidth}px ${st} ${color}`;
            } else {
                div.style.height = lineWidth + 'px';
                div.style.background = color;
            }
            llmLevelsOverlay.appendChild(div);
        }

        function appendLabel(y, text, color) {
            const bucket = Math.round(y / 14);
            let top = Math.max(2, Math.min((chartWrapper.clientHeight || 0) - 14, y - 8));
            const prev = labelLanes.get(bucket);
            if (prev != null && Math.abs(prev - top) < 12) top = Math.max(2, top - 12);
            labelLanes.set(bucket, top);
            const span = document.createElement('span');
            span.textContent = String(text || '');
            span.style.position = 'absolute';
            span.style.right = '6px';
            span.style.top = top + 'px';
            span.style.fontSize = '10px';
            span.style.lineHeight = '1.1';
            span.style.fontWeight = '600';
            span.style.color = color;
            span.style.background = 'rgba(9, 9, 11, 0.72)';
            span.style.padding = '1px 4px';
            span.style.borderRadius = '3px';
            span.style.pointerEvents = 'none';
            span.style.whiteSpace = 'nowrap';
            span.style.maxWidth = '45%';
            span.style.overflow = 'hidden';
            span.style.textOverflow = 'ellipsis';
            llmLevelsOverlay.appendChild(span);
        }

        try {
            const zones = Array.isArray(lastLlmChartLevels.zones) ? lastLlmChartLevels.zones : [];
            zones.forEach((z) => {
                if (!z || typeof z !== 'object') return;
                const y1 = candlestickSeries.priceToCoordinate(Number(z.price_high));
                const y2 = candlestickSeries.priceToCoordinate(Number(z.price_low));
                if (y1 == null || y2 == null || Number.isNaN(y1) || Number.isNaN(y2)) return;
                const div = document.createElement('div');
                div.style.position = 'absolute';
                div.style.left = '0';
                div.style.top = Math.min(y1, y2) + 'px';
                div.style.width = chartW + 'px';
                div.style.height = Math.max(1, Math.abs(y2 - y1)) + 'px';
                div.style.background = llmZoneFillColor(z.timeframe);
                div.style.borderTop = '1px dashed rgba(148, 163, 184, 0.35)';
                div.style.borderBottom = '1px dashed rgba(148, 163, 184, 0.35)';
                div.style.pointerEvents = 'none';
                llmLevelsOverlay.appendChild(div);
                if (z.label) appendLabel((y1 + y2) / 2, z.label, '#cbd5e1');
            });

            const levels = Array.isArray(lastLlmChartLevels.levels) ? lastLlmChartLevels.levels : [];
            levels.forEach((ln) => {
                if (!ln || typeof ln !== 'object') return;
                const price = Number(ln.price);
                if (!Number.isFinite(price)) return;
                const y = candlestickSeries.priceToCoordinate(price);
                if (y == null || Number.isNaN(y)) return;
                const color = llmLevelKindColor(ln.kind, null);
                const style = ln.style || llmLevelDefaultStyle(ln.kind);
                const lw = (String(ln.kind || '').toLowerCase() === 'pivot') ? 2 : 1;
                appendHLine(y, color, style, lw);
                const label = ln.label || ((ln.timeframe ? ln.timeframe + ' ' : '') + (ln.kind || 'level'));
                appendLabel(y, label, color);
            });

            const entry = (lastLlmChartLevels.entry && typeof lastLlmChartLevels.entry === 'object')
                ? lastLlmChartLevels.entry
                : null;
            if (entry && Number.isFinite(Number(entry.price))) {
                const y = candlestickSeries.priceToCoordinate(Number(entry.price));
                if (y != null && !Number.isNaN(y)) {
                    const side = entry.side ? String(entry.side).toUpperCase() : null;
                    const color = llmLevelKindColor('entry', side);
                    appendHLine(y, color, 'solid', 2);
                    const entryLabel = entry.label || ('entry' + (side ? ' ' + side : ''));
                    appendLabel(y, entryLabel, color);
                }
            }
        } catch (e) {
            console.error('[LLM levels] redraw', e);
        }
    }

    function applyLlmChartLevelsFromAnalysis(data) {
        if (!BOT_IS_CHART_ANALYST) return;
        const instrument = data && data.instrument ? String(data.instrument) : (currentInstrument || null);
        if (data && data.chart_levels_ok && data.chart_levels) {
            lastLlmChartLevels = data.chart_levels;
            lastLlmChartLevelsInstrument = instrument;
            if (layerLlmLevels) layerLlmLevels.checked = true;
        } else {
            clearLlmChartLevels();
        }
        if (llmLevelsOverlay) llmLevelsOverlay.classList.remove('hidden');
        redrawLlmLevelsOverlay();
    }

    /** После ручного анализа подставляем SMC Flow с сервера (тот же расчёт, что ушёл в LLM). */
    function applySmcFlowFromAnalysis(data) {
        if (!BOT_IS_CHART_ANALYST || !BOT_ANALYST_SMC_FLOW_ENABLED) return;
        if (data && data.smc_flow && typeof data.smc_flow === 'object') {
            lastSmcPayload = data.smc_flow;
            if (layerSmc) layerSmc.checked = true;
            saveLayerPrefs();
            if (smcFlowOverlay) smcFlowOverlay.classList.remove('hidden');
            redrawSmcFlowOverlay();
        }
    }

    /** Нормализация цвета индикатора (#RRGGBBAA) для CSS. */
    function paCssColor(c) {
        if (c == null || c === '') return 'rgba(148,163,184,0.5)';
        const s = String(c);
        if (s.startsWith('#') && s.length === 9) return s;
        return s;
    }

    /** Отрезок оверлея между двумя координатами (ломаная UT-трейла). */
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

    /**
     * Подтягивает расчёты индикаторов с /bots/{id}/indicators/data.
     * В query передаём только включённые layers[] — сервер считает лишь их.
     * opts.force = true — игнор throttle (смена чекбокса / reload).
     */
    function fetchPriceActionIndicator(opts) {
        if (!currentInstrument) return;
        const force = !!(opts && opts.force);
        const now = Date.now();
        // Throttle: poll свечей шёл каждые 500мс и ставил в очередь десятки тяжёлых
        // /indicators/data — ответы приходили вразнобой, applyClassic успевал clear без redraw.
        if (!force && (now - lastIndicatorFetchAt) < INDICATOR_MIN_REFETCH_MS) {
            return;
        }
        lastIndicatorFetchAt = now;
        const fetchSeq = ++indicatorFetchSeq;
        const wantPa = layerPa && layerPa.checked;
        const wantLs = layerLiquidity && layerLiquidity.checked;
        const wantSmc = layerSmc && layerSmc.checked;
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
        const wantDonchian = layerDonchian && layerDonchian.checked;
        const wantStochRsi = layerStochRsi && layerStochRsi.checked;
        const wantAc = layerAc && layerAc.checked;
        const wantBullsPower = layerBullsPower && layerBullsPower.checked;
        const wantBearsPower = layerBearsPower && layerBearsPower.checked;
        const wantDpo = layerDpo && layerDpo.checked;
        const wantQstick = layerQstick && layerQstick.checked;
        const wantVhFilter = layerVhFilter && layerVhFilter.checked;
        const wantEfficiencyRatio = layerEfficiencyRatio && layerEfficiencyRatio.checked;
        const wantRavi = layerRavi && layerRavi.checked;
        const wantCog = layerCog && layerCog.checked;
        const wantPivotFloor = layerPivotFloor && layerPivotFloor.checked;
        const wantMassIndex = layerMassIndex && layerMassIndex.checked;
        const wantNrtrWatr = layerNrtrWatr && layerNrtrWatr.checked;
        const wantPriceChannelAdaptive = layerPriceChannelAdaptive && layerPriceChannelAdaptive.checked;
        const wantPriceChannelOffset = layerPriceChannelOffset && layerPriceChannelOffset.checked;
        const wantParabolicBollinger = layerParabolicBollinger && layerParabolicBollinger.checked;
        const wantParabolicPriceChannel = layerParabolicPriceChannel && layerParabolicPriceChannel.checked;
        const wantAtrChannel = layerAtrChannel && layerAtrChannel.checked;
        const wantOffsetSma = layerOffsetSma && layerOffsetSma.checked;
        const wantOffsetEma = layerOffsetEma && layerOffsetEma.checked;
        const wantOffsetSsma = layerOffsetSsma && layerOffsetSsma.checked;
        const wantVolatilityAverage = layerVolatilityAverage && layerVolatilityAverage.checked;
        const wantVolatilityAverageChannel = layerVolatilityAverageChannel && layerVolatilityAverageChannel.checked;
        const wantPriceOscillator = layerPriceOscillator && layerPriceOscillator.checked;
        const wantDynamicTrendDetector = layerDynamicTrendDetector && layerDynamicTrendDetector.checked;
        const wantAdaptiveLookBack = layerAdaptiveLookBack && layerAdaptiveLookBack.checked;
        const wantLinRegChannelFast = layerLinRegChannelFast && layerLinRegChannelFast.checked;
        const wantVolatilityCandles = layerVolatilityCandles && layerVolatilityCandles.checked;
        const wantZigZag = layerZigZag && layerZigZag.checked;
        const wantVolatilityAverageTwice = layerVolatilityAverageTwice && layerVolatilityAverageTwice.checked;
        const wantVolatilityStagesAw = layerVolatilityStagesAw && layerVolatilityStagesAw.checked;
        if (!wantPa && !wantLs && !wantSmc && !wantBb && !wantRsi && !wantSma && !wantEma && !wantVwma && !wantAtr && !wantMacd && !wantStochastic && !wantCci && !wantKeltner && !wantEnvelops && !wantParabolicSar && !wantSuperTrend && !wantAdx && !wantWilliams && !wantAo && !wantAlligator && !wantIchimoku && !wantPriceChannel && !wantNrtr && !wantHma && !wantAroon
            && !wantMomentum && !wantRoc && !wantOsma && !wantTrix && !wantCmo && !wantDemarker && !wantRvi && !wantUltimate && !wantSmi && !wantStdDev && !wantSmaChannel && !wantIvashov && !wantLinReg && !wantLinRegChannel && !wantKalman && !wantFractal
            && !wantDonchian && !wantStochRsi && !wantAc && !wantBullsPower && !wantBearsPower && !wantDpo && !wantQstick && !wantVhFilter && !wantEfficiencyRatio && !wantRavi && !wantCog && !wantPivotFloor && !wantMassIndex && !wantNrtrWatr && !wantPriceChannelAdaptive && !wantPriceChannelOffset && !wantParabolicBollinger && !wantParabolicPriceChannel && !wantAtrChannel && !wantOffsetSma && !wantOffsetEma && !wantOffsetSsma && !wantVolatilityAverage && !wantVolatilityAverageChannel && !wantPriceOscillator && !wantDynamicTrendDetector && !wantAdaptiveLookBack && !wantLinRegChannelFast && !wantVolatilityCandles && !wantZigZag && !wantVolatilityAverageTwice && !wantVolatilityStagesAw) {
            lastPaPayload = null;
            lastLsPayload = null;
            lastSmcPayload = null;
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
            lastDonchianPayload = null;
            lastStochRsiPayload = null;
            lastAcPayload = null;
            lastBullsPowerPayload = null;
            lastBearsPowerPayload = null;
            lastDpoPayload = null;
            lastQstickPayload = null;
            lastVhFilterPayload = null;
            lastEfficiencyRatioPayload = null;
            lastRaviPayload = null;
            lastCogPayload = null;
            lastPivotFloorPayload = null;
            lastMassIndexPayload = null;
            lastNrtrWatrPayload = null;
            lastPriceChannelAdaptivePayload = null;
            lastPriceChannelOffsetPayload = null;
            lastParabolicBollingerPayload = null;
            lastParabolicPriceChannelPayload = null;
            lastAtrChannelPayload = null;
            lastOffsetSmaPayload = null;
            lastOffsetEmaPayload = null;
            lastOffsetSsmaPayload = null;
            lastVolatilityAveragePayload = null;
            lastVolatilityAverageChannelPayload = null;
            lastPriceOscillatorPayload = null;
            lastDynamicTrendDetectorPayload = null;
            lastAdaptiveLookBackPayload = null;
            lastLinRegChannelFastPayload = null;
            lastVolatilityCandlesPayload = null;
            lastZigZagPayload = null;
            lastVolatilityAverageTwicePayload = null;
            lastVolatilityStagesAwPayload = null;
            redrawPriceActionOverlay();
            applyPaBarColors({ fullResync: true });
            renderChartMarkers();
            applyClassicOsEngineSeries();
            return;
        }
        const params = new URLSearchParams({ instrument: currentInstrument, timeframe: String(currentTimeframeMinutes) });
        const layers = [];
        if (wantPa) layers.push('pa');
        if (wantLs) layers.push('ls');
        if (wantSmc) layers.push('smc');
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
        if (wantDonchian) layers.push('donchian');
        if (wantStochRsi) layers.push('stoch_rsi');
        if (wantAc) layers.push('ac');
        if (wantBullsPower) layers.push('bulls_power');
        if (wantBearsPower) layers.push('bears_power');
        if (wantDpo) layers.push('dpo');
        if (wantQstick) layers.push('qstick');
        if (wantVhFilter) layers.push('vh_filter');
        if (wantEfficiencyRatio) layers.push('efficiency_ratio');
        if (wantRavi) layers.push('ravi');
        if (wantCog) layers.push('cog');
        if (wantPivotFloor) layers.push('pivot_floor');
        if (wantMassIndex) layers.push('mass_index');
        if (wantNrtrWatr) layers.push('nrtr_watr');
        if (wantPriceChannelAdaptive) layers.push('price_channel_adaptive');
        if (wantPriceChannelOffset) layers.push('price_channel_offset');
        if (wantParabolicBollinger) layers.push('parabolic_bollinger');
        if (wantParabolicPriceChannel) layers.push('parabolic_price_channel');
        if (wantAtrChannel) layers.push('atr_channel');
        if (wantOffsetSma) layers.push('offset_sma');
        if (wantOffsetEma) layers.push('offset_ema');
        if (wantOffsetSsma) layers.push('offset_ssma');
        if (wantVolatilityAverage) layers.push('volatility_average');
        if (wantVolatilityAverageChannel) layers.push('volatility_average_channel');
        if (wantPriceOscillator) layers.push('price_oscillator');
        if (wantDynamicTrendDetector) layers.push('dynamic_trend_detector');
        if (wantAdaptiveLookBack) layers.push('adaptive_look_back');
        if (wantLinRegChannelFast) layers.push('linreg_channel_fast');
        if (wantVolatilityCandles) layers.push('volatility_candles');
        if (wantZigZag) layers.push('zigzag');
        if (wantVolatilityAverageTwice) layers.push('volatility_average_twice');
        if (wantVolatilityStagesAw) layers.push('volatility_stages_aw');
        layers.forEach((l) => params.append('layers[]', l));
        fetch(`${PA_INDICATOR_URL}?${params.toString()}`, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then((r) => r.json())
            .then((d) => {
                // Устаревший ответ (пришёл после более нового запроса) — не трогаем payload/chart.
                if (fetchSeq !== indicatorFetchSeq) return;
                try {
                    lastPaPayload = wantPa && d && d.indicator ? d.indicator : null;
                    lastLsPayload = wantLs && d && d.liquidity_swings ? d.liquidity_swings : null;
                    lastSmcPayload = wantSmc && d && d.smc_flow ? d.smc_flow : null;
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
                    lastDonchianPayload = wantDonchian && d && d.donchian ? d.donchian : null;
                    lastStochRsiPayload = wantStochRsi && d && d.stoch_rsi ? d.stoch_rsi : null;
                    lastAcPayload = wantAc && d && d.ac ? d.ac : null;
                    lastBullsPowerPayload = wantBullsPower && d && d.bulls_power ? d.bulls_power : null;
                    lastBearsPowerPayload = wantBearsPower && d && d.bears_power ? d.bears_power : null;
                    lastDpoPayload = wantDpo && d && d.dpo ? d.dpo : null;
                    lastQstickPayload = wantQstick && d && d.qstick ? d.qstick : null;
                    lastVhFilterPayload = wantVhFilter && d && d.vh_filter ? d.vh_filter : null;
                    lastEfficiencyRatioPayload = wantEfficiencyRatio && d && d.efficiency_ratio ? d.efficiency_ratio : null;
                    lastRaviPayload = wantRavi && d && d.ravi ? d.ravi : null;
                    lastCogPayload = wantCog && d && d.cog ? d.cog : null;
                    lastPivotFloorPayload = wantPivotFloor && d && d.pivot_floor ? d.pivot_floor : null;
                    lastMassIndexPayload = wantMassIndex && d && d.mass_index ? d.mass_index : null;
                    lastNrtrWatrPayload = wantNrtrWatr && d && d.nrtr_watr ? d.nrtr_watr : null;
                    lastPriceChannelAdaptivePayload = wantPriceChannelAdaptive && d && d.price_channel_adaptive ? d.price_channel_adaptive : null;
                    lastPriceChannelOffsetPayload = wantPriceChannelOffset && d && d.price_channel_offset ? d.price_channel_offset : null;
                    lastParabolicBollingerPayload = wantParabolicBollinger && d && d.parabolic_bollinger ? d.parabolic_bollinger : null;
                    lastParabolicPriceChannelPayload = wantParabolicPriceChannel && d && d.parabolic_price_channel ? d.parabolic_price_channel : null;
                    lastAtrChannelPayload = wantAtrChannel && d && d.atr_channel ? d.atr_channel : null;
                    lastOffsetSmaPayload = wantOffsetSma && d && d.offset_sma ? d.offset_sma : null;
                    lastOffsetEmaPayload = wantOffsetEma && d && d.offset_ema ? d.offset_ema : null;
                    lastOffsetSsmaPayload = wantOffsetSsma && d && d.offset_ssma ? d.offset_ssma : null;
                    lastVolatilityAveragePayload = wantVolatilityAverage && d && d.volatility_average ? d.volatility_average : null;
                    lastVolatilityAverageChannelPayload = wantVolatilityAverageChannel && d && d.volatility_average_channel ? d.volatility_average_channel : null;
                    lastPriceOscillatorPayload = wantPriceOscillator && d && d.price_oscillator ? d.price_oscillator : null;
                    lastDynamicTrendDetectorPayload = wantDynamicTrendDetector && d && d.dynamic_trend_detector ? d.dynamic_trend_detector : null;
                    lastAdaptiveLookBackPayload = wantAdaptiveLookBack && d && d.adaptive_look_back ? d.adaptive_look_back : null;
                    lastLinRegChannelFastPayload = wantLinRegChannelFast && d && d.linreg_channel_fast ? d.linreg_channel_fast : null;
                    lastVolatilityCandlesPayload = wantVolatilityCandles && d && d.volatility_candles ? d.volatility_candles : null;
                    lastZigZagPayload = wantZigZag && d && d.zigzag ? d.zigzag : null;
                    lastVolatilityAverageTwicePayload = wantVolatilityAverageTwice && d && d.volatility_average_twice ? d.volatility_average_twice : null;
                    lastVolatilityStagesAwPayload = wantVolatilityStagesAw && d && d.volatility_stages_aw ? d.volatility_stages_aw : null;
                    redrawPriceActionOverlay();
                    applyPaBarColors({ fullResync: wantPa });
                    renderChartMarkers();
                    applyClassicOsEngineSeries();
                } catch (e) {
                    console.error('[indicators] apply', e);
                    lastPaPayload = null;
                    lastLsPayload = null;
                    lastSmcPayload = null;
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
                    lastDonchianPayload = null;
                    lastStochRsiPayload = null;
                    lastAcPayload = null;
                    lastBullsPowerPayload = null;
                    lastBearsPowerPayload = null;
                    lastDpoPayload = null;
                    lastQstickPayload = null;
                    lastVhFilterPayload = null;
                    lastEfficiencyRatioPayload = null;
                    lastRaviPayload = null;
                    lastCogPayload = null;
                    lastPivotFloorPayload = null;
                    lastMassIndexPayload = null;
                    lastNrtrWatrPayload = null;
                    lastPriceChannelAdaptivePayload = null;
                    lastPriceChannelOffsetPayload = null;
                    lastParabolicBollingerPayload = null;
                    lastParabolicPriceChannelPayload = null;
                    lastAtrChannelPayload = null;
                    lastOffsetSmaPayload = null;
                    lastOffsetEmaPayload = null;
                    lastOffsetSsmaPayload = null;
                    lastVolatilityAveragePayload = null;
                    lastVolatilityAverageChannelPayload = null;
                    lastPriceOscillatorPayload = null;
                    lastDynamicTrendDetectorPayload = null;
                    lastAdaptiveLookBackPayload = null;
                    lastLinRegChannelFastPayload = null;
                    lastVolatilityCandlesPayload = null;
                    lastZigZagPayload = null;
                    lastVolatilityAverageTwicePayload = null;
                    lastVolatilityStagesAwPayload = null;
                    try {
                        redrawPriceActionOverlay();
                        applyPaBarColors({ fullResync: true });
                        renderChartMarkers();
                        applyClassicOsEngineSeries();
                    } catch (_) {}
                }
            })
            .catch(() => {});
    }

    /**
     * Удаляет line-series классических индикаторов и лишние pane осцилляторов (LWC v5).
     * После removeSeries пустые pane обычно схлопываются; removePane — страховка.
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
        // Сносим pane осцилляторов снизу вверх, пока остаётся только pane 0 (свечи).
        try {
            while (typeof chart.panes === 'function' && chart.panes().length > 1) {
                chart.removePane(chart.panes().length - 1);
            }
        } catch (_) {}
        classicOscPaneCount = 0;
        // Основная шкала снова на всю высоту pane 0 (без «фейкового» низа под RSI).
        try {
            chart.priceScale('right').applyOptions({
                scaleMargins: { top: 0.08, bottom: 0.05 },
            });
        } catch (_) {}
    }

    /**
     * Рисует BB/SMA/EMA/VWMA на pane 0; RSI и ATR — в отдельных chart.addPane() (настоящие panes LWC v5).
     */
    function applyClassicOsEngineSeries() {
        if (!chart || !candlestickSeries) return;
        clearClassicOsEngineSeries();

        const LineSeries = LightweightCharts.LineSeries;
        const HistogramSeries = LightweightCharts.HistogramSeries;
        if (!LineSeries || typeof chart.addSeries !== 'function') {
            console.error('[indicators] LWC v5 addSeries/LineSeries недоступны');
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
        const wantDonchian = layerDonchian && layerDonchian.checked && lastDonchianPayload;
        const wantStochRsi = layerStochRsi && layerStochRsi.checked && lastStochRsiPayload;
        const wantAc = layerAc && layerAc.checked && lastAcPayload;
        const wantBullsPower = layerBullsPower && layerBullsPower.checked && lastBullsPowerPayload;
        const wantBearsPower = layerBearsPower && layerBearsPower.checked && lastBearsPowerPayload;
        const wantDpo = layerDpo && layerDpo.checked && lastDpoPayload;
        const wantQstick = layerQstick && layerQstick.checked && lastQstickPayload;
        const wantVhFilter = layerVhFilter && layerVhFilter.checked && lastVhFilterPayload;
        const wantEfficiencyRatio = layerEfficiencyRatio && layerEfficiencyRatio.checked && lastEfficiencyRatioPayload;
        const wantRavi = layerRavi && layerRavi.checked && lastRaviPayload;
        const wantCog = layerCog && layerCog.checked && lastCogPayload;
        const wantPivotFloor = layerPivotFloor && layerPivotFloor.checked && lastPivotFloorPayload;
        const wantMassIndex = layerMassIndex && layerMassIndex.checked && lastMassIndexPayload;
        const wantNrtrWatr = layerNrtrWatr && layerNrtrWatr.checked && lastNrtrWatrPayload;
        const wantPriceChannelAdaptive = layerPriceChannelAdaptive && layerPriceChannelAdaptive.checked && lastPriceChannelAdaptivePayload;
        const wantPriceChannelOffset = layerPriceChannelOffset && layerPriceChannelOffset.checked && lastPriceChannelOffsetPayload;
        const wantParabolicBollinger = layerParabolicBollinger && layerParabolicBollinger.checked && lastParabolicBollingerPayload;
        const wantParabolicPriceChannel = layerParabolicPriceChannel && layerParabolicPriceChannel.checked && lastParabolicPriceChannelPayload;
        const wantAtrChannel = layerAtrChannel && layerAtrChannel.checked && lastAtrChannelPayload;
        const wantOffsetSma = layerOffsetSma && layerOffsetSma.checked && lastOffsetSmaPayload;
        const wantOffsetEma = layerOffsetEma && layerOffsetEma.checked && lastOffsetEmaPayload;
        const wantOffsetSsma = layerOffsetSsma && layerOffsetSsma.checked && lastOffsetSsmaPayload;
        const wantVolatilityAverage = layerVolatilityAverage && layerVolatilityAverage.checked && lastVolatilityAveragePayload;
        const wantVolatilityAverageChannel = layerVolatilityAverageChannel && layerVolatilityAverageChannel.checked && lastVolatilityAverageChannelPayload;
        const wantPriceOscillator = layerPriceOscillator && layerPriceOscillator.checked && lastPriceOscillatorPayload;
        const wantDynamicTrendDetector = layerDynamicTrendDetector && layerDynamicTrendDetector.checked && lastDynamicTrendDetectorPayload;
        const wantAdaptiveLookBack = layerAdaptiveLookBack && layerAdaptiveLookBack.checked && lastAdaptiveLookBackPayload;
        const wantLinRegChannelFast = layerLinRegChannelFast && layerLinRegChannelFast.checked && lastLinRegChannelFastPayload;
        const wantVolatilityCandles = layerVolatilityCandles && layerVolatilityCandles.checked && lastVolatilityCandlesPayload;
        const wantZigZag = layerZigZag && layerZigZag.checked && lastZigZagPayload;
        const wantVolatilityAverageTwice = layerVolatilityAverageTwice && layerVolatilityAverageTwice.checked && lastVolatilityAverageTwicePayload;
        const wantVolatilityStagesAw = layerVolatilityStagesAw && layerVolatilityStagesAw.checked && lastVolatilityStagesAwPayload;

        // Линии на основной pane (индекс 0) — рядом со свечами.
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
                // OsEngine Point: точки без соединяющей линии (LWC pointMarkers).
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
        // Donchian — оверлей up/mid/down на pane 0.
        if (wantDonchian) {
            const st = lastDonchianPayload.style || {};
            const ser = lastDonchianPayload.series || {};
            const w = st.line_width || 1;
            addMainLine('donchian_up', ser.up, st.color_up, w);
            addMainLine('donchian_mid', ser.mid, st.color_mid, w);
            addMainLine('donchian_down', ser.down, st.color_down, w);
        }
        // Pivot Floor — оверлей P/R/S уровней на pane 0.
        if (wantPivotFloor) {
            const st = lastPivotFloorPayload.style || {};
            const ser = lastPivotFloorPayload.series || {};
            const w = st.line_width || 1;
            addMainLine('pivot_p', ser.p, st.color_p, w);
            addMainLine('pivot_r1', ser.r1, st.color_r, w);
            addMainLine('pivot_r2', ser.r2, st.color_r, w);
            addMainLine('pivot_r3', ser.r3, st.color_r, w);
            addMainLine('pivot_s1', ser.s1, st.color_s, w);
            addMainLine('pivot_s2', ser.s2, st.color_s, w);
            addMainLine('pivot_s3', ser.s3, st.color_s, w);
        }

        // Priority-1 overlays on pane 0.
        if (wantNrtrWatr) {
            const st = lastNrtrWatrPayload.style || {};
            const ser = lastNrtrWatrPayload.series || {};
            const w = st.line_width || 1;
            addMainLine('nrtr_watr', ser.nrtr, st.color, w);
        }
        if (wantPriceChannelAdaptive) {
            const st = lastPriceChannelAdaptivePayload.style || {};
            const ser = lastPriceChannelAdaptivePayload.series || {};
            const w = st.line_width || 1;
            addMainLine('pca_up', ser.up, st.color_up, w);
            addMainLine('pca_down', ser.down, st.color_down, w);
        }
        if (wantPriceChannelOffset) {
            const st = lastPriceChannelOffsetPayload.style || {};
            const ser = lastPriceChannelOffsetPayload.series || {};
            const w = st.line_width || 1;
            addMainLine('pco_up', ser.up, st.color_up, w);
            addMainLine('pco_down', ser.down, st.color_down, w);
            addMainLine('pco_up_border', ser.up_border, st.color_up_border, w);
            addMainLine('pco_down_border', ser.down_border, st.color_down_border, w);
        }
        if (wantParabolicBollinger) {
            const st = lastParabolicBollingerPayload.style || {};
            const ser = lastParabolicBollingerPayload.series || {};
            const w = st.line_width || 1;
            addMainLine('pb_up', ser.up, st.color_up, w);
            addMainLine('pb_down', ser.down, st.color_down, w);
            const addParabPoints = (pkey, data, color) => {
                if (!Array.isArray(data) || !data.length) return;
                const s = chart.addSeries(LineSeries, {
                    color: color || '#ffff00',
                    lineWidth: 1,
                    lineVisible: false,
                    pointMarkersVisible: true,
                    pointMarkersRadius: st.point_radius || 2,
                    priceLineVisible: false,
                    lastValueVisible: false,
                    crosshairMarkerVisible: false,
                }, 0);
                s.setData(data.map((p) => ({ time: p.time, value: p.value })));
                classicLineSeries[pkey] = s;
            };
            addParabPoints('parabolic_bollinger_parab', ser.parabolic, st.color_parabolic);
        }
        if (wantParabolicPriceChannel) {
            const st = lastParabolicPriceChannelPayload.style || {};
            const ser = lastParabolicPriceChannelPayload.series || {};
            const w = st.line_width || 1;
            addMainLine('ppc_up', ser.up, st.color_up, w);
            addMainLine('ppc_down', ser.down, st.color_down, w);
            const addParabPoints = (pkey, data, color) => {
                if (!Array.isArray(data) || !data.length) return;
                const s = chart.addSeries(LineSeries, {
                    color: color || '#ffff00',
                    lineWidth: 1,
                    lineVisible: false,
                    pointMarkersVisible: true,
                    pointMarkersRadius: st.point_radius || 2,
                    priceLineVisible: false,
                    lastValueVisible: false,
                    crosshairMarkerVisible: false,
                }, 0);
                s.setData(data.map((p) => ({ time: p.time, value: p.value })));
                classicLineSeries[pkey] = s;
            };
            addParabPoints('parabolic_price_channel_parab', ser.parabolic, st.color_parabolic);
        }
        if (wantAtrChannel) {
            const st = lastAtrChannelPayload.style || {};
            const ser = lastAtrChannelPayload.series || {};
            const w = st.line_width || 1;
            addMainLine('atr_channel', ser.channel, st.color, w);
        }
        if (wantOffsetSma) {
            const st = lastOffsetSmaPayload.style || {};
            const ser = lastOffsetSmaPayload.series || {};
            const w = st.line_width || 1;
            addMainLine('offset_sma', ser.sma, st.color, w);
        }
        if (wantOffsetEma) {
            const st = lastOffsetEmaPayload.style || {};
            const ser = lastOffsetEmaPayload.series || {};
            const w = st.line_width || 1;
            addMainLine('offset_ema', ser.ema, st.color, w);
        }
        if (wantOffsetSsma) {
            const st = lastOffsetSsmaPayload.style || {};
            const ser = lastOffsetSsmaPayload.series || {};
            const w = st.line_width || 1;
            addMainLine('offset_ssma', ser.ssma, st.color, w);
        }
        if (wantDynamicTrendDetector) {
            const st = lastDynamicTrendDetectorPayload.style || {};
            const ser = lastDynamicTrendDetectorPayload.series || {};
            const w = st.line_width || 1;
            addMainLine('dtd', ser.dtd, st.color, w);
        }
        if (wantLinRegChannelFast) {
            const st = lastLinRegChannelFastPayload.style || {};
            const ser = lastLinRegChannelFastPayload.series || {};
            const w = st.line_width || 1;
            addMainLine('lrcf_up', ser.up, st.color_up, w);
            addMainLine('lrcf_mid', ser.mid, st.color_mid, w);
            addMainLine('lrcf_down', ser.down, st.color_down, w);
        }


        // ZigZag — ломаная + точки хай/лоу на основной pane.
        if (wantZigZag) {
            const st = lastZigZagPayload.style || {};
            const ser = lastZigZagPayload.series || {};
            const w = st.line_width || 1;
            if (ser.line && ser.line.length) {
                addMainLine('zz_line', ser.line, st.color_line || '#6495ed', w);
            }
            const addZzPoints = (key, data, color) => {
                if (!Array.isArray(data) || !data.length) return;
                const s = chart.addSeries(LineSeries, {
                    color: color || '#adff2f',
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
            addZzPoints('zz_highs', ser.highs, st.color_high || '#adff2f');
            addZzPoints('zz_lows', ser.lows, st.color_low || '#ff0000');
            addZzPoints('zz_pts', ser.zigzag, st.color_line || '#6495ed');
        }

        const oscCount = (wantRsi ? 1 : 0) + (wantMacd ? 1 : 0) + (wantStochastic ? 1 : 0) + (wantCci ? 1 : 0) + (wantAtr ? 1 : 0) + (wantAdx ? 1 : 0) + (wantWilliams ? 1 : 0) + (wantAo ? 1 : 0) + (wantAroon ? 1 : 0)
            + (wantMomentum ? 1 : 0) + (wantRoc ? 1 : 0) + (wantOsma ? 1 : 0) + (wantTrix ? 1 : 0) + (wantCmo ? 1 : 0) + (wantDemarker ? 1 : 0) + (wantRvi ? 1 : 0) + (wantUltimate ? 1 : 0) + (wantSmi ? 1 : 0) + (wantStdDev ? 1 : 0) + (wantIvashov ? 1 : 0)
            + (wantStochRsi ? 1 : 0) + (wantAc ? 1 : 0) + (wantBullsPower ? 1 : 0) + (wantBearsPower ? 1 : 0) + (wantDpo ? 1 : 0) + (wantQstick ? 1 : 0) + (wantVhFilter ? 1 : 0) + (wantEfficiencyRatio ? 1 : 0) + (wantRavi ? 1 : 0) + (wantCog ? 1 : 0) + (wantMassIndex ? 1 : 0) + (wantVolatilityAverage ? 1 : 0) + (wantVolatilityAverageChannel ? 1 : 0) + (wantPriceOscillator ? 1 : 0) + (wantAdaptiveLookBack ? 1 : 0) + (wantVolatilityCandles ? 1 : 0) + (wantVolatilityAverageTwice ? 1 : 0) + (wantVolatilityStagesAw ? 2 : 0);
        if (oscCount > 0) {
            // Главная pane крупнее осцилляторов (~1 : 0.28 на каждый нижний pane).
            try {
                const mainPane = chart.panes()[0];
                if (mainPane && typeof mainPane.setStretchFactor === 'function') {
                    mainPane.setStretchFactor(1);
                }
            } catch (_) {}
        }

        // RSI — отдельный нижний pane (не overlay на цене).
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
                console.error('[indicators] addPane RSI', e);
            }
            const s = chart.addSeries(LineSeries, {
                color: st.color || '#1e90ff',
                lineWidth: st.line_width || 1,
                priceLineVisible: false,
                baseLineVisible: false,
                // Показываем текущее значение RSI справа для быстрого контроля уровня.
                lastValueVisible: true,
                crosshairMarkerVisible: false,
                // RSI работает в диапазоне 0..100: целые подписи достаточно информативны.
                priceFormat: { type: 'price', precision: 0, minMove: 1 },
                // Фиксируем шкалу, чтобы уровни 70/30 всегда оставались в видимой области.
                autoscaleInfoProvider: () => ({ priceRange: { minValue: 0, maxValue: 100 } }),
            }, paneIndex);
            const data = ((lastRsiPayload.series || {}).rsi) || [];
            s.setData(data.map((p) => ({ time: p.time, value: p.value })));
            classicLineSeries['rsi'] = s;
            // Поджимаем отступы шкалы, чтобы линия не "липла" к верхней/нижней кромке pane.
            try {
                s.priceScale().applyOptions({
                    scaleMargins: { top: 0.08, bottom: 0.08 },
                    borderVisible: false,
                    entireTextOnly: true,
                });
            } catch (_) {}

            // Однотонный фон для RSI-pane: снижает визуальный шум от общей сетки графика.
            try {
                const paneApi = rsiPane || (typeof chart.panes === 'function' ? chart.panes()[paneIndex] : null);
                if (paneApi && typeof paneApi.attachPrimitive === 'function'
                    && window.PlatformChartMarkers
                    && typeof window.PlatformChartMarkers.createPaneSolidBackdrop === 'function') {
                    paneApi.attachPrimitive(window.PlatformChartMarkers.createPaneSolidBackdrop('#0e1018'));
                }
            } catch (e) {
                console.error('[indicators] RSI pane backdrop', e);
            }

            // Метки 70/30 справа: используем helper, чтобы поведение было единым с dashboard.
            try {
                const lines = (window.PlatformChartMarkers
                    && typeof window.PlatformChartMarkers.attachRsiLevelLines === 'function')
                    ? window.PlatformChartMarkers.attachRsiLevelLines(s, levels)
                    : [];
                rsiLevelLines.push(...lines);
            } catch (_) {}
        }

        // MACD — отдельный pane: histogram + MACD line + signal (+ нулевая линия).
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
                console.error('[indicators] addPane MACD', e);
            }

            // Гистограмма первой — визуально «под» линиями.
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
                    console.error('[indicators] MACD histogram', e);
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

            // Нулевая линия на шкале MACD (если гистограммы нет — рисуем priceLine на line series).
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

        // Stochastic — отдельный pane 0..100 с %K/%D и уровнями 80/20.
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
                console.error('[indicators] addPane Stochastic', e);
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
                console.error('[indicators] Stochastic pane backdrop', e);
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
                console.error('[indicators] addPane CCI', e);
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
                console.error('[indicators] CCI pane backdrop', e);
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

        // Williams %R — pane -100..0, уровни -20/-80.
        if (wantWilliams) {
            const st = lastWilliamsPayload.style || {};
            const levels = lastWilliamsPayload.levels || {};
            const ser = lastWilliamsPayload.series || {};
            let paneIndex = 1;
            let wrPane = null;
            try {
                wrPane = chart.addPane(false);
                if (wrPane && typeof wrPane.setStretchFactor === 'function') {
                    wrPane.setStretchFactor(0.28);
                }
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[indicators] addPane Williams', e);
            }
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
                const paneApi = wrPane || (typeof chart.panes === 'function' ? chart.panes()[paneIndex] : null);
                if (paneApi && typeof paneApi.attachPrimitive === 'function'
                    && window.PlatformChartMarkers
                    && typeof window.PlatformChartMarkers.createPaneSolidBackdrop === 'function') {
                    paneApi.attachPrimitive(window.PlatformChartMarkers.createPaneSolidBackdrop('#0e1018'));
                }
            } catch (_) {}
            try {
                if (wrLine && levels.show && window.PlatformChartMarkers
                    && typeof window.PlatformChartMarkers.attachRsiLevelLines === 'function') {
                    rsiLevelLines.push(...window.PlatformChartMarkers.attachRsiLevelLines(wrLine, levels));
                }
            } catch (_) {}
        }

        // AO — гистограмма (зелёная/красная по знаку).
        if (wantAo) {
            const st = lastAoPayload.style || {};
            const ser = lastAoPayload.series || {};
            const histColor = st.color_histogram || '#006400';
            const histNeg = st.color_histogram_neg || '#f43f5e';
            let paneIndex = 1;
            try {
                const pane = chart.addPane(false);
                if (pane && typeof pane.setStretchFactor === 'function') {
                    pane.setStretchFactor(0.32);
                }
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[indicators] addPane AO', e);
            }
            if (HistogramSeries && Array.isArray(ser.ao) && ser.ao.length) {
                try {
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
                        color: Number(p.value) >= 0 ? histColor : histNeg,
                    })));
                    classicLineSeries['ao_hist'] = hs;
                } catch (e) {
                    console.error('[indicators] AO histogram', e);
                }
            }
        }

        // Aroon — pane Up/Down + уровни 70/30.
        if (wantAroon) {
            const st = lastAroonPayload.style || {};
            const levels = lastAroonPayload.levels || {};
            const ser = lastAroonPayload.series || {};
            const lineW = st.line_width || 1;
            let paneIndex = 1;
            let aroonPane = null;
            try {
                aroonPane = chart.addPane(false);
                if (aroonPane && typeof aroonPane.setStretchFactor === 'function') {
                    aroonPane.setStretchFactor(0.28);
                }
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[indicators] addPane Aroon', e);
            }
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
                const paneApi = aroonPane || (typeof chart.panes === 'function' ? chart.panes()[paneIndex] : null);
                if (paneApi && typeof paneApi.attachPrimitive === 'function'
                    && window.PlatformChartMarkers
                    && typeof window.PlatformChartMarkers.createPaneSolidBackdrop === 'function') {
                    paneApi.attachPrimitive(window.PlatformChartMarkers.createPaneSolidBackdrop('#0e1018'));
                }
            } catch (_) {}
            try {
                const anchor = upLine || classicLineSeries['aroon_down'];
                if (anchor && levels.show && window.PlatformChartMarkers
                    && typeof window.PlatformChartMarkers.attachRsiLevelLines === 'function') {
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
                console.error('[indicators] addPane Momentum', e);
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
                if (paneApi && typeof paneApi.attachPrimitive === 'function'
                    && window.PlatformChartMarkers
                    && typeof window.PlatformChartMarkers.createPaneSolidBackdrop === 'function') {
                    paneApi.attachPrimitive(window.PlatformChartMarkers.createPaneSolidBackdrop('#0e1018'));
                }
            } catch (e) {
                console.error('[indicators] Momentum pane backdrop', e);
            }
            try {
                if (momentumLine && levels.show && window.PlatformChartMarkers
                    && typeof window.PlatformChartMarkers.attachRsiLevelLines === 'function') {
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
                console.error('[indicators] addPane ROC', e);
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
                if (paneApi && typeof paneApi.attachPrimitive === 'function'
                    && window.PlatformChartMarkers
                    && typeof window.PlatformChartMarkers.createPaneSolidBackdrop === 'function') {
                    paneApi.attachPrimitive(window.PlatformChartMarkers.createPaneSolidBackdrop('#0e1018'));
                }
            } catch (e) {
                console.error('[indicators] ROC pane backdrop', e);
            }
            try {
                if (rocLine && levels.show && window.PlatformChartMarkers
                    && typeof window.PlatformChartMarkers.attachRsiLevelLines === 'function') {
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
                console.error('[indicators] addPane OsMA', e);
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
                    console.error('[indicators] OsMA histogram', e);
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
                console.error('[indicators] addPane TRIX', e);
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
                if (paneApi && typeof paneApi.attachPrimitive === 'function'
                    && window.PlatformChartMarkers
                    && typeof window.PlatformChartMarkers.createPaneSolidBackdrop === 'function') {
                    paneApi.attachPrimitive(window.PlatformChartMarkers.createPaneSolidBackdrop('#0e1018'));
                }
            } catch (e) {
                console.error('[indicators] TRIX pane backdrop', e);
            }
            try {
                if (trixLine && levels.show && window.PlatformChartMarkers
                    && typeof window.PlatformChartMarkers.attachRsiLevelLines === 'function') {
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
                console.error('[indicators] addPane CMO', e);
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
                if (paneApi && typeof paneApi.attachPrimitive === 'function'
                    && window.PlatformChartMarkers
                    && typeof window.PlatformChartMarkers.createPaneSolidBackdrop === 'function') {
                    paneApi.attachPrimitive(window.PlatformChartMarkers.createPaneSolidBackdrop('#0e1018'));
                }
            } catch (e) {
                console.error('[indicators] CMO pane backdrop', e);
            }
            try {
                if (cmoLine && levels.show && window.PlatformChartMarkers
                    && typeof window.PlatformChartMarkers.attachRsiLevelLines === 'function') {
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
                console.error('[indicators] addPane DeMarker', e);
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
                if (paneApi && typeof paneApi.attachPrimitive === 'function'
                    && window.PlatformChartMarkers
                    && typeof window.PlatformChartMarkers.createPaneSolidBackdrop === 'function') {
                    paneApi.attachPrimitive(window.PlatformChartMarkers.createPaneSolidBackdrop('#0e1018'));
                }
            } catch (e) {
                console.error('[indicators] DeMarker pane backdrop', e);
            }
            try {
                if (demLine && levels.show && window.PlatformChartMarkers
                    && typeof window.PlatformChartMarkers.attachRsiLevelLines === 'function') {
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
                console.error('[indicators] addPane RVI', e);
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
                if (paneApi && typeof paneApi.attachPrimitive === 'function'
                    && window.PlatformChartMarkers
                    && typeof window.PlatformChartMarkers.createPaneSolidBackdrop === 'function') {
                    paneApi.attachPrimitive(window.PlatformChartMarkers.createPaneSolidBackdrop('#0e1018'));
                }
            } catch (e) {
                console.error('[indicators] RVI pane backdrop', e);
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
                console.error('[indicators] addPane Ultimate', e);
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
                if (paneApi && typeof paneApi.attachPrimitive === 'function'
                    && window.PlatformChartMarkers
                    && typeof window.PlatformChartMarkers.createPaneSolidBackdrop === 'function') {
                    paneApi.attachPrimitive(window.PlatformChartMarkers.createPaneSolidBackdrop('#0e1018'));
                }
            } catch (e) {
                console.error('[indicators] Ultimate pane backdrop', e);
            }
            try {
                if (uoLine && levels.show && window.PlatformChartMarkers
                    && typeof window.PlatformChartMarkers.attachRsiLevelLines === 'function') {
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
                console.error('[indicators] addPane SMI', e);
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
                if (paneApi && typeof paneApi.attachPrimitive === 'function'
                    && window.PlatformChartMarkers
                    && typeof window.PlatformChartMarkers.createPaneSolidBackdrop === 'function') {
                    paneApi.attachPrimitive(window.PlatformChartMarkers.createPaneSolidBackdrop('#0e1018'));
                }
            } catch (e) {
                console.error('[indicators] SMI pane backdrop', e);
            }
            try {
                if (smiLine && levels.show && window.PlatformChartMarkers
                    && typeof window.PlatformChartMarkers.attachRsiLevelLines === 'function') {
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
                console.error('[indicators] addPane StdDev', e);
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
                console.error('[indicators] addPane Ivashov', e);
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

        // Stoch RSI — pane 0..100 с %K/%D и уровнями (как Stochastic).
        if (wantStochRsi) {
            const st = lastStochRsiPayload.style || {};
            const levels = lastStochRsiPayload.levels || {};
            const ser = lastStochRsiPayload.series || {};
            const lineW = st.line_width || 1;
            let paneIndex = 1;
            let stochRsiPane = null;
            try {
                stochRsiPane = chart.addPane(false);
                if (stochRsiPane && typeof stochRsiPane.setStretchFactor === 'function') {
                    stochRsiPane.setStretchFactor(0.28);
                }
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[indicators] addPane Stoch RSI', e);
            }
            const addStochRsiLine = (key, data, color, showLast) => {
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
            const kLine = addStochRsiLine('stoch_rsi_k', ser.k, st.color_k || '#1e90ff', true);
            addStochRsiLine('stoch_rsi_d', ser.d, st.color_d || '#8b0000', false);
            try {
                const paneApi = stochRsiPane || (typeof chart.panes === 'function' ? chart.panes()[paneIndex] : null);
                if (paneApi && typeof paneApi.attachPrimitive === 'function'
                    && window.PlatformChartMarkers
                    && typeof window.PlatformChartMarkers.createPaneSolidBackdrop === 'function') {
                    paneApi.attachPrimitive(window.PlatformChartMarkers.createPaneSolidBackdrop('#0e1018'));
                }
            } catch (_) {}
            try {
                const anchor = kLine || classicLineSeries['stoch_rsi_d'];
                if (anchor && levels.show && window.PlatformChartMarkers
                    && typeof window.PlatformChartMarkers.attachRsiLevelLines === 'function') {
                    rsiLevelLines.push(...window.PlatformChartMarkers.attachRsiLevelLines(anchor, levels));
                }
            } catch (_) {}
        }

        // AC — гистограмма по знаку (как AO, серия ac).
        if (wantAc) {
            const st = lastAcPayload.style || {};
            const ser = lastAcPayload.series || {};
            const histColor = st.color_histogram || '#006400';
            const histNeg = st.color_histogram_neg || '#f43f5e';
            let paneIndex = 1;
            try {
                const pane = chart.addPane(false);
                if (pane && typeof pane.setStretchFactor === 'function') pane.setStretchFactor(0.32);
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[indicators] addPane AC', e);
            }
            if (HistogramSeries && Array.isArray(ser.ac) && ser.ac.length) {
                try {
                    const hs = chart.addSeries(HistogramSeries, {
                        priceLineVisible: false,
                        lastValueVisible: false,
                        baseLineVisible: true,
                        baseLineColor: 'rgba(161,161,170,0.45)',
                        baseLineWidth: 1,
                        baseLineStyle: 2,
                    }, paneIndex);
                    hs.setData(ser.ac.map((p) => ({
                        time: p.time,
                        value: p.value,
                        color: Number(p.value) >= 0 ? histColor : histNeg,
                    })));
                    classicLineSeries['ac_hist'] = hs;
                } catch (e) {
                    console.error('[indicators] AC histogram', e);
                }
            }
        }

        // Bulls Power — гистограмма bulls_power.
        if (wantBullsPower) {
            const st = lastBullsPowerPayload.style || {};
            const ser = lastBullsPowerPayload.series || {};
            const histColor = st.color_histogram || '#006400';
            const histNeg = st.color_histogram_neg || '#f43f5e';
            let paneIndex = 1;
            try {
                const pane = chart.addPane(false);
                if (pane && typeof pane.setStretchFactor === 'function') pane.setStretchFactor(0.32);
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[indicators] addPane Bulls Power', e);
            }
            if (HistogramSeries && Array.isArray(ser.bulls_power) && ser.bulls_power.length) {
                try {
                    const hs = chart.addSeries(HistogramSeries, {
                        priceLineVisible: false,
                        lastValueVisible: false,
                        baseLineVisible: true,
                        baseLineColor: 'rgba(161,161,170,0.45)',
                        baseLineWidth: 1,
                        baseLineStyle: 2,
                    }, paneIndex);
                    hs.setData(ser.bulls_power.map((p) => ({
                        time: p.time,
                        value: p.value,
                        color: Number(p.value) >= 0 ? histColor : histNeg,
                    })));
                    classicLineSeries['bulls_power_hist'] = hs;
                } catch (e) {
                    console.error('[indicators] Bulls Power histogram', e);
                }
            }
        }

        // Bears Power — гистограмма bears_power.
        if (wantBearsPower) {
            const st = lastBearsPowerPayload.style || {};
            const ser = lastBearsPowerPayload.series || {};
            const histColor = st.color_histogram || '#006400';
            const histNeg = st.color_histogram_neg || '#f43f5e';
            let paneIndex = 1;
            try {
                const pane = chart.addPane(false);
                if (pane && typeof pane.setStretchFactor === 'function') pane.setStretchFactor(0.32);
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[indicators] addPane Bears Power', e);
            }
            if (HistogramSeries && Array.isArray(ser.bears_power) && ser.bears_power.length) {
                try {
                    const hs = chart.addSeries(HistogramSeries, {
                        priceLineVisible: false,
                        lastValueVisible: false,
                        baseLineVisible: true,
                        baseLineColor: 'rgba(161,161,170,0.45)',
                        baseLineWidth: 1,
                        baseLineStyle: 2,
                    }, paneIndex);
                    hs.setData(ser.bears_power.map((p) => ({
                        time: p.time,
                        value: p.value,
                        color: Number(p.value) >= 0 ? histColor : histNeg,
                    })));
                    classicLineSeries['bears_power_hist'] = hs;
                } catch (e) {
                    console.error('[indicators] Bears Power histogram', e);
                }
            }
        }

        // Простая pane-линия для DPO / QStick / VHFilter / Efficiency Ratio.
        const addSimpleOscPaneLine = (key, payload, seriesKey, colorKey) => {
            if (!payload) return;
            const st = payload.style || {};
            const data = (payload.series || {})[seriesKey] || [];
            let paneIndex = 1;
            try {
                const pane = chart.addPane(false);
                if (pane && typeof pane.setStretchFactor === 'function') pane.setStretchFactor(0.28);
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[indicators] addPane', key, e);
                return;
            }
            if (!Array.isArray(data) || !data.length) return;
            const s = chart.addSeries(LineSeries, {
                color: st[colorKey] || st.color || '#00ffff',
                lineWidth: st.line_width || 1,
                priceLineVisible: false,
                baseLineVisible: false,
                lastValueVisible: true,
                crosshairMarkerVisible: false,
                priceFormat: { type: 'price', precision: 2, minMove: 0.01 },
            }, paneIndex);
            s.setData(data.map((p) => ({ time: p.time, value: p.value })));
            classicLineSeries[key] = s;
        };
        if (wantDpo) addSimpleOscPaneLine('dpo', lastDpoPayload, 'dpo', 'color');
        if (wantQstick) addSimpleOscPaneLine('qstick', lastQstickPayload, 'qstick', 'color');
        if (wantVhFilter) addSimpleOscPaneLine('vh_filter', lastVhFilterPayload, 'vh_filter', 'color');
        if (wantEfficiencyRatio) addSimpleOscPaneLine('efficiency_ratio', lastEfficiencyRatioPayload, 'er', 'color');

        // RAVI — линия ravi + up_line/down_line.
        if (wantRavi) {
            const st = lastRaviPayload.style || {};
            const ser = lastRaviPayload.series || {};
            const lineW = st.line_width || 1;
            let paneIndex = 1;
            try {
                const pane = chart.addPane(false);
                if (pane && typeof pane.setStretchFactor === 'function') pane.setStretchFactor(0.28);
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[indicators] addPane RAVI', e);
            }
            const addRaviLine = (key, data, color, showLast) => {
                if (!Array.isArray(data) || !data.length) return null;
                const s = chart.addSeries(LineSeries, {
                    color: color || '#8b0000',
                    lineWidth: lineW,
                    priceLineVisible: false,
                    lastValueVisible: !!showLast,
                    crosshairMarkerVisible: false,
                    priceFormat: { type: 'price', precision: 2, minMove: 0.01 },
                }, paneIndex);
                s.setData(data.map((p) => ({ time: p.time, value: p.value })));
                classicLineSeries[key] = s;
                return s;
            };
            addRaviLine('ravi', ser.ravi, st.color_ravi, true);
            addRaviLine('ravi_up_line', ser.up_line, st.color_up_line, false);
            addRaviLine('ravi_down_line', ser.down_line, st.color_down_line, false);
        }

        // COG — cog + prev_cog.
        if (wantCog) {
            const st = lastCogPayload.style || {};
            const ser = lastCogPayload.series || {};
            const lineW = st.line_width || 1;
            let paneIndex = 1;
            try {
                const pane = chart.addPane(false);
                if (pane && typeof pane.setStretchFactor === 'function') pane.setStretchFactor(0.28);
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[indicators] addPane COG', e);
            }
            const addCogLine = (key, data, color, showLast) => {
                if (!Array.isArray(data) || !data.length) return;
                const s = chart.addSeries(LineSeries, {
                    color: color || '#1e90ff',
                    lineWidth: lineW,
                    priceLineVisible: false,
                    lastValueVisible: !!showLast,
                    crosshairMarkerVisible: false,
                    priceFormat: { type: 'price', precision: 2, minMove: 0.01 },
                }, paneIndex);
                s.setData(data.map((p) => ({ time: p.time, value: p.value })));
                classicLineSeries[key] = s;
            };
            addCogLine('cog', ser.cog, st.color_cog, true);
            addCogLine('prev_cog', ser.prev_cog, st.color_prev_cog, false);
        }

        // Mass Index — mi + level_up/level_down.
        if (wantMassIndex) {
            const st = lastMassIndexPayload.style || {};
            const ser = lastMassIndexPayload.series || {};
            const lineW = st.line_width || 1;
            let paneIndex = 1;
            try {
                const pane = chart.addPane(false);
                if (pane && typeof pane.setStretchFactor === 'function') pane.setStretchFactor(0.28);
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[indicators] addPane Mass Index', e);
            }
            const addMiLine = (key, data, color, showLast) => {
                if (!Array.isArray(data) || !data.length) return null;
                const s = chart.addSeries(LineSeries, {
                    color: color || '#1e90ff',
                    lineWidth: lineW,
                    priceLineVisible: false,
                    lastValueVisible: !!showLast,
                    crosshairMarkerVisible: false,
                    priceFormat: { type: 'price', precision: 2, minMove: 0.01 },
                }, paneIndex);
                s.setData(data.map((p) => ({ time: p.time, value: p.value })));
                classicLineSeries[key] = s;
                return s;
            };
            addMiLine('mass_index_mi', ser.mi, st.color_mi, true);
            addMiLine('mass_index_level_up', ser.level_up, st.color_level, false);
            addMiLine('mass_index_level_down', ser.level_down, st.color_level, false);
        }

        // Priority-1 oscillator panes.
        if (wantVolatilityAverage) {
            const st = lastVolatilityAveragePayload.style || {};
            const ser = lastVolatilityAveragePayload.series || {};
            const lineW = st.line_width || 1;
            let paneIndex = 1;
            try {
                const pane = chart.addPane(false);
                if (pane && typeof pane.setStretchFactor === 'function') pane.setStretchFactor(0.28);
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[indicators] addPane Vol Average', e);
            }
            const addOscLine = (okey, data, color, showLast) => {
                if (!Array.isArray(data) || !data.length) return;
                const s = chart.addSeries(LineSeries, {
                    color: color || '#1e90ff',
                    lineWidth: lineW,
                    priceLineVisible: false,
                    lastValueVisible: !!showLast,
                    crosshairMarkerVisible: false,
                    priceFormat: { type: 'price', precision: 2, minMove: 0.01 },
                }, paneIndex);
                s.setData(data.map((p) => ({ time: p.time, value: p.value })));
                classicLineSeries[okey] = s;
            };
            addOscLine('va_vol', ser.volatility, st.color_volatility, true);
            addOscLine('va_sma', ser.sma, st.color_sma, false);
        }
        if (wantVolatilityAverageChannel) {
            const st = lastVolatilityAverageChannelPayload.style || {};
            const ser = lastVolatilityAverageChannelPayload.series || {};
            const lineW = st.line_width || 1;
            let paneIndex = 1;
            try {
                const pane = chart.addPane(false);
                if (pane && typeof pane.setStretchFactor === 'function') pane.setStretchFactor(0.28);
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[indicators] addPane Vol Avg Channel', e);
            }
            const addOscLine = (okey, data, color, showLast) => {
                if (!Array.isArray(data) || !data.length) return;
                const s = chart.addSeries(LineSeries, {
                    color: color || '#1e90ff',
                    lineWidth: lineW,
                    priceLineVisible: false,
                    lastValueVisible: !!showLast,
                    crosshairMarkerVisible: false,
                    priceFormat: { type: 'price', precision: 2, minMove: 0.01 },
                }, paneIndex);
                s.setData(data.map((p) => ({ time: p.time, value: p.value })));
                classicLineSeries[okey] = s;
            };
            addOscLine('vac_vol', ser.volatility, st.color_volatility, true);
            addOscLine('vac_sma_fast', ser.sma_fast, st.color_sma_fast, false);
            addOscLine('vac_sma_slow', ser.sma_slow, st.color_sma_slow, false);
            addOscLine('vac_up', ser.up, st.color_up, false);
            addOscLine('vac_down', ser.down, st.color_down, false);
        }
        if (wantPriceOscillator) {
            const st = lastPriceOscillatorPayload.style || {};
            const ser = lastPriceOscillatorPayload.series || {};
            const lineW = st.line_width || 1;
            let paneIndex = 1;
            try {
                const pane = chart.addPane(false);
                if (pane && typeof pane.setStretchFactor === 'function') pane.setStretchFactor(0.28);
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[indicators] addPane Price Oscillator', e);
            }
            const addOscLine = (okey, data, color, showLast) => {
                if (!Array.isArray(data) || !data.length) return;
                const s = chart.addSeries(LineSeries, {
                    color: color || '#1e90ff',
                    lineWidth: lineW,
                    priceLineVisible: false,
                    lastValueVisible: !!showLast,
                    crosshairMarkerVisible: false,
                    priceFormat: { type: 'price', precision: 2, minMove: 0.01 },
                }, paneIndex);
                s.setData(data.map((p) => ({ time: p.time, value: p.value })));
                classicLineSeries[okey] = s;
            };
            addOscLine('po', ser.po, st.color, true);
        }
        if (wantAdaptiveLookBack) {
            const st = lastAdaptiveLookBackPayload.style || {};
            const ser = lastAdaptiveLookBackPayload.series || {};
            const lineW = st.line_width || 1;
            let paneIndex = 1;
            try {
                const pane = chart.addPane(false);
                if (pane && typeof pane.setStretchFactor === 'function') pane.setStretchFactor(0.28);
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[indicators] addPane Adaptive Look Back', e);
            }
            const addOscLine = (okey, data, color, showLast) => {
                if (!Array.isArray(data) || !data.length) return;
                const s = chart.addSeries(LineSeries, {
                    color: color || '#1e90ff',
                    lineWidth: lineW,
                    priceLineVisible: false,
                    lastValueVisible: !!showLast,
                    crosshairMarkerVisible: false,
                    priceFormat: { type: 'price', precision: 2, minMove: 0.01 },
                }, paneIndex);
                s.setData(data.map((p) => ({ time: p.time, value: p.value })));
                classicLineSeries[okey] = s;
            };
            addOscLine('alb', ser.alb, st.color, true);
        }
        // VolatilityCandles — относительная амплитуда бара (%) в отдельной pane.
        if (wantVolatilityCandles) {
            const st = lastVolatilityCandlesPayload.style || {};
            const ser = lastVolatilityCandlesPayload.series || {};
            const lineW = st.line_width || 1;
            let paneIndex = 1;
            try {
                const pane = chart.addPane(false);
                if (pane && typeof pane.setStretchFactor === 'function') pane.setStretchFactor(0.28);
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[indicators] addPane Volatility Candles', e);
            }
            const addOscLine = (okey, data, color, showLast) => {
                if (!Array.isArray(data) || !data.length) return;
                const s = chart.addSeries(LineSeries, {
                    color: color || '#00ffff',
                    lineWidth: lineW,
                    priceLineVisible: false,
                    lastValueVisible: !!showLast,
                    crosshairMarkerVisible: false,
                    priceFormat: { type: 'price', precision: 3, minMove: 0.001 },
                }, paneIndex);
                s.setData(data.map((p) => ({ time: p.time, value: p.value })));
                classicLineSeries[okey] = s;
            };
            addOscLine('vc', ser.vc, st.color, true);
        }

        // VolatilityAverageTwice — вола + fast/slow SMA в отдельной pane.
        if (wantVolatilityAverageTwice) {
            const st = lastVolatilityAverageTwicePayload.style || {};
            const ser = lastVolatilityAverageTwicePayload.series || {};
            const lineW = st.line_width || 1;
            let paneIndex = 1;
            try {
                const pane = chart.addPane(false);
                if (pane && typeof pane.setStretchFactor === 'function') pane.setStretchFactor(0.28);
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[indicators] addPane Vol Avg Twice', e);
            }
            const addOscLine = (okey, data, color, showLast) => {
                if (!Array.isArray(data) || !data.length) return;
                const s = chart.addSeries(LineSeries, {
                    color: color || '#1e90ff',
                    lineWidth: lineW,
                    priceLineVisible: false,
                    lastValueVisible: !!showLast,
                    crosshairMarkerVisible: false,
                    priceFormat: { type: 'price', precision: 2, minMove: 0.01 },
                }, paneIndex);
                s.setData(data.map((p) => ({ time: p.time, value: p.value })));
                classicLineSeries[okey] = s;
            };
            addOscLine('vat_vol', ser.volatility, st.color_volatility, true);
            addOscLine('vat_fast', ser.sma_fast, st.color_sma_fast, false);
            addOscLine('vat_slow', ser.sma_slow, st.color_sma_slow, false);
        }
        // VolatilityStagesAW — две pane: стадии (1..4) отдельно от канала/SMA волы.
        // Иначе ordinal stages≈2 и SMA≈0.7 на одной шкале: канал «прилипает» к низу и кажется, что индикатор не рисуется.
        if (wantVolatilityStagesAw) {
            const st = lastVolatilityStagesAwPayload.style || {};
            const ser = lastVolatilityStagesAwPayload.series || {};
            const lineW = st.line_width || 1;

            const addOscLineOnPane = (okey, data, color, showLast, paneIndex, precision) => {
                if (!Array.isArray(data) || !data.length) return;
                const s = chart.addSeries(LineSeries, {
                    color: color || '#008000',
                    lineWidth: lineW,
                    priceLineVisible: false,
                    lastValueVisible: !!showLast,
                    crosshairMarkerVisible: false,
                    priceFormat: { type: 'price', precision: precision, minMove: Math.pow(10, -precision) },
                }, paneIndex);
                s.setData(data.map((p) => ({ time: p.time, value: p.value })));
                classicLineSeries[okey] = s;
            };

            // Pane 1: только стадии режима (целые уровни).
            if (Array.isArray(ser.stages) && ser.stages.length) {
                let stagesPaneIndex = 1;
                try {
                    const pane = chart.addPane(false);
                    if (pane && typeof pane.setStretchFactor === 'function') pane.setStretchFactor(0.22);
                    stagesPaneIndex = Math.max(1, chart.panes().length - 1);
                    classicOscPaneCount += 1;
                } catch (e) {
                    console.error('[indicators] addPane Vol Stages AW (stages)', e);
                }
                addOscLineOnPane('vsa_stages', ser.stages, st.color_stages, true, stagesPaneIndex, 0);
            }

            // Pane 2: volatility + SMA + границы канала (одна масштабная «семья»).
            const channelSeries = [
                ['vsa_vol', ser.volatility, st.color_volatility || '#1e90ff', true],
                ['vsa_fast', ser.sma_fast, st.color_sma_fast || '#8b0000', false],
                ['vsa_slow', ser.sma_slow, st.color_sma_slow || '#f5f5f5', true],
                ['vsa_up', ser.up, st.color_up || '#f5f5f5', false],
                ['vsa_down', ser.down, st.color_down || '#f5f5f5', false],
            ].filter((row) => Array.isArray(row[1]) && row[1].length);

            if (channelSeries.length) {
                let channelPaneIndex = 1;
                try {
                    const pane = chart.addPane(false);
                    if (pane && typeof pane.setStretchFactor === 'function') pane.setStretchFactor(0.28);
                    channelPaneIndex = Math.max(1, chart.panes().length - 1);
                    classicOscPaneCount += 1;
                } catch (e) {
                    console.error('[indicators] addPane Vol Stages AW (channel)', e);
                }
                channelSeries.forEach(([okey, data, color, showLast]) => {
                    addOscLineOnPane(okey, data, color, showLast, channelPaneIndex, 2);
                });
            }
        }

        // ATR — ещё один pane под RSI/MACD/Stochastic/CCI (или единственный нижний).
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
                console.error('[indicators] addPane ATR', e);
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

    /** Включён ли хотя бы один слой, требующий /indicators/data. */
    function anyOverlayIndicatorLayerOn() {
        return !!(
            (layerPa && layerPa.checked) ||
            (layerLiquidity && layerLiquidity.checked) ||
            (layerSmc && layerSmc.checked) ||
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
            (layerFractal && layerFractal.checked) ||
            (layerDonchian && layerDonchian.checked) ||
            (layerStochRsi && layerStochRsi.checked) ||
            (layerAc && layerAc.checked) ||
            (layerBullsPower && layerBullsPower.checked) ||
            (layerBearsPower && layerBearsPower.checked) ||
            (layerDpo && layerDpo.checked) ||
            (layerQstick && layerQstick.checked) ||
            (layerVhFilter && layerVhFilter.checked) ||
            (layerEfficiencyRatio && layerEfficiencyRatio.checked) ||
            (layerRavi && layerRavi.checked) ||
            (layerCog && layerCog.checked) ||
            (layerPivotFloor && layerPivotFloor.checked) ||
            (layerMassIndex && layerMassIndex.checked) ||
            (layerNrtrWatr && layerNrtrWatr.checked) ||
            (layerPriceChannelAdaptive && layerPriceChannelAdaptive.checked) ||
            (layerPriceChannelOffset && layerPriceChannelOffset.checked) ||
            (layerParabolicBollinger && layerParabolicBollinger.checked) ||
            (layerParabolicPriceChannel && layerParabolicPriceChannel.checked) ||
            (layerAtrChannel && layerAtrChannel.checked) ||
            (layerOffsetSma && layerOffsetSma.checked) ||
            (layerOffsetEma && layerOffsetEma.checked) ||
            (layerOffsetSsma && layerOffsetSsma.checked) ||
            (layerVolatilityAverage && layerVolatilityAverage.checked) ||
            (layerVolatilityAverageChannel && layerVolatilityAverageChannel.checked) ||
            (layerPriceOscillator && layerPriceOscillator.checked) ||
            (layerDynamicTrendDetector && layerDynamicTrendDetector.checked) ||
            (layerAdaptiveLookBack && layerAdaptiveLookBack.checked) ||
            (layerLinRegChannelFast && layerLinRegChannelFast.checked) ||
            (layerVolatilityCandles && layerVolatilityCandles.checked) ||
            (layerZigZag && layerZigZag.checked) ||
            (layerVolatilityAverageTwice && layerVolatilityAverageTwice.checked) ||
            (layerVolatilityStagesAw && layerVolatilityStagesAw.checked)
        );
    }

    /** Размер подписей объёма (Labels Size): tiny / small / normal → px для DOM-оверлея. */
    function lsLabelFontPx(sz) {
        const s = (sz || 'tiny').toString().toLowerCase();
        if (s === 'small') return '11px';
        if (s === 'normal') return '13px';
        return '9px';
    }

    /** Оверлей Liquidity Swings (зоны, пунктиры, подписи объёма). */
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
            console.error('[Liquidity Swings] bot redraw', e);
        }
    }

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
            // Зоны рендерим первым слоем (под линиями/лейблами), как в TradingView.
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
            console.error('[SMC Flow] bot redraw', e);
        }
    }

    /** Рисует линии и зоны индикатора поверх графика (HTML-оверлей, как ордерблоки). */
    function redrawPriceActionOverlay() {
        redrawLiquiditySwingsOverlay();
        // SMC Flow должен жить отдельно от DGT: обновляем его даже когда PA слой выключен.
        redrawSmcFlowOverlay();
        if (!priceActionOverlay || !chart || !candlestickSeries) return;
        priceActionOverlay.innerHTML = '';
        if (!layerPa || !layerPa.checked || !lastPaPayload || !allChartData.length) return;
        const ts = chart.timeScale();
        /**
         * Боковой VP: колонка у правого края панели, вплотную к ценовой шкале.
         * Ширина шкалы берётся из API (меняется при зуме и длине подписей), а не фиксированные ~78px.
         */
        const vpPanelMaxHistPx = 100;
        const vpPanelMaxDomPx = 26;
        const vpPanelGapPx = 2;
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
        // VP: колонка слева от шкалы цен (anchor по priceScale().width()); по Y — как у свечей (priceToCoordinate).
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
        // Вертикальная линия между total volume и delta (как на эталоне), только если были сегменты VP.
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
            console.error('[PriceAction DGT] redraw overlay', e);
        }
    }

    /** Окраска свечей DGT; на poll — только update хвоста (см. applyIncrementalChartUpdate).
     *  Последний бар во время tween — через candleSmooth.visualFor, иначе PA снэпает close. */
    function applyPaBarColors(opts = {}) {
        const fullResync = opts && opts.fullResync === true;
        if (!candlestickSeries || !allChartData.length) return;
        const plainBar = (c) => {
            const src = candleSmooth ? candleSmooth.visualFor(c) : c;
            return { time: src.time, open: src.open, high: src.high, low: src.low, close: src.close };
        };
        const plain = allChartData.map((c) => ({
            time: c.time, open: c.open, high: c.high, low: c.low, close: c.close,
        }));
        const bc = lastPaPayload && lastPaPayload.bar_colors ? lastPaPayload.bar_colors : null;
        const paOn = layerPa && layerPa.checked && bc && Object.keys(bc).length > 0;
        if (!paOn) {
            if (fullResync) {
                if (candleSmooth) candleSmooth.reset();
                try { candlestickSeries.setData(plain); } catch (e) { console.error('[PA] setData plain', e); }
                if (candleSmooth && allChartData.length) {
                    candleSmooth.snap(allChartData[allChartData.length - 1]);
                }
            }
            return;
        }
        const paintBar = (c) => {
            const src = candleSmooth ? candleSmooth.visualFor(c) : c;
            const o = bc[String(c.time)];
            if (!o) {
                return { time: src.time, open: src.open, high: src.high, low: src.low, close: src.close };
            }
            return {
                time: src.time, open: src.open, high: src.high, low: src.low, close: src.close,
                color: o.color, borderColor: o.borderColor || o.color, wickColor: o.wickColor || o.color,
            };
        };
        if (fullResync) {
            if (candleSmooth) candleSmooth.reset();
            try {
                candlestickSeries.setData(allChartData.map(paintBar));
            } catch (e) {
                console.error('[PA] setData painted', e);
                try { candlestickSeries.setData(plain); } catch (e2) { console.error('[PA] setData fallback', e2); }
            }
            if (candleSmooth && allChartData.length) {
                candleSmooth.snap(allChartData[allChartData.length - 1]);
            }
            return;
        }
        const barSec = timeframeSeconds(currentTimeframeMinutes);
        const lastT = allChartData[allChartData.length - 1].time;
        const tailFrom = lastT - barSec * 2;
        for (const c of allChartData) {
            if (c.time >= tailFrom) {
                if (c.time === lastT && candleSmooth && candleSmooth.isAnimating()) {
                    continue;
                }
                try { candlestickSeries.update(paintBar(c)); } catch (e) { console.error('[PA] update', e); }
            }
        }
    }

    /** Цвета DGT для кадра плавной анимации последней свечи. */
    function paintBarForSmooth(bar) {
        if (!bar) return bar;
        const bc = lastPaPayload && lastPaPayload.bar_colors ? lastPaPayload.bar_colors : null;
        const paOn = layerPa && layerPa.checked && bc && Object.keys(bc).length > 0;
        if (!paOn) {
            return { time: bar.time, open: bar.open, high: bar.high, low: bar.low, close: bar.close };
        }
        const o = bc[String(bar.time)];
        if (!o) {
            return { time: bar.time, open: bar.open, high: bar.high, low: bar.low, close: bar.close };
        }
        return {
            time: bar.time, open: bar.open, high: bar.high, low: bar.low, close: bar.close,
            color: o.color, borderColor: o.borderColor || o.color, wickColor: o.wickColor || o.color,
        };
    }

    function toBucketTime(unixTs) {
        const t = Number(unixTs || 0);
        return t > 0 ? Math.floor(t / 60) * 60 : null;
    }
    // Унифицированный парсер времени для всех источников (ISO, unix sec/ms, MySQL datetime).
    // ВАЖНО: timezone-преобразование выполняется только на ingest-слое (backend),
    // поэтому здесь не добавляем ручные +03:00/-03:00 — просто корректно парсим вход.
    function toUnixSec(value) {
        if (value == null || value === '') return null;
        if (typeof value === 'number' && Number.isFinite(value)) {
            return value > 1e12 ? Math.floor(value / 1000) : Math.floor(value);
        }
        if (typeof value === 'string') {
            const raw = value.trim();
            if (raw === '') return null;
            const num = Number(raw);
            if (Number.isFinite(num)) {
                return num > 1e12 ? Math.floor(num / 1000) : Math.floor(num);
            }
            const hasTimezone = /([zZ]|[+\-]\d{2}:?\d{2})$/.test(raw);
            const isoLike = raw.includes('T');
            const normalized = (!hasTimezone && !isoLike && raw.includes(' '))
                ? `${raw.replace(' ', 'T')}Z`
                : raw;
            const ms = Date.parse(normalized);
            if (Number.isFinite(ms)) return Math.floor(ms / 1000);
        }
        return null;
    }
    // Форматирует timestamp одновременно в локальном времени браузера и в UTC:
    // это убирает визуальную путаницу "отставания" на несколько часов при сравнении с server `date`.
    function formatDebugTs(ts) {
        if (!Number.isFinite(ts) || ts <= 0) return '—';
        const d = new Date(ts * 1000);
        const local = d.toLocaleString('ru-RU', {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hour12: false,
            timeZoneName: 'short',
        });
        const utc = d.toISOString();
        return `${local} (UTC ${utc})`;
    }
    // Обновляет debug-плашку времени. Вызывается после обновления данных и по таймеру,
    // чтобы видеть текущее клиентское время в реальном времени.
    function renderTimeDebugPanel() {
        if (!timeDebugPanel || !timeDebugServer || !timeDebugClient || !timeDebugPlatform || !timeDebugMarker) return;
        const nowSec = Math.floor(Date.now() / 1000);
        const serverTsCandidates = Object.values(lastServerTimes)
            .map((x) => toUnixSec(x))
            .filter((x) => Number.isFinite(x) && x > 0);
        const serverTs = serverTsCandidates.length ? Math.max(...serverTsCandidates) : null;
        const markerTimes = markerTimesForInstrument(currentInstrument);
        const lastMarkerTs = markerTimes.length ? Math.max(...markerTimes) : null;
        const drift = (serverTs != null) ? (nowSec - serverTs) : null;
        const rawFeedTs = toUnixSec(lastFeedDebug.rawFeedTime);
        const effectiveFeedTs = toUnixSec(lastFeedDebug.effectiveFeedTime);
        const ingestFeedTs = toUnixSec(lastFeedDebug.ingestReceivedAt);
        const lastClosedCandleTs = toUnixSec(lastFeedDebug.lastClosedCandleTime);
        const feedServerDelta = (serverTs != null && effectiveFeedTs != null)
            ? (effectiveFeedTs - serverTs)
            : null;

        timeDebugServer.textContent = `Серверное время (по последнему ответу API): ${formatDebugTs(serverTs)}${drift == null ? '' : ` (drift ${drift >= 0 ? '+' : ''}${drift}s)`}`;
        timeDebugClient.textContent = `Клиентское время (часы браузера): ${formatDebugTs(nowSec)}`;
        if (timeDebugFeedRaw) {
            timeDebugFeedRaw.textContent = `Время feed (сырое, как пришло от источника): ${formatDebugTs(rawFeedTs)}`;
        }
        if (timeDebugFeedEffective) {
            timeDebugFeedEffective.textContent = `Время feed (используемое в системе): ${formatDebugTs(effectiveFeedTs)}`;
        }
        if (timeDebugFeedIngest) {
            timeDebugFeedIngest.textContent = `Время приема feed на backend (ingest): ${formatDebugTs(ingestFeedTs)}`;
        }
        if (timeDebugFeedClosed) {
            timeDebugFeedClosed.textContent = `Время последней закрытой свечи из БД: ${formatDebugTs(lastClosedCandleTs)}`;
        }
        if (timeDebugFeedDelta) {
            timeDebugFeedDelta.textContent = `Смещение feed ↔ сервер (в секундах): ${feedServerDelta == null ? '—' : `${feedServerDelta >= 0 ? '+' : ''}${feedServerDelta}s`}`;
        }
        timeDebugPlatform.textContent = `Время платформы (по последнему маркеру/сигналу): ${formatDebugTs(lastMarkerTs)}`;
        timeDebugMarker.textContent = `Время последнего маркера на графике: ${formatDebugTs(lastMarkerTs)}`;
    }

    // Форматируем цену для подписи маркера:
    // - принимаем любое "сырое" значение (строка/число/null),
    // - приводим к числу,
    // - показываем ровно 5 знаков после запятой, как в таблицах сделок.
    // Если значение отсутствует или невалидно, возвращаем null и просто не добавляем цену в подпись.
    function formatMarkerPrice(rawPrice) {
        // Делегируем форматирование в общий helper, чтобы логика цены в маркерах
        // была одинаковой на всех страницах графиков.
        if (window.PlatformChartMarkers && typeof window.PlatformChartMarkers.formatMarkerPrice === 'function') {
            return window.PlatformChartMarkers.formatMarkerPrice(rawPrice, 5);
        }
        const p = Number(rawPrice);
        if (!Number.isFinite(p)) return null;
        return p.toFixed(5);
    }

    // Собираем хвост подписи вида " (1.23456)".
    // Отдельная функция нужна, чтобы одинаково добавлять цену и к маркеру входа, и к маркеру завершения.
    function markerPriceSuffix(rawPrice) {
        // Через helper получаем единый формат суффикса " (1.23456)".
        if (window.PlatformChartMarkers && typeof window.PlatformChartMarkers.markerPriceSuffix === 'function') {
            return window.PlatformChartMarkers.markerPriceSuffix(rawPrice, 5);
        }
        const priceText = formatMarkerPrice(rawPrice);
        return priceText ? ` (${priceText})` : '';
    }
    // Единая лента сигналов без дублей:
    // сначала добавляем synthetic (мгновенная визуализация закрытия),
    // затем серверные signals с приоритетом по trade.id.
    function mergedSignals(includeInstrument = null) {
        const byKey = new Map();
        const put = (row, priority) => {
            if (!row) return;
            if (includeInstrument && row.instrument !== includeInstrument) return;
            const tradeId = (row.trade && Number(row.trade.id)) || null;
            const key = Number.isFinite(tradeId) && tradeId > 0
                ? `trade:${tradeId}`
                : `sig:${row.instrument || ''}|${row.signal_at || ''}|${(row.trade && row.trade.expiry_time) || row.expiry_time || ''}|${(row.side || '').toUpperCase()}`;
            const prev = byKey.get(key);
            if (!prev || priority > prev.priority) byKey.set(key, { row, priority });
        };
        (syntheticClosedSignals || []).forEach((s) => put(s, 1));
        (signals || []).forEach((s) => put(s, 2));
        return [...byKey.values()].map((x) => x.row);
    }

    function markersForInstrument(instrument) {
        // Общая сборка истории входов/экспираций — через PlatformChartMarkers (как на dashboard).
        if (window.PlatformChartMarkers && typeof window.PlatformChartMarkers.buildHistoryMarkers === 'function') {
            return window.PlatformChartMarkers.buildHistoryMarkers(mergedSignals(instrument), {
                instrument,
                targetTradeId,
                getFrozenExpiryParams,
            });
        }
        return [];
    }
    function findTargetSignal() {
        if (targetTradeId === null) return null;
        const all = mergedSignals();
        return all.find((s) => {
            const tid = (s.trade && s.trade.id) ? Number(s.trade.id) : null;
            return Number.isFinite(tid) && tid === targetTradeId;
        }) || null;
    }
    function targetTradeMarkerTimeForInstrument(instrument) {
        if (!instrument || targetTradeId === null) return null;
        const s = findTargetSignal();
        if (s && s.instrument === instrument) {
            // Предпочитаем маркер экспирации (итог сделки), fallback — время входа.
            const expiryRaw = (s.trade && s.trade.expiry_time) ? s.trade.expiry_time : s.expiry_time;
            const expiryTs = toBucketTime(toUnixSec(expiryRaw));
            if (Number.isFinite(expiryTs)) return expiryTs;
            const entryTs = toBucketTime(toUnixSec(s.signal_at));
            if (Number.isFinite(entryTs)) return entryTs;
        }
        // Fallback: если в signals записи ещё нет (или это активная сделка), берём время из openTrades.
        const ot = (openTrades || []).find((t) => Number(t.id) === targetTradeId && t.instrument === instrument);
        if (ot) {
            const expiryTs = toBucketTime(toUnixSec(ot.expiry_ts));
            if (Number.isFinite(expiryTs)) return expiryTs;
            const entryTs = toBucketTime(toUnixSec(ot.entry_ts));
            if (Number.isFinite(entryTs)) return entryTs;
        }
        return null;
    }
    function focusTargetTradeOnChart() {
        if (targetTradeFocusDone || !chart || !allChartData.length || !currentInstrument) return;
        const targetTs = targetTradeMarkerTimeForInstrument(currentInstrument);
        if (!Number.isFinite(targetTs)) return;
        const idx = allChartData.findIndex((c) => Number(c.time) >= targetTs);
        if (idx < 0) return;
        // Дефолтный "сделочный" масштаб:
        // держим целевую сделку немного правее центра, чтобы слева было видно предысторию входа,
        // а справа оставалось немного места для ближайших баров (как на референс-скриншоте).
        const TARGET_FOCUS_VISIBLE_BARS = 72;
        const TARGET_FOCUS_LEFT_BARS = 52;
        const TARGET_FOCUS_RIGHT_BARS = TARGET_FOCUS_VISIBLE_BARS - TARGET_FOCUS_LEFT_BARS;
        const from = Math.max(0, idx - TARGET_FOCUS_LEFT_BARS);
        const to = Math.min(allChartData.length - 1 + CHART_RIGHT_GAP_BARS, idx + TARGET_FOCUS_RIGHT_BARS);
        chart.timeScale().setVisibleLogicalRange({ from, to });
        targetTradeFocusDone = true;
    }
    function earliestSignalTsForInstrument(instrument) {
        if (!instrument) return null;
        let minTs = null;
        mergedSignals(instrument).forEach((s) => {
            if (s.instrument !== instrument) return;
            const ts = toUnixSec(s.signal_at);
            if (!Number.isFinite(ts) || ts <= 0) return;
            if (minTs === null || ts < minTs) minTs = ts;
        });
        return minTs;
    }
    function activeTradeMarkersForCurrentInstrument() {
        if (!currentInstrument) return [];
        if (window.PlatformChartMarkers && typeof window.PlatformChartMarkers.buildOpenTradeMarkers === 'function') {
            return window.PlatformChartMarkers.buildOpenTradeMarkers(openTrades || [], {
                instrument: currentInstrument,
                nowSec: Math.floor(Date.now() / 1000),
            });
        }
        return [];
    }
    function renderChartMarkers() {
        if (!candlestickSeries || !currentInstrument) return;
        const base = markersForInstrument(currentInstrument);
        const active = activeTradeMarkersForCurrentInstrument();
        const paMk = (layerPa && layerPa.checked && lastPaPayload && Array.isArray(lastPaPayload.markers)) ? lastPaPayload.markers : [];
        const PCM = window.PlatformChartMarkers;
        const all = (PCM && typeof PCM.mergeMarkers === 'function')
            ? PCM.mergeMarkers(base, active, paMk)
            : [...base, ...active, ...paMk].sort((a, b) => a.time - b.time);
        // LWC v5: setMarkers убран с series — обновляем через createSeriesMarkers plugin.
        if (PCM && typeof PCM.applyMarkers === 'function') {
            PCM.applyMarkers(candlestickMarkers, all);
        } else if (candlestickMarkers && typeof candlestickMarkers.setMarkers === 'function') {
            candlestickMarkers.setMarkers(all);
        }
        renderTradeDurationOverlay();
    }
    /**
     * Формируем интервалы сделок (entry -> expiry) для текущего инструмента.
     * Это используется для "полосы времени" сделки на графике истории.
     */
    function tradeDurationsForInstrument(instrument) {
        if (!instrument) return [];
        if (historyDurationCache.has(instrument)) return historyDurationCache.get(instrument) || [];
        const out = [];
        mergedSignals(instrument).forEach((s) => {
            if (s.instrument !== instrument) return;
            // График в M1: привязываем интервалы к открытию минуты, иначе при seconds>00
            // отрезок попадает в следующую свечу (визуальный сдвиг +1 мин).
            const entryTs = toBucketTime(toUnixSec(s.signal_at));
            const expiryRaw = (s.trade && s.trade.expiry_time) ? s.trade.expiry_time : s.expiry_time;
            const expiryTs = toBucketTime(toUnixSec(expiryRaw));
            if (!Number.isFinite(entryTs) || !Number.isFinite(expiryTs) || expiryTs <= entryTs) return;
            const side = (s.side || '').toUpperCase();
            const result = ((s.trade && s.trade.result) || s.result || 'pending');
            const tradeId = (s.trade && s.trade.id) ? Number(s.trade.id) : null;
            const entryPrice = (s.trade && s.trade.entry_price != null) ? Number(s.trade.entry_price) : null;
            const expiryPrice = (s.trade && s.trade.expiry_price != null) ? Number(s.trade.expiry_price) : null;
            out.push({ entryTs, expiryTs, side, result, tradeId, entryPrice, expiryPrice, source: 'history' });
        });
        historyDurationCache.set(instrument, out);
        return out;
    }
    /**
     * Интервалы активных сделок: рисуем тем же типом полосы, но отдельным цветом,
     * чтобы живые позиции визуально отличались от исторических.
     */
    function activeTradeDurationsForInstrument(instrument) {
        if (!instrument) return [];
        const nowSec = Math.floor(Date.now() / 1000);
        let rows = (openTrades || [])
            .filter((t) => t.instrument === instrument && toUnixSec(t.expiry_ts) > nowSec)
        if (rows.length === 0 && ssrActiveTradeSeed && ssrActiveTradeSeed.instrument === instrument && toUnixSec(ssrActiveTradeSeed.expiry_ts) > nowSec) {
            rows = [ssrActiveTradeSeed];
        }
        return rows
            .map((t) => {
                // Для активных сделок та же нормализация к минутному бакету.
                const entryTs = toBucketTime(toUnixSec(t.entry_ts));
                const expiryTs = toBucketTime(toUnixSec(t.expiry_ts));
                if (!Number.isFinite(entryTs) || !Number.isFinite(expiryTs) || expiryTs <= entryTs) return null;
                const side = (t.side || '').toUpperCase();
                const tradeId = Number(t.id);
                const entryPrice = (t.entry_price != null && t.entry_price !== '') ? Number(t.entry_price) : null;
                return {
                    entryTs,
                    expiryTs,
                    side,
                    result: 'pending',
                    tradeId: Number.isFinite(tradeId) ? tradeId : null,
                    entryPrice,
                    expiryPrice: null,
                    source: 'active',
                };
            })
            .filter(Boolean);
    }
    function resolveDurationX(ts, startTs, endTs) {
        if (!Array.isArray(allChartData) || allChartData.length === 0) return null;
        const lastIdx = allChartData.length - 1;
        let startIdx = allChartData.findIndex((c) => Number(c.time) >= startTs);
        let endIdx = allChartData.findIndex((c) => Number(c.time) >= endTs);
        // Если начало сделки левее текущего окна свечей — рисуем от левой границы буфера, не теряя полосу.
        if (startIdx < 0) startIdx = 0;
        if (endIdx < 0) endIdx = lastIdx;
        if (endIdx <= startIdx) endIdx = Math.min(lastIdx, startIdx + 1);
        let x1 = null;
        let x2 = null;
        if (typeof ts.logicalToCoordinate === 'function') {
            x1 = ts.logicalToCoordinate(startIdx);
            x2 = ts.logicalToCoordinate(endIdx);
        }
        if (x1 == null || Number.isNaN(x1)) x1 = ts.timeToCoordinate(Number(allChartData[startIdx].time));
        if (x2 == null || Number.isNaN(x2)) x2 = ts.timeToCoordinate(Number(allChartData[endIdx].time));
        if (x1 == null || Number.isNaN(x1) || x2 == null || Number.isNaN(x2)) return null;
        return { x1, x2 };
    }
    function resolveCandleAtOrAfterTs(unixTs) {
        if (!Array.isArray(allChartData) || allChartData.length === 0 || !Number.isFinite(unixTs)) return null;
        const bucketTs = toBucketTime(unixTs);
        if (!Number.isFinite(bucketTs)) return null;
        let idx = allChartData.findIndex((c) => Number(c.time) >= bucketTs);
        if (idx < 0) idx = allChartData.length - 1;
        const candle = allChartData[idx];
        if (!candle) return null;
        return { idx, candle };
    }
    function resolveCandleX(ts, unixTs) {
        const resolved = resolveCandleAtOrAfterTs(unixTs);
        if (!resolved) return null;
        const idx = resolved.idx;
        let x = null;
        if (typeof ts.logicalToCoordinate === 'function') {
            x = ts.logicalToCoordinate(idx);
        }
        if (x == null || Number.isNaN(x)) {
            x = ts.timeToCoordinate(Number(resolved.candle.time));
        }
        return (x == null || Number.isNaN(x)) ? null : x;
    }
    function approxPriceByTime(ts) {
        if (!Array.isArray(allChartData) || allChartData.length === 0 || !Number.isFinite(ts)) return null;
        // Ищем ближайшую свечу справа (или последнюю доступную) и берём её close как опорную цену.
        const idx = allChartData.findIndex((c) => Number(c.time) >= ts);
        const c = idx >= 0 ? allChartData[idx] : allChartData[allChartData.length - 1];
        const p = Number(c && c.close);
        return Number.isFinite(p) ? p : null;
    }
    /**
     * Рисуем горизонтальные полосы длительности сделок в нижней части графика.
     * Полоса помогает визуально понять "когда вошли" и "когда закрылась" сделка.
     */
    function renderTradeDurationOverlay() {
        if (!tradeDurationOverlay) return;
        if (!chart || !currentInstrument || !allChartData.length) {
            tradeDurationOverlay.classList.add('hidden');
            tradeDurationOverlay.innerHTML = '';
            return;
        }
        // Перед отрисовкой обновляем кэш замороженных цен.
        updateFrozenExpiryParams();
        const activeDurations = activeTradeDurationsForInstrument(currentInstrument);
        const activeIds = new Set(
            activeDurations
                .map((d) => Number(d.tradeId))
                .filter((id) => Number.isFinite(id) && id > 0)
        );
        const historyDurations = tradeDurationsForInstrument(currentInstrument)
            // Если сделка активна, рисуем только её "active"-полосу (не дублируем историей).
            .filter((d) => !(Number.isFinite(Number(d.tradeId)) && activeIds.has(Number(d.tradeId))));
        const ts = chart.timeScale();
        // Отрисовываем только интервалы, пересекающие текущий видимый диапазон.
        const range = ts.getVisibleLogicalRange();
        let visibleFromTs = Number(allChartData[0].time);
        let visibleToTs = Number(allChartData[allChartData.length - 1].time);
        if (range) {
            const leftIdx = Math.max(0, Math.floor(Math.max(0, range.from)));
            const rightIdx = Math.min(allChartData.length - 1, Math.ceil(Math.max(0, range.to)));
            if (allChartData[leftIdx]) visibleFromTs = Number(allChartData[leftIdx].time);
            if (allChartData[rightIdx]) visibleToTs = Number(allChartData[rightIdx].time);
        }
        const durations = [...historyDurations, ...activeDurations]
            .filter((d) => d.expiryTs >= visibleFromTs && d.entryTs <= visibleToTs)
            .sort((a, b) => a.entryTs - b.entryTs || a.expiryTs - b.expiryTs)
            .map((d) => {
                // Для завершённых сделок используем замороженные параметры экспирации.
                const frozen = getFrozenExpiryParams(d.tradeId);
                if (frozen && (d.result === 'win' || d.result === 'loss' || d.result === 'draw')) {
                    return { ...d, expiryPrice: frozen.price };
                }
                return d;
            });
        // Ограничиваем количество исторических полос в кадре для плавного pan/zoom.
        const MAX_VISIBLE_DURATION_ROWS = 280;
        const activeCount = durations.filter((d) => d.source === 'active').length;
        const maxTotal = Math.max(activeCount, MAX_VISIBLE_DURATION_ROWS);
        const trimmedDurations = durations.length > maxTotal ? durations.slice(durations.length - maxTotal) : durations;
        if (!trimmedDurations.length) {
            tradeDurationOverlay.classList.add('hidden');
            tradeDurationOverlay.innerHTML = '';
            return;
        }
        const lanesByY = new Map();
        tradeDurationOverlay.innerHTML = '';
        trimmedDurations.forEach((d) => {
                const pos = resolveDurationX(ts, d.entryTs, d.expiryTs);
                if (!pos) return;
                const { x1, x2 } = pos;
                const left = Math.min(x1, x2);
                const width = Math.max(8, Math.abs(x2 - x1));

                // Вертикальная привязка: сначала к entry_price сделки, иначе к цене ближайшей свечи.
                const basePrice = Number.isFinite(d.entryPrice) ? d.entryPrice : approxPriceByTime(d.entryTs);
                const y = basePrice != null ? candlestickSeries.priceToCoordinate(basePrice) : null;
                if (y == null || Number.isNaN(y)) return;
                let top = Math.max(2, Math.min((chartWrapper.clientHeight || 0) - 10, y - 3));

                // Если полосы на том же уровне пересекаются по X — поднимаем чуть выше, чтобы читались.
                const yBucket = Math.round(top / 6);
                const laneEnd = lanesByY.get(yBucket);
                if (laneEnd != null && left < laneEnd + 2) {
                    top = Math.max(2, top - 8);
                }
                lanesByY.set(Math.round(top / 6), left + width);

                const isTarget = targetTradeId !== null && Number.isFinite(d.tradeId) && d.tradeId === targetTradeId;
                const isActive = d.source === 'active';
                const color = isTarget
                    ? '#f59e0b'
                    : (isActive
                        ? 'rgba(56,189,248,0.92)'
                        : (d.side === 'CALL'
                            ? (d.result === 'loss' ? 'rgba(16,185,129,0.45)' : 'rgba(16,185,129,0.75)')
                            : (d.result === 'win' ? 'rgba(244,63,94,0.45)' : 'rgba(244,63,94,0.75)')));
                const border = isTarget
                    ? '1px solid rgba(245,158,11,0.95)'
                    : (isActive ? '1px solid rgba(56,189,248,1)' : '1px solid rgba(255,255,255,0.18)');

                const div = document.createElement('div');
                div.style.position = 'absolute';
                div.style.left = `${left}px`;
                div.style.top = `${top}px`;
                div.style.width = `${width}px`;
                div.style.height = isActive ? '3px' : '1px';
                div.style.borderRadius = '999px';
                div.style.background = color;
                div.style.border = border;
                div.style.boxShadow = isTarget
                    ? '0 0 8px rgba(245,158,11,0.75)'
                    : (isActive ? '0 0 6px rgba(56,189,248,0.65)' : '0 0 3px rgba(255,255,255,0.25)');
                tradeDurationOverlay.appendChild(div);

                // Явные маркеры начала/конца отрезка — визуально подчеркивают "entry -> expiry".
                const mkStart = document.createElement('div');
                mkStart.style.position = 'absolute';
                mkStart.style.left = `${Math.max(0, left - 1)}px`;
                mkStart.style.top = `${Math.max(0, top - (isActive ? 2 : 1))}px`;
                mkStart.style.width = '2px';
                mkStart.style.height = isActive ? '9px' : '6px';
                mkStart.style.borderRadius = '2px';
                mkStart.style.background = isTarget ? '#f59e0b' : 'rgba(255,255,255,0.75)';
                tradeDurationOverlay.appendChild(mkStart);

                const mkEnd = document.createElement('div');
                mkEnd.style.position = 'absolute';
                mkEnd.style.left = `${Math.max(0, left + width - 2)}px`;
                mkEnd.style.top = `${Math.max(0, top - (isActive ? 2 : 1))}px`;
                mkEnd.style.width = '2px';
                mkEnd.style.height = isActive ? '9px' : '6px';
                mkEnd.style.borderRadius = '2px';
                mkEnd.style.background = isTarget ? '#f59e0b' : 'rgba(255,255,255,0.75)';
                tradeDurationOverlay.appendChild(mkEnd);

                if (isActive && Number.isFinite(d.tradeId)) {
                    const label = document.createElement('div');
                    label.style.position = 'absolute';
                    label.style.left = `${Math.max(0, left + 2)}px`;
                    label.style.top = `${Math.max(0, top - 12)}px`;
                    label.style.fontSize = '10px';
                    label.style.lineHeight = '1';
                    label.style.fontFamily = 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace';
                    label.style.color = '#7dd3fc';
                    label.style.textShadow = '0 0 4px rgba(2,132,199,0.9)';
                    label.textContent = `OPEN #${d.tradeId}`;
                    tradeDurationOverlay.appendChild(label);
                }

                // Для завершённых сделок показываем "уровень закрытия" на свече exp:
                // короткая горизонтальная полоска шириной примерно в одну свечу.
                // Это даёт визуальный ответ на вопрос "на каком уровне закрылась сделка".
                const isSettled = d.result === 'win' || d.result === 'loss' || d.result === 'draw';
                const expiryPrice = Number(d.expiryPrice);
                if (isSettled) {
                    // Для завершённых сделок используем expiry_price из БД как основной источник.
                    // Фронтенд не пересчитывает цену — он отображает то, что записал сервер.
                    const frozen = getFrozenExpiryParams(d.tradeId);
                    const expLevel = frozen
                        ? frozen.price
                        : (Number.isFinite(expiryPrice) ? expiryPrice : null);
                    if (!Number.isFinite(expLevel)) return;
                    const yExp = candlestickSeries.priceToCoordinate(expLevel);
                    if (yExp != null && !Number.isNaN(yExp)) {
                        const expiryX = resolveCandleX(ts, d.expiryTs);
                        if (expiryX == null) return;
                        // Берём ширину ровно "одной свечи" по текущему масштабу timeScale.
                        const bs = (typeof ts.barSpacing === 'function') ? Number(ts.barSpacing()) : 8;
                        const candleW = Math.max(16, Math.min(20, bs * 0.95));
                        const expLeft = Math.max(0, Math.min((chartWrapper.clientWidth || 0) - candleW, expiryX - candleW / 2));
                        
                        // Проверяем, что цена находится в видимом диапазоне графика
                        const chartHeight = chartWrapper.clientHeight || 0;
                        if (yExp < 0 || yExp > chartHeight) {
                            // Цена вне видимого диапазона — не рисуем маркер
                            return;
                        }
                        const expTop = Math.max(1, Math.min(chartHeight - 3, yExp - 1.5));

                        const expMark = document.createElement('div');
                        expMark.style.position = 'absolute';
                        expMark.style.left = `${expLeft}px`;
                        expMark.style.top = `${expTop}px`;
                        expMark.style.width = `${candleW}px`;
                        expMark.style.height = '3px';
                        expMark.style.borderRadius = '999px';
                        expMark.style.background = isTarget ? '#f59e0b' : color;
                        expMark.style.border = isTarget
                            ? '1px solid rgba(245,158,11,1)'
                            : '1px solid rgba(255,255,255,0.35)';
                        expMark.style.boxShadow = isTarget
                            ? '0 0 8px rgba(245,158,11,0.75)'
                            : '0 0 4px rgba(255,255,255,0.35)';
                        tradeDurationOverlay.appendChild(expMark);
                    }
                }
            });
        tradeDurationOverlay.classList.remove('hidden');
    }
    // Ищет сделку по времени клика на графике:
    // 1) сначала по времени экспирации (наиболее ожидаемый пользовательский кейс),
    // 2) затем по времени входа, если клик пришёлся на entry-маркер.
    function tradeIdByChartClickTime(clickedBucketTs) {
        if (!Number.isFinite(clickedBucketTs) || !currentInstrument) return null;
        const rows = mergedSignals(currentInstrument)
            .filter((s) => s.instrument === currentInstrument)
            .map((s) => {
                const tradeId = (s.trade && s.trade.id) ? Number(s.trade.id) : null;
                if (!Number.isFinite(tradeId) || tradeId <= 0) return null;
                const entryTs = toBucketTime(toUnixSec(s.signal_at));
                const expiryRaw = (s.trade && s.trade.expiry_time) ? s.trade.expiry_time : s.expiry_time;
                const expiryTs = toBucketTime(toUnixSec(expiryRaw));
                return {
                    tradeId,
                    entryTs,
                    expiryTs,
                    isSettled: ((s.trade && s.trade.result) || s.result || 'pending') !== 'pending',
                };
            })
            .filter(Boolean);
        if (!rows.length) return null;

        const byExpiry = rows
            .filter((x) => Number.isFinite(x.expiryTs) && x.expiryTs === clickedBucketTs)
            .sort((a, b) => Number(b.isSettled) - Number(a.isSettled) || b.tradeId - a.tradeId);
        if (byExpiry.length) return byExpiry[0].tradeId;

        const byEntry = rows
            .filter((x) => Number.isFinite(x.entryTs) && x.entryTs === clickedBucketTs)
            .sort((a, b) => Number(b.isSettled) - Number(a.isSettled) || b.tradeId - a.tradeId);
        return byEntry.length ? byEntry[0].tradeId : null;
    }
    // Переход в "Историю сделок" с подсветкой выбранной сделки.
    function goToTradeHistoryById(tradeId) {
        if (!Number.isFinite(Number(tradeId)) || Number(tradeId) <= 0) return;
        const url = `${TRADES_HISTORY_URL}?trade_id=${encodeURIComponent(String(tradeId))}`;
        window.location.href = url;
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
        // Пунктирные линии цены входа отключены по UX-требованию:
        // вместо них используется полоса длительности сделки на уровне цены.
        clearEntryPriceLines();
        return;
    }
    function candlesSince() { return Math.floor(Date.now() / 1000) - WINDOW_SEC; }
    function setLoading(show) { chartLoading.classList.toggle('hidden', !show); }
    function normalizeText(s) { return (s || '').toString().toLowerCase().trim(); }
    function clearList(el) {
        while (el && el.firstChild) el.removeChild(el.firstChild);
    }
    function fillList(el, items) {
        clearList(el);
        (items || []).forEach((txt) => {
            const li = document.createElement('li');
            li.textContent = String(txt || '');
            el.appendChild(li);
        });
    }
    /** Свернуть/развернуть текстовый блок анализа (шапка остаётся видимой). */
    function setBotAnalysisCollapsed(collapsed) {
        if (!botAnalysisPanel) return;
        const isCollapsed = !!collapsed;
        botAnalysisPanel.classList.toggle('bot-analysis-collapsed', isCollapsed);
        if (botAnalysisCollapseBtn) {
            botAnalysisCollapseBtn.textContent = isCollapsed ? 'Развернуть' : 'Свернуть';
            botAnalysisCollapseBtn.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
            botAnalysisCollapseBtn.title = isCollapsed
                ? 'Показать полный текст анализа'
                : 'Свернуть текст анализа, оставить график';
        }
        requestAnimationFrame(() => {
            if (chart && chartWrapper && chartWrapper.clientWidth) {
                chart.resize(chartWrapper.clientWidth, chartWrapper.clientHeight);
            }
        });
    }

    function renderChartAnalystAnalysis(data) {
        if (!botAnalysisPanel) return;
        botAnalysisPanel.classList.remove('hidden');
        setBotAnalysisCollapsed(false);
        latestManualAnalysis = data || null;

        const hasError = !!(data && data.error);
        let summaryText = (data && data.summary) ? String(data.summary) : 'Нет данных анализа.';
        if (data && data.error_detail) {
            summaryText += ' — ' + String(data.error_detail);
        }
        botAnalysisSummary.textContent = summaryText;
        botAnalysisSummary.classList.toggle('text-emerald-300', !hasError && !!(data && data.analysis_text));
        botAnalysisSummary.classList.toggle('text-rose-300', hasError || !(data && data.analysis_text));

        const instrument = data && data.instrument ? String(data.instrument) : (currentInstrument || '—');
        const model = data && data.model ? String(data.model) : '—';
        const logId = data && data.llm_log_id != null ? String(data.llm_log_id) : '—';
        const billingLabel = (data && data.billing_label) ? String(data.billing_label) : '';
        let meta = `${instrument} · модель: ${model}`;
        if (billingLabel) {
            meta += ` · ${billingLabel}`;
        }
        if (data && data.chart_levels_message) {
            meta += ` · ${String(data.chart_levels_message)}`;
        }
        meta += ` · log #${logId}`;
        botAnalysisMeta.textContent = meta;

        botAnalysisText.textContent = data && data.analysis_text ? String(data.analysis_text) : '';
        if (botAnalysisBody) botAnalysisBody.scrollTop = 0;
        applyLlmChartLevelsFromAnalysis(data);
        applySmcFlowFromAnalysis(data);
        updateSidebarActiveTradePanel();
    }
    function renderBotAnalysis(data) {
        if (BOT_IS_CHART_ANALYST) {
            renderChartAnalystAnalysis(data);
            return;
        }
        if (!botAnalysisPanel) return;
        botAnalysisPanel.classList.remove('hidden');
        setBotAnalysisCollapsed(false);
        latestManualAnalysis = data || null;

        const canEnter = !!(data && data.can_enter);
        botAnalysisSummary.textContent = (data && data.summary) ? String(data.summary) : 'Нет данных анализа.';
        botAnalysisSummary.classList.toggle('text-emerald-300', canEnter);
        botAnalysisSummary.classList.toggle('text-rose-300', !canEnter);

        const instrument = data && data.instrument ? String(data.instrument) : (currentInstrument || '—');
        const side = data && data.side ? String(data.side) : '—';
        const conf = (data && data.confidence != null && Number.isFinite(Number(data.confidence))) ? Number(data.confidence).toFixed(2) : '—';
        const minConf = (data && data.min_confidence != null && Number.isFinite(Number(data.min_confidence))) ? Number(data.min_confidence).toFixed(2) : '—';
        botAnalysisMeta.textContent = `${instrument} · side: ${side} · confidence: ${conf} / min: ${minConf}`;

        botAnalysisText.textContent = data && data.analysis_text ? String(data.analysis_text) : '';
        const reasons = Array.isArray(data && data.reasons_short) ? data.reasons_short : [];
        const blockers = Array.isArray(data && data.blockers) ? data.blockers : [];
        fillList(botAnalysisReasons, reasons);
        fillList(botAnalysisBlockers, blockers);
        botAnalysisReasonsWrap.classList.toggle('hidden', reasons.length === 0);
        botAnalysisBlockersWrap.classList.toggle('hidden', blockers.length === 0);
        if (botAnalysisBody) botAnalysisBody.scrollTop = 0;
        updateSidebarActiveTradePanel();
    }
    /** Показывает на кнопке анализа, сколько секунд идёт запрос: «Анализ (3)». */
    function startBotAnalysisButtonTimer() {
        stopBotAnalysisButtonTimer();
        let seconds = 1;
        if (botAnalysisBtn) {
            botAnalysisBtn.textContent = `Анализ (${seconds})`;
        }
        botAnalysisTimerId = setInterval(() => {
            seconds += 1;
            if (botAnalysisBtn) {
                botAnalysisBtn.textContent = `Анализ (${seconds})`;
            }
        }, 1000);
    }

    function stopBotAnalysisButtonTimer() {
        if (botAnalysisTimerId !== null) {
            clearInterval(botAnalysisTimerId);
            botAnalysisTimerId = null;
        }
    }

    async function fetchBotAnalysis() {
        if (botAnalysisLoading) return;
        if (!currentInstrument) {
            renderBotAnalysis(BOT_IS_CHART_ANALYST ? {
                summary: 'Сначала выберите инструмент слева, затем нажмите «Сделать анализ».',
                instrument: '—',
                analysis_text: '',
                error: 'no_instrument',
            } : {
                can_enter: false,
                summary: 'Сначала выберите инструмент слева, затем запустите анализ.',
                instrument: '—',
                side: '—',
                confidence: null,
                min_confidence: null,
                analysis_text: '',
                reasons_short: [],
                blockers: [],
            });
            return;
        }

        botAnalysisLoading = true;
        if (BOT_IS_CHART_ANALYST) clearLlmChartLevels();
        const prevLabel = botAnalysisBtn ? botAnalysisBtn.textContent : '';
        if (botAnalysisBtn) {
            botAnalysisBtn.disabled = true;
        }
        startBotAnalysisButtonTimer();
        try {
            const params = new URLSearchParams({ instrument: currentInstrument });
            if (BOT_IS_CHART_ANALYST) {
                const qEl = document.getElementById('bot-analysis-user-question');
                const q = qEl && qEl.value ? String(qEl.value).trim() : '';
                if (q) params.set('user_question', q);
            }
            const url = `${BOT_ANALYSIS_URL}?${params.toString()}`;
            const res = await fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            const payload = await res.json();
            renderBotAnalysis(payload);
        } catch (_) {
            renderBotAnalysis(BOT_IS_CHART_ANALYST ? {
                summary: 'Не удалось получить анализ от сервера.',
                instrument: currentInstrument,
                analysis_text: '',
                error: 'http_error',
            } : {
                can_enter: false,
                summary: 'Не удалось получить анализ от сервера.',
                instrument: currentInstrument,
                side: '—',
                confidence: null,
                min_confidence: null,
                analysis_text: '',
                reasons_short: [],
                blockers: ['Проверьте доступность API и повторите запрос.'],
            });
        } finally {
            botAnalysisLoading = false;
            stopBotAnalysisButtonTimer();
            if (botAnalysisBtn) {
                botAnalysisBtn.disabled = false;
                botAnalysisBtn.textContent = prevLabel || (BOT_IS_CHART_ANALYST ? 'Сделать анализ' : 'Анализ');
            }
        }
    }
    const CHART_DEFAULT_VISIBLE_BARS = 110;
    const CHART_RIGHT_GAP_BARS = 22;
    const CHART_LEFT_GAP_BARS = 4;

    function markerTimesForInstrument(instrument) {
        if (!instrument) return [];
        const out = [];
        ([...(signals || []), ...syntheticClosedSignals]).forEach((s) => {
            if (s.instrument !== instrument) return;
            const signalTs = toUnixSec(s.signal_at);
            if (Number.isFinite(signalTs) && signalTs > 0) out.push(toBucketTime(signalTs));
            const expiryRaw = (s.trade && s.trade.expiry_time) ? s.trade.expiry_time : s.expiry_time;
            const expiryTs = toUnixSec(expiryRaw);
            if (Number.isFinite(expiryTs) && expiryTs > 0) out.push(toBucketTime(expiryTs));
        });
        (openTrades || []).forEach((t) => {
            if (t.instrument !== instrument) return;
            const entryTs = toUnixSec(t.entry_ts);
            const expTs = toUnixSec(t.expiry_ts);
            if (Number.isFinite(entryTs) && entryTs > 0) out.push(toBucketTime(entryTs));
            if (Number.isFinite(expTs) && expTs > 0) out.push(toBucketTime(expTs));
        });
        return out.filter((x) => Number.isFinite(x) && x != null);
    }

    function applyDefaultChartViewport() {
        if (!chart || allChartData.length === 0) return;
        const n = allChartData.length;
        const lastIdx = n - 1;
        let from = Math.max(0, lastIdx - Math.min(CHART_DEFAULT_VISIBLE_BARS, n) + 1);

        // Полный исторический охват по маркерам нужен только для точечного перехода на конкретную сделку.
        if (targetTradeId !== null) {
            const markerTimes = markerTimesForInstrument(currentInstrument);
            const earliestMarker = Math.min(...markerTimes);
            if (markerTimes.length > 0) {
                const idx = allChartData.findIndex((c) => Number(c.time) >= earliestMarker);
                if (idx >= 0) {
                    from = Math.max(0, idx - CHART_LEFT_GAP_BARS);
                } else {
                    from = 0;
                }
            }
        }
        const to = lastIdx + CHART_RIGHT_GAP_BARS;
        requestAnimationFrame(() => {
            if (!chart) return;
            chart.timeScale().setVisibleLogicalRange({ from, to });
            // Защита от редкого race-condition при первом рендере:
            // иногда после setData/resize библиотека может вернуть "весь график" вместо нашего окна.
            // Проверяем фактический span и при необходимости повторно применяем целевой диапазон.
            requestAnimationFrame(() => {
                if (!chart) return;
                const range = chart.timeScale().getVisibleLogicalRange();
                if (!range || !Number.isFinite(range.from) || !Number.isFinite(range.to)) return;
                const span = range.to - range.from;
                const expectedSpan = to - from;
                // Для обычного открытия (без trade_id) не допускаем разворот на всю историю.
                // Небольшой буфер оставляем, чтобы не "драться" с округлением библиотеки.
                if (targetTradeId === null && span > expectedSpan + 25) {
                    chart.timeScale().setVisibleLogicalRange({ from, to });
                }
            });
        });
    }

    /**
     * «Живой» хвост: range.to не левее индекса последнего бара (без допуска в несколько баров,
     * иначе при просмотре почти у края срабатывал scrollToRealTime и выбрасывало к правому краю).
     */
    function isViewNearRightEdge() {
        if (!chart || allChartData.length === 0) return true;
        const range = chart.timeScale().getVisibleLogicalRange();
        if (!range) return true;
        const lastIdx = allChartData.length - 1;
        return range.to >= lastIdx - 1e-3;
    }

    /**
     * DOM-зоны поверх canvas: обрезаем правый край, чтобы не перекрывать формирующуюся свечу.
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

    function payoutClassByValue(value) {
        if (value == null || Number.isNaN(value)) return 'text-zinc-500';
        if (value >= 80) return 'text-emerald-400';
        if (value >= 60) return 'text-amber-400';
        return 'text-rose-400';
    }
    const instrumentListEl = document.getElementById('instrument-list');
    function instrumentScanDotMeta(color) {
        if (color === 'red') {
            return { className: 'bg-red-500', title: 'Идёт анализ' };
        }
        if (color === 'green') {
            return { className: 'bg-emerald-500', title: 'Проверено' };
        }
        return { className: 'bg-zinc-500', title: 'Ожидает проверки' };
    }
    function appendInstrumentScanDot(nameWrap, color) {
        if (!color || color === 'none') return;
        const meta = instrumentScanDotMeta(color);
        const dot = document.createElement('span');
        dot.className = `instrument-scan-dot shrink-0 inline-block h-2 w-2 rounded-full ${meta.className}`;
        dot.title = meta.title;
        dot.setAttribute('aria-hidden', 'true');
        nameWrap.appendChild(dot);
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
            appendInstrumentScanDot(nameWrap, inst.scan_dot || 'gray');
            const name = document.createElement('span');
            name.className = 'font-medium text-zinc-100 truncate';
            name.textContent = inst.display_name || inst.instrument;
            nameWrap.appendChild(name);
            if (inst.open_trade && inst.expiry_ts) {
                const timer = document.createElement('span');
                timer.className = 'instrument-trade-timer shrink-0 text-xs font-mono tabular-nums text-stone-200';
                // Нормализуем до unix-sec: так таймер и маркеры не "прыгают" при ms/строковом формате.
                timer.dataset.expiryTs = String(toUnixSec(inst.expiry_ts) || '');
                const nowSec = Math.floor(Date.now() / 1000);
                timer.textContent = formatMmSs((toUnixSec(inst.expiry_ts) || 0) - nowSec);
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
    function formatScanMinPayout(value) {
        const n = Number(value);
        if (!Number.isFinite(n)) return '80';
        return String(Math.round(n * 10) / 10).replace(/\.0$/, '');
    }
    function updateScanRemainingCounter(payload) {
        const wrap = document.getElementById('bot-scan-remaining-wrap');
        const countEl = document.getElementById('bot-scan-remaining-count');
        const minPayoutEl = document.getElementById('bot-scan-min-payout');
        if (!wrap || !countEl) return;
        if (minPayoutEl && payload && payload.scan_min_payout != null) {
            minPayoutEl.textContent = formatScanMinPayout(payload.scan_min_payout);
        }
        const total = Number.parseInt(String(payload?.scan_total ?? ''), 10);
        const analyzed = Array.isArray(payload?.analyzed_instruments) ? payload.analyzed_instruments.length : 0;
        const active = !!(payload && (
            payload.scan_active
            || payload.analyzing_instrument
            || (Number.isFinite(total) && total > 0 && analyzed > 0)
        ));
        let remaining = payload ? payload.scan_remaining : null;
        if (active && remaining == null && Number.isFinite(total) && total > 0) {
            remaining = Math.max(0, total - analyzed);
        }
        if (!active || remaining == null) {
            wrap.classList.add('hidden');
            return;
        }
        countEl.textContent = String(Math.max(0, Number.parseInt(String(remaining), 10) || 0));
        wrap.classList.remove('hidden');
    }
    function refreshInstrumentSidebar() {
        return fetch(INSTRUMENTS_SIDEBAR_URL, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then((r) => r.json())
            .then((d) => {
                if (d && d.server_time) lastServerTimes.instruments = d.server_time;
                renderInstrumentSidebarRows(d.instruments || []);
                updateScanRemainingCounter(d);
                applyInstrumentFilter(instrumentSearchInput.value);
                syncSearchClearButton();
                if (currentInstrument) {
                    document.querySelectorAll('.instrument-btn').forEach((b) => b.classList.toggle('bg-blue-900/40', b.dataset.instrument === currentInstrument));
                }
                renderTimeDebugPanel();
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

    function formatStatNumber(value, fallback = '0') {
        const n = Number(value);
        if (!Number.isFinite(n)) return fallback;
        return String(Math.max(0, Math.trunc(n)));
    }

    // Обновляем только текст внутри карточек статистики: это дешёвая DOM-операция,
    // поэтому можно выполнять её часто, не затрагивая график и другие тяжёлые виджеты.
    function applyStatsToSidebar(stats) {
        if (!stats || typeof stats !== 'object') return;
        const totalEl = document.getElementById('bot-stat-total');
        const openEl = document.getElementById('bot-stat-open');
        const winsEl = document.getElementById('bot-stat-wins');
        const lossesEl = document.getElementById('bot-stat-losses');
        const winrateEl = document.getElementById('bot-stat-winrate');
        const instrumentWinrateEl = document.getElementById('bot-stat-instrument-winrate');
        if (!totalEl || !openEl || !winsEl || !lossesEl || !winrateEl || !instrumentWinrateEl) return;

        totalEl.textContent = formatStatNumber(stats.total, '0');
        openEl.textContent = formatStatNumber(stats.open, '0');
        winsEl.textContent = formatStatNumber(stats.wins, '0');
        lossesEl.textContent = formatStatNumber(stats.losses, '0');

        const winrate = stats.winrate;
        if (winrate == null || !Number.isFinite(Number(winrate))) {
            winrateEl.textContent = '—';
        } else {
            winrateEl.textContent = `${Number(winrate).toFixed(2)}%`;
        }
        const instrumentWinrate = stats.instrument_winrate;
        if (instrumentWinrate == null || !Number.isFinite(Number(instrumentWinrate))) {
            instrumentWinrateEl.textContent = '—';
        } else {
            instrumentWinrateEl.textContent = `${Number(instrumentWinrate).toFixed(2)}%`;
        }
    }

    function updateBotStatsScopeButtons() {
        const h = document.getElementById('bot-stats-scope-hour');
        const a = document.getElementById('bot-stats-scope-all');
        if (!h || !a) {
            return;
        }
        const active = 'flex-1 rounded px-1 py-1 bg-zinc-600 text-zinc-100 shadow-sm';
        const idle = 'flex-1 rounded px-1 py-1 text-zinc-500 hover:bg-zinc-800/80 hover:text-zinc-300';
        h.className = botStatsScope === 'hour' ? active : idle;
        a.className = botStatsScope === 'all' ? active : idle;
    }
    function setBotStatsScope(scope) {
        if (scope !== 'hour' && scope !== 'all') {
            return;
        }
        botStatsScope = scope;
        try {
            localStorage.setItem(STATS_SCOPE_STORAGE_KEY, scope);
        } catch (_) {}
        updateBotStatsScopeButtons();
        applyStatsToSidebar(STATS_BOOTSTRAP[scope]);
        refreshBotStats();
    }
    function refreshBotStats() {
        const instrumentQuery = currentInstrument ? `&instrument=${encodeURIComponent(currentInstrument)}` : '';
        const url = `${BOT_STATS_URL}?scope=${encodeURIComponent(botStatsScope)}${instrumentQuery}`;
        fetch(url, {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then((r) => r.json())
            .then((d) => {
                if (d && d.server_time) lastServerTimes.stats = d.server_time;
                // Игнорируем ответ, если пользователь уже переключил режим, пока шёл запрос.
                if (d && d.scope && d.scope !== botStatsScope) {
                    return;
                }
                if (d && d.stats && typeof d.stats === 'object') {
                    STATS_BOOTSTRAP[botStatsScope] = d.stats;
                    applyStatsToSidebar(d.stats);
                }
                renderTimeDebugPanel();
            })
            .catch(() => {});
    }

    /** Склеивает reasons_short из API в одну строку (как на сервере). */
    function joinTradeReasonsLines(arr) {
        if (!Array.isArray(arr) || !arr.length) return '';
        return arr.map((x) => String(x).trim()).filter(Boolean).join(' · ');
    }

    /** Правая колонка: полный блок по текущей паре (те же поля, что openTradeSidebarRow в BotController). */
    function updateSidebarActiveTradePanel() {
        const panel = document.getElementById('sidebar-active-trade-panel');
        const timerEl = document.getElementById('sidebar-trade-timer');
        const headlineEl = document.getElementById('sidebar-trade-headline');
        const timesEl = document.getElementById('sidebar-trade-times');
        const stateEl = document.getElementById('sidebar-trade-state');
        const pnlEl = document.getElementById('sidebar-trade-pnl');
        const analysisWrap = document.getElementById('sidebar-trade-analysis-wrap');
        const analysisTextEl = document.getElementById('sidebar-trade-analysis-text');
        const reasonsWrap = document.getElementById('sidebar-trade-reasons-wrap');
        const reasonsTextEl = document.getElementById('sidebar-trade-reasons-text');
        if (!panel || !headlineEl || !timesEl || !stateEl || !pnlEl) return;

        if (!currentInstrument) {
            panel.classList.add('hidden');
            return;
        }
        const nowSec = Math.floor(Date.now() / 1000);
        const rows = (openTrades || [])
            .filter((t) => t.instrument === currentInstrument && toUnixSec(t.expiry_ts) > nowSec)
            .sort((a, b) => toUnixSec(a.expiry_ts || 0) - toUnixSec(b.expiry_ts || 0));
        if (!rows.length) {
            // Если активной сделки нет, но пользователь запросил "Анализ", показываем этот же блок справа в режиме диагностики.
            const a = latestManualAnalysis;
            const sameInstrument = a && a.instrument && String(a.instrument) === String(currentInstrument);
            if (!sameInstrument) {
                panel.classList.add('hidden');
                return;
            }
            panel.classList.remove('hidden');
            if (timerEl) {
                timerEl.textContent = '';
                timerEl.classList.add('hidden');
            }

            const sideU = (a.side || '—').toString().toUpperCase();
            const confFmt = (a.confidence != null && Number.isFinite(Number(a.confidence)))
                ? Number(a.confidence).toFixed(2)
                : '—';
            headlineEl.textContent = `${currentInstrument} · ${sideU} · ${confFmt} confidence`;
            timesEl.textContent = 'Режим сравнения: анализ без активной сделки';
            stateEl.textContent = a.can_enter
                ? 'Сигнал есть: вход возможен'
                : 'Сигнал/вход отсутствует или заблокирован';
            pnlEl.textContent = 'PnL: —';

            const analysis = (a.analysis_text != null && a.analysis_text !== '') ? String(a.analysis_text).trim() : '';
            if (analysis && analysisWrap && analysisTextEl) {
                analysisTextEl.textContent = analysis;
                analysisWrap.classList.remove('hidden');
            } else if (analysisWrap) {
                analysisWrap.classList.add('hidden');
            }

            const reasons = Array.isArray(a.reasons_short) ? a.reasons_short : [];
            const blockers = Array.isArray(a.blockers) ? a.blockers : [];
            const reasonsLine = joinTradeReasonsLines([...reasons, ...blockers.map((x) => `BLOCK: ${x}`)]);
            if (reasonsLine && reasonsWrap && reasonsTextEl) {
                reasonsTextEl.textContent = reasonsLine;
                reasonsWrap.classList.remove('hidden');
            } else if (reasonsWrap) {
                reasonsWrap.classList.add('hidden');
            }
            return;
        }
        const t = rows[0];
        panel.classList.remove('hidden');
        const left = toUnixSec(t.expiry_ts) - nowSec;
        if (timerEl) {
            timerEl.textContent = formatMmSs(left);
            timerEl.classList.remove('hidden');
        }

        const sideU = (t.side || '').toString().toUpperCase();
        let confFmt = t.confidence_fmt;
        if (confFmt == null || confFmt === '') {
            const c = t.confidence;
            confFmt = (c != null && Number.isFinite(Number(c))) ? Number(c).toFixed(2) : '—';
        }
        headlineEl.textContent = `${t.instrument} · ${sideU} · ${confFmt} confidence`;
        timesEl.textContent = `Сигнал: ${t.signal_at_fmt || '—'} · Сделка: ${t.entry_at_fmt || '—'}`;
        const statusRu = t.status_ru || (t.trade_status === 'open' ? 'открыта' : (t.trade_status || ''));
        stateEl.textContent = t.state_line || `${t.trade_result || 'pending'} ${statusRu}`;
        const pnlShown = (t.pnl_display != null && t.pnl_display !== '')
            ? String(t.pnl_display)
            : ((t.pnl != null && Number.isFinite(Number(t.pnl))) ? Number(t.pnl).toFixed(2) : '—');
        pnlEl.textContent = 'PnL: ' + pnlShown;

        const analysis = (t.analysis_text != null && t.analysis_text !== '') ? String(t.analysis_text).trim() : '';
        if (analysis && analysisWrap && analysisTextEl) {
            analysisTextEl.textContent = analysis;
            analysisWrap.classList.remove('hidden');
        } else if (analysisWrap) {
            analysisWrap.classList.add('hidden');
        }

        const reasonsLine = joinTradeReasonsLines(t.reasons_short);
        if (reasonsLine && reasonsWrap && reasonsTextEl) {
            reasonsTextEl.textContent = reasonsLine;
            reasonsWrap.classList.remove('hidden');
        } else if (reasonsWrap) {
            reasonsWrap.classList.add('hidden');
        }
    }
    function tickSidebarTradeTimers() {
        const nowSec = Math.floor(Date.now() / 1000);
        document.querySelectorAll('.instrument-trade-timer[data-expiry-ts]').forEach((el) => {
            const ts = toUnixSec(el.dataset.expiryTs);
            if (!Number.isFinite(ts)) return;
            el.textContent = formatMmSs(ts - nowSec);
        });
    }

    function positionCandleTimerOverlay() {
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
        const lastT = Number(allChartData[allChartData.length - 1].time);
        if (!Number.isFinite(lastT)) {
            if (candleTimerOverlay) candleTimerOverlay.classList.add('hidden');
            candleCloseTimerEl.textContent = '';
            return;
        }
        let left = null;
        if (currentTimeframeMinutes === 1) {
            // Для M1 используем серверный якорь m1_seconds_to_close (он учитывает потенциальный дрейф).
            left = m1CountdownLeftNow();
        } else {
            // Для остальных TF (включая S5/S15/S30) считаем по времени открытия последней свечи.
            left = genericCountdownLeftNow(currentTimeframeMinutes);
        }
        if (left === null) {
            if (candleTimerOverlay) candleTimerOverlay.classList.add('hidden');
            candleCloseTimerEl.textContent = '';
            return;
        }
        if (candleTfLabelEl) candleTfLabelEl.textContent = timeframeLabel(currentTimeframeMinutes);
        candleCloseTimerEl.textContent = formatMmSs(left);
        requestAnimationFrame(positionCandleTimerOverlay);
    }

    function renderOpenTradesOverlay() {
        if (!openTradesOverlay) return;
        if (!currentInstrument) {
            openTradeOverlayCardMap.clear();
            openTradesOverlay.replaceChildren();
            return;
        }
        const nowSec = Math.floor(Date.now() / 1000);
        const rows = (openTrades || [])
            .filter((t) => t.instrument === currentInstrument && toUnixSec(t.expiry_ts) > nowSec)
            .sort((a, b) => toUnixSec(a.expiry_ts || 0) - toUnixSec(b.expiry_ts || 0));
        if (!rows.length) {
            openTradeOverlayCardMap.clear();
            openTradesOverlay.replaceChildren();
            return;
        }
        const seenIds = new Set();
        const fragment = document.createDocumentFragment();
        rows.forEach((t) => {
            const tradeId = String(t.id);
            seenIds.add(tradeId);
            const left = toUnixSec(t.expiry_ts) - nowSec;
            const side = (t.side || '').toUpperCase();
            const sideClass = side === 'CALL' ? 'text-emerald-300' : 'text-rose-300';

            let cardRef = openTradeOverlayCardMap.get(tradeId);
            if (!cardRef) {
                const root = document.createElement('div');
                root.className = 'rounded border border-zinc-700/80 bg-zinc-900/85 px-2 py-1 text-xs shadow-sm shadow-black/30';
                const sideEl = document.createElement('span');
                const idEl = document.createElement('span');
                idEl.className = 'text-zinc-400';
                const timerEl = document.createElement('span');
                timerEl.className = 'text-zinc-200 font-mono ml-2';
                root.appendChild(sideEl);
                root.appendChild(idEl);
                root.appendChild(timerEl);
                cardRef = { root, sideEl, idEl, timerEl, expiryTs: null };
                openTradeOverlayCardMap.set(tradeId, cardRef);
            }
            cardRef.sideEl.className = `font-medium ${sideClass}`;
            cardRef.sideEl.textContent = `● OPEN ${side || 'TRADE'}`;
            cardRef.idEl.textContent = ` #${tradeId}`;
            cardRef.expiryTs = toUnixSec(t.expiry_ts);
            cardRef.timerEl.textContent = formatMmSs(left);
            fragment.appendChild(cardRef.root);
        });
        [...openTradeOverlayCardMap.keys()].forEach((id) => {
            if (!seenIds.has(id)) openTradeOverlayCardMap.delete(id);
        });
        openTradesOverlay.replaceChildren(fragment);
    }
    function tickOpenTradesOverlayTimers() {
        if (!openTradesOverlay || openTradeOverlayCardMap.size === 0) return;
        const nowSec = Math.floor(Date.now() / 1000);
        openTradeOverlayCardMap.forEach((cardRef) => {
            const left = Number(cardRef.expiryTs) - nowSec;
            cardRef.timerEl.textContent = formatMmSs(left);
        });
    }
    function refreshOpenTrades() {
        if (!currentInstrument) return;
        const params = new URLSearchParams({ instrument: currentInstrument });
        fetch(`${OPEN_TRADES_URL}?${params.toString()}`, {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then((r) => r.json())
            .then((d) => {
                if (d && d.server_time) lastServerTimes.openTrades = d.server_time;
                const prevOpenTrades = openTrades || [];
                openTrades = d.trades || [];
                // Если сделка исчезла из open-list, считаем что она закрылась и ставим временный exp-маркер сразу.
                const nowOpenIds = new Set(openTrades.map((t) => String(t.id)));
                const disappearedTrades = prevOpenTrades.filter((t) => !nowOpenIds.has(String(t.id)));
                disappearedTrades.forEach((t) => {
                    const entryTs = toUnixSec(t.entry_ts);
                    const expiryTs = toUnixSec(t.expiry_ts);
                    if (!Number.isFinite(entryTs) || entryTs <= 0) return;
                    if (!Number.isFinite(expiryTs) || expiryTs <= 0) return;
                    const syntheticId = `closed-${t.id}-${expiryTs}`;
                    if (syntheticClosedSignals.some((s) => String(s.id) === syntheticId)) return;
                    // Synthetic сигнал НЕ содержит expiry_price — цена придёт с сервера через /signals.
                    // Это важно: цена экспирации рассчитывается сервером (BotSettlementService),
                    // и фронтенд не должен придумывать свою "временную" цену.
                    syntheticClosedSignals.push({
                        id: syntheticId,
                        instrument: t.instrument,
                        side: (t.side || '').toUpperCase(),
                        signal_at: new Date(entryTs * 1000).toISOString(),
                        expiry_time: new Date(expiryTs * 1000).toISOString(),
                        result: (t.trade_result || 'pending'),
                        trade: {
                            id: t.id,
                            expiry_time: new Date(expiryTs * 1000).toISOString(),
                            result: (t.trade_result || 'pending'),
                            entry_price: (t.entry_price != null && t.entry_price !== '') ? Number(t.entry_price) : null,
                            expiry_price: null,
                        },
                    });
                });
                // Таймер сделки и карточка справа опираются на /signals; без немедленного pull после исчезновения
                // из open-list до следующего poll остаётся только synthetic — таймер «обнуляется» раньше, чем придёт итог с сервера.
                if (disappearedTrades.length > 0) {
                    // Сразу обновляем замороженные параметры и маркеры на основе synthetic данных,
                    // чтобы маркер экспирации появился немедленно (не дожидаясь ответа /signals).
                    historyDurationCache.clear();
                    updateFrozenExpiryParams();
                    renderChartMarkers();
                    refreshSignalsHistory();
                }
                historyDurationCache.clear();
                updateFrozenExpiryParams();
                renderOpenTradesOverlay();
                renderChartMarkers();
                renderEntryPriceLines();
                focusTargetTradeOnChart();
                updateSidebarActiveTradePanel();
                renderTimeDebugPanel();
            })
            .catch(() => {});
    }
    function refreshSignalsHistory() {
        // История сигналов обновляется отдельно от open-trades, иначе после закрытия сделки маркер exp может пропасть до перезагрузки.
        const params = new URLSearchParams();
        if (currentInstrument) params.set('instrument', currentInstrument);
        fetch(`${SIGNALS_URL}?${params.toString()}`, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then((r) => r.json())
            .then((d) => {
                if (d && d.server_time) lastServerTimes.signals = d.server_time;
                if (!Array.isArray(d.signals)) return;
                signals = d.signals;
                // Когда серверная история уже содержит сделку, убираем временную копию, чтобы не было дублей.
                const serverKeys = new Set(signals.map((s) => {
                    const expRaw = (s.trade && s.trade.expiry_time) ? s.trade.expiry_time : s.expiry_time;
                    return `${s.instrument}|${s.signal_at}|${expRaw || ''}`;
                }));
                syntheticClosedSignals = syntheticClosedSignals.filter((s) => {
                    const expRaw = (s.trade && s.trade.expiry_time) ? s.trade.expiry_time : s.expiry_time;
                    const key = `${s.instrument}|${s.signal_at}|${expRaw || ''}`;
                    return !serverKeys.has(key);
                });
                historyDurationCache.clear();
                updateFrozenExpiryParams();
                renderChartMarkers();
                focusTargetTradeOnChart();
                renderTimeDebugPanel();
            })
            .catch(() => {});
    }
    function buildChartData(candles) {
        return (candles || []).map((c) => ({ time: c.time, open: c.open, high: c.high, low: c.low, close: c.close }))
            .sort((a, b) => a.time - b.time);
    }

    function fetchCandles(instrument, optsOrBefore = null) {
        // Обратная совместимость: fetchCandles(inst, oldestLoadedTime) для loadMoreCandles.
        const opts = typeof optsOrBefore === 'number'
            ? { before: optsOrBefore, kind: 'more' }
            : (optsOrBefore && typeof optsOrBefore === 'object' ? optsOrBefore : { kind: 'initial' });
        const before = opts.before ?? null;
        const kind = opts.kind || (before != null ? 'more' : 'initial');

        const earliestSignalTs = earliestSignalTsForInstrument(instrument);
        let since;
        let limit;
        if (kind === 'poll') {
            since = Math.floor(Date.now() / 1000) - POLL_WINDOW_SEC;
            limit = POLL_LIMIT;
        } else {
            const dynamicSince = (earliestSignalTs && Number.isFinite(earliestSignalTs))
                ? Math.min(candlesSince(), Math.max(0, earliestSignalTs - 3600))
                : candlesSince();
            since = dynamicSince;
            limit = LIMIT;
        }

        const params = new URLSearchParams({
            instrument,
            timeframe: String(currentTimeframeMinutes),
            limit: String(limit),
            since: String(since),
        });
        if (before != null) params.set('before', before);
        // Partial нужен для live-бара при initial/poll; при догрузке влево (before) — только закрытые бары.
        if (before == null) {
            params.set('include_partial', '1');
        }
        return fetch(`${CANDLES_URL}?${params.toString()}`, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then((r) => r.json())
            .then((payload) => {
                applyM1CountdownFromPayload(payload);
                applyPriceScaleFromPayload(payload);
                if (currentTimeframeMinutes !== 1) {
                    applyGenericCountdownFromPayload(payload, currentTimeframeMinutes);
                } else {
                    tfCloseCountdownAnchorSec = null;
                    tfCloseCountdownAnchorPerfMs = null;
                }
                if (payload && payload.feed_debug && typeof payload.feed_debug === 'object') {
                    lastFeedDebug = {
                        rawFeedTime: payload.feed_debug.raw_feed_time ?? null,
                        effectiveFeedTime: payload.feed_debug.effective_feed_time ?? null,
                        ingestReceivedAt: payload.feed_debug.ingest_received_at ?? null,
                        lastClosedCandleTime: payload.feed_debug.last_closed_candle_time ?? null,
                    };
                }
                renderTimeDebugPanel();
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
        if (!allChartData.length || !lastZones.length) {
            renderTradeDurationOverlay();
            redrawPriceActionOverlay();
            return;
        }
        const lastBarTime = allChartData[allChartData.length - 1].time;
        const showOb = layerOb.checked;
        const showFvg = layerFvg.checked;
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
        lastZones.forEach((z) => {
            if (z.type.startsWith('order_block') && !showOb) return;
            if (z.type.startsWith('imbalance') && !showFvg) return;
            if (lastBarTime < z.time_start) return;
            const tEnd = z.time_end ?? lastBarTime;
            const x1 = chart.timeScale().timeToCoordinate(z.time_start);
            const x2 = zoneEndXClipped(chart.timeScale(), tEnd, allChartData);
            const y1 = candlestickSeries.priceToCoordinate(z.price_high);
            const y2 = candlestickSeries.priceToCoordinate(z.price_low);
            if (x1 == null || x2 == null || y1 == null || y2 == null) return;
            if (Math.abs(x2 - x1) < 1) return;
            const paint = zonePaint[z.type] || zonePaint.default;
            const div = document.createElement('div');
            div.style.position = 'absolute';
            div.style.pointerEvents = 'none';
            div.style.boxSizing = 'border-box';
            div.style.left = Math.min(x1, x2) + 'px';
            div.style.top = Math.min(y1, y2) + 'px';
            div.style.width = Math.max(1, Math.abs(x2 - x1)) + 'px';
            div.style.height = Math.max(1, Math.abs(y2 - y1)) + 'px';
            div.style.background = z.fill || paint.background;
            div.style.border = z.border || paint.border;
            appendOrderBlockLabel(div, z);
            structureOverlay.appendChild(div);
        });
        renderTradeDurationOverlay();
        redrawPriceActionOverlay();
    }
    /** Upsert poll-свечей в allChartData; не заменять весь ряд узким окном ответа.
     *  Хвост: candleSmooth.toward() — close плавно доезжает до новой цены. */
    function applyIncrementalChartUpdate(newData) {
        if (!candlestickSeries || !newData.length) return;
        if (!allChartData.length) {
            allChartData = newData;
            oldestLoadedTime = Math.min(...allChartData.map(c => c.time));
            if (candleSmooth) candleSmooth.reset();
            candlestickSeries.setData(allChartData);
            if (candleSmooth && allChartData.length) {
                candleSmooth.snap(allChartData[allChartData.length - 1]);
            }
            renderChartMarkers();
            renderEntryPriceLines();
            tickCandleCloseTimer();
            applyPaBarColors({ fullResync: true });
            return;
        }

        const prevOldest = allChartData[0].time;
        const prevLast = allChartData[allChartData.length - 1].time;

        const byTime = new Map();
        for (const c of allChartData) byTime.set(c.time, c);
        for (const c of newData) byTime.set(c.time, c);
        const merged = Array.from(byTime.values()).sort((a, b) => a.time - b.time);
        const prependedOlder = merged.length > 0 && merged[0].time < prevOldest;
        const newestTime = merged[merged.length - 1].time;

        let updateFailed = false;
        for (const c of newData) {
            if (c.time < prevLast) {
                continue;
            }
            try {
                if (c.time === newestTime && candleSmooth) {
                    candleSmooth.toward(c);
                } else {
                    candlestickSeries.update(c);
                }
            } catch (e) {
                updateFailed = true;
                console.error('[bot chart] candle update', e);
            }
        }

        allChartData = merged;
        oldestLoadedTime = oldestLoadedTime == null
            ? merged[0].time
            : Math.min(oldestLoadedTime, merged[0].time);

        if (prependedOlder || updateFailed) {
            if (candleSmooth) candleSmooth.reset();
            candlestickSeries.setData(allChartData);
            if (candleSmooth && allChartData.length) {
                candleSmooth.snap(allChartData[allChartData.length - 1]);
            }
            applyPaBarColors({ fullResync: true });
        } else {
            applyPaBarColors();
        }

        renderChartMarkers();
        renderEntryPriceLines();
        tickCandleCloseTimer();
    }

    /**
     * Подтягиваем HTML-оверлеи (сделки, OB/FVG, DGT) к canvas при зуме/панораме по цене.
     *
     * subscribeVisibleLogicalRangeChange не вызывается, если меняется только вертикальный масштаб;
     * без доп. хука priceToCoordinate устаревает. Series primitive: updateAllViews() дергается
     * библиотекой при любом изменении viewport основной панели — планируем одну перерисовку на кадр.
     */
    let botChartDomOverlaySyncRaf = null;
    function scheduleBotChartDomOverlaySync() {
        if (botChartDomOverlaySyncRaf !== null) return;
        botChartDomOverlaySyncRaf = requestAnimationFrame(() => {
            botChartDomOverlaySyncRaf = null;
            try {
                redrawStructureOverlay();
                redrawPriceActionOverlay();
                redrawLlmLevelsOverlay();
            } catch (e) {
                console.error('[bot chart] dom overlay sync', e);
            }
            requestAnimationFrame(() => {
                try {
                    positionCandleTimerOverlay();
                } catch (e2) {
                    console.error('[bot chart] candle timer overlay sync', e2);
                }
            });
        });
    }
    function createBotChartDomOverlaySyncPrimitive() {
        const paneView = {
            renderer() {
                return {
                    draw() {
                        /* Рисование только в DOM; canvas не трогаем. */
                    },
                };
            },
        };
        const paneViewsStable = [paneView];
        return {
            updateAllViews() {
                scheduleBotChartDomOverlaySync();
            },
            paneViews() {
                return paneViewsStable;
            },
        };
    }

    /** Границы области свечей (pane 0): без ценовой шкалы справа, шкалы времени и pane осцилляторов. */
    function computeChartPlotBounds() {
        const cw = chartWrapper ? chartWrapper.clientWidth : 0;
        const ch = chartWrapper ? chartWrapper.clientHeight : 0;
        let scaleW = 78;
        try {
            if (candlestickSeries && typeof candlestickSeries.priceScale === 'function') {
                const w = Number(candlestickSeries.priceScale().width());
                if (Number.isFinite(w) && w > 0) scaleW = w;
            }
        } catch (_) {}
        let timeH = 28;
        try {
            if (chart && typeof chart.timeScale === 'function') {
                const h = Number(chart.timeScale().height());
                if (Number.isFinite(h) && h > 0) timeH = h;
            }
        } catch (_) {}
        // Высота только основного pane (свечи), иначе кроссхейр/клип заезжают на RSI/ATR.
        let plotH = Math.max(1, ch - timeH);
        try {
            if (chart && typeof chart.panes === 'function') {
                const panes = chart.panes();
                if (panes && panes[0] && typeof panes[0].getHeight === 'function') {
                    const ph = Number(panes[0].getHeight());
                    if (Number.isFinite(ph) && ph > 0) plotH = ph;
                }
            }
        } catch (_) {}
        return {
            plotW: Math.max(1, cw - scaleW),
            plotH,
            scaleW,
            timeH,
            cw,
            ch,
        };
    }

    function hideBotCrosshairOverlay() {
        [botCrosshairHLine, botCrosshairVLine, botCrosshairPrice, botCrosshairTime].forEach((el) => {
            if (el) el.classList.add('hidden');
        });
    }

    function formatBotCrosshairPrice(price) {
        if (!Number.isFinite(Number(price))) return '';
        return Number(price).toFixed(botChartPricePrecision);
    }

    function formatBotCrosshairTime(time) {
        if (time == null) return '';
        const sec = typeof time === 'number' ? time : Number(time);
        if (!Number.isFinite(sec)) return '';
        const d = new Date(sec * 1000);
        const p = (n) => String(n).padStart(2, '0');
        return `${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`;
    }

    /** Рисуем кроссхейр в HTML-слое z-3: поверх уровней LLM и других DOM-оверлеев. */
    function setupBotCrosshairOverlay() {
        if (!botCrosshairOverlay || !chart) return;
        if (!TV_CROSSHAIR_ENABLED) {
            botCrosshairOverlay.classList.add('hidden');
            hideBotCrosshairOverlay();
            return;
        }
        botCrosshairOverlay.classList.remove('hidden');
        chart.subscribeCrosshairMove((param) => {
            if (!TV_CROSSHAIR_ENABLED || !param || !param.point || !candlestickSeries) {
                hideBotCrosshairOverlay();
                return;
            }
            const x = Number(param.point.x);
            const y = Number(param.point.y);
            if (!Number.isFinite(x) || !Number.isFinite(y) || x < 0 || y < 0) {
                hideBotCrosshairOverlay();
                return;
            }
            const b = computeChartPlotBounds();
            if (x > b.plotW || y > b.plotH) {
                hideBotCrosshairOverlay();
                return;
            }

            if (botCrosshairHLine) {
                botCrosshairHLine.classList.remove('hidden');
                botCrosshairHLine.style.left = '0';
                botCrosshairHLine.style.top = y + 'px';
                botCrosshairHLine.style.width = b.plotW + 'px';
            }
            if (botCrosshairVLine) {
                botCrosshairVLine.classList.remove('hidden');
                botCrosshairVLine.style.top = '0';
                botCrosshairVLine.style.left = x + 'px';
                botCrosshairVLine.style.height = b.plotH + 'px';
            }

            let price = null;
            try {
                price = candlestickSeries.coordinateToPrice(y);
            } catch (_) {}
            if (botCrosshairPrice) {
                botCrosshairPrice.classList.remove('hidden');
                botCrosshairPrice.textContent = formatBotCrosshairPrice(price);
                botCrosshairPrice.style.right = '0';
                botCrosshairPrice.style.width = b.scaleW + 'px';
                botCrosshairPrice.style.top = Math.max(0, Math.min(b.ch - 18, y - 9)) + 'px';
            }

            if (botCrosshairTime) {
                botCrosshairTime.classList.remove('hidden');
                botCrosshairTime.textContent = formatBotCrosshairTime(param.time);
                const labelW = 72;
                botCrosshairTime.style.top = b.plotH + 'px';
                botCrosshairTime.style.left = Math.max(0, Math.min(b.plotW - labelW, x - labelW / 2)) + 'px';
            }
        });
    }

    /** Canvas (z-1) → оверлеи анализа (z-2) → HTML-кроссхейр (z-3). */
    function ensureChartLayerStack() {
        if (!chartWrapper) return;
        const lwTable = chartWrapper.querySelector(':scope > table');
        if (lwTable) {
            lwTable.style.position = 'relative';
            lwTable.style.zIndex = '1';
        }
        chartWrapper.querySelectorAll('.chart-dom-overlay').forEach((el) => {
            el.style.zIndex = '2';
        });
        if (botCrosshairOverlay) {
            botCrosshairOverlay.style.zIndex = '3';
        }
    }

    function createChart() {
        entryPriceLineHandles = [];
        if (candleSmooth) {
            candleSmooth.reset();
            candleSmooth = null;
        }
        if (chart) chart.remove();
        chartWrapper.classList.remove('hidden');
        structureOverlay.classList.remove('hidden');
        if (liquiditySwingsOverlay) liquiditySwingsOverlay.classList.remove('hidden');
        if (priceActionOverlay) priceActionOverlay.classList.remove('hidden');
        if (smcFlowOverlay) smcFlowOverlay.classList.remove('hidden');
        if (BOT_IS_CHART_ANALYST && llmLevelsOverlay) llmLevelsOverlay.classList.remove('hidden');
        chartPlaceholder.classList.add('hidden');
        // Собираем конфиг кроссхейра в зависимости от настройки из "Настройки бота":
        // - true: режим как в TradingView (свободный курсор + линии X/Y + подписи),
        // - false: упрощенный режим (прилипание к барам, скрытые линии и их подписи).
        const crosshairOptions = TV_CROSSHAIR_ENABLED
            ? {
                // Режим Normal оставляем для логики курсора; линии/подписи рисуем в #bot-crosshair-overlay (z-3).
                mode: LightweightCharts.CrosshairMode.Normal,
                vertLine: {
                    visible: false,
                    labelVisible: false,
                },
                horzLine: {
                    visible: false,
                    labelVisible: false,
                },
            }
            : {
                mode: LightweightCharts.CrosshairMode.Magnet,
                vertLine: {
                    visible: false,
                    labelVisible: false,
                },
                horzLine: {
                    visible: false,
                    labelVisible: false,
                },
            };
        chart = LightweightCharts.createChart(chartWrapper, {
            layout: {
                background: { type: 'solid', color: '#191c30' },
                textColor: '#a1a1aa',
                // Скрываем логотип TradingView в углу графика (licensing: attributionLogo).
                attributionLogo: false,
            },
            grid: { vertLines: { color: '#27272a' }, horzLines: { color: '#27272a' } },
            rightPriceScale: { borderColor: '#3f3f46', scaleMargins: { top: 0.08, bottom: 0.05 } },
            timeScale: { borderColor: '#3f3f46', timeVisible: true, secondsVisible: false },
            // Включаем "tradingview-like" интерактивность перемещения графика:
            // - drag мышью (зажали и тянем) = прокрутка истории влево/вправо,
            // - wheel и drag по осям = удобная навигация по времени/цене,
            // - touch-gesture = мобильное перетаскивание.
            // Явно задаем эти флаги, чтобы поведение было стабильным и не зависело от дефолтов версии библиотеки.
            handleScroll: {
                mouseWheel: true,
                pressedMouseMove: true,
                horzTouchDrag: true,
                vertTouchDrag: true,
            },
            // Разрешаем масштаб/смещение через взаимодействие с осями,
            // что приближает UX к TradingView при работе мышью.
            handleScale: {
                mouseWheel: true,
                pinch: true,
                axisPressedMouseMove: {
                    time: true,
                    price: true,
                },
            },
            // "Инерционный" доскролл после отпускания мыши/пальца для более естественного ощущения перетаскивания.
            kineticScroll: {
                mouse: true,
                touch: true,
            },
            crosshair: crosshairOptions,
            width: chartWrapper.clientWidth,
            height: chartWrapper.clientHeight
        });
        candlestickSeries = chart.addSeries(LightweightCharts.CandlestickSeries, {
            upColor: '#26a69a', downColor: '#ef5350', borderVisible: false,
            wickUpColor: '#26a69a', wickDownColor: '#ef5350',
            priceFormat: { type: 'price', precision: 5, minMove: 0.00001 }
        });
        // Плавный close последней свечи (~200ms ease-out между poll-обновлениями).
        if (window.PlatformCandleSmooth && typeof window.PlatformCandleSmooth.create === 'function') {
            candleSmooth = window.PlatformCandleSmooth.create({
                getSeries: () => candlestickSeries,
                paintBar: paintBarForSmooth,
                durationMs: 200,
            });
        } else {
            candleSmooth = null;
        }
        // LWC v5 markers plugin (вместо series.setMarkers).
        candlestickMarkers = null;
        try {
            if (window.PlatformChartMarkers && typeof window.PlatformChartMarkers.createPluginForSeries === 'function') {
                candlestickMarkers = window.PlatformChartMarkers.createPluginForSeries(candlestickSeries, []);
            } else if (typeof LightweightCharts.createSeriesMarkers === 'function') {
                candlestickMarkers = LightweightCharts.createSeriesMarkers(candlestickSeries, []);
            }
        } catch (e) {
            console.error('[bot chart] createSeriesMarkers', e);
        }
        classicLineSeries = {};
        rsiLevelLines = [];
        classicOscPaneCount = 0;
        try {
            candlestickSeries.attachPrimitive(createBotChartDomOverlaySyncPrimitive());
        } catch (e) {
            console.error('[bot chart] attachPrimitive(dom overlay sync)', e);
        }
        ensureChartLayerStack();
        setupBotCrosshairOverlay();
        chart.subscribeClick((param) => {
            if (!param || param.time == null) return;
            const clickedBucketTs = toBucketTime(toUnixSec(param.time));
            if (!Number.isFinite(clickedBucketTs)) return;
            const tradeId = tradeIdByChartClickTime(clickedBucketTs);
            if (tradeId != null) goToTradeHistoryById(tradeId);
        });
        chart.timeScale().subscribeVisibleLogicalRangeChange(() => {
            try {
                const range = chart.timeScale().getVisibleLogicalRange();
                if (range && currentTimeframeMinutes === 1 && !loadingMore && oldestLoadedTime != null && range.from < 2) loadMoreCandles();
                redrawStructureOverlay();
                requestAnimationFrame(positionCandleTimerOverlay);
            } catch (e) {
                console.error('[chart] visible range handler', e);
            }
        });
    }
    function loadMoreCandles() {
        if (currentTimeframeMinutes !== 1 || !currentInstrument || loadingMore || oldestLoadedTime == null) return;
        const earliestSignalTs = earliestSignalTsForInstrument(currentInstrument);
        const dynamicSince = (earliestSignalTs && Number.isFinite(earliestSignalTs))
            ? Math.min(candlesSince(), Math.max(0, earliestSignalTs - 3600))
            : candlesSince();
        if (oldestLoadedTime <= dynamicSince) return;
        loadingMore = true;
        setLoading(true);
        const inst = currentInstrument;
        const loadSeq = ++chartLoadSeq;
        fetchCandles(inst, { before: oldestLoadedTime, kind: 'more' }).then((data) => {
            if (loadSeq !== chartLoadSeq || inst !== currentInstrument) return;
            const newBars = buildChartData(data.candles || []);
            const existingTimes = new Set(allChartData.map(c => c.time));
            const toAdd = newBars.filter(c => !existingTimes.has(c.time));
            if (!toAdd.length) return;
            allChartData = [...toAdd, ...allChartData].sort((a, b) => a.time - b.time);
            oldestLoadedTime = Math.min(...allChartData.map(c => c.time));
            if (candleSmooth) candleSmooth.cancel();
            candlestickSeries.setData(allChartData);
            if (candleSmooth && allChartData.length) {
                candleSmooth.snap(allChartData[allChartData.length - 1]);
            }
            renderChartMarkers();
            renderEntryPriceLines();
            redrawStructureOverlay();
            fetchStructures();
            if (anyOverlayIndicatorLayerOn()) fetchPriceActionIndicator();
        }).finally(() => {
            if (loadSeq !== chartLoadSeq) return;
            loadingMore = false;
            setLoading(false);
            tickCandleCloseTimer();
        });
    }
    function startAutoRefresh() {
        if (refreshInterval) clearInterval(refreshInterval);
        const refreshMs = 500;
        refreshInterval = setInterval(() => {
            if (!currentInstrument || loadingMore || !candlestickSeries) return;
            const inst = currentInstrument;
            const seq = ++pollSeq;
            fetchCandles(inst, { kind: 'poll' }).then((data) => {
                if (seq !== pollSeq || inst !== currentInstrument) return;
                const newData = buildChartData(data.candles || []);
                if (!newData.length) return;
                const newLast = newData[newData.length - 1].time;
                const prevLast = allChartData.length ? allChartData[allChartData.length - 1].time : null;
                const followTail = isViewNearRightEdge();
                const rangeBefore = followTail && chart ? chart.timeScale().getVisibleLogicalRange() : null;
                applyIncrementalChartUpdate(newData);
                if (followTail && rangeBefore && chart && prevLast !== null && newLast > prevLast) {
                    const addedBars = newData.filter((c) => c.time > prevLast).length;
                    if (addedBars > 0 && Number.isFinite(rangeBefore.from) && Number.isFinite(rangeBefore.to)) {
                        chart.timeScale().setVisibleLogicalRange({
                            from: rangeBefore.from + addedBars,
                            to: rangeBefore.to + addedBars,
                        });
                    }
                }
                redrawStructureOverlay();
                // Структуры/индикаторы — НЕ на каждом 500мс poll (см. dashboard startOverlaysRefresh).
                // Индикаторы пересчитываем только когда закрылась/появилась новая свеча.
                if (prevLast !== null && newLast > prevLast && anyOverlayIndicatorLayerOn()) {
                    fetchPriceActionIndicator();
                }
            }).catch(() => {});
        }, refreshMs);
    }

    /** Медленный таймер тяжёлых оверлеев (структуры OB/FVG) — как на dashboard. */
    let heavyOverlaysRefreshInterval = null;
    const HEAVY_OVERLAYS_REFRESH_MS = 15000;
    function stopHeavyOverlaysRefresh() {
        if (heavyOverlaysRefreshInterval) {
            clearInterval(heavyOverlaysRefreshInterval);
            heavyOverlaysRefreshInterval = null;
        }
    }
    function startHeavyOverlaysRefresh() {
        stopHeavyOverlaysRefresh();
        heavyOverlaysRefreshInterval = setInterval(() => {
            if (!currentInstrument || loadingMore || !candlestickSeries) return;
            fetchStructures();
            // Подстраховка: если слой включён, но payload пуст (гонка) — force-пересчёт.
            if (anyOverlayIndicatorLayerOn()) {
                fetchPriceActionIndicator();
            }
        }, HEAVY_OVERLAYS_REFRESH_MS);
    }
    function reloadChartData() {
        if (!currentInstrument || !candlestickSeries) return;
        setLoading(true);
        const inst = currentInstrument;
        const loadSeq = ++chartLoadSeq;
        pollSeq++;
        fetchCandles(inst, { kind: 'initial' }).then((data) => {
            if (loadSeq !== chartLoadSeq || inst !== currentInstrument) return;
            allChartData = buildChartData(data.candles || []);
            if (allChartData.length) {
                oldestLoadedTime = Math.min(...allChartData.map(c => c.time));
                if (candleSmooth) candleSmooth.reset();
                candlestickSeries.setData(allChartData);
                if (candleSmooth) candleSmooth.snap(allChartData[allChartData.length - 1]);
                renderChartMarkers();
                renderEntryPriceLines();
                applyDefaultChartViewport();
                fetchStructures();
                fetchPriceActionIndicator({ force: true });
            } else {
                if (candleSmooth) candleSmooth.reset();
                candlestickSeries.setData([]);
                lastZones = [];
                structureOverlay.innerHTML = '';
                lastPaPayload = null;
                lastLsPayload = null;
                lastSmcPayload = null;
                if (priceActionOverlay) priceActionOverlay.innerHTML = '';
                if (liquiditySwingsOverlay) liquiditySwingsOverlay.innerHTML = '';
                if (smcFlowOverlay) smcFlowOverlay.innerHTML = '';
            }
            updateChartTitle();
            redrawStructureOverlay();
            renderEntryPriceLines();
            tickCandleCloseTimer();
            startHeavyOverlaysRefresh();
        }).finally(() => {
            if (loadSeq !== chartLoadSeq) return;
            setLoading(false);
        });
    }
    function selectInstrument(instrument, displayName) {
        if (currentInstrument === instrument) return;
        if (refreshInterval) {
            clearInterval(refreshInterval);
            refreshInterval = null;
        }
        stopHeavyOverlaysRefresh();
        pollSeq++;
        const loadSeq = ++chartLoadSeq;
        indicatorFetchSeq++; // инвалидируем in-flight индикаторы при смене пары
        const hadPriorInstrument = currentInstrument !== null;
        currentInstrument = instrument;
        currentDisplayName = displayName;
        saveSelectedInstrument(instrument);
        openTrades = [];
        renderOpenTradesOverlay();
        // При смене пары сразу убираем старый анализ; при первом выборе оставляем SSR до ответа open-trades (без мигания).
        if (hadPriorInstrument) updateSidebarActiveTradePanel();
        // Сразу тянем открытые сделки по новой паре (параллельно со свечами), чтобы анализ справа не отставал от графика.
        refreshOpenTrades();
        refreshSignalsHistory();
        refreshBotStats();
        document.querySelectorAll('.instrument-btn').forEach((b) => b.classList.toggle('bg-blue-900/40', b.dataset.instrument === instrument));
        lastZones = [];
        structureOverlay.innerHTML = '';
        lastPaPayload = null;
        lastLsPayload = null;
        lastSmcPayload = null;
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
        clearClassicOsEngineSeries();
        if (priceActionOverlay) priceActionOverlay.innerHTML = '';
        if (liquiditySwingsOverlay) liquiditySwingsOverlay.innerHTML = '';
        if (smcFlowOverlay) smcFlowOverlay.innerHTML = '';
        if (BOT_IS_CHART_ANALYST) clearLlmChartLevels();
        updateChartTitle();
        updateTfOptionStyles();
        setLoading(true);
        createChart();
        fetchCandles(instrument, { kind: 'initial' }).then((data) => {
            if (loadSeq !== chartLoadSeq || currentInstrument !== instrument) return;
            allChartData = buildChartData(data.candles || []);
            if (allChartData.length) {
                oldestLoadedTime = Math.min(...allChartData.map(c => c.time));
                if (candleSmooth) candleSmooth.reset();
                candlestickSeries.setData(allChartData);
                if (candleSmooth) candleSmooth.snap(allChartData[allChartData.length - 1]);
                renderChartMarkers();
                renderEntryPriceLines();
                applyDefaultChartViewport();
                focusTargetTradeOnChart();
                startAutoRefresh();
                startHeavyOverlaysRefresh();
                redrawStructureOverlay();
                fetchStructures();
                fetchPriceActionIndicator({ force: true });
            } else {
                allChartData = [];
                if (candleSmooth) candleSmooth.reset();
                candlestickSeries.setData([]);
                if (refreshInterval) {
                    clearInterval(refreshInterval);
                    refreshInterval = null;
                }
                stopHeavyOverlaysRefresh();
            }
            updateChartTitle();
            tickCandleCloseTimer();
        }).finally(() => {
            if (loadSeq !== chartLoadSeq) return;
            setLoading(false);
        });
    }

    tfBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        if (window.PlatformIndicatorsPopup) window.PlatformIndicatorsPopup.close();
        else layersPanel.classList.add('hidden');
        tfPanel.classList.toggle('hidden');
    });
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
    // Попап индикаторов / справка — в partials/chart-indicators-popup-script.
    document.addEventListener('click', () => { tfPanel.classList.add('hidden'); });
    layerOb.addEventListener('change', () => { saveLayerPrefs(); redrawStructureOverlay(); });
    layerFvg.addEventListener('change', () => { saveLayerPrefs(); redrawStructureOverlay(); });
    if (layerPa) {
        layerPa.addEventListener('change', () => {
            saveLayerPrefs();
            if (!layerPa.checked) lastPaPayload = null;
            fetchPriceActionIndicator({ force: true });
            applyPaBarColors({ fullResync: true });
        });
    }
    if (layerLiquidity) {
        layerLiquidity.addEventListener('change', () => {
            saveLayerPrefs();
            if (!layerLiquidity.checked) lastLsPayload = null;
            fetchPriceActionIndicator({ force: true });
            redrawStructureOverlay();
        });
    }
    if (layerSmc) {
        layerSmc.addEventListener('change', () => {
            saveLayerPrefs();
            if (!layerSmc.checked) lastSmcPayload = null;
            fetchPriceActionIndicator({ force: true });
            redrawStructureOverlay();
        });
    }
    // Классика OsEngine: при выключении чистим payload и перерисовываем line-series.
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
        [layerDonchian, () => { lastDonchianPayload = null; }],
        [layerStochRsi, () => { lastStochRsiPayload = null; }],
        [layerAc, () => { lastAcPayload = null; }],
        [layerBullsPower, () => { lastBullsPowerPayload = null; }],
        [layerBearsPower, () => { lastBearsPowerPayload = null; }],
        [layerDpo, () => { lastDpoPayload = null; }],
        [layerQstick, () => { lastQstickPayload = null; }],
        [layerVhFilter, () => { lastVhFilterPayload = null; }],
        [layerEfficiencyRatio, () => { lastEfficiencyRatioPayload = null; }],
        [layerRavi, () => { lastRaviPayload = null; }],
        [layerCog, () => { lastCogPayload = null; }],
        [layerPivotFloor, () => { lastPivotFloorPayload = null; }],
        [layerMassIndex, () => { lastMassIndexPayload = null; }],
        [layerNrtrWatr, () => { lastNrtrWatrPayload = null; }],
        [layerPriceChannelAdaptive, () => { lastPriceChannelAdaptivePayload = null; }],
        [layerPriceChannelOffset, () => { lastPriceChannelOffsetPayload = null; }],
        [layerParabolicBollinger, () => { lastParabolicBollingerPayload = null; }],
        [layerParabolicPriceChannel, () => { lastParabolicPriceChannelPayload = null; }],
        [layerAtrChannel, () => { lastAtrChannelPayload = null; }],
        [layerOffsetSma, () => { lastOffsetSmaPayload = null; }],
        [layerOffsetEma, () => { lastOffsetEmaPayload = null; }],
        [layerOffsetSsma, () => { lastOffsetSsmaPayload = null; }],
        [layerVolatilityAverage, () => { lastVolatilityAveragePayload = null; }],
        [layerVolatilityAverageChannel, () => { lastVolatilityAverageChannelPayload = null; }],
        [layerPriceOscillator, () => { lastPriceOscillatorPayload = null; }],
        [layerDynamicTrendDetector, () => { lastDynamicTrendDetectorPayload = null; }],
        [layerAdaptiveLookBack, () => { lastAdaptiveLookBackPayload = null; }],
        [layerLinRegChannelFast, () => { lastLinRegChannelFastPayload = null; }],
        [layerVolatilityCandles, () => { lastVolatilityCandlesPayload = null; }],
        [layerZigZag, () => { lastZigZagPayload = null; }],
        [layerVolatilityAverageTwice, () => { lastVolatilityAverageTwicePayload = null; }],
        [layerVolatilityStagesAw, () => { lastVolatilityStagesAwPayload = null; }],
    ].forEach(([el, clearFn]) => {
        if (!el) return;
        el.addEventListener('change', () => {
            saveLayerPrefs();
            if (!el.checked) clearFn();
            // force: сразу пересчитать, без throttle 2с (иначе слой «не появляется»).
            fetchPriceActionIndicator({ force: true });
        });
    });
    if (BOT_IS_CHART_ANALYST && layerLlmLevels) {
        layerLlmLevels.addEventListener('change', () => {
            saveLayerPrefs();
            redrawLlmLevelsOverlay();
        });
    }
    new ResizeObserver(() => {
        if (chart && chartWrapper.clientWidth) {
            chart.resize(chartWrapper.clientWidth, chartWrapper.clientHeight);
            ensureChartLayerStack();
            redrawStructureOverlay();
            redrawPriceActionOverlay();
            applyClassicOsEngineSeries();
            redrawLlmLevelsOverlay();
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
    botAnalysisBtn.addEventListener('click', fetchBotAnalysis);
    if (botAnalysisCollapseBtn) {
        botAnalysisCollapseBtn.addEventListener('click', () => {
            const collapsed = botAnalysisPanel && botAnalysisPanel.classList.contains('bot-analysis-collapsed');
            setBotAnalysisCollapsed(!collapsed);
        });
    }
    if (botAnalysisHeader) {
        botAnalysisHeader.addEventListener('click', (e) => {
            if (e.target.closest('button')) return;
            if (botAnalysisPanel && botAnalysisPanel.classList.contains('bot-analysis-collapsed')) {
                setBotAnalysisCollapsed(false);
            }
        });
    }
    botAnalysisCloseBtn.addEventListener('click', () => {
        botAnalysisPanel.classList.add('hidden');
        setBotAnalysisCollapsed(false);
        if (BOT_IS_CHART_ANALYST) clearLlmChartLevels();
    });

    loadLayerPrefs();
    updateTfOptionStyles();
    syncSearchClearButton();
    instrumentListRefreshInterval = setInterval(refreshInstrumentSidebar, 1000);
    openTradesTickInterval = setInterval(() => {
        tickOpenTradesOverlayTimers();
        updateSidebarActiveTradePanel();
        tickSidebarTradeTimers();
        tickCandleCloseTimer();
        renderTimeDebugPanel();
    }, 1000);
    renderOpenTradesOverlay();
    tickOpenTradesOverlayTimers();
    tickSidebarTradeTimers();
    tickCandleCloseTimer();
    renderTimeDebugPanel();
    refreshSignalsHistory();
    openTradesFetchInterval = setInterval(refreshOpenTrades, 3000);
    signalsRefreshInterval = setInterval(refreshSignalsHistory, 5000);
    // Блок "Статистика" рядом с графиком обновляем независимо от свечей и открытых сделок:
    // так карточки Total/Open/Wins/Losses/Winrate всегда отражают актуальное состояние бота.
    document.getElementById('bot-stats-scope-hour')?.addEventListener('click', () => setBotStatsScope('hour'));
    document.getElementById('bot-stats-scope-all')?.addEventListener('click', () => setBotStatsScope('all'));
    updateBotStatsScopeButtons();
    applyStatsToSidebar(STATS_BOOTSTRAP[botStatsScope]);
    botStatsRefreshInterval = setInterval(refreshBotStats, 3000);
    refreshBotStats();
    refreshInstrumentSidebar().then(() => {
        let ib = null;
        const targetSignal = findTargetSignal();
        const persisted = loadSelectedInstrument();
        if (targetSignal && targetSignal.instrument) {
            document.querySelectorAll('.instrument-btn').forEach((b) => {
                if (b.dataset.instrument === targetSignal.instrument) ib = b;
            });
        }
        if (!ib) {
            document.querySelectorAll('.instrument-btn').forEach((b) => {
                if (persisted && b.dataset.instrument === persisted) ib = b;
            });
        }
        if (!ib) {
            document.querySelectorAll('.instrument-btn').forEach((b) => {
                if (b.dataset.instrument === initialInstrument) ib = b;
            });
        }
        if (!ib) ib = document.querySelector('.instrument-btn');
        if (ib) ib.click();
    });
})();
</script>
@include('partials.chart-indicators-popup-script')
@endsection

