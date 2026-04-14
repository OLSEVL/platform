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
@endphp
<div class="space-y-6">
    <h1 class="text-2xl font-semibold">Аналитика ботов</h1>

    {{-- Период: выпадающий список + авто-отправка формы при смене --}}
    <form method="GET" class="flex flex-wrap items-center gap-3">
        <label for="hours" class="text-sm text-zinc-400">Период:</label>
        <select id="hours" name="hours"
                class="rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100 min-w-[14rem]"
                onchange="this.form.submit()">
            @foreach($periodPresets as $h => $label)
                <option value="{{ $h }}" @selected((int) $hours === (int) $h)>{{ $label }}</option>
            @endforeach
        </select>
        <span class="text-xs text-zinc-500">Ось X — время; ось Y — winrate в процентах от 0% до 100% для каждого временного интервала.</span>
    </form>

    @if(!empty($chartSeries))
        <div class="space-y-6">
            @foreach($chartSeries as $series)
                <div class="rounded border border-zinc-800 bg-zinc-950/40 p-4">
                    {{-- Название бота и общий winrate за период в одной строке (сводка по всем ботов — один раз у первого графика). --}}
                    <div class="mb-3 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-2">
                        <h2 class="text-sm font-medium text-zinc-200">{{ $series['name'] }}</h2>
                        @if($loop->first)
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-zinc-300 sm:text-sm">
                                <span class="rounded border border-zinc-700 bg-zinc-900/70 px-2.5 py-1">
                                    Общий winrate:
                                    @if($overallWinrate !== null)
                                        <span class="font-semibold text-emerald-400">{{ number_format((float) $overallWinrate, 2) }}%</span>
                                    @else
                                        <span class="font-semibold text-zinc-400">нет данных</span>
                                    @endif
                                </span>
                                <span class="text-zinc-500">
                                    Wins: <span class="text-emerald-400">{{ (int) $overallWins }}</span>,
                                    Losses: <span class="text-rose-400">{{ (int) $overallLosses }}</span>
                                </span>
                            </div>
                        @endif
                    </div>
                    {{-- Левая подпись оси Y: сверху 100% (максимальный winrate), снизу 0%. --}}
                    <div class="flex gap-2 min-w-[280px]">
                        <div class="flex flex-col justify-between text-[10px] tracking-wide text-zinc-500 py-1 shrink-0 select-none text-right" aria-hidden="true">
                            <span class="text-emerald-500/90">Winrate<br><span class="text-zinc-500">100%</span></span>
                            <span class="text-zinc-500">0%</span>
                        </div>
                        {{-- Фиксированная высота — Chart.js заполняет контейнер --}}
                        <div class="relative h-56 flex-1 min-w-0">
                            <canvas id="bot-chart-{{ $series['bot_id'] }}" aria-label="График win/loss"></canvas>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @else
        {{-- Нет графиков (нет win/loss) — общий winrate остаётся под формой. --}}
        @if($overallWinrate !== null || $overallWins > 0 || $overallLosses > 0)
            <div class="flex flex-wrap items-center gap-3 text-sm">
                <span class="rounded border border-zinc-700 bg-zinc-900/70 px-3 py-1.5 text-zinc-200">
                    Общий winrate:
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
                            <td class="px-4 py-3 text-zinc-300">{{ $r->bucket }}</td>
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

            seriesList.forEach(function (series) {
                const canvas = document.getElementById('bot-chart-' + series.bot_id);
                if (!canvas || typeof Chart === 'undefined') return;

                new Chart(canvas.getContext('2d'), {
                    type: 'line',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'Winrate, %',
                            data: series.values,
                            // Пропуски (null) между интервалами с данными — рисуем линию без обрыва на пустых бакетах.
                            spanGaps: true,
                            borderColor: 'rgb(239, 68, 68)',
                            backgroundColor: 'rgba(239, 68, 68, 0.12)',
                            borderWidth: 2,
                            tension: 0.35,
                            fill: true,
                            pointRadius: 0,
                            pointHoverRadius: 4,
                        }],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    label: function (ctx) {
                                        const v = ctx.parsed.y;
                                        if (v === null || typeof v === 'undefined') {
                                            return 'Winrate: нет данных';
                                        }
                                        return 'Winrate: ' + v + '%';
                                    },
                                },
                            },
                        },
                        scales: {
                            x: {
                                ticks: {
                                    color: text,
                                    maxRotation: 45,
                                    minRotation: 0,
                                    autoSkip: true,
                                    maxTicksLimit: 12,
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
                });
            });
        })();
    </script>
@endif
@endsection
