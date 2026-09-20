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
    /* Свободный кроссхейр (как на bots/show): линии в HTML поверх всех оверлеев. */
    #chart-crosshair-hline {
        border-top: 1px dashed #94a3b8;
    }
    #chart-crosshair-vline {
        border-left: 1px dashed #94a3b8;
    }
    .chart-crosshair-axis-label {
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
{{-- Классы ниже — safelist для Tailwind Vite (выставляются из JS / switch «Торговля») --}}
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
                <div class="flex items-center gap-2 min-w-0">
                    <h2
                        id="chart-title"
                        class="font-semibold text-zinc-100 truncate cursor-pointer"
                        title="Кликните, чтобы скопировать название инструмента"
                    >Выберите инструмент</h2>
                    {{-- Payout выбранной пары: те же % и цвет, что в списке слева. --}}
                    <span id="chart-title-payout" class="hidden text-sm font-medium tabular-nums shrink-0" aria-label="Процент выплаты"></span>
                </div>
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
            {{-- Справа: свободная шкала / торговля / статус загрузки. --}}
            <div class="flex items-center gap-3 shrink-0">
                {{-- Выкл по умолчанию: при zoom-out autoScale держит свечи в кадре.
                     Вкл — ЛКМ-drag вверх/вниз как в TradingView (цена может «уехать» при сильном zoom-out). --}}
                <label
                    for="free-price-scale-toggle"
                    class="inline-flex items-center gap-2 cursor-pointer select-none"
                    title="Свободная шкала цены: можно двигать график вверх/вниз мышкой. Выключите, если при уменьшении зума свечи пропадают."
                >
                    <span id="free-price-scale-label" class="text-xs text-zinc-500">Своб. шкала</span>
                    <span class="relative inline-block h-5 w-9 shrink-0">
                        <input type="checkbox" id="free-price-scale-toggle" class="peer sr-only">
                        <span class="block h-5 w-9 rounded-full bg-zinc-700 transition-colors peer-checked:bg-sky-600 peer-focus-visible:ring-2 peer-focus-visible:ring-sky-500/50"></span>
                        <span class="pointer-events-none absolute left-0.5 top-0.5 h-4 w-4 rounded-full bg-zinc-100 shadow transition-transform peer-checked:translate-x-4"></span>
                    </span>
                </label>
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
            {{-- Автономный dashboard-слой событий Sweep / Raid; не смешиваем его с Liquidity Swings. --}}
            <div id="sweep-raid-liquidity-overlay" class="absolute inset-0 z-[6] pointer-events-none overflow-visible hidden"></div>
            <div id="engulfing-liquidity-overlay" class="absolute inset-0 z-[6] pointer-events-none overflow-visible hidden"></div>
            {{-- Оверлей индикатора DGT выше зон OB/FVG (как на странице бота). --}}
            <div id="price-action-overlay" class="absolute inset-0 z-[7] pointer-events-none overflow-visible hidden"></div>
            {{-- SMC Flow (UT Bot): поверх DGT, отдельный слой — не смешиваем с Price Action. --}}
            <div id="smc-flow-overlay" class="absolute inset-0 z-[8] pointer-events-none overflow-visible hidden"></div>
            {{-- PO-стиль: горизонтальные лучи открытых сделок (точка входа + линия до экспирации). --}}
            <div id="po-open-trades-overlay" class="absolute inset-0 z-[8] pointer-events-none overflow-hidden hidden"></div>
            {{-- Paper-сделки ботов: горизонталь entry→expiry на уровне цены входа (как на странице бота). --}}
            <div id="trade-duration-overlay" class="absolute inset-0 z-[8] pointer-events-none overflow-hidden hidden"></div>
            {{-- Названия включённых индикаторов (оверлеи на основной pane + лейблы осцилляторов). --}}
            <div id="classic-indicator-labels" class="absolute inset-0 z-[9] pointer-events-none overflow-hidden hidden"></div>
            {{-- Линейка секунд на оси времени (деления :SS при zoom). --}}
            <div id="chart-time-ruler-overlay" class="absolute inset-0 z-[9] pointer-events-none overflow-visible hidden"></div>
            {{-- Кроссхейр в стиле TradingView: свободное движение (Normal), линии поверх оверлеев. --}}
            <div id="chart-crosshair-overlay" class="absolute inset-0 z-[10] pointer-events-none overflow-visible hidden">
                <div id="chart-crosshair-hline" class="absolute left-0 hidden h-0 w-0"></div>
                <div id="chart-crosshair-vline" class="absolute top-0 hidden h-0 w-0"></div>
                <span id="chart-crosshair-price" class="chart-crosshair-axis-label absolute hidden"></span>
                <span id="chart-crosshair-time" class="chart-crosshair-axis-label absolute hidden"></span>
            </div>
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

@include('partials.chart-dashboard-vite')

@endsection
