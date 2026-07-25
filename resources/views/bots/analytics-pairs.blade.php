@extends('layouts.app')
@section('title', 'Аналитика пар — '.$bot->name)
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
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="space-y-1">
            <h1 class="text-2xl font-semibold">Аналитика пар</h1>
            <p class="text-sm text-zinc-400">
                Бот: <span class="text-zinc-200">{{ $bot->name }}</span>
                — winrate по каждой валютной паре во времени
            </p>
        </div>
        <a href="{{ route('bots.analytics', ['hours' => $hours, 'interval' => $interval]) }}"
           class="text-sm text-blue-400 hover:text-blue-300">← К аналитике ботов</a>
    </div>

    {{-- Бот / период / интервал — при смене любого поля форма уходит GET-ом. --}}
    <form method="GET" class="flex flex-wrap items-center gap-3">
        <label for="bot_id_select" class="text-sm text-zinc-400">Бот:</label>
        <select id="bot_id_select" name="bot_id"
                class="rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100 min-w-[12rem]"
                onchange="this.form.submit()">
            @foreach($bots as $b)
                <option value="{{ $b->id }}" @selected((int) $bot->id === (int) $b->id)>
                    {{ $b->name }}@if(! $b->is_active) (выкл)@endif
                </option>
            @endforeach
        </select>
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
        <span class="text-xs text-zinc-500">Ось X — время; ось Y — winrate 0–100% по паре в каждом интервале.</span>
    </form>

    {{-- Сводка по всем парам выбранного бота за период. --}}
    @if($overallWinrate !== null || $overallWins > 0 || $overallLosses > 0)
        <div class="flex flex-wrap items-center gap-3 text-sm">
            <span class="rounded border border-zinc-700 bg-zinc-900/70 px-3 py-1.5 text-zinc-200">
                Winrate бота (все пары):
                @if($overallWinrate !== null)
                    <span class="font-semibold text-emerald-400">{{ number_format((float) $overallWinrate, 2) }}%</span>
                @else
                    <span class="font-semibold text-zinc-400">нет данных</span>
                @endif
            </span>
            <span class="text-xs text-zinc-500">
                Wins: <span class="text-emerald-400">{{ (int) $overallWins }}</span>,
                Losses: <span class="text-rose-400">{{ (int) $overallLosses }}</span>
            </span>
        </div>
    @endif

    {{-- График на каждую пару, по которой были сделки за период. --}}
    @if(!empty($chartSeries))
        <div class="space-y-6">
            @foreach($chartSeries as $series)
                <div class="rounded border border-zinc-800 bg-zinc-950/40 p-4">
                    <div class="mb-3 space-y-2">
                        <h2 class="text-sm font-medium text-zinc-200">{{ $series['instrument'] }}</h2>
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-zinc-300 sm:text-sm">
                            <span class="rounded border border-zinc-700 bg-zinc-900/70 px-2.5 py-1">
                                Winrate:
                                @if($series['winrate'] !== null)
                                    <span class="font-semibold text-emerald-400">{{ number_format((float) $series['winrate'], 2) }}%</span>
                                @else
                                    <span class="font-semibold text-zinc-400">нет данных</span>
                                @endif
                            </span>
                            <span class="text-zinc-500">
                                Wins: <span class="text-emerald-400">{{ (int) $series['wins'] }}</span>,
                                Losses: <span class="text-rose-400">{{ (int) $series['losses'] }}</span>
                            </span>
                        </div>
                    </div>
                    <div class="flex gap-2 min-w-[280px]">
                        <div class="flex flex-col justify-between text-[10px] tracking-wide text-zinc-500 py-1 shrink-0 select-none text-right" aria-hidden="true">
                            <span class="text-emerald-500/90">Winrate<br><span class="text-zinc-500">100%</span></span>
                            <span class="text-zinc-500">0%</span>
                        </div>
                        {{-- Чуть выше — место под точки и подписи w/l. --}}
                        <div class="relative h-72 flex-1 min-w-0">
                            <canvas id="pair-chart-{{ $series['slug'] }}"
                                    aria-label="График winrate пары {{ $series['instrument'] }}"></canvas>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @else
        <div class="rounded border border-zinc-800 bg-zinc-900/60 p-4 text-zinc-400">
            За выбранный период у бота «{{ $bot->name }}» нет сделок с указанной парой.
        </div>
    @endif

    @if($rows->isEmpty())
        <div class="rounded border border-zinc-800 bg-zinc-900/60 p-4 text-zinc-400">
            Пока нет сделок для таблицы за выбранный период.
        </div>
    @else
        <div class="overflow-x-auto rounded border border-zinc-800">
            <table class="min-w-full text-sm">
                <thead class="bg-zinc-900 text-zinc-400 uppercase text-xs">
                    <tr>
                        <th class="px-4 py-3 text-left">Время</th>
                        <th class="px-4 py-3 text-left">Пара</th>
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
                            <td class="px-4 py-3 text-zinc-100">{{ $r->instrument }}</td>
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

            // Подпись wN/lN над каждой точкой с данными.
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
                            ctx.save();
                            ctx.font = 'bold 9px ui-sans-serif, system-ui, sans-serif';
                            ctx.fillStyle = dataset.borderColor || text;
                            ctx.textAlign = 'center';
                            ctx.textBaseline = 'bottom';
                            ctx.fillText('w' + w + '/l' + l, pos.x, pos.y - 7);
                            ctx.restore();
                        });
                    });
                },
            };

            seriesList.forEach(function (series) {
                const canvas = document.getElementById('pair-chart-' + series.slug);
                if (!canvas || typeof Chart === 'undefined') return;

                new Chart(canvas.getContext('2d'), {
                    type: 'line',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'Winrate, %',
                            data: series.values,
                            // Wins/Losses по бакету — на точке и в tooltip.
                            bucketWins: series.bucket_wins,
                            bucketLosses: series.bucket_losses,
                            // Пустые бакеты (null) не рвут линию между интервалами с данными.
                            spanGaps: true,
                            borderColor: 'rgb(244, 244, 245)',
                            backgroundColor: 'rgba(244, 244, 245, 0.12)',
                            borderWidth: 2,
                            tension: 0.35,
                            fill: true,
                            pointRadius: 3,
                            pointHoverRadius: 5,
                            pointBackgroundColor: 'rgb(244, 244, 245)',
                        }],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        layout: {
                            padding: { top: 14, bottom: 4 },
                        },
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    label: function (ctx) {
                                        const idx = ctx.dataIndex;
                                        const wins = ctx.dataset.bucketWins[idx] ?? 0;
                                        const losses = ctx.dataset.bucketLosses[idx] ?? 0;
                                        const total = wins + losses;
                                        const v = ctx.parsed.y;

                                        if (total === 0 || v === null || typeof v === 'undefined') {
                                            return [
                                                'Winrate: нет данных',
                                                'Win: 0',
                                                'Loss: 0',
                                            ];
                                        }

                                        return [
                                            'Winrate: ' + v + '%',
                                            'Win: ' + wins,
                                            'Loss: ' + losses,
                                        ];
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
