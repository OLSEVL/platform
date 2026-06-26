@extends('layouts.app')

@section('title', 'Диагностика процессов')

@section('main_class', 'w-full max-w-none flex-1 min-h-0 overflow-y-auto p-0 m-0')

@section('content')
<div class="w-full min-w-0 px-3 sm:px-4 md:px-6 py-4">
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-zinc-100">Диагностика процессов</h1>
            <p class="text-sm text-zinc-400 mt-1">Фоновые daemon'ы, scan ботов, очереди и ingest. Обновление каждые 4 сек.</p>
        </div>
        <a href="{{ route('settings.index') }}" class="text-sm text-indigo-300 hover:text-indigo-200">← Настройки</a>
    </div>

    <div id="diag-flash" class="hidden mb-4 p-3 rounded border text-sm"></div>

    {{-- Сводка --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
        <div class="rounded-lg border border-zinc-800 bg-zinc-950/50 p-4">
            <p class="text-xs text-zinc-500 uppercase tracking-wide">Общий статус</p>
            <p id="diag-overall" class="text-lg font-semibold mt-1 text-zinc-200">—</p>
        </div>
        <div class="rounded-lg border border-zinc-800 bg-zinc-950/50 p-4">
            <p class="text-xs text-zinc-500 uppercase tracking-wide">Load average</p>
            <p id="diag-load" class="text-lg font-semibold mt-1 text-zinc-200 font-mono">—</p>
        </div>
        <div class="rounded-lg border border-zinc-800 bg-zinc-950/50 p-4">
            <p class="text-xs text-zinc-500 uppercase tracking-wide">Ingest</p>
            <p id="diag-ingest" class="text-sm mt-1 text-zinc-300">—</p>
        </div>
        <div class="rounded-lg border border-zinc-800 bg-zinc-950/50 p-4">
            <p class="text-xs text-zinc-500 uppercase tracking-wide">Активные боты</p>
            <p id="diag-bots" class="text-sm mt-1 text-zinc-300">—</p>
        </div>
    </div>

    {{-- Очереди --}}
    <div class="mb-6 rounded-lg border border-zinc-800 bg-zinc-950/40 p-4">
        <h2 class="text-sm font-semibold text-zinc-200 mb-3">Очереди Laravel</h2>
        <div id="diag-queues" class="flex flex-wrap gap-4 text-sm text-zinc-400">—</div>
    </div>

    {{-- Процессы --}}
    <div id="diag-processes" class="space-y-4">
        <p class="text-zinc-500 text-sm">Загрузка…</p>
    </div>
</div>

<script>
(function () {
    const liveUrl = @json(route('settings.processes.live-ajax'));
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const killUrlTemplate = @json(route('settings.processes.kill', ['key' => '__KEY__']));
    const restartUrlTemplate = @json(route('settings.processes.restart', ['key' => '__KEY__']));

    const statusClass = {
        ok: 'text-emerald-400 bg-emerald-950/40 border-emerald-800',
        warning: 'text-amber-300 bg-amber-950/40 border-amber-800',
        error: 'text-rose-300 bg-rose-950/40 border-rose-800',
        stopped: 'text-zinc-400 bg-zinc-900/60 border-zinc-700',
    };

    const statusLabel = {
        ok: 'OK',
        warning: 'Warning',
        error: 'Error',
        stopped: 'Stopped',
    };

    const formatElapsed = (sec) => {
        if (sec == null || sec < 0) return '—';
        const h = Math.floor(sec / 3600);
        const m = Math.floor((sec % 3600) / 60);
        const s = sec % 60;
        if (h > 0) return `${h}ч ${m}м`;
        if (m > 0) return `${m}м ${s}с`;
        return `${s}с`;
    };

    const flash = (text, ok) => {
        const node = document.getElementById('diag-flash');
        if (!node) return;
        node.textContent = text;
        node.classList.remove('hidden', 'border-emerald-800', 'text-emerald-200', 'bg-emerald-950/50', 'border-rose-800', 'text-rose-200', 'bg-rose-950/50');
        if (ok) {
            node.classList.add('border-emerald-800', 'text-emerald-200', 'bg-emerald-950/50');
        } else {
            node.classList.add('border-rose-800', 'text-rose-200', 'bg-rose-950/50');
        }
    };

    const renderSummary = (data) => {
        const overall = document.getElementById('diag-overall');
        const load = document.getElementById('diag-load');
        const ingest = document.getElementById('diag-ingest');
        const bots = document.getElementById('diag-bots');
        const queues = document.getElementById('diag-queues');

        if (overall) {
            const st = data.overall_status || 'ok';
            overall.textContent = statusLabel[st] || st;
            overall.className = 'text-lg font-semibold mt-1 ' + (st === 'ok' ? 'text-emerald-400' : st === 'warning' ? 'text-amber-300' : st === 'error' ? 'text-rose-300' : 'text-zinc-400');
        }

        if (load && data.load_avg) {
            load.textContent = `${data.load_avg['1m']} / ${data.load_avg['5m']} / ${data.load_avg['15m']}`;
        }

        if (ingest && data.ingest) {
            const i = data.ingest;
            ingest.innerHTML = `${i.active_instruments} инстр. · ${i.candle_updates_last_minute} upd/min` +
                (i.ingest_silent_instruments > 0 ? ` · <span class="text-amber-300">${i.ingest_silent_instruments} silent</span>` : '');
        }

        if (bots) {
            const list = data.active_bots || [];
            bots.textContent = list.length
                ? list.map(b => `#${b.id} ${b.name}`).join(', ')
                : 'нет активных';
        }

        if (queues && data.queues) {
            queues.innerHTML = Object.entries(data.queues).map(([name, q]) =>
                `<span class="rounded bg-zinc-900 px-2 py-1 border border-zinc-800"><strong class="text-zinc-300">${name}</strong>: pending ${q.pending}${q.failed ? `, failed ${q.failed}` : ''}</span>`
            ).join('');
        }
    };

    const escapeHtml = (value) => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');

    const renderProcessCard = (p) => {
        const st = p.status || 'stopped';
        const badgeClass = statusClass[st] || statusClass.stopped;
        const pids = (p.pids || []).join(', ') || '—';
        const issues = (p.issues || []).map(i => `<li class="text-amber-300/90">${escapeHtml(i)}</li>`).join('');
        const instances = (p.instances || []).map(inst =>
            `<span class="font-mono text-xs text-zinc-400">PID ${escapeHtml(inst.pid)}${inst.cpu_percent != null ? ` · CPU ${escapeHtml(inst.cpu_percent)}%` : ''}${inst.elapsed_sec != null ? ` · ${escapeHtml(formatElapsed(inst.elapsed_sec))}` : ''}</span>`
        ).join('<br>');

        let detailsHtml = '';
        if (p.key === 'bots_scan' && p.details?.scan_progress?.length) {
            detailsHtml += '<div class="mt-2 text-xs text-zinc-400"><p class="text-zinc-500 mb-1">Прогресс scan:</p><ul class="list-disc pl-4 space-y-1">';
            p.details.scan_progress.forEach(row => {
                const pr = row.progress || {};
                detailsHtml += `<li>#${escapeHtml(row.bot_id)} ${escapeHtml(row.bot_name)}: ${escapeHtml(pr.analyzing_instrument || '—')} · осталось ${escapeHtml(pr.scan_remaining ?? '?')} / ${escapeHtml(pr.scan_total ?? '?')}</li>`;
            });
            detailsHtml += '</ul></div>';
        }
        if (p.details?.last_run_at) {
            const mins = p.details.minutes_ago != null ? Math.max(0, Math.round(Number(p.details.minutes_ago))) : null;
            detailsHtml += `<p class="text-xs text-zinc-500 mt-2">Последний schedule: ${escapeHtml(p.details.last_run_at)}${mins != null ? ` (${mins} мин назад)` : ''}</p>`;
        }
        if (p.details?.pending_jobs != null) {
            detailsHtml += `<p class="text-xs text-zinc-500 mt-1">Pending jobs: ${escapeHtml(p.details.pending_jobs)}</p>`;
        }

        const actions = (p.actions || []).map(action => {
            const label = action === 'kill' ? 'Stop' : 'Restart';
            const btnClass = action === 'kill'
                ? 'border-rose-800/80 text-rose-300 hover:bg-rose-950/50'
                : 'border-indigo-800/80 text-indigo-300 hover:bg-indigo-950/50';
            return `<button type="button" class="diag-action px-2 py-1 text-xs rounded border ${btnClass}" data-key="${escapeHtml(p.key)}" data-action="${escapeHtml(action)}">${label}</button>`;
        }).join(' ');

        // Лог через DOM после вставки карточки: в хвосте бывают ${...} из PHP stack trace — ломают template literal.
        const logTail = (p.log_tail || '').trim();
        const logPlaceholder = logTail
            ? `<details class="mt-3 diag-log-details"><summary class="text-xs text-zinc-500 cursor-pointer">Лог (хвост)</summary><pre class="mt-2 text-[11px] text-zinc-500 bg-zinc-900/80 rounded p-2 overflow-x-auto max-h-40 whitespace-pre-wrap diag-log-pre"></pre></details>`
            : '';

        const html = `
            <article class="rounded-lg border border-zinc-800 bg-zinc-950/40 p-4" data-process-key="${escapeHtml(p.key)}">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-base font-semibold text-zinc-100">${escapeHtml(p.label)}</h2>
                            <span class="text-xs px-2 py-0.5 rounded border ${badgeClass}">${escapeHtml(statusLabel[st] || st)}</span>
                            ${p.running ? '<span class="text-xs text-emerald-400/80">running</span>' : '<span class="text-xs text-zinc-500">not running</span>'}
                        </div>
                        <p class="text-xs text-zinc-500 mt-1">${escapeHtml(p.description || '')}</p>
                        <div class="mt-2 text-sm text-zinc-400">${instances || `<span>PIDs: ${escapeHtml(pids)}</span>`}</div>
                        ${issues ? `<ul class="mt-2 text-xs list-disc pl-4">${issues}</ul>` : ''}
                        ${detailsHtml}
                        ${logPlaceholder}
                    </div>
                    <div class="flex flex-wrap gap-2 shrink-0">${actions}</div>
                </div>
            </article>`;

        const wrap = document.createElement('div');
        wrap.innerHTML = html;
        const article = wrap.firstElementChild;
        if (logTail && article) {
            const pre = article.querySelector('.diag-log-pre');
            if (pre) pre.textContent = logTail;
        }

        return article;
    };

    const renderProcesses = (data) => {
        const root = document.getElementById('diag-processes');
        if (!root) return;
        const list = data.processes || [];
        root.replaceChildren();
        list.forEach((p) => {
            const card = renderProcessCard(p);
            if (card) root.appendChild(card);
        });
    };

    let refreshInFlight = false;

    const applySnapshot = (data) => {
        if (!data?.ok) return;
        renderSummary(data);
        renderProcesses(data);
    };

    const refresh = async () => {
        if (refreshInFlight || document.hidden) return;
        refreshInFlight = true;
        try {
            const res = await fetch(liveUrl, { headers: { Accept: 'application/json' } });
            const data = await res.json();
            applySnapshot(data);
        } catch (_) {
            // тихо — оставляем последнее состояние
        } finally {
            refreshInFlight = false;
        }
    };

    const postAction = async (key, action) => {
        const tpl = action === 'kill' ? killUrlTemplate : restartUrlTemplate;
        const url = tpl.replace('__KEY__', encodeURIComponent(key));
        const label = action === 'kill' ? 'остановить' : 'перезапустить';
        if (!confirm(`Вы уверены, что хотите ${label} «${key}»?`)) return;

        try {
            const res = await fetch(url, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({}),
            });
            const data = await res.json();
            flash(data.message || (data.ok ? 'Готово' : (data.error || 'Ошибка')), !!data.ok);
            if (data.status) applySnapshot(data.status);
            else await refresh();
        } catch (e) {
            flash('Ошибка сети: ' + e.message, false);
        }
    };

    document.getElementById('diag-processes')?.addEventListener('click', (e) => {
        const btn = e.target.closest('.diag-action');
        if (!btn) return;
        postAction(btn.dataset.key, btn.dataset.action);
    });

    applySnapshot(@json($initialSnapshot));
    refresh();
    setInterval(refresh, 4000);
})();
</script>
@endsection
