@extends('layouts.app')

@section('title', 'Настройки')

@section('content')
<div class="w-full max-w-7xl mx-auto">
    <h1 class="text-2xl font-bold text-zinc-100 mb-6">Настройки</h1>

    @if(session('success'))
        <p class="mb-4 p-3 bg-emerald-950/60 border border-emerald-800 text-emerald-200 rounded">{{ session('success') }}</p>
    @endif

    @if(session('error'))
        <p class="mb-4 p-3 bg-red-950/60 border border-red-800 text-red-200 rounded">{{ session('error') }}</p>
    @endif

    <div class="bg-zinc-900 border border-zinc-800 rounded-lg shadow-lg shadow-black/20 p-5">
        <h2 class="text-lg font-semibold text-zinc-100 mb-2">Прокси Webshare</h2>
        <p class="text-sm text-zinc-400 mb-4">
            Кнопка загружает прокси из Webshare API и назначает их всем активным инструментам.
            Если прокси меньше, чем активных инструментов, назначение идёт по кругу.
        </p>
        <div class="mb-4 text-sm text-zinc-300">
            Всего в пуле: <span class="text-zinc-100 font-medium">{{ $proxyStats['total'] ?? 0 }}</span>,
            active: <span class="text-emerald-400">{{ $proxyStats['active'] ?? 0 }}</span>,
            cooldown: <span class="text-amber-400">{{ $proxyStats['cooldown'] ?? 0 }}</span>,
            dead: <span class="text-rose-400">{{ $proxyStats['dead'] ?? 0 }}</span>.
        </div>
        <form action="{{ route('settings.proxies.refresh') }}" method="POST">
            @csrf
            <button type="submit" class="inline-flex items-center px-4 py-2 rounded bg-blue-600 hover:bg-blue-500 text-white font-medium">
                Обновить прокси
            </button>
        </form>
    </div>

    <div class="bg-zinc-900 border border-zinc-800 rounded-lg shadow-lg shadow-black/20 p-5 mt-6">
        <h2 class="text-lg font-semibold text-zinc-100 mb-2">Ротация прокси</h2>
        <p class="text-sm text-zinc-400 mb-4">
            Когда опция включена, API при выдаче инструментов автоматически переназначает прокси для инструментов
            без рабочего endpoint (например, dead/cooldown/не назначен).
        </p>
        <form action="{{ route('settings.proxies.auto-rotation') }}" method="POST" class="flex flex-col items-start gap-3">
            @csrf
            <label class="inline-flex items-center gap-2 text-zinc-200 min-h-[40px]">
                <input type="checkbox" name="proxy_auto_rotation_enabled" value="1" {{ !empty($proxyAutoRotationEnabled) ? 'checked' : '' }} class="rounded border-zinc-600 bg-zinc-800 text-blue-500">
                Автоматическая ротация прокси
            </label>
            <label class="flex flex-col gap-1 text-zinc-200 text-sm">
                <span>Порог перевода в cooldown (streak >=)</span>
                <input
                    type="number"
                    name="proxy_cooldown_streak_threshold"
                    min="1"
                    max="1000"
                    value="{{ (int) ($proxyCooldownStreakThreshold ?? 10) }}"
                    class="w-28 px-2 py-1 rounded border border-zinc-600 bg-zinc-950 text-zinc-100 focus:ring-2 focus:ring-indigo-600 focus:border-transparent"
                >
            </label>
            <label class="flex flex-col gap-1 text-zinc-200 text-sm">
                <span>Лимит нагрузки на 1 прокси (активных инструментов)</span>
                <input
                    type="number"
                    name="proxy_max_instruments_per_endpoint"
                    min="1"
                    max="100"
                    value="{{ (int) ($proxyMaxInstrumentsPerEndpoint ?? 1) }}"
                    class="w-28 px-2 py-1 rounded border border-zinc-600 bg-zinc-950 text-zinc-100 focus:ring-2 focus:ring-indigo-600 focus:border-transparent"
                >
                <span class="text-xs text-zinc-500">
                    Ограничивает концентрацию: авто-ротация не назначит новый инструмент на proxy endpoint,
                    если на нём уже достигнут этот лимит.
                </span>
            </label>
            <button type="submit" class="inline-flex items-center justify-center px-4 py-2 rounded bg-indigo-600 hover:bg-indigo-500 text-white font-medium">
                Сохранить
            </button>
        </form>
    </div>

    <div class="bg-zinc-900 border border-zinc-800 rounded-lg shadow-lg shadow-black/20 p-5 mt-6">
        <h2 class="text-lg font-semibold text-zinc-100 mb-2">Диагностика проблемных прокси</h2>
        <p class="text-sm text-zinc-400 mb-4">
            Показывает последние прокси с ошибками подключения. Используй этот список, чтобы быстро понять,
            какие endpoint чаще падают и какие в cooldown/dead.
        </p>
        <form action="{{ route('settings.proxies.reactivate-all') }}" method="POST" class="mb-4" onsubmit="return confirm('Реанимировать все прокси в статусах dead/cooldown?');">
            @csrf
            <button type="submit" class="inline-flex items-center px-3 py-2 rounded bg-emerald-700 hover:bg-emerald-600 text-white text-sm font-medium">
                Реанимировать все dead/cooldown
            </button>
        </form>

        @if(($recentProblemProxies ?? collect())->isEmpty())
            <p class="text-sm text-zinc-500">Пока нет зафиксированных ошибок по прокси.</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm divide-y divide-zinc-800">
                    <thead class="bg-zinc-800/80 text-zinc-400 uppercase text-xs">
                        <tr>
                            <th class="px-3 py-2 text-left">Прокси</th>
                            <th class="px-3 py-2 text-left">Статус</th>
                            <th class="px-3 py-2 text-left">Ошибки</th>
                            <th class="px-3 py-2 text-left">Последняя ошибка</th>
                            <th class="px-3 py-2 text-left">Cooldown до</th>
                            <th class="px-3 py-2 text-right">Действие</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-800 text-zinc-200">
                        @foreach($recentProblemProxies as $proxy)
                            @php
                                $statusClass = match($proxy->status) {
                                    'active' => 'text-emerald-400',
                                    'cooldown' => 'text-amber-400',
                                    'dead' => 'text-rose-400',
                                    default => 'text-zinc-400',
                                };
                            @endphp
                            <tr class="hover:bg-zinc-800/40">
                                <td class="px-3 py-2 font-mono text-xs max-w-[360px] truncate" title="{{ $proxy->url }}">{{ $proxy->url }}</td>
                                <td class="px-3 py-2 {{ $statusClass }}">{{ $proxy->status }}</td>
                                <td class="px-3 py-2">{{ $proxy->failure_count }}</td>
                                <td class="px-3 py-2">
                                    <div class="text-zinc-300 text-xs">{{ $proxy->last_error ?? '—' }}</div>
                                    <div class="text-zinc-500 text-xs">
                                        {{ $proxy->last_failed_at ? $proxy->last_failed_at->timezone(config('app.timezone'))->format('d.m.Y H:i:s') : '—' }}
                                    </div>
                                </td>
                                <td class="px-3 py-2 text-zinc-400 text-xs">
                                    {{ $proxy->cooldown_until ? $proxy->cooldown_until->timezone(config('app.timezone'))->format('d.m.Y H:i:s') : '—' }}
                                </td>
                                <td class="px-3 py-2 text-right">
                                    {{-- Кнопка доступна для каждого proxy в списке: операция идемпотентна даже для active. --}}
                                    <form action="{{ route('settings.proxies.reactivate', $proxy) }}" method="POST" class="inline">
                                        @csrf
                                        <button type="submit" class="text-emerald-400 hover:text-emerald-300 hover:underline">
                                            Реанимировать
                                        </button>
                                    </form>
                                    <button
                                        type="button"
                                        class="ml-2 inline-flex items-center px-2.5 py-1 rounded bg-emerald-700 hover:bg-emerald-600 text-white text-xs font-medium js-proxy-test-btn"
                                        data-url="{{ route('settings.proxies.test-quick-ajax', $proxy) }}"
                                        data-proxy-id="{{ $proxy->id }}"
                                        data-mode="quick"
                                    >
                                        Быстрый тест
                                    </button>
                                    <button
                                        type="button"
                                        class="ml-2 inline-flex items-center px-2.5 py-1 rounded bg-indigo-700 hover:bg-indigo-600 text-white text-xs font-medium js-proxy-test-btn"
                                        data-url="{{ route('settings.proxies.test-deep-ajax', $proxy) }}"
                                        data-proxy-id="{{ $proxy->id }}"
                                        data-mode="deep"
                                    >
                                        Глубокий тест (45с)
                                    </button>
                                    <div id="proxy-test-result-{{ $proxy->id }}" class="mt-1 text-xs text-zinc-500"></div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="bg-zinc-900 border border-zinc-800 rounded-lg shadow-lg shadow-black/20 p-5 mt-6">
        <h2 class="text-lg font-semibold text-zinc-100 mb-2">Мониторинг Payout</h2>
        <p class="text-sm text-zinc-400 mb-4">
            Настройка влияет на колонку «Обновлён Payout» в разделе «Инструменты».
            Если payout не обновлялся дольше заданного интервала, значение подсвечивается красным.
        </p>
        <form action="{{ route('settings.payout.monitoring') }}" method="POST" class="flex flex-col items-start gap-3">
            @csrf
            <label class="flex flex-col gap-1 text-zinc-200 text-sm">
                <span>Порог stale для payout (минут)</span>
                <input
                    type="number"
                    name="payout_stale_minutes_threshold"
                    min="1"
                    max="1440"
                    value="{{ (int) ($payoutStaleMinutesThreshold ?? 5) }}"
                    class="w-28 px-2 py-1 rounded border border-zinc-600 bg-zinc-950 text-zinc-100 focus:ring-2 focus:ring-indigo-600 focus:border-transparent"
                >
            </label>
            <button type="submit" class="inline-flex items-center justify-center px-4 py-2 rounded bg-indigo-600 hover:bg-indigo-500 text-white font-medium">
                Сохранить порог
            </button>
        </form>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    const buttons = document.querySelectorAll('.js-proxy-test-btn');

    const setResult = (proxyId, text, status) => {
        const resultNode = document.getElementById(`proxy-test-result-${proxyId}`);
        if (!resultNode) return;

        const colorClass = status === 'ok'
            ? 'text-emerald-400'
            : (status === 'warn' ? 'text-amber-400' : 'text-rose-400');
        resultNode.className = `mt-1 text-xs ${colorClass}`;
        resultNode.textContent = text;
    };

    buttons.forEach((button) => {
        button.addEventListener('click', async () => {
            const proxyId = button.dataset.proxyId;
            const mode = button.dataset.mode || 'quick';
            const url = button.dataset.url;
            const timeoutMs = mode === 'deep' ? 45000 : 12000;
            const initialText = button.textContent;

            if (!url || !proxyId || !csrfToken) {
                return;
            }

            button.disabled = true;
            button.textContent = mode === 'deep' ? 'Тестирование...' : 'Проверка...';
            setResult(proxyId, mode === 'deep' ? 'Выполняется deep-тест (до 45с)...' : 'Выполняется быстрый тест...', 'warn');

            const controller = new AbortController();
            const abortTimer = setTimeout(() => controller.abort(), timeoutMs);

            try {
                const response = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json',
                    },
                    signal: controller.signal,
                });

                const payload = await response.json();
                const status = payload?.status || 'fail';
                const details = payload?.details || 'fail - timeout/conn error/auth error.';
                const latencyPart = payload?.latency_ms != null ? ` latency=${payload.latency_ms}ms;` : '';
                const countPart = payload?.events_count != null ? ` успешных ответов=${payload.events_count};` : '';
                const durationPart = payload?.duration_sec != null ? ` длительность=${payload.duration_sec}s;` : '';

                setResult(proxyId, `${status} - ${details};${latencyPart}${countPart}${durationPart}`.replace(/;;+/g, ';'), status);
            } catch (error) {
                setResult(proxyId, 'fail - timeout/conn error/auth error.', 'fail');
            } finally {
                clearTimeout(abortTimer);
                button.disabled = false;
                button.textContent = initialText;
            }
        });
    });
});
</script>
@endsection
