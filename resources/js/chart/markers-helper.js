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

    /** APP_TIMEZONE платформы (config app.timezone), см. partials/platform-timezone.blade.php */
    function appTimeFormat() {
        return global.PlatformTimeFormat || null;
    }

    /** HH:MM:SS в APP_TIMEZONE (не локаль браузера). */
    function formatWallClockHms(unixSec) {
        const TF = appTimeFormat();
        if (TF && typeof TF.hms === 'function') {
            return TF.hms(unixSec);
        }
        const sec = Math.floor(Number(unixSec));
        if (!Number.isFinite(sec)) return '';
        const d = new Date(sec * 1000);
        const p = (n) => String(n).padStart(2, '0');
        return `${p(d.getUTCHours())}:${p(d.getUTCMinutes())}:${p(d.getUTCSeconds())}`;
    }

    /** HH:MM в APP_TIMEZONE. */
    function formatWallClockHm(unixSec) {
        const TF = appTimeFormat();
        if (TF && typeof TF.hm === 'function') {
            return TF.hm(unixSec);
        }
        const sec = Math.floor(Number(unixSec));
        if (!Number.isFinite(sec)) return '';
        const d = new Date(sec * 1000);
        const p = (n) => String(n).padStart(2, '0');
        return `${p(d.getUTCHours())}:${p(d.getUTCMinutes())}`;
    }

    /** Время в скобках маркера: (HH:MM:SS) в APP_TIMEZONE. */
    function formatMarkerTimeSuffix(rawTime) {
        const sec = toUnixSec(rawTime);
        if (!Number.isFinite(sec) || sec <= 0) return '';
        const text = formatWallClockHms(sec);
        return text ? ` (${text})` : '';
    }

    function formatMarkerEntryTimeSuffix(rawTime) {
        return formatMarkerTimeSuffix(rawTime);
    }

    /** Длительность сделки entry→expiry в скобках: (300) — секунды. */
    function formatMarkerDurationSuffix(entryRaw, expiryRaw) {
        const entrySec = toUnixSec(entryRaw);
        const expirySec = toUnixSec(expiryRaw);
        if (!Number.isFinite(entrySec) || !Number.isFinite(expirySec) || expirySec <= entrySec) return '';
        return ` (${Math.round(expirySec - entrySec)})`;
    }

    /** entry_time из trade или signal_at как fallback (как в buildTradeDurationsFromSignals). */
    function resolveSignalEntryRaw(signalRow) {
        if (!signalRow) return null;
        const trade = signalRow.trade;
        return (trade && trade.entry_time) ? trade.entry_time : signalRow.signal_at;
    }

    /** expiry_time из trade или expiry_time сигнала. */
    function resolveSignalExpiryRaw(signalRow) {
        if (!signalRow) return null;
        const trade = signalRow.trade;
        return (trade && trade.expiry_time) ? trade.expiry_time : signalRow.expiry_time;
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
     * Привязка маркера к открытию бара, в котором лежит unixTs (по умолчанию M1).
     * Для экспирации: WIN/LOSE на свече, которая шла в момент expiry_time.
     */
    function toBucketTime(unixTs, barSec) {
        const t = Number(unixTs || 0);
        const step = Number(barSec);
        const bucket = (Number.isFinite(step) && step > 0) ? step : 60;
        return t > 0 ? Math.floor(t / bucket) * bucket : null;
    }

    /**
     * X-позиция точного unix-времени на шкале графика.
     *
     * Важно: нельзя полагаться на timeToCoordinate(unixTs) для времени внутри бара —
     * Lightweight Charts часто возвращает координату open текущего бара. Тогда 60-с сделка
     * 16:05:35 → 16:06:35 на M1 схлопывается в одну свечу (x2 ≈ x1), а PO-лучи пропадают.
     * Интерполируем только между open соседних баров из candleData.
     */
    function interpolatedTimeToX(ts, unixTs, data, barSec) {
        if (!ts || !Number.isFinite(unixTs) || !Array.isArray(data) || data.length === 0) return null;
        const step = (Number.isFinite(Number(barSec)) && Number(barSec) > 0) ? Number(barSec) : 60;

        const lastBar = data[data.length - 1];
        const lastBarTime = Number(lastBar && lastBar.time);
        let lastBarX = ts.timeToCoordinate(lastBarTime);
        if ((lastBarX == null || Number.isNaN(lastBarX)) && typeof ts.logicalToCoordinate === 'function') {
            lastBarX = ts.logicalToCoordinate(data.length - 1);
        }
        const spacing = (typeof ts.barSpacing === 'function') ? Number(ts.barSpacing()) : 8;

        // Будущее правее последней свечи — экстраполяция по barSpacing.
        if (Number.isFinite(lastBarTime) && unixTs > lastBarTime
            && lastBarX != null && !Number.isNaN(lastBarX)
            && Number.isFinite(spacing) && spacing > 0) {
            return lastBarX + ((unixTs - lastBarTime) / step) * spacing;
        }

        // Точное совпадение с open бара — берём координату бара без интерполяции.
        for (let i = 0; i < data.length; i++) {
            if (Number(data[i].time) === unixTs) {
                let x = ts.timeToCoordinate(unixTs);
                if (x != null && !Number.isNaN(x)) return x;
                if (typeof ts.logicalToCoordinate === 'function') {
                    x = ts.logicalToCoordinate(i);
                    if (x != null && !Number.isNaN(x)) return x;
                }
                break;
            }
        }

        // Первый бар правее unixTs — интерполируем между prev.open и next.open.
        let idx = data.findIndex((c) => Number(c.time) > unixTs);
        if (idx < 0) idx = data.length - 1;
        if (idx === 0 && Number(data[0].time) > unixTs) {
            const x0 = ts.timeToCoordinate(Number(data[0].time));
            return (x0 != null && !Number.isNaN(x0)) ? x0 : null;
        }
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

    /**
     * Дробный logical index по X (с секундами внутри бара).
     * LWC v5: logicalToCoordinate(i) — центр бара i → float index = i - 0.5.
     * coordinateToLogical() делает Math.ceil → только open (:00).
     */
    function floatLogicalAtX(ts, x, anchorLogical) {
        if (!ts || !Number.isFinite(x)) return null;
        const spacing = Number(typeof ts.barSpacing === 'function' ? ts.barSpacing() : NaN);
        if (!Number.isFinite(spacing) || spacing <= 0) return null;
        if (typeof ts.logicalToCoordinate !== 'function') return null;

        // param.logical от LWC — ceil(floatIndex); якорь для точной калибровки
        const anchor = Number(anchorLogical);
        if (Number.isFinite(anchor)) {
            const xAnchor = Number(ts.logicalToCoordinate(Math.trunc(anchor)));
            if (Number.isFinite(xAnchor)) {
                return (Math.trunc(anchor) - 0.5) + (x - xAnchor) / spacing;
            }
        }

        const range = ts.getVisibleLogicalRange();
        if (!range) return null;
        const idx0 = Math.floor(Number(range.from));
        const x0 = Number(ts.logicalToCoordinate(idx0));
        if (!Number.isFinite(x0)) return null;
        return (idx0 - 0.5) + (x - x0) / spacing;
    }

    /** Unix-время по дробному logical index (бар i занимает float (i-1, i]). */
    function floatLogicalToUnix(logical, data, barSec, series) {
        if (!Number.isFinite(logical)) return null;
        const step = (Number.isFinite(Number(barSec)) && Number(barSec) > 0) ? Number(barSec) : 60;
        const barIdx = Math.ceil(logical - 1e-9);
        if (barIdx < 0) return null;
        const frac = logical - (barIdx - 1);

        let tPrev = null;
        let tCurr = null;
        const prevIdx = barIdx - 1;
        if (series && typeof series.dataByIndex === 'function') {
            try {
                const bPrev = prevIdx >= 0 ? series.dataByIndex(prevIdx) : null;
                const bCurr = series.dataByIndex(barIdx);
                if (bPrev) tPrev = toUnixSec(bPrev.time);
                if (bCurr) tCurr = toUnixSec(bCurr.time);
            } catch (_) {}
        }
        if (tPrev == null && Array.isArray(data) && prevIdx >= 0 && data[prevIdx]) {
            tPrev = Number(data[prevIdx].time);
        }
        if (tCurr == null && Array.isArray(data) && data[barIdx]) {
            tCurr = Number(data[barIdx].time);
        }
        if (tPrev != null && tCurr != null && tCurr > tPrev) {
            return tPrev + frac * (tCurr - tPrev);
        }
        if (tCurr != null) return tCurr;
        return tPrev;
    }

    /**
     * Обратная к interpolatedTimeToX: X → unix с секундами.
     * Основной путь для кроссхейра — зеркалит расчёт маркеров/лучей сделок.
     */
    function unixTimeAtXSegmentScan(ts, x, data, barSec, hintLogical) {
        if (!ts || !Number.isFinite(x) || !Array.isArray(data) || data.length < 2) return null;
        const step = (Number.isFinite(Number(barSec)) && Number(barSec) > 0) ? Number(barSec) : 60;
        const spacing = (typeof ts.barSpacing === 'function') ? Number(ts.barSpacing()) : 8;
        const eps = 0.5;

        let start = 0;
        let end = data.length - 2;
        if (Number.isFinite(Number(hintLogical))) {
            const h = Math.trunc(Number(hintLogical));
            start = Math.max(0, h - 5);
            end = Math.min(data.length - 2, h + 5);
        }

        const trySegment = (i) => {
            const t0 = Number(data[i].time);
            const t1 = Number(data[i + 1].time);
            if (!(t1 > t0)) return null;
            const x0 = interpolatedTimeToX(ts, t0, data, step);
            const x1 = interpolatedTimeToX(ts, t1, data, step);
            if (x0 == null || x1 == null || Number.isNaN(x0) || Number.isNaN(x1) || x1 <= x0) return null;
            if (x >= x0 - eps && x <= x1 + eps) {
                return t0 + ((x - x0) / (x1 - x0)) * (t1 - t0);
            }
            return null;
        };

        for (let i = start; i <= end; i += 1) {
            const t = trySegment(i);
            if (Number.isFinite(t)) return t;
        }
        if (start > 0 || end < data.length - 2) {
            for (let i = 0; i < data.length - 1; i += 1) {
                const t = trySegment(i);
                if (Number.isFinite(t)) return t;
            }
        }

        const lastT = Number(data[data.length - 1].time);
        const lastX = interpolatedTimeToX(ts, lastT, data, step);
        if (lastX != null && !Number.isNaN(lastX) && x > lastX + eps && spacing > 0) {
            return lastT + ((x - lastX) / spacing) * step;
        }
        return null;
    }

    /** Unix-время под координатой X (float logical + segment scan). */
    function unixTimeAtCoordinate(ts, x, data, barSec, series, anchorLogical) {
        const tSeg = unixTimeAtXSegmentScan(ts, x, data, barSec, anchorLogical);
        if (Number.isFinite(tSeg)) return tSeg;
        const logical = floatLogicalAtX(ts, x, anchorLogical);
        return floatLogicalToUnix(logical, data, barSec, series);
    }

    /**
     * Обратная к interpolatedTimeToX: unix-время по X курсора.
     */
    function interpolateTimeAtX(ts, x, data, barSec, series) {
        if (!ts || !Number.isFinite(x)) return null;
        const t = unixTimeAtCoordinate(ts, x, data, barSec, series, null);
        if (Number.isFinite(t)) return t;
        return null;
    }

    /** Время под кроссхейром: X → unix с секундами; fallback — param.time (open бара). */
    function resolveCrosshairUnixTime(param, ts, data, barSec, series) {
        const x = Number(param && param.point ? param.point.x : NaN);
        const anchorLogical = Number(param && param.logical);
        if (Number.isFinite(x) && ts) {
            const t = unixTimeAtCoordinate(
                ts,
                x,
                data,
                barSec,
                series,
                Number.isFinite(anchorLogical) ? anchorLogical : null,
            );
            if (Number.isFinite(t)) return t;
        }
        return toUnixSec(param && param.time);
    }

    /**
     * Свеча, которую стратегия уже закрыла к моменту сигнала.
     * Scan почти всегда в +1 с нового бакета (S30: 13:35:31 → бар 13:35:00, не 13:35:30).
     * Иначе стрелка входа сидит на следующей свече, а DeMarker там уже не в зоне.
     */
    function toClosedBarTime(unixTs, barSec) {
        const t = Number(unixTs || 0);
        const step = Number(barSec);
        const bucket = (Number.isFinite(step) && step > 0) ? step : 60;
        if (!(t > 0)) return null;
        const closed = (Math.floor(t / bucket) - 1) * bucket;
        return closed > 0 ? closed : null;
    }

    /**
     * LWC-маркеры сделок отключены: стрелки WIN/LOSE рисуются в renderTradeDurationOverlay
     * на тех же X (interpolatedTimeToX), что и полоска entry→expiry.
     * toClosedBarTime / toBucketTime давали сдвиг относительно полоски.
     */
    function buildHistoryMarkers(rows, opts) {
        void rows;
        void opts;
        return [];
    }

    /** @see buildHistoryMarkers — открытые сделки тоже на DOM-оверлее полоски. */
    function buildOpenTradeMarkers(openTrades, opts) {
        void openTrades;
        void opts;
        return [];
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
     * Отвязывает markers-plugin от серии (LWC v5: Er.detach()).
     * Нужно на «чистом» графике: plugin + poll setData/update со временем ломает canvas свечей.
     */
    function detachPlugin(plugin) {
        if (!plugin) return;
        try {
            if (typeof plugin.detach === 'function') {
                plugin.detach();
            }
        } catch (e) {
            console.error('[markers-helper] detachPlugin', e);
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

        /**
         * Очищаем подписи только тогда, когда их действительно больше нельзя показать.
         * Важно также удалить ключ последнего рендера: после повторного создания графика
         * те же данные должны заново сформировать DOM-элементы.
         */
        const clearLabels = () => {
            if (container.childElementCount) container.innerHTML = '';
            delete container.dataset.indicatorLabelsRenderKey;
        container.classList.add('hidden');
        };

        const chart = opts && opts.chart;
        const mainLabels = Array.isArray(opts.mainLabels) ? opts.mainLabels.filter((x) => x && x.name) : [];
        const oscLabels = Array.isArray(opts.oscLabels) ? opts.oscLabels.filter((x) => x && x.name) : [];
        if (!chart || typeof chart.panes !== 'function') {
            clearLabels();
            return;
        }
        if (!mainLabels.length && !oscLabels.length) {
            clearLabels();
            return;
        }

        let panes = [];
        try {
            panes = chart.panes() || [];
        } catch (_) {
            panes = [];
        }
        if (!panes.length) {
            clearLabels();
            return;
        }

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
            // SVG остаётся компактным (11×11), а зона попадания мышью становится больше.
            el.style.width = '18px';
            el.style.height = '18px';
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
                // Тот же data-атрибут, что у ⚙ в списке индикаторов:
                // делегированный обработчик открывает любую форму в единой модали.
                a.dataset.indicatorSettingsModal = '';
                a.dataset.indicatorSettingsTitle = String(item.name);
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
                    const layerId = String(item.layerId);
                    // Pilot: экземпляры SMA/EMA — inst-42, без чекбокса layer-*.
                    if (layerId.startsWith('inst-') && window.PlatformChartIndicatorInstances) {
                        const id = parseInt(layerId.slice(5), 10);
                        if (Number.isFinite(id)) {
                            PlatformChartIndicatorInstances.setVisibleById(id, false);
                        }
                        return;
                    }
                    const cb = document.getElementById(layerId);
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
        const placements = [];
        let mainTop = 4;
        mainLabels.forEach((lb) => {
            if (mainTop + 18 > mainH - 2) return;
            placements.push({ item: lb, top: mainTop });
            mainTop += 18;
        });

        let offsetY = mainH;
        for (let i = 0; i < oscLabels.length; i += 1) {
            const pane = panes[i + 1];
            if (!pane) break;
            const sf = (pane && typeof pane.getStretchFactor === 'function')
                ? (Number(pane.getStretchFactor()) || 0.28)
                : 0.28;
            const h = paneHeight(pane, sf / stretchSum, hostH);
            placements.push({ item: oscLabels[i], top: offsetY + 3 });
            offsetY += h;
        }

        /**
         * Частые обновления свечей и canvas-оверлеев могут вызывать эту функцию много
         * раз в секунду. Если состав подписей и их координаты не изменились, сохраняем
         * существующие ссылки и кнопки в DOM. Иначе удаление элемента прямо во время
         * hover/click вызывает mouseleave, визуальное мигание и потерю клика.
         */
        const renderKey = JSON.stringify(placements.map(({ item, top }) => ({
            name: String(item.name),
            layerId: item.layerId ? String(item.layerId) : '',
            settingsUrl: item.settingsUrl ? String(item.settingsUrl) : '',
            top: Math.max(0, Math.round(top)),
        })));
        if (container.dataset.indicatorLabelsRenderKey === renderKey
            && container.childElementCount === placements.length) {
            container.classList.remove('hidden');
            return;
        }

        container.innerHTML = '';
        placements.forEach(({ item, top }) => makeLabel(item, top));
        container.dataset.indicatorLabelsRenderKey = renderKey;
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
            : ((global.PlatformServerClock && typeof global.PlatformServerClock.unixNow === 'function')
                ? global.PlatformServerClock.unixNow()
                : Math.floor(Date.now() / 1000));

        if (!show || !chart || !series || !instrument || !data.length) {
            // PR3b: не трогаем DOM, если оверлей уже пуст.
            if (!overlay.classList.contains('hidden') || overlay.childElementCount) {
                overlay.replaceChildren();
                overlay.classList.add('hidden');
            }
            return;
        }

        const ts = chart.timeScale();
        if (!ts) {
            if (!overlay.classList.contains('hidden') || overlay.childElementCount) {
                overlay.replaceChildren();
                overlay.classList.add('hidden');
            }
            return;
        }

        const rows = trades.filter((t) => {
            if (!t || t.instrument !== instrument) return false;
            const exp = toUnixSec(t.expiry_ts);
            return Number.isFinite(exp) && exp > nowSec;
        });

        if (!rows.length) {
            if (!overlay.classList.contains('hidden') || overlay.childElementCount) {
                overlay.replaceChildren();
                overlay.classList.add('hidden');
            }
            return;
        }

        function timeToX(unixTs) {
            return interpolatedTimeToX(ts, unixTs, data, barSec);
        }

        function formatMmSs(total) {
            const s = Math.max(0, Math.floor(total));
            const m = Math.floor(s / 60);
            const r = s % 60;
            return String(m).padStart(2, '0') + ':' + String(r).padStart(2, '0');
        }

        function formatClockHms(unixTs) {
            return formatWallClockHms(unixTs);
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

    /**
     * Собирает интервалы Paper-сделок (entry → expiry) для горизонтальных полос на графике.
     * rows — signals/chart-trades (как на bots/show и dashboard); openTrades — живые позиции.
     */
    function buildTradeDurationsFromSignals(rows, opts) {
        const options = opts || {};
        const instrument = options.instrument;
        if (!instrument) return [];
        const nowSec = Number.isFinite(Number(options.nowSec))
            ? Number(options.nowSec)
            : ((global.PlatformServerClock && typeof global.PlatformServerClock.unixNow === 'function')
                ? global.PlatformServerClock.unixNow()
                : Math.floor(Date.now() / 1000));
        const byKey = new Map();

        (rows || []).forEach((s) => {
            if (!s || s.instrument !== instrument) return;
            const tradeId = (s.trade && s.trade.id) ? Number(s.trade.id) : null;
            const entryRaw = (s.trade && s.trade.entry_time) ? s.trade.entry_time : s.signal_at;
            const entryTs = toUnixSec(entryRaw);
            const expiryRaw = (s.trade && s.trade.expiry_time) ? s.trade.expiry_time : s.expiry_time;
            const expiryTs = toUnixSec(expiryRaw);
            if (!Number.isFinite(entryTs) || !Number.isFinite(expiryTs) || expiryTs <= entryTs) return;
            const side = (s.side || '').toUpperCase();
            const result = ((s.trade && s.trade.result) || s.result || 'pending');
            const entryPrice = (s.trade && s.trade.entry_price != null) ? Number(s.trade.entry_price) : null;
            const expiryPrice = (s.trade && s.trade.expiry_price != null) ? Number(s.trade.expiry_price) : null;
            const isActive = result === 'pending' && expiryTs > nowSec;
            const key = (Number.isFinite(tradeId) && tradeId > 0) ? String(tradeId) : `sig-${s.id || entryTs}`;
            byKey.set(key, {
                entryTs,
                expiryTs,
                side,
                result,
                tradeId: Number.isFinite(tradeId) ? tradeId : null,
                botId: Number.isFinite(Number(s.bot_id)) ? Number(s.bot_id) : (Number.isFinite(Number(options.botId)) ? Number(options.botId) : null),
                entryPrice,
                expiryPrice,
                source: isActive ? 'active' : 'history',
            });
        });

        (options.openTrades || []).forEach((t) => {
            if (!t || t.instrument !== instrument) return;
            const expiryTs = toUnixSec(t.expiry_ts != null ? t.expiry_ts : t.expiry_time);
            if (!(Number.isFinite(expiryTs) && expiryTs > nowSec)) return;
            const tradeId = Number(t.id);
            const key = (Number.isFinite(tradeId) && tradeId > 0) ? String(tradeId) : `open-${t.id || expiryTs}`;
            const entryTs = toUnixSec(t.entry_ts != null ? t.entry_ts : t.entry_time);
            if (!Number.isFinite(entryTs) || expiryTs <= entryTs) return;
            const side = (t.side || '').toUpperCase();
            const entryPrice = (t.entry_price != null && t.entry_price !== '') ? Number(t.entry_price) : null;
            byKey.set(key, {
                entryTs,
                expiryTs,
                side,
                result: 'pending',
                tradeId: Number.isFinite(tradeId) ? tradeId : null,
                botId: Number.isFinite(Number(t.bot_id)) ? Number(t.bot_id) : (Number.isFinite(Number(options.botId)) ? Number(options.botId) : null),
                entryPrice,
                expiryPrice: null,
                source: 'active',
            });
        });

        return Array.from(byKey.values());
    }

    /**
     * Горизонтальные полосы длительности Paper-сделок (entry → expiry) на DOM-оверлее.
     * Не путать с renderPoOpenTradeRays — тот рисует PO-лучи только для открытых сделок.
     *
     * ctx = {
     *   overlayEl, chartWrapperEl, chart, series, candleData, barSec, show,
     *   durations[], targetTradeId?, getFrozenExpiryParams?(tradeId)
     * }
     */
    function renderTradeDurationOverlay(ctx) {
        const overlay = ctx && ctx.overlayEl;
        if (!overlay) return;
        const show = ctx.show !== false;
        const chart = ctx.chart;
        const series = ctx.series;
        const chartWrapperEl = ctx.chartWrapperEl;
        const data = Array.isArray(ctx.candleData) ? ctx.candleData : [];
        const barSec = (Number.isFinite(Number(ctx.barSec)) && Number(ctx.barSec) > 0)
            ? Number(ctx.barSec)
            : 60;
        const targetTradeId = ctx.targetTradeId != null ? Number(ctx.targetTradeId) : null;
        const getFrozen = typeof ctx.getFrozenExpiryParams === 'function'
            ? ctx.getFrozenExpiryParams
            : () => null;
        const rawDurations = Array.isArray(ctx.durations) ? ctx.durations : [];

        if (!show) {
            overlay.classList.add('hidden');
            overlay.innerHTML = '';
            return;
        }
        if (!chart || !series || !data.length) {
            overlay.classList.add('hidden');
            overlay.innerHTML = '';
            return;
        }

        const ts = chart.timeScale();
        const range = ts.getVisibleLogicalRange();
        let visibleFromTs = Number(data[0].time);
        // visibleToTs — open правого видимого бара; entry внутри этого бара иначе отфильтровывается.
        let visibleToTs = Number(data[data.length - 1].time);
        if (range) {
            const leftIdx = Math.max(0, Math.floor(Math.max(0, range.from)));
            const rightIdx = Math.min(data.length - 1, Math.ceil(Math.max(0, range.to)));
            if (data[leftIdx]) visibleFromTs = Number(data[leftIdx].time);
            if (data[rightIdx]) visibleToTs = Number(data[rightIdx].time);
        }
        const visibleToEndTs = visibleToTs + barSec;

        const durations = rawDurations
            .filter((d) => d.expiryTs >= visibleFromTs && d.entryTs <= visibleToEndTs)
            .sort((a, b) => a.entryTs - b.entryTs || a.expiryTs - b.expiryTs)
            .map((d) => {
                const frozen = getFrozen(d.tradeId);
                if (frozen && (d.result === 'win' || d.result === 'loss' || d.result === 'draw')) {
                    return { ...d, expiryPrice: frozen.price };
                }
                return d;
            });

        const MAX_VISIBLE_DURATION_ROWS = 280;
        const activeCount = durations.filter((d) => d.source === 'active').length;
        const maxTotal = Math.max(activeCount, MAX_VISIBLE_DURATION_ROWS);
        const trimmedDurations = durations.length > maxTotal ? durations.slice(durations.length - maxTotal) : durations;

        if (!trimmedDurations.length) {
            overlay.classList.add('hidden');
            overlay.innerHTML = '';
            return;
        }

        function approxPriceByTime(unixTs) {
            if (!data.length || !Number.isFinite(unixTs)) return null;
            const idx = data.findIndex((c) => Number(c.time) >= unixTs);
            const c = idx >= 0 ? data[idx] : data[data.length - 1];
            const p = Number(c && c.close);
            return Number.isFinite(p) ? p : null;
        }

        function resolveDurationX(startTs, endTs) {
            const x1 = interpolatedTimeToX(ts, startTs, data, barSec);
            const x2 = interpolatedTimeToX(ts, endTs, data, barSec);
            if (x1 == null || x2 == null || Number.isNaN(x1) || Number.isNaN(x2)) return null;
            return { x1, x2 };
        }

        /** Стрелка/подпись сделки на DOM — те же x1/x2, что у полоски (не LWC bar snap). */
        function appendTradeMarkerEl(parent, leftPx, topPx, cssExtra) {
            const el = document.createElement('div');
            el.style.position = 'absolute';
            el.style.left = `${Math.round(leftPx)}px`;
            el.style.top = `${Math.round(topPx)}px`;
            el.style.pointerEvents = 'none';
            el.style.zIndex = '3';
            if (cssExtra) {
                cssExtra.split(';').forEach((rule) => {
                    const idx = rule.indexOf(':');
                    if (idx <= 0) return;
                    const prop = rule.slice(0, idx).trim();
                    const val = rule.slice(idx + 1).trim();
                    if (prop) el.style.setProperty(prop, val);
                });
            }
            parent.appendChild(el);
            return el;
        }

        const chartWrapper = chartWrapperEl || overlay.parentElement;
        const lanesByY = new Map();
        overlay.innerHTML = '';
        // DOM-слой сделок только рисует маркеры: события мыши должны доходить до canvas LWC,
        // иначе subscribeCrosshairMove получает point=null и HTML-кроссхейр пропадает.
        // Клик по сделке обрабатывается через chart.subscribeClick + resolveTradePickAtChartPoint.
        overlay.style.pointerEvents = 'none';

        // Единый цвет всех полосок entry→expiry (1px): ярко-синий, без разделения CALL/PUT/win/loss.
        const TRADE_DURATION_BAR_COLOR = '#0088ff';

        trimmedDurations.forEach((d) => {
            const pos = resolveDurationX(d.entryTs, d.expiryTs);
            if (!pos) return;
            const { x1, x2 } = pos;
            const left = Math.min(x1, x2);
            const width = Math.max(8, Math.abs(x2 - x1));

            const basePrice = Number.isFinite(d.entryPrice) ? d.entryPrice : approxPriceByTime(d.entryTs);
            const y = basePrice != null ? series.priceToCoordinate(basePrice) : null;
            if (y == null || Number.isNaN(y)) return;
            // Полоска entry→expiry — ровно 1px по высоте, центр на уровне цены входа.
            const barH = 1;
            let top = Math.max(1, Math.min((chartWrapper.clientHeight || 0) - 2, Math.round(y - barH / 2)));

            const yBucket = Math.round(top / 4);
            const laneEnd = lanesByY.get(yBucket);
            if (laneEnd != null && left < laneEnd + 2) {
                top = Math.max(1, top - 4);
            }
            lanesByY.set(Math.round(top / 4), left + width);

            const isActive = d.source === 'active';
            // border на 1px-линии визually утолщает её до 3px — оставляем только fill + лёгкую тень.
            const div = document.createElement('div');
            div.style.position = 'absolute';
            div.style.left = `${left}px`;
            div.style.top = `${top}px`;
            div.style.width = `${width}px`;
            div.style.height = `${barH}px`;
            div.style.borderRadius = '0';
            div.style.background = TRADE_DURATION_BAR_COLOR;
            div.style.pointerEvents = 'none';
            overlay.appendChild(div);

            // Тики начала/конца — узкие, не выше самой полоски + 2px для читаемости.
            const tickH = 3;
            const tickTop = Math.max(0, top - Math.floor((tickH - barH) / 2));
            const mkStart = document.createElement('div');
            mkStart.style.position = 'absolute';
            mkStart.style.left = `${Math.max(0, left - 1)}px`;
            mkStart.style.top = `${tickTop}px`;
            mkStart.style.width = '1px';
            mkStart.style.height = `${tickH}px`;
            mkStart.style.background = TRADE_DURATION_BAR_COLOR;
            mkStart.style.pointerEvents = 'none';
            overlay.appendChild(mkStart);

            const mkEnd = document.createElement('div');
            mkEnd.style.position = 'absolute';
            mkEnd.style.left = `${Math.max(0, left + width - 1)}px`;
            mkEnd.style.top = `${tickTop}px`;
            mkEnd.style.width = '1px';
            mkEnd.style.height = `${tickH}px`;
            mkEnd.style.background = TRADE_DURATION_BAR_COLOR;
            mkEnd.style.pointerEvents = 'none';
            overlay.appendChild(mkEnd);

            const isCall = (d.side || '').toUpperCase() === 'CALL';
            const isTarget = targetTradeId !== null && Number.isFinite(d.tradeId) && d.tradeId === targetTradeId;
            const tradeIdSuffix = Number.isFinite(d.tradeId) ? ` ${d.tradeId}` : '';
            const entryPriceSuffix = markerPriceSuffix(d.entryPrice);
            const timeSuffixes = `${formatMarkerEntryTimeSuffix(d.entryTs)}${formatMarkerDurationSuffix(d.entryTs, d.expiryTs)}`;
            const result = String(d.result || 'pending').toLowerCase();
            const isSettled = result === 'win' || result === 'loss' || result === 'draw';

            // --- Вход: стрелка ровно на x1 (начало полоски) ---
            const entryColor = isTarget
                ? '#f59e0b'
                : (isActive ? (isCall ? '#10b981' : '#f43f5e') : '#ffffff');
            const entryArrowTop = isCall ? top + 4 : top - 12;
            const entryArrow = appendTradeMarkerEl(
                overlay,
                x1 - 5,
                entryArrowTop,
                `font-size:10px;line-height:1;color:${entryColor};text-shadow:0 1px 2px rgba(0,0,0,0.85)`,
            );
            entryArrow.textContent = isCall ? '▲' : '▼';

            if (isActive) {
                const openLbl = appendTradeMarkerEl(
                    overlay,
                    x1 + 4,
                    Math.max(0, top - 12),
                    'font-size:10px;line-height:1;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;color:#0088ff',
                );
                openLbl.textContent = `● OPEN ${(d.side || '').toUpperCase()}${tradeIdSuffix}${entryPriceSuffix}${timeSuffixes}`;
            } else {
                const entryLbl = appendTradeMarkerEl(
                    overlay,
                    x1 + 4,
                    isCall ? top + 14 : Math.max(0, top - 22),
                    `font-size:10px;line-height:1;white-space:nowrap;color:${entryColor};text-shadow:0 1px 2px rgba(0,0,0,0.85)`,
                );
                entryLbl.textContent = isTarget
                    ? `TARGET${tradeIdSuffix}${timeSuffixes}`
                    : (isCall
                        ? `ВВЕРХ${tradeIdSuffix}${entryPriceSuffix}${timeSuffixes}`
                        : `ВНИЗ${tradeIdSuffix}${entryPriceSuffix}${timeSuffixes}`);
            }

            // --- Экспирация: маркер на x2 (конец полоски) ---
            const expMarkerColor = isTarget
                ? '#f59e0b'
                : (result === 'win' ? '#10b981' : (result === 'loss' ? '#f43f5e' : '#a1a1aa'));
            const expMarkerTop = isCall ? top + 4 : top - 12;
            if (isSettled) {
                const frozen = getFrozen(d.tradeId);
                const expiryPriceRaw = frozen ? frozen.price : d.expiryPrice;
                const expiryPriceSuffix = markerPriceSuffix(expiryPriceRaw);
                let resultLabel = `EXP ${String(d.result || '').toUpperCase()}`.trim();
                if (result === 'win') resultLabel = `WIN${tradeIdSuffix}${expiryPriceSuffix}${timeSuffixes}`;
                if (result === 'loss') resultLabel = `LOSE${tradeIdSuffix}${expiryPriceSuffix}${timeSuffixes}`;

                // Горизонтальная черта цены экспирации (как раньше) + вертикальная по центру на x2 («+»).
                const frozenExp = getFrozen(d.tradeId);
                const expLevel = frozenExp
                    ? frozenExp.price
                    : (Number.isFinite(Number(d.expiryPrice)) ? Number(d.expiryPrice) : null);
                let markerY = y;
                if (Number.isFinite(expLevel)) {
                    const yExp = series.priceToCoordinate(expLevel);
                    if (yExp != null && !Number.isNaN(yExp)) {
                        markerY = yExp;
                        const bs = (typeof ts.barSpacing === 'function') ? Number(ts.barSpacing()) : 8;
                        const candleW = Math.max(16, Math.min(20, bs * 0.95));
                        const expLeft = Math.max(0, Math.min((chartWrapper.clientWidth || 0) - candleW, x2 - candleW / 2));
                        const chartHeight = chartWrapper.clientHeight || 0;
                        if (yExp >= 0 && yExp <= chartHeight) {
                            const expTop = Math.max(0, Math.min(chartHeight - 1, Math.round(yExp - 0.5)));
                            const expMarkH = document.createElement('div');
                            expMarkH.style.position = 'absolute';
                            expMarkH.style.left = `${expLeft}px`;
                            expMarkH.style.top = `${expTop}px`;
                            expMarkH.style.width = `${candleW}px`;
                            expMarkH.style.height = '1px';
                            expMarkH.style.borderRadius = '0';
                            expMarkH.style.background = TRADE_DURATION_BAR_COLOR;
                            expMarkH.style.pointerEvents = 'none';
                            expMarkH.style.zIndex = '2';
                            overlay.appendChild(expMarkH);

                            const expTickH = 7;
                            const expTickTop = Math.max(0, Math.min(chartHeight - expTickH, Math.round(yExp - expTickH / 2)));
                            const expMarkV = document.createElement('div');
                            expMarkV.style.position = 'absolute';
                            expMarkV.style.left = `${Math.round(x2)}px`;
                            expMarkV.style.top = `${expTickTop}px`;
                            expMarkV.style.width = '1px';
                            expMarkV.style.height = `${expTickH}px`;
                            expMarkV.style.background = TRADE_DURATION_BAR_COLOR;
                            expMarkV.style.pointerEvents = 'none';
                            expMarkV.style.zIndex = '2';
                            overlay.appendChild(expMarkV);
                        }
                    }
                }

                const expLbl = appendTradeMarkerEl(
                    overlay,
                    x2 + 5,
                    isCall ? expMarkerTop + 8 : Math.max(0, Math.round(markerY) - 14),
                    `font-size:10px;line-height:1;white-space:nowrap;color:${expMarkerColor};text-shadow:0 1px 2px rgba(0,0,0,0.85)`,
                );
                expLbl.textContent = isTarget ? `TARGET ${resultLabel}` : resultLabel;
            } else if (isActive) {
                const dot = appendTradeMarkerEl(
                    overlay,
                    x2 - 3,
                    expMarkerTop,
                    `width:6px;height:6px;border-radius:999px;background:${expMarkerColor};box-shadow:0 0 0 1px rgba(0,0,0,0.5)`,
                );
                void dot;
            }

        });

        overlay.classList.remove('hidden');
    }

    /**
     * Hit-test клика по canvas графика: X внутри [entry, expiry], Y рядом с ценой входа.
     * Возвращает { tradeId, botId } или null.
     */
    function resolveTradePickAtChartPoint(ctx) {
        const chart = ctx && ctx.chart;
        const series = ctx && ctx.series;
        const data = Array.isArray(ctx && ctx.candleData) ? ctx.candleData : [];
        const durations = Array.isArray(ctx && ctx.durations) ? ctx.durations : [];
        const x = Number(ctx && ctx.x);
        const y = Number(ctx && ctx.y);
        const barSec = (Number.isFinite(Number(ctx && ctx.barSec)) && Number(ctx.barSec) > 0)
            ? Number(ctx.barSec)
            : 60;
        if (!chart || !series || !data.length || !durations.length || !Number.isFinite(x)) {
            return null;
        }
        const ts = chart.timeScale();
        const hitXPad = 6;
        const hitYPx = 14;

        for (let i = durations.length - 1; i >= 0; i -= 1) {
            const d = durations[i];
            const tid = Number(d && d.tradeId);
            if (!Number.isFinite(tid) || tid <= 0) continue;
            const x1 = interpolatedTimeToX(ts, Number(d.entryTs), data, barSec);
            const x2 = interpolatedTimeToX(ts, Number(d.expiryTs), data, barSec);
            if (x1 == null || x2 == null || Number.isNaN(x1) || Number.isNaN(x2)) continue;
            const left = Math.min(x1, x2) - hitXPad;
            const right = Math.max(x1, x2) + hitXPad;
            if (x < left || x > right) continue;
            if (Number.isFinite(y)) {
                let basePrice = Number(d.entryPrice);
                if (!Number.isFinite(basePrice)) {
                    const idx = data.findIndex((c) => Number(c.time) >= Number(d.entryTs));
                    const c = idx >= 0 ? data[idx] : data[data.length - 1];
                    basePrice = Number(c && c.close);
                }
                if (Number.isFinite(basePrice)) {
                    const yLine = series.priceToCoordinate(basePrice);
                    if (yLine != null && !Number.isNaN(yLine) && Math.abs(y - yLine) > hitYPx) {
                        continue;
                    }
                }
            }
            const botIdRaw = Number(d.botId);
            return {
                tradeId: tid,
                botId: (Number.isFinite(botIdRaw) && botIdRaw > 0) ? botIdRaw : null,
            };
        }
        return null;
    }

    let timeRulerStylesInjected = false;
    function ensureTimeRulerStyles() {
        if (timeRulerStylesInjected || typeof document === 'undefined') return;
        timeRulerStylesInjected = true;
        const style = document.createElement('style');
        style.id = 'platform-time-ruler-styles';
        style.textContent = [
            '.time-ruler-grid-line{position:absolute;top:0;width:1px;transform:translateX(-0.5px);',
            'background:rgba(63,63,70,0.32);pointer-events:none}',
            '.time-ruler-tick{position:absolute;width:1px;height:6px;transform:translateX(-0.5px);',
            'background:rgba(148,163,184,0.72);pointer-events:none}',
            '.time-ruler-tick-major{height:10px;background:rgba(203,213,225,0.92)}',
            '.time-ruler-minute-tick{height:12px;width:1px;background:rgba(228,228,231,0.95)}',
            '.time-ruler-minute-label{position:absolute;font-size:10px;line-height:1;color:#f4f4f5;',
            'font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;',
            'font-variant-numeric:tabular-nums;pointer-events:none;transform:translateX(-50%);',
            'white-space:nowrap;text-align:center}',
            '.time-ruler-label{position:absolute;font-size:9px;line-height:1;color:rgba(161,161,170,0.92);',
            'font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;',
            'font-variant-numeric:tabular-nums;pointer-events:none;transform:translateX(-50%);',
            'min-width:14px;text-align:center}',
        ].join('');
        document.head.appendChild(style);
    }

    /** Шаг делений внутри одного бара (M1: секунды между минутными метками). */
    function pickIntraBarSubStepSec(barSec, barWidthPx) {
        if (!(Number.isFinite(barWidthPx) && barWidthPx >= 5)) return 0;
        if (barSec <= 60) {
            if (barWidthPx >= 50) return 5;
            if (barWidthPx >= 28) return 10;
            if (barWidthPx >= 14) return 15;
            return 30;
        }
        const pxPerMin = barWidthPx / Math.max(1, barSec / 60);
        if (pxPerMin >= 14) return 60;
        if (pxPerMin >= 8) return 120;
        if (pxPerMin >= 4) return 300;
        return 0;
    }

    /** Шаг делений для TF < 1 мин (S5/S15/S30). */
    function pickTimeRulerStepSec(pxPerSec) {
        if (pxPerSec >= 8) return 1;
        if (pxPerSec >= 4) return 2;
        if (pxPerSec >= 2) return 5;
        if (pxPerSec >= 1) return 10;
        if (pxPerSec >= 0.5) return 15;
        return 30;
    }

    /** Деления внутри каждого видимого бара — линейка между минутами на M1. */
    function appendIntraBarTimeRulerTicks(ts, data, barSec, plotW, plotH, frag) {
        const range = ts.getVisibleLogicalRange();
        if (!range || !data.length) return;
        const spacing = (typeof ts.barSpacing === 'function') ? Number(ts.barSpacing()) : 8;
        const leftIdx = Math.max(0, Math.floor(range.from) - 1);
        const rightIdx = Math.min(data.length - 1, Math.ceil(range.to) + 1);
        const p2 = (n) => String(n).padStart(2, '0');
        const axisTop = plotH;
        const formatMinute = (unixSec) => formatWallClockHm(unixSec);

        // При сильном zoom-out HTML-подписи наслаиваются на нативную ось LWC — оставляем только штрихи.
        const showMinuteLabels = spacing >= 16;
        let minuteLabelStep = 1;
        if (spacing < 16) {
            minuteLabelStep = 0;
        } else if (spacing < 24) {
            minuteLabelStep = 5;
        } else if (spacing < 40) {
            minuteLabelStep = 2;
        }
        const minLabelGapPx = 48;
        let lastMinuteLabelX = -9999;

        for (let i = leftIdx; i <= rightIdx; i += 1) {
            const t0 = Number(data[i].time);
            const x0 = interpolatedTimeToX(ts, t0, data, barSec);
            if (x0 == null || Number.isNaN(x0)) continue;
            let x1;
            if (i + 1 < data.length) {
                x1 = interpolatedTimeToX(ts, Number(data[i + 1].time), data, barSec);
            } else {
                x1 = x0 + spacing;
            }
            if (x1 == null || Number.isNaN(x1)) x1 = x0 + spacing;
            const barW = x1 - x0;

            // Метка минуты на границе бара (open) — основные деления линейки.
            const xi0 = Math.round(x0);
            if (xi0 >= -1 && xi0 <= plotW + 1) {
                const minuteTick = document.createElement('div');
                minuteTick.className = 'time-ruler-tick time-ruler-minute-tick';
                minuteTick.style.left = `${xi0}px`;
                minuteTick.style.top = `${axisTop - 12}px`;
                frag.appendChild(minuteTick);

                if (showMinuteLabels && minuteLabelStep > 0 && (i - leftIdx) % minuteLabelStep === 0) {
                    if (xi0 - lastMinuteLabelX >= minLabelGapPx) {
                        const minuteLabel = document.createElement('span');
                        minuteLabel.className = 'time-ruler-minute-label';
                        minuteLabel.style.left = `${xi0}px`;
                        minuteLabel.style.top = `${axisTop + 1}px`;
                        minuteLabel.textContent = formatMinute(t0);
                        frag.appendChild(minuteLabel);
                        lastMinuteLabelX = xi0;
                    }
                }
            }

            const subStep = pickIntraBarSubStepSec(barSec, barW);
            if (!subStep) continue;

            const showLabels = barSec <= 60 && barW >= 10 && subStep <= 15;
            const labelEvery = subStep <= 5 ? 10 : subStep;

            for (let off = subStep; off < barSec; off += subStep) {
                const x = x0 + (off / barSec) * barW;
                if (x < -1 || x > plotW + 1) continue;
                const xi = Math.round(x);
                const isMajor = off % 30 === 0;
                const tick = document.createElement('div');
                tick.className = isMajor ? 'time-ruler-tick time-ruler-tick-major' : 'time-ruler-tick';
                tick.style.left = `${xi}px`;
                tick.style.top = `${axisTop - (isMajor ? 9 : 5)}px`;
                frag.appendChild(tick);

                if (showLabels && barSec <= 60 && off % labelEvery === 0) {
                    const secInMin = off % 60;
                    if (secInMin === 0) continue;
                    const label = document.createElement('span');
                    label.className = 'time-ruler-label';
                    label.style.left = `${xi}px`;
                    label.style.top = `${axisTop + 1}px`;
                    label.textContent = p2(secInMin);
                    frag.appendChild(label);
                }
            }
        }
    }

    /**
     * Линейка секунд на оси времени: деления + подписи :SS.
     * ctx: overlayEl, chart, candleData[], barSec, plotW, plotH, timeH, show?
     */
    function renderTimeRulerOverlay(ctx) {
        const overlay = ctx && ctx.overlayEl;
        if (!overlay) return;
        ensureTimeRulerStyles();
        const show = ctx.show !== false;
        if (!show || !ctx.chart || !Array.isArray(ctx.candleData) || !ctx.candleData.length) {
            overlay.classList.add('hidden');
            overlay.replaceChildren();
            return;
        }

        const chart = ctx.chart;
        const ts = chart.timeScale();
        const data = ctx.candleData;
        const barSec = (Number.isFinite(Number(ctx.barSec)) && Number(ctx.barSec) > 0)
            ? Number(ctx.barSec)
            : 60;
        const plotW = Number(ctx.plotW);
        const plotH = Number(ctx.plotH);
        if (!Number.isFinite(plotW) || plotW <= 0 || !Number.isFinite(plotH) || plotH <= 0) {
            overlay.classList.add('hidden');
            overlay.replaceChildren();
            return;
        }

        const frag = document.createDocumentFragment();

        // M1+: деления внутри каждого минутного столбца (не ждём экстремального zoom).
        if (barSec >= 60) {
            const spacing = (typeof ts.barSpacing === 'function') ? Number(ts.barSpacing()) : 8;
            // Слишком сильный zoom-out: HTML-линейка дублирует нативную ось и даёт «кашу».
            if (!Number.isFinite(spacing) || spacing < 6) {
                overlay.classList.add('hidden');
                overlay.replaceChildren();
                return;
            }
            appendIntraBarTimeRulerTicks(ts, data, barSec, plotW, plotH, frag);
            overlay.replaceChildren(frag);
            overlay.classList.remove('hidden');
            return;
        }

        let tStart = null;
        let tEnd = null;
        try {
            if (typeof ts.coordinateToTime === 'function') {
                tStart = toUnixSec(ts.coordinateToTime(0));
                tEnd = toUnixSec(ts.coordinateToTime(Math.max(0, plotW - 1)));
            }
        } catch (_) {}
        if (!Number.isFinite(tStart) || !Number.isFinite(tEnd)) {
            const range = ts.getVisibleLogicalRange();
            if (range && data.length) {
                const leftIdx = Math.max(0, Math.floor(range.from));
                const rightIdx = Math.min(data.length - 1, Math.ceil(range.to));
                tStart = Number(data[leftIdx].time) + (range.from - leftIdx) * barSec;
                tEnd = Number(data[rightIdx].time) + (range.to - rightIdx) * barSec;
            }
        }
        if (!Number.isFinite(tStart) || !Number.isFinite(tEnd)) {
            overlay.classList.add('hidden');
            overlay.replaceChildren();
            return;
        }
        if (tEnd < tStart) {
            const tmp = tStart;
            tStart = tEnd;
            tEnd = tmp;
        }

        const spacing = (typeof ts.barSpacing === 'function') ? Number(ts.barSpacing()) : 8;
        const pxPerSec = (Number.isFinite(spacing) && spacing > 0 && barSec > 0)
            ? spacing / barSec
            : 0.1;
        let stepSec = pickTimeRulerStepSec(pxPerSec);
        const spanSec = Math.max(1, Math.ceil(tEnd) - Math.floor(tStart));
        const MAX_TICKS = 420;
        if (spanSec / stepSec > MAX_TICKS) {
            stepSec = Math.max(stepSec, Math.ceil(spanSec / MAX_TICKS));
        }

        const lastSec = Math.ceil(tEnd);
        let alignedStart = Math.ceil(Math.floor(tStart) / stepSec) * stepSec;
        if (alignedStart < Math.floor(tStart)) alignedStart += stepSec;

        const showLabels = stepSec <= 5 && pxPerSec >= 2;
        const labelEvery = stepSec === 1 ? 5 : stepSec;
        const showGrid = stepSec <= 2 && pxPerSec >= 5;
        const p2 = (n) => String(n).padStart(2, '0');

        for (let sec = alignedStart; sec <= lastSec; sec += stepSec) {
            const x = interpolatedTimeToX(ts, sec, data, barSec);
            if (x == null || Number.isNaN(x) || x < -1 || x > plotW + 1) continue;

            const xi = Math.round(x);
            const secInMin = sec % 60;
            const isMinute = secInMin === 0;
            const isMajor = isMinute || sec % 10 === 0 || stepSec >= 15;

            if (showGrid) {
                const grid = document.createElement('div');
                grid.className = 'time-ruler-grid-line';
                grid.style.left = `${xi}px`;
                grid.style.height = `${plotH}px`;
                frag.appendChild(grid);
            }

            const tick = document.createElement('div');
            tick.className = isMajor ? 'time-ruler-tick time-ruler-tick-major' : 'time-ruler-tick';
            tick.style.left = `${xi}px`;
            tick.style.top = `${plotH - (isMajor ? 9 : 5)}px`;
            frag.appendChild(tick);

            if (showLabels && !isMinute && sec % labelEvery === 0) {
                const label = document.createElement('span');
                label.className = 'time-ruler-label';
                label.style.left = `${xi}px`;
                label.style.top = `${plotH + 1}px`;
                label.textContent = p2(secInMin);
                frag.appendChild(label);
            }
        }

        overlay.replaceChildren(frag);
        overlay.classList.remove('hidden');
    }

    global.PlatformChartMarkers = {
        formatMarkerPrice,
        markerPriceSuffix,
        formatMarkerEntryTimeSuffix,
        formatMarkerDurationSuffix,
        toUnixSec,
        toBucketTime,
        toClosedBarTime,
        interpolatedTimeToX,
        interpolateTimeAtX,
        resolveCrosshairUnixTime,
        buildHistoryMarkers,
        buildOpenTradeMarkers,
        mergeMarkers,
        createPluginForSeries,
        applyMarkers,
        detachPlugin,
        createPaneSolidBackdrop,
        attachRsiLevelLines,
        renderIndicatorNameLabels,
        renderPoOpenTradeRays,
        buildTradeDurationsFromSignals,
        renderTradeDurationOverlay,
        resolveTradePickAtChartPoint,
        renderTimeRulerOverlay,
        formatWallClockHms,
        formatWallClockHm,
        buildChartTimezoneOptions(timeScaleBase) {
            const TF = appTimeFormat();
            if (TF && typeof TF.buildChartTimezoneOptions === 'function') {
                return TF.buildChartTimezoneOptions(timeScaleBase);
            }
            return { timeScale: timeScaleBase || {} };
        },
    };
})(window);
