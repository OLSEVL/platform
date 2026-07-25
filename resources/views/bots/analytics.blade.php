@extends('layouts.app')
@section('title', 'Аналитика ботов')
@section('content')
@php
    $periodPresets = [
        1 => 'Последний час',
        3 => 'Последние 3 часа',
        6 => 'Последние 6 часов',
        24 => 'Последние сутки',
        72 => 'Последние 3 дня',
        168 => 'Последняя неделя',
    ];
    $intervalPresets = $intervalPresets ?? [
        60 => '1 минута',
        300 => '5 минут',
        900 => '15 минут',
        1800 => '30 минут',
        3600 => '1 час',
    ];
@endphp
<div class="space-y-6">
    <h1 class="text-2xl font-semibold">Аналитика ботов</h1>

    {{-- Период и интервал обобщения — авто-отправка формы при смене любого селекта. --}}
    <form method="GET" class="flex flex-wrap items-center gap-3">
        <label for="hours" class="text-sm text-zinc-400">Период:</label>
        <select id="hours" name="hours"
                class="rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100 min-w-[14rem]"
                onchange="this.form.submit()">
            @foreach($periodPresets as $h => $label)
                <option value="{{ $h }}" @selected((int) $hours === (int) $h)>{{ $label }}</option>
            @endforeach
        </select>
        <label for="interval" class="text-sm text-zinc-400">Интервал:</label>
        <select id="interval" name="interval"
                class="rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100 min-w-[10rem]"
                onchange="this.form.submit()">
            @foreach($intervalPresets as $sec => $label)
                <option value="{{ $sec }}" @selected((int) ($interval ?? 3600) === (int) $sec)>{{ $label }}</option>
            @endforeach
        </select>
        <span class="text-xs text-zinc-500">Ось X — время; ось Y — winrate 0–100%. Белый — платформа, голубой — фактический PO.</span>
    </form>

    {{-- Самый удачный бот в текущем бакете размера «Интервал» (последнее окно на шкале). --}}
    @if(!empty($bestBotInInterval))
        <div class="rounded border border-emerald-800/60 bg-emerald-950/30 px-4 py-3">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <p class="text-sm text-emerald-100/90">
                    <span class="text-emerald-400/80">Лучший бот за интервал</span>
                    <span class="text-zinc-500">({{ $bestBotInInterval['interval_label'] }}:
                        {{ $bestBotInInterval['bucket_from'] }}–{{ $bestBotInInterval['bucket_to'] }})</span>
                </p>
                <a href="{{ route('bots.analytics.pairs', ['bot_id' => $bestBotInInterval['bot_id'], 'hours' => $hours, 'interval' => $interval]) }}"
                   class="text-xs text-emerald-400/90 hover:text-emerald-300">Аналитика пар →</a>
            </div>
            <div class="mt-1.5 flex flex-wrap items-center gap-x-4 gap-y-1">
                <span class="text-lg font-semibold text-zinc-50">{{ $bestBotInInterval['name'] }}</span>
                <span class="text-sm text-zinc-300">
                    Платформа:
                    <span class="font-semibold text-emerald-400">{{ number_format((float) $bestBotInInterval['winrate'], 2) }}%</span>
                    <span class="text-xs text-zinc-500 ml-1">
                        W <span class="text-emerald-400">{{ (int) $bestBotInInterval['wins'] }}</span>
                        / L <span class="text-rose-400">{{ (int) $bestBotInInterval['losses'] }}</span>
                    </span>
                </span>
                <span class="text-sm text-zinc-300">
                    PO:
                    @if($bestBotInInterval['po_winrate'] !== null)
                        <span class="font-semibold text-sky-400">{{ number_format((float) $bestBotInInterval['po_winrate'], 2) }}%</span>
                        <span class="text-xs text-zinc-500 ml-1">
                            W <span class="text-emerald-400">{{ (int) $bestBotInInterval['po_wins'] }}</span>
                            / L <span class="text-rose-400">{{ (int) $bestBotInInterval['po_losses'] }}</span>
                        </span>
                    @else
                        <span class="font-semibold text-zinc-500">нет данных</span>
                    @endif
                </span>
            </div>
            <p class="mt-1 text-[11px] text-zinc-500">
                Рейтинг по paper winrate; минимум {{ (int) $bestBotInInterval['min_trades'] }} сделок win/loss в этом интервале.
            </p>
        </div>
    @else
        <div class="rounded border border-zinc-800 bg-zinc-900/40 px-4 py-2.5 text-sm text-zinc-500">
            Нет бота с достаточным числом сделок в текущем интервале ({{ $intervalPresets[$interval] ?? ($interval.' сек') }}).
        </div>
    @endif

    {{-- Сводка по всем ботам за период — paper и PO рядом. --}}
    @if($overallWinrate !== null || $overallWins > 0 || $overallLosses > 0 || $overallPoWinrate !== null || ($overallPoWins ?? 0) > 0 || ($overallPoLosses ?? 0) > 0)
        <div class="flex flex-wrap items-center gap-3 text-sm">
            <span class="rounded border border-zinc-700 bg-zinc-900/70 px-3 py-1.5 text-zinc-200">
                Платформа:
                @if($overallWinrate !== null)
                    <span class="font-semibold text-zinc-100">{{ number_format((float) $overallWinrate, 2) }}%</span>
                @else
                    <span class="font-semibold text-zinc-400">нет данных</span>
                @endif
                <span class="text-xs text-zinc-500 ml-1">
                    W <span class="text-emerald-400">{{ (int) $overallWins }}</span>
                    / L <span class="text-rose-400">{{ (int) $overallLosses }}</span>
                </span>
            </span>
            <span class="rounded border border-sky-800/70 bg-sky-950/40 px-3 py-1.5 text-zinc-200">
                PO:
                @if(($overallPoWinrate ?? null) !== null)
                    <span class="font-semibold text-sky-400">{{ number_format((float) $overallPoWinrate, 2) }}%</span>
                @else
                    <span class="font-semibold text-zinc-400">нет данных</span>
                @endif
                <span class="text-xs text-zinc-500 ml-1">
                    W <span class="text-emerald-400">{{ (int) ($overallPoWins ?? 0) }}</span>
                    / L <span class="text-rose-400">{{ (int) ($overallPoLosses ?? 0) }}</span>
                </span>
            </span>
        </div>
    @endif

    {{-- График для активных ботов и ботов со сделками за период (в т.ч. гибридный Chart Analyst). --}}
    @if(!empty($chartSeries))
        <div class="space-y-6">
            @foreach($chartSeries as $series)
                <div class="rounded border border-zinc-800 bg-zinc-950/40 p-4">
                    <div class="mb-3 space-y-2">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h2 class="text-sm font-medium text-zinc-200">{{ $series['name'] }}</h2>
                            {{-- Переход к winrate по каждой валютной паре этого бота. --}}
                            <a href="{{ route('bots.analytics.pairs', ['bot_id' => $series['bot_id'], 'hours' => $hours, 'interval' => $interval]) }}"
                               class="text-xs text-blue-400 hover:text-blue-300">Аналитика пар →</a>
                        </div>
                        {{-- Winrate платформы и фактический PO за период — две строки под заголовком. --}}
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-zinc-300 sm:text-sm">
                            <span class="rounded border border-zinc-700 bg-zinc-900/70 px-2.5 py-1">
                                Платформа:
                                @if($series['winrate'] !== null)
                                    <span class="font-semibold text-zinc-100">{{ number_format((float) $series['winrate'], 2) }}%</span>
                                @else
                                    <span class="font-semibold text-zinc-400">нет данных</span>
                                @endif
                                <span class="text-zinc-500 ml-1">
                                    W <span class="text-emerald-400">{{ (int) $series['wins'] }}</span>
                                    / L <span class="text-rose-400">{{ (int) $series['losses'] }}</span>
                                </span>
                            </span>
                            <span class="rounded border border-sky-800/70 bg-sky-950/40 px-2.5 py-1">
                                PO:
                                @if(($series['po_winrate'] ?? null) !== null)
                                    <span class="font-semibold text-sky-400">{{ number_format((float) $series['po_winrate'], 2) }}%</span>
                                @else
                                    <span class="font-semibold text-zinc-400">нет данных</span>
                                @endif
                                <span class="text-zinc-500 ml-1">
                                    W <span class="text-emerald-400">{{ (int) ($series['po_wins'] ?? 0) }}</span>
                                    / L <span class="text-rose-400">{{ (int) ($series['po_losses'] ?? 0) }}</span>
                                </span>
                            </span>
                        </div>
                    </div>
                    {{-- Левая подпись оси Y: сверху 100% (максимальный winrate), снизу 0%. --}}
                    <div class="flex gap-2 min-w-[280px]">
                        <div class="flex flex-col justify-between text-[10px] tracking-wide text-zinc-500 py-1 shrink-0 select-none text-right" aria-hidden="true">
                            <span class="text-emerald-500/90">Winrate<br><span class="text-zinc-500">100%</span></span>
                            <span class="text-zinc-500">0%</span>
                        </div>
                        {{-- Фиксированная высота — Chart.js заполняет контейнер --}}
                        {{-- Чуть выше h-56 — место под точки и подписи w/l. --}}
                        <div class="relative h-72 flex-1 min-w-0">
                            <canvas id="bot-chart-{{ $series['bot_id'] }}" aria-label="График winrate бота {{ $series['name'] }}"></canvas>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @else
        <div class="rounded border border-zinc-800 bg-zinc-900/60 p-4 text-zinc-400">
            Нет включённых ботов — включите бота на странице списка, чтобы увидеть графики.
        </div>
    @endif

    @if($rows->isEmpty())
        <div class="rounded border border-zinc-800 bg-zinc-900/60 p-4 text-zinc-400">
            Пока нет сделок для аналитики за выбранный период.
        </div>
    @else
        <div class="overflow-x-auto rounded border border-zinc-800">
            <table class="min-w-full text-sm">
                <thead class="bg-zinc-900 text-zinc-400 uppercase text-xs">
                    <tr>
                        <th class="px-4 py-3 text-left">Время</th>
                        <th class="px-4 py-3 text-left">Бот</th>
                        <th class="px-4 py-3 text-left">Сделок</th>
                        <th class="px-4 py-3 text-left">Wins</th>
                        <th class="px-4 py-3 text-left">Losses</th>
                        <th class="px-4 py-3 text-left">Winrate</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-800 bg-zinc-950/40">
                    @foreach($rows as $r)
                        @php
                            $total = (int) $r->trades_total;
                            $wins = (int) $r->wins;
                            $losses = (int) $r->losses;
                            $wr = $total > 0 ? round(($wins * 100) / $total, 1) : 0;
                        @endphp
                        <tr class="hover:bg-zinc-900/50">
                            <td class="px-4 py-3 text-zinc-300">{{ \Carbon\Carbon::createFromTimestamp((int) $r->bucket_ts)->timezone(config('app.timezone'))->format('d.m H:i') }}</td>
                            <td class="px-4 py-3 text-zinc-100">{{ $r->bot_name }}</td>
                            <td class="px-4 py-3 text-zinc-300">{{ $total }}</td>
                            <td class="px-4 py-3 text-emerald-400">{{ $wins }}</td>
                            <td class="px-4 py-3 text-rose-400">{{ $losses }}</td>
                            <td class="px-4 py-3 text-zinc-300">{{ $wr }}%</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

