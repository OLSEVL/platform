<script>
/**
 * Общий helper для маркеров Lightweight Charts v5.
 *
 * Зачем это нужно:
 * 1) В v5 удалён series.setMarkers(), нужно работать через createSeriesMarkers().
 * 2) Логика инициализации/обновления маркеров повторяется на разных страницах.
 * 3) Единый helper уменьшает риск расхождения поведения между `show` и `dashboard`.
 *
 * Примечание по совместимости:
 * - Helper не «ломает» страницу, если LightweightCharts ещё не загружен:
 *   методы вернут null/false и позволят странице продолжить работу.
 */
(function attachPlatformChartMarkersHelper(global) {
    if (global.PlatformChartMarkers) {
        return;
    }

    function formatMarkerPrice(rawPrice, precision) {
        const p = Number(rawPrice);
        if (!Number.isFinite(p)) return null;
        const digits = Number.isFinite(Number(precision)) ? Math.max(0, Number(precision)) : 5;
        return p.toFixed(digits);
    }

    function markerPriceSuffix(rawPrice, precision) {
        const priceText = formatMarkerPrice(rawPrice, precision);
        return priceText ? ` (${priceText})` : '';
    }

    /**
     * Унифицированный парсер времени для всех источников (ISO, unix sec/ms, MySQL datetime).
     * Timezone-преобразование выполняется на ingest-слое (backend) — здесь только парсинг.
     */
    function toUnixSec(value) {
        if (value == null || value === '') return null;
        if (typeof value === 'number' && Number.isFinite(value)) {
            return value > 1e12 ? Math.floor(value / 1000) : Math.floor(value);
        }
        if (typeof value === 'string') {
            const raw = value.trim();
            if (raw === '') return null;
            const num = Number(raw);
            if (Number.isFinite(num)) {
                return num > 1e12 ? Math.floor(num / 1000) : Math.floor(num);
            }
            const hasTimezone = /([zZ]|[+\-]\d{2}:?\d{2})$/.test(raw);
            const isoLike = raw.includes('T');
            const normalized = (!hasTimezone && !isoLike && raw.includes(' '))
                ? `${raw.replace(' ', 'T')}Z`
                : raw;
            const ms = Date.parse(normalized);
            if (Number.isFinite(ms)) return Math.floor(ms / 1000);
        }
        return null;
    }

    /**
     * Привязка маркера к открытию бара (по умолчанию M1: floor к минуте).
     * barSec позволяет выровнять под S5/S15 при необходимости.
     */
    function toBucketTime(unixTs, barSec) {
        const t = Number(unixTs || 0);
        const step = Number(barSec);
        const bucket = (Number.isFinite(step) && step > 0) ? step : 60;
        return t > 0 ? Math.floor(t / bucket) * bucket : null;
    }

    /**
     * Маркеры истории сигналов/сделок (вход ВВЕРХ/ВНИЗ + WIN/LOSE на экспирации).
     * Формат rows — как chartSignalsPayload на боте / chart-trades на dashboard.
     *
     * opts:
     *  - instrument (required)
     *  - targetTradeId (optional) — подсветка сделки из ?trade_id=
     *  - getFrozenExpiryParams(tradeId) — для show: замороженные expiry/price
     *  - barSec — шаг бакета времени
     */
    function buildHistoryMarkers(rows, opts) {
        const options = opts || {};
        const instrument = options.instrument;
        if (!instrument || !Array.isArray(rows)) return [];
        const targetTradeId = options.targetTradeId != null ? Number(options.targetTradeId) : null;
        const getFrozen = typeof options.getFrozenExpiryParams === 'function'
            ? options.getFrozenExpiryParams
            : () => null;
        const barSec = options.barSec;
        const markers = [];

        rows.forEach((s) => {
            if (!s || s.instrument !== instrument) return;
            const tradeId = (s.trade && s.trade.id) ? Number(s.trade.id) : null;
            const isTargetTrade = targetTradeId !== null && Number.isFinite(tradeId) && tradeId === targetTradeId;
            const signalTime = toBucketTime(toUnixSec(s.signal_at), barSec);
            if (signalTime) {
                const isCall = s.side === 'CALL';
                const tradeIdSuffix = Number.isFinite(tradeId) ? ` ${tradeId}` : '';
                const entryPriceSuffix = markerPriceSuffix(s.trade ? s.trade.entry_price : null);
                markers.push({
                    time: signalTime,
                    position: isCall ? 'belowBar' : 'aboveBar',
                    color: '#ffffff',
                    shape: isTargetTrade ? 'circle' : (isCall ? 'arrowUp' : 'arrowDown'),
                    text: isTargetTrade
                        ? `TARGET${tradeIdSuffix}`
                        : (isCall ? `ВВЕРХ${tradeIdSuffix}${entryPriceSuffix}` : `ВНИЗ${tradeIdSuffix}${entryPriceSuffix}`),
                });
            }

            const expiryRaw = (s.trade && s.trade.expiry_time) ? s.trade.expiry_time : s.expiry_time;
            if (!expiryRaw) return;
            const frozen = getFrozen(tradeId);
            const expiryTime = frozen ? frozen.expiryTime : toBucketTime(toUnixSec(expiryRaw), barSec);
            if (!expiryTime) return;

            const result = ((s.trade && s.trade.result) || s.result || 'pending');
            const color = result === 'win' ? '#10b981' : (result === 'loss' ? '#f43f5e' : '#a1a1aa');
            const tradeIdSuffix = Number.isFinite(tradeId) ? ` ${tradeId}` : '';
            const expiryPriceRaw = frozen ? frozen.price : (s.trade ? s.trade.expiry_price : null);
            const expiryPriceSuffix = markerPriceSuffix(expiryPriceRaw);

            let resultLabel = `EXP ${String(result || '').toUpperCase()}`.trim();
            if (result === 'win') resultLabel = `WIN${tradeIdSuffix}${expiryPriceSuffix}`;
            if (result === 'loss') resultLabel = `LOSE${tradeIdSuffix}${expiryPriceSuffix}`;

            // Маркер завершения рисуем ВНЕ свечи (не inBar), иначе круг пропадает в теле бара.
            const side = (s.side || '').toUpperCase();
            const expiryPos = side === 'CALL' ? 'belowBar' : 'aboveBar';
            const expiryShape = result === 'pending' ? 'circle' : 'square';
            markers.push({
                time: expiryTime,
                position: expiryPos,
                color: isTargetTrade ? '#f59e0b' : color,
                shape: isTargetTrade ? 'square' : expiryShape,
                text: isTargetTrade ? `TARGET ${resultLabel}` : resultLabel,
            });
        });

        return markers;
    }

    /**
     * Маркеры открытых сделок (● OPEN CALL/PUT) по entry_ts.
     * openTrades: [{ instrument, side, entry_ts, expiry_ts }, ...]
     */
    function buildOpenTradeMarkers(openTrades, opts) {
        const options = opts || {};
        const instrument = options.instrument;
        if (!instrument || !Array.isArray(openTrades)) return [];
        const nowSec = Number.isFinite(Number(options.nowSec))
            ? Number(options.nowSec)
            : Math.floor(Date.now() / 1000);
        const barSec = options.barSec;
        const markers = [];

        openTrades.forEach((t) => {
            if (!t || t.instrument !== instrument) return;
            const expiryTs = toUnixSec(t.expiry_ts);
            if (!Number.isFinite(expiryTs) || expiryTs <= nowSec) return;
            const side = (t.side || '').toUpperCase();
            const isCall = side === 'CALL';
            const entryTime = toBucketTime(toUnixSec(t.entry_ts), barSec);
            if (!entryTime) return;
            markers.push({
                time: entryTime,
                position: isCall ? 'belowBar' : 'aboveBar',
                color: isCall ? '#10b981' : '#f43f5e',
                shape: 'circle',
                text: `● OPEN ${side}`,
            });
        });

        return markers;
    }

    /** Объединяет массивы маркеров и сортирует по time. */
    function mergeMarkers() {
        const all = [];
        for (let i = 0; i < arguments.length; i += 1) {
            const part = arguments[i];
            if (Array.isArray(part) && part.length) {
                for (let j = 0; j < part.length; j += 1) all.push(part[j]);
            }
        }
        return all.sort((a, b) => a.time - b.time);
    }

    /**
     * Создаёт markers-plugin для конкретной серии свечей.
     * Возвращает handle плагина или null, если API недоступен.
     */
    function createPluginForSeries(series, initialMarkers) {
        if (!series || !global.LightweightCharts || typeof global.LightweightCharts.createSeriesMarkers !== 'function') {
            return null;
        }
        try {
            const startMarkers = Array.isArray(initialMarkers) ? initialMarkers : [];
            return global.LightweightCharts.createSeriesMarkers(series, startMarkers);
        } catch (e) {
            console.error('[markers-helper] createSeriesMarkers', e);
            return null;
        }
    }

    /**
     * Единая точка обновления маркеров.
     * Возвращает true, если маркеры применены, иначе false.
     */
    function applyMarkers(plugin, markers) {
        if (!plugin || typeof plugin.setMarkers !== 'function') return false;
        try {
            const safeMarkers = Array.isArray(markers) ? markers : [];
            plugin.setMarkers(safeMarkers);
            return true;
        } catch (e) {
            console.error('[markers-helper] setMarkers', e);
            return false;
        }
    }

    /**
     * Создаёт непрозрачный фон для конкретной pane (например RSI),
     * чтобы визуально убрать сетку и сделать область осциллятора спокойной.
     *
     * Важно:
     * - primitive вешается на pane API (не на series),
     * - рисуем в `draw` (zOrder=normal), чтобы заливка шла после grid,
     *   но оставалась под линией самого индикатора.
     */
    function createPaneSolidBackdrop(color) {
        const fill = color || '#0e1018';
        const renderer = {
            draw(target) {
                if (!target || typeof target.useBitmapCoordinateSpace !== 'function') return;
                target.useBitmapCoordinateSpace((scope) => {
                    const ctx = scope.context;
                    const width = scope.bitmapSize.width;
                    const height = scope.bitmapSize.height;
                    ctx.fillStyle = fill;
                    ctx.fillRect(0, 0, width, height);
                });
            },
        };
        const paneView = {
            zOrder() {
                return 'normal';
            },
            renderer() {
                return renderer;
            },
        };
        const paneViewsStable = [paneView];
        return {
            updateAllViews() {},
            paneViews() {
                return paneViewsStable;
            },
        };
    }

    /**
     * Добавляет уровни RSI (обычно 70/30) с подписями на правой шкале.
     * Возвращает handles созданных линий для корректной очистки при re-render.
     */
    function attachRsiLevelLines(series, levels) {
        if (!series || typeof series.createPriceLine !== 'function') return [];
        const lv = levels || {};
        const overbought = Number.isFinite(Number(lv.overbought)) ? Number(lv.overbought) : 70;
        const oversold = Number.isFinite(Number(lv.oversold)) ? Number(lv.oversold) : 30;
        const lineOpts = {
            color: 'rgba(180, 180, 190, 0.7)',
            lineWidth: 1,
            lineStyle: 2, // dashed
            axisLabelVisible: true, // показываем подпись уровня справа
        };
        const out = [];
        try {
            out.push(series.createPriceLine({
                ...lineOpts,
                price: overbought,
                title: formatLevelTitle(overbought),
            }));
            out.push(series.createPriceLine({
                ...lineOpts,
                price: oversold,
                title: formatLevelTitle(oversold),
            }));
        } catch (e) {
            console.error('[markers-helper] RSI level lines', e);
        }
        return out;
    }

    /**
     * Подпись уровня: целые (70/30/±50) — без дробной части; дробные (DeMarker 0.7/0.3) — с 1-2 знаками.
     */
    function formatLevelTitle(value) {
        if (Number.isInteger(value)) return String(value);
        return String(Math.round(value * 100) / 100);
    }

    /**
     * Рисует названия включённых индикаторов поверх графика LWC в стиле Pocket Option:
     * - текст «RSI 14» / «MACD 12 26 9» в левом верхнем углу pane;
     * - справа иконки: настройки (карандаш) и снять слой (×).
     * Оверлеи на основной pane складываются сверху вниз.
     *
     * Порядок oscLabels должен совпадать с порядком chart.addPane() (pane 1..n).
     *
     * opts: { chart, container, mainLabels?, oscLabels? }
     * элемент лейбла: { name, color?, layerId?, settingsUrl? }
     */
    function renderIndicatorNameLabels(opts) {
        const container = opts && opts.container;
        if (!container) return;
        // Полная перерисовка: проще и надёжнее точечных update при смене слоёв.
        container.innerHTML = '';
        container.classList.add('hidden');

        const chart = opts && opts.chart;
        const mainLabels = Array.isArray(opts.mainLabels) ? opts.mainLabels.filter((x) => x && x.name) : [];
        const oscLabels = Array.isArray(opts.oscLabels) ? opts.oscLabels.filter((x) => x && x.name) : [];
        if (!chart || typeof chart.panes !== 'function') return;
        if (!mainLabels.length && !oscLabels.length) return;

        let panes = [];
        try {
            panes = chart.panes() || [];
        } catch (_) {
            panes = [];
        }
        if (!panes.length) return;

        const paneHeight = (pane, fallbackRatio, totalH) => {
            if (pane && typeof pane.getHeight === 'function') {
                const h = Number(pane.getHeight());
                if (Number.isFinite(h) && h > 0) return h;
            }
            if (pane && typeof pane.getStretchFactor === 'function' && totalH > 0) {
                const sf = Number(pane.getStretchFactor());
                if (Number.isFinite(sf) && sf > 0) return Math.max(30, totalH * fallbackRatio);
            }
            return Math.max(30, Math.round(totalH * fallbackRatio) || 80);
        };

        const hostH = container.clientHeight || 0;
        let stretchSum = 0;
        panes.forEach((p) => {
            try {
                if (p && typeof p.getStretchFactor === 'function') {
                    const sf = Number(p.getStretchFactor());
                    if (Number.isFinite(sf) && sf > 0) stretchSum += sf;
                }
            } catch (_) {}
        });
        if (stretchSum <= 0) stretchSum = panes.length;

        // Иконки как на PO: карандаш (настройки) и крестик (убрать слой).
        const svgPencil = '<svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>';
        const svgClose = '<svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>';

        const styleIconBtn = (el) => {
            el.style.display = 'inline-flex';
            el.style.alignItems = 'center';
            el.style.justifyContent = 'center';
            el.style.width = '14px';
            el.style.height = '14px';
            el.style.padding = '0';
            el.style.margin = '0';
            el.style.border = '0';
            el.style.background = 'transparent';
            el.style.color = '#a1a1aa';
            el.style.cursor = 'pointer';
            el.style.lineHeight = '1';
            el.style.textDecoration = 'none';
            el.style.flexShrink = '0';
            el.addEventListener('mouseenter', () => { el.style.color = '#f4f4f5'; });
            el.addEventListener('mouseleave', () => { el.style.color = '#a1a1aa'; });
        };

        const makeLabel = (item, topPx) => {
            const wrap = document.createElement('div');
            wrap.className = 'classic-indicator-name-label';
            wrap.style.position = 'absolute';
            wrap.style.left = '6px';
            wrap.style.top = `${Math.max(0, Math.round(topPx))}px`;
            wrap.style.zIndex = '5';
            wrap.style.display = 'inline-flex';
            wrap.style.alignItems = 'center';
            wrap.style.gap = '5px';
            wrap.style.padding = '1px 2px';
            wrap.style.fontSize = '12px';
            wrap.style.lineHeight = '1.2';
            wrap.style.fontWeight = '500';
            wrap.style.letterSpacing = '0.01em';
            wrap.style.fontFamily = 'ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, sans-serif';
            wrap.style.color = '#f4f4f5';
            // Лёгкая тень вместо «карточки» — ближе к подписи PO на тёмном фоне.
            wrap.style.textShadow = '0 1px 2px rgba(0,0,0,0.85), 0 0 6px rgba(0,0,0,0.45)';
            wrap.style.whiteSpace = 'nowrap';
            // Родитель overlay pointer-events:none — у детей auto, чтобы кликались иконки.
            wrap.style.pointerEvents = 'auto';
            wrap.style.userSelect = 'none';

            const text = document.createElement('span');
            text.textContent = String(item.name);
            text.style.pointerEvents = 'none';
            wrap.appendChild(text);

            const settingsUrl = item.settingsUrl || null;
            if (settingsUrl) {
                const a = document.createElement('a');
                a.href = settingsUrl;
                a.target = '_blank';
                a.rel = 'noopener noreferrer';
                a.title = 'Настройки индикатора';
                a.setAttribute('aria-label', `Настройки: ${item.name}`);
                a.innerHTML = svgPencil;
                styleIconBtn(a);
                wrap.appendChild(a);
            }

            if (item.layerId) {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.title = 'Убрать с графика';
                btn.setAttribute('aria-label', `Убрать: ${item.name}`);
                btn.innerHTML = svgClose;
                styleIconBtn(btn);
                btn.addEventListener('click', (ev) => {
                    ev.preventDefault();
                    ev.stopPropagation();
                    const cb = document.getElementById(String(item.layerId));
                    if (!cb || cb.disabled) return;
                    // Снимаем слой тем же путём, что и чекбокс в попапе индикаторов.
                    cb.checked = false;
                    cb.dispatchEvent(new Event('change', { bubbles: true }));
                });
                wrap.appendChild(btn);
            }

            container.appendChild(wrap);
            return wrap;
        };

        const mainH = paneHeight(panes[0], (panes[0] && typeof panes[0].getStretchFactor === 'function'
            ? Number(panes[0].getStretchFactor()) || 1
            : 1) / stretchSum, hostH);
        let mainTop = 4;
        mainLabels.forEach((lb) => {
            if (mainTop + 16 > mainH - 2) return;
            makeLabel(lb, mainTop);
            mainTop += 16;
        });

        let offsetY = mainH;
        for (let i = 0; i < oscLabels.length; i += 1) {
            const pane = panes[i + 1];
            if (!pane) break;
            const sf = (pane && typeof pane.getStretchFactor === 'function')
                ? (Number(pane.getStretchFactor()) || 0.28)
                : 0.28;
            const h = paneHeight(pane, sf / stretchSum, hostH);
            makeLabel(oscLabels[i], offsetY + 3);
            offsetY += h;
        }

        container.classList.remove('hidden');
    }

    /**
     * Оверлей открытых сделок в стиле Pocket Option:
     * белая точка входа + зелёная/красная горизонталь до экспирации + бейдж (стрелка/$/таймер)
     * + вертикаль «Время закрытия».
     *
     * ctx = {
     *   overlayEl, chart, series, candleData, trades, instrument, barSec,
     *   nowSec?, show?
     * }
     */
    function renderPoOpenTradeRays(ctx) {
        const overlay = ctx && ctx.overlayEl;
        if (!overlay) return;
        const chart = ctx.chart;
        const series = ctx.series;
        const data = Array.isArray(ctx.candleData) ? ctx.candleData : [];
        const instrument = ctx.instrument;
        const show = ctx.show !== false;
        const trades = Array.isArray(ctx.trades) ? ctx.trades : [];
        const barSec = (Number.isFinite(Number(ctx.barSec)) && Number(ctx.barSec) > 0)
            ? Number(ctx.barSec)
            : 60;
        const nowSec = Number.isFinite(Number(ctx.nowSec))
            ? Number(ctx.nowSec)
            : Math.floor(Date.now() / 1000);

        if (!show || !chart || !series || !instrument || !data.length) {
            overlay.innerHTML = '';
            overlay.classList.add('hidden');
            return;
        }

        const ts = chart.timeScale();
        if (!ts) {
            overlay.innerHTML = '';
            overlay.classList.add('hidden');
            return;
        }

        const rows = trades.filter((t) => {
            if (!t || t.instrument !== instrument) return false;
            const exp = toUnixSec(t.expiry_ts);
            return Number.isFinite(exp) && exp > nowSec;
        });

        if (!rows.length) {
            overlay.innerHTML = '';
            overlay.classList.add('hidden');
            return;
        }

        const lastBar = data[data.length - 1];
        const lastBarTime = Number(lastBar && lastBar.time);
        let lastBarX = ts.timeToCoordinate(lastBarTime);
        if ((lastBarX == null || Number.isNaN(lastBarX)) && typeof ts.logicalToCoordinate === 'function') {
            lastBarX = ts.logicalToCoordinate(data.length - 1);
        }
        const spacing = (typeof ts.barSpacing === 'function') ? Number(ts.barSpacing()) : 8;

        function timeToX(unixTs) {
            if (!Number.isFinite(unixTs)) return null;
            let x = ts.timeToCoordinate(unixTs);
            if (x != null && !Number.isNaN(x)) return x;

            // Будущее правее последней свечи — экстраполяция по barSpacing.
            if (Number.isFinite(lastBarTime) && unixTs > lastBarTime
                && lastBarX != null && !Number.isNaN(lastBarX)
                && Number.isFinite(spacing) && spacing > 0) {
                return lastBarX + ((unixTs - lastBarTime) / barSec) * spacing;
            }

            // Внутри истории: интерполяция между соседними барами (вход не всегда = open бара).
            let idx = data.findIndex((c) => Number(c.time) >= unixTs);
            if (idx < 0) idx = data.length - 1;
            if (idx > 0 && Number(data[idx].time) > unixTs) {
                const t0 = Number(data[idx - 1].time);
                const t1 = Number(data[idx].time);
                const x0 = ts.timeToCoordinate(t0);
                const x1c = ts.timeToCoordinate(t1);
                if (x0 != null && x1c != null && !Number.isNaN(x0) && !Number.isNaN(x1c) && t1 > t0) {
                    return x0 + (x1c - x0) * ((unixTs - t0) / (t1 - t0));
                }
            }
            const snapped = ts.timeToCoordinate(Number(data[idx].time));
            return (snapped != null && !Number.isNaN(snapped)) ? snapped : null;
        }

        function formatMmSs(total) {
            const s = Math.max(0, Math.floor(total));
            const m = Math.floor(s / 60);
            const r = s % 60;
            return String(m).padStart(2, '0') + ':' + String(r).padStart(2, '0');
        }

        function formatClockHms(unixTs) {
            const d = new Date(unixTs * 1000);
            if (Number.isNaN(d.getTime())) return '';
            return String(d.getHours()).padStart(2, '0') + ':'
                + String(d.getMinutes()).padStart(2, '0') + ':'
                + String(d.getSeconds()).padStart(2, '0');
        }

        const frag = document.createDocumentFragment();
        rows.forEach((t) => {
            const entryTs = toUnixSec(t.entry_ts);
            const expiryTs = toUnixSec(t.expiry_ts);
            if (!Number.isFinite(entryTs) || !Number.isFinite(expiryTs)) return;

            let entryPrice = (t.entry_price != null && t.entry_price !== '')
                ? Number(t.entry_price)
                : NaN;
            if (!Number.isFinite(entryPrice)) {
                // Фоллбек: close ближайшей свечи к входу.
                let idx = data.findIndex((c) => Number(c.time) >= entryTs);
                if (idx < 0) idx = data.length - 1;
                entryPrice = Number(data[idx] && data[idx].close);
            }
            if (!Number.isFinite(entryPrice)) return;

            const y = series.priceToCoordinate(entryPrice);
            if (y == null || Number.isNaN(y)) return;

            const x1 = timeToX(entryTs);
            const x2 = timeToX(expiryTs);
            if (x1 == null || x2 == null || Number.isNaN(x1) || Number.isNaN(x2)) return;
            if (x2 <= x1 + 2) return;

            const side = String(t.side || '').toUpperCase();
            const isCall = side === 'CALL';
            const color = isCall ? '#22c55e' : '#ef4444';
            const left = Math.min(x1, x2);
            const width = Math.abs(x2 - x1);
            const leftSec = Math.max(0, expiryTs - nowSec);
            const amountLabel = (t.amount_label && String(t.amount_label))
                || (Number.isFinite(Number(t.amount)) ? ('$' + Number(t.amount)) : '—');

            // Горизонтальная «черточка» entry → expiry.
            const ray = document.createElement('div');
            ray.setAttribute('data-po-ray', String(t.id || ''));
            ray.style.cssText = [
                'position:absolute',
                `left:${left}px`,
                `top:${y}px`,
                `width:${width}px`,
                'height:0',
                `border-top:2px solid ${color}`,
                'box-shadow:0 0 6px rgba(0,0,0,0.35)',
                'pointer-events:none',
                'z-index:2',
            ].join(';');
            frag.appendChild(ray);

            // Белая точка входа (как на PO).
            const dot = document.createElement('div');
            dot.style.cssText = [
                'position:absolute',
                `left:${x1 - 4}px`,
                `top:${y - 4}px`,
                'width:8px',
                'height:8px',
                'border-radius:999px',
                'background:#fff',
                `box-shadow:0 0 0 2px ${color}`,
                'pointer-events:none',
                'z-index:3',
            ].join(';');
            frag.appendChild(dot);

            // Бейдж слева от входа: стрелка+сумма сверху, таймер снизу (как на PO).
            const badge = document.createElement('div');
            badge.style.cssText = [
                'position:absolute',
                `left:${Math.max(4, x1 - 72)}px`,
                `top:${y - 22}px`,
                'display:flex',
                'flex-direction:column',
                'align-items:flex-start',
                'gap:2px',
                'padding:4px 8px',
                'border-radius:6px',
                `background:${color}`,
                'color:#fff',
                'font-size:11px',
                'font-weight:700',
                'line-height:1.15',
                'white-space:nowrap',
                'box-shadow:0 2px 8px rgba(0,0,0,0.35)',
                'pointer-events:none',
                'z-index:4',
                'font-family:ui-sans-serif,system-ui,sans-serif',
            ].join(';');
            const topRow = document.createElement('div');
            topRow.style.cssText = 'display:inline-flex;align-items:center;gap:5px';
            const arrow = document.createElement('span');
            arrow.setAttribute('aria-hidden', 'true');
            arrow.textContent = isCall ? '▲' : '▼';
            const amt = document.createElement('span');
            amt.className = 'tabular-nums';
            amt.textContent = amountLabel;
            topRow.appendChild(arrow);
            topRow.appendChild(amt);
            const timer = document.createElement('span');
            timer.className = 'tabular-nums';
            timer.setAttribute('data-po-timer', '1');
            timer.style.fontFamily = 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace';
            timer.style.fontWeight = '600';
            timer.style.fontSize = '10px';
            timer.textContent = formatMmSs(leftSec);
            badge.appendChild(topRow);
            badge.appendChild(timer);
            frag.appendChild(badge);

            // Вертикаль экспирации.
            const vline = document.createElement('div');
            vline.style.cssText = [
                'position:absolute',
                `left:${x2}px`,
                'top:0',
                'bottom:0',
                'width:0',
                'border-left:1px dashed rgba(96,165,250,0.85)',
                'pointer-events:none',
                'z-index:1',
            ].join(';');
            frag.appendChild(vline);

            // Подпись «Время закрытия» над вертикалью (две строки, как на PO).
            const closeLbl = document.createElement('div');
            closeLbl.style.cssText = [
                'position:absolute',
                `left:${x2 + 4}px`,
                'top:6px',
                'color:rgba(147,197,253,0.95)',
                'font-size:10px',
                'font-weight:600',
                'line-height:1.25',
                'white-space:nowrap',
                'text-shadow:0 1px 2px rgba(0,0,0,0.8)',
                'pointer-events:none',
                'z-index:4',
            ].join(';');
            closeLbl.innerHTML = 'Время закрытия<br>' + formatClockHms(expiryTs);
            frag.appendChild(closeLbl);

            // Маленький таймер у правого конца линии.
            const endTimer = document.createElement('div');
            endTimer.style.cssText = [
                'position:absolute',
                `left:${x2 + 4}px`,
                `top:${y + 6}px`,
                'color:rgba(226,232,240,0.9)',
                'font-size:10px',
                'font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace',
                'font-variant-numeric:tabular-nums',
                'pointer-events:none',
                'z-index:4',
            ].join(';');
            endTimer.setAttribute('data-po-timer', '1');
            endTimer.textContent = formatMmSs(leftSec);
            frag.appendChild(endTimer);
        });

        overlay.replaceChildren(frag);
        overlay.classList.remove('hidden');
    }

    global.PlatformChartMarkers = {
        formatMarkerPrice,
        markerPriceSuffix,
        toUnixSec,
        toBucketTime,
        buildHistoryMarkers,
        buildOpenTradeMarkers,
        mergeMarkers,
        createPluginForSeries,
        applyMarkers,
        createPaneSolidBackdrop,
        attachRsiLevelLines,
        renderIndicatorNameLabels,
        renderPoOpenTradeRays,
    };
})(window);
</script>
