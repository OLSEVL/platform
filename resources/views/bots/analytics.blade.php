@extends('layouts.app')
@section('title', 'Аналитика ботов')
@section('content')
<div class="space-y-4">
    <h1 class="text-2xl font-semibold">Аналитика ботов</h1>
    <form method="GET" class="flex items-center gap-2">
        <label for="hours" class="text-sm text-zinc-400">Период (часы):</label>
        <input id="hours" name="hours" type="number" min="1" max="168" value="{{ $hours }}"
               class="w-24 rounded border border-zinc-700 bg-zinc-900 px-2 py-1 text-sm">
        <button type="submit" class="rounded bg-zinc-800 px-3 py-1 text-sm hover:bg-zinc-700">Применить</button>
    </form>

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
@endsection

