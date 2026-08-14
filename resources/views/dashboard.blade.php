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
{{-- Классы ниже нужны Tailwind CDN (выставляются из JS / кастомный switch «Торговля») --}}
<span class="sr-only pointer-events-none bg-blue-900/40 bg-blue-900/50 bg-stone-800/45 text-stone-200 bg-emerald-600 peer-checked:bg-emerald-600 peer-checked:translate-x-4 translate-x-4 peer-focus-visible:ring-2 peer-focus-visible:ring-emerald-500/50" aria-hidden="true"></span>
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
                        class="instrument-btn w-full text-left px-4 py-3 hover:bg-zinc-800 focus:bg-zinc-800 focus:outline-none transition-colors {{ $openTradeInstruments->contains($inst->instrument) ? 'bg-stone-800/45' : '' }}"
                    data-instrument="{{ $inst->instrument }}"
                    data-display-name="{{ e($inst->display_name ?? $inst->instrument) }}"
                    data-search="{{ mb_strtolower(($inst->display_name ?? $inst->instrument).' '.$inst->instrument) }}"
                        data-open-trade="{{ $openTradeInstruments->contains($inst->instrument) ? '1' : '0' }}">
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex min-w-0 flex-1 items-center gap-1.5">
                            <span class="font-medium text-zinc-100 truncate">{{ $inst->display_name ?? $inst->instrument }}</span>
                                @if($openTradeInstruments->contains($inst->instrument) && isset($nearestExpiryByInstrument[$inst->instrument]))
                                    <span class="instrument-trade-timer shrink-0 text-xs font-mono tabular-nums text-stone-200" data-expiry-ts="{{ (int) $nearestExpiryByInstrument[$inst->instrument] }}">{{ str_pad((string) intdiv(max(0, (int) $nearestExpiryByInstrument[$inst->instrument] - now()->timestamp), 60), 2, '0', STR_PAD_LEFT) }}:{{ str_pad((string) (max(0, (int) $nearestExpiryByInstrument[$inst->instrument] - now()->timestamp) % 60), 2, '0', STR_PAD_LEFT) }}</span>
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
                    @include('partials.chart-indicators-popup', [
                        'indicatorBot' => $chartIndicatorBot ?? null,
                        'showLlmLevels' => false,
                    ])
                </div>
            </div>
            {{-- Справа: переключатель показа торговли + статус загрузки. --}}
            <div class="flex items-center gap-3 shrink-0">
                <label
                    for="show-trades-toggle"
                    class="inline-flex items-center gap-2 cursor-pointer select-none"
                    title="Показывать открытые и закрытые сделки на графике и в списке инструментов. Выключите — чистый график без торговли."
                >
                    <span id="show-trades-label" class="text-xs text-zinc-400">Торговля</span>
                    {{-- Кастомный switch: peer-классы работают с Tailwind CDN. --}}
                    <span class="relative inline-block h-5 w-9 shrink-0">
                        <input
                            type="checkbox"
                            id="show-trades-toggle"
                            class="peer sr-only"
                            checked
                        >
                        <span class="block h-5 w-9 rounded-full bg-zinc-700 transition-colors peer-checked:bg-emerald-600 peer-focus-visible:ring-2 peer-focus-visible:ring-emerald-500/50"></span>
                        <span class="pointer-events-none absolute left-0.5 top-0.5 h-4 w-4 rounded-full bg-zinc-100 shadow transition-transform peer-checked:translate-x-4"></span>
                    </span>
                </label>
                <span id="chart-loading" class="text-sm text-zinc-500 hidden">Загрузка…</span>
            </div>
        </div>
        <div id="chart-container" class="flex-1 min-h-0 relative">
            <div id="chart-placeholder" class="absolute inset-0 flex items-center justify-center text-zinc-500 bg-zinc-950/90 z-0">
                Нажмите на инструмент слева, чтобы показать минутный график
            </div>
            <div id="chart-wrapper" class="absolute inset-0 hidden z-[1]"></div>
            <div id="structure-overlay" class="absolute inset-0 z-[5] pointer-events-none overflow-visible hidden"></div>
            {{-- Liquidity Swings: между OB/FVG и DGT (как на референсе TradingView / Screenshot_4). --}}
            <div id="liquidity-swings-overlay" class="absolute inset-0 z-[6] pointer-events-none overflow-visible hidden"></div>
            {{-- Оверлей индикатора DGT выше зон OB/FVG (как на странице бота). --}}
            <div id="price-action-overlay" class="absolute inset-0 z-[7] pointer-events-none overflow-visible hidden"></div>
            {{-- SMC Flow (UT Bot): поверх DGT, отдельный слой — не смешиваем с Price Action. --}}
            <div id="smc-flow-overlay" class="absolute inset-0 z-[8] pointer-events-none overflow-visible hidden"></div>
            {{-- PO-стиль: горизонтальные лучи открытых сделок (точка входа + линия до экспирации). --}}
            <div id="po-open-trades-overlay" class="absolute inset-0 z-[8] pointer-events-none overflow-hidden hidden"></div>
            {{-- Названия включённых индикаторов (оверлеи на основной pane + лейблы осцилляторов). --}}
            <div id="classic-indicator-labels" class="absolute inset-0 z-[9] pointer-events-none overflow-hidden hidden"></div>
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

    {{-- Правая панель входа в сделку (стиль Pocket Option): время / сумма / выплата / CALL|PUT. --}}
    @php
        $manualTrade = $manualTrade ?? [
            'ssid_ready' => false,
            'is_demo' => true,
            'default_amount' => 1,
            'martingale_amount' => 1,
            'manual_respect_wait_previous' => false,
            'manual_advance_martingale' => false,
            'manual_amount_from_martingale' => false,
            'profile_url' => route('profile.trading.edit'),
            'settings_url' => route('trading.manual-settings'),
        ];
        $manualDefaultAmount = number_format((float) ($manualTrade['default_amount'] ?? 1), 2, '.', '');
        $manualDefaultAmount = rtrim(rtrim($manualDefaultAmount, '0'), '.');
        if ($manualDefaultAmount === '' || $manualDefaultAmount === '0') {
            $manualDefaultAmount = '1';
        }
        $manualMartingaleAmount = number_format((float) ($manualTrade['martingale_amount'] ?? $manualTrade['default_amount'] ?? 1), 2, '.', '');
        $manualMartingaleAmount = rtrim(rtrim($manualMartingaleAmount, '0'), '.');
        if ($manualMartingaleAmount === '' || $manualMartingaleAmount === '0') {
            $manualMartingaleAmount = '1';
        }
        $manualAmountFromMg = ! empty($manualTrade['manual_amount_from_martingale']);
    @endphp
    <aside id="manual-trade-panel"
        class="w-[15.5rem] shrink-0 border-l border-zinc-800 bg-[#161a23] flex flex-col min-h-0 overflow-hidden"
        data-ssid-ready="{{ !empty($manualTrade['ssid_ready']) ? '1' : '0' }}"
        data-is-demo="{{ !empty($manualTrade['is_demo']) ? '1' : '0' }}"
        data-profile-url="{{ e($manualTrade['profile_url'] ?? route('profile.trading.edit')) }}"
        data-order-url="{{ route('trading.manual-order') }}"
        data-settings-url="{{ e($manualTrade['settings_url'] ?? route('trading.manual-settings')) }}"
        data-martingale-amount="{{ e($manualMartingaleAmount) }}"
        data-flag-wait="{{ !empty($manualTrade['manual_respect_wait_previous']) ? '1' : '0' }}"
        data-flag-martingale="{{ !empty($manualTrade['manual_advance_martingale']) ? '1' : '0' }}"
        data-flag-amount-mg="{{ $manualAmountFromMg ? '1' : '0' }}">
        {{-- Верх: ручной вход CALL/PUT (как на PO). --}}
        <div class="shrink-0 p-3 space-y-3 border-b border-zinc-800/80">
            <div class="flex items-center justify-between gap-2">
                <p class="text-[11px] font-medium uppercase tracking-wide text-zinc-500">Сделка</p>
                <div class="flex items-center gap-1.5">
                    {{-- Шестерёнка: три тумблера wait / мартингейл / сумма с шага. --}}
                    <button type="button" id="manual-trade-settings-btn"
                        class="inline-flex h-6 w-6 items-center justify-center rounded text-zinc-400 transition hover:bg-zinc-800 hover:text-zinc-100"
                        title="Настройки ручной торговли" aria-haspopup="dialog" aria-controls="manual-trade-settings-modal">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                    </button>
                    <span id="manual-trade-account-badge"
                        class="rounded px-1.5 py-0.5 text-[10px] font-semibold {{ !empty($manualTrade['is_demo']) ? 'bg-amber-900/50 text-amber-200' : 'bg-rose-900/50 text-rose-200' }}">
                        {{ !empty($manualTrade['is_demo']) ? 'DEMO' : 'REAL' }}
                    </span>
                </div>
            </div>

            {{-- Время экспирации: поле + поповер как на Pocket Option (степперы + пресеты). --}}
            <div class="space-y-1">
                <label for="manual-trade-duration" class="block text-xs text-zinc-400">Время</label>
                <div class="relative" id="manual-trade-duration-wrap">
                    <input id="manual-trade-duration" type="text" inputmode="numeric"
                        value="00:05:00"
                        placeholder="00:05:00"
                        autocomplete="off"
                        spellcheck="false"
                        aria-haspopup="dialog"
                        aria-controls="manual-trade-duration-popover"
                        class="w-full rounded-lg border border-zinc-700 bg-zinc-900/80 px-3 py-2.5 pr-9 font-mono text-sm font-medium tabular-nums text-zinc-100 placeholder:text-zinc-600 focus:outline-none focus:ring-2 focus:ring-blue-600"
                        title="Формат MM:SS или HH:MM:SS (от 00:00:03 до 12:00:00)">
                    <span class="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 text-zinc-500" aria-hidden="true">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                            <circle cx="12" cy="12" r="8.5"/><path stroke-linecap="round" d="M12 7.5V12l2.8 1.7"/>
                        </svg>
                    </span>
                </div>
            </div>

            {{-- Сумма ставки ($): UI или шаг мартингейла (read-only) — тумблер в модалке --}}
            <div class="space-y-1">
                <div class="flex items-center justify-between gap-2">
                    <label for="manual-trade-amount" class="block text-xs text-zinc-400">Сумма</label>
                    <span id="manual-trade-amount-hint"
                        class="text-[10px] text-zinc-600 {{ $manualAmountFromMg ? '' : 'hidden' }}">шаг МГ</span>
                </div>
                <div class="relative">
                    <input id="manual-trade-amount" type="number" min="1" max="20000" step="0.01"
                        value="{{ $manualAmountFromMg ? $manualMartingaleAmount : $manualDefaultAmount }}"
                        @if($manualAmountFromMg) readonly @endif
                        class="w-full rounded-lg border border-zinc-700 bg-zinc-900/80 px-3 py-2.5 pr-9 text-sm font-medium text-zinc-100 focus:outline-none focus:ring-2 focus:ring-blue-600 read-only:bg-zinc-900/50 read-only:text-zinc-300 read-only:ring-0"
                        autocomplete="off">
                    <span class="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 text-zinc-500 text-sm font-semibold" aria-hidden="true">$</span>
                </div>
            </div>

            {{-- Выплата по текущему payout инструмента --}}
            <div class="space-y-1">
                <div class="flex items-center justify-between text-xs text-zinc-400">
                    <span>Выплата</span>
                    <span id="manual-trade-profit-usd" class="tabular-nums">+$0.00</span>
                </div>
                <div class="rounded-lg border border-dashed border-zinc-700/80 bg-zinc-900/40 px-3 py-3 text-center">
                    <p id="manual-trade-payout-pct" class="text-lg font-semibold text-emerald-400 tabular-nums">—</p>
                    <p id="manual-trade-payout-total" class="mt-0.5 text-sm text-emerald-400/70 tabular-nums">+$0.00</p>
                </div>
            </div>

            @if(empty($manualTrade['ssid_ready']))
                <p class="rounded border border-amber-900/60 bg-amber-950/40 px-2.5 py-2 text-[11px] leading-snug text-amber-100/90">
                    SSID не готов.
                    <a href="{{ $manualTrade['profile_url'] ?? route('profile.trading.edit') }}" class="underline hover:text-amber-50">Настроить в профиле</a>
                </p>
            @endif

            <div id="manual-trade-status" class="hidden rounded border px-2.5 py-2 text-[11px] leading-snug" role="status" aria-live="polite"></div>

            <div class="space-y-2 pt-1">
                <button type="button" id="manual-trade-buy"
                    class="flex w-full items-center justify-center gap-2 rounded-lg bg-[#22c55e] px-3 py-3 text-sm font-bold uppercase tracking-wide text-white shadow-sm transition hover:bg-emerald-500 disabled:cursor-not-allowed disabled:opacity-45"
                    {{ empty($manualTrade['ssid_ready']) ? 'disabled' : '' }}>
                    <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-white/20" aria-hidden="true">
                        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 15l7-7 7 7"/></svg>
                    </span>
                    Купить
                </button>
                <button type="button" id="manual-trade-sell"
                    class="flex w-full items-center justify-center gap-2 rounded-lg bg-[#ef4444] px-3 py-3 text-sm font-bold uppercase tracking-wide text-white shadow-sm transition hover:bg-red-500 disabled:cursor-not-allowed disabled:opacity-45"
                    {{ empty($manualTrade['ssid_ready']) ? 'disabled' : '' }}>
                    <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-white/20" aria-hidden="true">
                        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                    </span>
                    Продать
                </button>
            </div>
        </div>

        {{-- Низ: компактная лента зеркалимых на Pocket Option сделок (poll live-trades.data). --}}
        <div class="flex min-h-0 flex-1 flex-col">
            <div class="shrink-0 flex items-center justify-between gap-2 border-b border-zinc-800/80 px-3 py-2">
                <div class="min-w-0">
                    <p class="text-[11px] font-medium uppercase tracking-wide text-zinc-500">Сделки PO</p>
                    <p id="dash-po-trades-meta" class="truncate text-[10px] text-zinc-600">Загрузка…</p>
                </div>
                <a href="{{ route('bots.live-trades') }}"
                    class="shrink-0 rounded border border-zinc-700 bg-zinc-900/80 px-1.5 py-0.5 text-[10px] text-zinc-300 hover:bg-zinc-800 hover:text-zinc-100"
                    title="Полная лента сделок">Все</a>
            </div>
            <div id="dash-po-trades-list"
                class="min-h-0 flex-1 overflow-y-auto overscroll-contain divide-y divide-zinc-800/80"
                aria-live="polite">
                <div class="px-3 py-6 text-center text-[11px] text-zinc-500">Загрузка…</div>
            </div>
        </div>
    </aside>
</div>

{{-- Модалка настроек ручной торговли (три тумблера → user_trading_settings). --}}
<div id="manual-trade-settings-modal" class="fixed inset-0 z-50 hidden" role="dialog" aria-modal="true" aria-labelledby="manual-trade-settings-title">
    <div class="absolute inset-0 bg-black/60" data-manual-settings-dismiss></div>
    <div class="relative mx-auto flex min-h-full items-center justify-center p-4">
        <div class="w-full max-w-sm rounded-xl border border-zinc-700 bg-[#1a1f2b] shadow-xl">
            <div class="flex items-center justify-between gap-2 border-b border-zinc-800 px-4 py-3">
                <h2 id="manual-trade-settings-title" class="text-sm font-semibold text-zinc-100">Ручная торговля</h2>
                <button type="button" class="rounded p-1 text-zinc-400 hover:bg-zinc-800 hover:text-zinc-100" data-manual-settings-dismiss aria-label="Закрыть">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="space-y-4 px-4 py-4 text-sm text-zinc-200">
                <label class="flex cursor-pointer items-start gap-3">
                    <input id="manual-flag-wait" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-zinc-600 bg-zinc-900 text-blue-600 focus:ring-blue-600">
                    <span>
                        <span class="block font-medium text-zinc-100">Ждать предыдущую на PO</span>
                        <span class="mt-0.5 block text-[11px] leading-snug text-zinc-500">Не открывать поверх open-сделки; своя open блокирует зеркало ботов.</span>
                    </span>
                </label>
                <label class="flex cursor-pointer items-start gap-3">
                    <input id="manual-flag-martingale" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-zinc-600 bg-zinc-900 text-blue-600 focus:ring-blue-600">
                    <span>
                        <span class="block font-medium text-zinc-100">Двигать мартингейл</span>
                        <span class="mt-0.5 block text-[11px] leading-snug text-zinc-500">Win/loss ручной сделки сдвигает шаг сумм в профиле.</span>
                    </span>
                </label>
                <label class="flex cursor-pointer items-start gap-3">
                    <input id="manual-flag-amount-mg" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-zinc-600 bg-zinc-900 text-blue-600 focus:ring-blue-600">
                    <span>
                        <span class="block font-medium text-zinc-100">Сумма с шага мартингейла</span>
                        <span class="mt-0.5 block text-[11px] leading-snug text-zinc-500">Поле суммы = текущий шаг профиля (только чтение).</span>
                    </span>
                </label>
                <p id="manual-settings-status" class="hidden text-[11px] leading-snug" role="status"></p>
            </div>
            <div class="flex items-center justify-end gap-2 border-t border-zinc-800 px-4 py-3">
                <button type="button" data-manual-settings-dismiss
                    class="rounded-lg border border-zinc-700 px-3 py-1.5 text-xs font-medium text-zinc-300 hover:bg-zinc-800">Отмена</button>
                <button type="button" id="manual-settings-save"
                    class="rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-blue-500">Сохранить</button>
            </div>
        </div>
    </div>
</div>

