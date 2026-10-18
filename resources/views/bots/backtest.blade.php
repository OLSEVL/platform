@extends('layouts.app')
@section('title', $bot->name.' — бэктест')
@section('content')
{{-- На всю ширину контента layout (раньше max-w-5xl сжимал таблицы/сравнение). --}}
<div class="space-y-4 w-full">
    <div class="flex flex-wrap items-baseline justify-between gap-3">
        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
            <h1 class="text-2xl font-semibold text-zinc-100">{{ $bot->name }}</h1>
            <span class="text-sm text-zinc-500">Бэктест</span>
        </div>
        <div class="flex flex-wrap items-center gap-2 text-sm">
            <a href="{{ route('bots.show', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 hover:bg-zinc-700">← График</a>
            <a href="{{ route('bots.settings', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 hover:bg-zinc-700">Настройки</a>
            <a href="{{ route('bots.activity', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 hover:bg-zinc-700">Сигналы и сделки</a>
            <a href="{{ route('bots.index') }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 hover:bg-zinc-700">К списку</a>
        </div>
    </div>

    <p class="text-sm text-zinc-400">
        Прогон текущих настроек бота по истории свечей из БД. Live-сделки, Telegram и зеркало PO не затрагиваются.
        Итог в «юнитах ставки» при заданном payout.
    </p>

    @unless($backtestSupported ?? true)
        <div class="rounded border border-amber-800/50 bg-amber-950/30 px-4 py-3 text-sm text-amber-200">
            Бэктест для типа «{{ $bot->type }}» не поддерживается (LLM / ручной режим).
        </div>
    @else
    @php
        /** Кнопка «?» с текстом расчёта для data-bb-help (общий JS-тултип на странице). */
        $bbHelp = static fn (string $text): string => '<button type="button" class="bb-help-btn" aria-label="Как считается" aria-expanded="false" data-bb-help="'
            .e($text).'">?</button>';
    @endphp
    <style>
        /* Круглый «?» и всплывающая подсказка с формулой расчёта метрики. */
        .bb-help-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 14px;
            height: 14px;
            border-radius: 9999px;
            border: 1px solid rgb(82 82 91);
            background: rgb(39 39 42);
            color: rgb(161 161 170);
            font-size: 9px;
            font-weight: 700;
            line-height: 1;
            cursor: pointer;
            flex-shrink: 0;
            padding: 0;
        }
        .bb-help-btn:hover,
        .bb-help-btn[aria-expanded="true"] {
            border-color: rgb(34 211 238);
            color: rgb(34 211 238);
            background: rgb(24 24 27);
        }
        .bb-help-popover {
            position: fixed;
            z-index: 9999;
            max-width: min(300px, calc(100vw - 16px));
            padding: 8px 10px;
            border-radius: 6px;
            border: 1px solid rgb(63 63 70);
            background: rgb(24 24 27);
            color: rgb(212 212 216);
            font-size: 11px;
            line-height: 1.45;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.45);
        }
        .bb-help-popover.hidden { display: none; }
    </style>
    <div class="rounded border border-zinc-800 bg-zinc-900/80 p-4 space-y-4 text-sm">
        <div>
            <div id="bot-backtest-summary" class="font-medium text-zinc-200">Бэктест не запущен.</div>
            <div id="bot-backtest-meta" class="mt-0.5 text-xs text-zinc-500 break-words"></div>
        </div>

        <div class="flex flex-wrap items-end gap-3">
            <label class="flex flex-col gap-1 text-[10px] uppercase tracking-wide text-zinc-500">
                Период
                <select id="bot-backtest-preset" class="rounded border border-zinc-600 bg-zinc-800 px-2 py-1.5 text-xs text-zinc-100 normal-case tracking-normal min-w-[10rem]">
                    <option value="1">Последний 1 ч</option>
                    <option value="6">Последние 6 ч</option>
                    <option value="24">Последние 24 ч</option>
                    <option value="all" selected>Вся доступная история</option>
                    <option value="custom">Свой диапазон</option>
                </select>
            </label>
            <label id="bot-backtest-from-wrap" class="hidden flex flex-col gap-1 text-[10px] uppercase tracking-wide text-zinc-500">
                С
                <input type="datetime-local" id="bot-backtest-from" class="rounded border border-zinc-600 bg-zinc-800 px-2 py-1.5 text-xs text-zinc-100 normal-case tracking-normal">
            </label>
            <label id="bot-backtest-to-wrap" class="hidden flex flex-col gap-1 text-[10px] uppercase tracking-wide text-zinc-500">
                По
                <input type="datetime-local" id="bot-backtest-to" class="rounded border border-zinc-600 bg-zinc-800 px-2 py-1.5 text-xs text-zinc-100 normal-case tracking-normal">
            </label>
            <label class="flex flex-col gap-1 text-[10px] uppercase tracking-wide text-zinc-500">
                Инструмент
                <select id="bot-backtest-instrument" class="rounded border border-zinc-600 bg-zinc-800 px-2 py-1.5 text-xs text-zinc-100 normal-case tracking-normal min-w-[12rem] max-w-[16rem]">
                    {{-- Пустой value = все активные пары; по умолчанию, если нет ?instrument=. --}}
                    <option value="" @selected($preselectInstrument === '')>Все</option>
                    @foreach($instruments as $inst)
                        <option value="{{ $inst->instrument }}" @selected($preselectInstrument === $inst->instrument)>
                            {{ $inst->display_name ?? $inst->instrument }}
                            @if($inst->current_payout !== null) ({{ number_format((float) $inst->current_payout, 0) }}%)@endif
                        </option>
                    @endforeach
                </select>
            </label>
            <label class="flex flex-col gap-1 text-[10px] uppercase tracking-wide text-zinc-500">
                Payout %
                {{-- 0 = без фильтра по payout (все пары); >0 = мин. payout пары + расчёт PnL --}}
                <input type="number" id="bot-backtest-payout" min="0" max="100" step="1" value="0"
                    class="w-20 rounded border border-zinc-600 bg-zinc-800 px-2 py-1.5 text-xs text-zinc-100 normal-case tracking-normal"
                    title="0 — все пары; больше 0 — только пары с payout не ниже и расчёт PnL">
            </label>
            <button type="button" id="bot-backtest-run-btn"
                class="rounded border border-cyan-700 bg-cyan-900/50 px-4 py-1.5 text-xs font-medium text-cyan-100 hover:bg-cyan-800/60">Прогнать</button>
            <button type="button" id="bot-backtest-cancel-btn"
                class="hidden rounded border border-rose-800/70 bg-rose-950/40 px-4 py-1.5 text-xs font-medium text-rose-200 hover:bg-rose-900/50">Отменить</button>
            {{-- После прогона: открыть график бота с маркерами BT (?backtest_run=&instrument=). --}}
            <a id="bot-backtest-chart-link" href="#" target="_blank" rel="noopener noreferrer"
                class="hidden rounded border border-zinc-600 bg-zinc-800 px-4 py-1.5 text-xs font-medium text-zinc-200 hover:bg-zinc-700">На графике</a>
        </div>

        <div id="bot-backtest-range-hint" class="text-xs text-zinc-500"></div>

        <div id="bot-backtest-progress-wrap" class="hidden">
            <div class="mb-1 flex items-center justify-between text-xs text-zinc-400">
                <span id="bot-backtest-progress-label">Прогон…</span>
                <span id="bot-backtest-progress-pct" class="tabular-nums">0%</span>
            </div>
            <div class="h-1.5 overflow-hidden rounded bg-zinc-800">
                <div id="bot-backtest-progress-bar" class="h-full w-0 bg-cyan-500 transition-[width] duration-300"></div>
            </div>
        </div>

        <div id="bot-backtest-metrics" class="hidden grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-8 gap-2">
            <div class="rounded border border-zinc-800 bg-zinc-950/60 px-3 py-2">
                <div class="text-[10px] text-zinc-500 flex items-center gap-1"><span>Сделок</span>{!! $bbHelp('Число сделок прогона, где удалось определить результат по цене на момент expiry. Если свечи на expiry нет — сделка не создаётся.') !!}</div>
                <div id="bb-metric-trades" class="text-base tabular-nums text-zinc-100">—</div>
            </div>
            <div class="rounded border border-zinc-800 bg-zinc-950/60 px-3 py-2">
                <div class="text-[10px] text-zinc-500 flex items-center gap-1"><span>% побед</span>{!! $bbHelp('Побед ÷ (побед + поражений) × 100%. Ничьи в знаменатель не входят. Считается по всем сделкам прогона в порядке времени входа.') !!}</div>
                <div id="bb-metric-wr" class="text-base tabular-nums text-zinc-100">—</div>
            </div>
            <div class="rounded border border-zinc-800 bg-zinc-950/60 px-3 py-2">
                <div class="text-[10px] text-zinc-500 flex items-center gap-1"><span>Прибыль / убыток</span>{!! $bbHelp('Сумма PnL всех сделок в «юнитах ставки» (1 юнит = одна ставка). Победа: +payout% ÷ 100 (при 80% → +0,8). Поражение: −1. Ничья: 0. Payout задаётся в форме прогона.') !!}</div>
                <div id="bb-metric-pnl" class="text-base tabular-nums text-zinc-100">—</div>
            </div>
            <div class="rounded border border-zinc-800 bg-zinc-950/60 px-3 py-2">
                <div class="text-[10px] text-zinc-500 flex items-center gap-1"><span>Профит-фактор</span>{!! $bbHelp('Сумма PnL побед ÷ |сумма PnL поражений|. Больше 1 — выигрыши перекрывают проигрыши. Если поражений не было, а победы есть — значение не определено (показывается «—»).') !!}</div>
                <div id="bb-metric-pf" class="text-base tabular-nums text-zinc-100">—</div>
            </div>
            <div class="rounded border border-zinc-800 bg-zinc-950/60 px-3 py-2">
                <div class="text-[10px] text-zinc-500 flex items-center gap-1"><span>Макс. просадка</span>{!! $bbHelp('Максимальное падение накопленной equity от локального пика до дна. Equity после каждой сделки = сумма PnL с начала прогона (по времени входа).') !!}</div>
                <div id="bb-metric-dd" class="text-base tabular-nums text-zinc-100">—</div>
            </div>
            <div class="rounded border border-zinc-800 bg-zinc-950/60 px-3 py-2">
                <div class="text-[10px] text-zinc-500 flex items-center gap-1"><span>Победы / поражения / ничьи</span>{!! $bbHelp('Три счётчика: сколько сделок закрылись победой, поражением и ничьёй (цена на expiry совпала с ценой входа).') !!}</div>
                <div id="bb-metric-wld" class="text-base tabular-nums text-zinc-100">—</div>
            </div>
            <div class="rounded border border-zinc-800 bg-zinc-950/60 px-3 py-2">
                <div class="text-[10px] text-zinc-500 flex items-center gap-1"><span>Макс. побед подряд</span>{!! $bbHelp('Самая длинная серия побед подряд по времени входа. Поражение или ничья обрывают серию.') !!}</div>
                <div id="bb-metric-win-streak" class="text-base tabular-nums text-emerald-400">—</div>
            </div>
            <div class="rounded border border-zinc-800 bg-zinc-950/60 px-3 py-2">
                <div class="text-[10px] text-zinc-500 flex items-center gap-1"><span>Макс. поражений подряд</span>{!! $bbHelp('Самая длинная серия поражений подряд по времени входа. Победа или ничья обрывают серию.') !!}</div>
                <div id="bb-metric-loss-streak" class="text-base tabular-nums text-rose-400">—</div>
            </div>
        </div>

        <div id="bot-backtest-equity-wrap" class="hidden">
            <div class="mb-1 text-[10px] tracking-wide text-zinc-500 flex items-center gap-1">
                <span>Equity (юниты ставки)</span>{!! $bbHelp('График накопленной прибыли после каждой сделки. Для отображения точки прореживаются (до ~400), форма кривой сохраняется.') !!}
            </div>
            <canvas id="bot-backtest-equity" height="96" class="w-full max-h-[96px] rounded border border-zinc-800 bg-zinc-950/80"></canvas>
        </div>

        {{-- Сколько раз завершилась серия ровно N побед / поражений (глобально по всем парам). --}}
        <div id="bot-backtest-streak-dist-wrap" class="hidden">
            <div class="mb-1 text-[10px] tracking-wide text-zinc-500 flex items-center gap-1">
                <span>Распределение серий (все пары)</span>{!! $bbHelp('Сколько раз в прогоне завершилась серия ровно N побед или поражений подряд. Сделки всех пар в порядке времени входа; ничья обрывает серию. Пример: «3 плюса» = 2 раза означает две отдельные серии из трёх побед.') !!}
            </div>
            <div class="rounded border border-zinc-800 max-w-md">
                <table class="w-full text-left text-xs">
                    <thead class="bg-zinc-900 text-zinc-500">
                        <tr>
                            <th class="px-2 py-1.5 font-medium">Подряд</th>
                            <th class="px-2 py-1.5 font-medium text-emerald-400/90">Побед (раз)</th>
                            <th class="px-2 py-1.5 font-medium text-rose-400/90">Поражений (раз)</th>
                        </tr>
                    </thead>
                    <tbody id="bot-backtest-streak-dist-body" class="divide-y divide-zinc-800 text-zinc-300"></tbody>
                </table>
            </div>
        </div>

        {{-- Разбивка по парам (режим «Все» и мульти-инструмент). Сортировка — на клиенте. --}}
        <div id="bot-backtest-by-instr-wrap" class="hidden">
            <div class="mb-1 flex flex-wrap items-center gap-2 text-[10px] tracking-wide text-zinc-500">
                <span class="inline-flex items-center gap-1">
                    По парам
                    {!! $bbHelp('Таблица метрик по каждой паре. Сортировку выбирайте справа: прибыль, WIN/LOSS, % побед, серии и т.д.') !!}
                </span>
                <label class="ml-auto inline-flex items-center gap-1.5 normal-case">
                    <span class="text-zinc-600">Сорт.</span>
                    <select id="bot-backtest-by-instr-sort"
                        class="rounded border border-zinc-600 bg-zinc-800 px-2 py-0.5 text-[11px] text-zinc-100"
                        title="Критерий сортировки таблицы по парам">
                        <option value="pnl_desc">Прибыль ↓</option>
                        <option value="pnl_asc">Прибыль ↑</option>
                        <option value="winrate_desc">% побед ↓</option>
                        <option value="winrate_asc">% побед ↑</option>
                        <option value="wins_desc">WIN ↓</option>
                        <option value="wins_asc">WIN ↑</option>
                        <option value="losses_desc">LOSS ↓</option>
                        <option value="losses_asc">LOSS ↑</option>
                        <option value="trades_desc">Сделки ↓</option>
                        <option value="trades_asc">Сделки ↑</option>
                        <option value="max_win_streak_desc">Макс. + ↓</option>
                        <option value="max_win_streak_asc">Макс. + ↑</option>
                        <option value="max_loss_streak_desc">Макс. − ↓</option>
                        <option value="max_loss_streak_asc">Макс. − ↑</option>
                        <option value="name_asc">Пара A→Z</option>
                        <option value="name_desc">Пара Z→A</option>
                    </select>
                </label>
            </div>
            <div class="rounded border border-zinc-800">
                <table class="w-full text-left text-xs">
                    <thead class="bg-zinc-900 text-zinc-500">
                        <tr>
                            <th class="px-2 py-1.5 font-medium">Пара</th>
                            <th class="px-2 py-1.5 font-medium"><span class="inline-flex items-center gap-1">Сд.{!! $bbHelp('Число сделок по этой паре.') !!}</span></th>
                            <th class="px-2 py-1.5 font-medium"><span class="inline-flex items-center gap-1">% побед{!! $bbHelp('Побед по паре ÷ (побед + поражений по паре) × 100%. Ничьи не учитываются.') !!}</span></th>
                            <th class="px-2 py-1.5 font-medium"><span class="inline-flex items-center gap-1">Поб./пор.{!! $bbHelp('Счётчик побед и поражений по этой паре (формат: побед / поражений).') !!}</span></th>
                            <th class="px-2 py-1.5 font-medium"><span class="inline-flex items-center gap-1">Макс. +{!! $bbHelp('Максимум побед подряд только по сделкам этой пары.') !!}</span></th>
                            <th class="px-2 py-1.5 font-medium"><span class="inline-flex items-center gap-1">Макс. −{!! $bbHelp('Максимум поражений подряд только по сделкам этой пары.') !!}</span></th>
                            <th class="px-2 py-1.5 font-medium"><span class="inline-flex items-center gap-1">Прибыль{!! $bbHelp('Сумма PnL всех сделок этой пары в юнитах ставки.') !!}</span></th>
                        </tr>
                    </thead>
                    <tbody id="bot-backtest-by-instr-body" class="divide-y divide-zinc-800 text-zinc-300"></tbody>
                </table>
            </div>
        </div>

        <div id="bot-backtest-trades-wrap" class="hidden space-y-2">
            <div class="flex flex-wrap items-end gap-2">
                <div class="text-[10px] uppercase tracking-wide text-zinc-500 self-center mr-1">Сделки</div>
                <label class="flex flex-col gap-0.5 text-[10px] text-zinc-500">
                    Пара
                    <select id="bot-backtest-trades-instrument" class="rounded border border-zinc-600 bg-zinc-800 px-2 py-1 text-xs text-zinc-100 normal-case min-w-[8rem]">
                        <option value="">Все</option>
                    </select>
                </label>
                <label class="flex flex-col gap-0.5 text-[10px] text-zinc-500">
                    Результат
                    <select id="bot-backtest-trades-result" class="rounded border border-zinc-600 bg-zinc-800 px-2 py-1 text-xs text-zinc-100 normal-case">
                        <option value="">Все</option>
                        <option value="win">WIN</option>
                        <option value="loss">LOSS</option>
                        <option value="draw">DRAW</option>
                    </select>
                </label>
                <span id="bot-backtest-trades-pager-meta" class="text-xs text-zinc-500 self-center tabular-nums"></span>
                <div class="flex gap-1 self-center ml-auto">
                    <button type="button" id="bot-backtest-trades-prev" class="rounded border border-zinc-600 bg-zinc-800 px-2 py-0.5 text-[11px] text-zinc-300 hover:bg-zinc-700">←</button>
                    <button type="button" id="bot-backtest-trades-next" class="rounded border border-zinc-600 bg-zinc-800 px-2 py-0.5 text-[11px] text-zinc-300 hover:bg-zinc-700">→</button>
                </div>
            </div>
            <div class="rounded border border-zinc-800">
                <table class="w-full text-left text-xs">
                    <thead class="bg-zinc-900 text-zinc-500">
                        <tr>
                            <th class="px-2 py-1.5 font-medium">Время</th>
                            <th class="px-2 py-1.5 font-medium">Пара</th>
                            <th class="px-2 py-1.5 font-medium">Side</th>
                            <th class="px-2 py-1.5 font-medium">Результат</th>
                            <th class="px-2 py-1.5 font-medium"><span class="inline-flex items-center gap-1">Прибыль{!! $bbHelp('PnL этой сделки: +payout% ÷ 100 при победе, −1 при поражении, 0 при ничьей.') !!}</span></th>
                        </tr>
                    </thead>
                    <tbody id="bot-backtest-trades-body" class="divide-y divide-zinc-800 text-zinc-300"></tbody>
                </table>
            </div>
        </div>

        {{-- Сравнение двух прогонов. --}}
        <div id="bot-backtest-compare-wrap" class="space-y-2 rounded border border-zinc-800 bg-zinc-950/40 p-3">
            <div class="text-[10px] uppercase tracking-wide text-zinc-500">Сравнить прогоны</div>
            <div class="flex flex-wrap items-end gap-2">
                <label class="flex flex-col gap-0.5 text-[10px] text-zinc-500">
                    A
                    <select id="bot-backtest-compare-a" class="rounded border border-zinc-600 bg-zinc-800 px-2 py-1 text-xs text-zinc-100 min-w-[7rem]"></select>
                </label>
                <label class="flex flex-col gap-0.5 text-[10px] text-zinc-500">
                    B
                    <select id="bot-backtest-compare-b" class="rounded border border-zinc-600 bg-zinc-800 px-2 py-1 text-xs text-zinc-100 min-w-[7rem]"></select>
                </label>
                <button type="button" id="bot-backtest-compare-btn"
                    class="rounded border border-zinc-600 bg-zinc-800 px-3 py-1 text-xs text-zinc-200 hover:bg-zinc-700">Сравнить</button>
            </div>
            <div id="bot-backtest-compare-result" class="hidden text-xs text-zinc-300"></div>
        </div>

        <div id="bot-backtest-queue-hint" class="hidden text-xs text-amber-200/90 break-words"></div>

        <div id="bot-backtest-runs-wrap">
            <div class="mb-1 flex flex-wrap items-center justify-between gap-2">
                <div class="text-[10px] uppercase tracking-wide text-zinc-500">Последние прогоны</div>
                <button type="button" id="bot-backtest-clear-all"
                    class="rounded border border-rose-900/60 bg-rose-950/30 px-2 py-0.5 text-[11px] text-rose-200 hover:bg-rose-900/40"
                    title="Удалить все прогоны этого бота">Очистить все</button>
            </div>
            <ul id="bot-backtest-runs-list" class="space-y-1 text-xs text-zinc-400"></ul>
        </div>
    </div>
    @endunless
