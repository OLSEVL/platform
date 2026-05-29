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
                    <div id="layers-dropdown-panel" class="hidden absolute left-0 top-full mt-1 z-20 bg-zinc-900 border border-zinc-700 rounded shadow-lg shadow-black/40 py-2 pl-3 pr-2 min-w-[14.5rem] space-y-1">
                        <div class="flex items-center justify-between gap-1">
                            <label for="layer-ob" class="flex min-w-0 flex-1 items-center gap-2 text-sm text-zinc-300 cursor-pointer">
                                <input type="checkbox" id="layer-ob" class="rounded border-zinc-600 bg-zinc-800 text-blue-500 shrink-0">
                                <span class="truncate">Ордерблоки</span>
                            </label>
                            <a href="{{ route('bots.indicators.order-blocks.edit', $bot) }}"
                                class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded border border-zinc-700 bg-zinc-800/80 text-zinc-400 hover:bg-zinc-700 hover:text-zinc-100 focus:outline-none focus:ring-2 focus:ring-blue-500/50 pointer-events-auto"
                                title="Настройки: ордерблоки"
                                aria-label="Настройки ордерблоков на графике">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3.5 w-3.5" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.281Z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                </svg>
                            </a>
                        </div>
                        <div class="flex items-center justify-between gap-1">
                            <label for="layer-fvg" class="flex min-w-0 flex-1 items-center gap-2 text-sm text-zinc-300 cursor-pointer">
                                <input type="checkbox" id="layer-fvg" class="rounded border-zinc-600 bg-zinc-800 text-blue-500 shrink-0">
                                <span class="truncate">Имбалансы</span>
                            </label>
                            <a href="{{ route('bots.indicators.fvg.edit', $bot) }}"
                                class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded border border-zinc-700 bg-zinc-800/80 text-zinc-400 hover:bg-zinc-700 hover:text-zinc-100 focus:outline-none focus:ring-2 focus:ring-blue-500/50 pointer-events-auto"
                                title="Настройки: имбалансы (FVG)"
                                aria-label="Настройки имбалансов на графике">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3.5 w-3.5" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.281Z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                </svg>
                            </a>
                        </div>
                        <div class="flex items-center justify-between gap-1">
                            <label for="layer-pa-dgt" class="flex min-w-0 flex-1 items-center gap-2 text-sm text-zinc-300 cursor-pointer">
                                <input type="checkbox" id="layer-pa-dgt" class="rounded border-zinc-600 bg-zinc-800 text-blue-500 shrink-0">
                                <span class="truncate">Price Action (DGT)</span>
                            </label>
                            <a href="{{ route('bots.indicators.price-action-dgt.edit', $bot) }}"
                                class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded border border-zinc-700 bg-zinc-800/80 text-zinc-400 hover:bg-zinc-700 hover:text-zinc-100 focus:outline-none focus:ring-2 focus:ring-blue-500/50 pointer-events-auto"
                                title="Настройки: Price Action (DGT)"
                                aria-label="Настройки индикатора Price Action DGT">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3.5 w-3.5" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.281Z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                </svg>
                            </a>
                        </div>
                        <div class="flex items-center justify-between gap-1">
                            <label for="layer-liquidity-swings" class="flex min-w-0 flex-1 items-center gap-2 text-sm text-zinc-300 cursor-pointer">
                                <input type="checkbox" id="layer-liquidity-swings" class="rounded border-zinc-600 bg-zinc-800 text-blue-500 shrink-0">
                                <span class="truncate">Liquidity Swings</span>
                            </label>
                            <a href="{{ route('bots.indicators.liquidity-swings.edit', $bot) }}"
                                class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded border border-zinc-700 bg-zinc-800/80 text-zinc-400 hover:bg-zinc-700 hover:text-zinc-100 focus:outline-none focus:ring-2 focus:ring-blue-500/50 pointer-events-auto"
                                title="Настройки: Liquidity Swings"
                                aria-label="Настройки индикатора Liquidity Swings">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3.5 w-3.5" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.281Z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                </svg>
                            </a>
                        </div>
                        <div class="flex items-center justify-between gap-1">
                            <label for="layer-smc-flow" class="flex min-w-0 flex-1 items-center gap-2 text-sm text-zinc-300 cursor-pointer">
                                <input type="checkbox" id="layer-smc-flow" class="rounded border-zinc-600 bg-zinc-800 text-blue-500 shrink-0">
                                <span class="truncate">SMC Flow (UT Bot)</span>
                            </label>
                            <a href="{{ route('bots.indicators.smc-flow.edit', $bot) }}"
                                class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded border border-zinc-700 bg-zinc-800/80 text-zinc-400 hover:bg-zinc-700 hover:text-zinc-100 focus:outline-none focus:ring-2 focus:ring-blue-500/50 pointer-events-auto"
                                title="Настройки: SMC Flow"
                                aria-label="Настройки индикатора SMC Flow">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3.5 w-3.5" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.281Z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                </svg>
                            </a>
                        </div>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-1.5 shrink-0">
                    <button type="button" id="bot-analysis-btn"
                        class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">Анализ</button>
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
        {{-- Панель объяснения входа вынесена над графиком: абсолютные слои графика не перекрывают текст анализа. --}}
        <div id="bot-analysis-panel" class="hidden border-b border-zinc-800 bg-zinc-900/95 px-3 py-2 text-xs">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div id="bot-analysis-summary" class="font-medium text-zinc-200">Анализ не запрошен.</div>
                        <div id="bot-analysis-meta" class="mt-0.5 text-zinc-500"></div>
                    </div>
                    <button type="button" id="bot-analysis-close" class="shrink-0 rounded border border-zinc-700 bg-zinc-800 px-2 py-0.5 text-[11px] text-zinc-300 hover:bg-zinc-700">Закрыть</button>
                </div>
                <div id="bot-analysis-text" class="mt-2 whitespace-pre-wrap break-words text-zinc-300"></div>
                <div id="bot-analysis-reasons-wrap" class="mt-2 hidden">
                    <div class="text-[10px] uppercase tracking-wide text-zinc-500">Причины / триггеры</div>
                    <ul id="bot-analysis-reasons" class="mt-1 list-disc space-y-1 pl-4 text-zinc-300"></ul>
                </div>
                <div id="bot-analysis-blockers-wrap" class="mt-2 hidden">
                    <div class="text-[10px] uppercase tracking-wide text-rose-400">Что блокирует вход</div>
                    <ul id="bot-analysis-blockers" class="mt-1 list-disc space-y-1 pl-4 text-rose-300"></ul>
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
            <div id="chart-wrapper" class="absolute inset-0 hidden z-[1]"></div>
            {{-- Полосы длительности сделок (entry -> expiry) поверх графика. --}}
            <div id="trade-duration-overlay" class="absolute inset-0 z-[4] pointer-events-none overflow-hidden hidden"></div>
            <div id="structure-overlay" class="absolute inset-0 z-[5] pointer-events-none overflow-visible hidden"></div>
            <div id="liquidity-swings-overlay" class="absolute inset-0 z-[6] pointer-events-none overflow-visible hidden"></div>
            {{-- Индикатор Price Action DGT: z выше structure-overlay, чтобы боковой VP не перекрывался зонами OB/FVG. --}}
            <div id="price-action-overlay" class="absolute inset-0 z-[7] pointer-events-none overflow-visible hidden"></div>
            <div id="smc-flow-overlay" class="absolute inset-0 z-[8] pointer-events-none overflow-visible hidden"></div>
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