{{-- Поповер времени как на PO: fixed, чтобы не клипался overflow панели. --}}
<div id="manual-trade-duration-popover"
    class="fixed z-[60] hidden w-[13.5rem] rounded-xl border border-zinc-700/90 bg-[#1c2230] p-3 shadow-2xl shadow-black/50"
    role="dialog" aria-label="Выбор времени экспирации">
    {{-- Степперы HH : MM : SS --}}
    <div class="flex items-center justify-center gap-1.5 pb-3">
        <div class="flex w-14 flex-col items-center gap-1">
            <button type="button" data-dur-step="h" data-dur-delta="1"
                class="flex h-8 w-full items-center justify-center rounded-md bg-zinc-800/90 text-lg font-semibold text-zinc-200 transition hover:bg-zinc-700 hover:text-white"
                aria-label="Час плюс">+</button>
            <span id="manual-dur-h" class="py-1 font-mono text-xl font-semibold tabular-nums text-zinc-50">00</span>
            <button type="button" data-dur-step="h" data-dur-delta="-1"
                class="flex h-8 w-full items-center justify-center rounded-md bg-zinc-800/90 text-lg font-semibold text-zinc-200 transition hover:bg-zinc-700 hover:text-white"
                aria-label="Час минус">−</button>
        </div>
        <span class="pb-1 text-lg font-semibold text-zinc-500">:</span>
        <div class="flex w-14 flex-col items-center gap-1">
            <button type="button" data-dur-step="m" data-dur-delta="1"
                class="flex h-8 w-full items-center justify-center rounded-md bg-zinc-800/90 text-lg font-semibold text-zinc-200 transition hover:bg-zinc-700 hover:text-white"
                aria-label="Минута плюс">+</button>
            <span id="manual-dur-m" class="py-1 font-mono text-xl font-semibold tabular-nums text-zinc-50">05</span>
            <button type="button" data-dur-step="m" data-dur-delta="-1"
                class="flex h-8 w-full items-center justify-center rounded-md bg-zinc-800/90 text-lg font-semibold text-zinc-200 transition hover:bg-zinc-700 hover:text-white"
                aria-label="Минута минус">−</button>
        </div>
        <span class="pb-1 text-lg font-semibold text-zinc-500">:</span>
        <div class="flex w-14 flex-col items-center gap-1">
            <button type="button" data-dur-step="s" data-dur-delta="1"
                class="flex h-8 w-full items-center justify-center rounded-md bg-zinc-800/90 text-lg font-semibold text-zinc-200 transition hover:bg-zinc-700 hover:text-white"
                aria-label="Секунда плюс">+</button>
            <span id="manual-dur-s" class="py-1 font-mono text-xl font-semibold tabular-nums text-zinc-50">00</span>
            <button type="button" data-dur-step="s" data-dur-delta="-1"
                class="flex h-8 w-full items-center justify-center rounded-md bg-zinc-800/90 text-lg font-semibold text-zinc-200 transition hover:bg-zinc-700 hover:text-white"
                aria-label="Секунда минус">−</button>
        </div>
    </div>
    {{-- Быстрые пресеты как на PO --}}
    <div class="grid grid-cols-3 gap-1.5 border-t border-zinc-700/70 pt-3">
        <button type="button" data-dur-preset="3" class="rounded-md bg-zinc-800/80 px-1 py-2 text-center text-xs font-medium text-zinc-300 transition hover:bg-zinc-700 hover:text-white">S3</button>
        <button type="button" data-dur-preset="15" class="rounded-md bg-zinc-800/80 px-1 py-2 text-center text-xs font-medium text-zinc-300 transition hover:bg-zinc-700 hover:text-white">S15</button>
        <button type="button" data-dur-preset="30" class="rounded-md bg-zinc-800/80 px-1 py-2 text-center text-xs font-medium text-zinc-300 transition hover:bg-zinc-700 hover:text-white">S30</button>
        <button type="button" data-dur-preset="60" class="rounded-md bg-zinc-800/80 px-1 py-2 text-center text-xs font-medium text-zinc-300 transition hover:bg-zinc-700 hover:text-white">M1</button>
        <button type="button" data-dur-preset="180" class="rounded-md bg-zinc-800/80 px-1 py-2 text-center text-xs font-medium text-zinc-300 transition hover:bg-zinc-700 hover:text-white">M3</button>
        <button type="button" data-dur-preset="300" class="rounded-md bg-zinc-800/80 px-1 py-2 text-center text-xs font-medium text-zinc-300 transition hover:bg-zinc-700 hover:text-white">M5</button>
        <button type="button" data-dur-preset="1800" class="rounded-md bg-zinc-800/80 px-1 py-2 text-center text-xs font-medium text-zinc-300 transition hover:bg-zinc-700 hover:text-white">M30</button>
        <button type="button" data-dur-preset="3600" class="rounded-md bg-zinc-800/80 px-1 py-2 text-center text-xs font-medium text-zinc-300 transition hover:bg-zinc-700 hover:text-white">H1</button>
        <button type="button" data-dur-preset="14400" class="rounded-md bg-zinc-800/80 px-1 py-2 text-center text-xs font-medium text-zinc-300 transition hover:bg-zinc-700 hover:text-white">H4</button>
    </div>
</div>