</div>

@if($backtestSupported ?? true)
<script>
(function () {
    const BOT_BACKTESTS_URL = @json(route('bots.backtests.index', $bot));
    const BOT_BACKTEST_STORE_URL = @json(route('bots.backtests.store', $bot));
    const BOT_BACKTEST_COMPARE_URL = @json(route('bots.backtests.compare', $bot));
    const BOT_SHOW_URL = @json(route('bots.show', $bot));
    const INSTRUMENT_OPTIONS = @json($instruments->pluck('instrument')->values());
    const TRADES_PER_PAGE = 50;

    const bbSummary = document.getElementById('bot-backtest-summary');
    const bbMeta = document.getElementById('bot-backtest-meta');
    const bbPreset = document.getElementById('bot-backtest-preset');
    const bbFromWrap = document.getElementById('bot-backtest-from-wrap');
    const bbToWrap = document.getElementById('bot-backtest-to-wrap');
    const bbFrom = document.getElementById('bot-backtest-from');
    const bbTo = document.getElementById('bot-backtest-to');
    const bbInstrument = document.getElementById('bot-backtest-instrument');
    const bbPayout = document.getElementById('bot-backtest-payout');
    const bbRunBtn = document.getElementById('bot-backtest-run-btn');
    const bbCancelBtn = document.getElementById('bot-backtest-cancel-btn');
    const bbChartLink = document.getElementById('bot-backtest-chart-link');
    const bbRangeHint = document.getElementById('bot-backtest-range-hint');
    const bbQueueHint = document.getElementById('bot-backtest-queue-hint');
    const bbProgressWrap = document.getElementById('bot-backtest-progress-wrap');
    const bbProgressLabel = document.getElementById('bot-backtest-progress-label');
    const bbProgressPct = document.getElementById('bot-backtest-progress-pct');
    const bbProgressBar = document.getElementById('bot-backtest-progress-bar');
    const bbMetrics = document.getElementById('bot-backtest-metrics');
    const bbEquityWrap = document.getElementById('bot-backtest-equity-wrap');
    const bbEquityCanvas = document.getElementById('bot-backtest-equity');
    const bbStreakDistWrap = document.getElementById('bot-backtest-streak-dist-wrap');
    const bbStreakDistBody = document.getElementById('bot-backtest-streak-dist-body');
    const bbByInstrWrap = document.getElementById('bot-backtest-by-instr-wrap');
    const bbByInstrBody = document.getElementById('bot-backtest-by-instr-body');
    const bbByInstrSort = document.getElementById('bot-backtest-by-instr-sort');
    // Кэш by_instrument текущего прогона — чтобы менять сортировку без перезагрузки summary.
    let bbByInstrCache = null;
    const BB_BY_INSTR_SORT_KEY = 'bot_backtest_by_instr_sort_v1';
    const BB_BY_INSTR_SORT_ALLOWED = new Set([
        'pnl_desc', 'pnl_asc',
        'winrate_desc', 'winrate_asc',
        'wins_desc', 'wins_asc',
        'losses_desc', 'losses_asc',
        'trades_desc', 'trades_asc',
        'max_win_streak_desc', 'max_win_streak_asc',
        'max_loss_streak_desc', 'max_loss_streak_asc',
        'name_asc', 'name_desc',
    ]);
    function hydrateByInstrSortSelect() {
        if (!bbByInstrSort || bbByInstrSort.dataset.hydrated === '1') return;
        let mode = 'pnl_desc';
        try {
            const saved = localStorage.getItem(BB_BY_INSTR_SORT_KEY);
            if (saved && BB_BY_INSTR_SORT_ALLOWED.has(saved)) mode = saved;
        } catch (_) { /* private mode */ }
        bbByInstrSort.value = mode;
        bbByInstrSort.dataset.hydrated = '1';
    }
    function readByInstrSortMode() {
        hydrateByInstrSortSelect();
        const v = bbByInstrSort ? bbByInstrSort.value : 'pnl_desc';
        return BB_BY_INSTR_SORT_ALLOWED.has(v) ? v : 'pnl_desc';
    }
    function persistByInstrSortMode(mode) {
        try { localStorage.setItem(BB_BY_INSTR_SORT_KEY, mode); } catch (_) { /* ignore */ }
    }
    /** Числовое значение поля для сортировки; null winrate → −∞ при desc, +∞ при asc (вниз списка). */
    function byInstrSortValue(row, field, dir) {
        if (field === 'name') return null;
        if (field === 'winrate') {
            const wr = row.winrate;
            if (wr == null || !Number.isFinite(Number(wr))) {
                return dir === 'desc' ? Number.NEGATIVE_INFINITY : Number.POSITIVE_INFINITY;
            }
            return Number(wr);
        }
        const n = Number(row[field] ?? 0);
        return Number.isFinite(n) ? n : 0;
    }
    function sortByInstrumentEntries(entries, mode) {
        const m = BB_BY_INSTR_SORT_ALLOWED.has(mode) ? mode : 'pnl_desc';
        const lastUs = m.lastIndexOf('_');
        const field = m.slice(0, lastUs);
        const dir = m.slice(lastUs + 1);
        const mul = dir === 'asc' ? 1 : -1;
        return entries.slice().sort((a, b) => {
            const [instA, rowA] = a;
            const [instB, rowB] = b;
            if (field === 'name') {
                const cmp = String(instA).localeCompare(String(instB), 'en');
                return dir === 'asc' ? cmp : -cmp;
            }
            const va = byInstrSortValue(rowA, field, dir);
            const vb = byInstrSortValue(rowB, field, dir);
            if (va !== vb) return va < vb ? -mul : mul;
            // Стабильный тай-брейк: имя пары.
            return String(instA).localeCompare(String(instB), 'en');
        });
    }
    const bbTradesWrap = document.getElementById('bot-backtest-trades-wrap');
    const bbTradesBody = document.getElementById('bot-backtest-trades-body');
    const bbTradesInstrument = document.getElementById('bot-backtest-trades-instrument');
    const bbTradesResult = document.getElementById('bot-backtest-trades-result');
    const bbTradesPagerMeta = document.getElementById('bot-backtest-trades-pager-meta');
    const bbTradesPrev = document.getElementById('bot-backtest-trades-prev');
    const bbTradesNext = document.getElementById('bot-backtest-trades-next');
    const bbRunsList = document.getElementById('bot-backtest-runs-list');
    const bbClearAll = document.getElementById('bot-backtest-clear-all');
    const bbCompareA = document.getElementById('bot-backtest-compare-a');
    const bbCompareB = document.getElementById('bot-backtest-compare-b');
    const bbCompareBtn = document.getElementById('bot-backtest-compare-btn');
    const bbCompareResult = document.getElementById('bot-backtest-compare-result');
    const bbCsrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    let backtestActiveRunId = null;
    let backtestPollTimer = null;
    let backtestCandleRange = { min_ts: null, max_ts: null };
    let backtestBusy = false;
    let backtestQueueHint = '';
    let tradesPage = 1;
    let tradesLastPage = 1;
    let tradesTotal = 0;
    let lastCompletedRuns = [];

    // --- Подсказки «?»: один popover на странице, открывается по клику. ---
    let bbHelpPopoverEl = null;
    let bbHelpOpenBtn = null;

    function bbHelpEsc(text) {
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;');
    }

    /** HTML кнопки «?» для динамически собираемых таблиц (сравнение). */
    function bbHelpBtnHtml(text) {
        return `<button type="button" class="bb-help-btn" aria-label="Как считается" aria-expanded="false" data-bb-help="${bbHelpEsc(text)}">?</button>`;
    }

    function bbHelpLabel(label, helpText) {
        return `<span class="inline-flex items-center gap-1">${label}${bbHelpBtnHtml(helpText)}</span>`;
    }

    function ensureBbHelpPopover() {
        if (bbHelpPopoverEl) return bbHelpPopoverEl;
        bbHelpPopoverEl = document.createElement('div');
        bbHelpPopoverEl.id = 'bb-help-popover';
        bbHelpPopoverEl.className = 'bb-help-popover hidden';
        bbHelpPopoverEl.setAttribute('role', 'tooltip');
        document.body.appendChild(bbHelpPopoverEl);
        return bbHelpPopoverEl;
    }

    function closeBbHelpPopover() {
        if (bbHelpPopoverEl) bbHelpPopoverEl.classList.add('hidden');
        if (bbHelpOpenBtn) {
            bbHelpOpenBtn.setAttribute('aria-expanded', 'false');
            bbHelpOpenBtn = null;
        }
    }

    function positionBbHelpPopover(btn) {
        const pop = ensureBbHelpPopover();
        pop.classList.remove('hidden');
        const margin = 8;
        const rect = btn.getBoundingClientRect();
        const popRect = pop.getBoundingClientRect();
        let top = rect.bottom + margin;
        let left = rect.left + rect.width / 2 - popRect.width / 2;
        left = Math.max(margin, Math.min(left, window.innerWidth - popRect.width - margin));
        if (top + popRect.height > window.innerHeight - margin) {
            top = rect.top - popRect.height - margin;
        }
        pop.style.top = `${Math.max(margin, top)}px`;
        pop.style.left = `${left}px`;
    }

    function openBbHelpPopover(btn) {
        const text = btn.getAttribute('data-bb-help') || '';
        const pop = ensureBbHelpPopover();
        if (bbHelpOpenBtn === btn && !pop.classList.contains('hidden')) {
            closeBbHelpPopover();
            return;
        }
        closeBbHelpPopover();
        pop.textContent = text;
        positionBbHelpPopover(btn);
        bbHelpOpenBtn = btn;
        btn.setAttribute('aria-expanded', 'true');
    }

    document.addEventListener('click', (e) => {
        const btn = e.target.closest('.bb-help-btn');
        if (btn) {
            e.preventDefault();
            e.stopPropagation();
            openBbHelpPopover(btn);
            return;
        }
        if (!e.target.closest('#bb-help-popover')) closeBbHelpPopover();
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeBbHelpPopover();
    });
    window.addEventListener('resize', () => {
        if (bbHelpOpenBtn) positionBbHelpPopover(bbHelpOpenBtn);
    });
    window.addEventListener('scroll', () => closeBbHelpPopover(), true);

    function fmtTsLocal(ts) {
        const TF = window.PlatformTimeFormat;
        if (TF && typeof TF.ymdHm === 'function') {
            return TF.ymdHm(ts);
        }
        if (!Number.isFinite(ts) || ts <= 0) return '—';
        const d = new Date(ts * 1000);
        const p = (n) => String(n).padStart(2, '0');
        return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`;
    }

    function tsToDatetimeLocalValue(ts) {
        const TF = window.PlatformTimeFormat;
        if (TF && typeof TF.datetimeLocalValue === 'function') {
            return TF.datetimeLocalValue(ts);
        }
        const d = new Date(ts * 1000);
        const p = (n) => String(n).padStart(2, '0');
        return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`;
    }

    function datetimeLocalToTs(v) {
        if (!v) return null;
        const ms = Date.parse(v);
        return Number.isFinite(ms) ? Math.floor(ms / 1000) : null;
    }

    function updateBacktestPresetUi() {
        const custom = bbPreset && bbPreset.value === 'custom';
        if (bbFromWrap) bbFromWrap.classList.toggle('hidden', !custom);
        if (bbToWrap) bbToWrap.classList.toggle('hidden', !custom);
    }

    function resolveBacktestRange() {
        const preset = bbPreset ? String(bbPreset.value || 'all') : 'all';
        const maxTs = backtestCandleRange.max_ts || Math.floor(Date.now() / 1000);
        const minTs = backtestCandleRange.min_ts || (maxTs - 86400);
        if (preset === 'custom') {
            return { from_ts: datetimeLocalToTs(bbFrom && bbFrom.value), to_ts: datetimeLocalToTs(bbTo && bbTo.value) };
        }
        if (preset === 'all') {
            return { from_ts: minTs, to_ts: maxTs };
        }
        const hours = Math.max(1, parseInt(preset, 10) || 6);
        return { from_ts: Math.max(minTs, maxTs - hours * 3600), to_ts: maxTs };
    }

    function resolveBacktestInstruments() {
        const one = bbInstrument ? String(bbInstrument.value || '') : '';
        if (one === '') {
            return Array.isArray(INSTRUMENT_OPTIONS) ? INSTRUMENT_OPTIONS.slice() : [];
        }
        return [one];
    }

    function setBacktestBusy(busy) {
        backtestBusy = !!busy;
        if (bbRunBtn) bbRunBtn.disabled = backtestBusy;
        if (bbCancelBtn) bbCancelBtn.classList.toggle('hidden', !backtestBusy);
        if (bbProgressWrap) bbProgressWrap.classList.toggle('hidden', !backtestBusy);
    }

    function setBacktestProgress(pct, label) {
        const p = Math.max(0, Math.min(100, Number(pct) || 0));
        if (bbProgressPct) bbProgressPct.textContent = `${p}%`;
        if (bbProgressBar) bbProgressBar.style.width = `${p}%`;
        if (bbProgressLabel && label) bbProgressLabel.textContent = label;
    }

    function stopBacktestPoll() {
        if (backtestPollTimer) {
            clearInterval(backtestPollTimer);
            backtestPollTimer = null;
        }
    }

    function showQueueHint(queue, extra) {
        if (!bbQueueHint) return;
        const parts = [];
        if (extra) parts.push(extra);
        // Не пугаем «зависла» из‑за старых orphaned queued — только если job реально ждёт worker.
        if (queue && queue.stuck_queued && queue.pending_jobs > 0) {
            parts.push(`Прогон ждёт worker (jobs=${queue.pending_jobs}). ${queue.worker_hint || backtestQueueHint}`);
        } else if (queue && queue.worker_hint && queue.connection !== 'sync') {
            backtestQueueHint = queue.worker_hint;
        }
        if (!parts.length) {
            bbQueueHint.classList.add('hidden');
            bbQueueHint.textContent = '';
            return;
        }
        bbQueueHint.textContent = parts.join(' ');
        bbQueueHint.classList.remove('hidden');
    }

    function progressLabelForStatus(status) {
        if (status === 'running') return 'Прогон…';
        if (status === 'queued') return 'В очереди…';
        return status || 'Прогон…';
    }

    /**
     * Опрос статуса прогона + прогрессбар.
     * Нужен и после «Прогнать», и при возврате на страницу (F5 / другая вкладка).
     */
    function startBacktestPoll(runId) {
        const id = Number(runId);
        if (!Number.isFinite(id) || id <= 0) return;
        // Уже следим за этим же прогоном — не сбрасываем таймер.
        if (backtestPollTimer && backtestActiveRunId === id) return;

        stopBacktestPoll();
        backtestActiveRunId = id;
        setBacktestBusy(true);
        let queuedSince = Date.now();

        const tick = async () => {
            try {
                const r = await loadBacktestRun(backtestActiveRunId, { loadTrades: false });
                setBacktestProgress(
                    r.progress_pct || 0,
                    progressLabelForStatus(r.status),
                );
                if (r.status === 'queued' && Date.now() - queuedSince > 120000) {
                    showQueueHint({
                        stuck_queued: true,
                        pending_jobs: 1,
                        worker_hint: backtestQueueHint,
                    }, `Прогон #${r.id} всё ещё в queued.`);
                } else if (r.status === 'running' || r.status === 'completed') {
                    if (bbQueueHint) {
                        bbQueueHint.classList.add('hidden');
                        bbQueueHint.textContent = '';
                    }
                }
                if (['completed', 'failed', 'cancelled'].includes(r.status)) {
                    stopBacktestPoll();
                    await loadBacktestRun(r.id, { loadTrades: true });
                    await loadBacktestIndex({ skipResume: true });
                    setBacktestBusy(false);
                    if (r.status === 'completed') {
                        setBacktestProgress(100, 'Готово');
                    }
                }
            } catch (err) {
                stopBacktestPoll();
                setBacktestBusy(false);
                if (bbSummary) {
                    bbSummary.textContent = err.message || 'Ошибка опроса';
                    bbSummary.classList.add('text-rose-300');
                }
            }
        };

        backtestPollTimer = setInterval(tick, 1000);
        tick();
    }

    /** Если есть queued/running — показать бар и продолжить poll после ухода со страницы. */
    async function resumeActiveBacktestIfAny(runs) {
        if (backtestPollTimer) return;
        // Сначала running (актуальный chunk), иначе новейший queued — список уже orderByDesc id.
        const list = runs || [];
        const active = list.find((r) => r.status === 'running')
            || list.find((r) => r.status === 'queued');
        if (!active || !active.id) return;
        setBacktestProgress(active.progress_pct || 0, progressLabelForStatus(active.status));
        if (bbSummary) {
            bbSummary.textContent = `Прогон #${active.id}: ${progressLabelForStatus(active.status)}`;
            bbSummary.classList.remove('text-emerald-300', 'text-rose-300');
        }
        try {
            await loadBacktestRun(active.id, { loadTrades: false });
        } catch (_) {}
        startBacktestPoll(active.id);
    }

    async function loadBacktestIndex(opts = {}) {
        try {
            const res = await fetch(BOT_BACKTESTS_URL, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (!res.ok) throw new Error('Не удалось загрузить список прогонов');
            const data = await res.json();
            if (data.candle_range) {
                backtestCandleRange = {
                    min_ts: data.candle_range.min_ts != null ? Number(data.candle_range.min_ts) : null,
                    max_ts: data.candle_range.max_ts != null ? Number(data.candle_range.max_ts) : null,
                };
            }
            if (bbRangeHint) {
                if (backtestCandleRange.min_ts && backtestCandleRange.max_ts) {
                    bbRangeHint.textContent = `Доступная история свечей: ${fmtTsLocal(backtestCandleRange.min_ts)} → ${fmtTsLocal(backtestCandleRange.max_ts)}`;
                    if (bbFrom && !bbFrom.value) {
                        bbFrom.value = tsToDatetimeLocalValue(Math.max(backtestCandleRange.min_ts, backtestCandleRange.max_ts - 6 * 3600));
                    }
                    if (bbTo && !bbTo.value) bbTo.value = tsToDatetimeLocalValue(backtestCandleRange.max_ts);
                } else {
                    bbRangeHint.textContent = 'История свечей в БД пока пуста — прогон не даст сделок.';
                }
            }
            if (data.queue) showQueueHint(data.queue);
            const runs = Array.isArray(data.runs) ? data.runs : [];
            lastCompletedRuns = runs.filter((r) => r.status === 'completed');
            fillCompareSelects(lastCompletedRuns);
            renderBacktestRunsList(runs);
            // После F5 / возврата на страницу — подхватить идущий прогон.
            if (!opts.skipResume) {
                await resumeActiveBacktestIfAny(runs);
            }
        } catch (e) {
            if (bbRangeHint) bbRangeHint.textContent = e.message || 'Ошибка загрузки';
        }
    }

    function fillCompareSelects(runs) {
        const opts = runs.map((r) => {
            const s = r.summary_json || r.summary || {};
            const pnl = s.pnl_units != null ? Number(s.pnl_units).toFixed(1) : '—';
            return `<option value="${r.id}">#${r.id} · ${r.trades_total ?? 0} сд. · итог ${pnl}</option>`;
        }).join('');
        [bbCompareA, bbCompareB].forEach((sel, i) => {
            if (!sel) return;
            const prev = sel.value;
            sel.innerHTML = opts || '<option value="">—</option>';
            if (prev && [...sel.options].some((o) => o.value === prev)) sel.value = prev;
            else if (runs[i]) sel.value = String(runs[i].id);
            else if (runs[0]) sel.value = String(runs[0].id);
        });
    }

    function renderBacktestRunsList(runs) {
        if (!bbRunsList) return;
        if (!runs.length) {
            bbRunsList.innerHTML = '<li class="text-zinc-600">Прогонов ещё не было.</li>';
            return;
        }
        bbRunsList.innerHTML = runs.map((r) => {
            const s = r.summary_json || r.summary || {};
            const wr = s.winrate != null ? `${(Number(s.winrate) * 100).toFixed(1)}%` : '—';
            const pnl = s.pnl_units != null ? Number(s.pnl_units).toFixed(2) : '—';
            return `<li class="flex flex-wrap items-center gap-2 rounded border border-zinc-800 bg-zinc-950/50 px-2 py-1.5">
                <button type="button" class="bb-load-run text-cyan-300 hover:text-cyan-200 underline-offset-2 hover:underline" data-run-id="${r.id}">#${r.id}</button>
                <span class="tabular-nums text-zinc-500">${r.status}</span>
                <span>${fmtTsLocal(r.from_ts)} → ${fmtTsLocal(r.to_ts)}</span>
                <span class="tabular-nums">${r.trades_total ?? 0} сд.</span>
                <span>% побед ${wr}</span>
                <span>итог ${pnl}</span>
                <button type="button" class="bb-delete-run ml-auto text-rose-300/80 hover:text-rose-200 text-[11px]" data-run-id="${r.id}" title="Удалить прогон">✕</button>
            </li>`;
        }).join('');
    }

    function renderStreakDistribution(summary) {
        if (!bbStreakDistWrap || !bbStreakDistBody) return;
        const dist = summary && summary.streak_distribution ? summary.streak_distribution : null;
        const wins = dist && dist.wins ? dist.wins : {};
        const losses = dist && dist.losses ? dist.losses : {};
        const lengths = [...new Set([
            ...Object.keys(wins).map((k) => Number(k)),
            ...Object.keys(losses).map((k) => Number(k)),
        ])].filter((n) => Number.isFinite(n) && n > 0).sort((a, b) => a - b);
        if (!lengths.length) {
            bbStreakDistWrap.classList.add('hidden');
            bbStreakDistBody.innerHTML = '';
            return;
        }
        bbStreakDistWrap.classList.remove('hidden');
        bbStreakDistBody.innerHTML = lengths.map((len) => {
            const w = wins[len] != null ? Number(wins[len]) : 0;
            const l = losses[len] != null ? Number(losses[len]) : 0;
            return `<tr class="hover:bg-zinc-900/80">
                <td class="px-2 py-1 tabular-nums">${len}</td>
                <td class="px-2 py-1 tabular-nums text-emerald-400">${w > 0 ? w : '—'}</td>
                <td class="px-2 py-1 tabular-nums text-rose-400">${l > 0 ? l : '—'}</td>
            </tr>`;
        }).join('');
    }

    function renderByInstrument(summary) {
        if (!bbByInstrWrap || !bbByInstrBody) return;
        const by = summary && summary.by_instrument
            ? summary.by_instrument
            : bbByInstrCache;
        if (!by || typeof by !== 'object' || !Object.keys(by).length) {
            bbByInstrCache = null;
            bbByInstrWrap.classList.add('hidden');
            bbByInstrBody.innerHTML = '';
            return;
        }
        bbByInstrCache = by;
        const mode = readByInstrSortMode();
        const rows = sortByInstrumentEntries(Object.entries(by), mode);
        bbByInstrWrap.classList.remove('hidden');
        bbByInstrBody.innerHTML = rows.map(([inst, row]) => {
            const wr = row.winrate != null ? `${(Number(row.winrate) * 100).toFixed(0)}%` : '—';
            const pnl = Number(row.pnl || 0);
            const chartHref = backtestChartHref(backtestActiveRunId, inst);
            const maxW = row.max_win_streak != null ? String(row.max_win_streak) : '—';
            const maxL = row.max_loss_streak != null ? String(row.max_loss_streak) : '—';
            return `<tr class="hover:bg-zinc-900/80">
                <td class="px-2 py-1 truncate max-w-[10rem]">${chartHref ? `<a href="${chartHref}" target="_blank" rel="noopener noreferrer" class="text-cyan-300 hover:underline">${inst}</a>` : inst}</td>
                <td class="px-2 py-1 tabular-nums">${row.trades ?? 0}</td>
                <td class="px-2 py-1 tabular-nums">${wr}</td>
                <td class="px-2 py-1 tabular-nums">${row.wins ?? 0}/${row.losses ?? 0}</td>
                <td class="px-2 py-1 tabular-nums text-emerald-400">${maxW}</td>
                <td class="px-2 py-1 tabular-nums text-rose-400">${maxL}</td>
                <td class="px-2 py-1 tabular-nums ${pnl >= 0 ? 'text-emerald-400' : 'text-rose-400'}">${pnl.toFixed(2)}</td>
            </tr>`;
        }).join('');
    }

    function renderBacktestSummary(run) {
        if (!run) return;
        const summary = run.summary || run.summary_json || {};
        const status = String(run.status || '');
        if (bbSummary) {
            if (status === 'completed') {
                const wr = summary.winrate != null ? `${(Number(summary.winrate) * 100).toFixed(1)}%` : '—';
                bbSummary.textContent = `Прогон #${run.id}: ${summary.trades ?? run.trades_total ?? 0} сделок, % побед ${wr}`;
                bbSummary.classList.remove('text-rose-300');
                bbSummary.classList.add('text-emerald-300');
            } else if (status === 'failed') {
                bbSummary.textContent = `Прогон #${run.id} ошибка: ${run.error_message || 'unknown'}`;
                bbSummary.classList.add('text-rose-300');
                bbSummary.classList.remove('text-emerald-300');
            } else if (status === 'cancelled') {
                bbSummary.textContent = `Прогон #${run.id} отменён.`;
                bbSummary.classList.remove('text-emerald-300', 'text-rose-300');
            } else {
                bbSummary.textContent = `Прогон #${run.id}: ${status}…`;
                bbSummary.classList.remove('text-emerald-300', 'text-rose-300');
            }
        }
        if (bbMeta) {
            bbMeta.textContent = `${fmtTsLocal(run.from_ts)} → ${fmtTsLocal(run.to_ts)} · bars ${run.bars_done ?? 0}/${run.bars_total ?? 0}`;
        }
        if (status === 'completed' && bbMetrics) {
            bbMetrics.classList.remove('hidden');
            const el = (id, v) => { const n = document.getElementById(id); if (n) n.textContent = v; };
            el('bb-metric-trades', String(summary.trades ?? run.trades_total ?? 0));
            el('bb-metric-wr', summary.winrate != null ? `${(Number(summary.winrate) * 100).toFixed(1)}%` : '—');
            const pnl = summary.pnl_units;
            el('bb-metric-pnl', pnl != null ? Number(pnl).toFixed(2) : '—');
            const pnlEl = document.getElementById('bb-metric-pnl');
            if (pnlEl && pnl != null) {
                pnlEl.classList.toggle('text-emerald-400', Number(pnl) > 0);
                pnlEl.classList.toggle('text-rose-400', Number(pnl) < 0);
            }
            el('bb-metric-pf', summary.profit_factor != null ? Number(summary.profit_factor).toFixed(2) : '—');
            el('bb-metric-dd', summary.max_drawdown_units != null ? Number(summary.max_drawdown_units).toFixed(2) : '—');
            el('bb-metric-wld', `${summary.wins ?? 0} / ${summary.losses ?? 0} / ${summary.draws ?? 0}`);
            el('bb-metric-win-streak', summary.max_win_streak != null ? String(summary.max_win_streak) : '—');
            el('bb-metric-loss-streak', summary.max_loss_streak != null ? String(summary.max_loss_streak) : '—');
            drawBacktestEquity(Array.isArray(summary.equity_curve) ? summary.equity_curve : []);
            renderStreakDistribution(summary);
            renderByInstrument(summary);
        }
    }

    function drawBacktestEquity(curve) {
        if (!bbEquityCanvas || !bbEquityWrap) return;
        if (!curve.length) {
            bbEquityWrap.classList.add('hidden');
            return;
        }
        bbEquityWrap.classList.remove('hidden');
        const rect = bbEquityCanvas.getBoundingClientRect();
        const w = Math.max(120, Math.floor(rect.width || bbEquityCanvas.clientWidth || 320));
        const h = 96;
        const dpr = window.devicePixelRatio || 1;
        bbEquityCanvas.width = Math.floor(w * dpr);
        bbEquityCanvas.height = Math.floor(h * dpr);
        bbEquityCanvas.style.width = w + 'px';
        bbEquityCanvas.style.height = h + 'px';
        const ctx = bbEquityCanvas.getContext('2d');
        if (!ctx) return;
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, w, h);
        const vals = curve.map((p) => Number(p.equity));
        let min = Math.min(0, ...vals);
        let max = Math.max(0, ...vals);
        if (min === max) { min -= 1; max += 1; }
        const pad = 4;
        const xAt = (i) => pad + (i / Math.max(1, vals.length - 1)) * (w - pad * 2);
        const yAt = (v) => pad + (1 - (v - min) / (max - min)) * (h - pad * 2);
        ctx.strokeStyle = '#3f3f46';
        ctx.beginPath();
        ctx.moveTo(pad, yAt(0));
        ctx.lineTo(w - pad, yAt(0));
        ctx.stroke();
        ctx.strokeStyle = vals[vals.length - 1] >= 0 ? '#34d399' : '#fb7185';
        ctx.lineWidth = 1.5;
        ctx.beginPath();
        vals.forEach((v, i) => {
            const x = xAt(i);
            const y = yAt(v);
            if (i === 0) ctx.moveTo(x, y);
            else ctx.lineTo(x, y);
        });
        ctx.stroke();
    }

    function renderBacktestTradesTable(trades) {
        if (!bbTradesBody || !bbTradesWrap) return;
        if (!trades.length) {
            bbTradesBody.innerHTML = '<tr><td colspan="5" class="px-2 py-2 text-zinc-600">Нет сделок по фильтру.</td></tr>';
            bbTradesWrap.classList.remove('hidden');
            return;
        }
        bbTradesWrap.classList.remove('hidden');
        bbTradesBody.innerHTML = trades.map((t) => {
            const res = String(t.result || '').toLowerCase();
            const resCls = res === 'win' ? 'text-emerald-400' : (res === 'loss' ? 'text-rose-400' : 'text-zinc-400');
            const inst = t.instrument || '';
            const chartHref = backtestChartHref(backtestActiveRunId, inst);
            return `<tr class="hover:bg-zinc-900/80">
                <td class="px-2 py-1.5 tabular-nums whitespace-nowrap">${fmtTsLocal(Number(t.entry_ts))}</td>
                <td class="px-2 py-1.5 truncate max-w-[10rem]" title="${inst}">
                    ${chartHref ? `<a href="${chartHref}" target="_blank" rel="noopener noreferrer" class="text-cyan-300 hover:text-cyan-200 hover:underline">${inst}</a>` : inst}
                </td>
                <td class="px-2 py-1.5">${t.side || ''}</td>
                <td class="px-2 py-1.5 ${resCls}">${(t.result || '').toUpperCase()}</td>
                <td class="px-2 py-1.5 tabular-nums ${Number(t.pnl) >= 0 ? 'text-emerald-400' : 'text-rose-400'}">${t.pnl != null ? Number(t.pnl).toFixed(2) : '—'}</td>
            </tr>`;
        }).join('');
    }

    function fillTradesInstrumentFilter(instruments, keepValue) {
        if (!bbTradesInstrument) return;
        const cur = keepValue != null ? keepValue : bbTradesInstrument.value;
        bbTradesInstrument.innerHTML = '<option value="">Все</option>' +
            (instruments || []).map((i) => `<option value="${i}">${i}</option>`).join('');
        if (cur && [...bbTradesInstrument.options].some((o) => o.value === cur)) {
            bbTradesInstrument.value = cur;
        }
    }

    function updateTradesPagerUi() {
        if (bbTradesPagerMeta) {
            bbTradesPagerMeta.textContent = tradesTotal
                ? `стр. ${tradesPage}/${tradesLastPage} · ${tradesTotal} сд.`
                : '';
        }
        if (bbTradesPrev) bbTradesPrev.disabled = tradesPage <= 1;
        if (bbTradesNext) bbTradesNext.disabled = tradesPage >= tradesLastPage;
    }

    async function loadBacktestTradesPage(runId, page) {
        tradesPage = Math.max(1, page || 1);
        const params = new URLSearchParams({
            page: String(tradesPage),
            per_page: String(TRADES_PER_PAGE),
        });
        const inst = bbTradesInstrument ? String(bbTradesInstrument.value || '') : '';
        const result = bbTradesResult ? String(bbTradesResult.value || '') : '';
        if (inst) params.set('instrument', inst);
        if (result) params.set('result', result);
        const tr = await fetch(`${BOT_BACKTESTS_URL}/${runId}/trades?${params}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        if (!tr.ok) throw new Error('Не удалось загрузить сделки');
        const td = await tr.json();
        const trades = Array.isArray(td.trades) ? td.trades : [];
        tradesTotal = Number(td.total) || trades.length;
        tradesLastPage = Number(td.last_page) || 1;
        tradesPage = Number(td.page) || tradesPage;
        fillTradesInstrumentFilter(td.instruments || [], inst);
        renderBacktestTradesTable(trades);
        updateTradesPagerUi();
        return trades;
    }

    function backtestChartHref(runId, instrument) {
        if (!runId || !BOT_SHOW_URL) return '';
        const u = new URL(BOT_SHOW_URL, window.location.origin);
        u.searchParams.set('backtest_run', String(runId));
        if (instrument) u.searchParams.set('instrument', instrument);
        return u.pathname + u.search;
    }

    function updateBacktestChartLink(run, trades) {
        if (!bbChartLink) return;
        if (!run || run.status !== 'completed' || !run.id) {
            bbChartLink.classList.add('hidden');
            return;
        }
        let inst = bbInstrument ? String(bbInstrument.value || '') : '';
        if (!inst && Array.isArray(trades) && trades.length) {
            inst = String(trades[0].instrument || '');
        }
        const href = backtestChartHref(run.id, inst);
        if (!href) {
            bbChartLink.classList.add('hidden');
            return;
        }
        bbChartLink.href = href;
        bbChartLink.classList.remove('hidden');
        bbChartLink.title = inst
            ? `Открыть график ${inst} с маркерами прогона #${run.id}`
            : `Открыть график с маркерами прогона #${run.id}`;
    }

    async function loadBacktestRun(runId, { loadTrades = true } = {}) {
        const res = await fetch(`${BOT_BACKTESTS_URL}/${runId}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        if (!res.ok) throw new Error('Не удалось загрузить прогон');
        const data = await res.json();
        const run = data.run || data;
        backtestActiveRunId = run.id;
        renderBacktestSummary(run);
        let trades = [];
        if (loadTrades && (run.status === 'completed' || run.status === 'failed' || run.status === 'cancelled')) {
            trades = await loadBacktestTradesPage(runId, 1);
        }
        updateBacktestChartLink(run, trades);
        return run;
    }

    async function deleteBacktestRun(runId) {
        if (!confirm(`Удалить прогон #${runId}?`)) return;
        const res = await fetch(`${BOT_BACKTESTS_URL}/${runId}`, {
            method: 'DELETE',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': bbCsrf },
        });
        if (!res.ok) throw new Error('Не удалось удалить');
        if (backtestActiveRunId === runId) {
            backtestActiveRunId = null;
            bbByInstrCache = null;
            if (bbSummary) bbSummary.textContent = 'Прогон удалён.';
            if (bbMetrics) bbMetrics.classList.add('hidden');
            if (bbByInstrWrap) bbByInstrWrap.classList.add('hidden');
            if (bbStreakDistWrap) bbStreakDistWrap.classList.add('hidden');
            if (bbTradesWrap) bbTradesWrap.classList.add('hidden');
            if (bbEquityWrap) bbEquityWrap.classList.add('hidden');
            if (bbChartLink) bbChartLink.classList.add('hidden');
        }
        await loadBacktestIndex();
    }

    async function clearAllBacktests() {
        if (!confirm('Удалить ВСЕ прогоны этого бота?')) return;
        const res = await fetch(BOT_BACKTESTS_URL, {
            method: 'DELETE',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': bbCsrf },
        });
        if (!res.ok) throw new Error('Не удалось очистить');
        backtestActiveRunId = null;
        bbByInstrCache = null;
        if (bbSummary) bbSummary.textContent = 'Все прогоны удалены.';
        if (bbMetrics) bbMetrics.classList.add('hidden');
        if (bbByInstrWrap) bbByInstrWrap.classList.add('hidden');
        if (bbStreakDistWrap) bbStreakDistWrap.classList.add('hidden');
        if (bbTradesWrap) bbTradesWrap.classList.add('hidden');
        if (bbEquityWrap) bbEquityWrap.classList.add('hidden');
        await loadBacktestIndex();
    }

    async function runCompare() {
        const a = bbCompareA ? Number(bbCompareA.value) : 0;
        const b = bbCompareB ? Number(bbCompareB.value) : 0;
        if (!a || !b || a === b) {
            if (bbCompareResult) {
                bbCompareResult.classList.remove('hidden');
                bbCompareResult.textContent = 'Выберите два разных прогона.';
            }
            return;
        }
        const res = await fetch(`${BOT_BACKTEST_COMPARE_URL}?a=${a}&b=${b}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        if (!res.ok) throw new Error('Сравнение недоступно');
        const data = await res.json();
        const ma = data.a?.metrics || {};
        const mb = data.b?.metrics || {};
        const d = data.delta || {};
        const pct = (v) => (v != null ? `${(Number(v) * 100).toFixed(1)}%` : '—');
        const num = (v, dig = 2) => (v != null ? Number(v).toFixed(dig) : '—');
        const deltaCls = (v) => (v == null ? '' : (Number(v) > 0 ? 'text-emerald-400' : (Number(v) < 0 ? 'text-rose-400' : '')));
        if (bbCompareResult) {
            bbCompareResult.classList.remove('hidden');
            bbCompareResult.innerHTML = `
                <table class="w-full text-left">
                    <thead><tr class="text-zinc-500"><th class="py-1 pr-2"></th><th class="pr-2">A #${a}</th><th class="pr-2">B #${b}</th><th>Δ (B−A)</th></tr></thead>
                    <tbody>
                        <tr><td class="py-0.5 text-zinc-500">${bbHelpLabel('Сделок', 'Число сделок прогона.')}</td><td>${ma.trades ?? '—'}</td><td>${mb.trades ?? '—'}</td><td class="${deltaCls(d.trades)}">${num(d.trades, 0)}</td></tr>
                        <tr><td class="py-0.5 text-zinc-500">${bbHelpLabel('% побед', 'Побед ÷ (побед + поражений) × 100%. Ничьи не входят.')}</td><td>${pct(ma.winrate)}</td><td>${pct(mb.winrate)}</td><td class="${deltaCls(d.winrate)}">${d.winrate != null ? (Number(d.winrate) * 100).toFixed(1) + ' п.п.' : '—'}</td></tr>
                        <tr><td class="py-0.5 text-zinc-500">${bbHelpLabel('Прибыль / убыток', 'Сумма PnL в юнитах ставки: победа +payout% ÷ 100, поражение −1, ничья 0.')}</td><td>${num(ma.pnl_units)}</td><td>${num(mb.pnl_units)}</td><td class="${deltaCls(d.pnl_units)}">${num(d.pnl_units)}</td></tr>
                        <tr><td class="py-0.5 text-zinc-500">${bbHelpLabel('Профит-фактор', 'Сумма PnL побед ÷ |сумма PnL поражений|.')}</td><td>${num(ma.profit_factor)}</td><td>${num(mb.profit_factor)}</td><td class="${deltaCls(d.profit_factor)}">${num(d.profit_factor)}</td></tr>
                        <tr><td class="py-0.5 text-zinc-500">${bbHelpLabel('Макс. просадка', 'Макс. падение equity от пика до дна по сделкам прогона.')}</td><td>${num(ma.max_drawdown_units)}</td><td>${num(mb.max_drawdown_units)}</td><td class="${deltaCls(d.max_drawdown_units)}">${num(d.max_drawdown_units)}</td></tr>
                    </tbody>
                </table>`;
        }
    }

    async function startBacktest() {
        if (backtestBusy) return;
        const range = resolveBacktestRange();
        if (!range.from_ts || !range.to_ts || range.to_ts <= range.from_ts) {
            if (bbSummary) {
                bbSummary.textContent = 'Укажите корректный период (from < to).';
                bbSummary.classList.add('text-rose-300');
            }
            return;
        }
        const instruments = resolveBacktestInstruments();
        if (!instruments.length) {
            if (bbSummary) {
                bbSummary.textContent = 'Выберите инструмент.';
                bbSummary.classList.add('text-rose-300');
            }
            return;
        }

        // 0 — все пары; не используем || fallback, иначе 0 превращается в 80.
        const payoutRaw = Number(bbPayout && bbPayout.value);
        const payout = Math.max(0, Math.min(100, Number.isFinite(payoutRaw) ? payoutRaw : 0));
        const spanSec = range.to_ts - range.from_ts;
        const useSync = instruments.length === 1 && spanSec <= 6 * 3600;

        setBacktestBusy(true);
        setBacktestProgress(useSync ? 15 : 5, useSync ? 'Синхронный прогон…' : 'В очереди (backtest)…');
        if (bbSummary) {
            bbSummary.textContent = 'Запуск бэктеста…';
            bbSummary.classList.remove('text-emerald-300', 'text-rose-300');
        }
        try {
            const res = await fetch(BOT_BACKTEST_STORE_URL, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': bbCsrf,
                },
                body: JSON.stringify({
                    from_ts: range.from_ts,
                    to_ts: range.to_ts,
                    instruments,
                    payout,
                    sync: useSync,
                }),
            });
            const payload = await res.json().catch(() => ({}));
            if (!res.ok) throw new Error(payload.message || `Ошибка ${res.status}`);
            const run = payload.run || payload;
            backtestActiveRunId = run.id;
            if (payload.queue) {
                showQueueHint(payload.queue, payload.queued
                    ? `Прогон #${run.id} в очереди «${payload.queue.queue_name || 'backtest'}».`
                    : null);
            }
            renderBacktestSummary(run);
            if (!payload.queued || run.status === 'completed') {
                setBacktestProgress(100, 'Готово');
                await loadBacktestRun(run.id);
                await loadBacktestIndex({ skipResume: true });
                setBacktestBusy(false);
                return;
            }
            setBacktestProgress(run.progress_pct || 5, progressLabelForStatus(run.status));
            startBacktestPoll(run.id);
        } catch (e) {
            setBacktestBusy(false);
            if (bbSummary) {
                bbSummary.textContent = e.message || 'Не удалось запустить бэктест';
                bbSummary.classList.add('text-rose-300');
            }
        }
    }

    async function cancelBacktest() {
        if (!backtestActiveRunId) return;
        try {
            await fetch(`${BOT_BACKTESTS_URL}/${backtestActiveRunId}/cancel`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': bbCsrf },
            });
        } catch (_) {}
        stopBacktestPoll();
        setBacktestBusy(false);
        await loadBacktestRun(backtestActiveRunId).catch(() => {});
    }

    if (bbPreset) bbPreset.addEventListener('change', updateBacktestPresetUi);
    // Смена сортировки «По парам» — мгновенно, без повторного запроса summary.
    if (bbByInstrSort) {
        hydrateByInstrSortSelect();
        bbByInstrSort.addEventListener('change', () => {
            const mode = readByInstrSortMode();
            persistByInstrSortMode(mode);
            if (bbByInstrCache) renderByInstrument({ by_instrument: bbByInstrCache });
        });
    }
    if (bbRunBtn) bbRunBtn.addEventListener('click', startBacktest);
    if (bbCancelBtn) bbCancelBtn.addEventListener('click', cancelBacktest);
    if (bbClearAll) bbClearAll.addEventListener('click', () => clearAllBacktests().catch((e) => alert(e.message)));
    if (bbCompareBtn) bbCompareBtn.addEventListener('click', () => runCompare().catch((e) => alert(e.message)));
    if (bbTradesPrev) bbTradesPrev.addEventListener('click', () => {
        if (backtestActiveRunId && tradesPage > 1) loadBacktestTradesPage(backtestActiveRunId, tradesPage - 1).catch(() => {});
    });
    if (bbTradesNext) bbTradesNext.addEventListener('click', () => {
        if (backtestActiveRunId && tradesPage < tradesLastPage) loadBacktestTradesPage(backtestActiveRunId, tradesPage + 1).catch(() => {});
    });
    if (bbTradesInstrument) bbTradesInstrument.addEventListener('change', () => {
        if (backtestActiveRunId) loadBacktestTradesPage(backtestActiveRunId, 1).catch(() => {});
    });
    if (bbTradesResult) bbTradesResult.addEventListener('change', () => {
        if (backtestActiveRunId) loadBacktestTradesPage(backtestActiveRunId, 1).catch(() => {});
    });
    if (bbRunsList) {
        bbRunsList.addEventListener('click', (e) => {
            const del = e.target.closest('.bb-delete-run');
            if (del) {
                const id = Number(del.dataset.runId);
                if (Number.isFinite(id)) deleteBacktestRun(id).catch((err) => alert(err.message));
                return;
            }
            const btn = e.target.closest('.bb-load-run');
            if (!btn) return;
            const id = Number(btn.dataset.runId);
            if (!Number.isFinite(id)) return;
            loadBacktestRun(id).catch((err) => {
                if (bbSummary) {
                    bbSummary.textContent = err.message || 'Ошибка загрузки';
                    bbSummary.classList.add('text-rose-300');
                }
            });
        });
    }
    updateBacktestPresetUi();
    loadBacktestIndex();
})();
</script>
@endif
@endsection
