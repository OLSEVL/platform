@extends('layouts.app')
@section('title', 'Боты')
@section('content')
<div class="space-y-5">
    <div class="flex items-center justify-between gap-3">
        <h1 class="text-2xl font-semibold">Обзор ботов</h1>
        <a href="{{ route('bots.analytics') }}" class="text-sm text-blue-400 hover:text-blue-300">Открыть аналитику</a>
    </div>

    <!-- <div class="rounded border border-amber-900/60 bg-amber-950/30 p-4 text-sm text-amber-100/90">
        <p class="font-medium text-amber-200">Как боты запускаются</p>
        <p class="mt-1 text-amber-100/80">
            Скан и закрытие сделок идут по расписанию Laravel (раз в минуту). В продакшене нужен cron
            <code class="rounded bg-zinc-900 px-1 py-0.5 text-xs">* * * * * cd /path && php artisan schedule:run</code>
            или процесс <code class="rounded bg-zinc-900 px-1 py-0.5 text-xs">php artisan schedule:work</code>.
            Без этого сигналы не появятся сами — можно вручную:
            <code class="rounded bg-zinc-900 px-1 py-0.5 text-xs">php artisan bots:scan</code>
            и
            <code class="rounded bg-zinc-900 px-1 py-0.5 text-xs">php artisan bots:settle</code>.
            Диагностика: <code class="rounded bg-zinc-900 px-1 py-0.5 text-xs">php artisan bots:status</code>.
        </p>
        <p class="mt-2 text-amber-100/70">
            По каждому инструменту одновременно только одна открытая paper-сделка; пока она не закрыта по времени и не отработает settle, новый сигнал по той же паре не создаётся.
        </p>
    </div> -->

    @if($bots->isEmpty())
        <div class="rounded border border-zinc-800 bg-zinc-900/60 p-4 text-zinc-400">
            Боты пока не созданы. Используйте команду <code>php artisan bots:bootstrap</code> для быстрого старта.
        </div>
    @else
        <div class="overflow-x-auto rounded border border-zinc-800">
            <table class="min-w-full text-sm">
                <thead class="bg-zinc-900 text-zinc-400 uppercase text-xs">
                    <tr>
                        <th class="px-4 py-3 text-left">Бот</th>
                        <th class="px-4 py-3 text-left">Тип</th>
                        <th class="px-4 py-3 text-left">Статус</th>
                        {{-- Per-bot flag config.mirror_trades_enabled (default off if missing). --}}
                        <th class="px-4 py-3 text-left">Зеркало PO</th>
                        <th class="px-4 py-3 text-left">Сделок</th>
                        <th class="px-4 py-3 text-left">Win / Loss</th>
                        <th class="px-4 py-3 text-left">Открыто</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-800 bg-zinc-950/40">
                    @foreach($bots as $bot)
                        @php
                            $wlTotal = (int) ($bot->wins_count + $bot->losses_count);
                            $winrate = $wlTotal > 0 ? round(($bot->wins_count * 100) / $wlTotal, 1) : null;
                        @endphp
                        <tr class="hover:bg-zinc-900/50">
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                    <a href="{{ route('bots.show', $bot) }}" class="text-zinc-100 hover:text-blue-300">{{ $bot->name }}</a>
                                    <div class="flex flex-wrap items-center gap-1">
                                        @if($bot->type === 'strong_level')
                                        <a href="{{ route('bots.cards', $bot) }}"
                                            class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800/80 px-2 py-0.5 text-[11px] font-medium text-zinc-300 transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/40">Карточки</a>
                                        @endif
                                        @if($bot->type !== 'openrouter_llm_analyst')
                                        <a href="{{ route('bots.activity', $bot) }}"
                                            class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800/80 px-2 py-0.5 text-[11px] font-medium text-zinc-300 transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/40">Сигналы и сделки</a>
                                        @endif
                                        <a href="{{ route('bots.analytics.pairs', ['bot_id' => $bot->id]) }}"
                                            class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800/80 px-2 py-0.5 text-[11px] font-medium text-zinc-300 transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/40">Аналитика пар</a>
                                        <a href="{{ route('bots.settings', $bot) }}"
                                            class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800/80 px-2 py-0.5 text-[11px] font-medium text-zinc-300 transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/40">Настройки</a>
                                        <a href="{{ route('bots.logs', $bot) }}"
                                            class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800/80 px-2 py-0.5 text-[11px] font-medium text-zinc-300 transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/40">Логи и метрики</a>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-zinc-300">{{ $bot->type }}</td>
                            <td class="px-4 py-3">
                                @if($bot->is_active)
                                    <span class="text-emerald-400">active</span>
                                @else
                                    <span class="text-zinc-500">inactive</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                @if($bot->isMirrorTradesEnabled())
                                    <span class="text-emerald-400">вкл</span>
                                @else
                                    <span class="text-zinc-500">выкл</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-zinc-300">{{ $bot->trades_total }}</td>
                            <td class="px-4 py-3 text-zinc-300">
                                {{ $bot->wins_count }} / {{ $bot->losses_count }}
                                @if($winrate !== null)
                                    <span class="text-zinc-500">({{ $winrate }}%)</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-zinc-300">{{ $bot->open_count }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection

