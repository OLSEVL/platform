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
@php
    $poolCap = $proxyPoolCapacity ?? [];
    $poolExhausted = (bool) ($poolCap['is_exhausted'] ?? false);
@endphp
@if($poolExhausted)
    <div class="mb-4 p-3 rounded-lg bg-amber-950/50 border border-amber-800 text-amber-200 text-sm">
        <strong>Пул прокси исчерпан.</strong>
        Свободных слотов: {{ $poolCap['free_slots'] ?? 0 }} / {{ $poolCap['total_slots'] ?? 0 }}.
        Замена и «Пометить bad + заменить» не сработают, пока не появится место
        ({{ $poolCap['ready_proxies'] ?? 0 }} ready-прокси × лимит {{ $poolCap['max_per_proxy'] ?? 1 }}).
        <a href="{{ route('settings.index') }}" class="underline text-amber-100 hover:text-white">Настройки → прокси</a>.
    </div>
@endif
<div class="bg-zinc-900 border border-zinc-800 rounded-lg shadow-lg shadow-black/20 overflow-hidden">
    <table class="min-w-full divide-y divide-zinc-800">
        <thead class="bg-zinc-800/80">
            <tr>
                {{-- Порядковый номер строки в списке (как в таблице оператора): удобно ссылаться на «3-й инструмент» без поиска по коду. --}}
                <th class="w-10 px-1 py-2 text-center text-xs font-medium text-zinc-400 uppercase">№</th>
                <th class="px-1 py-2 text-left text-xs font-medium text-zinc-400 uppercase">Инструмент</th>
                <th class="px-1 py-2 text-left text-xs font-medium text-zinc-400 uppercase">Название</th>
                <th class="px-1 py-2 text-left text-xs font-medium text-zinc-400 uppercase">Тип</th>
                <th class="px-1 py-2 text-left text-xs font-medium text-zinc-400 uppercase">Приоритет</th>
                <th class="px-1 py-2 text-left text-xs font-medium text-zinc-400 uppercase">Статус</th>
                <th class="px-1 py-2 text-left text-xs font-medium text-zinc-400 uppercase">Свои auth</th>
                <th class="px-1 py-2 text-left text-xs font-medium text-zinc-400 uppercase">Прокси</th>
                <th
                    class="px-1 py-2 text-left align-bottom min-w-[4.5rem]"
                    title="Процент выплаты (current_payout), последнее известное значение от ingest. Не означает, что PO только что прислал обновление."
                >
                    <span class="block text-xs font-medium text-zinc-300">Payout %</span>
                    <span class="block text-[10px] font-normal text-zinc-500 leading-snug mt-0.5">значение в БД</span>
                </th>
                <th
                    class="px-1 py-2 text-left align-bottom min-w-[5rem]"
                    title="Откуда пришло последнее значение payout: payout_update (WebSocket-событие PO) или json_data (поле в ответе по активу)."
                >
                    <span class="block text-xs font-medium text-zinc-300">Источник</span>
                    <span class="block text-[10px] font-normal text-zinc-500 leading-snug mt-0.5">канал от PO</span>
                </th>
                <th
                    class="px-1 py-2 text-left align-bottom min-w-[7rem]"
                    title="last_payout_event_at — когда Pocket Options в последний раз прислал payout_update или payout в json_data. Красное = давно нет события от брокера (порог в Настройках) или payout_is_stale. Не путать с POST ingest."
                >
                    <span class="block text-xs font-medium text-zinc-300">Событие PO</span>
                    <span class="block text-[10px] font-normal text-zinc-500 leading-snug mt-0.5">когда брокер прислал payout</span>
                </th>
                <th
                    class="px-1 py-2 text-left align-bottom min-w-[7rem]"
                    title="last_payout_at — когда ingest в последний раз успешно вызвал POST /api/ingest-payout (heartbeat ~30 с). Жёлтое «нет POST» = для активного инструмента нет POST &gt; 2 мин (воркер/связь). Свежий POST при stale PO — норма."
                >
                    <span class="block text-xs font-medium text-zinc-300">POST ingest</span>
                    <span class="block text-[10px] font-normal text-zinc-500 leading-snug mt-0.5">когда ingest записал в БД</span>
                </th>
                <th class="px-1 py-2 text-right text-xs font-medium text-zinc-400 uppercase">Действия</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-zinc-800 text-zinc-200">
            @forelse($instruments as $i)
            <tr class="hover:bg-zinc-800/50">
                <td class="px-1 py-2 text-center text-sm tabular-nums text-zinc-500">{{ $loop->iteration }}</td>
                <td class="px-1 py-2 font-mono text-sm whitespace-nowrap">
                    {{-- Ссылка на главную: JS графика читает ?instrument= и открывает соответствующую пару. --}}
                    <a
                        href="{{ route('dashboard', ['instrument' => $i->instrument]) }}"
                        class="text-blue-400 hover:text-blue-300 hover:underline"
                        title="Открыть график на главной"
                    >{{ $i->instrument }}</a>
                </td>
                <td class="px-1 py-2 text-sm">{{ $i->display_name ?? '—' }}</td>
                <td class="px-1 py-2 text-sm text-zinc-300 whitespace-nowrap">
                    @if((int) $i->type === \App\Models\PocketInstrument::TYPE_CRYPTO)
                        <span class="text-amber-300">2 · Крипто</span>
                    @elseif((int) $i->type === \App\Models\PocketInstrument::TYPE_STOCK)
                        <span class="text-violet-300">3 · Акции</span>
                    @elseif((int) $i->type === \App\Models\PocketInstrument::TYPE_COMMODITY)
                        <span class="text-orange-300">4 · Сырьё</span>
                    @else
                        <span class="text-sky-300">1 · Валюта</span>
                    @endif
                </td>
                <td class="px-1 py-2 text-sm">{{ $i->priority }}</td>
                <td class="px-1 py-2 text-sm">
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
                <td class="px-1 py-2 text-sm">{{ $i->session_token ? 'Да' : 'Нет' }}</td>
                <td class="px-1 py-2 text-sm">
                    @if($i->proxyEndpoint)
                        @php
                            $proxyStatusClass = match($i->proxyEndpoint->status) {
                                'active' => 'text-emerald-400',
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
                <td class="px-1 py-2 text-sm">
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
                <td class="px-1 py-2 text-sm">{{ $i->payout_source ?? '—' }}</td>
                <td class="px-1 py-2 text-sm">
                    @php
                        // Колонка отражает последнее событие payout от Pocket Options, не heartbeat ingest.
                        $payoutEventAt = $i->last_payout_event_at ?? $i->last_payout_at;
                    @endphp
                    @if($payoutEventAt)
                        @php
                            $staleThresholdMinutes = (int) ($payoutStaleMinutesThreshold ?? 5);
                            $isPayoutStale = (bool) $i->payout_is_stale
                                || $payoutEventAt->lt(now()->subMinutes($staleThresholdMinutes));
                        @endphp
                        <span title="PO event: {{ $payoutEventAt->timezone(config('app.timezone'))->toIso8601String() }}">
                            <span class="{{ $isPayoutStale ? 'text-rose-300 font-medium' : '' }}">
                                {{ $payoutEventAt->timezone(config('app.timezone'))->format('d.m.Y H:i') }}
                            </span>
                            <span class="{{ $isPayoutStale ? 'text-rose-400' : 'text-zinc-500' }}">
                                ({{ $payoutEventAt->diffForHumans() }})
                            </span>
                            @if($i->payout_is_stale)
                                <span class="ml-1 inline-flex px-1.5 py-0.5 rounded text-[10px] font-medium bg-rose-950/80 text-rose-300 border border-rose-800/80">stale</span>
                            @endif
                        </span>
                    @else
                        <span class="text-zinc-500">—</span>
                    @endif
                </td>
                <td class="px-1 py-2 text-sm">
                    @if($i->last_payout_at)
                        @php
                            // Heartbeat ingest: при живом воркере POST payout приходит примерно раз в 30 с.
                            $ingestAt = $i->last_payout_at;
                            $ingestSilentMinutes = 2;
                            $isIngestSilent = $i->is_active
                                && $ingestAt->lt(now()->subMinutes($ingestSilentMinutes));
                        @endphp
                        <span title="Последний POST /api/ingest-payout: {{ $ingestAt->timezone(config('app.timezone'))->toIso8601String() }}">
                            <span class="{{ $isIngestSilent ? 'text-amber-300 font-medium' : 'text-zinc-300' }}">
                                {{ $ingestAt->timezone(config('app.timezone'))->format('d.m.Y H:i') }}
                            </span>
                            <span class="{{ $isIngestSilent ? 'text-amber-400/90' : 'text-zinc-500' }}">
                                ({{ $ingestAt->diffForHumans() }})
                            </span>
                            @if($isIngestSilent)
                                <span class="ml-1 inline-flex px-1.5 py-0.5 rounded text-[10px] font-medium bg-amber-950/80 text-amber-300 border border-amber-800/80">нет POST</span>
                            @endif
                        </span>
                    @else
                        <span class="text-zinc-500">—</span>
                    @endif
                </td>
                <td class="px-1 py-2 text-sm text-right">
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
            <tr><td colspan="12" class="px-1 py-6 text-center text-zinc-500">Нет инструментов. <a href="{{ route('instruments.create') }}" class="text-blue-400 hover:underline">Добавить</a></td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
