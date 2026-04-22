@extends('layouts.app')
@section('title', 'Лента сделок')
@section('content')
<div class="space-y-4">
    <div class="flex items-center justify-between gap-3 flex-wrap">
        <div class="flex items-center gap-3 flex-wrap">
            <h1 class="text-2xl font-semibold">Лента сделок (последние 2 часа)</h1>
            <a href="{{ route('bots.trades') }}" class="rounded bg-zinc-800 px-3 py-1 text-sm hover:bg-zinc-700 text-zinc-100">
                История сделок
            </a>
        </div>
        <div class="text-xs text-zinc-400" id="live-meta">Обновление: —</div>
    </div>

    <div class="overflow-x-auto rounded border border-zinc-800">
        <table class="min-w-full text-sm">
            <thead class="bg-zinc-900 text-zinc-400 uppercase text-xs">
                <tr>
                    <th class="px-4 py-3 text-left">ID</th>
                    <th class="px-4 py-3 text-left">Бот</th>
                    <th class="px-4 py-3 text-left">Инструмент</th>
                    <th class="px-4 py-3 text-left">Направление</th>
                    <th class="px-4 py-3 text-left">Сторона</th>
                    <th class="px-4 py-3 text-left">Цена входа</th>
                    <th class="px-4 py-3 text-left">Время входа</th>
                    <th class="px-4 py-3 text-left">Цена завершения</th>
                    <th class="px-4 py-3 text-left">Время окончания</th>
                    <th class="px-4 py-3 text-left">До конца</th>
                    <th class="px-4 py-3 text-left">Результат</th>
                    <th class="px-4 py-3 text-left">PnL</th>
                    <th class="px-4 py-3 text-left">Анализ</th>
                </tr>
            </thead>
            <tbody id="live-trades-body" class="divide-y divide-zinc-800 bg-zinc-950/40">
                <tr>
                    <td colspan="13" class="px-4 py-8 text-center text-zinc-500">Загрузка…</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<script>
