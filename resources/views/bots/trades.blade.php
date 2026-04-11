@extends('layouts.app')
@section('title', 'История сделок')
@section('content')
<div class="space-y-4">
    <h1 class="text-2xl font-semibold">История сделок</h1>
    <form method="GET" class="flex items-center gap-2 flex-wrap">
        <select name="bot_id" class="rounded border border-zinc-700 bg-zinc-900 px-2 py-1 text-sm">
            <option value="">Все боты</option>
            @foreach($bots as $bot)
                <option value="{{ $bot->id }}" @selected($selectedBotId === $bot->id)>{{ $bot->name }}</option>
            @endforeach
        </select>
        <select name="result" class="rounded border border-zinc-700 bg-zinc-900 px-2 py-1 text-sm">
            <option value="">Все результаты</option>
            @foreach(['pending', 'win', 'loss', 'draw'] as $r)
                <option value="{{ $r }}" @selected($selectedResult === $r)>{{ $r }}</option>
            @endforeach
        </select>
        <button type="submit" class="rounded bg-zinc-800 px-3 py-1 text-sm hover:bg-zinc-700">Применить</button>
    </form>

    <div class="overflow-x-auto rounded border border-zinc-800">
        <table class="min-w-full text-sm">
            <thead class="bg-zinc-900 text-zinc-400 uppercase text-xs">
                <tr>
                    <th class="px-4 py-3 text-left">Время</th>
                    <th class="px-4 py-3 text-left">Бот</th>
                    <th class="px-4 py-3 text-left">Инструмент</th>
                    <th class="px-4 py-3 text-left">Сторона</th>
                    <th class="px-4 py-3 text-left">Цена входа</th>
                    <th class="px-4 py-3 text-left">До конца</th>
                    <th class="px-4 py-3 text-left">Результат</th>
                    <th class="px-4 py-3 text-left">PnL</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-800 bg-zinc-950/40">
                @forelse($trades as $t)
                    <tr class="hover:bg-zinc-900/50">
                        <td class="px-4 py-3 text-zinc-300">{{ optional($t->entry_time)->format('Y-m-d H:i:s') }}</td>
                        <td class="px-4 py-3 text-zinc-100">{{ $t->bot?->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-zinc-300">{{ $t->instrument }}</td>
                        <td class="px-4 py-3 text-zinc-300">{{ $t->side }}</td>
                        <td class="px-4 py-3 text-zinc-300 font-mono">{{ $t->entry_price !== null ? number_format((float)$t->entry_price, 5) : '—' }}</td>
                        <td class="px-4 py-3">
                            @if($t->status === 'open' && $t->expiry_time)
                                @php
                                    $left = max(0, now()->diffInSeconds($t->expiry_time, false));
                                    $mins = intdiv($left, 60);
                                    $secs = $left % 60;
                                @endphp
                                <span class="text-cyan-300 font-mono">{{ str_pad((string)$mins, 2, '0', STR_PAD_LEFT) }}:{{ str_pad((string)$secs, 2, '0', STR_PAD_LEFT) }}</span>
                            @elseif($t->status === 'open')
                                <span class="text-zinc-500">—</span>
                            @else
                                <span class="text-zinc-500">завершена</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @if($t->result === 'win')
                                <span class="text-emerald-400">win</span>
                            @elseif($t->result === 'loss')
                                <span class="text-rose-400">loss</span>
                            @elseif($t->result === 'draw')
                                <span class="text-amber-400">draw</span>
                            @else
                                <span class="text-zinc-400">pending</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-zinc-300">{{ $t->pnl !== null ? number_format((float)$t->pnl, 4) : '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-8 text-center text-zinc-500">Сделок пока нет.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $trades->links() }}
</div>
@endsection

