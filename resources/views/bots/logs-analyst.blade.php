@extends('layouts.app')
@section('title', $bot->name.' — логи LLM')
@section('content')
<div class="space-y-4">
    <div class="flex flex-wrap items-baseline justify-between gap-3">
        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
            <h1 class="text-2xl font-semibold">{{ $bot->name }}</h1>
            <span class="text-sm text-zinc-500">История запросов к OpenRouter</span>
        </div>
        <div class="flex flex-wrap items-center gap-2 text-sm">
            <a href="{{ route('bots.show', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">График</a>
            <a href="{{ route('bots.settings', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">Настройки</a>
        </div>
    </div>

    <div class="rounded border border-zinc-800 bg-zinc-950/40 p-4">
        <div class="text-sm text-zinc-300 mb-3">Сводка</div>
        <div class="grid gap-3 sm:grid-cols-3 text-sm">
            <div class="rounded border border-zinc-800 bg-zinc-900/50 p-3">
                <div class="text-zinc-500 text-xs uppercase">Успешных</div>
                <div class="text-zinc-100 text-lg font-semibold">{{ (int) $totalOk }}</div>
            </div>
            <div class="rounded border border-zinc-800 bg-zinc-900/50 p-3">
                <div class="text-zinc-500 text-xs uppercase">Ошибок</div>
                <div class="text-zinc-100 text-lg font-semibold">{{ (int) $totalError }}</div>
            </div>
            <div class="rounded border border-zinc-800 bg-zinc-900/50 p-3">
                <div class="text-zinc-500 text-xs uppercase">Avg latency</div>
                <div class="text-zinc-100 text-lg font-semibold">
                    {{ $avgLatencyMs !== null ? number_format($avgLatencyMs, 1).' ms' : '—' }}
                </div>
            </div>
        </div>
    </div>

    <form method="GET" class="flex flex-wrap items-center gap-2">
        <select name="status" class="rounded border border-zinc-700 bg-zinc-900 px-2 py-1 text-sm">
            <option value="">Все статусы</option>
            <option value="ok" @selected($selectedStatus === 'ok')>ok</option>
            <option value="error" @selected($selectedStatus === 'error')>error</option>
        </select>
        <select name="instrument" class="rounded border border-zinc-700 bg-zinc-900 px-2 py-1 text-sm">
            <option value="">Все инструменты</option>
            @foreach($instrumentOptions as $inst)
                <option value="{{ $inst }}" @selected($selectedInstrument === $inst)>{{ $inst }}</option>
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
                    <th class="px-4 py-3 text-left">Модель</th>
                    <th class="px-4 py-3 text-left">Latency</th>
                    <th class="px-4 py-3 text-left">Ответ</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-800 bg-zinc-950/40">
                @forelse($llmLogs as $log)
                    <tr class="align-top hover:bg-zinc-900/50">
                        <td class="px-4 py-3 text-zinc-300 whitespace-nowrap">{{ optional($log->created_at)->format('Y-m-d H:i:s') }}</td>
                        <td class="px-4 py-3 text-zinc-100">{{ $log->instrument }}</td>
                        <td class="px-4 py-3 text-zinc-300">{{ $log->status }}</td>
                        <td class="px-4 py-3 text-zinc-400 text-xs">{{ $log->model ?? '—' }}</td>
                        <td class="px-4 py-3 text-zinc-300">{{ $log->latency_ms !== null ? (int) $log->latency_ms.' ms' : '—' }}</td>
                        <td class="px-4 py-3 text-xs text-zinc-300 max-w-[40rem]">
                            @if($log->error_message)
                                <span class="text-rose-400">{{ $log->error_message }}</span>
                            @endif
                            @if($log->response_text)
                                <details class="mt-1">
                                    <summary class="cursor-pointer text-zinc-400">Текст ответа</summary>
                                    <pre class="mt-2 whitespace-pre-wrap break-words text-[11px] leading-relaxed">{{ $log->response_text }}</pre>
                                </details>
                            @endif
                            @if($log->request_text)
                                <details class="mt-1">
                                    <summary class="cursor-pointer text-zinc-500">Запрос</summary>
                                    <pre class="mt-2 whitespace-pre-wrap break-words text-[11px] leading-relaxed text-zinc-500">{{ \App\Support\OpenRouterLlmLogFormatter::requestTextForDisplay(is_array($log->request_json) ? $log->request_json : null, $log->request_text) }}</pre>
                                </details>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-zinc-500">Запросов пока нет. Запустите анализ на графике.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if(method_exists($llmLogs, 'links'))
        <div>{{ $llmLogs->links() }}</div>
    @endif
</div>
@endsection
