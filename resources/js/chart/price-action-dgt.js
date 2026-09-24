/**
 * Price Action DGT — клиентский пересчёт (как TradingView).
 * Зеркало App\Services\Indicators\PriceActionDgtIndicatorService:
 * S/R, volume spike, ATR-волатильность, volume profile, supply/demand, VWCB и маркеры.
 * Вход: OHLC-ряды с графика (allChartData), без /indicators/data.
 *
 * Порт Pine "Price Action - Support & Resistance by DGT" (strategy1.txt).
 */
(function attachPlatformPriceActionDgt(global) {
    if (global.PlatformPriceActionDgt) return;

    const ID = 'tv.price_action_dgt';

    /** Дефолты совпадают с Pine-скриптом (strategy1.txt) / PHP defaultParams(). */
    const DEFAULT_PARAMS = {
        // sr_fixed_lookback_bars учитывается только если режим fixed
        // (строка содержит "fixed", напр. "fixed" или "Fixed Range").
        sr_lookback_range: 'visible',
        sr_fixed_lookback_bars: 360,
        sr_source: 'volume', // volume | price
        sr_lines: true,
        sr_line_color: '#4dd0e141',
        sr_line_width: 3,
        sr_line_style: 'Solid',

        v_spike_markers: true,
        v_spike_threshold: 4.669,
        v_spike_lines: true,
        v_spike_line_color: '#ffb74d69',
        v_spike_line_width: 3,
        v_spike_line_style: 'Solid',
        v_spike_bull_level: 'Both', // High | Close | Both
        v_spike_bear_level: 'Both',

        atr_markers: true,
        atr_length: 11,
        atr_mult: 2.718,
        atr_lines: true,
        atr_line_color: '#81c78455',
        atr_line_width: 3,
        atr_line_style: 'Solid',
        atr_bull_level: 'Both',
        atr_bear_level: 'Both',

        volume_sma_length: 89,

        volume_profile: true,
        // vp_fixed_lookback_bars только при режиме fixed (строка с подстрокой "fixed").
        vp_lookback_range: 'visible',
        vp_fixed_lookback_bars: 360,
        vp_profile_levels: 100,
        vp_horizontal_offset_bars: 13,
        vp_value_area_pct: 68,
        vp_vah: true,
        vp_vah_color: '#ffeb3b',
        vp_poc: true,
        vp_poc_color: '#ff0000',
        vp_val: true,
        vp_val_color: '#ffeb3b',
        vp_price_levels: true,
        vp_label_color: '#8c92a4',
        vp_avg_node_color: '#787b8640',
        vp_high_node_color: '#f57c0080',
        vp_low_node_color: '#787b86bf',

        supply_demand: true,
        sd_low_vol_ratio_pct: 15,
        sd_zone_color: '#512da8cc',

        vwcb: true,
        vwcb_high: 1.618,
        vwcb_low: 0.618,
    };

    /** Как PriceScale::roundMarket — 8 знаков. */
    function roundMarket(value) {
        return Math.round(Number(value) * 1e8) / 1e8;
    }

    function round8(value) {
        return Math.round(Number(value) * 1e8) / 1e8;
    }

    function emptyPayload() {
        return {
            id: ID,
            version: 1,
            lines: [],
            markers: [],
            // PHP emptyPayload: bar_colors => [] (JSON array when empty).
            bar_colors: [],
            volume_profile: { lines: [], boxes: [], labels: [], meta: {} },
            meta: { bars: 0, bar_seconds: 0 },
        };
    }

    /**
     * Режим Pine «Fixed Range» vs «Visible Range»: принимаем короткие ключи и подписи UI.
     */
    function lookbackRangeIsFixed(value) {
        const s = String(value == null ? '' : value).toLowerCase().trim();
        return s.indexOf('fixed') !== -1;
    }

    /**
     * sProcessing как в Pine: для fixed — по времени; для visible — все бары в загруженном окне.
     * @returns {boolean[]}
     */
    function computeSProcessing(fixedRange, lookbackBars, barSeconds, time, now, n) {
        const out = new Array(n);
        if (fixedRange && lookbackBars > 0) {
            const cutoff = now - lookbackBars * barSeconds;
            for (let i = 0; i < n; i++) {
                out[i] = time[i] > cutoff;
            }
        } else {
            for (let i = 0; i < n; i++) {
                out[i] = true;
            }
        }
        return out;
    }

    /** @returns {(number|null)[]} */
    function smaSeries(values, len) {
        const n = values.length;
        const out = new Array(n).fill(null);
        if (len <= 0) return out;
        for (let i = 0; i < n; i++) {
            if (i < len - 1) continue;
            let s = 0.0;
            for (let k = i - len + 1; k <= i; k++) s += values[k];
            out[i] = s / len;
        }
        return out;
    }

    /**
     * ATR Wilder (как ta.atr в Pine).
     * @returns {(number|null)[]}
     */
    function atrWilder(high, low, close, len) {
        const n = high.length;
        const out = new Array(n).fill(null);
        if (len <= 1 || n === 0) return out;
        const tr = new Array(n);
        for (let i = 0; i < n; i++) {
            if (i === 0) {
                tr[i] = high[i] - low[i];
            } else {
                tr[i] = Math.max(
                    high[i] - low[i],
                    Math.abs(high[i] - close[i - 1]),
                    Math.abs(low[i] - close[i - 1])
                );
            }
        }
        if (n < len) return out;
        let seed = 0.0;
        for (let i = 0; i < len; i++) seed += tr[i];
        seed /= len;
        out[len - 1] = seed;
        for (let i = len; i < n; i++) {
            const prev = Number(out[i - 1]);
            out[i] = (prev * (len - 1) + tr[i]) / len;
        }
        return out;
    }

    /**
     * Последний индекс j ≤ i, где условие истинно и бар в окне sProcessing.
     * @param {boolean[]} cond
     * @param {boolean[]} window
     * @returns {number|null}
     */
    function lastTrueIndexInWindow(cond, window, i) {
        for (let j = i; j >= 0; j--) {
            if (cond[j] && window[j]) return j;
        }
        return null;
    }

    function normalizeLevel(s) {
        const t = String(s || '').toLowerCase().trim();
        if (t === 'high' || t === 'low' || t === 'close' || t === 'both') {
            return t.charAt(0).toUpperCase() + t.slice(1);
        }
        return 'Both';
    }

    /** @returns {number[]} */
    function bullLevels(mode, high, close) {
        if (mode === 'High') return [high];
        if (mode === 'Close') return [close];
        return [close, (high + close) / 2.0, high];
    }

    /** @returns {number[]} */
    function bearLevels(mode, low, close) {
        if (mode === 'Low') return [low];
        if (mode === 'Close') return [close];
        return [low, (low + close) / 2.0, close];
    }

    /**
     * Удаляет последний элемент out с source === src (как PHP popLastMatchingSource).
     * @param {object[]} out
     */
    function popLastMatchingSource(out, src) {
        for (let k = out.length - 1; k >= 0; k--) {
            if ((out[k].source || '') === src) {
                out.splice(k, 1);
                return;
            }
        }
    }

    /**
     * S/R сегменты: на каждом баре при новом сигнале — новая линия;
     * при подряд одинаковых falling/rising — замена последней (как в Pine).
     * @returns {object[]}
     */
    function buildSrLinesLikePine(
        n, time, lastTime, sProcessing,
        falling, rising, condSr, lwst3, hst3,
        enabled, color, width, style
    ) {
        if (!enabled) return [];
        const out = [];
        for (let i = 0; i < n; i++) {
            if (!sProcessing[i]) continue;
            // Якорь только среди баров в «рабочем» окне (sProcessing).
            const j = lastTrueIndexInWindow(condSr, sProcessing, i);
            if (j === null) continue;
            // Pine: y/x1 от последнего бара, где было falling|rising (valuewhen occurrence 0).
            const y = falling[j] ? lwst3[j] : hst3[j];
            const x1 = time[j];

            if (falling[i]) {
                if (i > 0 && falling[i - 1] && falling[i]) out.pop();
                out.push({
                    source: 'sr',
                    time_start: x1,
                    time_end: lastTime,
                    price: round8(y),
                    color,
                    width,
                    style,
                });
            } else if (rising[i]) {
                if (i > 0 && rising[i - 1] && rising[i]) out.pop();
                out.push({
                    source: 'sr',
                    time_start: x1,
                    time_end: lastTime,
                    price: round8(y),
                    color,
                    width,
                    style,
                });
            }
        }
        return out;
    }

    /**
     * Spike или ATR линии по тем же правилам уровней, что в Pine.
     * @param {boolean[]} flag exhaustVol или highVol
     * @returns {object[]}
     */
    function buildSpikeOrAtrLines(
        n, time, lastTime, sProcessing, flag,
        bull, bear, isSpike,
        bullLevel, bearLevel, color, width, style,
        high, low, close
    ) {
        const out = [];
        bullLevel = normalizeLevel(bullLevel);
        bearLevel = normalizeLevel(bearLevel);
        const src = isSpike ? 'volume_spike' : 'high_volatility';
        const nPopBull = bullLevel === 'Both' ? 3 : 1;
        const nPopBear = bearLevel === 'Both' ? 3 : 1;

        for (let i = 0; i < n; i++) {
            if (!sProcessing[i] || !flag[i]) continue;
            // Pine: при подряд одинаковых условиях удаляет предыдущую линию (1 или 3 в Both).
            if (i > 0 && flag[i] && flag[i - 1]) {
                if (bull[i] && bull[i - 1]) {
                    for (let k = 0; k < nPopBull; k++) popLastMatchingSource(out, src);
                } else if (bear[i] && bear[i - 1]) {
                    for (let k = 0; k < nPopBear; k++) popLastMatchingSource(out, src);
                }
            }

            const x1 = time[i];
            if (bull[i]) {
                const levels = bullLevels(bullLevel, high[i], close[i]);
                for (let idx = 0; idx < levels.length; idx++) {
                    out.push({
                        source: src,
                        time_start: x1,
                        time_end: lastTime,
                        price: round8(levels[idx]),
                        color,
                        width: idx === 1 ? Math.max(1, width - 1) : width,
                        style: idx === 1 ? 'Dotted' : style,
                    });
                }
            } else if (bear[i]) {
                const levels = bearLevels(bearLevel, low[i], close[i]);
                for (let idx = 0; idx < levels.length; idx++) {
                    out.push({
                        source: src,
                        time_start: x1,
                        time_end: lastTime,
                        price: round8(levels[idx]),
                        color,
                        width: idx === 1 ? Math.max(1, width - 1) : width,
                        style: idx === 1 ? 'Dotted' : style,
                    });
                }
            }
        }

        return out.length > 500 ? out.slice(0, 500) : out;
    }

    /**
     * Volume profile + SD + линии POC/VAH/VAL (логика блока barstate.islast в Pine).
     * @returns {{lines:object[],boxes:object[],labels:object[],meta:object}}
     */
    function computeVolumeProfileBlock(p, high, low, vol, time, bull, n, barSeconds) {
        const profileLevels = Math.max(10, Math.min(170, Number(p.vp_profile_levels) | 0));
        const fixedLb = Math.max(10, Math.min(5000, Number(p.vp_fixed_lookback_bars) | 0));

        let L = n;
        if (lookbackRangeIsFixed(p.vp_lookback_range)) {
            L = Math.min(fixedLb, n);
        }
        if (L < 2) {
            return { lines: [], boxes: [], labels: [], meta: {} };
        }

        const startIdx = n - L;
        let priceHighest = high[startIdx];
        let priceLowest = low[startIdx];
        for (let i = startIdx; i < n; i++) {
            priceHighest = Math.max(priceHighest, high[i]);
            priceLowest = Math.min(priceLowest, low[i]);
        }
        if (priceHighest <= priceLowest) {
            return { lines: [], boxes: [], labels: [], meta: {} };
        }

        const priceStep = (priceHighest - priceLowest) / profileLevels;
        if (priceStep <= 0) {
            return { lines: [], boxes: [], labels: [], meta: {} };
        }

        const volumeStorage = new Array(profileLevels + 1).fill(0.0);
        const volumeStorageB = new Array(profileLevels + 1).fill(0.0);

        for (let barIndex = 0; barIndex < L; barIndex++) {
            const idx = n - 1 - barIndex;
            const barHigh = high[idx];
            const barLow = low[idx];
            const barVol = vol[idx];
            const hl = barHigh - barLow;
            const mult = hl === 0.0 ? 1.0 : priceStep / hl;

            for (let pi = 0; pi < profileLevels; pi++) {
                const priceLevel = priceLowest + pi * priceStep;
                if (barHigh >= priceLevel && barLow < priceLevel + priceStep) {
                    const add = barVol * mult;
                    volumeStorage[pi] += add;
                    if (bull[idx]) volumeStorageB[pi] += add;
                }
            }
        }

        let maxVol = 0.0;
        for (let pi = 0; pi < volumeStorage.length; pi++) {
            if (volumeStorage[pi] > maxVol) maxVol = volumeStorage[pi];
        }
        if (maxVol <= 0) {
            return { lines: [], boxes: [], labels: [], meta: {} };
        }

        let pocLevel = 0;
        for (let pi = 1; pi < profileLevels; pi++) {
            if (volumeStorage[pi] > volumeStorage[pocLevel]) pocLevel = pi;
        }

        const isValueArea = Number(p.vp_value_area_pct) / 100.0;
        let totalVolumeTraded = 0.0;
        for (let pi = 0; pi < volumeStorage.length; pi++) totalVolumeTraded += volumeStorage[pi];
        totalVolumeTraded *= isValueArea;

        let valueArea = volumeStorage[pocLevel];
        let levelAbovePoc = pocLevel | 0;
        let levelBelowPoc = pocLevel | 0;

        while (valueArea < totalVolumeTraded) {
            if (levelBelowPoc === 0 && levelAbovePoc >= profileLevels - 1) break;
            let volumeAbovePoc = 0.0;
            if (levelAbovePoc < profileLevels - 1) {
                volumeAbovePoc = volumeStorage[levelAbovePoc + 1];
            }
            let volumeBelowPoc = 0.0;
            if (levelBelowPoc > 0) {
                volumeBelowPoc = volumeStorage[levelBelowPoc - 1];
            }
            if (volumeAbovePoc >= volumeBelowPoc) {
                valueArea += volumeAbovePoc;
                levelAbovePoc++;
            } else {
                valueArea += volumeBelowPoc;
                levelBelowPoc--;
            }
        }

        const timeStart = time[startIdx];
        const timeEnd = time[n - 1];
        const offsetSec = ((Number(p.vp_horizontal_offset_bars) | 0) + 50) * barSeconds;

        const lines = [];
        const vahPrice = priceLowest + (levelAbovePoc + 1.0) * priceStep;
        const pocPrice = priceLowest + (pocLevel + 0.5) * priceStep;
        const valPrice = priceLowest + (levelBelowPoc + 0.0) * priceStep;

        if (p.vp_vah) {
            lines.push({
                source: 'vp_vah',
                time_start: timeStart,
                time_end: timeEnd + offsetSec,
                price: round8(vahPrice),
                color: String(p.vp_vah_color),
                width: 2,
                style: 'Solid',
            });
        }
        if (p.vp_poc) {
            lines.push({
                source: 'vp_poc',
                time_start: timeStart,
                time_end: timeEnd + offsetSec,
                price: round8(pocPrice),
                color: String(p.vp_poc_color),
                width: 2,
                style: 'Solid',
            });
        }
        if (p.vp_val) {
            lines.push({
                source: 'vp_val',
                time_start: timeStart,
                time_end: timeEnd + offsetSec,
                price: round8(valPrice),
                color: String(p.vp_val_color),
                width: 2,
                style: 'Solid',
            });
        }

        const boxes = [];
        const lowVolRatio = Number(p.sd_low_vol_ratio_pct) / 100.0;

        if (p.volume_profile) {
            for (let level = 0; level < profileLevels; level++) {
                const ratio = volumeStorage[level] / maxVol;
                const rowLow = priceLowest + (level + 0.25) * priceStep;
                const rowHigh = priceLowest + (level + 0.75) * priceStep;
                // Цвета узлов VP: ключи как в defaultParams (vp_*_color).
                const cAvg = String(p.vp_avg_node_color);
                const cHvn = String(p.vp_high_node_color);
                const cLvn = String(p.vp_low_node_color);
                const bg = ratio > 0.8 ? cHvn : ratio < 0.2 ? cLvn : cAvg;
                boxes.push({
                    kind: 'vp_hist',
                    time_start: timeEnd,
                    time_end: timeEnd + offsetSec,
                    price_low: round8(rowLow),
                    price_high: round8(rowHigh),
                    background: bg,
                    width_ratio: Math.max(0.05, Math.min(1.0, ratio)),
                });
                const bullBearPower = 2 * volumeStorageB[level] - volumeStorage[level];
                const domColor = bullBearPower > 0 ? '#26a69a80' : '#ef535080';
                boxes.push({
                    kind: 'vp_dom',
                    time_start: timeEnd,
                    time_end: timeEnd + offsetSec,
                    price_low: round8(rowLow),
                    price_high: round8(rowHigh),
                    background: domColor,
                    bias: bullBearPower > 0 ? 1 : -1,
                    strength_ratio: maxVol > 0 ? Math.min(1.0, Math.abs(bullBearPower) / maxVol) : 0,
                });
            }
            boxes.push({
                kind: 'vp_frame',
                time_start: timeStart,
                time_end: timeEnd + offsetSec,
                price_low: round8(priceLowest),
                price_high: round8(priceHighest),
                border: 'rgba(156,163,175,0.35)',
            });
        }

        if (p.supply_demand) {
            for (let level = 0; level < profileLevels; level++) {
                const ratio = volumeStorage[level] / maxVol;
                if (ratio < lowVolRatio) {
                    boxes.push({
                        kind: 'sd_zone',
                        time_start: timeStart,
                        time_end: timeEnd + Math.min(7, Number(p.vp_horizontal_offset_bars) | 0) * barSeconds,
                        price_low: round8(priceLowest + level * priceStep),
                        price_high: round8(priceLowest + (level + 1.0) * priceStep),
                        background: String(p.sd_zone_color),
                    });
                }
            }
        }

        const labels = [];
        if (p.vp_price_levels) {
            const lc = String(p.vp_label_color);
            // PHP: (string) PriceScale::roundMarket(...)
            labels.push({
                text: String(roundMarket(priceHighest)),
                price: priceHighest,
                time: timeEnd + offsetSec,
                color: lc,
                place: 'high',
            });
            labels.push({
                text: String(roundMarket(priceLowest)),
                price: priceLowest,
                time: timeEnd + offsetSec,
                color: lc,
                place: 'low',
            });
        }

        return {
            lines,
            boxes,
            labels,
            meta: {
                lookback_bars: L,
                poc_price: round8(pocPrice),
                vah_price: round8(vahPrice),
                val_price: round8(valPrice),
                price_lowest: round8(priceLowest),
                price_highest: round8(priceHighest),
            },
        };
    }

    /**
     * @param {Array<{time:number,open:number,high:number,low:number,close:number,volume?:number}>} rows
     * @param {object|null} params
     * @param {number} [barSeconds=60] длительность бара в секундах (как PHP 3-й аргумент)
     * @returns {object} payload как PHP compute()
     */
    function compute(rows, params, barSeconds) {
        const p = Object.assign({}, DEFAULT_PARAMS, params || {});
        const n = Array.isArray(rows) ? rows.length : 0;
        const bs = barSeconds == null ? 60 : Math.max(1, Number(barSeconds) | 0);

        if (n < 3) {
            return emptyPayload();
        }

        const open = new Array(n);
        const high = new Array(n);
        const low = new Array(n);
        const close = new Array(n);
        const vol = new Array(n);
        const time = new Array(n);
        for (let i = 0; i < n; i++) {
            const c = rows[i];
            open[i] = Number(c.open);
            high[i] = Number(c.high);
            low[i] = Number(c.low);
            close[i] = Number(c.close);
            vol[i] = Number(c.volume != null ? c.volume : 0);
            time[i] = Math.trunc(Number(c.time));
        }

        const vSmaLen = Math.max(1, Number(p.volume_sma_length) | 0);
        const vSma = smaSeries(vol, vSmaLen);

        const bull = new Array(n);
        const bear = new Array(n);
        for (let i = 0; i < n; i++) {
            bull[i] = close[i] > open[i];
            bear[i] = close[i] < open[i];
        }

        const risingPrice = new Array(n);
        const fallingPrice = new Array(n);
        for (let i = 0; i < n; i++) {
            risingPrice[i] = i > 0 && close[i] > close[i - 1];
            fallingPrice[i] = i > 0 && close[i] < close[i - 1];
        }

        const risingVol = new Array(n);
        for (let i = 0; i < n; i++) {
            risingVol[i] = i > 0 && vol[i] >= vol[i - 1];
        }

        const lwst3 = new Array(n);
        const hst3 = new Array(n);
        for (let i = 0; i < n; i++) {
            if (i < 2) {
                lwst3[i] = low[i];
                hst3[i] = high[i];
            } else {
                lwst3[i] = Math.min(low[i], low[i - 1], low[i - 2]);
                hst3[i] = Math.max(high[i], high[i - 1], high[i - 2]);
            }
        }

        const atr = atrWilder(high, low, close, Number(p.atr_length) | 0);
        const atrMult = Number(p.atr_mult);
        const weightedAtr = new Array(n);
        const range1 = new Array(n);
        for (let i = 0; i < n; i++) {
            range1[i] = Math.abs(high[i] - low[i]);
            weightedAtr[i] = atr[i] !== null ? atrMult * atr[i] : null;
        }

        // PHP: $now = time() — unix seconds «сейчас» для fixed lookback cutoff.
        const now = Math.floor(Date.now() / 1000);
        const sProcessing = computeSProcessing(
            lookbackRangeIsFixed(p.sr_lookback_range),
            Number(p.sr_fixed_lookback_bars) | 0,
            bs,
            time,
            now,
            n
        );

        const falling = new Array(n);
        const rising = new Array(n);
        for (let i = 0; i < n; i++) {
            if (i < 2) {
                falling[i] = false;
                rising[i] = false;
                continue;
            }
            if (p.sr_source === 'volume') {
                falling[i] = bear[i] && bear[i - 1] && bear[i - 2]
                    && vol[i] > (vSma[i] || 0)
                    && risingVol[i] && risingVol[i - 1];
                rising[i] = bull[i] && bull[i - 1] && bull[i - 2]
                    && vol[i] > (vSma[i] || 0)
                    && risingVol[i] && risingVol[i - 1];
            } else {
                falling[i] = bear[i] && bear[i - 1] && bear[i - 2]
                    && fallingPrice[i] && fallingPrice[i - 1] && fallingPrice[i - 2];
                rising[i] = bull[i] && bull[i - 1] && bull[i - 2]
                    && risingPrice[i] && risingPrice[i - 1] && risingPrice[i - 2];
            }
        }

        const condSr = new Array(n);
        for (let i = 0; i < n; i++) {
            condSr[i] = falling[i] || rising[i];
        }

        const lastTime = time[n - 1];

        // S/R: при подряд одинаковых falling/rising удаляем предыдущий сегмент.
        let lines = buildSrLinesLikePine(
            n, time, lastTime, sProcessing,
            falling, rising, condSr, lwst3, hst3,
            !!p.sr_lines,
            String(p.sr_line_color),
            Number(p.sr_line_width) | 0,
            String(p.sr_line_style)
        );

        const exhaustVol = new Array(n);
        for (let i = 0; i < n; i++) {
            const vs = vSma[i];
            exhaustVol[i] = vs !== null && vol[i] > Number(p.v_spike_threshold) * vs;
        }

        const highVol = new Array(n);
        for (let i = 0; i < n; i++) {
            highVol[i] = weightedAtr[i] !== null && range1[i] > weightedAtr[i];
        }

        if (p.v_spike_lines) {
            lines = lines.concat(buildSpikeOrAtrLines(
                n, time, lastTime, sProcessing, exhaustVol,
                bull, bear, true,
                String(p.v_spike_bull_level),
                String(p.v_spike_bear_level),
                String(p.v_spike_line_color),
                Number(p.v_spike_line_width) | 0,
                String(p.v_spike_line_style),
                high, low, close
            ));
        }

        if (p.atr_lines) {
            lines = lines.concat(buildSpikeOrAtrLines(
                n, time, lastTime, sProcessing, highVol,
                bull, bear, false,
                String(p.atr_bull_level),
                String(p.atr_bear_level),
                String(p.atr_line_color),
                Number(p.atr_line_width) | 0,
                String(p.atr_line_style),
                high, low, close
            ));
        }

        const markers = [];
        for (let i = 0; i < n; i++) {
            if (!sProcessing[i]) continue;
            if (p.v_spike_markers && exhaustVol[i]) {
                markers.push({
                    time: time[i],
                    position: 'aboveBar',
                    color: '#f59e0b',
                    shape: 'circle',
                    text: '!',
                });
            }
            if (p.atr_markers && highVol[i]) {
                markers.push({
                    time: time[i],
                    position: 'belowBar',
                    color: '#22c55e',
                    shape: 'circle',
                    text: 'V',
                });
            }
        }

        /** @type {Object.<string,{color:string,borderColor:string,wickColor:string}>} */
        const barColors = {};
        if (p.vwcb) {
            for (let i = 0; i < n; i++) {
                const vs = vSma[i];
                if (vs === null) continue;
                const hiT = Number(p.vwcb_high);
                const loT = Number(p.vwcb_low);
                if (vol[i] > vs * hiT) {
                    const c = bear[i] ? '#910000' : '#006400';
                    barColors[String(time[i])] = { color: c, borderColor: c, wickColor: c };
                } else if (vol[i] < vs * loT) {
                    const c = bear[i] ? '#FF9800' : '#7FFFD4';
                    barColors[String(time[i])] = { color: c, borderColor: c, wickColor: c };
                }
            }
        }

        let vpPayload = { lines: [], boxes: [], labels: [], meta: {} };
        if (p.volume_profile || p.supply_demand) {
            vpPayload = computeVolumeProfileBlock(p, high, low, vol, time, bull, n, bs);
            if (vpPayload.lines && vpPayload.lines.length) {
                lines = lines.concat(vpPayload.lines);
            }
        }

        // PHP: пустой $barColors остаётся [] в json_encode; с ключами — object.
        const barColorsOut = Object.keys(barColors).length ? barColors : [];

        return {
            id: ID,
            version: 1,
            lines: lines.length > 500 ? lines.slice(0, 500) : lines,
            markers,
            bar_colors: barColorsOut,
            volume_profile: vpPayload,
            meta: {
                bars: n,
                bar_seconds: bs,
            },
        };
    }

    global.PlatformPriceActionDgt = {
        DEFAULT_PARAMS,
        ID,
        compute,
    };
})(typeof window !== 'undefined' ? window : globalThis);
