@extends('layouts.app')
@section('title', 'Инструменты')
@section('content')
<div class="flex justify-between items-center mb-6">
    <h1 class="text-2xl font-bold text-zinc-100">Инструменты</h1>
    <a href="{{ route('instruments.create') }}" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-500">Добавить</a>
</div>
@if(session('success'))
    <p class="mb-4 p-3 bg-emerald-950/60 border border-emerald-800 text-emerald-200 rounded">{{ session('success') }}</p>
@endif
<div class="bg-zinc-900 border border-zinc-800 rounded-lg shadow-lg shadow-black/20 overflow-hidden">
    <table class="min-w-full divide-y divide-zinc-800">
        <thead class="bg-zinc-800/80">
            <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-zinc-400 uppercase">Инструмент</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-zinc-400 uppercase">Название</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-zinc-400 uppercase">Приоритет</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-zinc-400 uppercase">Статус</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-zinc-400 uppercase">Свои auth</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-zinc-400 uppercase">Payout</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-zinc-400 uppercase">Источник</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-zinc-400 uppercase">Обновлён Payout</th>
                <th class="px-6 py-3 text-right text-xs font-medium text-zinc-400 uppercase">Действия</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-zinc-800 text-zinc-200">
            @forelse($instruments as $i)
            <tr class="hover:bg-zinc-800/50">
                <td class="px-6 py-4 font-mono text-zinc-300">{{ $i->instrument }}</td>
                <td class="px-6 py-4">{{ $i->display_name ?? '—' }}</td>
                <td class="px-6 py-4">{{ $i->priority }}</td>
                <td class="px-6 py-4">{{ $i->is_active ? 'Вкл' : 'Выкл' }}</td>
                <td class="px-6 py-4">{{ $i->session_token ? 'Да' : 'Нет' }}</td>
                <td class="px-6 py-4">
                    @if($i->current_payout !== null)
                        @php
                            $payout = (float) $i->current_payout;
                            $payoutClass = $payout >= 80
                                ? 'text-emerald-400'
                                : ($payout >= 60 ? 'text-amber-400' : 'text-rose-400');
                        @endphp
                        <span class="font-medium {{ $payoutClass }}">{{ number_format($payout, 0) }}%</span>
                    @else
                        <span class="text-zinc-500">—</span>
                    @endif
                </td>
                <td class="px-6 py-4">{{ $i->payout_source ?? '—' }}</td>
                <td class="px-6 py-4">
                    @if($i->last_payout_at)
                        {{-- Показываем конкретное локальное время + ISO в title для быстрой визуальной и технической сверки timezone. --}}
                        <span title="{{ $i->last_payout_at->timezone(config('app.timezone'))->toIso8601String() }}">
                            {{ $i->last_payout_at->timezone(config('app.timezone'))->format('d.m.Y H:i') }}
                            <span class="text-zinc-500">({{ $i->last_payout_at->diffForHumans() }})</span>
                        </span>
                    @else
                        <span class="text-zinc-500">—</span>
                    @endif
                </td>
                <td class="px-6 py-4 text-right">
                    <a href="{{ route('instruments.edit', $i) }}" class="text-blue-400 hover:text-blue-300 hover:underline mr-2">Изменить</a>
                    <form action="{{ route('instruments.destroy', $i) }}" method="POST" class="inline" onsubmit="return confirm('Удалить?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="text-red-400 hover:text-red-300 hover:underline">Удалить</button>
                    </form>
                </td>
            </tr>
            @empty
            <tr><td colspan="9" class="px-6 py-8 text-center text-zinc-500">Нет инструментов. <a href="{{ route('instruments.create') }}" class="text-blue-400 hover:underline">Добавить</a></td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
