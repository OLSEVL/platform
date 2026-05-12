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
@if(session('error'))
    <p class="mb-4 p-3 bg-red-950/60 border border-red-800 text-red-200 rounded">{{ session('error') }}</p>
@endif
<div class="bg-zinc-900 border border-zinc-800 rounded-lg shadow-lg shadow-black/20 overflow-hidden">
    <table class="min-w-full divide-y divide-zinc-800">
        <thead class="bg-zinc-800/80">
            <tr>
                {{-- Порядковый номер строки в списке (как в таблице оператора): удобно ссылаться на «3-й инструмент» без поиска по коду. --}}
                <th class="w-14 px-3 py-3 text-center text-xs font-medium text-zinc-400 uppercase">№</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-zinc-400 uppercase">Инструмент</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-zinc-400 uppercase">Название</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-zinc-400 uppercase">Приоритет</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-zinc-400 uppercase">Статус</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-zinc-400 uppercase">Свои auth</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-zinc-400 uppercase">Прокси</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-zinc-400 uppercase">Payout</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-zinc-400 uppercase">Источник</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-zinc-400 uppercase">Обновлён Payout</th>
                <th class="px-6 py-3 text-right text-xs font-medium text-zinc-400 uppercase">Действия</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-zinc-800 text-zinc-200">
            @forelse($instruments as $i)
            <tr class="hover:bg-zinc-800/50">
                <td class="px-3 py-4 text-center text-sm tabular-nums text-zinc-500">{{ $loop->iteration }}</td>
                <td class="px-6 py-4 font-mono text-zinc-300">{{ $i->instrument }}</td>
                <td class="px-6 py-4">{{ $i->display_name ?? '—' }}</td>
                <td class="px-6 py-4">{{ $i->priority }}</td>
                <td class="px-6 py-4">
                    <div class="text-zinc-200">{{ $i->is_active ? 'Вкл' : 'Выкл' }}</div>
                    <form action="{{ route('instruments.toggle-active', $i) }}" method="POST" class="mt-2">
                        @csrf
                        {{-- Кнопка-переключатель активности инструмента:
                             даёт быстрый контроль запуска/остановки инструмента прямо из таблицы,
                             без перехода в редактирование и без риска промахнуться по другому полю. --}}
                        <button
                            type="submit"
                            class="inline-flex items-center px-2.5 py-1 rounded border text-[11px] font-medium transition-colors {{ $i->is_active ? 'border-emerald-700/70 text-emerald-300 hover:bg-emerald-900/30' : 'border-amber-700/70 text-amber-300 hover:bg-amber-900/30' }}"
                        >
                            {{ $i->is_active ? 'Выключить' : 'Включить' }}
                        </button>
                    </form>
                </td>
                <td class="px-6 py-4">{{ $i->session_token ? 'Да' : 'Нет' }}</td>
                <td class="px-6 py-4">
                    @if($i->proxyEndpoint)
                        @php
                            $proxyStatusClass = match($i->proxyEndpoint->status) {
                                'active' => 'text-emerald-400',
                                'cooldown' => 'text-amber-400',
                                'dead' => 'text-rose-400',
                                default => 'text-zinc-400',
                            };
                        @endphp
                        {{-- Показываем полный URL прокси без сокращения, чтобы оператор видел endpoint целиком. --}}
                        <div class="text-xs font-mono text-zinc-300 break-all">
                            {{ $i->proxyEndpoint->url }}
                        </div>
                        <div class="text-xs {{ $proxyStatusClass }}">{{ $i->proxyEndpoint->status }}</div>
                        @if($i->proxyEndpoint->last_error)
                            {{-- Встроенная диагностика в строке инструмента: видно последнюю причину деградации прокси. --}}
                            <div class="text-[11px] text-rose-300 break-all" title="{{ $i->proxyEndpoint->last_error }}">
                                err: {{ $i->proxyEndpoint->last_error }} ({{ (int) ($i->proxyEndpoint->failure_count ?? 0) }})
                            </div>
                        @endif
                        @if($i->proxyEndpoint->last_failed_at)
                            <div class="text-[11px] text-zinc-500">
                                fail: {{ $i->proxyEndpoint->last_failed_at->timezone(config('app.timezone'))->format('d.m H:i:s') }}
                            </div>
                        @endif
                    @else
                        <span class="text-zinc-500">—</span>
                    @endif
                    @php
                        // Единый базовый стиль для proxy-действий в колонке "Прокси".
                        $proxyButtonBase = 'inline-flex items-center px-2.5 py-1 rounded-md border text-[11px] font-medium transition-colors';
                    @endphp
                    <div class="mt-2 flex flex-wrap gap-2">
                        <form action="{{ route('instruments.proxy.replace', $i) }}" method="POST" class="inline">
                            @csrf
                            <button type="submit" class="{{ $proxyButtonBase }} border-emerald-700/70 text-emerald-300 hover:bg-emerald-900/30 hover:text-emerald-200">Заменить прокси</button>
                        </form>
                        <form action="{{ route('instruments.proxy.replace-mark-dead', $i) }}" method="POST" class="inline" onsubmit="return confirm('Пометить текущий прокси как нерабочий и сразу заменить?');">
                            @csrf
                            <button type="submit" class="{{ $proxyButtonBase }} border-amber-700/70 text-amber-300 hover:bg-amber-900/30 hover:text-amber-200">Пометить bad + заменить</button>
                        </form>
                        <form action="{{ route('instruments.proxy.remove', $i) }}" method="POST" class="inline" onsubmit="return confirm('Удалить прокси у инструмента и работать без прокси?');">
                            @csrf
                            <button type="submit" class="{{ $proxyButtonBase }} border-zinc-700 text-zinc-300 hover:bg-zinc-800 hover:text-zinc-100">Удалить прокси</button>
                        </form>
                    </div>
                </td>
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
                        @php
                            // Порог "устаревшего" payout управляется из раздела "Настройки".
                            // Это даёт оператору гибкость: на волатильном рынке можно ужесточить
                            // алерт, а в спокойные часы — ослабить без правки кода.
                            $staleThresholdMinutes = (int) ($payoutStaleMinutesThreshold ?? 5);
                            $isPayoutStale = $i->last_payout_at->lt(now()->subMinutes($staleThresholdMinutes));
                        @endphp
                        <span title="{{ $i->last_payout_at->timezone(config('app.timezone'))->toIso8601String() }}">
                            <span class="{{ $isPayoutStale ? 'text-rose-300 font-medium' : '' }}">
                                {{ $i->last_payout_at->timezone(config('app.timezone'))->format('d.m.Y H:i') }}
                            </span>
                            <span class="{{ $isPayoutStale ? 'text-rose-400' : 'text-zinc-500' }}">
                                ({{ $i->last_payout_at->diffForHumans() }})
                            </span>
                        </span>
                    @else
                        <span class="text-zinc-500">—</span>
                    @endif
                </td>
                <td class="px-6 py-4 text-right">
                    @php
                        // Единый базовый стиль для всех action-кнопок:
                        // так "Изменить / Заменить / Удалить" выглядят как единый UI-контрол,
                        // а не как набор текстовых ссылок с разным визуальным весом.
                        $actionButtonBase = 'inline-flex items-center px-3 py-1.5 rounded-md border text-xs font-medium transition-colors';
                    @endphp
                    <div class="flex flex-wrap justify-end gap-2">
                        <a href="{{ route('instruments.edit', $i) }}" class="{{ $actionButtonBase }} border-blue-700/70 text-blue-300 hover:bg-blue-900/30 hover:text-blue-200">Изменить</a>
                    <form action="{{ route('instruments.destroy', $i) }}" method="POST" class="inline" onsubmit="return confirm('Удалить?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="{{ $actionButtonBase }} border-red-700/70 text-red-300 hover:bg-red-900/30 hover:text-red-200">Удалить</button>
                    </form>
                    </div>
                </td>
            </tr>
            @empty
            <tr><td colspan="11" class="px-6 py-8 text-center text-zinc-500">Нет инструментов. <a href="{{ route('instruments.create') }}" class="text-blue-400 hover:underline">Добавить</a></td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
