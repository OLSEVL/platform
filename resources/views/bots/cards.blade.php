@extends('layouts.app')
@section('title', 'Карточки — '.$bot->name)
@section('content')
<div class="space-y-4" id="bot-cards-app"
    data-data-url="{{ route('bots.cards.data', $bot) }}"
    data-bot-id="{{ $bot->id }}"
    data-bot-name="{{ $bot->name }}">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="text-2xl font-semibold">Карточки — {{ $bot->name }}</h1>
            <a href="{{ route('bots.show', $bot) }}" class="rounded bg-zinc-800 px-3 py-1 text-sm text-zinc-100 hover:bg-zinc-700">График</a>
            <a href="{{ route('bots.activity', $bot) }}" class="rounded bg-zinc-800 px-3 py-1 text-sm text-zinc-100 hover:bg-zinc-700">Сигналы</a>
        </div>
        <div class="flex flex-wrap items-center gap-4 text-sm">
            <label class="inline-flex cursor-pointer items-center gap-2 text-zinc-300">
                <input type="checkbox" id="cards-sound-toggle" class="rounded border-zinc-600 bg-zinc-900 text-emerald-500 focus:ring-emerald-500/40">
                <span>Звук</span>
            </label>
            <div class="text-xs text-zinc-400" id="cards-meta">Обновление: —</div>
        </div>
    </div>

    <div id="cards-grid" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
        <div class="col-span-full rounded border border-zinc-800 bg-zinc-950/40 px-4 py-10 text-center text-zinc-500">
            Загрузка карточек…
        </div>
    </div>
</div>