@include('partials.chart-markers-helper')
@include('partials.chart-candle-smooth-helper')
@include('partials.chart-fetch-helper')
<script src="https://unpkg.com/lightweight-charts@5.2.0/dist/lightweight-charts.standalone.production.js"></script>
<script>
(function() {
    const CANDLES_URL = '{{ route("candles.data") }}';
    const STRUCTURES_URL = '{{ route("structures.data") }}';
    const PA_INDICATOR_URL = '{{ route("dashboard.indicators.price-action-dgt") }}';
    const LS_INDICATOR_URL = '{{ route("dashboard.indicators.liquidity-swings") }}';
    const SMC_INDICATOR_URL = '{{ route("dashboard.indicators.smc-flow") }}';
    // Классика OsEngine: BB/RSI/SMA/EMA/ATR (params с якорного бота).
    const CLASSIC_INDICATORS_URL = '{{ route("dashboard.indicators.classic") }}';
    const INSTRUMENTS_SIDEBAR_URL = '{{ route("dashboard.instruments.data") }}';
    // Сделки всех ботов пользователя по текущему инструменту → маркеры WIN/LOSE/OPEN.
    const CHART_TRADES_URL = '{{ route("dashboard.chart-trades.data") }}';
    // Лента зеркалимых на PO сделок для правой колонки dashboard.
    const LIVE_TRADES_URL = '{{ route("bots.live-trades.data") }}';
    const LIVE_TRADES_ACTIVITY_TMPL = '{{ route("bots.activity", ["bot" => "__BOT_ID__"]) }}';

    // Окна загрузки разделены на три режима, чтобы переключение между инструментами
    // было быстрым, но при этом сохранялись lazy-догрузка истории и live-обновление.
    //
    // 1) INITIAL — что грузим при первом показе инструмента (selectInstrument / reloadChartData).
    //    Минутные TF: 2 часа (~110 M1-баров). Секундные (S5/S15/S30): окно привязано к viewport
    //    (~130 баров), чтобы не тянуть 1440 S5-свечей на каждый клик.
    // INITIAL — первичный показ: считаем в БАРАХ (не в часах), иначе на M5/M15
    //     2 часа давали ~24/~8 свечей. Берём viewport + буфер = 130 баров на любом TF.
    // MAX — максимальная глубина истории для loadMoreCandles при скролле влево (24ч).
    // POLL — узкое окно auto-refresh хвоста.
    const MAX_WINDOW_SEC = 24 * 3600;
    const POLL_WINDOW_SEC = 5 * 60;

    // Лимиты баров в одном ответе:
    // - INITIAL_BARS / initialLimitForTf — первичная загрузка по числу свечей TF.
    // - LOAD_MORE_LIMIT — ленивая подгрузка старшей истории.
    // - POLL_LIMIT — апдейт хвоста.
    const INITIAL_VISIBLE_BUFFER_BARS = 20;
    const LOAD_MORE_LIMIT = 1440;
    const POLL_LIMIT = 32;

    // Секундные ID (4005/4015/4030) и минутные (1,2,3...) — единая шкала для poll/таймера/PA.
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
    function isSecondTimeframe(tf) {
        return tf === 4005 || tf === 4015 || tf === 4030;
    }

    const M1_COUNTDOWN_SECONDS = 60;
    /**
     * Таймер M1: сервер в каждом ответе /candles отдаёт m1_seconds_to_close (учёт смещения шкалы time свечей vs PHP time).
     * Между опросами убываем локально по performance.now(), без смешивания «часов» на клиенте.
     */
    let m1CloseCountdownAnchorSec = null;
    let m1CloseCountdownAnchorPerfMs = null;
    let tfCloseCountdownAnchorSec = null;
    let tfCloseCountdownAnchorPerfMs = null;
    /** Точность оси цены; синхронизируется с price_scale из /candles (NGN/LBP → 8 знаков). */
    let chartPricePrecision = 5;

    /**
     * Применяем precision/minMove с API к candlestick-серии.
     * Без этого дашборд оставался на hardcoded 5 знаках и микро-пары (NGN/USD OTC) рисовались плоской линией.
     */
    function applyPriceScaleFromPayload(payload) {
        if (!candlestickSeries || !payload || !payload.price_scale) return;
        const precision = Number(payload.price_scale.precision);
        const minMove = Number(payload.price_scale.min_move);
        if (!Number.isFinite(precision) || !Number.isFinite(minMove) || precision <= 0 || minMove <= 0) return;
        chartPricePrecision = precision;
        try {
            candlestickSeries.applyOptions({
                priceFormat: { type: 'price', precision, minMove },
            });
        } catch (e) {
            console.error('[dashboard chart] price scale', e);
        }
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
        if (m1CloseCountdownAnchorSec === null || m1CloseCountdownAnchorPerfMs === null) {
            return null;
        }
        const elapsed = (performance.now() - m1CloseCountdownAnchorPerfMs) / 1000;
        return Math.max(0, Math.min(M1_COUNTDOWN_SECONDS, m1CloseCountdownAnchorSec - elapsed));
    }

    /** Обратный отсчёт до закрытия бара для S5/S15/S30 и прочих не-M1 TF (по server_unix из /candles). */
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
    /**
     * Горизонталь таймера: xCenter = x0 + (x1 - x0) * t, где x0/x1 — якорь последнего бара и слот справа (≈ barSpacing).
     * t = 0 — у x0; t = 1 — на один интервал вправо; t = 1.5 / 2 — ещё дальше (полтора/два интервала). Только нижняя граница 0.
     */
    const CANDLE_TIMER_GAP_T = 3;

    const chartPlaceholder = document.getElementById('chart-placeholder');
    const chartWrapper = document.getElementById('chart-wrapper');
    const structureOverlay = document.getElementById('structure-overlay');
    // PO-лучи открытых сделок (точка входа + горизонталь до экспирации).
    const poOpenTradesOverlay = document.getElementById('po-open-trades-overlay');
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
    const priceActionOverlay = document.getElementById('price-action-overlay');
    const liquiditySwingsOverlay = document.getElementById('liquidity-swings-overlay');
    const smcFlowOverlay = document.getElementById('smc-flow-overlay');
    const classicIndicatorLabelsEl = document.getElementById('classic-indicator-labels');
    const instrumentSearchInput = document.getElementById('instrument-search');
    const instrumentSearchClearBtn = document.getElementById('instrument-search-clear');
    const instrumentListEl = document.getElementById('instrument-list');
    const showTradesToggle = document.getElementById('show-trades-toggle');
    const showTradesLabel = document.getElementById('show-trades-label');

    // Показ сделок на дашборде: localStorage, по умолчанию включено (как раньше).
    const SHOW_TRADES_STORAGE_KEY = 'platform.dashboard.showTrades';
    // Последняя выбранная пара и ТФ — чтобы перезагрузка не сбрасывала график.
    const SELECTED_INSTRUMENT_KEY = 'platform.dashboard.selectedInstrument';
    const SELECTED_TIMEFRAME_KEY = 'platform.dashboard.selectedTimeframe';

    /**
     * Читает предпочтение «показывать торговлю» из localStorage.
     * false / '0' / 'false' / 'off' → выкл; иначе вкл.
     */
    function loadShowTradesPref() {
        try {
            const v = localStorage.getItem(SHOW_TRADES_STORAGE_KEY);
            if (v == null) return true;
            return !(v === '0' || v === 'false' || v === 'off');
        } catch (_) {
            return true;
        }
    }

    function saveShowTradesPref(on) {
        try {
            localStorage.setItem(SHOW_TRADES_STORAGE_KEY, on ? '1' : '0');
        } catch (_) { /* ignore quota / private mode */ }
    }

    /** Сохраняет выбранный инструмент (символ пары). */
    function saveSelectedInstrument(instrument) {
        try {
            if (!instrument) return;
            localStorage.setItem(SELECTED_INSTRUMENT_KEY, String(instrument));
        } catch (_) { /* ignore */ }
    }

    /** @returns {string|null} */
    function loadSelectedInstrument() {
        try {
            const v = localStorage.getItem(SELECTED_INSTRUMENT_KEY);
            return v && String(v).trim() !== '' ? String(v).trim() : null;
        } catch (_) {
            return null;
        }
    }

    /**
     * Сохраняет таймфрейм (минуты или код секундного: 4005/4015/4030).
     * @param {number} tf
     */
    function saveSelectedTimeframe(tf) {
        try {
            if (!Number.isFinite(tf)) return;
            localStorage.setItem(SELECTED_TIMEFRAME_KEY, String(tf));
        } catch (_) { /* ignore */ }
    }

    /**
     * Читает сохранённый ТФ, только если такая кнопка есть в UI.
     * @returns {number|null}
     */
    function loadSelectedTimeframe() {
        try {
            const raw = localStorage.getItem(SELECTED_TIMEFRAME_KEY);
            if (raw == null || raw === '') return null;
            const tf = parseInt(raw, 10);
            if (!Number.isFinite(tf)) return null;
            const opt = document.querySelector('.tf-option[data-minutes="' + tf + '"]');
            return opt ? tf : null;
        } catch (_) {
            return null;
        }
    }

    /** Текущий флаг: рисовать ли сделки (маркеры + сайдбар). */
    let showTrades = loadShowTradesPref();

    /** Синхронизирует чекбокс/подпись с флагом showTrades. */
    function syncShowTradesToggleUi() {
        if (showTradesToggle) showTradesToggle.checked = showTrades;
        if (showTradesLabel) {
            showTradesLabel.textContent = 'Торговля';
            showTradesLabel.classList.toggle('text-zinc-400', showTrades);
            showTradesLabel.classList.toggle('text-zinc-500', !showTrades);
        }
    }

    /**
     * Убирает из сайдбара визуал открытых сделок (подсветка + таймер),
     * не трогая выбранный инструмент (синий bg-blue-900/40).
     */
    function hideSidebarTradeChrome() {
        document.querySelectorAll('.instrument-btn').forEach((btn) => {
            btn.classList.remove('bg-stone-800/45');
            btn.querySelectorAll('.instrument-trade-timer').forEach((el) => el.remove());
        });
    }

    /**
     * Вкл/выкл показа торговли: маркеры на графике + подсветка/таймеры в списке слева.
     * При выключении — сразу чистый вид; при включении — перезагрузка сайдбара и сделок.
     */
    function applyShowTradesMode(on, { persist = true } = {}) {
        showTrades = !!on;
        if (persist) saveShowTradesPref(showTrades);
        syncShowTradesToggleUi();

        if (!showTrades) {
            // Чистый график: не держим кэш сделок и не рисуем WIN/LOSE/OPEN.
            lastChartSignals = [];
            lastOpenTrades = [];
            // Инвалидируем late-ответы fetchChartTrades через общий helper.
            if (window.PlatformChartFetch) window.PlatformChartFetch.invalidate('chart-trades');
            renderChartMarkers();
            hideSidebarTradeChrome();
            return;
        }

        // Снова показываем: обновляем список (таймеры/подсветка) и маркеры по инструменту.
        refreshInstrumentSidebar();
        fetchChartTrades();
    }

    // v4: classic OsEngine (bollinger/rsi/sma/ema/atr). При отсутствии ключа тянем v3 без потери старых prefs.
    const LAYER_PREFS_KEY = 'platform.dashboard.chartLayers.v5';

    function loadLayerPrefs() {
        try {
            let raw = localStorage.getItem(LAYER_PREFS_KEY);
            if (raw == null) {
                raw = localStorage.getItem('platform.dashboard.chartLayers.v4');
            }
            if (raw == null) {
                raw = localStorage.getItem('platform.dashboard.chartLayers.v3');
            }
            if (raw == null) {
                raw = localStorage.getItem('platform.dashboard.chartLayers.v2');
            }
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
            if (layerLiquidity && typeof o.liquiditySwings === 'boolean') layerLiquidity.checked = o.liquiditySwings;
            if (layerSmc && typeof o.smcFlow === 'boolean') layerSmc.checked = o.smcFlow;
            if (layerBollinger && typeof o.bollinger === 'boolean') layerBollinger.checked = o.bollinger;
            if (layerRsi && typeof o.rsi === 'boolean') layerRsi.checked = o.rsi;
            if (layerSma && typeof o.sma === 'boolean') layerSma.checked = o.sma;
            if (layerEma && typeof o.ema === 'boolean') layerEma.checked = o.ema;
            if (layerVwma && typeof o.vwma === 'boolean') layerVwma.checked = o.vwma;
            if (layerAtr && typeof o.atr === 'boolean') layerAtr.checked = o.atr;
            if (layerMacd && typeof o.macd === 'boolean') layerMacd.checked = o.macd;
            if (layerStochastic && typeof o.stochastic === 'boolean') layerStochastic.checked = o.stochastic;
            if (layerCci && typeof o.cci === 'boolean') layerCci.checked = o.cci;
            if (layerKeltner && typeof o.keltner === 'boolean') layerKeltner.checked = o.keltner;
            if (layerEnvelops && typeof o.envelops === 'boolean') layerEnvelops.checked = o.envelops;
            if (layerParabolicSar && typeof o.parabolicSar === 'boolean') layerParabolicSar.checked = o.parabolicSar;
            if (layerSuperTrend && typeof o.supertrend === 'boolean') layerSuperTrend.checked = o.supertrend;
            if (layerAdx && typeof o.adx === 'boolean') layerAdx.checked = o.adx;
            if (layerWilliams && typeof o.williams === 'boolean') layerWilliams.checked = o.williams;
            if (layerAo && typeof o.ao === 'boolean') layerAo.checked = o.ao;
            if (layerAlligator && typeof o.alligator === 'boolean') layerAlligator.checked = o.alligator;
            if (layerIchimoku && typeof o.ichimoku === 'boolean') layerIchimoku.checked = o.ichimoku;
            if (layerPriceChannel && typeof o.price_channel === 'boolean') layerPriceChannel.checked = o.price_channel;
            if (layerNrtr && typeof o.nrtr === 'boolean') layerNrtr.checked = o.nrtr;
            if (layerHma && typeof o.hma === 'boolean') layerHma.checked = o.hma;
            if (layerAroon && typeof o.aroon === 'boolean') layerAroon.checked = o.aroon;
            if (layerMomentum && typeof o.momentum === 'boolean') layerMomentum.checked = o.momentum;
            if (layerRoc && typeof o.roc === 'boolean') layerRoc.checked = o.roc;
            if (layerOsma && typeof o.osma === 'boolean') layerOsma.checked = o.osma;
            if (layerTrix && typeof o.trix === 'boolean') layerTrix.checked = o.trix;
            if (layerCmo && typeof o.cmo === 'boolean') layerCmo.checked = o.cmo;
            if (layerDemarker && typeof o.demarker === 'boolean') layerDemarker.checked = o.demarker;
            if (layerRvi && typeof o.rvi === 'boolean') layerRvi.checked = o.rvi;
            if (layerUltimate && typeof o.ultimate === 'boolean') layerUltimate.checked = o.ultimate;
            if (layerSmi && typeof o.smi === 'boolean') layerSmi.checked = o.smi;
            if (layerStdDev && typeof o.stddev === 'boolean') layerStdDev.checked = o.stddev;
            if (layerSmaChannel && typeof o.smaChannel === 'boolean') layerSmaChannel.checked = o.smaChannel;
            if (layerIvashov && typeof o.ivashov === 'boolean') layerIvashov.checked = o.ivashov;
            if (layerLinReg && typeof o.linreg === 'boolean') layerLinReg.checked = o.linreg;
            // Совместимость: раньше ключ был camelCase linregChannel, теперь snake_case как в API.
            if (layerLinRegChannel) {
                if (typeof o.linreg_channel === 'boolean') layerLinRegChannel.checked = o.linreg_channel;
                else if (typeof o.linregChannel === 'boolean') layerLinRegChannel.checked = o.linregChannel;
            }
            if (layerKalman && typeof o.kalman === 'boolean') layerKalman.checked = o.kalman;
            if (layerFractal && typeof o.fractal === 'boolean') layerFractal.checked = o.fractal;
            if (layerDonchian && typeof o.donchian === 'boolean') layerDonchian.checked = o.donchian;
            if (layerStochRsi && typeof o.stoch_rsi === 'boolean') layerStochRsi.checked = o.stoch_rsi;
            if (layerAc && typeof o.ac === 'boolean') layerAc.checked = o.ac;
            if (layerBullsPower && typeof o.bulls_power === 'boolean') layerBullsPower.checked = o.bulls_power;
            if (layerBearsPower && typeof o.bears_power === 'boolean') layerBearsPower.checked = o.bears_power;
            if (layerDpo && typeof o.dpo === 'boolean') layerDpo.checked = o.dpo;
            if (layerQstick && typeof o.qstick === 'boolean') layerQstick.checked = o.qstick;
            if (layerVhFilter && typeof o.vh_filter === 'boolean') layerVhFilter.checked = o.vh_filter;
            if (layerEfficiencyRatio && typeof o.efficiency_ratio === 'boolean') layerEfficiencyRatio.checked = o.efficiency_ratio;
            if (layerRavi && typeof o.ravi === 'boolean') layerRavi.checked = o.ravi;
            if (layerCog && typeof o.cog === 'boolean') layerCog.checked = o.cog;
            if (layerPivotFloor && typeof o.pivot_floor === 'boolean') layerPivotFloor.checked = o.pivot_floor;
            if (layerMassIndex && typeof o.mass_index === 'boolean') layerMassIndex.checked = o.mass_index;
            if (layerNrtrWatr && typeof o.nrtr_watr === 'boolean') layerNrtrWatr.checked = o.nrtr_watr;
            if (layerPriceChannelAdaptive && typeof o.price_channel_adaptive === 'boolean') layerPriceChannelAdaptive.checked = o.price_channel_adaptive;
            if (layerPriceChannelOffset && typeof o.price_channel_offset === 'boolean') layerPriceChannelOffset.checked = o.price_channel_offset;
            if (layerParabolicBollinger && typeof o.parabolic_bollinger === 'boolean') layerParabolicBollinger.checked = o.parabolic_bollinger;
            if (layerParabolicPriceChannel && typeof o.parabolic_price_channel === 'boolean') layerParabolicPriceChannel.checked = o.parabolic_price_channel;
            if (layerAtrChannel && typeof o.atr_channel === 'boolean') layerAtrChannel.checked = o.atr_channel;
            if (layerOffsetSma && typeof o.offset_sma === 'boolean') layerOffsetSma.checked = o.offset_sma;
            if (layerOffsetEma && typeof o.offset_ema === 'boolean') layerOffsetEma.checked = o.offset_ema;
            if (layerOffsetSsma && typeof o.offset_ssma === 'boolean') layerOffsetSsma.checked = o.offset_ssma;
            if (layerVolatilityAverage && typeof o.volatility_average === 'boolean') layerVolatilityAverage.checked = o.volatility_average;
            if (layerVolatilityAverageChannel && typeof o.volatility_average_channel === 'boolean') layerVolatilityAverageChannel.checked = o.volatility_average_channel;
            if (layerPriceOscillator && typeof o.price_oscillator === 'boolean') layerPriceOscillator.checked = o.price_oscillator;
            if (layerDynamicTrendDetector && typeof o.dynamic_trend_detector === 'boolean') layerDynamicTrendDetector.checked = o.dynamic_trend_detector;
            if (layerAdaptiveLookBack && typeof o.adaptive_look_back === 'boolean') layerAdaptiveLookBack.checked = o.adaptive_look_back;
            if (layerLinRegChannelFast && typeof o.linreg_channel_fast === 'boolean') layerLinRegChannelFast.checked = o.linreg_channel_fast;
            if (layerVolatilityCandles && typeof o.volatility_candles === 'boolean') layerVolatilityCandles.checked = o.volatility_candles;
            if (layerZigZag && typeof o.zigzag === 'boolean') layerZigZag.checked = o.zigzag;
            if (layerVolatilityAverageTwice && typeof o.volatility_average_twice === 'boolean') layerVolatilityAverageTwice.checked = o.volatility_average_twice;
            if (layerVolatilityStagesAw && typeof o.volatility_stages_aw === 'boolean') layerVolatilityStagesAw.checked = o.volatility_stages_aw;
        } catch (_) { /* ignore */ }
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
            }));
        } catch (_) { /* ignore */ }
    }

    loadLayerPrefs();

    let chart = null;
    let candlestickSeries = null;
    /**
     * Единый markers-plugin v5 (как на странице бота).
     * Сейчас dashboard не рисует торговые маркеры, но держим ту же точку расширения:
     * при добавлении markers достаточно вызвать PlatformChartMarkers.applyMarkers(...).
     */
    let candlestickMarkers = null;
    /**
     * Плавный close последней свечи (PlatformCandleSmooth): target из poll,
     * визуал — RAF lerp, allChartData всегда хранит серверные OHLC.
     */
    let candleSmooth = null;
    let currentInstrument = null;
    let currentDisplayName = '';
    let currentTimeframeMinutes = 1;
    let oldestLoadedTime = null;
    let allChartData = [];
    let loadingMore = false;
    let refreshInterval = null;
    // Отдельный таймер для тяжёлых оверлеев (структуры и Price Action DGT).
    // Они меняются редко, но каждый запрос /structures и /indicators/price-action-dgt
    // заметно нагружает сервер (последний при дефолтных параметрах тянет до 5000 свечей
    // и ~46 KB ответа). Если опрашивать их в общем 500-мс цикле, fetch-pool браузера
    // быстро переполняется (net::ERR_INSUFFICIENT_RESOURCES), запросы /candles стоят
    // в очереди и при переключении инструмента график «висит» на «Загрузка…», даже
    // если бэкенд готов отдать данные мгновенно.
    let overlaysRefreshInterval = null;
    // Базовый шаг обновления оверлеев — 15 сек. Зоны/индикатор за такое время
    // успевают сменить статус, а нагрузка на БД и сеть кратно ниже.
    const OVERLAYS_REFRESH_MS = 15000;
    let instrumentListRefreshInterval = null;
    let sidebarTimerInterval = null;
    let lastZones = [];
    let lastPaPayload = null;
    /** JSON с сервера: boxes / lines / labels для Liquidity Swings. */
    let lastLsPayload = null;
    let lastSmcPayload = null;
    /** Сигналы/сделки для маркеров (формат как на bots/show). */
    let lastChartSignals = [];
    let lastOpenTrades = [];
    /** Payloads классики OsEngine (BB/RSI/SMA/EMA/VWMA/ATR). */
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
    let classicLineSeries = {};
    let rsiLevelLines = [];
    let classicOscPaneCount = 0;
    /** overlays — основная pane; oscillators — в порядке addPane (pane 1..n). */
    let lastClassicNameState = { overlays: [], oscillators: [] };

    /**
     * Названия structural-слоёв (OB/FVG/PA/…) при включённом чекбоксе.
     * @returns {Array<{name:string,color:string,layerId:string,settingsUrl:?string}>}
     */
    function structureLayerNameLabels() {
        const out = [];
        const add = (layerId, fallbackName) => {
            const cb = document.getElementById(layerId);
            if (!cb || !cb.checked) return;
            const meta = (window.PLATFORM_INDICATOR_LAYER_META && window.PLATFORM_INDICATOR_LAYER_META[layerId]) || {};
            out.push({
                name: meta.label || fallbackName,
                color: '#f4f4f5',
                layerId,
                settingsUrl: meta.settingsUrl || null,
            });
        };
        add('layer-ob', 'Ордерблоки');
        add('layer-fvg', 'Имбалансы');
        add('layer-pa-dgt', 'Price Action (DGT)');
        add('layer-liquidity-swings', 'Liquidity Swings');
        add('layer-smc-flow', 'SMC Flow');
        return out;
    }

    /**
     * Лейбл классики: title из API («RSI 14») + слой для иконок карандаш/×.
     * @returns {{name:string,color:string,layerId:string,settingsUrl:?string}|null}
     */
    function classicIndicatorLabel(on, payload, fallbackName, layerId) {
        if (!on) return null;
        const meta = (window.PLATFORM_INDICATOR_LAYER_META && window.PLATFORM_INDICATOR_LAYER_META[layerId]) || {};
        const title = payload && payload.title ? String(payload.title) : fallbackName;
        return {
            name: title,
            color: '#f4f4f5',
            layerId: layerId || null,
            settingsUrl: meta.settingsUrl || null,
        };
    }

    /** DOM-бейджи названий индикаторов (structural + classic). */
    function renderAllIndicatorNameLabels() {
        if (!window.PlatformChartMarkers
            || typeof window.PlatformChartMarkers.renderIndicatorNameLabels !== 'function') {
            return;
        }
        window.PlatformChartMarkers.renderIndicatorNameLabels({
            chart,
            container: classicIndicatorLabelsEl,
            mainLabels: [
                ...structureLayerNameLabels(),
                ...(lastClassicNameState.overlays || []),
            ],
            oscLabels: lastClassicNameState.oscillators || [],
        });
    }

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
        const lastT = Number(allChartData[allChartData.length - 1].time);
        if (!Number.isFinite(lastT)) {
            if (candleTimerOverlay) candleTimerOverlay.classList.add('hidden');
            candleCloseTimerEl.textContent = '';
            return;
        }
        const left = currentTimeframeMinutes === 1
            ? m1CountdownLeftNow()
            : genericCountdownLeftNow(currentTimeframeMinutes);
        if (left === null) {
            if (candleTimerOverlay) candleTimerOverlay.classList.add('hidden');
            candleCloseTimerEl.textContent = '';
            return;
        }
        if (candleTfLabelEl) {
            candleTfLabelEl.textContent = timeframeLabel(currentTimeframeMinutes);
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
            // Подсветку открытой сделки показываем только если включён режим «Торговля».
            const showOpenTrade = !!(showTrades && inst.open_trade);
            btn.className = 'instrument-btn w-full text-left px-4 py-3 hover:bg-zinc-800 focus:bg-zinc-800 focus:outline-none transition-colors' + (showOpenTrade ? ' bg-stone-800/45' : '');
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
            if (showOpenTrade && inst.expiry_ts) {
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
        if (!window.PlatformChartFetch) {
            return fetch(INSTRUMENTS_SIDEBAR_URL, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                .then((r) => r.json())
                .then((d) => {
                    renderInstrumentSidebarRows(d.instruments || []);
                })
                .catch(() => {});
        }
        // coalesce: несколько триггеров (toggle «Торговля», таймер списка) не дублируют GET.
        return PlatformChartFetch.requestJson('instruments-sidebar', {
            key: 'sidebar',
            url: INSTRUMENTS_SIDEBAR_URL,
            apply: (d) => {
                renderInstrumentSidebarRows(d.instruments || []);
                if (instrumentSearchInput) {
                    applyInstrumentFilter(instrumentSearchInput.value);
                }
                syncSearchClearButton();
                if (currentInstrument) {
                    document.querySelectorAll('.instrument-btn').forEach((b) => b.classList.toggle('bg-blue-900/40', b.dataset.instrument === currentInstrument));
                }
                // Обновляем блок «Выплата» в правой панели после refresh payout.
                if (typeof updateManualTradePayout === 'function') updateManualTradePayout();
            },
        }) || Promise.resolve();
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

    /**
     * Нижняя граница «потолка» доступной истории графика.
     * Используется в loadMoreCandles как стоп для ленивой подгрузки влево
     * (`oldestLoadedTime <= candlesSince()` => дальше не идём).
     * Это НЕ окно первичной загрузки: первичный показ = INITIAL_BARS свечей TF.
     */
    function candlesSince() {
        return Math.floor(Date.now() / 1000) - MAX_WINDOW_SEC;
    }

    /** ~кол-во свечей по горизонтали и «воздух» справа (в долях бара), как на референсе */
    const CHART_DEFAULT_VISIBLE_BARS = 110;
    const CHART_RIGHT_GAP_BARS = 22;
    /** Первичный показ: сколько баров TF тянем (viewport + буфер слева). */
    const INITIAL_BARS = CHART_DEFAULT_VISIBLE_BARS + INITIAL_VISIBLE_BUFFER_BARS;

    /**
     * Окно since для initial: INITIAL_BARS × длительность бара.
     * Раньше для минутных был фиксированный 2ч → M5≈24 / M15≈8 свечей.
     */
    function initialWindowSecForTf(tf) {
        return timeframeSeconds(tf) * INITIAL_BARS;
    }

    function initialLimitForTf(tf) {
        return INITIAL_BARS + 10;
    }

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

    /**
     * DOM-зоны рисуются поверх canvas LW Charts: если time_end = текущий бар,
     * полупрозрачный прямоугольник перекрывает формирующуюся свечу.
     * Обрезаем правый край до левой границы колонки последнего бара.
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

        lastZones.forEach(z => {
            const isOb = z.type.startsWith('order_block');
            const isFvg = z.type.startsWith('imbalance');
            if (isOb && !showOb) return;
            if (isFvg && !showFvg) return;

            if (lastBarTime < z.time_start) return;
            const tEnd = z.time_end ?? lastBarTime;

            const x1 = chart.timeScale().timeToCoordinate(z.time_start);
            const x2 = zoneEndXClipped(chart.timeScale(), tEnd, allChartData);
            if (x1 == null || x2 == null) return;
            if (Math.abs(x2 - x1) < 1) return;

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
            div.style.background = z.fill || paint.background;
            div.style.border = z.border || paint.border;
            appendOrderBlockLabel(div, z);
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

    /** Отрезок оверлея между двумя координатами (ломаная UT-трейла SMC Flow). */
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

    function fetchPriceActionIndicator() {
        if (!currentInstrument || !layerPa || !layerPa.checked) {
            lastPaPayload = null;
            if (window.PlatformChartFetch) window.PlatformChartFetch.invalidate('pa');
            redrawPriceActionOverlay();
            applyPaBarColors({ fullResync: true });
            return;
        }
        const params = new URLSearchParams({ instrument: currentInstrument, timeframe: String(currentTimeframeMinutes) });
        const key = currentInstrument + '|' + String(currentTimeframeMinutes);
        if (!window.PlatformChartFetch) return;
        // coalesce: повторный тик 15с с тем же key не стартует второй запрос.
        PlatformChartFetch.requestJson('pa', {
            key: key,
            url: PA_INDICATOR_URL + '?' + params.toString(),
            apply: (d) => {
                try {
                    lastPaPayload = d && d.indicator ? d.indicator : null;
                    redrawPriceActionOverlay();
                    applyPaBarColors({ fullResync: true });
                    // PA отдаёт собственные markers (v_spike/atr) — пересобираем серию маркеров.
                    renderChartMarkers();
                } catch (e) {
                    console.error('[PriceAction DGT] dashboard apply', e);
                    lastPaPayload = null;
                    try {
                        redrawPriceActionOverlay();
                        applyPaBarColors({ fullResync: true });
                        renderChartMarkers();
                    } catch (_) {}
                }
            },
        });
    }

    /**
     * Тянем сделки пользователя по текущему инструменту и рисуем маркеры
     * тем же helper, что и на странице бота (ВВЕРХ/ВНИЗ/WIN/LOSE/OPEN).
     * Если переключатель «Торговля» выключен — не ходим на API и чистим маркеры.
     */
    function fetchChartTrades() {
        if (!showTrades) {
            lastChartSignals = [];
            lastOpenTrades = [];
            if (window.PlatformChartFetch) window.PlatformChartFetch.invalidate('chart-trades');
            renderChartMarkers();
            return;
        }
        if (!currentInstrument) {
            lastChartSignals = [];
            lastOpenTrades = [];
            if (window.PlatformChartFetch) window.PlatformChartFetch.invalidate('chart-trades');
            renderChartMarkers();
            return;
        }
        if (!window.PlatformChartFetch) return;
        const params = new URLSearchParams({ instrument: currentInstrument });
        PlatformChartFetch.requestJson('chart-trades', {
            key: currentInstrument,
            url: CHART_TRADES_URL + '?' + params.toString(),
            apply: (d) => {
                if (!showTrades) return;
                lastChartSignals = Array.isArray(d && d.signals) ? d.signals : [];
                lastOpenTrades = Array.isArray(d && d.open_trades) ? d.open_trades : [];
                renderChartMarkers();
            },
            onError: () => {
                // При ошибке сети не стираем уже показанные маркеры.
            },
        });
    }

    /**
     * Собирает и применяет маркеры: история сделок + открытые + PA (если слой включён).
     * Через PlatformChartMarkers — тот же код, что на bots/show.
     * При выключенной «Торговле» оставляем только маркеры индикаторов (например PA).
     */
    function renderChartMarkers() {
        if (!candlestickSeries || !currentInstrument) return;
        const PCM = window.PlatformChartMarkers;
        if (!PCM) return;

        // Сделки на графике только если пользователь не попросил «чистый» режим.
        const history = (showTrades && typeof PCM.buildHistoryMarkers === 'function')
            ? PCM.buildHistoryMarkers(lastChartSignals, { instrument: currentInstrument })
            : [];
        const open = (showTrades && typeof PCM.buildOpenTradeMarkers === 'function')
            ? PCM.buildOpenTradeMarkers(lastOpenTrades, {
                instrument: currentInstrument,
                nowSec: Math.floor(Date.now() / 1000),
            })
            : [];
        const paMk = (layerPa && layerPa.checked && lastPaPayload && Array.isArray(lastPaPayload.markers))
            ? lastPaPayload.markers
            : [];
        const all = typeof PCM.mergeMarkers === 'function'
            ? PCM.mergeMarkers(history, open, paMk)
            : [...history, ...open, ...paMk].sort((a, b) => a.time - b.time);

        if (typeof PCM.applyMarkers === 'function') {
            PCM.applyMarkers(candlestickMarkers, all);
        }
        // Параллельно маркерам — горизонтальные лучи как на Pocket Option.
        renderPoOpenTradeRaysOverlay();
    }

    /**
     * Рисует/прячет PO-лучи открытых сделок на DOM-оверлее графика.
     * Пересчитывается при pan/zoom, смене ТФ и тике таймера раз в секунду.
     */
    function renderPoOpenTradeRaysOverlay() {
        const PCM = window.PlatformChartMarkers;
        if (!PCM || typeof PCM.renderPoOpenTradeRays !== 'function') return;
        PCM.renderPoOpenTradeRays({
            overlayEl: poOpenTradesOverlay,
            chart,
            series: candlestickSeries,
            candleData: allChartData,
            trades: lastOpenTrades,
            instrument: currentInstrument,
            barSec: timeframeSeconds(currentTimeframeMinutes),
            nowSec: Math.floor(Date.now() / 1000),
            show: !!showTrades,
        });
    }

    /** Включён ли хотя бы один classic-слой OsEngine. */
    function anyClassicLayerOn() {
        return !!(
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

    /**
     * Удаляет line-series классики и лишние pane осцилляторов (LWC v5).
     * Логика 1:1 с bots/show — иначе RSI останется поверх свечей.
     */
    function clearClassicOsEngineSeries() {
        if (!chart) {
            classicLineSeries = {};
            rsiLevelLines = [];
            classicOscPaneCount = 0;
            lastClassicNameState = { overlays: [], oscillators: [] };
            renderAllIndicatorNameLabels();
            return;
        }
        Object.keys(classicLineSeries).forEach((k) => {
            try { chart.removeSeries(classicLineSeries[k]); } catch (_) {}
        });
        classicLineSeries = {};
        rsiLevelLines = [];
        try {
            while (typeof chart.panes === 'function' && chart.panes().length > 1) {
                chart.removePane(chart.panes().length - 1);
            }
        } catch (_) {}
        classicOscPaneCount = 0;
        lastClassicNameState = { overlays: [], oscillators: [] };
        try {
            chart.priceScale('right').applyOptions({
                scaleMargins: { top: 0.08, bottom: 0.05 },
            });
        } catch (_) {}
        renderAllIndicatorNameLabels();
    }

    /** Рисует BB/SMA/EMA/VWMA на pane 0; RSI и ATR — в отдельных addPane(). */
    function applyClassicOsEngineSeries() {
        if (!chart || !candlestickSeries) return;
        clearClassicOsEngineSeries();

        const LineSeries = LightweightCharts.LineSeries;
        const HistogramSeries = LightweightCharts.HistogramSeries;
        if (!LineSeries || typeof chart.addSeries !== 'function') {
            console.error('[dashboard indicators] LWC v5 addSeries/LineSeries недоступны');
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
        if (wantDonchian) {
            const st = lastDonchianPayload.style || {};
            const ser = lastDonchianPayload.series || {};
            const w = st.line_width || 1;
            addMainLine('donchian_up', ser.up, st.color_up, w);
            addMainLine('donchian_mid', ser.mid, st.color_mid, w);
            addMainLine('donchian_down', ser.down, st.color_down, w);
        }
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
            try {
                const mainPane = chart.panes()[0];
                if (mainPane && typeof mainPane.setStretchFactor === 'function') {
                    mainPane.setStretchFactor(1);
                }
            } catch (_) {}
        }

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
                console.error('[dashboard indicators] addPane RSI', e);
            }
            const s = chart.addSeries(LineSeries, {
                color: st.color || '#1e90ff',
                lineWidth: st.line_width || 1,
                priceLineVisible: false,
                baseLineVisible: false,
                // Показываем текущее значение RSI справа, чтобы видеть актуальный уровень.
                lastValueVisible: true,
                crosshairMarkerVisible: false,
                // RSI — осциллятор в шкале 0..100, отображаем целые значения.
                priceFormat: { type: 'price', precision: 0, minMove: 1 },
                // Жёсткий диапазон нужен, чтобы уровни 70/30 всегда были видимыми.
                autoscaleInfoProvider: () => ({ priceRange: { minValue: 0, maxValue: 100 } }),
            }, paneIndex);
            const data = ((lastRsiPayload.series || {}).rsi) || [];
            s.setData(data.map((p) => ({ time: p.time, value: p.value })));
            classicLineSeries['rsi'] = s;
            // Небольшие внешние отступы для более аккуратного расположения линии RSI.
            try {
                s.priceScale().applyOptions({
                    scaleMargins: { top: 0.08, bottom: 0.08 },
                    borderVisible: false,
                    entireTextOnly: true,
                });
            } catch (_) {}

            // Накладываем однотонный фон именно на RSI-pane, чтобы визуально погасить сетку.
            try {
                const paneApi = rsiPane || (typeof chart.panes === 'function' ? chart.panes()[paneIndex] : null);
                if (paneApi && typeof paneApi.attachPrimitive === 'function'
                    && window.PlatformChartMarkers
                    && typeof window.PlatformChartMarkers.createPaneSolidBackdrop === 'function') {
                    paneApi.attachPrimitive(window.PlatformChartMarkers.createPaneSolidBackdrop('#0e1018'));
                }
            } catch (e) {
                console.error('[dashboard indicators] RSI pane backdrop', e);
            }

            // Подписи 70/30 на правой шкале для быстрого чтения перекупленности/перепроданности.
            try {
                const lines = (window.PlatformChartMarkers
                    && typeof window.PlatformChartMarkers.attachRsiLevelLines === 'function')
                    ? window.PlatformChartMarkers.attachRsiLevelLines(s, levels)
                    : [];
                rsiLevelLines.push(...lines);
            } catch (_) {}
        }

        // MACD pane: histogram + lines + нулевая линия (как на bots/show).
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
                console.error('[dashboard indicators] addPane MACD', e);
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
                    classicLineSeries['macd_hist'] = hs;
                } catch (e) {
                    console.error('[dashboard indicators] MACD histogram', e);
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

        // Stochastic pane 0..100 (%K/%D + уровни 80/20).
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
                console.error('[dashboard indicators] addPane Stochastic', e);
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
                console.error('[dashboard indicators] Stochastic pane backdrop', e);
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
                console.error('[dashboard indicators] addPane CCI', e);
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
                console.error('[dashboard indicators] CCI pane backdrop', e);
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

        if (wantWilliams) {
            const st = lastWilliamsPayload.style || {};
            const levels = lastWilliamsPayload.levels || {};
            const ser = lastWilliamsPayload.series || {};
            let paneIndex = 1;
            let wrPane = null;
            try {
                wrPane = chart.addPane(false);
                if (wrPane && typeof wrPane.setStretchFactor === 'function') wrPane.setStretchFactor(0.28);
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) { console.error('[dashboard] addPane Williams', e); }
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
                if (wrLine && levels.show && window.PlatformChartMarkers?.attachRsiLevelLines) {
                    rsiLevelLines.push(...window.PlatformChartMarkers.attachRsiLevelLines(wrLine, levels));
                }
            } catch (_) {}
        }

        if (wantAo) {
            const st = lastAoPayload.style || {};
            const ser = lastAoPayload.series || {};
            let paneIndex = 1;
            try {
                const pane = chart.addPane(false);
                if (pane?.setStretchFactor) pane.setStretchFactor(0.32);
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) { console.error('[dashboard] addPane AO', e); }
            if (HistogramSeries && Array.isArray(ser.ao) && ser.ao.length) {
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
                    color: Number(p.value) >= 0 ? (st.color_histogram || '#006400') : (st.color_histogram_neg || '#f43f5e'),
                })));
                classicLineSeries['ao_hist'] = hs;
            }
        }

        if (wantAroon) {
            const st = lastAroonPayload.style || {};
            const levels = lastAroonPayload.levels || {};
            const ser = lastAroonPayload.series || {};
            const lineW = st.line_width || 1;
            let paneIndex = 1;
            try {
                const pane = chart.addPane(false);
                if (pane?.setStretchFactor) pane.setStretchFactor(0.28);
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) { console.error('[dashboard] addPane Aroon', e); }
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
                const anchor = upLine || classicLineSeries['aroon_down'];
                if (anchor && levels.show && window.PlatformChartMarkers?.attachRsiLevelLines) {
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
                console.error('[dashboard indicators] addPane Momentum', e);
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
                if (paneApi?.attachPrimitive && window.PlatformChartMarkers?.createPaneSolidBackdrop) {
                    paneApi.attachPrimitive(window.PlatformChartMarkers.createPaneSolidBackdrop('#0e1018'));
                }
            } catch (e) {
                console.error('[dashboard indicators] Momentum pane backdrop', e);
            }
            try {
                if (momentumLine && levels.show && window.PlatformChartMarkers?.attachRsiLevelLines) {
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
                console.error('[dashboard indicators] addPane ROC', e);
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
                if (paneApi?.attachPrimitive && window.PlatformChartMarkers?.createPaneSolidBackdrop) {
                    paneApi.attachPrimitive(window.PlatformChartMarkers.createPaneSolidBackdrop('#0e1018'));
                }
            } catch (e) {
                console.error('[dashboard indicators] ROC pane backdrop', e);
            }
            try {
                if (rocLine && levels.show && window.PlatformChartMarkers?.attachRsiLevelLines) {
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
                console.error('[dashboard indicators] addPane OsMA', e);
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
                    console.error('[dashboard indicators] OsMA histogram', e);
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
                console.error('[dashboard indicators] addPane TRIX', e);
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
                if (paneApi?.attachPrimitive && window.PlatformChartMarkers?.createPaneSolidBackdrop) {
                    paneApi.attachPrimitive(window.PlatformChartMarkers.createPaneSolidBackdrop('#0e1018'));
                }
            } catch (e) {
                console.error('[dashboard indicators] TRIX pane backdrop', e);
            }
            try {
                if (trixLine && levels.show && window.PlatformChartMarkers?.attachRsiLevelLines) {
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
                console.error('[dashboard indicators] addPane CMO', e);
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
                if (paneApi?.attachPrimitive && window.PlatformChartMarkers?.createPaneSolidBackdrop) {
                    paneApi.attachPrimitive(window.PlatformChartMarkers.createPaneSolidBackdrop('#0e1018'));
                }
            } catch (e) {
                console.error('[dashboard indicators] CMO pane backdrop', e);
            }
            try {
                if (cmoLine && levels.show && window.PlatformChartMarkers?.attachRsiLevelLines) {
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
                console.error('[dashboard indicators] addPane DeMarker', e);
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
                if (paneApi?.attachPrimitive && window.PlatformChartMarkers?.createPaneSolidBackdrop) {
                    paneApi.attachPrimitive(window.PlatformChartMarkers.createPaneSolidBackdrop('#0e1018'));
                }
            } catch (e) {
                console.error('[dashboard indicators] DeMarker pane backdrop', e);
            }
            try {
                if (demLine && levels.show && window.PlatformChartMarkers?.attachRsiLevelLines) {
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
                console.error('[dashboard indicators] addPane RVI', e);
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
                if (paneApi?.attachPrimitive && window.PlatformChartMarkers?.createPaneSolidBackdrop) {
                    paneApi.attachPrimitive(window.PlatformChartMarkers.createPaneSolidBackdrop('#0e1018'));
                }
            } catch (e) {
                console.error('[dashboard indicators] RVI pane backdrop', e);
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
                console.error('[dashboard indicators] addPane Ultimate', e);
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
                if (paneApi?.attachPrimitive && window.PlatformChartMarkers?.createPaneSolidBackdrop) {
                    paneApi.attachPrimitive(window.PlatformChartMarkers.createPaneSolidBackdrop('#0e1018'));
                }
            } catch (e) {
                console.error('[dashboard indicators] Ultimate pane backdrop', e);
            }
            try {
                if (uoLine && levels.show && window.PlatformChartMarkers?.attachRsiLevelLines) {
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
                console.error('[dashboard indicators] addPane SMI', e);
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
                if (paneApi?.attachPrimitive && window.PlatformChartMarkers?.createPaneSolidBackdrop) {
                    paneApi.attachPrimitive(window.PlatformChartMarkers.createPaneSolidBackdrop('#0e1018'));
                }
            } catch (e) {
                console.error('[dashboard indicators] SMI pane backdrop', e);
            }
            try {
                if (smiLine && levels.show && window.PlatformChartMarkers?.attachRsiLevelLines) {
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
                console.error('[dashboard indicators] addPane StdDev', e);
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
                console.error('[dashboard indicators] addPane Ivashov', e);
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

        if (wantStochRsi) {
            const st = lastStochRsiPayload.style || {};
            const levels = lastStochRsiPayload.levels || {};
            const ser = lastStochRsiPayload.series || {};
            const lineW = st.line_width || 1;
            let paneIndex = 1;
            try {
                const pane = chart.addPane(false);
                if (pane && typeof pane.setStretchFactor === 'function') pane.setStretchFactor(0.28);
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[dashboard indicators] addPane Stoch RSI', e);
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
            const kLine = addStochRsiLine('stoch_rsi_k', ser.k, st.color_k, true);
            addStochRsiLine('stoch_rsi_d', ser.d, st.color_d, false);
            try {
                const anchor = kLine || classicLineSeries['stoch_rsi_d'];
                if (anchor && levels.show && window.PlatformChartMarkers
                    && typeof window.PlatformChartMarkers.attachRsiLevelLines === 'function') {
                    rsiLevelLines.push(...window.PlatformChartMarkers.attachRsiLevelLines(anchor, levels));
                }
            } catch (_) {}
        }

        const addHistPane = (key, payload, seriesKey) => {
            if (!payload) return;
            const st = payload.style || {};
            const ser = payload.series || {};
            const histColor = st.color_histogram || '#006400';
            const histNeg = st.color_histogram_neg || '#f43f5e';
            let paneIndex = 1;
            try {
                const pane = chart.addPane(false);
                if (pane && typeof pane.setStretchFactor === 'function') pane.setStretchFactor(0.32);
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[dashboard indicators] addPane', key, e);
                return;
            }
            const data = ser[seriesKey];
            if (HistogramSeries && Array.isArray(data) && data.length) {
                const hs = chart.addSeries(HistogramSeries, {
                    priceLineVisible: false,
                    lastValueVisible: false,
                    baseLineVisible: true,
                    baseLineColor: 'rgba(161,161,170,0.45)',
                    baseLineWidth: 1,
                    baseLineStyle: 2,
                }, paneIndex);
                hs.setData(data.map((p) => ({
                    time: p.time,
                    value: p.value,
                    color: Number(p.value) >= 0 ? histColor : histNeg,
                })));
                classicLineSeries[key] = hs;
            }
        };
        if (wantAc) addHistPane('ac_hist', lastAcPayload, 'ac');
        if (wantBullsPower) addHistPane('bulls_power_hist', lastBullsPowerPayload, 'bulls_power');
        if (wantBearsPower) addHistPane('bears_power_hist', lastBearsPowerPayload, 'bears_power');

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
                console.error('[dashboard indicators] addPane', key, e);
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
                console.error('[dashboard indicators] addPane RAVI', e);
            }
            const addRaviLine = (key, data, color, showLast) => {
                if (!Array.isArray(data) || !data.length) return;
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
            };
            addRaviLine('ravi', ser.ravi, st.color_ravi, true);
            addRaviLine('ravi_up_line', ser.up_line, st.color_up_line, false);
            addRaviLine('ravi_down_line', ser.down_line, st.color_down_line, false);
        }

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
                console.error('[dashboard indicators] addPane COG', e);
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
                console.error('[dashboard indicators] addPane Mass Index', e);
            }
            const addMiLine = (key, data, color, showLast) => {
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
                console.error('[dashboard indicators] addPane ATR', e);
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

        // Подписи названий в стиле PO: title из API («RSI 14») + иконки через layerId.
        const overlays = [];
        const pushOverlay = (on, payload, fallbackName, layerId) => {
            const lb = classicIndicatorLabel(on, payload, fallbackName, layerId);
            if (lb) overlays.push(lb);
        };
        pushOverlay(wantBb, lastBollingerPayload, 'Bollinger', 'layer-bollinger');
        pushOverlay(wantSma, lastSmaPayload, 'SMA', 'layer-sma');
        pushOverlay(wantEma, lastEmaPayload, 'EMA', 'layer-ema');
        pushOverlay(wantVwma, lastVwmaPayload, 'VWMA', 'layer-vwma');
        pushOverlay(wantKeltner, lastKeltnerPayload, 'Keltner', 'layer-keltner');
        pushOverlay(wantEnvelops, lastEnvelopsPayload, 'Envelops', 'layer-envelops');
        pushOverlay(wantParabolicSar, lastParabolicSarPayload, 'Parabolic SAR', 'layer-parabolic-sar');
        pushOverlay(wantSuperTrend, lastSuperTrendPayload, 'SuperTrend', 'layer-supertrend');
        pushOverlay(wantAlligator, lastAlligatorPayload, 'Alligator', 'layer-alligator');
        pushOverlay(wantIchimoku, lastIchimokuPayload, 'Ichimoku', 'layer-ichimoku');
        pushOverlay(wantPriceChannel, lastPriceChannelPayload, 'Price Channel', 'layer-price-channel');
        pushOverlay(wantNrtr, lastNrtrPayload, 'NRTR', 'layer-nrtr');
        pushOverlay(wantHma, lastHmaPayload, 'HMA', 'layer-hma');
        pushOverlay(wantSmaChannel, lastSmaChannelPayload, 'SMA Channel', 'layer-sma-channel');
        pushOverlay(wantLinReg, lastLinRegPayload, 'Linear Regression', 'layer-linreg');
        pushOverlay(wantLinRegChannel, lastLinRegChannelPayload, 'Linear Reg. Channel', 'layer-linreg-channel');
        pushOverlay(wantKalman, lastKalmanPayload, 'Kalman Filter', 'layer-kalman');
        pushOverlay(wantFractal, lastFractalPayload, 'Fractal', 'layer-fractal');
        pushOverlay(wantDonchian, lastDonchianPayload, 'Donchian', 'layer-donchian');
        pushOverlay(wantPivotFloor, lastPivotFloorPayload, 'Pivot Floor', 'layer-pivot-floor');
        pushOverlay(wantNrtrWatr, lastNrtrWatrPayload, 'NRTR WATR', 'layer-nrtr-watr');
        pushOverlay(wantPriceChannelAdaptive, lastPriceChannelAdaptivePayload, 'PC Adaptive', 'layer-price-channel-adaptive');
        pushOverlay(wantPriceChannelOffset, lastPriceChannelOffsetPayload, 'PC Offset', 'layer-price-channel-offset');
        pushOverlay(wantParabolicBollinger, lastParabolicBollingerPayload, 'Parabolic BB', 'layer-parabolic-bollinger');
        pushOverlay(wantParabolicPriceChannel, lastParabolicPriceChannelPayload, 'Parabolic PC', 'layer-parabolic-price-channel');
        pushOverlay(wantAtrChannel, lastAtrChannelPayload, 'ATR Channel', 'layer-atr-channel');
        pushOverlay(wantOffsetSma, lastOffsetSmaPayload, 'Offset SMA', 'layer-offset-sma');
        pushOverlay(wantOffsetEma, lastOffsetEmaPayload, 'Offset EMA', 'layer-offset-ema');
        pushOverlay(wantOffsetSsma, lastOffsetSsmaPayload, 'Offset SSMA', 'layer-offset-ssma');
        pushOverlay(wantDynamicTrendDetector, lastDynamicTrendDetectorPayload, 'DTD', 'layer-dynamic-trend-detector');
        pushOverlay(wantLinRegChannelFast, lastLinRegChannelFastPayload, 'LR Channel Fast', 'layer-linreg-channel-fast');
        pushOverlay(wantZigZag, lastZigZagPayload, 'ZigZag', 'layer-zigzag');

        const oscillators = [];
        const pushOsc = (on, payload, fallbackName, layerId) => {
            const lb = classicIndicatorLabel(on, payload, fallbackName, layerId);
            if (lb) oscillators.push(lb);
        };
        pushOsc(wantRsi, lastRsiPayload, 'RSI', 'layer-rsi');
        pushOsc(wantMacd, lastMacdPayload, 'MACD', 'layer-macd');
        pushOsc(wantStochastic, lastStochasticPayload, 'Stochastic', 'layer-stochastic');
        pushOsc(wantCci, lastCciPayload, 'CCI', 'layer-cci');
        pushOsc(wantAdx, lastAdxPayload, 'ADX', 'layer-adx');
        pushOsc(wantWilliams, lastWilliamsPayload, 'Williams %R', 'layer-williams');
        pushOsc(wantAo, lastAoPayload, 'AO', 'layer-ao');
        pushOsc(wantAroon, lastAroonPayload, 'Aroon', 'layer-aroon');
        pushOsc(wantMomentum, lastMomentumPayload, 'Momentum', 'layer-momentum');
        pushOsc(wantRoc, lastRocPayload, 'ROC', 'layer-roc');
        pushOsc(wantOsma, lastOsmaPayload, 'OsMA', 'layer-osma');
        pushOsc(wantTrix, lastTrixPayload, 'TRIX', 'layer-trix');
        pushOsc(wantCmo, lastCmoPayload, 'CMO', 'layer-cmo');
        pushOsc(wantDemarker, lastDemarkerPayload, 'DeMarker', 'layer-demarker');
        pushOsc(wantRvi, lastRviPayload, 'RVI', 'layer-rvi');
        pushOsc(wantUltimate, lastUltimatePayload, 'Ultimate Oscillator', 'layer-ultimate');
        pushOsc(wantSmi, lastSmiPayload, 'SMI', 'layer-smi');
        pushOsc(wantStdDev, lastStdDevPayload, 'StdDev', 'layer-stddev');
        pushOsc(wantIvashov, lastIvashovPayload, 'Ivashov Range', 'layer-ivashov');
        pushOsc(wantStochRsi, lastStochRsiPayload, 'Stoch RSI', 'layer-stoch-rsi');
        pushOsc(wantAc, lastAcPayload, 'AC', 'layer-ac');
        pushOsc(wantBullsPower, lastBullsPowerPayload, 'Bulls Power', 'layer-bulls-power');
        pushOsc(wantBearsPower, lastBearsPowerPayload, 'Bears Power', 'layer-bears-power');
        pushOsc(wantDpo, lastDpoPayload, 'DPO', 'layer-dpo');
        pushOsc(wantQstick, lastQstickPayload, 'QStick', 'layer-qstick');
        pushOsc(wantVhFilter, lastVhFilterPayload, 'VHFilter', 'layer-vh-filter');
        pushOsc(wantEfficiencyRatio, lastEfficiencyRatioPayload, 'Efficiency Ratio', 'layer-efficiency-ratio');
        pushOsc(wantRavi, lastRaviPayload, 'RAVI', 'layer-ravi');
        pushOsc(wantCog, lastCogPayload, 'COG', 'layer-cog');
        pushOsc(wantMassIndex, lastMassIndexPayload, 'Mass Index', 'layer-mass-index');
        pushOsc(wantVolatilityAverage, lastVolatilityAveragePayload, 'Vol Average', 'layer-volatility-average');
        pushOsc(wantVolatilityAverageChannel, lastVolatilityAverageChannelPayload, 'Vol Avg Channel', 'layer-volatility-average-channel');
        pushOsc(wantPriceOscillator, lastPriceOscillatorPayload, 'Price Oscillator', 'layer-price-oscillator');
        pushOsc(wantAdaptiveLookBack, lastAdaptiveLookBackPayload, 'Adaptive Look Back', 'layer-adaptive-look-back');
        pushOsc(wantVolatilityCandles, lastVolatilityCandlesPayload, 'Volatility Candles', 'layer-volatility-candles');
        pushOsc(wantVolatilityAverageTwice, lastVolatilityAverageTwicePayload, 'Vol Avg Twice', 'layer-volatility-average-twice');
        if (wantVolatilityStagesAw && lastVolatilityStagesAwPayload) {
            const ser = lastVolatilityStagesAwPayload.series || {};
            const baseTitle = (lastVolatilityStagesAwPayload.title)
                ? String(lastVolatilityStagesAwPayload.title)
                : 'Vol Stages AW';
            const meta = (window.PLATFORM_INDICATOR_LAYER_META && window.PLATFORM_INDICATOR_LAYER_META['layer-volatility-stages-aw']) || {};
            if (Array.isArray(ser.stages) && ser.stages.length) {
                oscillators.push({
                    name: baseTitle,
                    color: '#f4f4f5',
                    layerId: 'layer-volatility-stages-aw',
                    settingsUrl: meta.settingsUrl || null,
                });
            }
            const hasChannel = ['volatility', 'sma_fast', 'sma_slow', 'up', 'down']
                .some((k) => Array.isArray(ser[k]) && ser[k].length);
            if (hasChannel) {
                oscillators.push({
                    name: `${baseTitle} (ch)`,
                    color: '#f4f4f5',
                    layerId: 'layer-volatility-stages-aw',
                    settingsUrl: meta.settingsUrl || null,
                });
            }
        }
        pushOsc(wantAtr, lastAtrPayload, 'ATR', 'layer-atr');

        lastClassicNameState = { overlays, oscillators };
        requestAnimationFrame(() => renderAllIndicatorNameLabels());
    }

    /** Тянем только включённые classic-слои и рисуем series/panes. */
    function fetchClassicIndicators() {
        if (!currentInstrument || !anyClassicLayerOn()) {
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
            if (window.PlatformChartFetch) window.PlatformChartFetch.invalidate('classic');
            applyClassicOsEngineSeries();
            return;
        }
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
        const layers = [];
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

        if (!window.PlatformChartFetch) return;

        // Ключ: instrument|tf|layers — тот же набор не стартует повторно (таймер 15с).
        const requestKey = [
            currentInstrument,
            String(currentTimeframeMinutes),
            layers.slice().sort().join(','),
        ].join('|');
        const params = new URLSearchParams({
            instrument: currentInstrument,
            timeframe: String(currentTimeframeMinutes),
        });
        layers.forEach((l) => params.append('layers[]', l));

        PlatformChartFetch.requestJson('classic', {
            key: requestKey,
            url: CLASSIC_INDICATORS_URL + '?' + params.toString(),
            apply: (d) => {
                try {
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
                    applyClassicOsEngineSeries();
                } catch (e) {
                    console.error('[dashboard classic indicators] apply', e);
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
                    try { applyClassicOsEngineSeries(); } catch (_) {}
                }
            },
        });
    }

    /**
     * Тянем Liquidity Swings с отдельного эндпоинта (как DGT), чтобы не смешивать тяжёлые ответы.
     * Отрисовка — в redrawLiquiditySwingsOverlay(), вызывается перед PA-оверлеем.
     */
    function fetchLiquiditySwingsIndicator() {
        if (!currentInstrument || !layerLiquidity || !layerLiquidity.checked) {
            lastLsPayload = null;
            if (window.PlatformChartFetch) window.PlatformChartFetch.invalidate('liquidity');
            redrawLiquiditySwingsOverlay();
            return;
        }
        if (!window.PlatformChartFetch) return;
        const params = new URLSearchParams({ instrument: currentInstrument, timeframe: String(currentTimeframeMinutes) });
        PlatformChartFetch.requestJson('liquidity', {
            key: currentInstrument + '|' + String(currentTimeframeMinutes),
            url: LS_INDICATOR_URL + '?' + params.toString(),
            apply: (d) => {
                try {
                    lastLsPayload = d && d.liquidity_swings ? d.liquidity_swings : null;
                    redrawLiquiditySwingsOverlay();
                } catch (e) {
                    console.error('[Liquidity Swings] dashboard apply', e);
                    lastLsPayload = null;
                    try { redrawLiquiditySwingsOverlay(); } catch (_) {}
                }
            },
        });
    }

    /**
     * SMC Flow — отдельный эндпоинт (как Liquidity Swings), чтобы не раздувать ответ DGT.
     * Отрисовка в redrawSmcFlowOverlay(); вызывается из redrawPriceActionOverlay().
     */
    function fetchSmcFlowIndicator() {
        if (!currentInstrument || !layerSmc || !layerSmc.checked) {
            lastSmcPayload = null;
            if (window.PlatformChartFetch) window.PlatformChartFetch.invalidate('smc');
            redrawSmcFlowOverlay();
            return;
        }
        if (!window.PlatformChartFetch) return;
        const params = new URLSearchParams({ instrument: currentInstrument, timeframe: String(currentTimeframeMinutes) });
        PlatformChartFetch.requestJson('smc', {
            key: currentInstrument + '|' + String(currentTimeframeMinutes),
            url: SMC_INDICATOR_URL + '?' + params.toString(),
            apply: (d) => {
                try {
                    lastSmcPayload = d && d.smc_flow ? d.smc_flow : null;
                    redrawSmcFlowOverlay();
                } catch (e) {
                    console.error('[SMC Flow] dashboard apply', e);
                    lastSmcPayload = null;
                    try { redrawSmcFlowOverlay(); } catch (_) {}
                }
            },
        });
    }

    /** Размер подписей объёма: как Labels Size в LuxAlgo (tiny / small / normal). */
    function lsLabelFontPx(sz) {
        const s = (sz || 'tiny').toString().toLowerCase();
        if (s === 'small') return '11px';
        if (s === 'normal') return '13px';
        return '9px';
    }

    /**
     * DOM-оверлей зон / линий / подписей объёма (аналог отображения в TradingView).
     */
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
            console.error('[Liquidity Swings] dashboard redraw', e);
        }
    }

    /** Mxwll BoS/CHoCH — горизонтальный пунктир структуры. */
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

    /** Зоны, линии и лейблы SMC Flow (UT Bot) — отдельный DOM-слой поверх DGT. */
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
            // Пунктиры BoS/CHoCH — в приоритете, иначе их вытесняют сегменты UT/Hull.
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
            console.error('[SMC Flow] dashboard redraw', e);
        }
    }

    function redrawPriceActionOverlay() {
        redrawLiquiditySwingsOverlay();
        // SMC Flow не зависит от слоя DGT — перерисовываем всегда при sync оверлеев.
        redrawSmcFlowOverlay();
        try {
            if (!priceActionOverlay || !chart || !candlestickSeries) return;
            priceActionOverlay.innerHTML = '';
            if (!layerPa || !layerPa.checked || !lastPaPayload || !allChartData.length) return;
            const ts = chart.timeScale();
            const vpPanelMaxHistPx = 100;
            const vpPanelMaxDomPx = 26;
            const vpPanelGapPx = 2;
            /** Правый край колонки VP = левый край области подписей цен (ширина шкалы меняется при зуме и формате чисел). */
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
                // На первом кадре width() иногда 0 — не даём VP уехать вправо за холст (старый запас ~78px).
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
        } finally {
            // Названия structural/classic-слоёв обновляем после любого выхода из PA overlay.
            renderAllIndicatorNameLabels();
        }
    }

    /**
     * Pocket Option считает свечу растущей только при close > open.
     * Lightweight Charts использует close >= open, поэтому точный доджи без явного
     * per-bar цвета становится зелёным. Для равных OHLC задаём красный цвет явно,
     * чтобы dashboard совпадал с правилом и визуальным результатом PO.
     *
     * Функция возвращает исходный объект для обычных свечей: это сохраняет штатную
     * бычью/медвежью окраску библиотеки и не создаёт лишние объекты на каждом poll.
     */
    function paintPocketOptionDoji(bar) {
        if (!bar) return bar;
        const open = Number(bar.open);
        const close = Number(bar.close);
        if (!Number.isFinite(open) || !Number.isFinite(close) || open !== close) {
            return bar;
        }
        return {
            ...bar,
            color: '#ef5350',
            borderColor: '#ef5350',
            wickColor: '#ef5350',
        };
    }

    /**
     * Окраска свечей DGT. На poll НЕ делаем setData всего ряда — только update хвоста;
     * иначе каждые 500ms LightweightCharts пересобирает серию и появляются «ломаные» бары.
     * fullResync: true — при первой загрузке, смене TF и выключении слоя PA.
     * Последний бар при активном tween берётся через candleSmooth.visualFor — иначе PA
     * каждый poll снэпает close и убивает плавность.
     */
    function applyPaBarColors(opts = {}) {
        const fullResync = opts && opts.fullResync === true;
        if (!candlestickSeries || !allChartData.length) return;
        const plainBar = (c) => {
            const src = candleSmooth ? candleSmooth.visualFor(c) : c;
            return paintPocketOptionDoji({
                time: src.time, open: src.open, high: src.high, low: src.low, close: src.close,
            });
        };
        const plain = allChartData.map((c) => paintPocketOptionDoji({
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
                return paintPocketOptionDoji({
                    time: src.time, open: src.open, high: src.high, low: src.low, close: src.close,
                });
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
                try { candlestickSeries.setData(plain); } catch (e2) { console.error('[PA] fallback', e2); }
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
                // Хвост без последнего бара: его ведёт candleSmooth.toward / RAF.
                if (c.time === lastT && candleSmooth && candleSmooth.isAnimating()) {
                    continue;
                }
                try { candlestickSeries.update(paintBar(c)); } catch (e) { console.error('[PA] update', e); }
            }
        }
    }

    /** Цвета DGT для одного бара — в update-пути плавной анимации. */
    function paintBarForSmooth(bar) {
        if (!bar) return bar;
        const bc = lastPaPayload && lastPaPayload.bar_colors ? lastPaPayload.bar_colors : null;
        const paOn = layerPa && layerPa.checked && bc && Object.keys(bc).length > 0;
        if (!paOn) {
            return paintPocketOptionDoji({
                time: bar.time, open: bar.open, high: bar.high, low: bar.low, close: bar.close,
            });
        }
        const o = bc[String(bar.time)];
        if (!o) {
            return paintPocketOptionDoji({
                time: bar.time, open: bar.open, high: bar.high, low: bar.low, close: bar.close,
            });
        }
        return {
            time: bar.time, open: bar.open, high: bar.high, low: bar.low, close: bar.close,
            color: o.color, borderColor: o.borderColor || o.color, wickColor: o.wickColor || o.color,
        };
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
        if (!window.PlatformChartFetch) return;
        const params = new URLSearchParams({
            instrument: currentInstrument,
            timeframe: String(currentTimeframeMinutes)
        });
        PlatformChartFetch.requestJson('structures', {
            key: currentInstrument + '|' + String(currentTimeframeMinutes),
            url: STRUCTURES_URL + '?' + params.toString(),
            apply: (data) => {
                lastZones = data.zones || [];
                requestAnimationFrame(redrawStructureOverlay);
            },
        });
    }

    function startAutoRefresh() {
        if (refreshInterval) clearInterval(refreshInterval);
        // Тот же шаг, что и на странице бота: 500ms для всех TF — максимально частое обновление свечного графика через REST.
        refreshInterval = setInterval(() => {
            if (!currentInstrument || loadingMore || !candlestickSeries) return;
            // Поллингу важен только хвост: запрашиваем узкое окно последних минут.
            // Это и снимает нагрузку с БД, и предохраняет от перетирания истории,
            // догруженной пользователем влево через loadMoreCandles.
            fetchCandles(currentInstrument, { kind: 'poll' })
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
                    // Перерисовываем DOM-оверлей структуры на основании уже имеющихся
                    // в памяти зон (lastZones). Сами зоны при этом НЕ перезапрашиваем —
                    // это делает отдельный медленный таймер ниже. Раньше этот блок
                    // на каждом тике посылал тяжёлые fetchStructures() и
                    // fetchPriceActionIndicator() (~46 KB) каждые 500мс, переполнял
                    // браузерный fetch-pool и блокировал клик-инициированные
                    // initial-запросы /candles при выборе инструмента.
                    requestAnimationFrame(redrawStructureOverlay);
                })
                .catch(console.error);
        }, 500);

        startOverlaysRefresh();
    }

    /**
     * Раз в OVERLAYS_REFRESH_MS подтягиваем тяжёлые оверлеи: зоны структуры и
     * Price Action DGT. Дополнительно один раз сразу запускаем (ниже),
     * чтобы при выборе инструмента оверлеи появились без 15-секундной задержки.
     */
    function startOverlaysRefresh() {
        if (overlaysRefreshInterval) clearInterval(overlaysRefreshInterval);
        overlaysRefreshInterval = setInterval(() => {
            if (!currentInstrument || loadingMore || !candlestickSeries) return;
            // Структуры обновляем всегда (легче по объёму ответа), DGT — только если слой включён.
            fetchStructures();
            if (layerPa && layerPa.checked) fetchPriceActionIndicator();
            if (layerLiquidity && layerLiquidity.checked) fetchLiquiditySwingsIndicator();
            if (layerSmc && layerSmc.checked) fetchSmcFlowIndicator();
            // Маркеры сделок: лёгкий JSON, обновляем вместе с оверлеями (не в 500мс poll свечей).
            fetchChartTrades();
            // Классика OsEngine — только если чекбоксы включены.
            if (anyClassicLayerOn()) fetchClassicIndicators();
        }, OVERLAYS_REFRESH_MS);
    }

    function stopOverlaysRefresh() {
        if (overlaysRefreshInterval) {
            clearInterval(overlaysRefreshInterval);
            overlaysRefreshInterval = null;
        }
    }

    function setLoading(show) {
        chartLoading.classList.toggle('hidden', !show);
    }

    /**
     * Загрузка свечей для графика с учётом режима запроса.
     *
     * Раньше каждый клик по инструменту тянул сразу 24 часа M1 (limit=1440, since=now-86400),
     * из-за чего сервер на каждый запрос сканировал из БД до 15000 S5-строк. Теперь окно
     * выбирается под конкретный сценарий:
     *   - 'initial' (выбор инструмента / смена TF): малое окно для мгновенного отклика;
     *   - 'more'    (ленивая догрузка влево при скролле): расширяется до MAX_WINDOW_SEC;
     *   - 'poll'    (auto-refresh каждые 500мс): узкое окно — только последние минуты,
     *               чтобы поллинг не перезатирал уже догруженную историю.
     *
     * Параметры:
     *   instrument — символ инструмента;
     *   opts.before — нижняя граница диапазона (для догрузки влево);
     *   opts.kind   — режим запроса: initial | more | poll (см. выше).
     */
    function fetchCandles(instrument, opts = {}) {
        const before = opts.before != null ? opts.before : null;
        // По умолчанию ведём себя как первичная загрузка — это безопасный fallback
        // для случаев, когда вызывающий код не указал режим явно.
        const kind = opts.kind || 'initial';

        let since;
        let limit;
        if (kind === 'more') {
            // Догрузка влево опирается на «потолок» истории (24ч), чтобы пользователь
            // мог пролистать на максимально возможную глубину.
            since = Math.floor(Date.now() / 1000) - MAX_WINDOW_SEC;
            limit = LOAD_MORE_LIMIT;
        } else if (kind === 'poll') {
            // Авто-обновление: достаточно ровно «хвоста» последних минут.
            // Минимизирует чтение из БД и payload.
            since = Math.floor(Date.now() / 1000) - POLL_WINDOW_SEC;
            limit = POLL_LIMIT;
        } else {
            // Первичный показ: INITIAL_BARS свечей выбранного TF (не «2 часа на всех»).
            since = Math.floor(Date.now() / 1000) - initialWindowSecForTf(currentTimeframeMinutes);
            limit = initialLimitForTf(currentTimeframeMinutes);
        }

        const params = new URLSearchParams({
            instrument,
            timeframe: String(currentTimeframeMinutes),
            limit: String(limit),
            since: String(since),
        });
        if (before != null) params.set('before', before);
        // Live partial для всех TF (как на странице бота): S5/S15 обновляются каждые 500ms, не раз в 5с.
        if (before == null) {
            params.set('include_partial', '1');
        }

        const url = CANDLES_URL + '?' + params.toString();
        // Отдельные каналы: poll не abort'ит initial/more и наоборот.
        // Poll coalesce по instrument|tf — не копим очередь 500мс-тиков.
        const channel = kind === 'poll' ? 'candles-poll'
            : (kind === 'more' ? 'candles-more' : 'candles-initial');
        const key = kind === 'poll'
            ? (instrument + '|' + String(currentTimeframeMinutes) + '|poll')
            : (instrument + '|' + String(currentTimeframeMinutes) + '|' + kind + '|' + String(before ?? ''));

        const finishPayload = (payload) => {
            if (!payload || typeof payload !== 'object') {
                return { candles: [] };
            }
            applyM1CountdownFromPayload(payload);
            // Микро-инструменты: подставляем 8 знаков (или иной scale) до setData/update.
            applyPriceScaleFromPayload(payload);
            if (currentTimeframeMinutes !== 1) {
                applyGenericCountdownFromPayload(payload, currentTimeframeMinutes);
            } else {
                tfCloseCountdownAnchorSec = null;
                tfCloseCountdownAnchorPerfMs = null;
            }
            return payload;
        };

        if (!window.PlatformChartFetch) {
            return fetch(url, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            }).then((r) => r.json()).then(finishPayload);
        }

        const p = PlatformChartFetch.requestJson(channel, {
            key: key,
            url: url,
            // initial всегда уникален по смыслу; poll/more coalesce.
            coalesce: kind !== 'initial',
        });
        // Coalesce poll: нет нового HTTP — отдаём пустой хвост (caller early-return).
        if (!p) {
            return Promise.resolve({ candles: [] });
        }
        return p.then((payload) => finishPayload(payload));
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

    /**
     * Подмешивает свечи из poll/initial в allChartData, не затирая историю слева.
     * Poll (POLL_WINDOW_SEC ≈ 5 мин) раньше при совпадении первого бара с oldest делал
     * allChartData = newData и через applyPaBarColors() setData() — график «схлопывался»
     * и ломался примерно через 5–10 минут простоя на одном инструменте.
     *
     * Последний бар: не series.update() сразу, а candleSmooth.toward() — close «плывёт»
     * к новой цене между poll-тиками (как на Pocket Option).
     */
    function applyIncrementalChartUpdate(newData) {
        if (!candlestickSeries || !Array.isArray(newData) || newData.length === 0) return;

        if (allChartData.length === 0) {
            allChartData = newData;
            oldestLoadedTime = Math.min(...allChartData.map(c => c.time));
            if (candleSmooth) candleSmooth.reset();
            candlestickSeries.setData(allChartData.map(paintPocketOptionDoji));
            // Зафиксировать стартовую точку tween на последнем баре.
            if (candleSmooth && allChartData.length) {
                candleSmooth.snap(allChartData[allChartData.length - 1]);
            }
            tickCandleCloseTimer();
            applyPaBarColors({ fullResync: true });
            requestAnimationFrame(redrawPriceActionOverlay);
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
        // LightweightCharts.update() допускает только последний бар или новый бар после него.
        // Нельзя update() «середину» ряда — иначе Cannot update oldest data и артефакты на графике.
        let updateFailed = false;
        for (const c of newData) {
            if (c.time < prevLast) {
                continue;
            }
            try {
                if (c.time === newestTime && candleSmooth) {
                    // Тот же или новый хвост: toward сам сделает snap при смене time.
                    candleSmooth.toward(c);
                } else {
                    candlestickSeries.update(paintPocketOptionDoji(c));
                }
            } catch (e) {
                updateFailed = true;
                console.error('[chart] candle update', e);
            }
        }

        allChartData = merged;
        oldestLoadedTime = oldestLoadedTime == null
            ? merged[0].time
            : Math.min(oldestLoadedTime, merged[0].time);

        if (prependedOlder || updateFailed) {
            if (candleSmooth) candleSmooth.reset();
            candlestickSeries.setData(allChartData.map(paintPocketOptionDoji));
            if (candleSmooth && allChartData.length) {
                candleSmooth.snap(allChartData[allChartData.length - 1]);
            }
            applyPaBarColors({ fullResync: true });
        } else {
            applyPaBarColors();
        }

        tickCandleCloseTimer();
        requestAnimationFrame(redrawPriceActionOverlay);
    }

    /**
     * Синхронизация HTML-оверлеев с canvas при любом изменении viewport.
     *
     * Проблема: subscribeVisibleLogicalRangeChange срабатывает только когда меняется горизонтальный
     * логический диапазон. Зум/сдвиг только по цене (правая шкала, колёсико на области цены)
     * не вызывает этот колбэк, а timeToCoordinate/priceToCoordinate при этом меняются — оверлей
     * остаётся со старыми пикселями и «отлипает» от свечей.
     *
     * Решение: attachPrimitive с пустым paneView. У примитива updateAllViews() вызывается
     * библиотекой при любом пересчёте вида (время + цена + размеры). Здесь только планируем
     * перерисовку DOM; на canvas ничего не рисуем.
     *
     * rAF: за кадр сливаем много вызовов updateAllViews в одну перерисовку оверлеев.
     */
    let chartDomOverlaySyncRaf = null;
    function scheduleChartDomOverlaySync() {
        if (chartDomOverlaySyncRaf !== null) return;
        chartDomOverlaySyncRaf = requestAnimationFrame(() => {
            chartDomOverlaySyncRaf = null;
            try {
                redrawStructureOverlay();
            } catch (e) {
                console.error('[chart] dom overlay sync', e);
            }
            try {
                // Лучи PO привязаны к price/time координатам — синхроним с pan/zoom вместе с structure.
                renderPoOpenTradeRaysOverlay();
            } catch (ePo) {
                console.error('[chart] po open trade rays sync', ePo);
            }
            requestAnimationFrame(() => {
                try {
                    positionCandleTimerOverlay();
                } catch (e2) {
                    console.error('[chart] candle timer overlay sync', e2);
                }
            });
        });
    }
    function createChartDomOverlaySyncPrimitive() {
        const paneView = {
            renderer() {
                return {
                    draw() {
                        /* Нам нужен только updateAllViews; draw оставляем пустым. */
                    },
                };
            },
        };
        const paneViewsStable = [paneView];
        return {
            updateAllViews() {
                scheduleChartDomOverlaySync();
            },
            paneViews() {
                return paneViewsStable;
            },
        };
    }

    function createChart() {
        // Останавливаем tween предыдущего графика (смена инструмента / TF / rebuild).
        if (candleSmooth) {
            candleSmooth.reset();
            candleSmooth = null;
        }
        if (chart) chart.remove();
        chartWrapper.innerHTML = '';
        chartWrapper.classList.remove('hidden');
        structureOverlay.classList.remove('hidden');
        if (liquiditySwingsOverlay) liquiditySwingsOverlay.classList.remove('hidden');
        if (priceActionOverlay) priceActionOverlay.classList.remove('hidden');
        if (smcFlowOverlay) smcFlowOverlay.classList.remove('hidden');
        if (classicIndicatorLabelsEl) classicIndicatorLabelsEl.classList.remove('hidden');
        chartPlaceholder.classList.add('hidden');

        chart = LightweightCharts.createChart(chartWrapper, {
            layout: {
                background: { type: 'solid', color: '#191c30' },
                textColor: '#a1a1aa',
                // Скрываем логотип TradingView в углу графика (licensing: attributionLogo).
                attributionLogo: false,
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

        candlestickSeries = chart.addSeries(LightweightCharts.CandlestickSeries, {
            upColor: '#26a69a',
            downColor: '#ef5350',
            borderVisible: false,
            wickUpColor: '#26a69a',
            wickDownColor: '#ef5350',
            priceFormat: {
                type: 'price',
                precision: 5,
                minMove: 0.00001
            }
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
        // Инициализируем markers-plugin единообразно с bots/show.
        candlestickMarkers = null;
        try {
            if (window.PlatformChartMarkers && typeof window.PlatformChartMarkers.createPluginForSeries === 'function') {
                candlestickMarkers = window.PlatformChartMarkers.createPluginForSeries(candlestickSeries, []);
            } else if (typeof LightweightCharts.createSeriesMarkers === 'function') {
                candlestickMarkers = LightweightCharts.createSeriesMarkers(candlestickSeries, []);
            }
        } catch (e) {
            console.error('[dashboard chart] createSeriesMarkers', e);
        }
        classicLineSeries = {};
        rsiLevelLines = [];
        classicOscPaneCount = 0;
        lastClassicNameState = { overlays: [], oscillators: [] };
        if (classicIndicatorLabelsEl) {
            classicIndicatorLabelsEl.innerHTML = '';
            classicIndicatorLabelsEl.classList.remove('hidden');
        }

        try {
            candlestickSeries.attachPrimitive(createChartDomOverlaySyncPrimitive());
        } catch (e) {
            console.error('[chart] attachPrimitive(dom overlay sync)', e);
        }

        chart.timeScale().subscribeVisibleLogicalRangeChange(() => {
            const range = chart.timeScale().getVisibleLogicalRange();
            // Догрузка влево на любом TF (раньше только M1 — на M5+ история обрывалась на initial).
            if (range && !loadingMore && oldestLoadedTime != null && range.from < 2) {
                loadMoreCandles();
            }
            redrawStructureOverlay();
            requestAnimationFrame(redrawPriceActionOverlay);
            requestAnimationFrame(positionCandleTimerOverlay);
            requestAnimationFrame(renderPoOpenTradeRaysOverlay);
        });
    }

    function loadMoreCandles() {
        if (!currentInstrument || loadingMore || oldestLoadedTime == null) return;
        const sinceT = candlesSince();
        if (oldestLoadedTime <= sinceT) return;

        loadingMore = true;
        setLoading(true);
        // Догрузка более старой истории: расширяем окно до MAX_WINDOW_SEC.
        // Работает для всех TF графика (M1/M5/… и S5/S15/S30).
        fetchCandles(currentInstrument, { before: oldestLoadedTime, kind: 'more' })
            .then(data => {
                const candles = data.candles || [];
                if (candles.length === 0) { loadingMore = false; setLoading(false); return; }
                const newBars = buildChartData(candles);
                const existingTimes = new Set(allChartData.map(c => c.time));
                const toAdd = newBars.filter(c => !existingTimes.has(c.time));
                if (toAdd.length === 0) { loadingMore = false; setLoading(false); return; }
                allChartData = [...toAdd, ...allChartData].sort((a, b) => a.time - b.time);
                oldestLoadedTime = Math.min(...allChartData.map(c => c.time));
                if (candleSmooth) candleSmooth.cancel();
                candlestickSeries.setData(allChartData.map(paintPocketOptionDoji));
                if (candleSmooth && allChartData.length) {
                    candleSmooth.snap(allChartData[allChartData.length - 1]);
                }
                loadingMore = false;
                setLoading(false);
                tickCandleCloseTimer();
                applyPaBarColors({ fullResync: true });
                requestAnimationFrame(redrawStructureOverlay);
                fetchStructures();
                if (layerPa && layerPa.checked) fetchPriceActionIndicator();
                if (layerLiquidity && layerLiquidity.checked) fetchLiquiditySwingsIndicator();
                if (anyClassicLayerOn()) fetchClassicIndicators();
            })
            .catch(() => { loadingMore = false; setLoading(false); });
    }

    function reloadChartData() {
        if (!currentInstrument || !candlestickSeries) return;
        setLoading(true);
        // Смена таймфрейма / явный reload — это «как заново выбрали инструмент»:
        // тянем только initial-окно, остальное пользователь догрузит скроллом.
        fetchCandles(currentInstrument, { kind: 'initial' })
            .then(data => {
                const candles = data.candles || [];
                allChartData = buildChartData(candles);
                if (allChartData.length) {
                    oldestLoadedTime = Math.min(...allChartData.map(c => c.time));
                    if (candleSmooth) candleSmooth.reset();
                    candlestickSeries.setData(allChartData.map(paintPocketOptionDoji));
                    if (candleSmooth) candleSmooth.snap(allChartData[allChartData.length - 1]);
                    applyDefaultChartViewport();
                    fetchStructures();
                    fetchPriceActionIndicator();
                    fetchLiquiditySwingsIndicator();
                    fetchSmcFlowIndicator();
                    fetchChartTrades();
                    fetchClassicIndicators();
                } else {
                    if (candleSmooth) candleSmooth.reset();
                    candlestickSeries.setData([]);
                    lastZones = [];
                    structureOverlay.innerHTML = '';
                    lastPaPayload = null;
                    lastLsPayload = null;
                    lastSmcPayload = null;
                    lastChartSignals = [];
                    lastOpenTrades = [];
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
                    if (priceActionOverlay) priceActionOverlay.innerHTML = '';
                    if (liquiditySwingsOverlay) liquiditySwingsOverlay.innerHTML = '';
                    if (smcFlowOverlay) smcFlowOverlay.innerHTML = '';
                    renderChartMarkers();
                    applyClassicOsEngineSeries();
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
        // Сброс всех in-flight: иначе ответ по старой паре может перезаписать новый график.
        if (window.PlatformChartFetch) window.PlatformChartFetch.invalidateAll();
        currentInstrument = instrument;
        currentDisplayName = displayName;
        // Запоминаем пару — при F5 вернёмся к ней, а не к первой в списке.
        saveSelectedInstrument(instrument);
        document.querySelectorAll('.instrument-btn').forEach((b) => b.classList.toggle('bg-blue-900/40', b.dataset.instrument === instrument));
        // Синхронизируем выплату/доступность кнопок CALL|PUT с выбранной парой.
        if (typeof updateManualTradePayout === 'function') updateManualTradePayout();
        lastZones = [];
        structureOverlay.innerHTML = '';
        lastPaPayload = null;
        lastLsPayload = null;
        lastSmcPayload = null;
        lastChartSignals = [];
        lastOpenTrades = [];
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
        if (priceActionOverlay) priceActionOverlay.innerHTML = '';
        if (liquiditySwingsOverlay) liquiditySwingsOverlay.innerHTML = '';
        if (smcFlowOverlay) smcFlowOverlay.innerHTML = '';
        updateChartTitle();
        updateTfOptionStyles();
        tickCandleCloseTimer();
        setLoading(true);
        createChart();

        // Первичная загрузка свечей при выборе инструмента: только initial-окно,
        // чтобы переключение между парами происходило быстро. Остальное
        // (старая история) подтянется по требованию через loadMoreCandles.
        fetchCandles(instrument, { kind: 'initial' })
            .then(data => {
                const candles = data.candles || [];
                allChartData = buildChartData(candles);
                if (allChartData.length) {
                    oldestLoadedTime = Math.min(...allChartData.map(c => c.time));
                    if (candleSmooth) candleSmooth.reset();
                    candlestickSeries.setData(allChartData.map(paintPocketOptionDoji));
                    if (candleSmooth) candleSmooth.snap(allChartData[allChartData.length - 1]);
                    applyDefaultChartViewport();
                    // startAutoRefresh внутри уже стартует и медленный таймер оверлеев.
                    startAutoRefresh();
                    requestAnimationFrame(() => {
                        redrawStructureOverlay();
                        // Один раз дёргаем «тяжёлые» эндпоинты сразу при выборе инструмента,
                        // чтобы оверлеи появились без 15-секундной задержки таймера.
                        fetchStructures();
                        fetchPriceActionIndicator();
                        fetchLiquiditySwingsIndicator();
                        fetchSmcFlowIndicator();
                        fetchChartTrades();
                        fetchClassicIndicators();
                    });
                } else {
                    if (refreshInterval) clearInterval(refreshInterval);
                    stopOverlaysRefresh();
                    renderChartMarkers();
                    applyClassicOsEngineSeries();
                }
                updateChartTitle();
                tickCandleCloseTimer();
                setLoading(false);
            })
            .catch(() => {
                setLoading(false);
                if (refreshInterval) clearInterval(refreshInterval);
                stopOverlaysRefresh();
                tickCandleCloseTimer();
            });
    }

    tfBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        if (window.PlatformIndicatorsPopup) window.PlatformIndicatorsPopup.close();
        else layersPanel.classList.add('hidden');
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
            saveSelectedTimeframe(m);
            updateTfOptionStyles();
            tfPanel.classList.add('hidden');
            // Смена TF: abort старых classic/structures и т.п., чтобы не нарисовать M5 поверх M1.
            if (window.PlatformChartFetch) window.PlatformChartFetch.invalidateAll();
            if (currentInstrument && candlestickSeries) {
                reloadChartData();
            }
        });
    });

    // Попап «Индикаторы» открывается в chart-indicators-popup-script (не дублируем click).
    document.addEventListener('click', () => {
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
            applyPaBarColors({ fullResync: true });
            renderChartMarkers();
            requestAnimationFrame(redrawStructureOverlay);
        });
    }
    if (layerLiquidity) {
        layerLiquidity.addEventListener('change', () => {
            saveLayerPrefs();
            if (!layerLiquidity.checked) lastLsPayload = null;
            fetchLiquiditySwingsIndicator();
            requestAnimationFrame(redrawStructureOverlay);
        });
    }
    if (layerSmc) {
        layerSmc.addEventListener('change', () => {
            saveLayerPrefs();
            if (!layerSmc.checked) lastSmcPayload = null;
            fetchSmcFlowIndicator();
            requestAnimationFrame(redrawPriceActionOverlay);
        });
    }
    // Classic OsEngine: пересчитываем только при смене чекбокса (как на bots/show).
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
            // Чистим payload только при выключении; при включении ждём ответ API.
            if (!el.checked) clearFn();
            fetchClassicIndicators();
        });
    });

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

    // Переключатель «Торговля» справа в шапке: скрывает сделки на графике и в сайдбаре.
    syncShowTradesToggleUi();
    if (showTradesToggle) {
        showTradesToggle.addEventListener('change', () => {
            applyShowTradesMode(!!showTradesToggle.checked, { persist: true });
        });
    }
    // SSR-список мог прийти с таймерами открытых сделок — сразу прячем, если режим выкл.
    if (!showTrades) {
        hideSidebarTradeChrome();
    }

    // Восстанавливаем ТФ до выбора инструмента, чтобы initial-fetch шёл уже с нужным периодом.
    const persistedTf = loadSelectedTimeframe();
    if (persistedTf != null) {
        currentTimeframeMinutes = persistedTf;
        updateTfOptionStyles();
    }

    instrumentListRefreshInterval = setInterval(refreshInstrumentSidebar, 1000);
    sidebarTimerInterval = setInterval(() => {
        tickSidebarTradeTimers();
        tickCandleCloseTimer();
        // Обновляем только текст таймеров на лучах (полный recalculate не нужен каждый кадр,
        // но координаты могли сдвинуться из‑за live-свечи — перерисовываем раз в секунду).
        renderPoOpenTradeRaysOverlay();
    }, 1000);
    tickSidebarTradeTimers();
    // Правая панель CALL/PUT: слушатели + первичный расчёт выплаты.
    initManualTradePanel();
    refreshInstrumentSidebar().then(() => {
        tickCandleCloseTimer();
        // Сначала сохранённая пара, иначе первая доступная в списке.
        const persistedInstr = loadSelectedInstrument();
        let btn = null;
        if (persistedInstr) {
            document.querySelectorAll('#instrument-list .instrument-btn').forEach((b) => {
                if (!btn && b.dataset.instrument === persistedInstr) btn = b;
            });
        }
        if (!btn) btn = document.querySelector('#instrument-list .instrument-btn');
        if (btn) btn.click();
    });

    /**
     * Панель ручного входа на PO (справа от графика).
     * SSID читается только на сервере из /profile/trading; сюда не передаём.
     * Paper+mirror идут в бот «Ручная / Manual» → лента Сделки PO ниже.
     */
    function initManualTradePanel() {
        const panel = document.getElementById('manual-trade-panel');
        if (!panel) return;

        const amountEl = document.getElementById('manual-trade-amount');
        const amountHintEl = document.getElementById('manual-trade-amount-hint');
        const durationEl = document.getElementById('manual-trade-duration');
        const durationPopover = document.getElementById('manual-trade-duration-popover');
        const durHEl = document.getElementById('manual-dur-h');
        const durMEl = document.getElementById('manual-dur-m');
        const durSEl = document.getElementById('manual-dur-s');
        const buyBtn = document.getElementById('manual-trade-buy');
        const sellBtn = document.getElementById('manual-trade-sell');
        const statusEl = document.getElementById('manual-trade-status');
        const settingsBtn = document.getElementById('manual-trade-settings-btn');
        const settingsModal = document.getElementById('manual-trade-settings-modal');
        const flagWaitEl = document.getElementById('manual-flag-wait');
        const flagMgEl = document.getElementById('manual-flag-martingale');
        const flagAmountMgEl = document.getElementById('manual-flag-amount-mg');
        const settingsSaveBtn = document.getElementById('manual-settings-save');
        const settingsStatusEl = document.getElementById('manual-settings-status');

        const ssidReady = panel.dataset.ssidReady === '1';
        const orderUrl = panel.dataset.orderUrl || '';
        const settingsUrl = panel.dataset.settingsUrl || '';
        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        // Как на сервере: ManualPocketOrderService::MIN/MAX_DURATION_SEC (S3 = 3с как на PO).
        const DURATION_MIN_SEC = 3;
        const DURATION_MAX_SEC = 43200;
        // Переживают F5 в этом браузере (тумблеры шестерёнки — уже в профиле БД).
        const LS_DURATION_SEC = 'platform.manualTrade.durationSec';
        const LS_AMOUNT = 'platform.manualTrade.amount';

        let placing = false;
        let amountFromMartingale = panel.dataset.flagAmountMg === '1';
        // Текущее значение поповера в секундах (синхронизируется с полем).
        let popoverDurationSec = 300;

        function persistManualDurationSec(sec) {
            try {
                localStorage.setItem(LS_DURATION_SEC, String(clampDurationSec(sec)));
            } catch (_) { /* private mode / quota */ }
        }

        function persistManualAmount(amount) {
            if (amountFromMartingale) return;
            if (!Number.isFinite(amount) || amount < 1) return;
            try {
                localStorage.setItem(LS_AMOUNT, String(amount));
            } catch (_) { /* private mode / quota */ }
        }

        function restoreManualPanelPrefs() {
            try {
                const rawDur = localStorage.getItem(LS_DURATION_SEC);
                if (rawDur != null && rawDur !== '') {
                    const sec = parseInt(rawDur, 10);
                    if (Number.isFinite(sec)) {
                        writeDurationToInput(sec);
                        syncPopoverDigits(sec);
                    }
                }
            } catch (_) { /* ignore */ }

            // Сумму восстанавливаем только если не включён «сумма с шага МГ».
            if (!amountFromMartingale && amountEl) {
                try {
                    const rawAmt = localStorage.getItem(LS_AMOUNT);
                    const amt = rawAmt != null ? parseFloat(rawAmt) : NaN;
                    if (Number.isFinite(amt) && amt >= 1) {
                        amountEl.value = String(amt);
                    }
                } catch (_) { /* ignore */ }
            }
        }

        if (amountEl) {
            amountEl.addEventListener('input', () => {
                updateManualTradePayout();
                const amt = parseFloat(amountEl.value);
                if (Number.isFinite(amt)) persistManualAmount(amt);
            });
            amountEl.addEventListener('change', () => {
                updateManualTradePayout();
                const amt = parseFloat(amountEl.value);
                if (Number.isFinite(amt)) persistManualAmount(amt);
            });
        }

        /** Секунды → HH:MM:SS. */
        function formatDurationClock(totalSec) {
            const s = Math.max(0, Math.floor(Number(totalSec) || 0));
            const hh = Math.floor(s / 3600);
            const mm = Math.floor((s % 3600) / 60);
            const ss = s % 60;
            return String(hh).padStart(2, '0') + ':'
                + String(mm).padStart(2, '0') + ':'
                + String(ss).padStart(2, '0');
        }

        /**
         * Парс MM:SS / HH:MM:SS (+ голые секунды цифрами).
         * @returns {number|null}
         */
        function parseDurationClock(raw) {
            const text = String(raw ?? '').trim();
            if (!text) return null;
            if (/^\d+$/.test(text)) {
                const n = parseInt(text, 10);
                return Number.isFinite(n) ? n : null;
            }
            const parts = text.split(':').map((p) => p.trim());
            if (parts.length < 2 || parts.length > 3) return null;
            if (!parts.every((p) => /^\d{1,2}$/.test(p))) return null;
            const nums = parts.map((p) => parseInt(p, 10));
            let hh = 0, mm = 0, ss = 0;
            if (nums.length === 2) {
                mm = nums[0];
                ss = nums[1];
            } else {
                hh = nums[0];
                mm = nums[1];
                ss = nums[2];
            }
            if (mm > 59 || ss > 59 || hh > 12) return null;
            return hh * 3600 + mm * 60 + ss;
        }

        function clampDurationSec(sec) {
            if (!Number.isFinite(sec)) return DURATION_MIN_SEC;
            return Math.min(DURATION_MAX_SEC, Math.max(DURATION_MIN_SEC, Math.floor(sec)));
        }

        function resolveManualDurationSec() {
            return parseDurationClock(durationEl ? durationEl.value : '');
        }

        function writeDurationToInput(sec) {
            if (!durationEl) return;
            durationEl.value = formatDurationClock(clampDurationSec(sec));
        }

        function syncPopoverDigits(sec) {
            const s = clampDurationSec(sec);
            popoverDurationSec = s;
            const hh = Math.floor(s / 3600);
            const mm = Math.floor((s % 3600) / 60);
            const ss = s % 60;
            if (durHEl) durHEl.textContent = String(hh).padStart(2, '0');
            if (durMEl) durMEl.textContent = String(mm).padStart(2, '0');
            if (durSEl) durSEl.textContent = String(ss).padStart(2, '0');
            // Подсветка активного пресета.
            if (durationPopover) {
                durationPopover.querySelectorAll('[data-dur-preset]').forEach((btn) => {
                    const match = parseInt(btn.getAttribute('data-dur-preset') || '', 10) === s;
                    btn.classList.toggle('ring-1', match);
                    btn.classList.toggle('ring-blue-500', match);
                    btn.classList.toggle('text-white', match);
                    btn.classList.toggle('bg-zinc-700', match);
                });
            }
        }

        function applyDurationSec(sec, { close = false } = {}) {
            const clamped = clampDurationSec(sec);
            writeDurationToInput(clamped);
            syncPopoverDigits(clamped);
            persistManualDurationSec(clamped);
            if (close) closeDurationPopover();
        }

        function positionDurationPopover() {
            if (!durationPopover || !durationEl) return;
            const rect = durationEl.getBoundingClientRect();
            const popW = durationPopover.offsetWidth || 216;
            const popH = durationPopover.offsetHeight || 260;
            // Слева от поля (как на PO); если не влезает — прижимаем к краю.
            let left = rect.left - popW - 10;
            let top = rect.top;
            if (left < 8) left = 8;
            if (top + popH > window.innerHeight - 8) {
                top = Math.max(8, window.innerHeight - popH - 8);
            }
            durationPopover.style.left = left + 'px';
            durationPopover.style.top = top + 'px';
        }

        function isDurationPopoverOpen() {
            return durationPopover && !durationPopover.classList.contains('hidden');
        }

        function openDurationPopover() {
            if (!durationPopover) return;
            const parsed = resolveManualDurationSec();
            const sec = parsed != null ? clampDurationSec(parsed) : 300;
            syncPopoverDigits(sec);
            durationPopover.classList.remove('hidden');
            positionDurationPopover();
        }

        function closeDurationPopover() {
            if (durationPopover) durationPopover.classList.add('hidden');
        }

        if (durationEl) {
            durationEl.addEventListener('focus', () => openDurationPopover());
            durationEl.addEventListener('click', () => openDurationPopover());
            durationEl.addEventListener('blur', () => {
                // Не закрываем сразу: клик по степперам уводит фокус с input.
                const sec = resolveManualDurationSec();
                if (sec != null && sec >= DURATION_MIN_SEC && sec <= DURATION_MAX_SEC) {
                    writeDurationToInput(sec);
                    syncPopoverDigits(sec);
                    persistManualDurationSec(sec);
                }
            });
            durationEl.addEventListener('keydown', (ev) => {
                if (ev.key === 'Escape') {
                    closeDurationPopover();
                    durationEl.blur();
                }
                if (ev.key === 'Enter') {
                    ev.preventDefault();
                    const sec = resolveManualDurationSec();
                    if (sec != null) applyDurationSec(sec, { close: true });
                }
            });
        }

        if (durationPopover) {
            durationPopover.addEventListener('mousedown', (ev) => {
                // Не даём input потерять «сессию» до обработки клика по кнопке.
                ev.preventDefault();
            });
            durationPopover.addEventListener('click', (ev) => {
                const stepBtn = ev.target.closest('[data-dur-step]');
                if (stepBtn) {
                    const unit = stepBtn.getAttribute('data-dur-step');
                    const delta = parseInt(stepBtn.getAttribute('data-dur-delta') || '0', 10);
                    let next = popoverDurationSec;
                    if (unit === 'h') next += delta * 3600;
                    else if (unit === 'm') next += delta * 60;
                    else if (unit === 's') next += delta;
                    applyDurationSec(next);
                    return;
                }
                const presetBtn = ev.target.closest('[data-dur-preset]');
                if (presetBtn) {
                    const sec = parseInt(presetBtn.getAttribute('data-dur-preset') || '', 10);
                    if (Number.isFinite(sec)) applyDurationSec(sec, { close: true });
                }
            });
        }

        document.addEventListener('mousedown', (ev) => {
            if (!isDurationPopoverOpen()) return;
            const t = ev.target;
            if (durationPopover && durationPopover.contains(t)) return;
            if (durationEl && (t === durationEl || durationEl.contains(t))) return;
            closeDurationPopover();
        });
        window.addEventListener('resize', () => {
            if (isDurationPopoverOpen()) positionDurationPopover();
        });
        window.addEventListener('scroll', () => {
            if (isDurationPopoverOpen()) positionDurationPopover();
        }, true);

        function applyAmountMode() {
            if (!amountEl) return;
            const mg = parseFloat(panel.dataset.martingaleAmount || '1');
            if (amountFromMartingale) {
                amountEl.value = Number.isFinite(mg) ? String(mg) : '1';
                amountEl.readOnly = true;
                if (amountHintEl) amountHintEl.classList.remove('hidden');
            } else {
                amountEl.readOnly = false;
                if (amountHintEl) amountHintEl.classList.add('hidden');
            }
            updateManualTradePayout();
        }

        function openSettingsModal() {
            if (!settingsModal) return;
            if (flagWaitEl) flagWaitEl.checked = panel.dataset.flagWait === '1';
            if (flagMgEl) flagMgEl.checked = panel.dataset.flagMartingale === '1';
            if (flagAmountMgEl) flagAmountMgEl.checked = panel.dataset.flagAmountMg === '1';
            if (settingsStatusEl) {
                settingsStatusEl.classList.add('hidden');
                settingsStatusEl.textContent = '';
            }
            settingsModal.classList.remove('hidden');
        }

        function closeSettingsModal() {
            if (settingsModal) settingsModal.classList.add('hidden');
        }

        async function saveSettings() {
            if (!settingsUrl || !settingsSaveBtn) return;
            settingsSaveBtn.disabled = true;
            try {
                const res = await fetch(settingsUrl, {
                    method: 'PUT',
                    headers: {
                        'X-CSRF-TOKEN': csrf,
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        manual_respect_wait_previous: !!(flagWaitEl && flagWaitEl.checked),
                        manual_advance_martingale: !!(flagMgEl && flagMgEl.checked),
                        manual_amount_from_martingale: !!(flagAmountMgEl && flagAmountMgEl.checked),
                    }),
                });
                const data = await res.json().catch(() => ({}));
                if (!res.ok || !data.ok) {
                    throw new Error(data.message || data.error || ('HTTP ' + res.status));
                }
                panel.dataset.flagWait = data.manual_respect_wait_previous ? '1' : '0';
                panel.dataset.flagMartingale = data.manual_advance_martingale ? '1' : '0';
                panel.dataset.flagAmountMg = data.manual_amount_from_martingale ? '1' : '0';
                if (data.martingale_amount != null) {
                    panel.dataset.martingaleAmount = String(data.martingale_amount);
                }
                amountFromMartingale = !!data.manual_amount_from_martingale;
                applyAmountMode();
                // Вернуть время (и сумму, если не режим МГ) из localStorage.
                restoreManualPanelPrefs();
                closeSettingsModal();
            } catch (e) {
                if (settingsStatusEl) {
                    settingsStatusEl.classList.remove('hidden');
                    settingsStatusEl.className = 'text-[11px] leading-snug text-rose-300';
                    settingsStatusEl.textContent = e && e.message ? e.message : 'Не удалось сохранить';
                }
            } finally {
                settingsSaveBtn.disabled = false;
            }
        }

        if (settingsBtn) settingsBtn.addEventListener('click', openSettingsModal);
        if (settingsSaveBtn) settingsSaveBtn.addEventListener('click', saveSettings);
        if (settingsModal) {
            settingsModal.querySelectorAll('[data-manual-settings-dismiss]').forEach((el) => {
                el.addEventListener('click', closeSettingsModal);
            });
            document.addEventListener('keydown', (ev) => {
                if (ev.key === 'Escape' && !settingsModal.classList.contains('hidden')) {
                    closeSettingsModal();
                }
            });
        }

        async function placeSide(side) {
            if (placing) return;
            if (!ssidReady) {
                showManualTradeStatus(false, 'SSID не готов — откройте Профиль → Торговля.');
                return;
            }
            if (!currentInstrument) {
                showManualTradeStatus(false, 'Сначала выберите инструмент слева.');
                return;
            }
            let amount = amountEl ? parseFloat(amountEl.value) : NaN;
            // На всякий случай синхронизируем с шагом МГ, если тумблер включён.
            if (amountFromMartingale) {
                const mg = parseFloat(panel.dataset.martingaleAmount || '');
                if (Number.isFinite(mg) && mg >= 1) amount = mg;
            }
            const duration = resolveManualDurationSec();
            if (!Number.isFinite(amount) || amount < 1) {
                showManualTradeStatus(false, 'Укажите сумму ставки (минимум $1).');
                return;
            }
            if (duration == null || !Number.isFinite(duration)) {
                showManualTradeStatus(false, 'Введите время как MM:SS или HH:MM:SS (например 00:05:00).');
                return;
            }
            if (duration < DURATION_MIN_SEC || duration > DURATION_MAX_SEC) {
                showManualTradeStatus(false, 'Время экспирации: от 00:00:03 до 12:00:00.');
                return;
            }

            placing = true;
            setManualTradeBusy(true);
            closeDurationPopover();
            showManualTradeStatus(null, 'Отправка ордера на Pocket Option…');

            try {
                const res = await fetch(orderUrl, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': csrf,
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        instrument: currentInstrument,
                        side: side,
                        amount: amount,
                        duration: duration,
                    }),
                });
                const data = await res.json().catch(() => ({}));
                if (data.ok) {
                    const oid = data.order_id ? (' #' + data.order_id) : '';
                    const acct = data.is_demo ? 'DEMO' : 'REAL';
                    showManualTradeStatus(true, (side === 'CALL' ? 'Купить' : 'Продать')
                        + ': ордер отправлен (' + acct + ')' + oid + '.');
                    // Сразу обновить ленту Сделки PO (paper+mirror уже в БД).
                    if (typeof window.refreshDashPoTrades === 'function') {
                        window.refreshDashPoTrades();
                    }
                    // Сразу подтянуть open_trades → лучи/маркеры на графике (не ждать poll).
                    if (typeof fetchChartTrades === 'function') {
                        fetchChartTrades();
                    }
                } else {
                    let err = data.error || data.message || '';
                    if (!err && data.errors && typeof data.errors === 'object') {
                        err = Object.values(data.errors).flat().join(' ');
                    }
                    if (!err) err = 'Не удалось открыть сделку (HTTP ' + res.status + ').';
                    showManualTradeStatus(false, err);
                }
            } catch (e) {
                showManualTradeStatus(false, 'Сеть/прокси недоступны: ' + (e && e.message ? e.message : 'ошибка'));
            } finally {
                placing = false;
                setManualTradeBusy(false);
            }
        }

        function setManualTradeBusy(busy) {
            if (buyBtn) buyBtn.disabled = busy || !ssidReady;
            if (sellBtn) sellBtn.disabled = busy || !ssidReady;
            if (amountEl) amountEl.disabled = busy;
            if (durationEl) durationEl.disabled = busy;
        }

        function showManualTradeStatus(ok, message) {
            if (!statusEl) return;
            statusEl.classList.remove('hidden', 'border-emerald-800', 'bg-emerald-950/40', 'text-emerald-300',
                'border-rose-800', 'bg-rose-950/40', 'text-rose-300',
                'border-zinc-700', 'bg-zinc-900/50', 'text-zinc-300');
            if (ok === true) {
                statusEl.classList.add('border-emerald-800', 'bg-emerald-950/40', 'text-emerald-300');
            } else if (ok === false) {
                statusEl.classList.add('border-rose-800', 'bg-rose-950/40', 'text-rose-300');
            } else {
                statusEl.classList.add('border-zinc-700', 'bg-zinc-900/50', 'text-zinc-300');
            }
            statusEl.textContent = message || '';
        }

        if (buyBtn) buyBtn.addEventListener('click', () => placeSide('CALL'));
        if (sellBtn) sellBtn.addEventListener('click', () => placeSide('PUT'));

        applyAmountMode();
        // После дефолтов с сервера — время/сумма с прошлого визита (localStorage).
        restoreManualPanelPrefs();
        updateManualTradePayout();
    }

    /** Пересчёт % и $ выплаты по currentInstrument + сумме в панели. */
    function updateManualTradePayout() {
        const pctEl = document.getElementById('manual-trade-payout-pct');
        const totalEl = document.getElementById('manual-trade-payout-total');
        const profitEl = document.getElementById('manual-trade-profit-usd');
        const amountEl = document.getElementById('manual-trade-amount');
        if (!pctEl || !totalEl || !profitEl) return;

        const amount = amountEl ? parseFloat(amountEl.value) : NaN;
        const amt = Number.isFinite(amount) && amount > 0 ? amount : 0;

        let payout = null;
        if (currentInstrument) {
            const span = document.querySelector(
                '.instrument-payout[data-instrument="' + CSS.escape(currentInstrument) + '"]'
            );
            if (span && span.dataset.payout !== '' && span.dataset.payout != null) {
                const n = parseFloat(span.dataset.payout);
                if (Number.isFinite(n)) payout = n;
            }
        }

        if (payout == null) {
            pctEl.textContent = '—';
            profitEl.textContent = '+$0.00';
            totalEl.textContent = '+$0.00';
            return;
        }

        const profit = amt * (payout / 100);
        const total = amt + profit;
        pctEl.textContent = '+' + Math.round(payout) + '%';
        profitEl.textContent = '+$' + profit.toFixed(2);
        totalEl.textContent = '+$' + total.toFixed(2);
    }

    // -------------------------------------------------------------------------
    // Правая колонка: компактная лента зеркалимых на Pocket Option сделок.
    // Источник — GET bots.live-trades.data; на UI показываем только mirrored_*.
    // Клик по карточке переключает график на инструмент сделки (selectInstrument).
    // -------------------------------------------------------------------------
    (function initDashPoTrades() {
        const listEl = document.getElementById('dash-po-trades-list');
        const metaEl = document.getElementById('dash-po-trades-meta');
        if (!listEl || !metaEl || !LIVE_TRADES_URL) return;

        let pollTimer = null;
        let lastRenderedIds = '';

        function escapeHtml(s) {
            return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        }

        function sideLabel(side) {
            const raw = String(side ?? '').toUpperCase();
            if (raw === 'PUT') return 'ВНИЗ';
            if (raw === 'CALL') return 'ВВЕРХ';
            return side ?? '—';
        }

        function sideTone(side) {
            const raw = String(side ?? '').toUpperCase();
            if (raw === 'CALL') return 'text-emerald-400';
            if (raw === 'PUT') return 'text-rose-400';
            return 'text-zinc-300';
        }

        // Компактный бейдж результата (paper или PO).
        function resultBadge(result, title) {
            const r = String(result ?? '').toLowerCase();
            const tip = title ? ` title="${escapeHtml(title).replace(/"/g, '&quot;')}"` : '';
            if (r === 'win') return `<span class="text-emerald-400"${tip}>win</span>`;
            if (r === 'loss') return `<span class="text-rose-400"${tip}>loss</span>`;
            if (r === 'draw') return `<span class="text-amber-400"${tip}>draw</span>`;
            if (r === 'pending' || r === '') return `<span class="text-zinc-500"${tip}>…</span>`;
            return `<span class="text-zinc-400"${tip}>${escapeHtml(r)}</span>`;
        }

        // Итог на PO: учитываем assume_not_opened / ожидание successcloseOrder.
        function poBadge(t) {
            const src = t.mirror_po_result_source ? String(t.mirror_po_result_source) : '';
            const mirrorStatus = t.mirror_status ? String(t.mirror_status) : '';
            if (src === 'po_assume_not_opened' || mirrorStatus === 'assumed_not_opened') {
                return '<span class="text-amber-400" title="PO: открытие не подтверждено">?</span>';
            }
            if (src === 'paper_fallback') {
                return '<span class="text-amber-400" title="PO-итог не получен">err</span>';
            }
            if (!t.mirror_po_result) {
                if (t.mirror_status === 'executed' && t.status === 'open') {
                    return '<span class="text-zinc-500" title="Ждём итог PO">…</span>';
                }
                return '<span class="text-zinc-600">—</span>';
            }
            return resultBadge(t.mirror_po_result, 'Итог Pocket Option');
        }

        function secondsToClock(total) {
            const s = Math.max(0, Math.floor(total));
            const m = Math.floor(s / 60);
            const r = s % 60;
            return String(m).padStart(2, '0') + ':' + String(r).padStart(2, '0');
        }

        // Таймер до экспирации для open; для закрытых — короткий статус.
        function timeLeftHtml(t) {
            if (t.status !== 'open') {
                return '<span class="text-zinc-600">готово</span>';
            }
            if (!t.expiry_ts) return '<span class="text-zinc-600">—</span>';
            const left = t.expiry_ts - Math.floor(Date.now() / 1000);
            if (left <= 0) return '<span class="text-amber-400 font-mono">00:00</span>';
            return '<span class="text-cyan-300 font-mono tabular-nums">' + secondsToClock(left) + '</span>';
        }

        // Реально ушло на PO: markMirror('executed') или settlement assumed_not_opened.
        // mirrored_at ставится и для skipped_* / failed — сам по себе не критерий.
        function isMirroredToPo(t) {
            const st = String(t.mirror_status ?? '').toLowerCase();
            return st === 'executed' || st === 'assumed_not_opened';
        }

        // Имя для selectInstrument: из кнопки в сайдбаре или сам код инструмента.
        function displayNameForInstrument(instrument) {
            const key = String(instrument ?? '');
            if (!key) return key;
            const btn = document.querySelector(
                '.instrument-btn[data-instrument="' + CSS.escape(key) + '"]'
            );
            return (btn && btn.dataset.displayName) ? btn.dataset.displayName : key;
        }

        function renderCards(trades) {
            // Ограничиваем высоту колонки: свежие сверху (API уже latest).
            const mirrored = (trades || []).filter(isMirroredToPo).slice(0, 40);
            // Стабильный ключ: не перерисовываем DOM каждую секунду без нужды
            // (таймеры обновляем через data-expiry-ts в tickTimers).
            const idsKey = mirrored.map((t) =>
                [t.id, t.status, t.paper_result || t.result, t.mirror_po_result || '', t.mirror_status || ''].join(':')
            ).join('|');

            if (idsKey === lastRenderedIds && listEl.querySelector('[data-trade-id]')) {
                tickTimers(listEl);
                return;
            }
            lastRenderedIds = idsKey;

            if (!mirrored.length) {
                listEl.innerHTML = '<div class="px-3 py-6 text-center text-[11px] text-zinc-500">Нет зеркалимых сделок за 2 ч</div>';
                return;
            }

            listEl.innerHTML = mirrored.map((t) => {
                const side = String(t.side ?? '').toUpperCase();
                const isOpen = t.status === 'open';
                const instrument = String(t.instrument ?? '');
                const amount = t.entry_amount != null ? String(t.entry_amount) : '—';
                const bot = escapeHtml(t.bot_name || '—');
                const paper = resultBadge(t.paper_result ?? t.result, 'Paper');
                const po = poBadge(t);
                const ring = isOpen ? 'ring-1 ring-inset ring-sky-800/50' : '';
                return `
                    <button type="button"
                        data-trade-id="${escapeHtml(t.id)}"
                        data-instrument="${escapeHtml(instrument)}"
                        data-expiry-ts="${t.expiry_ts || ''}"
                        data-status="${escapeHtml(t.status || '')}"
                        class="dash-po-trade-card w-full text-left px-3 py-2.5 hover:bg-zinc-800/60 transition ${ring} ${currentInstrument === instrument ? 'bg-blue-950/30' : ''}"
                        title="Открыть ${escapeHtml(instrument)} на графике">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="truncate text-[12px] font-semibold text-zinc-100">${escapeHtml(instrument)}</p>
                                <p class="mt-0.5 truncate text-[10px] text-zinc-500">${bot}</p>
                            </div>
                            <div class="shrink-0 text-right">
                                <p class="text-[11px] font-semibold tabular-nums ${sideTone(side)}">${sideLabel(side)}</p>
                                <p class="mt-0.5 text-[11px] font-mono tabular-nums text-zinc-200">$${escapeHtml(amount)}</p>
                            </div>
                        </div>
                        <div class="mt-1.5 flex items-center justify-between gap-2 text-[10px]">
                            <span class="dash-po-timer">${timeLeftHtml(t)}</span>
                            <span class="tabular-nums text-zinc-500">P ${paper} · PO ${po}</span>
                        </div>
                    </button>`;
            }).join('');
        }

        // Обновляем только countdown без полного re-render списка.
        function tickTimers(root) {
            root.querySelectorAll('.dash-po-trade-card[data-status="open"]').forEach((card) => {
                const timerEl = card.querySelector('.dash-po-timer');
                if (!timerEl) return;
                const ts = Number(card.dataset.expiryTs || 0);
                if (!ts) {
                    timerEl.innerHTML = '<span class="text-zinc-600">—</span>';
                    return;
                }
                const left = ts - Math.floor(Date.now() / 1000);
                if (left <= 0) {
                    timerEl.innerHTML = '<span class="text-amber-400 font-mono">00:00</span>';
                    return;
                }
                timerEl.innerHTML = '<span class="text-cyan-300 font-mono tabular-nums">' + secondsToClock(left) + '</span>';
            });
        }

        // Клик по карточке → переключить инструмент на графике (как в сайдбаре).
        listEl.addEventListener('click', (ev) => {
            const card = ev.target.closest('.dash-po-trade-card');
            if (!card) return;
            const instrument = card.dataset.instrument;
            if (!instrument) return;
            selectInstrument(instrument, displayNameForInstrument(instrument));
            // Подсветка выбранной карточки без ожидания следующего poll.
            listEl.querySelectorAll('.dash-po-trade-card').forEach((el) => {
                el.classList.toggle('bg-blue-950/30', el === card);
            });
        });

        async function refreshOnce() {
            try {
                const res = await fetch(LIVE_TRADES_URL, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    cache: 'no-store',
                });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                const data = await res.json();
                const all = data.trades || [];
                const mirrored = all.filter(isMirroredToPo);
                renderCards(all);
                const openCnt = mirrored.filter((t) => t.status === 'open').length;
                metaEl.textContent = mirrored.length
                    ? `${mirrored.length} · open ${openCnt}`
                    : 'пусто';
            } catch (_) {
                metaEl.textContent = 'ошибка…';
            }
        }

        // Экспорт для initManualTradePanel: обновить ленту сразу после ручного place.
        window.refreshDashPoTrades = refreshOnce;

        refreshOnce();
        pollTimer = setInterval(refreshOnce, 2000);
        window.addEventListener('beforeunload', () => {
            if (pollTimer) clearInterval(pollTimer);
        });
    })();
})();
</script>
@include('partials.chart-indicators-popup-script')
@endsection
