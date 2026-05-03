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
<span class="sr-only pointer-events-none bg-blue-900/40 bg-blue-900/50 bg-stone-800/45 text-stone-200" aria-hidden="true"></span>
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
                    {{-- Как на странице бота: у каждого слоя своя шестерёнка → те же маршруты настроек (бот-якорь из контроллера). --}}
                    <div id="layers-dropdown-panel"
                        class="hidden absolute left-0 top-full mt-1 z-20 bg-zinc-900 border border-zinc-700 rounded shadow-lg shadow-black/40 py-2 pl-3 pr-2 min-w-[14.5rem] space-y-1">
                        <div class="flex items-center justify-between gap-1">
                            <label for="layer-ob" class="flex min-w-0 flex-1 items-center gap-2 text-sm text-zinc-300 cursor-pointer">
                                <input type="checkbox" id="layer-ob" class="rounded border-zinc-600 bg-zinc-800 text-blue-500 shrink-0" checked>
                                <span class="truncate">Ордерблоки</span>
                            </label>
                            @if($chartIndicatorBot)
                                <a href="{{ route('bots.indicators.order-blocks.edit', $chartIndicatorBot) }}"
                                    class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded border border-zinc-700 bg-zinc-800/80 text-zinc-400 hover:bg-zinc-700 hover:text-zinc-100 focus:outline-none focus:ring-2 focus:ring-blue-500/50 pointer-events-auto"
                                    title="Настройки: ордерблоки"
                                    aria-label="Настройки ордерблоков на графике">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3.5 w-3.5" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.281Z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                    </svg>
                                </a>
                            @else
                                <a href="{{ route('bots.index') }}"
                                    class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded border border-zinc-700 bg-zinc-800/40 text-zinc-500 hover:bg-zinc-800 hover:text-zinc-300 focus:outline-none focus:ring-2 focus:ring-blue-500/50 pointer-events-auto"
                                    title="Создайте бота — страницы настроек индикаторов привязаны к боту"
                                    aria-label="Перейти к списку ботов">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3.5 w-3.5" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.281Z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                    </svg>
                                </a>
                            @endif
                        </div>
                        <div class="flex items-center justify-between gap-1">
                            <label for="layer-fvg" class="flex min-w-0 flex-1 items-center gap-2 text-sm text-zinc-300 cursor-pointer">
                                <input type="checkbox" id="layer-fvg" class="rounded border-zinc-600 bg-zinc-800 text-blue-500 shrink-0" checked>
                                <span class="truncate">Имбалансы</span>
                            </label>
                            @if($chartIndicatorBot)
                                <a href="{{ route('bots.indicators.fvg.edit', $chartIndicatorBot) }}"
                                    class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded border border-zinc-700 bg-zinc-800/80 text-zinc-400 hover:bg-zinc-700 hover:text-zinc-100 focus:outline-none focus:ring-2 focus:ring-blue-500/50 pointer-events-auto"
                                    title="Настройки: имбалансы (FVG)"
                                    aria-label="Настройки имбалансов на графике">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3.5 w-3.5" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.281Z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                    </svg>
                                </a>
                            @else
                                <a href="{{ route('bots.index') }}"
                                    class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded border border-zinc-700 bg-zinc-800/40 text-zinc-500 hover:bg-zinc-800 hover:text-zinc-300 focus:outline-none focus:ring-2 focus:ring-blue-500/50 pointer-events-auto"
                                    title="Создайте бота — страницы настроек индикаторов привязаны к боту"
                                    aria-label="Перейти к списку ботов">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3.5 w-3.5" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.281Z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                    </svg>
                                </a>
                            @endif
                        </div>
                        <div class="flex items-center justify-between gap-1">
                            <label for="layer-pa-dgt" class="flex min-w-0 flex-1 items-center gap-2 text-sm text-zinc-300 cursor-pointer">
                                <input type="checkbox" id="layer-pa-dgt" class="rounded border-zinc-600 bg-zinc-800 text-blue-500 shrink-0" checked>
                                <span class="truncate">Price Action (DGT)</span>
                            </label>
                            @if($chartIndicatorBot)
                                <a href="{{ route('bots.indicators.price-action-dgt.edit', $chartIndicatorBot) }}"
                                    class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded border border-zinc-700 bg-zinc-800/80 text-zinc-400 hover:bg-zinc-700 hover:text-zinc-100 focus:outline-none focus:ring-2 focus:ring-blue-500/50 pointer-events-auto"
                                    title="Настройки: Price Action (DGT)"
                                    aria-label="Настройки индикатора Price Action DGT">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3.5 w-3.5" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.281Z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                    </svg>
                                </a>
                            @else
                                <a href="{{ route('bots.index') }}"
                                    class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded border border-zinc-700 bg-zinc-800/40 text-zinc-500 hover:bg-zinc-800 hover:text-zinc-300 focus:outline-none focus:ring-2 focus:ring-blue-500/50 pointer-events-auto"
                                    title="Создайте бота — страницы настроек индикаторов привязаны к боту"
                                    aria-label="Перейти к списку ботов">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3.5 w-3.5" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.281Z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                    </svg>
                                </a>
                            @endif
                        </div>
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
            {{-- Оверлей индикатора DGT выше зон OB/FVG (как на странице бота). --}}
            <div id="price-action-overlay" class="absolute inset-0 z-[7] pointer-events-none overflow-visible hidden"></div>
            <div id="candle-timer-overlay"
                class="pointer-events-none absolute z-[6] hidden h-0 w-0 overflow-visible"
                title="До закрытия текущей минутной свечи (по времени открытия бара с графика, мм:сс)">
                <span id="candle-tf-label"
                    class="absolute left-1/2 z-[6] -translate-x-1/2 whitespace-nowrap text-[11px] font-medium leading-none text-zinc-400">M1</span>
                <span id="candle-close-timer"
                    class="absolute left-1/2 z-[6] -translate-x-1/2 whitespace-nowrap font-mono text-[11px] tabular-nums leading-none text-zinc-400"
                    aria-live="polite"></span>
            </div>
        </div>
    </div>
