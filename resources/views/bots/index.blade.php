@extends('layouts.app')
@section('title', 'Боты')
@section('content')
@php
    // Каталог алгоритмов по type — один раз для всех строк таблицы (попап читает отсюда).
    $algoCatalog = [];
    foreach ($bots as $b) {
        $t = (string) $b->type;
        if (! isset($algoCatalog[$t])) {
            $algoCatalog[$t] = \App\Support\BotAlgorithmSummary::meta($t);
        }
    }
@endphp
<div class="space-y-5">
    <div class="flex items-center justify-between gap-3">
        <h1 class="text-2xl font-semibold">Обзор ботов</h1>
        <a href="{{ route('bots.analytics') }}" class="text-sm text-blue-400 hover:text-blue-300">Открыть аналитику</a>
    </div>

    @if($bots->isEmpty())
        <div class="rounded border border-zinc-800 bg-zinc-900/60 p-4 text-zinc-400">
            Боты пока не созданы. Используйте команду <code>php artisan bots:bootstrap</code> для быстрого старта.
        </div>
    @else
        <div class="overflow-x-auto rounded border border-zinc-800">
            <table class="min-w-full text-sm">
                <thead class="bg-zinc-900 text-zinc-400 uppercase text-xs">
                    <tr>
                        {{-- ID слева — удобно сопоставлять с логами / artisan --bot_id. --}}
                        <th class="px-3 py-3 text-left whitespace-nowrap">ID</th>
                        <th class="px-4 py-3 text-left whitespace-nowrap">Бот</th>
                        <th class="px-3 py-3 text-left whitespace-nowrap">Действия</th>
                        <th class="px-4 py-3 text-left">Тип</th>
                        <th class="px-4 py-3 text-left min-w-[14rem]">Алгоритм</th>
                        <th class="px-4 py-3 text-left">Статус</th>
                        {{-- Per-bot flag config.mirror_trades_enabled (default off if missing). --}}
                        <th class="px-4 py-3 text-left">Зеркало PO</th>
                        <th class="px-4 py-3 text-left">Сделок</th>
                        <th class="px-4 py-3 text-left">Win / Loss</th>
                        <th class="px-4 py-3 text-left">Открыто</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-800 bg-zinc-950/40">
                    @foreach($bots as $bot)
                        @php
                            $wlTotal = (int) ($bot->wins_count + $bot->losses_count);
                            $winrate = $wlTotal > 0 ? round(($bot->wins_count * 100) / $wlTotal, 1) : null;
                            $algoShort = $algoCatalog[(string) $bot->type]['short'] ?? '';
                            // Размер кнопок как раньше (px-2 py-0.5); w-full только для выравнивания колонок сетки.
                            $btnClass = 'inline-flex w-full items-center justify-center whitespace-nowrap rounded border border-zinc-600 bg-zinc-800/80 px-2 py-0.5 text-[11px] font-medium text-zinc-300 transition-colors hover:border-zinc-500 hover:bg-zinc-700 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/40';
                        @endphp
                        <tr class="hover:bg-zinc-900/50">
                            <td class="px-3 py-3 tabular-nums text-zinc-500 align-middle">{{ $bot->id }}</td>
                            <td class="px-4 py-3 align-middle whitespace-nowrap">
                                <a href="{{ route('bots.show', $bot) }}" class="text-zinc-100 hover:text-blue-300">{{ $bot->name }}</a>
                            </td>
                            <td class="px-3 py-3 align-middle">
                                <div class="grid grid-cols-[5.5rem_7.5rem_6.5rem_5.5rem_7rem] gap-1">
                                    {{-- Карточки: live open-сделки; доступны всем типам ботов (пустая сетка, если сделок нет). --}}
                                    <a href="{{ route('bots.cards', $bot) }}" class="{{ $btnClass }}">Карточки</a>
                                    <a href="{{ route('bots.activity', $bot) }}" class="{{ $btnClass }}">Сигналы и сделки</a>
                                    <a href="{{ route('bots.analytics.pairs', ['bot_id' => $bot->id]) }}" class="{{ $btnClass }}">Аналитика пар</a>
                                    <a href="{{ route('bots.settings', $bot) }}" class="{{ $btnClass }}">Настройки</a>
                                    <a href="{{ route('bots.logs', $bot) }}" class="{{ $btnClass }}">Логи и метрики</a>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-zinc-300 align-middle">{{ $bot->type }}</td>
                            <td class="px-4 py-1 min-w-[16rem] max-w-sm lg:max-w-lg align-middle">
                                {{-- Без переноса сразу после <button>: whitespace-pre-line иначе рисует пустую первую строку (= «отступ сверху»). --}}
                                <button type="button"
                                    class="algo-open-btn block w-full text-left text-[12px] leading-snug text-zinc-400 whitespace-pre-line underline decoration-zinc-600 decoration-dotted underline-offset-2 transition-colors hover:text-blue-300 hover:decoration-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-500/40 rounded p-0 m-0"
                                    data-algo-type="{{ $bot->type }}"
                                    data-algo-bot="{{ $bot->name }}"
                                    data-algo-id="{{ $bot->id }}"
                                    title="Открыть полное описание">{{ $algoShort }}</button>
                            </td>
                            <td class="px-4 py-3 align-middle">
                                @if($bot->is_active)
                                    <span class="text-emerald-400">active</span>
                                @else
                                    <span class="text-zinc-500">inactive</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 align-middle">
                                @if($bot->isMirrorTradesEnabled())
                                    <span class="text-emerald-400">вкл</span>
                                @else
                                    <span class="text-zinc-500">выкл</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-zinc-300 align-middle">{{ $bot->trades_total }}</td>
                            <td class="px-4 py-3 text-zinc-300 align-middle">
                                {{ $bot->wins_count }} / {{ $bot->losses_count }}
                                @if($winrate !== null)
                                    <span class="text-zinc-500">({{ $winrate }}%)</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-zinc-300 align-middle">{{ $bot->open_count }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

