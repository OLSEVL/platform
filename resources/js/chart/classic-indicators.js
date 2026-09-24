/**
 * Классика OsEngine на клиенте (SMA/EMA/RSI/BB/ATR/MACD/VWMA/Stochastic).
 * Зеркало PHP Services\Indicators\* + OsEngineCandleMath.
 * window.PlatformClassicIndicators.computeAll(rows, wants, paramsByKey)
 */
(function attachPlatformClassicIndicators(global) {
    if (global.PlatformClassicIndicators) return;

    const CANDLE_POINTS = ['Open', 'High', 'Low', 'Close', 'Median', 'Typical'];

    function candlePoint(candle, point) {
        const o = Number(candle.open) || 0;
        const h = Number(candle.high) || 0;
        const l = Number(candle.low) || 0;
        const c = Number(candle.close) || 0;
        if (point === 'Open') return o;
        if (point === 'High') return h;
        if (point === 'Low') return l;
        if (point === 'Median') return (h + l) / 2;
        if (point === 'Typical') return (h + l + c) / 3;
        return c;
    }

    function packSeries(rows, values, skipZeros) {
        const skip = skipZeros !== false;
        const out = [];
        const n = Math.min(rows.length, values.length);
        for (let i = 0; i < n; i++) {
            const v = values[i];
            if (v == null) continue;
            const fv = Number(v);
            if (skip && fv === 0) continue;
            out.push({ time: Number(rows[i].time) | 0, value: fv });
        }
        return out;
    }

    function smaAt(rows, index, length, point) {
        if (length <= 0 || index < length - 1 || index >= rows.length) return 0;
        let sum = 0;
        const from = index - length + 1;
        for (let i = from; i <= index; i++) sum += candlePoint(rows[i], point);
        return sum / length;
    }

    function roundMarket(v) {
        // Достаточно 8 знаков для UI (PriceScale::roundMarket на сервере).
        return Math.round(Number(v) * 1e8) / 1e8;
    }

    function merge(defaults, params) {
        return Object.assign({}, defaults, params || {});
    }

    // --- SMA ---
    const SMA_DEFAULTS = {
        length: 14, candle_point: 'Close', show_line: true, color: '#1e90ff', line_width: 1,
    };
    function computeSma(rows, params) {
        const p = merge(SMA_DEFAULTS, params);
        const length = Math.max(1, Number(p.length) | 0);
        let point = String(p.candle_point || 'Close');
        if (CANDLE_POINTS.indexOf(point) < 0) point = 'Close';
        const n = rows.length;
        const raw = new Array(n).fill(0);
        for (let index = 0; index < n; index++) {
            if (length > index) continue;
            raw[index] = smaAt(rows, index, length, point);
        }
        const series = {};
        if (p.show_line) series.ma = packSeries(rows, raw);
        return {
            series,
            style: { color: String(p.color), line_width: Math.max(1, Number(p.line_width) | 0) },
        };
    }

    // --- EMA ---
    const EMA_DEFAULTS = {
        length: 14, candle_point: 'Close', show_line: true, color: '#8b0000', line_width: 1,
    };
    function computeEmaSeries(rows, params) {
        const p = merge(EMA_DEFAULTS, params);
        const length = Math.max(1, Number(p.length) | 0);
        let point = String(p.candle_point || 'Close');
        if (CANDLE_POINTS.indexOf(point) < 0) point = 'Close';
        const n = rows.length;
        const values = new Array(n).fill(0);
        for (let index = 0; index < n; index++) {
            let result = 0;
            if (index === length) {
                let lastMoving = 0;
                for (let i = index - length + 1; i <= index; i++) {
                    lastMoving += candlePoint(rows[i], point);
                }
                result = lastMoving / length;
            } else if (index > length) {
                const a = Math.round((2 / (length + 1)) * 1e8) / 1e8;
                const emaLast = values[index - 1];
                const price = candlePoint(rows[index], point);
                result = emaLast + a * (price - emaLast);
            }
            values[index] = Math.round(result * 1e8) / 1e8;
        }
        return values;
    }
    function computeEma(rows, params) {
        const p = merge(EMA_DEFAULTS, params);
        const raw = computeEmaSeries(rows, p);
        const series = {};
        if (p.show_line) series.ema = packSeries(rows, raw);
        return {
            series,
            style: { color: String(p.color), line_width: Math.max(1, Number(p.line_width) | 0) },
        };
    }

    // --- VWMA ---
    const VWMA_DEFAULTS = {
        length: 14, candle_point: 'Close', show_line: true, color: '#00ffff', line_width: 1,
    };
    function computeVwma(rows, params) {
        const p = merge(VWMA_DEFAULTS, params);
        const length = Math.max(1, Number(p.length) | 0);
        let point = String(p.candle_point || 'Close');
        if (CANDLE_POINTS.indexOf(point) < 0) point = 'Close';
        const n = rows.length;
        const raw = new Array(n).fill(0);
        for (let index = 0; index < n; index++) {
            if (length > index) continue;
            let average = 0;
            let weights = 0;
            for (let i = index; i > index - length; i--) {
                const vol = Number(rows[i].volume) || 0;
                average += candlePoint(rows[i], point) * vol;
                weights += vol;
            }
            if (weights !== 0) average /= weights;
            raw[index] = average;
        }
        const series = {};
        if (p.show_line) series.vwma = packSeries(rows, raw);
        return {
            series,
            style: { color: String(p.color), line_width: Math.max(1, Number(p.line_width) | 0) },
        };
    }

    // --- ATR ---
    const ATR_DEFAULTS = {
        length: 14, series_type: 'Absolute', show_line: true, color: '#1e90ff', line_width: 1,
    };
    function movingAverageWild(valuesSeries, moving, length, index) {
        if (index < length - 1) {
            moving[index] = 0;
            return;
        }
        if (index === length - 1) {
            let sum = 0;
            for (let i = 0; i <= index; i++) sum += valuesSeries[i];
            moving[index] = sum / length;
            return;
        }
        const lastValueSeries = Math.round(valuesSeries[index] * 1e9) / 1e9;
        const lastValueMoving = moving[index - 1];
        moving[index] = Math.round(((lastValueMoving * (length - 1) + lastValueSeries) / length) * 1e9) / 1e9;
    }
    function computeAtr(rows, params) {
        const p = merge(ATR_DEFAULTS, params);
        const length = Math.max(1, Number(p.length) | 0);
        const percent = String(p.series_type) === 'Percent';
        const n = rows.length;
        const trueRange = new Array(n).fill(0);
        const moving = new Array(n).fill(0);
        const values = new Array(n).fill(0);
        for (let index = 0; index < n; index++) {
            if (index === 0) {
                trueRange[0] = 0;
            } else {
                const hiToLow = Math.abs(Number(rows[index].high) - Number(rows[index].low));
                const closeToHigh = Math.abs(Number(rows[index - 1].close) - Number(rows[index].high));
                const closeToLow = Math.abs(Number(rows[index - 1].close) - Number(rows[index].low));
                let tr = Math.max(hiToLow, closeToHigh, closeToLow);
                if (percent && tr !== 0) {
                    const prevOpen = Number(rows[index - 1].open);
                    if (prevOpen !== 0) tr = tr / (prevOpen / 100);
                }
                trueRange[index] = tr;
            }
            movingAverageWild(trueRange, moving, length, index);
            values[index] = Math.round(moving[index] * 1e9) / 1e9;
        }
        const series = {};
        if (p.show_line) series.atr = packSeries(rows, values);
        return {
            series,
            style: {
                color: String(p.color),
                line_width: Math.max(1, Number(p.line_width) | 0),
                series_type: String(p.series_type),
            },
        };
    }

    // --- Bollinger ---
    const BB_DEFAULTS = {
        length: 21, deviation: 2, show_up: true, show_mid: true, show_down: true,
        color_up: '#22c55e', color_mid: '#22c55e', color_down: '#22c55e', line_width: 1,
    };
    function computeBollinger(rows, params) {
        const p = merge(BB_DEFAULTS, params);
        const length = Math.max(1, Number(p.length) | 0);
        const deviation = Number(p.deviation);
        const n = rows.length;
        const mid = new Array(n).fill(0);
        const up = new Array(n).fill(0);
        const down = new Array(n).fill(0);
        for (let index = length + 1; index < n; index++) {
            const valueSma = smaAt(rows, index, length, 'Close');
            if (valueSma === 0) continue;
            mid[index] = valueSma;
            let sumSq = 0;
            const from = index - length + 1;
            for (let i = from; i <= index; i++) {
                const diff = Number(rows[i].close) - valueSma;
                sumSq += diff * diff;
            }
            const div = length > 30 ? length - 1 : length;
            const sigma = div > 0 ? Math.sqrt(sumSq / div) : 0;
            up[index] = roundMarket(valueSma + sigma * deviation);
            down[index] = roundMarket(valueSma - sigma * deviation);
        }
        const series = {};
        if (p.show_mid) series.mid = packSeries(rows, mid);
        if (p.show_up) series.up = packSeries(rows, up);
        if (p.show_down) series.down = packSeries(rows, down);
        return {
            series,
            style: {
                color_up: String(p.color_up),
                color_mid: String(p.color_mid),
                color_down: String(p.color_down),
                line_width: Math.max(1, Number(p.line_width) | 0),
            },
        };
    }

    // --- RSI ---
    const RSI_DEFAULTS = {
        length: 14, show_line: true, color: '#1e90ff', line_width: 1,
        level_overbought: 70, level_oversold: 30, show_levels: true,
    };
    function movingAverageHard(valuesSeries, moving, length, index) {
        if (index === length) {
            let lastMoving = 0;
            for (let i = index; i < valuesSeries.length && i > index - 1 - length; i--) {
                lastMoving += valuesSeries[i];
            }
            lastMoving /= length;
            while (moving.length <= index) moving.push(0);
            moving[index] = lastMoving;
        } else if (index > length) {
            const a = 2 / (length * 2);
            const lastValueMoving = moving[index - 1];
            const lastValueSeries = valuesSeries[index];
            const nowValueMoving = lastValueMoving + a * (lastValueSeries - lastValueMoving);
            while (moving.length <= index) moving.push(0);
            moving[index] = nowValueMoving;
        }
    }
    function computeRsi(rows, params) {
        const p = merge(RSI_DEFAULTS, params);
        const length = Math.max(1, Number(p.length) | 0);
        const n = rows.length;
        const values = new Array(n).fill(0);
        for (let index = 0; index < n; index++) {
            if (index - length - 1 <= 0) continue;
            let startIndex = 1;
            if (index > length) {
                startIndex = index - length - 20;
                if (startIndex < 1) startIndex = Math.max(1, index - length);
            }
            const priceChangeHigh = [];
            const priceChangeLow = [];
            const avgHigh = [];
            const avgLow = [];
            for (let i = startIndex, valueInd = 0; i <= index && i < n; i++, valueInd++) {
                const diff = Number(rows[i].close) - Number(rows[i - 1].close);
                if (diff > 0) {
                    priceChangeHigh.push(diff);
                    priceChangeLow.push(0);
                } else {
                    priceChangeLow.push(Number(rows[i - 1].close) - Number(rows[i].close));
                    priceChangeHigh.push(0);
                }
                movingAverageHard(priceChangeHigh, avgHigh, length, valueInd);
                movingAverageHard(priceChangeLow, avgLow, length, valueInd);
            }
            if (!avgHigh.length) continue;
            const averageHigh = avgHigh[avgHigh.length - 1];
            const averageLow = avgLow[avgLow.length - 1];
            let rsi = 100;
            if (averageHigh !== 0 && averageLow !== 0) {
                rsi = 100 * (1 - averageLow / (averageLow + averageHigh));
            }
            values[index] = roundMarket(rsi);
        }
        const series = {};
        if (p.show_line) series.rsi = packSeries(rows, values);
        return {
            series,
            style: { color: String(p.color), line_width: Math.max(1, Number(p.line_width) | 0) },
            levels: {
                overbought: Number(p.level_overbought),
                oversold: Number(p.level_oversold),
                show: !!p.show_levels,
            },
        };
    }

    // --- MACD ---
    const MACD_DEFAULTS = {
        length_fast: 12, length_slow: 26, length_signal: 9,
        show_macd: true, show_signal: true, show_histogram: true,
        color_macd: '#006400', color_signal: '#8b0000',
        color_histogram: '#1e90ff', color_histogram_neg: '#f43f5e', line_width: 1,
    };
    function processSignalLine(macdValues, signalValues, length, index) {
        let result = 0;
        if (index === length) {
            let lastMoving = 0;
            for (let i = index - length + 1; i <= index; i++) {
                lastMoving += Number(macdValues[i] || 0);
            }
            result = lastMoving / length;
        } else if (index > length) {
            const a = Math.round((2 / (length + 1)) * 1e8) / 1e8;
            const emaLast = Number(signalValues[index - 1] || 0);
            const pv = Number(macdValues[index] || 0);
            result = emaLast + a * (pv - emaLast);
        }
        return Math.round(result * 1e8) / 1e8;
    }
    function computeMacd(rows, params) {
        const p = merge(MACD_DEFAULTS, params);
        const fastLen = Math.max(1, Number(p.length_fast) | 0);
        const slowLen = Math.max(1, Number(p.length_slow) | 0);
        const signalLen = Math.max(1, Number(p.length_signal) | 0);
        const n = rows.length;
        const emaFast = computeEmaSeries(rows, { length: fastLen, candle_point: 'Close' });
        const emaSlow = computeEmaSeries(rows, { length: slowLen, candle_point: 'Close' });
        const macd = new Array(n).fill(null);
        const signal = new Array(n).fill(null);
        const histogram = new Array(n).fill(null);
        const warmup = Math.max(fastLen, slowLen);
        for (let index = 0; index < n; index++) {
            if (index < warmup) continue;
            const macdVal = Math.round((emaFast[index] - emaSlow[index]) * 1e8) / 1e8;
            macd[index] = macdVal;
            const signalVal = processSignalLine(macd, signal, signalLen, index);
            signal[index] = signalVal;
            histogram[index] = Math.round((macdVal - signalVal) * 1e8) / 1e8;
        }
        const series = {};
        if (p.show_macd) series.macd = packSeries(rows, macd, false);
        if (p.show_signal) series.signal = packSeries(rows, signal, false);
        if (p.show_histogram) series.histogram = packSeries(rows, histogram, false);
        return {
            series,
            style: {
                color_macd: String(p.color_macd),
                color_signal: String(p.color_signal),
                color_histogram: String(p.color_histogram),
                color_histogram_neg: String(p.color_histogram_neg),
                line_width: Math.max(1, Number(p.line_width) | 0),
            },
        };
    }

    // --- Stochastic ---
    const STOCH_DEFAULTS = {
        length: 14, length_smooth: 3, show_k: true, show_d: true,
        color_k: '#1e90ff', color_d: '#ff4500', line_width: 1,
        level_overbought: 80, level_oversold: 20, show_levels: true,
    };
    function computeStochastic(rows, params) {
        // Упрощённый порт: %K = 100*(C-L)/(H-L) по окну; %D = SMA(%K).
        const p = merge(STOCH_DEFAULTS, params);
        const length = Math.max(1, Number(p.length) | 0);
        const smooth = Math.max(1, Number(p.length_smooth) | 0);
        const n = rows.length;
        const kRaw = new Array(n).fill(0);
        for (let index = 0; index < n; index++) {
            if (index < length - 1) continue;
            let hh = -Infinity;
            let ll = Infinity;
            for (let i = index - length + 1; i <= index; i++) {
                hh = Math.max(hh, Number(rows[i].high));
                ll = Math.min(ll, Number(rows[i].low));
            }
            const den = hh - ll;
            kRaw[index] = den === 0 ? 50 : roundMarket(100 * (Number(rows[index].close) - ll) / den);
        }
        const dRaw = new Array(n).fill(0);
        for (let index = 0; index < n; index++) {
            if (index < length - 1 + smooth - 1) continue;
            let sum = 0;
            for (let i = index - smooth + 1; i <= index; i++) sum += kRaw[i];
            dRaw[index] = roundMarket(sum / smooth);
        }
        const series = {};
        if (p.show_k) series.k = packSeries(rows, kRaw);
        if (p.show_d) series.d = packSeries(rows, dRaw);
        return {
            series,
            style: {
                color_k: String(p.color_k),
                color_d: String(p.color_d),
                line_width: Math.max(1, Number(p.line_width) | 0),
            },
            levels: {
                overbought: Number(p.level_overbought),
                oversold: Number(p.level_oversold),
                show: !!p.show_levels,
            },
        };
    }

    /**
     * @param {object} wants — { sma, ema, rsi, bollinger, atr, macd, vwma, stochastic }
     * @param {object} paramsByKey — boot params per indicator key
     * @returns {object} payloads keyed like server JSON (sma, ema, …)
     */
    function computeAll(rows, wants, paramsByKey) {
        const p = paramsByKey || {};
        const out = {};
        if (!Array.isArray(rows) || !rows.length) return out;
        if (wants.sma) out.sma = computeSma(rows, p.sma);
        if (wants.ema) out.ema = computeEma(rows, p.ema);
        if (wants.vwma) out.vwma = computeVwma(rows, p.vwma);
        if (wants.atr) out.atr = computeAtr(rows, p.atr);
        if (wants.bollinger) out.bollinger = computeBollinger(rows, p.bollinger);
        if (wants.rsi) out.rsi = computeRsi(rows, p.rsi);
        if (wants.macd) out.macd = computeMacd(rows, p.macd);
        if (wants.stochastic) out.stochastic = computeStochastic(rows, p.stochastic);
        return out;
    }

    global.PlatformClassicIndicators = {
        computeAll,
        computeSma,
        computeEma,
        computeVwma,
        computeAtr,
        computeBollinger,
        computeRsi,
        computeMacd,
        computeStochastic,
        DEFAULTS: {
            sma: SMA_DEFAULTS,
            ema: EMA_DEFAULTS,
            vwma: VWMA_DEFAULTS,
            atr: ATR_DEFAULTS,
            bollinger: BB_DEFAULTS,
            rsi: RSI_DEFAULTS,
            macd: MACD_DEFAULTS,
            stochastic: STOCH_DEFAULTS,
        },
    };
})(typeof window !== 'undefined' ? window : globalThis);