</div>

<script src="https://unpkg.com/lightweight-charts@4.1.0/dist/lightweight-charts.standalone.production.js"></script>
<script>
(function() {
    const CANDLES_URL = '{{ route("candles.data") }}';
    const STRUCTURES_URL = '{{ route("structures.data") }}';
    const PA_INDICATOR_URL = '{{ route("dashboard.indicators.price-action-dgt") }}';
    const INSTRUMENTS_SIDEBAR_URL = '{{ route("dashboard.instruments.data") }}';
    const LIMIT = 1440;
    const WINDOW_SEC = 86400;
    const M1_COUNTDOWN_SECONDS = 60;
    /**
     * Таймер M1: сервер в каждом ответе /candles отдаёт m1_seconds_to_close (учёт смещения шкалы time свечей vs PHP time).
     * Между опросами убываем локально по performance.now(), без смешивания «часов» на клиенте.
     */
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
        if (m1CloseCountdownAnchorSec === null || m1CloseCountdownAnchorPerfMs === null) {
            return null;
        }
        const elapsed = (performance.now() - m1CloseCountdownAnchorPerfMs) / 1000;
        return Math.max(0, Math.min(M1_COUNTDOWN_SECONDS, m1CloseCountdownAnchorSec - elapsed));
    }
    /**
     * Горизонталь таймера: xCenter = x0 + (x1 - x0) * t, где x0/x1 — якорь последнего бара и слот справа (≈ barSpacing).
     * t = 0 — у x0; t = 1 — на один интервал вправо; t = 1.5 / 2 — ещё дальше (полтора/два интервала). Только нижняя граница 0.
     */
    const CANDLE_TIMER_GAP_T = 3;

    const chartPlaceholder = document.getElementById('chart-placeholder');
    const chartWrapper = document.getElementById('chart-wrapper');
    const structureOverlay = document.getElementById('structure-overlay');
    const candleTimerOverlay = document.getElementById('candle-timer-overlay');
    const chartTitle = document.getElementById('chart-title');
    const chartLoading = document.getElementById('chart-loading');
    const tfBtn = document.getElementById('tf-dropdown-btn');
    const tfPanel = document.getElementById('tf-dropdown-panel');
    const tfLabelEl = document.getElementById('tf-dropdown-label');
    const candleCloseTimerEl = document.getElementById('candle-close-timer');
    const candleTfLabelEl = document.getElementById('candle-tf-label');
    const layersBtn = document.getElementById('layers-dropdown-btn');
    const layersPanel = document.getElementById('layers-dropdown-panel');
    const layerOb = document.getElementById('layer-ob');
    const layerFvg = document.getElementById('layer-fvg');
    const layerPa = document.getElementById('layer-pa-dgt');
    const priceActionOverlay = document.getElementById('price-action-overlay');
    const instrumentSearchInput = document.getElementById('instrument-search');
    const instrumentSearchClearBtn = document.getElementById('instrument-search-clear');
    const instrumentListEl = document.getElementById('instrument-list');

    const LAYER_PREFS_KEY = 'platform.dashboard.chartLayers.v2';

    function loadLayerPrefs() {
        try {
            const raw = localStorage.getItem(LAYER_PREFS_KEY);
            if (raw == null) {
                try {
                    const legacy = localStorage.getItem('platform.dashboard.chartLayers');
                    if (legacy == null) return;
                    const o = JSON.parse(legacy);
                    if (typeof o.orderBlocks === 'boolean') layerOb.checked = o.orderBlocks;
                    if (typeof o.imbalances === 'boolean') layerFvg.checked = o.imbalances;
                } catch (_) { /* ignore */ }
                return;
            }
            const o = JSON.parse(raw);
            if (typeof o.orderBlocks === 'boolean') layerOb.checked = o.orderBlocks;
            if (typeof o.imbalances === 'boolean') layerFvg.checked = o.imbalances;
            if (layerPa && typeof o.paDgt === 'boolean') layerPa.checked = o.paDgt;
        } catch (_) { /* ignore */ }
    }

    function saveLayerPrefs() {
        try {
            localStorage.setItem(LAYER_PREFS_KEY, JSON.stringify({
                orderBlocks: layerOb.checked,
                imbalances: layerFvg.checked,
                paDgt: layerPa ? layerPa.checked : false,
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
    let instrumentListRefreshInterval = null;
    let sidebarTimerInterval = null;
    let lastZones = [];
    let lastPaPayload = null;

    function payoutClassByValue(value) {
        if (value == null || Number.isNaN(value)) return 'text-zinc-500';
        if (value >= 80) return 'text-emerald-400';
        if (value >= 60) return 'text-amber-400';
        return 'text-rose-400';
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
            if (candleTimerOverlay) {
                candleTimerOverlay.classList.add('hidden');
            }
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
        if (x0 == null || Number.isNaN(x0)) {
            x0 = ts.timeToCoordinate(lastT);
        }
        if (x0 == null || Number.isNaN(x0)) {
            candleTimerOverlay.classList.add('hidden');
            return;
        }
        // Часто x1 === x0 или |x1-x0|≈0 — тогда (x1-x0)*t всегда 0 и CANDLE_TIMER_GAP_T «не работает».
        if (x1 == null || Number.isNaN(x1) || Math.abs(x1 - x0) < 0.5) {
            x1 = x0 + bs;
        }
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
        if (candleTfLabelEl) {
            candleTfLabelEl.textContent = 'M' + String(currentTimeframeMinutes);
        }
        candleCloseTimerEl.textContent = formatMmSs(left);
        requestAnimationFrame(positionCandleTimerOverlay);
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
            const name = document.createElement('span');
            name.className = 'font-medium text-zinc-100 truncate';
            name.textContent = inst.display_name || inst.instrument;
            nameWrap.appendChild(name);
            if (inst.open_trade && inst.expiry_ts) {
                const timer = document.createElement('span');
                timer.className = 'instrument-trade-timer shrink-0 text-xs font-mono tabular-nums text-stone-200';
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
                if (instrumentSearchInput) {
                    applyInstrumentFilter(instrumentSearchInput.value);
                }
                syncSearchClearButton();
                if (currentInstrument) {
                    document.querySelectorAll('.instrument-btn').forEach((b) => b.classList.toggle('bg-blue-900/40', b.dataset.instrument === currentInstrument));
                }
            })
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

    /** ~кол-во свечей по горизонтали и «воздух» справа (в долях бара), как на референсе */
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

    /**
     * Пользователь смотрит «живой» хвост: правая граница логического диапазона не левее последнего бара.
     * Раньше было lastIdx−3 — при отступе всего на пару баров от края всё равно вызывали scrollToRealTime(),
     * и график резко перескакивал к правому краю при закрытии новой свечи.
     */
    function isViewNearRightEdge() {
        if (!chart || allChartData.length === 0) return true;
        const range = chart.timeScale().getVisibleLogicalRange();
        if (!range) return true;
        const lastIdx = allChartData.length - 1;
        const edgeEps = 1e-3;
        return range.to >= lastIdx - edgeEps;
    }

    function redrawStructureOverlay() {
        if (!chart || !candlestickSeries || !structureOverlay) return;
        if (!allChartData.length) {
            structureOverlay.innerHTML = '';
            requestAnimationFrame(redrawPriceActionOverlay);
            return;
        }
        if (lastZones.length === 0) {
            structureOverlay.innerHTML = '';
            requestAnimationFrame(redrawPriceActionOverlay);
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
        requestAnimationFrame(redrawPriceActionOverlay);
    }

    function paCssColor(c) {
        if (c == null || c === '') return 'rgba(148,163,184,0.5)';
        const s = String(c);
        if (s.startsWith('#') && s.length === 9) return s;
        return s;
    }

    function fetchPriceActionIndicator() {
        if (!currentInstrument || !layerPa || !layerPa.checked) {
            lastPaPayload = null;
            redrawPriceActionOverlay();
            applyPaBarColors();
            return;
        }
        const params = new URLSearchParams({ instrument: currentInstrument, timeframe: String(currentTimeframeMinutes) });
        fetch(PA_INDICATOR_URL + '?' + params.toString(), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then((r) => r.json())
            .then((d) => {
                try {
                    lastPaPayload = d && d.indicator ? d.indicator : null;
                    redrawPriceActionOverlay();
                    applyPaBarColors();
                } catch (e) {
                    console.error('[PriceAction DGT] dashboard apply', e);
                    lastPaPayload = null;
                    try {
                        redrawPriceActionOverlay();
                        applyPaBarColors();
                    } catch (_) {}
                }
            })
            .catch(() => {});
    }

    function redrawPriceActionOverlay() {
        if (!priceActionOverlay || !chart || !candlestickSeries) return;
        priceActionOverlay.innerHTML = '';
        if (!layerPa || !layerPa.checked || !lastPaPayload || !allChartData.length) return;
        const ts = chart.timeScale();
        const vpRightAxisReservePx = 78;
        const vpPanelMaxHistPx = 100;
        const vpPanelMaxDomPx = 26;
        const vpPanelGapPx = 2;
        const computeVpAnchorRight = () => {
            const cw = chartWrapper.clientWidth || 0;
            if (!Number.isFinite(cw) || cw < 40) return null;
            return cw - vpRightAxisReservePx;
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
            console.error('[PriceAction DGT] dashboard redraw', e);
        }
    }

    function applyPaBarColors() {
        if (!candlestickSeries || !allChartData.length) return;
        const plain = allChartData.map((c) => ({ time: c.time, open: c.open, high: c.high, low: c.low, close: c.close }));
        const bc = lastPaPayload && lastPaPayload.bar_colors ? lastPaPayload.bar_colors : null;
        if (!layerPa || !layerPa.checked || !bc || Object.keys(bc).length === 0) {
            try { candlestickSeries.setData(plain); } catch (e) { console.error('[PA] setData', e); }
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
            try { candlestickSeries.setData(plain); } catch (e2) { console.error('[PA] fallback', e2); }
        }
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
            fetchCandles(currentInstrument)
                .then(data => {
                    const candles = data.candles || [];
                    if (candles.length === 0) return;
                    const newData = buildChartData(candles);
                    const newLastT = newData[newData.length - 1].time;
                    const prevLastT = allChartData.length ? allChartData[allChartData.length - 1].time : null;
                    // Снимаем диапазон сразу перед setData/update, чтобы не ловить рассинхрон после await fetch.
                    const followTail = isViewNearRightEdge();
                    const rangeBefore = followTail && chart ? chart.timeScale().getVisibleLogicalRange() : null;
                    applyIncrementalChartUpdate(newData);
                    tickCandleCloseTimer();
                    // Вместо scrollToRealTime(): он пересобирает отступ справа по правилам библиотеки и «прыгает».
                    // Сдвигаем тот же логический viewport на число новых баров — картинка для «режима следования» остаётся плавной.
                    if (followTail && rangeBefore && chart && prevLastT !== null && newLastT > prevLastT) {
                        const addedBars = newData.filter(c => c.time > prevLastT).length;
                        if (addedBars > 0 && Number.isFinite(rangeBefore.from) && Number.isFinite(rangeBefore.to)) {
                            chart.timeScale().setVisibleLogicalRange({
                                from: rangeBefore.from + addedBars,
                                to: rangeBefore.to + addedBars,
                            });
                        }
                    }
                    requestAnimationFrame(redrawStructureOverlay);
                    fetchStructures();
                    if (layerPa && layerPa.checked) fetchPriceActionIndicator();
                })
                .catch(console.error);
        }, 1000);
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
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then((r) => r.json())
            .then((payload) => {
                applyM1CountdownFromPayload(payload);
                return payload;
            });
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
            tickCandleCloseTimer();
            applyPaBarColors();
            requestAnimationFrame(redrawPriceActionOverlay);
            return;
        }

        const oldFirstTime = allChartData[0]?.time ?? null;
        const newFirstTime = newData[0]?.time ?? null;
        if (oldFirstTime == null || newFirstTime == null || oldFirstTime !== newFirstTime) {
            allChartData = newData;
            oldestLoadedTime = Math.min(...allChartData.map(c => c.time));
            candlestickSeries.setData(allChartData);
            tickCandleCloseTimer();
            applyPaBarColors();
            requestAnimationFrame(redrawPriceActionOverlay);
            return;
        }

        // LightweightCharts.update() умеет менять только последний бар или добавлять новый.
        // Поэтому обновляем только безопасный "хвост": последний и, при смене минуты, следующий за ним.
        const lastKnownTime = allChartData[allChartData.length - 1]?.time ?? 0;
        const tailCandles = newData.slice(-2).filter(c => c.time >= lastKnownTime);
        tailCandles.forEach(c => candlestickSeries.update(c));

        allChartData = newData;
        oldestLoadedTime = Math.min(...allChartData.map(c => c.time));
        tickCandleCloseTimer();
        applyPaBarColors();
        requestAnimationFrame(redrawPriceActionOverlay);
    }

    function createChart() {
        if (chart) chart.remove();
        chartWrapper.innerHTML = '';
        chartWrapper.classList.remove('hidden');
        structureOverlay.classList.remove('hidden');
        if (priceActionOverlay) priceActionOverlay.classList.remove('hidden');
        chartPlaceholder.classList.add('hidden');

        chart = LightweightCharts.createChart(chartWrapper, {
            layout: {
                background: { type: 'solid', color: '#191c30' },
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

        chart.timeScale().subscribeVisibleLogicalRangeChange(() => {
            const range = chart.timeScale().getVisibleLogicalRange();
            if (range && currentTimeframeMinutes === 1 && !loadingMore && oldestLoadedTime != null && range.from < 2) {
                loadMoreCandles();
            }
            redrawStructureOverlay();
            requestAnimationFrame(redrawPriceActionOverlay);
            requestAnimationFrame(positionCandleTimerOverlay);
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
                tickCandleCloseTimer();
                applyPaBarColors();
                requestAnimationFrame(redrawStructureOverlay);
                fetchStructures();
                if (layerPa && layerPa.checked) fetchPriceActionIndicator();
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
                    applyDefaultChartViewport();
                    fetchStructures();
                    fetchPriceActionIndicator();
                } else {
                    candlestickSeries.setData([]);
                    lastZones = [];
                    structureOverlay.innerHTML = '';
                    lastPaPayload = null;
                    if (priceActionOverlay) priceActionOverlay.innerHTML = '';
                }
                updateChartTitle();
                tickCandleCloseTimer();
                requestAnimationFrame(redrawStructureOverlay);
                setLoading(false);
            })
            .catch(() => {
                setLoading(false);
                tickCandleCloseTimer();
            });
    }

    function selectInstrument(instrument, displayName) {
        if (currentInstrument === instrument) return;
        currentInstrument = instrument;
        currentDisplayName = displayName;
        document.querySelectorAll('.instrument-btn').forEach((b) => b.classList.toggle('bg-blue-900/40', b.dataset.instrument === instrument));
        lastZones = [];
        structureOverlay.innerHTML = '';
        lastPaPayload = null;
        if (priceActionOverlay) priceActionOverlay.innerHTML = '';
        updateChartTitle();
        updateTfOptionStyles();
        tickCandleCloseTimer();
        setLoading(true);
        createChart();

        fetchCandles(instrument)
            .then(data => {
                const candles = data.candles || [];
                allChartData = buildChartData(candles);
                if (allChartData.length) {
                    oldestLoadedTime = Math.min(...allChartData.map(c => c.time));
                    candlestickSeries.setData(allChartData);
                    applyDefaultChartViewport();
                    startAutoRefresh();
                    requestAnimationFrame(() => {
                        redrawStructureOverlay();
                        fetchStructures();
                        fetchPriceActionIndicator();
                    });
                } else {
                    if (refreshInterval) clearInterval(refreshInterval);
                }
                updateChartTitle();
                tickCandleCloseTimer();
                setLoading(false);
            })
            .catch(() => {
                setLoading(false);
                if (refreshInterval) clearInterval(refreshInterval);
                tickCandleCloseTimer();
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
    if (layerPa) {
        layerPa.addEventListener('change', () => {
            saveLayerPrefs();
            if (!layerPa.checked) lastPaPayload = null;
            fetchPriceActionIndicator();
            requestAnimationFrame(redrawStructureOverlay);
        });
    }

    new ResizeObserver(() => {
        if (!chart || !chartWrapper.clientWidth) return;
        chart.resize(chartWrapper.clientWidth, chartWrapper.clientHeight);
        requestAnimationFrame(() => {
            redrawStructureOverlay();
            redrawPriceActionOverlay();
            positionCandleTimerOverlay();
        });
    }).observe(chartWrapper);

    if (instrumentListEl) {
        instrumentListEl.addEventListener('click', (e) => {
            const btn = e.target.closest('.instrument-btn');
            if (!btn) return;
            selectInstrument(btn.dataset.instrument, btn.dataset.displayName);
        });
    }

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
    syncSearchClearButton();

    instrumentListRefreshInterval = setInterval(refreshInstrumentSidebar, 1000);
    sidebarTimerInterval = setInterval(() => {
        tickSidebarTradeTimers();
        tickCandleCloseTimer();
    }, 1000);
    tickSidebarTradeTimers();
    refreshInstrumentSidebar().then(() => {
        tickCandleCloseTimer();
        const first = document.querySelector('#instrument-list .instrument-btn');
        if (first) first.click();
    });
})();
</script>
@endsection