(function() {
    const DATA_URL = '{{ route("bots.live-trades.data") }}';
    const ACTIVITY_ROUTE_TEMPLATE = '{{ route("bots.activity", ["bot" => "__BOT_ID__"]) }}';
    const body = document.getElementById('live-trades-body');
    const meta = document.getElementById('live-meta');
    // targetTradeId приходит из show-графика (?trade_id=123) для авто-подсветки и прокрутки строки.
    const targetTradeId = Number(new URLSearchParams(window.location.search).get('trade_id') || 0) || null;
    let targetTradeScrolled = false;
    let lastIds = new Set();
    let timer = null;

    // Нормализуем сторону сделки для интерфейса:
    // PUT = сделка на понижение ("ВНИЗ"), CALL = сделка на повышение ("ВВЕРХ").
    // Если придет иное значение, показываем исходный код, чтобы не потерять диагностическую информацию.
    function sideLabel(side) {
        const raw = String(side ?? '').toUpperCase();
        if (raw === 'PUT') return 'ВНИЗ';
        if (raw === 'CALL') return 'ВВЕРХ';
        return side ?? '—';
    }

    function resultCell(result) {
        if (result === 'win') return '<span class="text-emerald-400">win</span>';
        if (result === 'loss') return '<span class="text-rose-400">loss</span>';
        if (result === 'draw') return '<span class="text-amber-400">draw</span>';
        return '<span class="text-zinc-400">pending</span>';
    }

    function secondsToClock(total) {
        const s = Math.max(0, Math.floor(total));
        const m = Math.floor(s / 60);
        const r = s % 60;
        return String(m).padStart(2, '0') + ':' + String(r).padStart(2, '0');
    }

    function timeLeftCell(t) {
        if (t.status !== 'open') {
            return '<span class="text-zinc-500">завершена</span>';
        }
        if (!t.expiry_ts) {
            return '<span class="text-zinc-500">—</span>';
        }
        const left = t.expiry_ts - Math.floor(Date.now() / 1000);
        if (left <= 0) {
            return '<span class="text-amber-400">00:00</span>';
        }
        return '<span class="text-cyan-300 font-mono">' + secondsToClock(left) + '</span>';
    }

    function renderRows(trades) {
        if (!trades.length) {
            body.innerHTML = '<tr><td colspan="13" class="px-4 py-8 text-center text-zinc-500">За последние 2 часа сделок нет.</td></tr>';
            return;
        }

        const newIds = new Set(trades.map(t => t.id));
        body.innerHTML = trades.map(t => {
            const isNew = !lastIds.has(t.id);
            const isTarget = targetTradeId !== null && Number(t.id) === targetTradeId;
            // Ссылка на "Сигналы и сделки" конкретного бота + trade_id.
            // Так пользователь мгновенно открывает именно нужный анализ сделки.
            const hasBot = Number.isFinite(Number(t.bot_id)) && Number(t.bot_id) > 0;
            const activityUrl = hasBot
                ? ACTIVITY_ROUTE_TEMPLATE.replace('__BOT_ID__', encodeURIComponent(String(t.bot_id))) + `?trade_id=${encodeURIComponent(String(t.id))}`
                : null;
            return `
                <tr data-trade-id="${t.id}" class="hover:bg-zinc-900/50 ${isTarget ? 'bg-amber-900/40 ring-1 ring-amber-500/50' : (isNew ? 'bg-sky-950/30' : '')}">
                    <td class="px-4 py-3 text-zinc-300 font-mono">${t.id ?? '—'}</td>
                    <td class="px-4 py-3 text-zinc-100">${t.bot_name ?? '—'}</td>
                    <td class="px-4 py-3 text-zinc-300">${t.instrument ?? '—'}</td>
                    <td class="px-4 py-3 text-zinc-400 font-mono">${sideLabel(t.side)}</td>
                    <td class="px-4 py-3 text-zinc-300">${sideLabel(t.side)}</td>
                    <td class="px-4 py-3 text-zinc-300 font-mono">${t.entry_price ?? '—'}</td>
                    <td class="px-4 py-3 text-zinc-300">${t.entry_time ?? '—'}</td>
                    <td class="px-4 py-3 text-zinc-300 font-mono">${t.expiry_price ?? '—'}</td>
                    <td class="px-4 py-3 text-zinc-300">${t.expiry_time ?? '—'}</td>
                    <td class="px-4 py-3">${timeLeftCell(t)}</td>
                    <td class="px-4 py-3">${resultCell(t.result)}</td>
                    <td class="px-4 py-3 text-zinc-300">${t.pnl ?? '—'}</td>
                    <td class="px-4 py-3">
                        ${activityUrl
                            ? `<a href="${activityUrl}" class="inline-flex items-center rounded border border-zinc-700 bg-zinc-800 px-2 py-1 text-xs text-zinc-200 hover:bg-zinc-700">Открыть</a>`
                            : '<span class="text-zinc-500 text-xs">—</span>'}
                    </td>
                </tr>
            `;
        }).join('');
        lastIds = newIds;

        if (targetTradeId !== null && !targetTradeScrolled) {
            const row = body.querySelector(`tr[data-trade-id="${targetTradeId}"]`);
            if (row) {
                row.scrollIntoView({ behavior: 'smooth', block: 'center' });
                targetTradeScrolled = true;
            }
        }
    }

    async function refreshOnce() {
        try {
            const res = await fetch(DATA_URL, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                cache: 'no-store',
            });
            const data = await res.json();
            renderRows(data.trades || []);
            const now = new Date();
            const targetHint = (targetTradeId !== null && !targetTradeScrolled)
                ? ` • ищем сделку #${targetTradeId}`
                : '';
            meta.textContent = `Обновление: ${now.toLocaleTimeString()} • записей: ${data.count ?? 0}${targetHint}`;
        } catch (_) {
            meta.textContent = 'Ошибка обновления. Повторяем…';
        }
    }

    refreshOnce();
    timer = setInterval(refreshOnce, 1000);
    window.addEventListener('beforeunload', () => {
        if (timer) clearInterval(timer);
    });
})();
</script>
@endsection
