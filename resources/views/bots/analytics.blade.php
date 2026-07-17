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
        <span class="text-xs text-zinc-500">Ось X — время; ось Y — winrate 0–100%. Красный — платформа, голубой — фактический PO.</span>
    </form>

    {{-- Сводка по всем ботам за период — paper и PO рядом. --}}
    @if($overallWinrate !== null || $overallWins > 0 || $overallLosses > 0 || $overallPoWinrate !== null || ($overallPoWins ?? 0) > 0 || ($overallPoLosses ?? 0) > 0)
        <div class="flex flex-wrap items-center gap-3 text-sm">
            <span class="rounded border border-zinc-700 bg-zinc-900/70 px-3 py-1.5 text-zinc-200">
                Платформа:
                @if($overallWinrate !== null)
                    <span class="font-semibold text-rose-400">{{ number_format((float) $overallWinrate, 2) }}%</span>
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

    {{-- График для каждого включённого бота; пустой canvas, если за период нет сделок. --}}
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
                                    <span class="font-semibold text-rose-400">{{ number_format((float) $series['winrate'], 2) }}%</span>
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
                        <div class="relative h-56 flex-1 min-w-0">
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
                                borderColor: 'rgb(239, 68, 68)',
                                backgroundColor: 'rgba(239, 68, 68, 0.08)',
                                borderWidth: 2,
                                tension: 0.35,
                                fill: false,
                                pointRadius: 0,
                                pointHoverRadius: 4,
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
                                pointRadius: 0,
                                pointHoverRadius: 4,
                            },
                        ],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
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
