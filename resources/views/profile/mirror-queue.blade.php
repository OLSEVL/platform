@extends('profile._layout')
@section('title', 'Профиль — Очередь PO')

@section('profile_content')
<div class="space-y-5" id="mirror-queue-app">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="text-lg font-medium text-slate-100">Очередь зеркалирования Pocket Option</h2>
            <p class="mt-1 text-sm text-zinc-400">Обновление каждые 2 с. Зеркало по умолчанию уходит на PO <strong class="text-zinc-300">сразу</strong> после paper-сделки (режим sync).</p>
        </div>
        <div class="text-xs text-zinc-500" id="mq-meta">—</div>
    </div>

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" id="mq-summary-cards">
        <div class="rounded border border-zinc-700 bg-zinc-950/50 px-4 py-3">
            <p class="text-xs text-zinc-500">В очереди Laravel</p>
            <p class="text-2xl font-semibold text-zinc-100" id="mq-jobs-pending">—</p>
        </div>
        <div class="rounded border border-zinc-700 bg-zinc-950/50 px-4 py-3">
            <p class="text-xs text-zinc-500">Упавшие jobs</p>
            <p class="text-2xl font-semibold text-zinc-100" id="mq-jobs-failed">—</p>
        </div>
        <div class="rounded border border-zinc-700 bg-zinc-950/50 px-4 py-3">
            <p class="text-xs text-zinc-500">Зеркало / счёт</p>
            <p class="text-sm font-medium text-zinc-100" id="mq-mirror-mode">—</p>
        </div>
        <div class="rounded border border-zinc-700 bg-zinc-950/50 px-4 py-3">
            <p class="text-xs text-zinc-500">Прокси PO</p>
            <p class="text-sm font-medium" id="mq-proxy">—</p>
        </div>
    </div>

    <div class="rounded border border-zinc-700 bg-zinc-950/30 px-4 py-3">
        <h3 class="text-sm font-medium text-zinc-300 mb-2">Задачи в очереди (MirrorBotTradeJob)</h3>
        <div class="overflow-x-auto">
            <table class="min-w-full text-xs">
                <thead class="text-zinc-500 uppercase">
                    <tr>
                        <th class="text-left py-2 pr-4">Job</th>
                        <th class="text-left py-2 pr-4">Сделка</th>
                        <th class="text-left py-2 pr-4">Состояние</th>
                        <th class="text-left py-2 pr-4">Попытки</th>
                        <th class="text-left py-2 pr-4">Поставлена</th>
                        <th class="text-left py-2 pr-4">Начата</th>
                        <th class="text-left py-2">Выполнена</th>
                    </tr>
                </thead>
                <tbody id="mq-jobs-body" class="text-zinc-300 divide-y divide-zinc-800/80">
                    <tr><td colspan="7" class="py-4 text-zinc-500">Загрузка…</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="rounded border border-zinc-800">
        <table class="w-full table-fixed text-xs">
            <colgroup>
                <col class="w-[3%]">
                <col class="w-[7%]">
                <col class="w-[6%]">
                <col class="w-[5%]">
                <col class="w-[7%]">
                <col class="w-[9%]">
                <col class="w-[9%]">
                <col class="w-[5%]">
                <col class="w-[5%]">
                <col class="w-[7%]">
                <col class="w-[7%]">
                <col class="w-[7%]">
                <col class="w-[7%]">
                <col class="w-[7%]">
                <col class="w-[11%]">
            </colgroup>
            <thead class="bg-zinc-900 text-zinc-400 uppercase text-[10px] leading-tight">
                <tr>
                    <th class="px-1.5 py-2 text-left">ID</th>
                    <th class="px-1.5 py-2 text-left">Бот</th>
                    <th class="px-1.5 py-2 text-left">Пара</th>
                    <th class="px-1.5 py-2 text-left">Стор.</th>
                    <th class="px-1.5 py-2 text-left">Очередь</th>
                    <th class="px-1.5 py-2 text-left">Поставлена</th>
                    <th class="px-1.5 py-2 text-left">Выполнена</th>
                    <th class="px-1.5 py-2 text-left">Длит.</th>
                    <th class="px-1.5 py-2 text-left">PO, мс</th>
                    <th class="px-1.5 py-2 text-left">Статус</th>
                    <th class="px-1.5 py-2 text-left">Прокси</th>
                    <th class="px-1.5 py-2 text-left">Order</th>
                    <th class="px-1.5 py-2 text-left">Вход</th>
                    <th class="px-1.5 py-2 text-left">Эксп.</th>
                    <th class="px-1.5 py-2 text-left">Ошибка</th>
                </tr>
            </thead>
            <tbody id="mq-trades-body" class="divide-y divide-zinc-800 bg-zinc-950/40">
                <tr><td colspan="15" class="px-2 py-8 text-center text-zinc-500">Загрузка…</td></tr>
            </tbody>
        </table>
    </div>

    <p class="text-xs text-zinc-500">
        <span class="inline-block w-2 h-2 rounded-full bg-amber-400 mr-1"></span> в очереди
        <span class="inline-block w-2 h-2 rounded-full bg-sky-400 mx-1"></span> обработка
        <span class="inline-block w-2 h-2 rounded-full bg-emerald-400 mx-1"></span> на PO
        <span class="inline-block w-2 h-2 rounded-full bg-rose-400 mx-1"></span> ошибка
    </p>
