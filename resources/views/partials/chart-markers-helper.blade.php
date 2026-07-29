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
                title: String(Math.round(overbought)),
            }));
            out.push(series.createPriceLine({
                ...lineOpts,
                price: oversold,
                title: String(Math.round(oversold)),
            }));
        } catch (e) {
            console.error('[markers-helper] RSI level lines', e);
        }
        return out;
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
    };
})(window);
</script>
