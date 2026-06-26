@extends('layouts.app')

@section('title', 'Настройки')

@section('main_class', 'w-full max-w-none flex-1 min-h-0 overflow-y-auto p-0 m-0')

@section('content')
<div class="w-full min-w-0 px-3 sm:px-4 md:px-6 py-4">
    <h1 class="text-2xl font-bold text-zinc-100 mb-6">Настройки</h1>

    <div class="mb-6">
        <a href="{{ route('settings.processes.index') }}" class="inline-flex items-center gap-2 rounded-lg border border-indigo-800/60 bg-indigo-950/30 px-4 py-2 text-sm text-indigo-200 hover:bg-indigo-950/50 transition-colors">
            Диагностика процессов
            <span class="text-indigo-400/70 text-xs">scan, queue, proxy, ingest</span>
        </a>
    </div>

    @if(session('success'))
        <p class="mb-4 p-3 bg-emerald-950/60 border border-emerald-800 text-emerald-200 rounded">{{ session('success') }}</p>
    @endif

    @if(session('error'))
        <p class="mb-4 p-3 bg-red-950/60 border border-red-800 text-red-200 rounded">{{ session('error') }}</p>
    @endif

    @php
        $poolCap = $proxyPoolCapacity ?? [];
        $poolExhausted = (bool) ($poolCap['is_exhausted'] ?? false);
        $onDeadCount = (int) ($poolCap['instruments_on_dead_proxy'] ?? 0);
    @endphp
    @if($poolExhausted || $onDeadCount > 0)
        <div class="mb-4 p-4 rounded-lg border {{ $poolExhausted ? 'bg-amber-950/50 border-amber-800' : 'bg-rose-950/40 border-rose-900/80' }}">
            <p class="text-sm font-medium {{ $poolExhausted ? 'text-amber-200' : 'text-rose-200' }}">
                @if($poolExhausted)
                    Пул прокси исчерпан
                @else
                    Есть инструменты на dead-прокси
                @endif
            </p>
            <p class="text-xs {{ $poolExhausted ? 'text-amber-300/90' : 'text-rose-300/90' }} mt-2">
                Активных инструментов: <strong>{{ $poolCap['active_instruments'] ?? 0 }}</strong>,
                ready-прокси: <strong>{{ $poolCap['ready_proxies'] ?? 0 }}</strong>,
                лимит: <strong>{{ $poolCap['max_per_proxy'] ?? 1 }}</strong> инстр./прокси,
                слотов всего: <strong>{{ $poolCap['total_slots'] ?? 0 }}</strong>,
                занято на ready: <strong>{{ $poolCap['used_slots_on_ready'] ?? 0 }}</strong>,
                <span class="{{ $poolExhausted ? 'text-amber-100' : '' }}">свободно: <strong>{{ $poolCap['free_slots'] ?? 0 }}</strong></span>.
                @if($onDeadCount > 0)
                    На dead-прокси: <strong>{{ $onDeadCount }}</strong> инстр. — «Обновить прокси» или «Реанимировать» + замена.
                @endif
            </p>
            @if($poolExhausted)
                <p class="text-xs text-amber-400/80 mt-2">
                    «Пометить bad + заменить» не оставит инструмент на dead без замены, но при 0 свободных слотов замена невозможна — расширьте пул.
                </p>
            @endif
        </div>
    @endif

    <div class="bg-zinc-900 border border-zinc-800 rounded-lg shadow-lg shadow-black/20 p-5">
        <h2 class="text-lg font-semibold text-zinc-100 mb-2">Прокси Webshare</h2>
        <p class="text-sm text-zinc-400 mb-4">
            Кнопка удаляет все старые endpoint из БД, загружает актуальный список из Webshare API и назначает прокси всем активным инструментам.
            Если прокси меньше, чем активных инструментов, назначение идёт по кругу.
        </p>
        <div class="mb-4 text-sm text-zinc-300">
            Всего в пуле: <span class="text-zinc-100 font-medium">{{ $proxyStats['total'] ?? 0 }}</span>,
            active: <span class="text-emerald-400">{{ $proxyStats['active'] ?? 0 }}</span>,
            dead: <span class="text-rose-400">{{ $proxyStats['dead'] ?? 0 }}</span>.
            <span class="block mt-1 text-zinc-500">
                Ёмкость назначения: свободно
                <span class="{{ $poolExhausted ? 'text-amber-400 font-medium' : 'text-emerald-400' }}">{{ $poolCap['free_slots'] ?? 0 }}</span>
                / {{ $poolCap['total_slots'] ?? 0 }} слотов
                ({{ $poolCap['ready_proxies'] ?? 0 }} ready × {{ $poolCap['max_per_proxy'] ?? 1 }} инстр.).
            </span>
        </div>
        <form action="{{ route('settings.proxies.refresh') }}" method="POST">
            @csrf
            <button type="submit" class="inline-flex items-center px-4 py-2 rounded bg-blue-600 hover:bg-blue-500 text-white font-medium">
                Обновить прокси
            </button>
        </form>
    </div>

    <div class="bg-zinc-900 border border-zinc-800 rounded-lg shadow-lg shadow-black/20 p-5 mt-6">
        <h2 class="text-lg font-semibold text-zinc-100 mb-2">Ротация прокси</h2>
        <p class="text-sm text-zinc-400 mb-4">
            Когда опция включена, API при выдаче инструментов автоматически переназначает прокси для инструментов
            без рабочего endpoint (например, dead/не назначен). Опционально можно помечать прокси dead,
            если колонка «Событие PO» в «Инструментах» не обновлялась дольше заданного порога.
        </p>
        <form action="{{ route('settings.proxies.auto-rotation') }}" method="POST" class="flex flex-col items-start gap-3">
            @csrf
            <label class="inline-flex items-center gap-2 text-zinc-200 min-h-[40px]">
                <input type="checkbox" name="proxy_auto_rotation_enabled" value="1" {{ !empty($proxyAutoRotationEnabled) ? 'checked' : '' }} class="rounded border-zinc-600 bg-zinc-800 text-blue-500">
                Автоматическая ротация прокси
            </label>
            <label class="inline-flex items-center gap-2 text-zinc-200 min-h-[40px]">
                <input
                    type="checkbox"
                    name="proxy_dead_on_po_stale_enabled"
                    id="proxy_dead_on_po_stale_enabled"
                    value="1"
                    {{ !empty($proxyDeadOnPoStaleEnabled) ? 'checked' : '' }}
                    class="rounded border-zinc-600 bg-zinc-800 text-blue-500"
                >
                Помечать dead при отсутствии события PO
            </label>
            <label
                id="proxy_dead_on_po_stale_minutes_label"
                class="flex flex-col gap-1 text-zinc-200 text-sm {{ empty($proxyDeadOnPoStaleEnabled) ? 'hidden' : '' }}"
            >
                <span>Порог stale «Событие PO» для dead (минут)</span>
                <input
                    type="number"
                    name="proxy_dead_on_po_stale_minutes"
                    min="1"
                    max="1440"
                    value="{{ (int) ($proxyDeadOnPoStaleMinutes ?? 30) }}"
                    class="w-28 px-2 py-1 rounded border border-zinc-600 bg-zinc-950 text-zinc-100 focus:ring-2 focus:ring-indigo-600 focus:border-transparent"
                >
                <span class="text-xs text-zinc-500">
                    Отдельно от порога подсветки в «Мониторинг Payout» ниже. Срабатывает только при включённой авто-ротации
                    и по полю last_payout_event_at (когда брокер прислал payout_update), не по heartbeat POST ingest.
                </span>
            </label>
            <label class="inline-flex items-center gap-2 text-zinc-200 min-h-[40px]">
                <input
                    type="checkbox"
                    name="proxy_auto_test_all_enabled"
                    id="proxy_auto_test_all_enabled"
                    value="1"
                    {{ !empty($proxyAutoTestAllEnabled) ? 'checked' : '' }}
                    class="rounded border-zinc-600 bg-zinc-800 text-indigo-500"
                >
                Автоматический тест всех прокси
            </label>
            <label
                id="proxy_auto_test_dead_label"
                class="inline-flex items-center gap-2 text-zinc-200 min-h-[40px] {{ !empty($proxyAutoTestAllEnabled) ? 'opacity-50 cursor-not-allowed' : '' }}"
            >
                <input
                    type="checkbox"
                    name="proxy_auto_test_enabled"
                    id="proxy_auto_test_enabled"
                    value="1"
                    {{ !empty($proxyAutoTestEnabled) ? 'checked' : '' }}
                    {{ !empty($proxyAutoTestAllEnabled) ? 'disabled' : '' }}
                    class="rounded border-zinc-600 bg-zinc-800 text-indigo-500 disabled:opacity-60"
                >
                Автоматический тест dead-прокси
            </label>
            <label class="flex flex-col gap-1 text-zinc-200 text-sm">
                <span>Порог перевода в dead (streak >=)</span>
                <input
                    type="number"
                    name="proxy_dead_streak_threshold"
                    min="1"
                    max="1000"
                    value="{{ (int) ($proxyDeadStreakThreshold ?? 10) }}"
                    class="w-28 px-2 py-1 rounded border border-zinc-600 bg-zinc-950 text-zinc-100 focus:ring-2 focus:ring-indigo-600 focus:border-transparent"
                >
            </label>
            <label class="flex flex-col gap-1 text-zinc-200 text-sm">
                <span>Лимит нагрузки на 1 прокси (активных инструментов)</span>
                <input
                    type="number"
                    name="proxy_max_instruments_per_endpoint"
                    min="1"
                    max="100"
                    value="{{ (int) ($proxyMaxInstrumentsPerEndpoint ?? 1) }}"
                    class="w-28 px-2 py-1 rounded border border-zinc-600 bg-zinc-950 text-zinc-100 focus:ring-2 focus:ring-indigo-600 focus:border-transparent"
                >
                <span class="text-xs text-zinc-500">
                    Ограничивает концентрацию: авто-ротация не назначит новый инструмент на proxy endpoint,
                    если на нём уже достигнут этот лимит.
                </span>
            </label>
            <label class="flex flex-col gap-1 text-zinc-200 text-sm w-full max-w-sm">
                <span>Инструмент для авто-теста dead-прокси (PO/WS)</span>
                <select
                    name="proxy_reactivation_probe_instrument"
                    class="w-full px-2 py-1 rounded border border-zinc-600 bg-zinc-950 text-zinc-100 focus:ring-2 focus:ring-indigo-600 focus:border-transparent"
                >
                    <option value="">— не выбрано (авто-реанимация выключена) —</option>
                    @foreach(($proxyReactivationProbeInstruments ?? collect()) as $inst)
                        <option
                            value="{{ $inst->instrument }}"
                            @selected(($proxyReactivationProbeInstrument ?? '') === $inst->instrument)
                        >
                            {{ $inst->instrument }}@if(!empty($inst->display_name)) — {{ $inst->display_name }}@endif @if(!$inst->is_active) (выкл.) @endif
                        </option>
                    @endforeach
                </select>
                <span class="text-xs text-zinc-500">
                    Пара временно назначается на тестируемый прокси; успех пробы — только PO ok. Для dead — реанимация.
                    Режим «все прокси» обходит весь пул по кругу. Daemon: <code class="text-zinc-400">proxies:probe-loop</code>.
                </span>
            </label>
            <label class="flex flex-col gap-1 text-zinc-200 text-sm">
                <span>Окно ожидания PO/WS (сек)</span>
                <input
                    type="number"
                    name="proxy_probe_window_sec"
                    min="5"
                    max="180"
                    value="{{ (int) ($proxyProbeWindowSec ?? 15) }}"
                    class="w-28 px-2 py-1 rounded border border-zinc-600 bg-zinc-950 text-zinc-100 focus:ring-2 focus:ring-indigo-600 focus:border-transparent"
                >
                <span class="text-xs text-zinc-500">
                    Должно быть не меньше интервала обновления ingest (<code class="text-zinc-400">INSTRUMENTS_REFRESH_SEC</code> на прокси-сервере, рекомендуется 10–15).
                </span>
            </label>
            <button type="submit" class="inline-flex items-center justify-center px-4 py-2 rounded bg-indigo-600 hover:bg-indigo-500 text-white font-medium">
                Сохранить
            </button>
        </form>
    </div>

    @php
        $poolHealth = $proxyPoolHealth ?? ['filter' => 'all', 'summary' => [], 'rows' => []];
        $healthSummary = $poolHealth['summary'] ?? [];
        $healthRows = $poolHealth['rows'] ?? [];
        $healthFilterCurrent = $proxyHealthFilter ?? 'all';
        $layerBadgeClass = static function (string $layer): string {
            return match ($layer) {
                'ok' => 'text-emerald-300 bg-emerald-950/60 border-emerald-800/80',
                'warn' => 'text-amber-300 bg-amber-950/60 border-amber-800/80',
                'fail' => 'text-rose-300 bg-rose-950/60 border-rose-800/80',
                default => 'text-zinc-400 bg-zinc-800/60 border-zinc-700/80',
            };
        };
        $healthFilterLinkClass = static function (string $filter, string $current): string {
            $base = 'px-3 py-1 rounded-md border text-xs font-medium transition-colors';
            if ($filter === $current) {
                return $base.' border-indigo-600 bg-indigo-900/40 text-indigo-200';
            }

            return $base.' border-zinc-700 text-zinc-400 hover:bg-zinc-800 hover:text-zinc-200';
        };
    @endphp

    <div class="bg-zinc-900 border border-zinc-800 rounded-lg shadow-lg shadow-black/20 p-5 mt-6">
        <h2 class="text-lg font-semibold text-zinc-100 mb-2">Здоровье пула прокси</h2>
        <div class="text-sm text-zinc-400 mb-4 space-y-3 max-w-4xl">
            <p>
                У каждого прокси три независимые проверки. Они отвечают на разные вопросы: «туннель в интернет жив?»,
                «ingest подключается к Pocket Options через этот прокси?», «брокер присылает payout по инструментам?».
                Красный бейдж на одном уровне <strong class="text-zinc-300">не всегда</strong> значит, что прокси нужно
                помечать dead — смотрите подсказку под строкой и раскройте инструменты.
            </p>
            <div class="grid gap-3 sm:grid-cols-3 text-xs">
                <div class="rounded-md border border-zinc-800 bg-zinc-950/50 p-3">
                    <div class="font-medium text-zinc-200 mb-1.5">HTTP (колонка слева)</div>
                    <p class="text-zinc-500 mb-2">Запрос к api.ipify.org через прокси. Кнопка «Быстрый HTTP-тест» сохраняет результат в БД.</p>
                    <ul class="space-y-1 text-zinc-400 list-disc list-inside">
                        <li><span class="text-zinc-500">не тестировали</span> — тест ещё не запускали; статус неизвестен.</li>
                        <li><span class="text-emerald-400/90">HTTP ok</span> — ответ 200, задержка нормальная; туннель работает.</li>
                        <li><span class="text-amber-400/90">HTTP медленно</span> — 200, но долго; прокси нагружен или далеко.</li>
                        <li><span class="text-rose-400/90">HTTP fail</span> — таймаут, 407 (логин/пароль), сеть; сменить прокси или refresh Webshare.</li>
                    </ul>
                </div>
                <div class="rounded-md border border-zinc-800 bg-zinc-950/50 p-3">
                    <div class="font-medium text-zinc-200 mb-1.5">WebSocket / ingest (центр)</div>
                    <p class="text-zinc-500 mb-2">Связь ingest → Pocket Options. Не то же самое, что HTTP: ipify может быть ok, а WS — нет.</p>
                    <ul class="space-y-1 text-zinc-400 list-disc list-inside">
                        <li><span class="text-zinc-500">нет инструментов</span> — на endpoint не назначены активные пары.</li>
                        <li><span class="text-rose-400/90">WS fail</span> — недавно connect_failed / not_connected (см. last_error).</li>
                        <li><span class="text-rose-400/90">ingest молчит</span> — ни у одного инструмента нет POST ingest &gt; 2 мин (воркер упал или не постит).</li>
                        <li><span class="text-amber-400/90">WS / ingest</span> — часть инструментов без POST или есть старые ошибки в failure_count.</li>
                        <li><span class="text-emerald-400/90">WS ok</span> — нет свежих connect_failed, ingest шлёт payout на платформу.</li>
                    </ul>
                </div>
                <div class="rounded-md border border-zinc-800 bg-zinc-950/50 p-3">
                    <div class="font-medium text-zinc-200 mb-1.5">Payout PO (справа)</div>
                    <p class="text-zinc-500 mb-2">События payout_update от Pocket Options (не время POST ingest).</p>
                    <ul class="space-y-1 text-zinc-400 list-disc list-inside">
                        <li><span class="text-rose-400/90">PO stale</span> — у всех инструментов на прокси давно не было свежего события payout от брокера (порог в «Мониторинг Payout» ниже) или payout_is_stale.</li>
                        <li><span class="text-amber-400/90">PO / ingest</span> — часть пар без свежего события PO или без POST ingest; смотрите таблицу внутри строки.</li>
                        <li><span class="text-emerald-400/90">PO ok</span> — события payout и POST ingest свежие по всем инструментам на прокси.</li>
                    </ul>
                </div>
            </div>
            <p class="text-xs text-zinc-500">
                Фильтр «Ложный dead» — прокси в статусе dead, но последний HTTP-тест = ok: часто помечали dead только из‑за stale payout, хотя туннель жив.
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2 mb-4">
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('settings.index', ['health_filter' => 'all']) }}" class="{{ $healthFilterLinkClass('all', $healthFilterCurrent) }}">Все</a>
                <a href="{{ route('settings.index', ['health_filter' => 'problems']) }}" class="{{ $healthFilterLinkClass('problems', $healthFilterCurrent) }}">Проблемные</a>
                <a href="{{ route('settings.index', ['health_filter' => 'false_dead']) }}" class="{{ $healthFilterLinkClass('false_dead', $healthFilterCurrent) }}">Ложный dead</a>
                <a href="{{ route('settings.index', ['health_filter' => 'active']) }}" class="{{ $healthFilterLinkClass('active', $healthFilterCurrent) }}">Active</a>
            </div>
            <form
                action="{{ route('settings.proxies.reactivate-all') }}"
                method="POST"
                class="sm:ml-auto"
                onsubmit="return confirm('Реанимировать все dead-прокси?');"
            >
                @csrf
                <button type="submit" class="inline-flex items-center px-3 py-1.5 rounded bg-emerald-700 hover:bg-emerald-600 text-white text-xs font-medium whitespace-nowrap">
                    Реанимировать все dead
                </button>
            </form>
        </div>
        <div class="mb-4 text-sm text-zinc-300">
            В пуле: <span class="text-zinc-100 font-medium">{{ $healthSummary['total'] ?? 0 }}</span>,
            показано: <span class="text-zinc-100 font-medium">{{ $healthSummary['shown'] ?? 0 }}</span>,
            ложный dead (HTTP ok): <span class="text-amber-400 font-medium">{{ $healthSummary['false_dead'] ?? 0 }}</span>,
            HTTP не тестировали: <span class="text-zinc-400 font-medium">{{ $healthSummary['http_untested'] ?? 0 }}</span>.
        </div>
        <div id="proxy-live-current" class="mb-4 rounded-md border border-zinc-800 bg-zinc-950/60 px-3 py-2 text-xs text-zinc-400">
            <div class="flex items-start gap-2">
                <span
                    class="js-live-probe-spinner hidden mt-0.5 inline-block h-3.5 w-3.5 shrink-0 animate-spin rounded-full border-2 border-indigo-400 border-t-transparent"
                    aria-hidden="true"
                ></span>
                <span class="js-live-probe-text">Сейчас не тестируется.</span>
            </div>
        </div>

        @if(count($healthRows) === 0)
            <p class="text-sm text-zinc-500">Нет прокси для выбранного фильтра.</p>
        @else
            <div class="overflow-x-auto rounded-lg border border-zinc-800">
                <table class="min-w-full text-sm divide-y divide-zinc-800">
                    <thead class="bg-zinc-800/80 text-zinc-400 uppercase text-xs">
                        <tr>
                            <th class="px-3 py-2 text-left whitespace-nowrap">№ / id</th>
                            <th class="px-3 py-2 text-left min-w-[200px]">Прокси</th>
                            <th class="px-3 py-2 text-left whitespace-nowrap">Статус</th>
                            <th class="px-3 py-2 text-left whitespace-nowrap">Ошибки</th>
                            <th class="px-3 py-2 text-left whitespace-nowrap">HTTP</th>
                            <th class="px-3 py-2 text-left whitespace-nowrap">WebSocket</th>
                            <th class="px-3 py-2 text-left whitespace-nowrap">Payout PO</th>
                            <th class="px-3 py-2 text-left min-w-[14rem]">Авто-тест</th>
                            <th class="px-3 py-2 text-left min-w-[12rem]">Последний тест</th>
                            <th class="px-3 py-2 text-left min-w-[12rem]">Рекомендация</th>
                            <th class="px-3 py-2 text-left min-w-[10rem]">Последняя ошибка</th>
                            <th class="px-3 py-2 text-right min-w-[12rem]">Действия</th>
                            <th class="px-2 py-2 text-center whitespace-nowrap w-14" title="Инструменты на прокси">Пары</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-800 text-zinc-200">
                        @foreach($healthRows as $row)
                            @php
                                $proxy = $row['proxy'];
                                $proxyId = $row['proxy_id'];
                                $statusClass = match($row['status']) {
                                    'active' => 'text-emerald-400',
                                    'dead' => 'text-rose-400',
                                    default => 'text-zinc-400',
                                };
                            @endphp
                            <tr class="hover:bg-zinc-800/40 align-top" data-proxy-health-row="{{ $proxyId }}" data-health-row-url="{{ route('settings.proxies.health-row-ajax', $proxy) }}">
                                <td class="px-3 py-2 whitespace-nowrap">
                                    <div class="text-sm font-semibold tabular-nums text-zinc-100">{{ $loop->iteration }}</div>
                                    <div class="text-[10px] tabular-nums text-zinc-500">id {{ $proxyId }}</div>
                                </td>
                                <td class="px-3 py-2 font-mono text-xs max-w-[280px]">
                                    <div class="break-all text-zinc-300" title="{{ $row['url'] }}">{{ $row['url'] }}</div>
                                </td>
                                <td class="px-3 py-2 text-xs whitespace-nowrap">
                                    <div class="{{ $statusClass }} js-proxy-status">{{ $row['status'] }}</div>
                                    <div class="text-zinc-500 mt-0.5">инстр. {{ $row['active_instruments_count'] }}</div>
                                    <div class="text-amber-300 font-medium mt-0.5 js-false-dead-label {{ $row['is_false_dead'] ? '' : 'hidden' }}">ложный dead</div>
                                </td>
                                <td class="px-3 py-2 tabular-nums whitespace-nowrap js-proxy-failures">{{ $row['failure_count'] }}</td>
                                <td class="px-3 py-2 whitespace-nowrap">
                                    <span class="js-health-badge-http inline-flex px-2 py-0.5 rounded border text-[11px] {{ $layerBadgeClass($row['http']['layer']) }}" title="{{ $row['http']['detail'] }}">{{ $row['http']['label'] }}</span>
                                </td>
                                <td class="px-3 py-2 whitespace-nowrap">
                                    <span class="js-health-badge-ws inline-flex px-2 py-0.5 rounded border text-[11px] {{ $layerBadgeClass($row['ws']['layer']) }}" title="{{ $row['ws']['detail'] }}">{{ $row['ws']['label'] }}</span>
                                </td>
                                <td class="px-3 py-2 whitespace-nowrap">
                                    <span class="js-health-badge-payout inline-flex px-2 py-0.5 rounded border text-[11px] {{ $layerBadgeClass($row['payout']['layer']) }}" title="{{ $row['payout']['detail'] }}">{{ $row['payout']['label'] }}</span>
                                </td>
                                <td class="px-3 py-2 text-xs min-w-[14rem]">
                                    @php
                                        // JSON результата последнего авто-теста:
                                        // сюда пишет фоновая команда proxies:probe-dead.
                                        $autoTest = is_array($proxy->last_auto_test ?? null) ? $proxy->last_auto_test : [];
                                        $autoStatus = (string) ($autoTest['status'] ?? '');
                                        $autoStatusClass = match($autoStatus) {
                                            'ok' => 'text-emerald-300',
                                            'warn' => 'text-amber-300',
                                            'fail' => 'text-rose-300',
                                            'skip' => 'text-zinc-400',
                                            default => 'text-zinc-500',
                                        };
                                        $autoCheckedAtRaw = $autoTest['checked_at'] ?? null;
                                        $autoCheckedAt = null;
                                        if (is_string($autoCheckedAtRaw) && $autoCheckedAtRaw !== '') {
                                            try {
                                                $autoCheckedAt = \Illuminate\Support\Carbon::parse($autoCheckedAtRaw);
                                            } catch (\Throwable) {
                                                $autoCheckedAt = null;
                                            }
                                        }
                                    @endphp
                                    @if($autoStatus !== '')
                                        <div class="js-auto-test-status {{ $autoStatusClass }} font-medium">{{ $autoStatus }}</div>
                                        <div class="js-auto-test-time text-zinc-500">
                                            {{ $autoCheckedAt ? $autoCheckedAt->timezone(config('app.timezone'))->format('d.m.Y H:i:s') : '—' }}
                                        </div>
                                        <div class="js-auto-test-meta text-zinc-500 text-[10px] break-words">
                                            {{ (string) ($autoTest['instrument'] ?? '—') }}; {{ (string) ($autoTest['reason'] ?? '—') }}
                                        </div>
                                    @else
                                        <div class="js-auto-test-status text-zinc-500">не тестировали</div>
                                        <div class="js-auto-test-time text-zinc-600"></div>
                                        <div class="js-auto-test-meta text-zinc-600 text-[10px] break-words"></div>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-xs min-w-[12rem]">
                                    @php
                                        $lastCheckResult = $proxy->last_http_check_result;
                                        $lastCheckClass = match($lastCheckResult) {
                                            'ok' => 'text-emerald-300',
                                            'warn' => 'text-amber-300',
                                            'fail' => 'text-rose-300',
                                            default => 'text-zinc-500',
                                        };
                                    @endphp
                                    @if($proxy->last_http_check_at)
                                        <div class="js-last-test-status {{ $lastCheckClass }} font-medium">{{ $lastCheckResult ?? '—' }}</div>
                                        <div class="js-last-test-time text-zinc-500">
                                            {{ $proxy->last_http_check_at->timezone(config('app.timezone'))->format('d.m.Y H:i:s') }}
                                        </div>
                                        <div class="js-last-test-meta text-zinc-500 text-[10px]">
                                            code={{ $proxy->last_http_status ?? '—' }},
                                            {{ $proxy->last_http_latency_ms ?? '—' }}ms,
                                            {{ $proxy->last_http_check_mode ?? 'quick' }}
                                        </div>
                                    @else
                                        <div class="js-last-test-status text-zinc-500">не тестировали</div>
                                        <div class="js-last-test-time text-zinc-600"></div>
                                        <div class="js-last-test-meta text-zinc-600 text-[10px]"></div>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-xs text-zinc-400 max-w-xs js-proxy-recommendation">{{ $row['recommendation'] }}</td>
                                <td class="px-3 py-2 text-xs min-w-[10rem]">
                                    @php
                                        $latestDeadReason = $row['latest_dead_reason'] ?? null;
                                    @endphp
                                    @if($row['last_error'])
                                        <div class="text-zinc-300 break-words js-proxy-last-error">{{ $row['last_error'] }}</div>
                                        <div class="text-zinc-500 text-[10px] mt-0.5 js-proxy-last-error-at">
                                            {{ $proxy->last_failed_at ? $proxy->last_failed_at->timezone(config('app.timezone'))->format('d.m.Y H:i:s') : '—' }}
                                        </div>
                                    @else
                                        <div class="text-zinc-500 js-proxy-last-error">—</div>
                                        <div class="text-zinc-500 text-[10px] mt-0.5 js-proxy-last-error-at"></div>
                                    @endif
                                    @if(is_array($latestDeadReason))
                                        <div class="text-amber-300/90 text-[10px] mt-1 break-words js-proxy-dead-reason">
                                            dead: {{ $latestDeadReason['reason'] ?? '—' }}
                                            @if(!empty($latestDeadReason['instrument']))
                                                · {{ $latestDeadReason['instrument'] }} (#{{ $latestDeadReason['instrument_id'] ?? '—' }})
                                            @endif
                                            @if(!empty($latestDeadReason['at']))
                                                · {{ \Illuminate\Support\Carbon::parse($latestDeadReason['at'])->timezone(config('app.timezone'))->format('d.m.Y H:i:s') }}
                                            @endif
                                        </div>
                                    @else
                                        <div class="js-proxy-dead-reason hidden"></div>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-right align-top" data-proxy-test-scope>
                                    <div class="flex flex-wrap justify-end gap-1.5">
                                        <button
                                            type="button"
                                            class="inline-flex items-center px-2 py-1 rounded bg-emerald-700 hover:bg-emerald-600 text-white text-[11px] font-medium js-proxy-test-btn whitespace-nowrap"
                                            data-url="{{ route('settings.proxies.test-quick-ajax', $proxy) }}"
                                            data-proxy-id="{{ $proxyId }}"
                                            data-mode="quick"
                                        >Быстрый тест</button>
                                        <button
                                            type="button"
                                            class="inline-flex items-center px-2 py-1 rounded bg-indigo-700 hover:bg-indigo-600 text-white text-[11px] font-medium js-proxy-test-btn whitespace-nowrap"
                                            data-url="{{ route('settings.proxies.test-deep-ajax', $proxy) }}"
                                            data-proxy-id="{{ $proxyId }}"
                                            data-mode="deep"
                                        >Deep (45с)</button>
                                        <form action="{{ route('settings.proxies.reactivate', $proxy) }}" method="POST" class="inline">
                                            @csrf
                                            <button
                                                type="submit"
                                                class="inline-flex items-center px-2 py-1 rounded bg-emerald-700 hover:bg-emerald-600 text-white text-[11px] font-medium whitespace-nowrap"
                                            >Реанимировать</button>
                                        </form>
                                    </div>
                                    <div data-proxy-test-result class="mt-1 text-[10px] text-zinc-500 min-h-[1rem] break-words text-right max-w-[12rem] ml-auto"></div>
                                </td>
                                <td class="px-2 py-2 text-center align-top whitespace-nowrap">
                                    @if(count($row['instruments']) > 0)
                                        <button
                                            type="button"
                                            class="js-proxy-instruments-toggle inline-flex flex-col items-center justify-center min-w-[2.25rem] px-1.5 py-1 rounded border border-zinc-700 bg-zinc-900/60 text-zinc-400 hover:bg-zinc-800 hover:text-zinc-100 text-[11px] leading-tight"
                                            data-proxy-id="{{ $proxyId }}"
                                            aria-expanded="false"
                                            title="Показать инструменты (payout, ingest, WS)"
                                        >
                                            <span class="js-proxy-instruments-chevron transition-transform inline-block">▶</span>
                                            <span class="tabular-nums font-medium">{{ count($row['instruments']) }}</span>
                                        </button>
                                    @else
                                        <span class="text-zinc-600 text-xs">—</span>
                                    @endif
                                </td>
                            </tr>
                            @if(count($row['instruments']) > 0)
                                <tr class="hidden js-proxy-instruments-row bg-zinc-950/50" data-proxy-instruments-for="{{ $proxyId }}">
                                    <td colspan="13" class="px-3 py-2">
                                        <div class="overflow-x-auto">
                                            <table class="min-w-full text-xs divide-y divide-zinc-800">
                                                    <thead class="text-zinc-500 uppercase">
                                                        <tr>
                                                            <th class="py-1 pr-3 text-left">Инструмент</th>
                                                            <th class="py-1 pr-3 text-left">Payout %</th>
                                                            <th class="py-1 pr-3 text-left">Событие PO</th>
                                                            <th class="py-1 pr-3 text-left">POST ingest</th>
                                                            <th class="py-1 text-left">WebSocket</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody class="text-zinc-300 divide-y divide-zinc-800/80">
                                                        @foreach($row['instruments'] as $inst)
                                                            <tr>
                                                                <td class="py-1.5 pr-3 font-mono">
                                                                    <a
                                                                        href="{{ route('dashboard', ['instrument' => $inst['instrument']]) }}"
                                                                        class="text-blue-400 hover:text-blue-300 hover:underline"
                                                                        title="Открыть график на главной"
                                                                    >{{ $inst['instrument'] }}</a>
                                                                </td>
                                                                <td class="py-1.5 pr-3">{{ $inst['current_payout'] !== null ? number_format((float) $inst['current_payout'], 0).'%' : '—' }}</td>
                                                                <td class="py-1.5 pr-3">
                                                                    @if($inst['payout']['event_at'])
                                                                        <span class="{{ $inst['payout']['is_stale'] ? 'text-rose-300' : '' }}">
                                                                            {{ $inst['payout']['event_at']->timezone(config('app.timezone'))->format('d.m H:i') }}
                                                                            @if($inst['payout']['is_stale_flag'])
                                                                                <span class="text-rose-400">stale</span>
                                                                            @endif
                                                                        </span>
                                                                    @else
                                                                        —
                                                                    @endif
                                                                </td>
                                                                <td class="py-1.5 pr-3">
                                                                    @if($inst['ingest']['posted_at'])
                                                                        <span class="{{ $inst['ingest']['is_silent'] ? 'text-amber-300' : '' }}">
                                                                            {{ $inst['ingest']['posted_at']->timezone(config('app.timezone'))->format('d.m H:i') }}
                                                                        </span>
                                                                    @else
                                                                        —
                                                                    @endif
                                                                </td>
                                                                <td class="py-1.5">
                                                                    @php
                                                                        $wsState = $inst['ws']['state'] ?? 'unknown';
                                                                        $wsClass = match($wsState) {
                                                                            'ok' => 'text-emerald-300',
                                                                            'fail' => 'text-rose-300',
                                                                            'silent' => 'text-amber-300',
                                                                            default => 'text-zinc-500',
                                                                        };
                                                                    @endphp
                                                                    <span class="{{ $wsClass }}">
                                                                        @switch($wsState)
                                                                            @case('ok') ok @break
                                                                            @case('fail') fail @break
                                                                            @case('silent') молчит @break
                                                                            @default —
                                                                        @endswitch
                                                                    </span>
                                                                    @if(!empty($inst['ws']['error']))
                                                                        <span class="block text-[10px] text-rose-400/80 truncate max-w-[240px]" title="{{ $inst['ws']['error'] }}">{{ Str::limit($inst['ws']['error'], 48) }}</span>
                                                                    @endif
                                                                </td>
                                                            </tr>
                                                        @endforeach
                                                    </tbody>
                                            </table>
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="bg-zinc-900 border border-zinc-800 rounded-lg shadow-lg shadow-black/20 p-5 mt-6">
        <h2 class="text-lg font-semibold text-zinc-100 mb-2">Очистка данных</h2>
        <p class="text-sm text-zinc-400 mb-4">
            Удаляет исторические данные платформы: свечи в БД, сигналы и сделки всех ботов,
            LLM-логи, анализы и записи запусков. Действие необратимо.
            Вариант «0 часов» стирает <strong class="text-zinc-300">всё</strong> без исключения;
            остальные варианты оставляют данные за последние N часов.
        </p>
        <form
            action="{{ route('settings.data.purge') }}"
            method="POST"
            class="flex flex-wrap items-end gap-3"
            onsubmit="const h=this.retention_hours.value; return confirm(h==='0' ? 'Удалить ВСЕ данные без исключения? Действие необратимо.' : 'Удалить все данные старше выбранного периода? Действие необратимо.');"
        >
            @csrf
            <label class="flex flex-col gap-1 text-zinc-200 text-sm">
                <span>Удалить данные старше</span>
                <select
                    name="retention_hours"
                    class="min-w-[10rem] px-2 py-2 rounded border border-zinc-600 bg-zinc-950 text-zinc-100 focus:ring-2 focus:ring-rose-600 focus:border-transparent"
                >
                    <option value="0">0 часов (всё)</option>
                    <option value="1">1 час</option>
                    <option value="3">3 часа</option>
                    <option value="6">6 часов</option>
                    <option value="12">12 часов</option>
                    <option value="24" selected>24 часа</option>
                    <option value="48">48 часов</option>
                </select>
            </label>
            <button
                type="submit"
                class="inline-flex items-center justify-center px-4 py-2 rounded bg-rose-700 hover:bg-rose-600 text-white font-medium"
            >
                Удалить все данные
            </button>
        </form>
    </div>

    <div class="bg-zinc-900 border border-zinc-800 rounded-lg shadow-lg shadow-black/20 p-5 mt-6">
        <h2 class="text-lg font-semibold text-zinc-100 mb-2">Мониторинг Payout</h2>
        <p class="text-sm text-zinc-400 mb-4">
            Порог влияет на колонку «Событие payout (PO)» в разделе «Инструменты».
            Красная подсветка — если Pocket Options давно не присылал payout_update (или ingest пометил payout_is_stale).
            Колонка «POST ingest» показывает время последнего heartbeat от ingest и не использует этот порог.
        </p>
        <form action="{{ route('settings.payout.monitoring') }}" method="POST" class="flex flex-col items-start gap-3">
            @csrf
            <label class="flex flex-col gap-1 text-zinc-200 text-sm">
                <span>Порог stale для payout (минут)</span>
                <input
                    type="number"
                    name="payout_stale_minutes_threshold"
                    min="1"
                    max="1440"
                    value="{{ (int) ($payoutStaleMinutesThreshold ?? 5) }}"
                    class="w-28 px-2 py-1 rounded border border-zinc-600 bg-zinc-950 text-zinc-100 focus:ring-2 focus:ring-indigo-600 focus:border-transparent"
                >
            </label>
            <button type="submit" class="inline-flex items-center justify-center px-4 py-2 rounded bg-indigo-600 hover:bg-indigo-500 text-white font-medium">
                Сохранить порог
            </button>
        </form>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const poStaleDeadCheckbox = document.getElementById('proxy_dead_on_po_stale_enabled');
    const poStaleDeadMinutesLabel = document.getElementById('proxy_dead_on_po_stale_minutes_label');

    const syncPoStaleDeadUi = () => {
        if (!poStaleDeadCheckbox || !poStaleDeadMinutesLabel) return;
        poStaleDeadMinutesLabel.classList.toggle('hidden', !poStaleDeadCheckbox.checked);
    };

    poStaleDeadCheckbox?.addEventListener('change', syncPoStaleDeadUi);
    syncPoStaleDeadUi();

    const proxyTestAllCheckbox = document.getElementById('proxy_auto_test_all_enabled');
    const proxyTestDeadCheckbox = document.getElementById('proxy_auto_test_enabled');
    const proxyTestDeadLabel = document.getElementById('proxy_auto_test_dead_label');

    const syncProxyTestModeUi = () => {
        if (!proxyTestAllCheckbox || !proxyTestDeadCheckbox) return;
        const allOn = proxyTestAllCheckbox.checked;
        proxyTestDeadCheckbox.disabled = allOn;
        if (allOn) {
            proxyTestDeadCheckbox.checked = false;
        }
        if (proxyTestDeadLabel) {
            proxyTestDeadLabel.classList.toggle('opacity-50', allOn);
            proxyTestDeadLabel.classList.toggle('cursor-not-allowed', allOn);
        }
    };

    proxyTestAllCheckbox?.addEventListener('change', syncProxyTestModeUi);
    syncProxyTestModeUi();

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    const buttons = document.querySelectorAll('.js-proxy-test-btn');
    const liveUrl = '{{ route('settings.proxies.health-live-ajax') }}';
    const currentProbeNode = document.getElementById('proxy-live-current');
    // Защита от наложения запросов и лишних перерисовок (иначе таблица «дёргается» каждые 3 с).
    let liveRefreshInFlight = false;
    const liveRowSnapshot = new Map();

    const setTextIfChanged = (node, text) => {
        if (!node) return;
        const next = String(text ?? '');
        if (node.textContent !== next) {
            node.textContent = next;
        }
    };

    const setClassIfChanged = (node, className) => {
        if (!node || node.className === className) return;
        node.className = className;
    };

    const layerBadgeClass = (layer) => {
        const map = {
            ok: 'text-emerald-300 bg-emerald-950/60 border-emerald-800/80',
            warn: 'text-amber-300 bg-amber-950/60 border-amber-800/80',
            fail: 'text-rose-300 bg-rose-950/60 border-rose-800/80',
        };
        return map[layer] || 'text-zinc-400 bg-zinc-800/60 border-zinc-700/80';
    };

    const applyHealthBadges = (proxyId, health) => {
        if (!health) return;
        const row = document.querySelector(`tr[data-proxy-health-row="${proxyId}"]`);
        if (!row) return;

        const pairs = [
            ['http', health.http],
            ['ws', health.ws],
            ['payout', health.payout],
        ];
        pairs.forEach(([key, layer]) => {
            if (!layer) return;
            const badge = row.querySelector(`.js-health-badge-${key}`);
            if (!badge) return;
            const nextClass = `js-health-badge-${key} inline-flex px-2 py-0.5 rounded border text-[11px] ${layerBadgeClass(layer.layer)}`;
            setClassIfChanged(badge, nextClass);
            setTextIfChanged(badge, layer.label || '—');
            const nextTitle = layer.detail || '';
            if (badge.title !== nextTitle) {
                badge.title = nextTitle;
            }
        });
    };

    const applyLastTestCell = (proxyId, payload) => {
        const row = document.querySelector(`tr[data-proxy-health-row="${proxyId}"]`);
        if (!row || !payload) return;
        const statusNode = row.querySelector('.js-last-test-status');
        const timeNode = row.querySelector('.js-last-test-time');
        const metaNode = row.querySelector('.js-last-test-meta');
        if (!statusNode || !timeNode || !metaNode) return;

        const status = String(payload.status || 'fail');
        statusNode.classList.remove('text-zinc-500', 'text-zinc-600', 'text-emerald-300', 'text-amber-300', 'text-rose-300');
        statusNode.classList.add(
            status === 'ok'
                ? 'text-emerald-300'
                : (status === 'warn' ? 'text-amber-300' : 'text-rose-300')
        );
        statusNode.textContent = status;

        // Время фиксируем на клиенте в локальном часовом поясе: это момент завершения теста.
        const now = new Date();
        const pad2 = (n) => String(n).padStart(2, '0');
        timeNode.textContent = `${pad2(now.getDate())}.${pad2(now.getMonth() + 1)}.${now.getFullYear()} ${pad2(now.getHours())}:${pad2(now.getMinutes())}:${pad2(now.getSeconds())}`;
        timeNode.classList.remove('text-zinc-600');
        timeNode.classList.add('text-zinc-500');

        const code = payload?.http_status ?? '—';
        const latency = payload?.latency_ms ?? '—';
        const mode = payload?.mode ?? 'quick';
        metaNode.textContent = `code=${code}, ${latency}ms, ${mode}`;
        metaNode.classList.remove('text-zinc-600');
        metaNode.classList.add('text-zinc-500');
    };

    const formatDate = (isoString) => {
        if (!isoString) return '—';
        const d = new Date(isoString);
        if (Number.isNaN(d.getTime())) return '—';
        const pad2 = (n) => String(n).padStart(2, '0');
        return `${pad2(d.getDate())}.${pad2(d.getMonth() + 1)}.${d.getFullYear()} ${pad2(d.getHours())}:${pad2(d.getMinutes())}:${pad2(d.getSeconds())}`;
    };

    const applyAutoTestCell = (proxyId, autoTest) => {
        const row = document.querySelector(`tr[data-proxy-health-row="${proxyId}"]`);
        if (!row) return;
        const statusNode = row.querySelector('.js-auto-test-status');
        const timeNode = row.querySelector('.js-auto-test-time');
        const metaNode = row.querySelector('.js-auto-test-meta');
        if (!statusNode || !timeNode || !metaNode) return;

        if (!autoTest || typeof autoTest !== 'object' || !autoTest.status) {
            setTextIfChanged(statusNode, 'не тестировали');
            setClassIfChanged(statusNode, 'js-auto-test-status text-zinc-500');
            setTextIfChanged(timeNode, '');
            setTextIfChanged(metaNode, '');
            return;
        }

        const status = String(autoTest.status);
        const statusClass = status === 'ok'
            ? 'text-emerald-300'
            : (status === 'warn' ? 'text-amber-300' : (status === 'fail' ? 'text-rose-300' : 'text-zinc-400'));
        setClassIfChanged(statusNode, `js-auto-test-status ${statusClass} font-medium`);
        setTextIfChanged(statusNode, status);
        setTextIfChanged(timeNode, formatDate(autoTest.checked_at || autoTest.started_at));
        setClassIfChanged(timeNode, 'js-auto-test-time text-zinc-500');
        setTextIfChanged(metaNode, `${autoTest.instrument || '—'}; ${autoTest.reason || '—'}`);
        setClassIfChanged(metaNode, 'js-auto-test-meta text-zinc-500 text-[10px] break-words');
    };

    const formatLatestDeadReason = (entry) => {
        if (!entry || typeof entry !== 'object') return '';
        const parts = [`dead: ${entry.reason || '—'}`];
        if (entry.instrument) {
            parts.push(`${entry.instrument} (#${entry.instrument_id ?? '—'})`);
        }
        if (entry.at) {
            parts.push(formatDate(entry.at));
        }
        return parts.join(' · ');
    };

    const rowLiveFingerprint = (row) => JSON.stringify({
        status: row.status,
        failure_count: row.failure_count,
        is_false_dead: row.is_false_dead,
        recommendation: row.recommendation,
        last_error: row.last_error,
        last_failed_at: row.last_failed_at,
        latest_dead_reason: row.latest_dead_reason,
        http: row.http,
        ws: row.ws,
        payout: row.payout,
        last_auto_test: row.last_auto_test,
    });

    const applyLiveRow = (row) => {
        const rowNode = document.querySelector(`tr[data-proxy-health-row="${row.proxy_id}"]`);
        if (!rowNode) return;

        const fingerprint = rowLiveFingerprint(row);
        if (liveRowSnapshot.get(row.proxy_id) === fingerprint) {
            return;
        }
        liveRowSnapshot.set(row.proxy_id, fingerprint);

        applyHealthBadges(row.proxy_id, { http: row.http, ws: row.ws, payout: row.payout });
        applyAutoTestCell(row.proxy_id, row.last_auto_test);

        const statusNode = rowNode.querySelector('.js-proxy-status');
        if (statusNode) {
            const map = { active: 'text-emerald-400', dead: 'text-rose-400' };
            setClassIfChanged(statusNode, `js-proxy-status ${map[row.status] || 'text-zinc-400'}`);
            setTextIfChanged(statusNode, row.status || '—');
        }
        const falseDeadNode = rowNode.querySelector('.js-false-dead-label');
        if (falseDeadNode) {
            falseDeadNode.classList.toggle('hidden', !row.is_false_dead);
        }
        const failureNode = rowNode.querySelector('.js-proxy-failures');
        setTextIfChanged(failureNode, String(row.failure_count ?? '0'));
        const recommendationNode = rowNode.querySelector('.js-proxy-recommendation');
        setTextIfChanged(recommendationNode, row.recommendation || '—');
        const lastErrorNode = rowNode.querySelector('.js-proxy-last-error');
        setTextIfChanged(lastErrorNode, row.last_error || '—');
        const lastErrorAtNode = rowNode.querySelector('.js-proxy-last-error-at');
        setTextIfChanged(lastErrorAtNode, formatDate(row.last_failed_at));
        const deadReasonNode = rowNode.querySelector('.js-proxy-dead-reason');
        if (deadReasonNode) {
            const deadReasonText = formatLatestDeadReason(row.latest_dead_reason);
            deadReasonNode.classList.toggle('hidden', deadReasonText === '');
            setTextIfChanged(deadReasonNode, deadReasonText);
        }
    };

    const applyCurrentProbe = (probe) => {
        if (!currentProbeNode) return;
        const spinnerNode = currentProbeNode.querySelector('.js-live-probe-spinner');
        const textNode = currentProbeNode.querySelector('.js-live-probe-text');
        const isRunning = probe && probe.state === 'running';

        currentProbeNode.classList.toggle('border-indigo-700/60', isRunning);
        currentProbeNode.classList.toggle('bg-indigo-950/40', isRunning);
        if (spinnerNode) spinnerNode.classList.toggle('hidden', !isRunning);
        if (!textNode) return;

        if (!isRunning) {
            setClassIfChanged(textNode, 'js-live-probe-text text-zinc-400');
            setTextIfChanged(textNode, 'Сейчас не тестируется.');
            return;
        }

        setClassIfChanged(textNode, 'js-live-probe-text text-indigo-200');
        const scopeLabel = probe.probe_scope === 'all' ? 'все прокси' : 'только dead';
        const statusPart = probe.proxy_status ? `, статус ${probe.proxy_status}` : '';
        setTextIfChanged(
            textNode,
            `Сейчас тестируется (${scopeLabel}): #${probe.proxy_id ?? '—'}${statusPart}, инструмент ${probe.instrument ?? '—'}, с ${formatDate(probe.started_at)}.`,
        );
    };

    const applyTestingRowHighlight = (currentProbe) => {
        const probeId = (currentProbe && currentProbe.state === 'running') ? Number(currentProbe.proxy_id) : null;
        const shouldHighlight = Number.isFinite(probeId) && probeId > 0;

        document.querySelectorAll('tr[data-proxy-health-row].js-probe-highlight').forEach((rowNode) => {
            rowNode.classList.remove('ring-1', 'ring-indigo-500/50', 'bg-indigo-950/20', 'js-probe-highlight');
        });

        if (!shouldHighlight) {
            return;
        }

        const activeRow = document.querySelector(`tr[data-proxy-health-row="${probeId}"]`);
        if (!activeRow) return;

        activeRow.classList.add('ring-1', 'ring-indigo-500/50', 'bg-indigo-950/20', 'js-probe-highlight');
    };

    const refreshLiveHealth = async () => {
        if (!liveUrl || liveRefreshInFlight) return;
        if (document.hidden) return;

        liveRefreshInFlight = true;
        try {
            const res = await fetch(liveUrl, { headers: { Accept: 'application/json' } });
            const payload = await res.json();
            if (!payload?.ok) return;
            (payload.rows || []).forEach((row) => applyLiveRow(row));
            applyCurrentProbe(payload.current_probe || null);
            applyTestingRowHighlight(payload.current_probe || null);
            lastProbeRunning = (payload.current_probe?.state === 'running');
        } catch (_) {
            // Ошибки опроса игнорируем: UI продолжает работать с последним известным состоянием.
        } finally {
            liveRefreshInFlight = false;
        }
    };

    let livePollTimer = null;
    let lastProbeRunning = false;

    const scheduleLivePoll = () => {
        if (livePollTimer) clearTimeout(livePollTimer);
        const delayMs = lastProbeRunning ? 3000 : 8000;
        livePollTimer = setTimeout(async () => {
            await refreshLiveHealth();
            scheduleLivePoll();
        }, delayMs);
    };

    const resultNodeForButton = (button) => button.closest('[data-proxy-test-scope]')?.querySelector('[data-proxy-test-result]') ?? null;

    const setResult = (button, text, status) => {
        const resultNode = resultNodeForButton(button);
        if (!resultNode) return;

        const colorClass = status === 'ok'
            ? 'text-emerald-400'
            : (status === 'warn' ? 'text-amber-400' : 'text-rose-400');
        resultNode.classList.remove('text-zinc-500', 'text-emerald-400', 'text-amber-400', 'text-rose-400');
        resultNode.classList.add(colorClass);
        resultNode.textContent = text;
    };

    document.querySelectorAll('.js-proxy-instruments-toggle').forEach((toggleBtn) => {
        toggleBtn.addEventListener('click', () => {
            const proxyId = toggleBtn.dataset.proxyId;
            const detailsRow = document.querySelector(`tr[data-proxy-instruments-for="${proxyId}"]`);
            if (!detailsRow) return;

            detailsRow.classList.toggle('hidden');
            const isOpen = !detailsRow.classList.contains('hidden');
            toggleBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');

            const chevron = toggleBtn.querySelector('.js-proxy-instruments-chevron');
            if (chevron) {
                chevron.classList.toggle('rotate-90', isOpen);
            }
        });
    });

    buttons.forEach((button) => {
        button.addEventListener('click', async () => {
            const proxyId = button.dataset.proxyId;
            const mode = button.dataset.mode || 'quick';
            const url = button.dataset.url;
            const timeoutMs = mode === 'deep' ? 45000 : 12000;
            const initialText = button.textContent;

            if (!url || !proxyId || !csrfToken) {
                return;
            }

            button.disabled = true;
            button.textContent = mode === 'deep' ? 'Тестирование...' : 'Проверка...';
            setResult(button, mode === 'deep' ? 'Выполняется deep-тест (до 45с)...' : 'Выполняется быстрый тест...', 'warn');

            const controller = new AbortController();
            const abortTimer = setTimeout(() => controller.abort(), timeoutMs);

            try {
                const response = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json',
                    },
                    signal: controller.signal,
                });

                const payload = await response.json();
                const status = payload?.status || 'fail';
                const details = payload?.details || 'fail - timeout/conn error/auth error.';
                const latencyPart = payload?.latency_ms != null ? ` latency=${payload.latency_ms}ms;` : '';
                const countPart = payload?.events_count != null ? ` успешных ответов=${payload.events_count};` : '';
                const durationPart = payload?.duration_sec != null ? ` длительность=${payload.duration_sec}s;` : '';

                setResult(button, `${status} - ${details};${latencyPart}${countPart}${durationPart}`.replace(/;;+/g, ';'), status);
                applyLastTestCell(proxyId, { ...payload, mode });
                if (payload?.health) {
                    applyHealthBadges(proxyId, payload.health);
                } else {
                    const rowUrl = document.querySelector(`tr[data-proxy-health-row="${proxyId}"]`)?.dataset?.healthRowUrl;
                    if (rowUrl) {
                        try {
                            const rowRes = await fetch(rowUrl, { headers: { Accept: 'application/json' } });
                            const rowPayload = await rowRes.json();
                            if (rowPayload?.ok) {
                                applyHealthBadges(proxyId, {
                                    http: rowPayload.http,
                                    ws: rowPayload.ws,
                                    payout: rowPayload.payout,
                                });
                            }
                        } catch (_) { /* ignore */ }
                    }
                }
            } catch (error) {
                setResult(button, 'fail - timeout/conn error/auth error.', 'fail');
            } finally {
                clearTimeout(abortTimer);
                button.disabled = false;
                button.textContent = initialText;
            }
        });
    });

    refreshLiveHealth().then(() => {
        scheduleLivePoll();
    });
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            refreshLiveHealth();
        }
    });
});
</script>
@endsection