</div>

<script>
(function () {
    const DATA_URL = @json(route('profile.mirror-queue.data'));
    const metaEl = document.getElementById('mq-meta');
    const jobsBody = document.getElementById('mq-jobs-body');
    const tradesBody = document.getElementById('mq-trades-body');
    let lastTradeIds = new Set();
    let timer = null;

    const queueLabels = {
        queued: { text: 'В очереди', class: 'text-amber-300' },
        processing: { text: 'Отправка…', class: 'text-sky-300' },
        done_ok: { text: 'На PO', class: 'text-emerald-400' },
        done_fail: { text: 'Ошибка', class: 'text-rose-400' },
        skipped: { text: 'Пропуск', class: 'text-zinc-500' },
        mirror_off: { text: 'Зеркало выкл.', class: 'text-zinc-500' },
        paper_closed: { text: 'Paper закрыта', class: 'text-zinc-500' },
        expired: { text: 'Истекла', class: 'text-zinc-500' },
        pending: { text: 'Ожидание', class: 'text-zinc-400' },
    };

    function sideLabel(side) {
        const s = String(side ?? '').toUpperCase();
        if (s === 'PUT') return 'PUT ↓';
        if (s === 'CALL') return 'CALL ↑';
        return side ?? '—';
    }

    function queueCell(state) {
        const cfg = queueLabels[state] || { text: state, class: 'text-zinc-400' };
        return `<span class="${cfg.class} font-medium">${cfg.text}</span>`;
    }

    function formatDuration(sec) {
        if (sec == null || sec === '') return '—';
        const n = Number(sec);
        if (!Number.isFinite(n) || n < 0) return '—';
        if (n < 60) return n + ' с';
        const m = Math.floor(n / 60);
        const s = n % 60;
        return m + ' м ' + s + ' с';
    }

    function timeCell(value, sub) {
        if (!value) return '<span class="text-zinc-600">—</span>';
        const extra = sub ? `<div class="text-zinc-600 text-[10px]">${escapeHtml(sub)}</div>` : '';
        return `<span class="text-zinc-300 font-mono text-xs">${escapeHtml(value)}</span>${extra}`;
    }

    function renderSummary(s) {
        document.getElementById('mq-jobs-pending').textContent = s.jobs_pending ?? '0';
        document.getElementById('mq-jobs-failed').textContent = s.jobs_failed ?? '0';
        const mirrorOn = s.mirror_enabled;
        const demo = s.is_demo ? 'DEMO' : 'REAL';
        const mgStep = Number(s.martingale_step ?? 0);
        const mgTotal = Number(s.martingale_total ?? 0);
        const mgLabel = s.martingale_amount_label || ('$' + (s.order_amount ?? '?'));
        const mgLine = mgTotal > 0
            ? ` · шаг ${mgStep + 1}/${mgTotal} → ${mgLabel}`
            : '';
        document.getElementById('mq-mirror-mode').innerHTML = mirrorOn
            ? `<span class="text-emerald-400">Вкл.</span> · ${demo}${mgLine}<div class="text-[10px] text-zinc-500 mt-0.5 font-mono">${escapeHtml(s.order_amounts_list || '')}</div>`
            : '<span class="text-rose-400">Выключено</span>';
        const proxyOk = s.proxy_ok && s.proxy_has_place_order;
        document.getElementById('mq-proxy').innerHTML = proxyOk
            ? '<span class="text-emerald-400">OK</span>'
            : '<span class="text-rose-400">Недоступен</span>';
    }

    function renderJobs(jobs) {
        if (!jobs.length) {
            jobsBody.innerHTML = '<tr><td colspan="7" class="py-4 text-zinc-500">Очередь пуста.</td></tr>';
            return;
        }
        jobsBody.innerHTML = jobs.map(j => {
            const st = j.state === 'processing' ? 'processing' : 'queued';
            return `<tr class="hover:bg-zinc-900/40">
                <td class="py-2 pr-4 font-mono">#${j.job_id}</td>
                <td class="py-2 pr-4 font-mono">#${j.trade_id}</td>
                <td class="py-2 pr-4">${queueCell(st)}</td>
                <td class="py-2 pr-4">${j.attempts ?? 0}</td>
                <td class="py-2 pr-4">${timeCell(j.queued_at)}</td>
                <td class="py-2 pr-4">${timeCell(j.started_at)}</td>
                <td class="py-2">${timeCell(j.finished_at, j.state === 'queued' || j.state === 'processing' ? 'в работе' : '')}</td>
            </tr>`;
        }).join('');
    }

    function renderTrades(trades) {
        if (!trades.length) {
            tradesBody.innerHTML = '<tr><td colspan="15" class="px-4 py-8 text-center text-zinc-500">За последние 6 ч сделок нет.</td></tr>';
            return;
        }
        const newIds = new Set(trades.map(t => t.id));
        tradesBody.innerHTML = trades.map(t => {
            const isNew = !lastTradeIds.has(t.id);
            const err = t.mirror_error ? `<span class="text-rose-300" title="${escapeAttr(t.mirror_error)}">${escapeHtml(t.mirror_error.slice(0, 40))}${t.mirror_error.length > 40 ? '…' : ''}</span>` : '—';
            const proxyTitle = t.mirror_proxy_url ? escapeAttr(t.mirror_proxy_url) : '';
            const proxyCell = t.mirror_proxy_direct
                ? '<span class="text-rose-400 font-medium" title="Прямое подключение с IP сервера">direct</span>'
                : (t.mirror_proxy_host
                    ? `<span class="font-mono text-emerald-400/90" title="${proxyTitle}">${escapeHtml(t.mirror_proxy_host)}</span>`
                    : '<span class="text-zinc-600">—</span>');
            return `<tr class="hover:bg-zinc-900/50 ${isNew ? 'bg-sky-950/20' : ''}">
                <td class="px-1.5 py-1.5 font-mono text-zinc-400 truncate">#${t.id}</td>
                <td class="px-1.5 py-1.5 text-zinc-200 truncate" title="${escapeAttr(t.bot_name)}">${escapeHtml(t.bot_name)}</td>
                <td class="px-1.5 py-1.5 font-mono text-zinc-300 truncate">${escapeHtml(t.instrument)}</td>
                <td class="px-1.5 py-1.5 whitespace-nowrap">${sideLabel(t.side)}</td>
                <td class="px-1.5 py-1.5">${queueCell(t.queue_state)}</td>
                <td class="px-1.5 py-1.5 truncate">${timeCell(t.job_queued_at, t.job_started_at ? 'старт ' + t.job_started_at : '')}</td>
                <td class="px-1.5 py-1.5 truncate">${timeCell(t.job_finished_at)}</td>
                <td class="px-1.5 py-1.5 text-zinc-400 font-mono whitespace-nowrap">${formatDuration(t.job_duration_sec)}</td>
                <td class="px-1.5 py-1.5 font-mono whitespace-nowrap ${t.mirror_latency_ms != null && t.mirror_latency_ms < 5000 ? 'text-emerald-400' : 'text-amber-300'}">${t.mirror_latency_ms != null ? t.mirror_latency_ms : '—'}</td>
                <td class="px-1.5 py-1.5 text-zinc-500 truncate">${escapeHtml(t.mirror_status || '—')}</td>
                <td class="px-1.5 py-1.5 truncate">${proxyCell}</td>
                <td class="px-1.5 py-1.5 font-mono text-zinc-400 truncate">${escapeHtml(t.pocket_order_id ? String(t.pocket_order_id).slice(0, 10) + '…' : '—')}</td>
                <td class="px-1.5 py-1.5 text-zinc-500 truncate">${t.entry_time ?? '—'}</td>
                <td class="px-1.5 py-1.5 text-zinc-500 truncate">${t.expiry_time ?? '—'}</td>
                <td class="px-1.5 py-1.5 truncate">${err}</td>
            </tr>`;
        }).join('');
        lastTradeIds = newIds;
    }

    function escapeHtml(s) {
        return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }
    function escapeAttr(s) {
        return escapeHtml(s).replace(/"/g, '&quot;');
    }

    async function tick() {
        try {
            const res = await fetch(DATA_URL, { headers: { 'Accept': 'application/json' } });
            const data = await res.json();
            metaEl.textContent = 'Обновлено: ' + (data.server_time || '—')
                + ' · OK: ' + (data.summary?.mirror_executed_recent ?? 0)
                + ' · fail: ' + (data.summary?.mirror_failed_recent ?? 0);
            renderSummary(data.summary || {});
            renderJobs(data.pending_jobs || []);
            renderTrades(data.trades || []);
        } catch (e) {
            metaEl.textContent = 'Ошибка загрузки';
        }
    }

    tick();
    timer = setInterval(tick, 2000);
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            clearInterval(timer);
        } else {
            tick();
            timer = setInterval(tick, 2000);
        }
    });
})();
</script>
@endsection