@if(!empty($chartSeries))
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.6/dist/chart.umd.min.js" crossorigin="anonymous"></script>
    <script>
        (function () {
            const labels = @json($chartLabels);
            const seriesList = @json($chartSeries);
            const grid = 'rgba(82, 82, 91, 0.45)';
            const text = 'rgb(161, 161, 170)';

            // Подпись wN/lN у каждой точки с данными (платформа сверху, PO снизу).
            const wlPointLabelsPlugin = {
                id: 'wlPointLabels',
                afterDatasetsDraw: function (chart) {
                    const ctx = chart.ctx;
                    chart.data.datasets.forEach(function (dataset, datasetIndex) {
                        const meta = chart.getDatasetMeta(datasetIndex);
                        if (!meta || meta.hidden) return;
                        meta.data.forEach(function (el, i) {
                            const v = dataset.data[i];
                            if (v === null || typeof v === 'undefined') return;
                            const w = (dataset.bucketWins && dataset.bucketWins[i]) || 0;
                            const l = (dataset.bucketLosses && dataset.bucketLosses[i]) || 0;
                            if (w + l === 0) return;
                            const pos = el.getProps(['x', 'y'], true);
                            const above = datasetIndex === 0;
                            ctx.save();
                            ctx.font = 'bold 9px ui-sans-serif, system-ui, sans-serif';
                            ctx.fillStyle = dataset.borderColor || text;
                            ctx.textAlign = 'center';
                            ctx.textBaseline = above ? 'bottom' : 'top';
                            ctx.fillText('w' + w + '/l' + l, pos.x, pos.y + (above ? -7 : 8));
                            ctx.restore();
                        });
                    });
                },
            };

            seriesList.forEach(function (series) {
                const canvas = document.getElementById('bot-chart-' + series.bot_id);
                if (!canvas || typeof Chart === 'undefined') return;

                new Chart(canvas.getContext('2d'), {
                    type: 'line',
                    data: {
                        labels: labels,
                        datasets: [
                            {
                                // Paper / платформенный winrate по свечам.
                                label: 'Платформа',
                                data: series.values,
                                bucketWins: series.bucket_wins,
                                bucketLosses: series.bucket_losses,
                                spanGaps: true,
                                borderColor: 'rgb(244, 244, 245)',
                                backgroundColor: 'rgba(244, 244, 245, 0.08)',
                                borderWidth: 2,
                                tension: 0.35,
                                fill: false,
                                // Все бакеты с данными — видимые точки.
                                pointRadius: 3,
                                pointHoverRadius: 5,
                                pointBackgroundColor: 'rgb(244, 244, 245)',
                            },
                            {
                                // Фактический winrate по сделкам, закрытым на Pocket Option.
                                label: 'PO',
                                data: series.po_values || [],
                                bucketWins: series.po_bucket_wins || [],
                                bucketLosses: series.po_bucket_losses || [],
                                spanGaps: true,
                                borderColor: 'rgb(56, 189, 248)',
                                backgroundColor: 'rgba(56, 189, 248, 0.08)',
                                borderWidth: 2,
                                tension: 0.35,
                                fill: false,
                                pointRadius: 3,
                                pointHoverRadius: 5,
                                pointBackgroundColor: 'rgb(56, 189, 248)',
                            },
                        ],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        layout: {
                            // Место под подписи w/l над/под точками.
                            padding: { top: 14, bottom: 10 },
                        },
                        plugins: {
                            legend: {
                                display: true,
                                labels: {
                                    color: text,
                                    boxWidth: 12,
                                    boxHeight: 2,
                                    padding: 12,
                                },
                            },
                            tooltip: {
                                callbacks: {
                                    label: function (ctx) {
                                        const idx = ctx.dataIndex;
                                        const wins = ctx.dataset.bucketWins[idx] ?? 0;
                                        const losses = ctx.dataset.bucketLosses[idx] ?? 0;
                                        const total = wins + losses;
                                        const v = ctx.parsed.y;
                                        const name = ctx.dataset.label || 'Winrate';

                                        if (total === 0 || v === null || typeof v === 'undefined') {
                                            return name + ': нет данных';
                                        }

                                        return name + ': ' + v + '% (W ' + wins + ' / L ' + losses + ')';
                                    },
                                },
                            },
                        },
                        scales: {
                            x: {
                                ticks: {
                                    color: text,
                                    // Чаще подписываем ось времени: до ~1 подписи на бакет при умеренной длине шкалы.
                                    maxRotation: 45,
                                    minRotation: 45,
                                    autoSkip: true,
                                    autoSkipPadding: 4,
                                    maxTicksLimit: Math.min(Math.max(labels.length, 12), 48),
                                    font: { size: 10 },
                                },
                                grid: { color: grid },
                            },
                            y: {
                                min: 0,
                                max: 100,
                                ticks: {
                                    color: text,
                                    callback: function (value) {
                                        return value + '%';
                                    },
                                },
                                grid: {
                                    color: function (ctx) {
                                        const v = ctx.tick.value;
                                        if (v === 0 || (typeof v === 'number' && Math.abs(v) < 1e-9)) {
                                            return 'rgba(244, 244, 245, 0.35)';
                                        }
                                        return grid;
                                    },
                                },
                            },
                        },
                    },
                    plugins: [wlPointLabelsPlugin],
                });
            });
        })();
    </script>
@endif
@endsection
