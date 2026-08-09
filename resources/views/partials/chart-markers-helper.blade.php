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
    };
})(window);
</script>
