@extends('layouts.app')
@section('title', $bot->name.' — логи и метрики')
@section('content')
<div class="space-y-4">
    <div class="flex flex-wrap items-baseline justify-between gap-3">
        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
            <h1 class="text-2xl font-semibold">{{ $bot->name }}</h1>
            <span class="text-sm text-zinc-500">Логи и метрики</span>
        </div>
        <div class="flex flex-wrap items-center gap-2 text-sm">
            <a href="{{ route('bots.show', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">График</a>
            <a href="{{ route('bots.cards', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">Карточки</a>
            <a href="{{ route('bots.activity', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">Сигналы и сделки</a>
            <a href="{{ route('bots.settings', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">Настройки</a>
        </div>
    </div>

    <div class="rounded border border-zinc-800 bg-zinc-950/40 p-4">
        <div class="text-sm text-zinc-300 mb-3">Сводка воронки анализа</div>
        @if(($analysisMetrics['enabled'] ?? false) === false)
            <div class="text-sm text-zinc-500">Метрики анализа пока недоступны (не применены миграции).</div>
        @else
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 text-sm">
                <div class="rounded border border-zinc-800 bg-zinc-900/50 p-3">
                    <div class="text-zinc-500 text-xs uppercase">Анализов</div>
                    <div class="text-zinc-100 text-lg font-semibold">{{ (int) ($analysisMetrics['analysis_count'] ?? 0) }}</div>
                </div>
                <div class="rounded border border-zinc-800 bg-zinc-900/50 p-3">
                    <div class="text-zinc-500 text-xs uppercase">Сигналов</div>
                    <div class="text-zinc-100 text-lg font-semibold">{{ (int) ($analysisMetrics['signal_count'] ?? 0) }}</div>
                </div>
                <div class="rounded border border-zinc-800 bg-zinc-900/50 p-3">
                    <div class="text-zinc-500 text-xs uppercase">Сделок</div>
                    <div class="text-zinc-100 text-lg font-semibold">{{ (int) ($analysisMetrics['trade_count'] ?? 0) }}</div>
                </div>
                <div class="rounded border border-zinc-800 bg-zinc-900/50 p-3">
                    <div class="text-zinc-500 text-xs uppercase">Avg latency</div>
                    <div class="text-zinc-100 text-lg font-semibold">
                        {{ $analysisMetrics['avg_latency_ms'] !== null ? number_format((float) $analysisMetrics['avg_latency_ms'], 1).' ms' : '—' }}
                    </div>
                </div>
            </div>
            <div class="mt-3 text-xs text-zinc-500">
                Conversion: analysis->signal {{ $analysisMetrics['conversion_analysis_to_signal'] ?? '—' }},
                signal->trade {{ $analysisMetrics['conversion_signal_to_trade'] ?? '—' }},
                analysis->trade {{ $analysisMetrics['conversion_analysis_to_trade'] ?? '—' }}
            </div>
            @if(!empty($analysisMetrics['skip_breakdown']) && is_array($analysisMetrics['skip_breakdown']))
                <div class="mt-2 text-xs text-zinc-400">
                    Skip breakdown:
                    @foreach($analysisMetrics['skip_breakdown'] as $reason => $count)
                        <span class="inline-block rounded bg-zinc-800 px-2 py-0.5 mr-1 mb-1">{{ $reason }}: {{ (int) $count }}</span>
                    @endforeach
                </div>
            @endif
        @endif
    </div>

    <form method="GET" class="flex flex-wrap items-center gap-2">
        <select name="status" class="rounded border border-zinc-700 bg-zinc-900 px-2 py-1 text-sm">
            <option value="">Все статусы</option>
            @foreach($statusOptions as $status)
                <option value="{{ $status }}" @selected($selectedStatus === $status)>{{ $status }}</option>
            @endforeach
        </select>
        <select name="instrument" class="rounded border border-zinc-700 bg-zinc-900 px-2 py-1 text-sm">
            <option value="">Все инструменты</option>
            @foreach($instrumentOptions as $instrument)
                <option value="{{ $instrument }}" @selected($selectedInstrument === $instrument)>{{ $instrument }}</option>
            @endforeach
        </select>
        <select name="skip_reason" class="rounded border border-zinc-700 bg-zinc-900 px-2 py-1 text-sm">
            <option value="">Все skip_reason</option>
            @foreach($skipReasonOptions as $skipReason)
                <option value="{{ $skipReason }}" @selected($selectedSkipReason === $skipReason)>{{ $skipReason }}</option>
            @endforeach
        </select>
        <button type="submit" class="rounded bg-zinc-800 px-3 py-1 text-sm hover:bg-zinc-700">Фильтр</button>
    </form>

    <div class="overflow-x-auto rounded border border-zinc-800">
        <table class="min-w-full text-sm">
            <thead class="bg-zinc-900 text-zinc-400 uppercase text-xs">
                <tr>
                    <th class="px-4 py-3 text-left">Время</th>
                    <th class="px-4 py-3 text-left">Инструмент</th>
                    <th class="px-4 py-3 text-left">Статус</th>
                    <th class="px-4 py-3 text-left">Причина</th>
                    <th class="px-4 py-3 text-left">Latency</th>
                    <th class="px-4 py-3 text-left">Decision</th>
                    <th class="px-4 py-3 text-left">LLM</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-800 bg-zinc-950/40">
                @forelse($runs as $run)
                    @php
                        $logs = $llmLogsByRun[$run->id] ?? collect();
                        $latestLog = $logs instanceof \Illuminate\Support\Collection ? $logs->first() : (is_array($logs) ? ($logs[0] ?? null) : null);
                    @endphp
                    <tr class="align-top hover:bg-zinc-900/50">
                        <td class="px-4 py-3 text-zinc-300">{{ optional($run->started_at)->format('Y-m-d H:i:s') }}</td>
                        <td class="px-4 py-3 text-zinc-100">{{ $run->instrument }}</td>
                        <td class="px-4 py-3 text-zinc-300">{{ $run->status }}</td>
                        <td class="px-4 py-3 text-zinc-400">{{ $run->skip_reason ?? '—' }}</td>
                        <td class="px-4 py-3 text-zinc-300">{{ $run->latency_ms !== null ? (int) $run->latency_ms.' ms' : '—' }}</td>
                        <td class="px-4 py-3 text-xs text-zinc-400 max-w-[28rem]">
                            @if(is_array($run->decision_json))
                                <details>
                                    <summary class="cursor-pointer text-zinc-300">Показать JSON</summary>
                                    <pre class="mt-2 whitespace-pre-wrap break-words text-[11px] leading-relaxed">{{ json_encode($run->decision_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                                </details>
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-4 py-3 text-xs text-zinc-300 max-w-[34rem]">
                            @if($latestLog)
                                <div class="mb-1">#{{ $latestLog->id }} · {{ $latestLog->status }} · {{ $latestLog->model ?? '—' }}</div>
                                <details>
                                    <summary class="cursor-pointer text-zinc-400">Request / Response</summary>
                                    <div class="mt-2 space-y-2">
                                        <div>
                                            <div class="text-zinc-500 mb-1">Request</div>
                                            <pre class="whitespace-pre-wrap break-words text-[11px] leading-relaxed">{{ \App\Support\OpenRouterLlmLogFormatter::requestTextForDisplay(is_array($latestLog->request_json) ? $latestLog->request_json : null, $latestLog->request_text) ?: '—' }}</pre>
                                        </div>
                                        <div>
                                            <div class="text-zinc-500 mb-1">Response</div>
                                            <pre class="whitespace-pre-wrap break-words text-[11px] leading-relaxed">{{ $latestLog->response_text ?? ($latestLog->error_message ?? '—') }}</pre>
                                        </div>
                                    </div>
                                </details>
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-8 text-center text-zinc-500">Логи анализа пока отсутствуют.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $runs->links() }}
</div>
@endsection