<script src="https://unpkg.com/lightweight-charts@4.1.0/dist/lightweight-charts.standalone.production.js"></script>
<script>
(function() {
    const CANDLES_URL = '{{ route("candles.data") }}';
    const STRUCTURES_URL = '{{ route("structures.data") }}';
    const PA_INDICATOR_URL = '{{ route("bots.indicators.data", $bot) }}';
    const INSTRUMENTS_SIDEBAR_URL = '{{ route("bots.instruments.sidebar-data", $bot) }}';
    const OPEN_TRADES_URL = '{{ route("bots.open-trades.data", $bot) }}';
    const SIGNALS_URL = '{{ route("bots.signals.data", $bot) }}';
    const TRADES_HISTORY_URL = '{{ route("bots.trades") }}';
    const BOT_ANALYSIS_URL = '{{ route("bots.analysis.data", $bot) }}';
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
    const instrumentSearchInput = document.getElementById('instrument-search');
    const instrumentSearchClearBtn = document.getElementById('instrument-search-clear');
    const botAnalysisBtn = document.getElementById('bot-analysis-btn');
    const botAnalysisPanel = document.getElementById('bot-analysis-panel');
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
    // Без записи в localStorage все слои выключены (чекбоксы без checked в разметке).
    const LAYER_PREFS_KEY = 'platform.bot.show.chartLayers.v5';
    let lastPaPayload = null;
    let lastLsPayload = null;
    let lastSmcPayload = null;

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
    let signalsRefreshInterval = null;
    let botStatsRefreshInterval = null;
    let botAnalysisLoading = false;
    // Последний ручной анализ по кнопке "Анализ" для выбранной пары; используем в правой панели при отсутствии сделки.
    let latestManualAnalysis = null;
    let lastZones = [];
    let openTrades = [];
    let entryPriceLineHandles = [];
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
            if (layerSmc && typeof obj.smcFlow === 'boolean') layerSmc.checked = obj.smcFlow;
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
            }));
        } catch (_) {}
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
     * Подтягивает расчёты индикаторов с /bots/{id}/indicators/data (DGT + Liquidity Swings в одном JSON).
     * Запрос выполняем, если включен хотя бы один из слоёв — иначе экономим БД и сеть.
     */
    function fetchPriceActionIndicator() {
        if (!currentInstrument) return;
        const wantPa = layerPa && layerPa.checked;
        const wantLs = layerLiquidity && layerLiquidity.checked;
        const wantSmc = layerSmc && layerSmc.checked;
        if (!wantPa && !wantLs && !wantSmc) {
            lastPaPayload = null;
            lastLsPayload = null;
            lastSmcPayload = null;
            redrawPriceActionOverlay();
            applyPaBarColors();
            renderChartMarkers();
            return;
        }
        const params = new URLSearchParams({ instrument: currentInstrument, timeframe: String(currentTimeframeMinutes) });
        fetch(`${PA_INDICATOR_URL}?${params.toString()}`, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then((r) => r.json())
            .then((d) => {
                try {
                    lastPaPayload = wantPa && d && d.indicator ? d.indicator : null;
                    lastLsPayload = wantLs && d && d.liquidity_swings ? d.liquidity_swings : null;
                    lastSmcPayload = wantSmc && d && d.smc_flow ? d.smc_flow : null;
                    redrawPriceActionOverlay();
                    applyPaBarColors();
                    renderChartMarkers();
                } catch (e) {
                    console.error('[indicators] apply', e);
                    lastPaPayload = null;
                    lastLsPayload = null;
                    lastSmcPayload = null;
                    try {
                        redrawPriceActionOverlay();
                        applyPaBarColors();
                        renderChartMarkers();
                    } catch (_) {}
                }
            })
            .catch(() => {});
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

    function redrawSmcFlowOverlay() {
        if (!smcFlowOverlay || !chart || !candlestickSeries) return;
        smcFlowOverlay.innerHTML = '';
        if (!layerSmc || !layerSmc.checked || !lastSmcPayload || !allChartData.length) return;
        const ts = chart.timeScale();
        try {
            const areas = Array.isArray(lastSmcPayload.areas) ? lastSmcPayload.areas : [];
            const lines = Array.isArray(lastSmcPayload.lines) ? lastSmcPayload.lines : [];
            const fragment = document.createDocumentFragment();
            const viewportW = Math.max(1, chartWrapper?.clientWidth || 0);
            const maxRenderable = Math.max(250, Math.min(900, Math.floor(viewportW * 0.9)));
            let rendered = 0;
            // Зоны рендерим первым слоем (под линиями/лейблами), как в TradingView.
            areas.forEach((ar) => {
                if (rendered >= maxRenderable) return;
                if (!ar || typeof ar !== 'object') return;
                const x1 = ts.timeToCoordinate(Number(ar.time_start));
                const x2 = ts.timeToCoordinate(Number(ar.time_end));
                const y1 = candlestickSeries.priceToCoordinate(Number(ar.price_high));
                const y2 = candlestickSeries.priceToCoordinate(Number(ar.price_low));
                if (x1 == null || x2 == null || y1 == null || y2 == null) return;
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
            lines.forEach((ln) => {
                if (rendered >= maxRenderable) return;
                if (!ln || typeof ln !== 'object') return;
                const x1 = ts.timeToCoordinate(Number(ln.time_start));
                const x2 = ts.timeToCoordinate(Number(ln.time_end));
                const priceStart = Number(ln.price_start ?? ln.price);
                const priceEnd = Number(ln.price_end ?? ln.price);
                const y1 = candlestickSeries.priceToCoordinate(priceStart);
                const y2 = candlestickSeries.priceToCoordinate(priceEnd);
                if (x1 == null || x2 == null || y1 == null || y2 == null) return;
                if ([x1, x2, y1, y2].some((v) => Number.isNaN(v))) return;
                if (Math.max(x1, x2) < -60 || Math.min(x1, x2) > viewportW + 60) return;
                appendOverlaySegment(
                    fragment,
                    x1,
                    y1,
                    x2,
                    y2,
                    paCssColor(ln.color),
                    Math.max(1, Number(ln.width) || 2)
                );
                rendered++;
            });
            const labels = Array.isArray(lastSmcPayload.labels) ? lastSmcPayload.labels : [];
            labels.forEach((lb) => {
                if (!lb || typeof lb !== 'object') return;
                const x = ts.timeToCoordinate(Number(lb.time));
                const y = candlestickSeries.priceToCoordinate(Number(lb.price));
                if (x == null || y == null || Number.isNaN(x) || Number.isNaN(y)) return;
                if (x < -80 || x > viewportW + 80) return;
                const span = document.createElement('span');
                span.textContent = String(lb.text || '');
                span.style.position = 'absolute';
                span.style.left = x + 'px';
                span.style.top = y + 'px';
                span.style.transform = (lb.position === 'below')
                    ? 'translate(-50%, 6px)'
                    : 'translate(-50%, calc(-100% - 4px))';
                span.style.fontSize = lsLabelFontPx(lastSmcPayload.label_size);
                span.style.fontWeight = '600';
                span.style.lineHeight = '1';
                span.style.color = paCssColor(lb.color || '#ffffff');
                span.style.background = paCssColor(lb.background || '#22c55e');
                span.style.padding = '2px 5px';
                span.style.borderRadius = '3px';
                span.style.pointerEvents = 'none';
                span.style.whiteSpace = 'nowrap';
                fragment.appendChild(span);
            });
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

    /** Окраска свечей по объёму (Volume Weighted Colored Bars), если включено на сервере. */
    function applyPaBarColors() {
        if (!candlestickSeries || !allChartData.length) return;
        const plain = allChartData.map((c) => ({ time: c.time, open: c.open, high: c.high, low: c.low, close: c.close }));
        const bc = lastPaPayload && lastPaPayload.bar_colors ? lastPaPayload.bar_colors : null;
        if (!layerPa || !layerPa.checked || !bc || Object.keys(bc).length === 0) {
            try { candlestickSeries.setData(plain); } catch (e) { console.error('[PA] setData plain', e); }
            return;
        }
        const painted = allChartData.map((c) => {
            const o = bc[String(c.time)];
            if (!o) return { time: c.time, open: c.open, high: c.high, low: c.low, close: c.close };
            return {
                time: c.time, open: c.open, high: c.high, low: c.low, close: c.close,
                color: o.color, borderColor: o.borderColor || o.color, wickColor: o.wickColor || o.color,
            };
        });
        try {
            candlestickSeries.setData(painted);
        } catch (e) {
            console.error('[PA] setData painted', e);
            try { candlestickSeries.setData(plain); } catch (e2) { console.error('[PA] setData fallback', e2); }
        }
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
        const p = Number(rawPrice);
        if (!Number.isFinite(p)) return null;
        return p.toFixed(5);
    }

    // Собираем хвост подписи вида " (1.23456)".
    // Отдельная функция нужна, чтобы одинаково добавлять цену и к маркеру входа, и к маркеру завершения.
    function markerPriceSuffix(rawPrice) {
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
        const markers = [];
        mergedSignals(instrument).forEach((s) => {
            if (s.instrument !== instrument) return;
            const tradeId = (s.trade && s.trade.id) ? Number(s.trade.id) : null;
            const isTargetTrade = targetTradeId !== null && Number.isFinite(tradeId) && tradeId === targetTradeId;
            const signalTime = toBucketTime(toUnixSec(s.signal_at));
            if (signalTime) {
                const isCall = s.side === 'CALL';
                const tradeIdSuffix = Number.isFinite(tradeId) ? ` ${tradeId}` : '';
                const entryPriceSuffix = markerPriceSuffix(s.trade ? s.trade.entry_price : null);
                markers.push({
                    time: signalTime,
                    position: isCall ? 'belowBar' : 'aboveBar',
                    color: '#ffffff',
                    shape: isTargetTrade ? 'circle' : (isCall ? 'arrowUp' : 'arrowDown'),
                    text: isTargetTrade
                        ? `TARGET${tradeIdSuffix}`
                        : (isCall ? `ВВЕРХ${tradeIdSuffix}${entryPriceSuffix}` : `ВНИЗ${tradeIdSuffix}${entryPriceSuffix}`),
                });
            }
            const expiryRaw = (s.trade && s.trade.expiry_time) ? s.trade.expiry_time : s.expiry_time;
            if (expiryRaw) {
                // Сначала проверяем замороженные параметры — если есть, используем их.
                const frozen = getFrozenExpiryParams(tradeId);
                const expiryTime = frozen ? frozen.expiryTime : toBucketTime(toUnixSec(expiryRaw));
                if (expiryTime) {
                    const result = ((s.trade && s.trade.result) || s.result || 'pending');
                    const color = result === 'win' ? '#10b981' : (result === 'loss' ? '#f43f5e' : '#a1a1aa');
                    const tradeIdSuffix = Number.isFinite(tradeId) ? ` ${tradeId}` : '';

                    // Используем замороженную цену если она есть, иначе берём из сделки.
                    const expiryPriceRaw = frozen ? frozen.price : (s.trade ? s.trade.expiry_price : null);
                    const expiryPriceSuffix = markerPriceSuffix(expiryPriceRaw);

                    let resultLabel = `EXP ${String(result || '').toUpperCase()}`.trim();
                    if (result === 'win') resultLabel = `WIN${tradeIdSuffix}${expiryPriceSuffix}`;
                    if (result === 'loss') resultLabel = `LOSE${tradeIdSuffix}${expiryPriceSuffix}`;
                    // Важно: маркер завершения сделки рисуем ВНЕ свечи, иначе при плотном потоке баров/сигналов
                    // круг inBar визуально "пропадает" в теле свечи и создаётся впечатление, что история исчезла.
                    // Направление (над/под баром) привязываем к side, чтобы маркеры читались одинаково.
                    const side = (s.side || '').toUpperCase();
                    const expiryPos = side === 'CALL' ? 'belowBar' : 'aboveBar';
                    const expiryShape = result === 'pending' ? 'circle' : 'square';
                    markers.push({
                        time: expiryTime,
                        position: expiryPos,
                        color: isTargetTrade ? '#f59e0b' : color,
                        shape: isTargetTrade ? 'square' : expiryShape,
                        text: isTargetTrade ? `TARGET ${resultLabel}` : resultLabel,
                    });
                }
            }
        });
        return markers.sort((a, b) => a.time - b.time);
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
        const nowSec = Math.floor(Date.now() / 1000);
        const lastBarTime = allChartData.length ? Number(allChartData[allChartData.length - 1].time) : null;
        const active = (openTrades || [])
            .filter((t) => t.instrument === currentInstrument && toUnixSec(t.expiry_ts) > nowSec);
        const markers = [];
        active.forEach((t) => {
            const side = (t.side || '').toUpperCase();
            const isCall = side === 'CALL';
            const entryTime = toBucketTime(toUnixSec(t.entry_ts));
            if (entryTime) {
                markers.push({
                    time: entryTime,
                    position: isCall ? 'belowBar' : 'aboveBar',
                    color: isCall ? '#10b981' : '#f43f5e',
                    shape: 'circle',
                    text: '● OPEN',
                });
            }
            if (lastBarTime) {
                markers.push({
                    time: lastBarTime,
                    position: isCall ? 'belowBar' : 'aboveBar',
                    color: isCall ? '#10b981' : '#f43f5e',
                    shape: 'square',
                    text: `${side} OPEN`,
                });
            }
        });
        return markers;
    }
    function renderChartMarkers() {
        if (!candlestickSeries || !currentInstrument) return;
        const base = markersForInstrument(currentInstrument);
        const active = activeTradeMarkersForCurrentInstrument();
        const paMk = (layerPa && layerPa.checked && lastPaPayload && Array.isArray(lastPaPayload.markers)) ? lastPaPayload.markers : [];
        candlestickSeries.setMarkers([...base, ...active, ...paMk].sort((a, b) => a.time - b.time));
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
    function renderBotAnalysis(data) {
        if (!botAnalysisPanel) return;
        botAnalysisPanel.classList.remove('hidden');
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
        // Синхронизируем правую панель сразу после получения анализа, чтобы можно было сравнить с режимом "сделка открыта".
        updateSidebarActiveTradePanel();
    }
    async function fetchBotAnalysis() {
        if (botAnalysisLoading) return;
        if (!currentInstrument) {
            renderBotAnalysis({
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
        const prevLabel = botAnalysisBtn ? botAnalysisBtn.textContent : '';
        if (botAnalysisBtn) {
            botAnalysisBtn.disabled = true;
            botAnalysisBtn.textContent = 'Анализ…';
        }
        try {
            const url = `${BOT_ANALYSIS_URL}?instrument=${encodeURIComponent(currentInstrument)}`;
            const res = await fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            const payload = await res.json();
            renderBotAnalysis(payload);
        } catch (_) {
            renderBotAnalysis({
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
            if (botAnalysisBtn) {
                botAnalysisBtn.disabled = false;
                botAnalysisBtn.textContent = prevLabel || 'Анализ';
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
    function refreshInstrumentSidebar() {
        return fetch(INSTRUMENTS_SIDEBAR_URL, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then((r) => r.json())
            .then((d) => {
                if (d && d.server_time) lastServerTimes.instruments = d.server_time;
                renderInstrumentSidebarRows(d.instruments || []);
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

    function fetchCandles(instrument, before) {
        // Для полного обзора используем динамический since: от самого раннего сигнала по выбранной паре (с часовым запасом).
        const earliestSignalTs = earliestSignalTsForInstrument(instrument);
        const dynamicSince = (earliestSignalTs && Number.isFinite(earliestSignalTs))
            ? Math.min(candlesSince(), Math.max(0, earliestSignalTs - 3600))
            : candlesSince();
        const params = new URLSearchParams({ instrument, timeframe: String(currentTimeframeMinutes), limit: LIMIT, since: String(dynamicSince) });
        if (before != null) params.set('before', before);
        // Просим partial для всех TF (кроме пагинации before),
        // а backend сам преобразует S5-partial в live-bar выбранного timeframe.
        if (before == null) {
            params.set('include_partial', '1');
        }
        return fetch(`${CANDLES_URL}?${params.toString()}`, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then((r) => r.json())
            .then((payload) => {
                applyM1CountdownFromPayload(payload);
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
        renderTradeDurationOverlay();
        redrawPriceActionOverlay();
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
            applyPaBarColors();
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
            applyPaBarColors();
            return;
        }
        const lastKnown = allChartData[allChartData.length - 1]?.time ?? 0;
        newData.slice(-2).filter(c => c.time >= lastKnown).forEach(c => candlestickSeries.update(c));
        allChartData = newData;
        oldestLoadedTime = Math.min(...allChartData.map(c => c.time));
        renderChartMarkers();
        renderEntryPriceLines();
        tickCandleCloseTimer();
        applyPaBarColors();
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

    function createChart() {
        entryPriceLineHandles = [];
        if (chart) chart.remove();
        chartWrapper.classList.remove('hidden');
        structureOverlay.classList.remove('hidden');
        if (liquiditySwingsOverlay) liquiditySwingsOverlay.classList.remove('hidden');
        if (priceActionOverlay) priceActionOverlay.classList.remove('hidden');
        if (smcFlowOverlay) smcFlowOverlay.classList.remove('hidden');
        chartPlaceholder.classList.add('hidden');
        // Собираем конфиг кроссхейра в зависимости от настройки из "Настройки бота":
        // - true: режим как в TradingView (свободный курсор + линии X/Y + подписи),
        // - false: упрощенный режим (прилипание к барам, скрытые линии и их подписи).
        const crosshairOptions = TV_CROSSHAIR_ENABLED
            ? {
                mode: LightweightCharts.CrosshairMode.Normal,
                vertLine: {
                    visible: true,
                    width: 1,
                    color: '#94a3b8',
                    style: LightweightCharts.LineStyle.Solid,
                    labelVisible: true,
                    labelBackgroundColor: '#334155',
                },
                horzLine: {
                    visible: true,
                    width: 1,
                    color: '#94a3b8',
                    style: LightweightCharts.LineStyle.Solid,
                    labelVisible: true,
                    labelBackgroundColor: '#334155',
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
            layout: { background: { type: 'solid', color: '#191c30' }, textColor: '#a1a1aa' },
            grid: { vertLines: { color: '#27272a' }, horzLines: { color: '#27272a' } },
            rightPriceScale: { borderColor: '#3f3f46', scaleMargins: { top: 0.1, bottom: 0.2 } },
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
        candlestickSeries = chart.addCandlestickSeries({
            upColor: '#26a69a', downColor: '#ef5350', borderVisible: false,
            priceFormat: { type: 'price', precision: 5, minMove: 0.00001 }
        });
        try {
            candlestickSeries.attachPrimitive(createBotChartDomOverlaySyncPrimitive());
        } catch (e) {
            console.error('[bot chart] attachPrimitive(dom overlay sync)', e);
        }
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
            if ((layerPa && layerPa.checked) || (layerLiquidity && layerLiquidity.checked) || (layerSmc && layerSmc.checked)) fetchPriceActionIndicator();
        }).finally(() => {
            loadingMore = false;
            setLoading(false);
            tickCandleCloseTimer();
        });
    }
    function startAutoRefresh() {
        if (refreshInterval) clearInterval(refreshInterval);
        // Интервал опроса свечей для всех таймфреймов: 500ms (как было ранее на платформе).
        // Частые запросы держат частичную (формирующуюся) свечу и оверлеи ближе к реальному потоку цены.
        // Нагрузка на сервер выше, чем при 1–2s — при множестве вкладок при необходимости поднимите значение.
        const refreshMs = 500;
        refreshInterval = setInterval(() => {
            if (!currentInstrument || loadingMore || !candlestickSeries) return;
            fetchCandles(currentInstrument).then((data) => {
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
                fetchStructures();
                if ((layerPa && layerPa.checked) || (layerLiquidity && layerLiquidity.checked) || (layerSmc && layerSmc.checked)) fetchPriceActionIndicator();
            }).catch(() => {});
        }, refreshMs);
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
                fetchPriceActionIndicator();
            } else {
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
        }).finally(() => setLoading(false));
    }
    function selectInstrument(instrument, displayName) {
        if (currentInstrument === instrument) return;
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
        if (priceActionOverlay) priceActionOverlay.innerHTML = '';
        if (liquiditySwingsOverlay) liquiditySwingsOverlay.innerHTML = '';
        if (smcFlowOverlay) smcFlowOverlay.innerHTML = '';
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
                // После дефолтного viewport прицельно фокусируемся на целевой сделке (если есть trade_id).
                focusTargetTradeOnChart();
                startAutoRefresh();
                redrawStructureOverlay();
                fetchStructures();
                fetchPriceActionIndicator();
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
    if (layerPa) {
        layerPa.addEventListener('change', () => {
            saveLayerPrefs();
            if (!layerPa.checked) lastPaPayload = null;
            fetchPriceActionIndicator();
        });
    }
    if (layerLiquidity) {
        layerLiquidity.addEventListener('change', () => {
            saveLayerPrefs();
            if (!layerLiquidity.checked) lastLsPayload = null;
            fetchPriceActionIndicator();
            redrawStructureOverlay();
        });
    }
    if (layerSmc) {
        layerSmc.addEventListener('change', () => {
            saveLayerPrefs();
            if (!layerSmc.checked) lastSmcPayload = null;
            fetchPriceActionIndicator();
            redrawStructureOverlay();
        });
    }
    new ResizeObserver(() => {
        if (chart && chartWrapper.clientWidth) {
            chart.resize(chartWrapper.clientWidth, chartWrapper.clientHeight);
            redrawStructureOverlay();
            redrawPriceActionOverlay();
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
    botAnalysisCloseBtn.addEventListener('click', () => botAnalysisPanel.classList.add('hidden'));

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
@endsection

