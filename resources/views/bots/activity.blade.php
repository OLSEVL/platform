@extends('layouts.app')
@section('title', $bot->name.' — сигналы и сделки')
@section('content')
<div class="space-y-4">
    <div class="flex flex-wrap items-baseline justify-between gap-3">
        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
            <h1 class="text-2xl font-semibold">{{ $bot->name }}</h1>
            <span class="text-sm text-zinc-500">Сигналы и сделки</span>
        </div>
        <div class="flex flex-wrap items-center gap-2 text-sm">
            <a href="{{ route('bots.show', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">← График</a>
            <a href="{{ route('bots.settings', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">Настройки</a>
            <a href="{{ route('bots.index') }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">К списку</a>
        </div>
    </div>

    <div class="rounded border border-zinc-800 overflow-hidden">
        <div class="px-4 py-3 bg-zinc-900 text-sm text-zinc-300">Сигнал → итог сделки</div>
        <div class="divide-y divide-zinc-800 max-h-[min(75vh,40rem)] overflow-y-auto">
            @forelse($signalsWithTrades as $s)
                @php
                    $t = $s->trade;
                @endphp
                <div class="px-4 py-3 text-sm space-y-2">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="space-y-0.5 min-w-0">
                            <div class="font-medium text-zinc-100">
                                {{ $s->instrument }}
                                <span class="text-zinc-500">·</span>
                                {{ $s->side }}
                                <span class="text-zinc-500 font-normal">·</span>
                                <span class="text-zinc-400 font-normal tabular-nums">{{ number_format((float) $s->confidence, 2) }}</span>
                                confidence
                            </div>
                            <div class="text-xs text-zinc-500">
                                Сигнал: {{ optional($s->signal_at)->format('Y-m-d H:i:s') }}
                                @if($t)
                                    <span class="text-zinc-600">·</span>
                                    Сделка: {{ optional($t->entry_time)->format('H:i:s') }}
                                    @if($t->status === 'settled' && $t->settled_at)
                                        <span class="text-zinc-600">→</span> закрыта {{ $t->settled_at->format('Y-m-d H:i:s') }}
                                    @endif
                                @endif
                            </div>
                        </div>
                        <div class="shrink-0 text-right space-y-0.5">
                            @if($t)
                                <div>
                                    @if($t->result === 'win')
                                        <span class="inline-flex rounded px-2 py-0.5 text-xs font-medium bg-emerald-950 text-emerald-300 border border-emerald-800">win</span>
                                    @elseif($t->result === 'loss')
                                        <span class="inline-flex rounded px-2 py-0.5 text-xs font-medium bg-rose-950 text-rose-300 border border-rose-800">loss</span>
                                    @elseif($t->result === 'draw')
                                        <span class="inline-flex rounded px-2 py-0.5 text-xs font-medium bg-amber-950 text-amber-200 border border-amber-800">draw</span>
                                    @else
                                        <span class="inline-flex rounded px-2 py-0.5 text-xs font-medium bg-zinc-800 text-zinc-300 border border-zinc-700">pending</span>
                                    @endif
                                    @if($t->status === 'open')
                                        <span class="text-zinc-500 ml-1 text-xs">открыта</span>
                                    @endif
                                </div>
                                <div class="text-zinc-400 tabular-nums text-xs">
                                    PnL: {{ $t->pnl !== null ? number_format((float) $t->pnl, 4) : '—' }}
                                    @if($t->entry_price !== null && $t->expiry_price !== null)
                                        <span class="text-zinc-600">·</span>
                                        {{ number_format((float) $t->entry_price, 5) }}
                                        →
                                        {{ number_format((float) $t->expiry_price, 5) }}
                                    @endif
                                </div>
                            @else
                                <span class="text-xs text-zinc-500">Сделка не найдена</span>
                            @endif
                        </div>
                    </div>
                    @if($s->analysis_text)
                        <div class="text-zinc-400 text-sm leading-relaxed">{{ $s->analysis_text }}</div>
                    @endif
                    @if(is_array($s->reasons_json) && count($s->reasons_json) > 0)
                        <div class="text-xs text-zinc-500">{{ implode(' · ', $s->reasons_json) }}</div>
                    @endif
                </div>
            @empty
                <div class="px-4 py-6 text-sm text-zinc-500">Сигналов пока нет.</div>
            @endforelse
        </div>
    </div>
</div>
@endsection