{{-- Модалка: данные замораживаются при открытии (без live-обновления). --}}
<div id="card-modal" class="fixed inset-0 z-50 hidden" aria-hidden="true">
    <div id="card-modal-backdrop" class="absolute inset-0 bg-black/70"></div>
    <div class="relative flex min-h-full items-center justify-center p-4">
        <div class="relative flex max-h-[90vh] w-full max-w-5xl flex-col overflow-hidden rounded-xl border border-zinc-700 bg-zinc-900 shadow-2xl lg:flex-row"
            role="dialog" aria-modal="true" aria-labelledby="card-modal-title">
            <button type="button" id="card-modal-close"
                class="absolute right-3 top-3 z-10 rounded bg-zinc-800/90 px-2 py-1 text-lg leading-none text-zinc-300 hover:bg-zinc-700 hover:text-white"
                aria-label="Закрыть">×</button>

            <div class="flex min-h-[220px] flex-1 flex-col gap-3 overflow-y-auto border-b border-zinc-800 bg-zinc-950 p-3 lg:max-h-[90vh] lg:max-w-[50%] lg:border-b-0 lg:border-r">
                <div>
                    <div class="mb-1 text-xs font-medium uppercase tracking-wide text-zinc-500">M1</div>
                    <img id="card-modal-chart-m1" src="" alt="График M1" class="max-h-[240px] w-full rounded border border-zinc-800 object-contain">
                </div>
                <div>
                    <div class="mb-1 text-xs font-medium uppercase tracking-wide text-zinc-500">M15</div>
                    <img id="card-modal-chart-m15" src="" alt="График M15" class="max-h-[240px] w-full rounded border border-zinc-800 object-contain">
                </div>
            </div>

            <div class="flex max-h-[50vh] flex-1 flex-col overflow-y-auto p-5 lg:max-h-[90vh]">
                <h2 id="card-modal-title" class="pr-8 text-lg font-semibold text-zinc-100"></h2>

                <dl class="mt-4 grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                    <div>
                        <dt class="text-zinc-500">Валютная пара</dt>
                        <dd><a id="card-modal-pair" href="#" class="font-medium text-cyan-400 hover:text-cyan-300"></a></dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500">Направление</dt>
                        <dd id="card-modal-side" class="font-semibold"></dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500">Экспирация</dt>
                        <dd id="card-modal-expiry" class="text-zinc-200"></dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500">Таймфрейм</dt>
                        <dd id="card-modal-tf" class="text-zinc-200">1м</dd>
                    </div>
                </dl>

                <div class="mt-4 rounded-lg border border-zinc-700/80 bg-zinc-950/60 p-4" id="card-modal-strategy-box">
                    <div id="card-modal-strategy-title" class="font-semibold text-zinc-100"></div>
                    <p id="card-modal-strategy-text" class="mt-2 text-sm leading-relaxed text-zinc-300"></p>
                </div>

                <div class="mt-4 space-y-2 text-sm">
                    <div class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Статистика сигнала</div>
                    <ul class="space-y-1.5 text-zinc-300">
                        <li class="flex justify-between gap-2"><span class="text-zinc-500">Выплата брокера</span><span id="card-modal-payout"></span></li>
                        <li class="flex justify-between gap-2"><span class="text-zinc-500">Уверенность</span><span id="card-modal-confidence"></span></li>
                        <li class="flex justify-between gap-2"><span class="text-zinc-500">Вход</span><span id="card-modal-entry" class="font-mono"></span></li>
                        <li class="flex justify-between gap-2"><span class="text-zinc-500">Текущая цена</span><span id="card-modal-current" class="font-mono"></span></li>
                        <li class="flex justify-between gap-2"><span class="text-zinc-500">Появился</span><span id="card-modal-active"></span></li>
                        <li class="flex justify-between gap-2"><span class="text-zinc-500">До экспирации</span><span id="card-modal-left" class="font-mono text-cyan-300"></span></li>
                        <li class="flex justify-between gap-2"><span class="text-zinc-500">Статус</span><span id="card-modal-status"></span></li>
                    </ul>
                </div>

                <div class="mt-4 border-t border-zinc-800 pt-3 text-xs leading-relaxed text-zinc-500" id="card-modal-technical"></div>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    const root = document.getElementById('bot-cards-app');
    const DATA_URL = root.dataset.dataUrl;
    const BOT_ID = root.dataset.botId;
    const grid = document.getElementById('cards-grid');
    const meta = document.getElementById('cards-meta');
    const soundToggle = document.getElementById('cards-sound-toggle');
    const modal = document.getElementById('card-modal');
    const modalBackdrop = document.getElementById('card-modal-backdrop');
    const modalClose = document.getElementById('card-modal-close');

    const SOUND_KEY = 'bot_cards_sound_' + BOT_ID;
    let knownIds = new Set();
    let cardsById = new Map();
    let liveCards = [];
    let firstLoad = true;
    let pollTimer = null;
    let tickTimer = null;

    soundToggle.checked = localStorage.getItem(SOUND_KEY) !== '0';
    soundToggle.addEventListener('change', () => {
        localStorage.setItem(SOUND_KEY, soundToggle.checked ? '1' : '0');
    });

    function playNewCardSound() {
        if (!soundToggle.checked) return;
        try {
            const ctx = new (window.AudioContext || window.webkitAudioContext)();
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.type = 'sine';
            osc.frequency.value = 880;
            gain.gain.value = 0.08;
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start();
            gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.25);
            osc.stop(ctx.currentTime + 0.25);
        } catch (_) {}
    }

    function escapeHtml(s) {
        return String(s ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    /** URL в HTML-атрибут src: не трогаем &, только кавычки. */
    function attrUrl(url) {
        return String(url ?? '').replace(/"/g, '&quot;');
    }

    function withCacheBust(url) {
        if (!url) return '';
        return url + (url.includes('?') ? '&' : '?') + 't=' + Date.now();
    }

    function cardThumbSrc(card) {
        return card.chart_thumb_data_uri || card.chart_thumb_url || '';
    }

    function loadChartIntoImg(imgEl, url, previewDataUri) {
        if (!imgEl) return;
        if (imgEl.dataset.blobUrl) {
            URL.revokeObjectURL(imgEl.dataset.blobUrl);
            delete imgEl.dataset.blobUrl;
        }

        const fullUrl = url ? withCacheBust(url) : '';
        const preview = previewDataUri || '';

        imgEl.classList.remove('opacity-40');
        imgEl.alt = imgEl.dataset.chartAlt || 'График';

        // Сначала миниатюра из JSON (мгновенно), затем полный PNG по URL.
        if (preview && fullUrl) {
            imgEl.src = preview;
            const loader = new Image();
            loader.onload = () => { imgEl.src = fullUrl; };
            loader.onerror = () => { imgEl.src = fullUrl; };
            loader.src = fullUrl;
            return;
        }

        if (preview) {
            imgEl.src = preview;
            return;
        }

        if (!fullUrl) {
            imgEl.removeAttribute('src');
            imgEl.alt = 'График недоступен';
            return;
        }

        imgEl.classList.add('opacity-40');
        imgEl.onload = () => imgEl.classList.remove('opacity-40');
        imgEl.onerror = () => {
            imgEl.removeAttribute('src');
            imgEl.alt = 'График недоступен';
            imgEl.classList.remove('opacity-40');
        };
        imgEl.src = fullUrl;
    }

    function formatActiveSec(sec) {
        sec = Math.max(0, Math.floor(sec));
        if (sec < 60) return sec + 'с назад';
        const m = Math.floor(sec / 60);
        if (m < 60) return m + 'м назад';
        return Math.floor(m / 60) + 'ч назад';
    }

    function formatCountdown(sec) {
        sec = Math.max(0, Math.floor(sec));
        const m = Math.floor(sec / 60);
        const s = sec % 60;
        return String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
    }

    function progressPct(card, nowSec) {
        const entryTs = Number(card.entry_ts) || 0;
        const expiryTs = Number(card.expiry_ts) || 0;
        const total = expiryTs > entryTs ? (expiryTs - entryTs) : (Number(card.expiry_sec) || 0);
        if (total <= 0) return 0;
        const left = expiryTs > 0 ? Math.max(0, expiryTs - nowSec) : 0;
        const elapsed = Math.min(total, total - left);
        return Math.min(100, Math.max(0, Math.round((elapsed / total) * 100)));
    }

    function sideBadge(card) {
        const up = card.is_up;
        const cls = up ? 'bg-emerald-900/60 text-emerald-300 border-emerald-700/50' : 'bg-rose-900/60 text-rose-300 border-rose-700/50';
        const arrow = up ? '↑' : '↓';
        return `<span class="inline-flex items-center rounded border px-2 py-0.5 text-xs font-semibold ${cls}">${arrow} ${escapeHtml(card.side_ru)} · ${escapeHtml(card.expiry_label)}</span>`;
    }

    function strategyAnalysisHtml(card, compact) {
        const title = escapeHtml(card.strategy_title || '');
        const narrative = escapeHtml(card.strategy_narrative || '');
        const technical = escapeHtml(card.technical_details || '');
        const textCls = compact ? 'text-xs' : 'text-sm';
        const padCls = compact ? 'p-3' : 'p-4';

        return `
            <div class="mt-3 rounded-lg border border-zinc-700/80 bg-zinc-950/60 ${padCls}">
                <div class="font-semibold text-zinc-100 card-strategy-title ${textCls}">${title}</div>
                <p class="mt-2 ${textCls} leading-relaxed text-zinc-300 card-strategy-narrative">${narrative}</p>
            </div>
            ${technical ? `<div class="mt-2 text-xs leading-relaxed text-zinc-500 card-technical">${technical}</div>` : ''}
        `;
    }

    function renderCard(card) {
        const barColor = card.is_up ? 'bg-emerald-500' : 'bg-rose-500';
        const nowSec = Math.floor(Date.now() / 1000);
        const activeSec = card.created_ts ? Math.max(0, nowSec - Number(card.created_ts)) : 0;
        const expiryLeft = card.expiry_ts ? Math.max(0, Number(card.expiry_ts) - nowSec) : 0;
        const pct = progressPct(card, nowSec);

        return `
            <article class="group relative flex cursor-pointer flex-col rounded-lg border border-zinc-800 bg-zinc-900/80 p-4 shadow-sm transition hover:border-zinc-600 hover:bg-zinc-900"
                data-card-id="${card.id}"
                data-created-ts="${card.created_ts ?? ''}"
                data-entry-ts="${card.entry_ts ?? ''}"
                data-expiry-ts="${card.expiry_ts ?? ''}"
                data-expiry-sec="${card.expiry_sec ?? ''}">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <a href="${escapeHtml(card.show_url)}"
                            class="card-pair-link text-base font-semibold text-cyan-400 hover:text-cyan-300 hover:underline"
                            data-stop-card="1">${escapeHtml(card.instrument)}</a>
                        <div class="mt-1 text-xs text-zinc-500">ТФ ${escapeHtml(card.timeframe)}</div>
                    </div>
                    ${sideBadge(card)}
                </div>

                <div class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-zinc-400">
                    <span class="text-emerald-400 card-active-label">● активна ${escapeHtml(formatActiveSec(activeSec))}</span>
                    <span class="font-mono text-cyan-300 card-expiry-left">${formatCountdown(expiryLeft)}</span>
                    <span>Выплата <span class="text-zinc-200 card-payout">${escapeHtml(card.payout_label)}</span></span>
                </div>

                <div class="mt-3 font-mono text-2xl font-semibold tracking-tight text-zinc-50 card-price">
                    ${escapeHtml(card.current_price ?? card.entry_price ?? '—')}
                </div>

                ${cardThumbSrc(card) ? `
                <div class="mt-3 overflow-hidden rounded-lg border border-zinc-800 bg-zinc-950">
                    <img src="${attrUrl(cardThumbSrc(card))}"
                        alt="M1 ${escapeHtml(card.instrument)}"
                        class="card-chart-thumb block min-h-[120px] w-full object-cover"
                        loading="lazy">
                </div>` : `
                <div class="mt-3 rounded-lg border border-dashed border-zinc-700 bg-zinc-950/50 px-3 py-6 text-center text-xs text-zinc-500 card-chart-missing">
                    График M1 загружается…
                </div>`}

                ${strategyAnalysisHtml(card, true)}

                <div class="mt-4 h-1.5 overflow-hidden rounded-full bg-zinc-800">
                    <div class="${barColor} card-progress-inner h-full rounded-full transition-[width] duration-1000 ease-linear" style="width:${pct}%"></div>
                </div>

                <div class="absolute bottom-0 left-0 right-0 h-1 rounded-b-lg ${barColor} opacity-80"></div>
            </article>
        `;
    }

    function ensureChartThumb(el, card) {
        const src = cardThumbSrc(card);
        if (!src) {
            return;
        }
        let thumbEl = el.querySelector('.card-chart-thumb');
        if (!thumbEl) {
            const missing = el.querySelector('.card-chart-missing');
            if (missing) {
                missing.remove();
            }
            const priceEl = el.querySelector('.card-price');
            if (!priceEl) {
                return;
            }
            const box = document.createElement('div');
            box.className = 'mt-3 overflow-hidden rounded-lg border border-zinc-800 bg-zinc-950';
            thumbEl = document.createElement('img');
            thumbEl.alt = 'M1 ' + (card.instrument || '');
            thumbEl.className = 'card-chart-thumb block min-h-[120px] w-full object-cover';
            thumbEl.loading = 'lazy';
            box.appendChild(thumbEl);
            priceEl.insertAdjacentElement('afterend', box);
        }
        if (thumbEl.getAttribute('src') !== src) {
            thumbEl.src = src;
        }
    }

    function updateCardElement(el, card) {
        el.dataset.createdTs = String(card.created_ts ?? '');
        el.dataset.entryTs = String(card.entry_ts ?? '');
        el.dataset.expiryTs = String(card.expiry_ts ?? '');
        el.dataset.expirySec = String(card.expiry_sec ?? '');

        const priceEl = el.querySelector('.card-price');
        if (priceEl) {
            priceEl.textContent = card.current_price ?? card.entry_price ?? '—';
        }
        const payoutEl = el.querySelector('.card-payout');
        if (payoutEl) {
            payoutEl.textContent = card.payout_label ?? '—';
        }
        ensureChartThumb(el, card);
        const thumbEl = el.querySelector('.card-chart-thumb');
        if (thumbEl) {
            const nextSrc = cardThumbSrc(card);
            if (nextSrc && thumbEl.getAttribute('src') !== nextSrc) {
                thumbEl.src = nextSrc;
            }
        }
        const titleEl = el.querySelector('.card-strategy-title');
        if (titleEl) {
            titleEl.textContent = card.strategy_title ?? '';
        }
        const narrativeEl = el.querySelector('.card-strategy-narrative');
        if (narrativeEl) {
            narrativeEl.textContent = card.strategy_narrative ?? '';
        }
        const technicalEl = el.querySelector('.card-technical');
        if (technicalEl) {
            technicalEl.textContent = card.technical_details ?? '';
        }

        cardsById.set(card.id, card);
    }

    function bindCardClicks() {
        grid.querySelectorAll('article[data-card-id]').forEach(el => {
            if (el.dataset.clickBound === '1') return;
            el.dataset.clickBound = '1';
            el.addEventListener('click', (e) => {
                if (e.target.closest('[data-stop-card]')) return;
                const id = Number(el.getAttribute('data-card-id'));
                const card = cardsById.get(id);
                if (card) openModal(card);
            });
        });
    }

    function tickLive() {
        const nowSec = Math.floor(Date.now() / 1000);

        grid.querySelectorAll('article[data-card-id]').forEach(el => {
            const createdTs = Number(el.dataset.createdTs) || 0;
            const expiryTs = Number(el.dataset.expiryTs) || 0;
            const entryTs = Number(el.dataset.entryTs) || 0;
            const expirySec = Number(el.dataset.expirySec) || 0;

            const activeSec = createdTs > 0 ? Math.max(0, nowSec - createdTs) : 0;
            const expiryLeft = expiryTs > 0 ? Math.max(0, expiryTs - nowSec) : 0;
            const total = expiryTs > entryTs ? (expiryTs - entryTs) : expirySec;
            const elapsed = total > 0 ? Math.min(total, total - expiryLeft) : 0;
            const pct = total > 0 ? Math.min(100, Math.max(0, Math.round((elapsed / total) * 100))) : 0;

            const activeEl = el.querySelector('.card-active-label');
            if (activeEl) activeEl.textContent = '● активна ' + formatActiveSec(activeSec);

            const leftEl = el.querySelector('.card-expiry-left');
            if (leftEl) leftEl.textContent = formatCountdown(expiryLeft);

            const barEl = el.querySelector('.card-progress-inner');
            if (barEl) barEl.style.width = pct + '%';
        });
    }

    function syncGrid(cards) {
        if (!cards.length) {
            grid.innerHTML = '<div class="col-span-full rounded border border-zinc-800 bg-zinc-950/40 px-4 py-10 text-center text-zinc-500">Нет активных сделок.</div>';
            return;
        }

        cards.forEach(c => cardsById.set(c.id, c));

        // Первая отрисовка — целиком, дальше точечные обновления без мерцания.
        if (!grid.querySelector('article[data-card-id]')) {
            grid.innerHTML = cards.map(renderCard).join('');
            bindCardClicks();
            tickLive();
            return;
        }

        const nextIds = new Set(cards.map(c => c.id));

        grid.querySelectorAll('article[data-card-id]').forEach(el => {
            const id = Number(el.getAttribute('data-card-id'));
            if (!nextIds.has(id)) el.remove();
        });

        const fragment = document.createDocumentFragment();
        cards.forEach(card => {
            let el = grid.querySelector(`article[data-card-id="${card.id}"]`);
            if (el) {
                updateCardElement(el, card);
            } else {
                const wrap = document.createElement('div');
                wrap.innerHTML = renderCard(card);
                const article = wrap.firstElementChild;
                if (article) fragment.appendChild(article);
            }
        });
        if (fragment.childNodes.length) {
            grid.prepend(fragment);
        }

        bindCardClicks();
        tickLive();
    }

    function openModal(card) {
        const nowSec = Math.floor(Date.now() / 1000);
        const activeSec = card.created_ts ? Math.max(0, nowSec - Number(card.created_ts)) : 0;
        const expiryLeft = card.expiry_ts ? Math.max(0, Number(card.expiry_ts) - nowSec) : 0;

        document.getElementById('card-modal-title').textContent =
            `${card.instrument} · ${card.side_ru} · ${card.expiry_label} · ${card.timeframe}`;

        const pair = document.getElementById('card-modal-pair');
        pair.textContent = card.instrument;
        pair.href = card.show_url;

        const sideEl = document.getElementById('card-modal-side');
        sideEl.textContent = card.side_ru;
        sideEl.className = 'font-semibold ' + (card.is_up ? 'text-emerald-400' : 'text-rose-400');

        document.getElementById('card-modal-expiry').textContent = card.expiry_label;
        document.getElementById('card-modal-strategy-title').textContent = card.strategy_title;
        document.getElementById('card-modal-strategy-text').textContent = card.strategy_narrative || '';
        document.getElementById('card-modal-payout').textContent = card.payout_label || '—';
        document.getElementById('card-modal-confidence').textContent = card.confidence_fmt || '—';
        document.getElementById('card-modal-entry').textContent = card.entry_price || '—';
        document.getElementById('card-modal-current').textContent = card.current_price || '—';
        document.getElementById('card-modal-active').textContent = formatActiveSec(activeSec);
        document.getElementById('card-modal-left').textContent = formatCountdown(expiryLeft);
        document.getElementById('card-modal-status').textContent = card.status_label || 'Активна';
        document.getElementById('card-modal-technical').textContent = card.technical_details || '';

        const chartM1 = document.getElementById('card-modal-chart-m1');
        const chartM15 = document.getElementById('card-modal-chart-m15');
        if (chartM1) {
            chartM1.dataset.chartAlt = 'M1 ' + (card.instrument || '');
            loadChartIntoImg(chartM1, card.chart_m1_url || card.chart_url || '', card.chart_thumb_data_uri || '');
        }
        if (chartM15) {
            chartM15.dataset.chartAlt = 'M15 ' + (card.instrument || '');
            loadChartIntoImg(chartM15, card.chart_m15_url || '', '');
        }

        modal.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
    }

    function closeModal() {
        modal.classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
        ['card-modal-chart-m1', 'card-modal-chart-m15'].forEach((id) => {
            const img = document.getElementById(id);
            if (!img) return;
            if (img.dataset.blobUrl) {
                URL.revokeObjectURL(img.dataset.blobUrl);
                delete img.dataset.blobUrl;
            }
            img.removeAttribute('src');
        });
    }

    modalClose.addEventListener('click', closeModal);
    modalBackdrop.addEventListener('click', closeModal);
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !modal.classList.contains('hidden')) closeModal();
    });

    async function refreshOnce() {
        try {
            const res = await fetch(DATA_URL, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                cache: 'no-store',
            });
            if (!res.ok) {
                throw new Error('http_' + res.status);
            }
            const data = await res.json();
            const cards = data.cards || [];
            const newIds = new Set(cards.map(c => c.id));

            if (!firstLoad) {
                for (const c of cards) {
                    if (!knownIds.has(c.id)) playNewCardSound();
                }
            }
            firstLoad = false;
            knownIds = newIds;
            liveCards = cards;

            syncGrid(cards);
            meta.textContent = `Обновление: ${new Date().toLocaleTimeString()} · активных: ${data.count ?? 0}`;
        } catch (err) {
            meta.textContent = 'Ошибка обновления. Повторяем…';
        }
    }

    refreshOnce();
    pollTimer = setInterval(refreshOnce, 1500);
    tickTimer = setInterval(tickLive, 1000);
    window.addEventListener('beforeunload', () => {
        if (pollTimer) clearInterval(pollTimer);
        if (tickTimer) clearInterval(tickTimer);
    });
})();
</script>
@endsection