{{-- Попап: полное описание + SVG-схема с примером. --}}
<div id="algo-modal" class="fixed inset-0 z-50 hidden" aria-hidden="true">
    <div id="algo-modal-backdrop" class="absolute inset-0 bg-black/70"></div>
    <div class="relative flex min-h-full items-center justify-center p-4">
        <div class="relative w-full max-w-2xl overflow-hidden rounded-xl border border-zinc-700 bg-zinc-900 shadow-2xl"
            role="dialog" aria-modal="true" aria-labelledby="algo-modal-title">
            <button type="button" id="algo-modal-close"
                class="absolute right-3 top-3 z-10 rounded bg-zinc-800/90 px-2 py-1 text-lg leading-none text-zinc-300 hover:bg-zinc-700 hover:text-white"
                aria-label="Закрыть">×</button>

            <div class="max-h-[85vh] overflow-y-auto p-5 pr-12">
                <p id="algo-modal-bot" class="text-xs text-zinc-500"></p>
                <h2 id="algo-modal-title" class="mt-1 text-lg font-semibold text-zinc-100"></h2>
                <p id="algo-modal-type" class="mt-1 font-mono text-[11px] text-zinc-500"></p>

                {{-- Картинка-схема с примером CALL/PUT. --}}
                <div class="mt-4 overflow-hidden rounded-lg border border-zinc-700/80 bg-zinc-950/80" id="algo-modal-diagram"></div>

                <div class="mt-4 rounded-lg border border-zinc-700/80 bg-zinc-950/60 p-3">
                    <div class="text-[10px] font-semibold uppercase tracking-wide text-zinc-500">Кратко</div>
                    <p id="algo-modal-short" class="mt-1 text-sm leading-snug text-zinc-300 whitespace-pre-line"></p>
                </div>

                <div class="mt-4 space-y-4" id="algo-modal-full"></div>
            </div>
        </div>
    </div>
</div>

@include('bots._algo-diagrams')
<script type="application/json" id="bot-algo-catalog">@json($algoCatalog)</script>
<script>
(() => {
    const catalogEl = document.getElementById('bot-algo-catalog');
    let catalog = {};
    try {
        catalog = catalogEl ? JSON.parse(catalogEl.textContent || '{}') : {};
    } catch (e) {
        catalog = {};
    }

    const modal = document.getElementById('algo-modal');
    if (!modal) return;

    const backdrop = document.getElementById('algo-modal-backdrop');
    const btnClose = document.getElementById('algo-modal-close');
    const elBot = document.getElementById('algo-modal-bot');
    const elTitle = document.getElementById('algo-modal-title');
    const elType = document.getElementById('algo-modal-type');
    const elShort = document.getElementById('algo-modal-short');
    const elFull = document.getElementById('algo-modal-full');
    const elDiagram = document.getElementById('algo-modal-diagram');
    const diagrams = window.ALGO_DIAGRAMS || {};

    function renderDiagram(key) {
        const fn = diagrams[key] || diagrams.generic;
        if (typeof fn !== 'function') {
            elDiagram.innerHTML = '';
            return;
        }
        // Вызов метода с корректным this (нужен для _wrap / _candle).
        elDiagram.innerHTML = fn.call(diagrams);
    }

    function openModal(type, botName, botId) {
        const meta = catalog[type] || {
            title: type,
            short: 'Описание не найдено.',
            sections: [{ h: 'Описание', p: 'Для типа «' + type + '» нет записи в каталоге.' }],
            diagram: 'generic',
        };

        elBot.textContent = botName
            ? ('Бот #' + botId + ' · ' + botName)
            : ('Бот #' + botId);
        elTitle.textContent = meta.title || type;
        elType.textContent = type;
        elShort.textContent = meta.short || '';

        renderDiagram(meta.diagram || 'generic');

        // Секции: Идея / CALL / PUT / Пример / Важно.
        elFull.innerHTML = '';
        const sections = Array.isArray(meta.sections) ? meta.sections : [];
        if (sections.length === 0 && Array.isArray(meta.full)) {
            // Обратная совместимость со старым форматом full[].
            meta.full.forEach((text) => {
                const p = document.createElement('p');
                p.className = 'text-sm leading-relaxed text-zinc-300';
                p.textContent = text;
                elFull.appendChild(p);
            });
        } else {
            sections.forEach((sec) => {
                const box = document.createElement('div');
                box.className = 'rounded-lg border border-zinc-800 bg-zinc-950/40 p-3';
                const h = document.createElement('div');
                h.className = 'text-[10px] font-semibold uppercase tracking-wide text-zinc-500';
                h.textContent = sec.h || '';
                const p = document.createElement('p');
                p.className = 'mt-1.5 text-sm leading-relaxed text-zinc-300';
                p.textContent = sec.p || '';
                box.appendChild(h);
                box.appendChild(p);
                elFull.appendChild(box);
            });
        }

        modal.classList.remove('hidden');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('overflow-hidden');
    }

    function closeModal() {
        modal.classList.add('hidden');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('overflow-hidden');
    }

    document.querySelectorAll('.algo-open-btn').forEach((btn) => {
        btn.addEventListener('click', () => {
            openModal(
                btn.getAttribute('data-algo-type') || '',
                btn.getAttribute('data-algo-bot') || '',
                btn.getAttribute('data-algo-id') || ''
            );
        });
    });

    backdrop?.addEventListener('click', closeModal);
    btnClose?.addEventListener('click', closeModal);
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !modal.classList.contains('hidden')) {
            closeModal();
        }
    });
})();
</script>
@endsection
