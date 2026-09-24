/**
 * SMC Flow — клиентский пересчёт (зеркало App\Services\Indicators\SmcFlowIndicatorService).
 * Композит Pine SMC Flow / AETHER FLOW: UT Bot, Hull Suite, Three Bar Reversal,
 * LuxAlgo FVG/OB, Reversal Signals, Mxwll structure/fibs, Sessions.
 * Вход: OHLC-ряды с графика (allChartData); выход совместим с smc-flow-overlay.js.
 */
(function attachPlatformSmcFlowCompute(global) {
    if (global.PlatformSmcFlowCompute) return;

    const ID = 'tv.smc_flow';

    /** @type {Record<string, mixed>} */
    const DEFAULT_PARAMS = {
        ut_enabled: true,
        ut_key_value: 2,
        ut_atr_period: 6,
        /** Heikin Ashi в Pine; на клиенте пока не считаем — флаг зарезервирован. */
        ut_use_heikin_ashi: false,
        show_ut_labels: true,
        keep_recent_only: true,
        max_ut_labels: 3,
        color_ut_buy: '#22c55e',
        color_ut_sell: '#ef4444',
        show_ut_trailing_stop: true,
        color_ut_trail_bull: '#22c55e',
        color_ut_trail_bear: '#ef4444',
        // Ограничение длины трейла для снижения нагрузки на canvas-оверлей при зуме/скролле.
        ut_max_trail_segments: 1200,
        // Hull Suite: цветной трендовый контур + опциональная сигнальная линия.
        hull_enabled: true,
        hull_mode: 'Hma', // Hma | Thma | Ehma
        hull_length: 55,
        hull_length_multiplier: 1.0,
        hull_switch_color: true,
        hull_show_signal_line: true,
        hull_line_width: 2,
        hull_signal_line_width: 1,
        hull_max_segments: 1200,
        color_hull_up: '#00ff00',
        color_hull_down: '#ff0000',
        color_hull_neutral: '#ff9800',
        // Three Bar Reversal: разворотные паттерны с опциональной фильтрацией тренда.
        three_br_enabled: true,
        three_br_type: 'All', // Normal | Enhanced | All
        three_br_trend_type: 'None', // Moving Average Cloud | None
        three_br_trend_filter: 'Aligned', // Aligned | Opposite
        three_br_ma_type: 'HMA', // SMA | EMA | HMA | RMA | WMA | VWMA
        three_br_ma_fast_length: 50,
        three_br_ma_slow_length: 200,
        three_br_show_levels: true,
        /** Level | Zone | None — brpSR в Pine: зона поддержки/сопротивления после подтверждения. */
        three_br_sr_mode: 'Level',
        three_br_st_atr_period: 10,
        three_br_st_factor: 3.0,
        three_br_dc_length: 13,
        three_br_max_patterns: 120,
        color_three_br_bull: '#2962ff',
        color_three_br_bear: '#ff9800',
        // Reversal Signals: momentum 9 + S/R step lines.
        rs_enabled: true,
        rs_sr_levels: true,
        rs_max_momentum_markers: 80,
        rs_max_sr_segments: 200,
        color_rs_bull: '#089981',
        color_rs_bear: '#f23645',
        // Mxwll Suite: swing labels + internal/external BoS/CHoCH.
        mxwll_enabled: true,
        mxwll_show_internals: true,
        mxwll_int_sensitivity: 5, // 3 | 5 | 8
        mxwll_int_structure: 'All', // All | BoS | CHoCH
        mxwll_show_externals: true,
        mxwll_ext_sensitivity: 25, // 10 | 25 | 50
        mxwll_ext_structure: 'All',
        mxwll_show_hh_lh: true,
        mxwll_show_hl_ll: true,
        mxwll_max_events: 120,
        /** Лимиты раздельно: swing-метки и пары line+label BoS/CHoCH. */
        mxwll_max_swing_labels: 80,
        mxwll_max_structure_events: 80,
        color_mxwll_bull: '#14D990',
        color_mxwll_bear: '#F24968',
        // Mxwll rolling High/Low (4H=240 баров M1, 1D=1440 баров M1).
        mxwll_show_4h_levels: true,
        mxwll_show_4h_labels: true,
        mxwll_show_1d_levels: true,
        mxwll_show_1d_labels: true,
        color_mxwll_level: '#00ffff',
        // Auto Fibs + main zigzag leg.
        mxwll_show_fibs: true,
        mxwll_show_main_leg: true,
        mxwll_fib_extend_bars: 5,
        // Sessions — bgcolor по America/New_York (NY / Asia / London).
        sessions_enabled: true,
        sessions_transparency: 98,
        color_session_ny: '#f24968',
        color_session_asia: '#14D990',
        color_session_london: '#F2B807',
        // Hull band fill между MHULL и SHULL.
        hull_show_band: true,
        hull_band_transparency: 40,
        // LuxAlgo FVG.
        lux_fvg_enabled: true,
        lux_fvg_threshold_pct: 0.0,
        lux_fvg_threshold_auto: false,
        lux_fvg_show_last: 0,
        lux_fvg_mitigation_levels: false,
        lux_fvg_extend_bars: 20,
        lux_fvg_dynamic: false,
        color_lux_fvg_bull: '#089981',
        color_lux_fvg_bull_alpha: 70,
        color_lux_fvg_bear: '#f23645',
        color_lux_fvg_bear_alpha: 70,
        // LuxAlgo Order Blocks.
        lux_ob_enabled: true,
        lux_ob_length: 5,
        lux_ob_bull_count: 3,
        lux_ob_bear_count: 3,
        lux_ob_mitigation: 'Wick', // Wick | Close
        lux_ob_avg_line_style: 'dotted', // solid | dashed | dotted
        lux_ob_avg_line_width: 1,
        color_lux_ob_bull_bg: '#089981',
        color_lux_ob_bull_bg_alpha: 80,
        color_lux_ob_bull_border: '#089981',
        color_lux_ob_bull_avg: '#9598a1',
        color_lux_ob_bull_avg_alpha: 37,
        color_lux_ob_bear_bg: '#f23645',
        color_lux_ob_bear_bg_alpha: 80,
        color_lux_ob_bear_border: '#f23645',
        color_lux_ob_bear_avg: '#9598a1',
        color_lux_ob_bear_avg_alpha: 37,
        label_size: 'tiny',
    };

    /** @return {Array<{ratio:number,color:string,show:boolean}>} */
    function defaultMxwllFibLevels() {
        return [
            { ratio: 0.236, color: '#a0a5b9', show: true },
            { ratio: 0.382, color: '#00ff00', show: true },
            { ratio: 0.5, color: '#ffff00', show: true },
            { ratio: 0.618, color: '#ff9800', show: true },
            { ratio: 0.786, color: '#ff0000', show: true },
            { ratio: 0.886, color: '#800080', show: true },
            { ratio: 1.13, color: '#0000ff', show: true },
            { ratio: 1.27, color: '#008080', show: true },
            { ratio: 1.41, color: '#800000', show: true },
            { ratio: 1.618, color: '#000080', show: true },
        ];
    }

    function defaultParams() {
        return Object.assign({}, DEFAULT_PARAMS);
    }

    function colTime(rows) {
        return rows.map(function (r) { return Number(r.time) | 0; });
    }
    function colFloat(rows, key) {
        return rows.map(function (r) { return Number(r[key]); });
    }
    function colVolume(rows) {
        return rows.map(function (r) { return Number(r.volume != null ? r.volume : 0); });
    }

    /**
     * @param {Array<{time:number,open:number,high:number,low:number,close:number,volume?:number}>} rows
     * @param {object|null} params
     * @returns {{lines:object[],areas:object[],labels:object[],label_size:string,modules:string[]}}
     */
    function compute(rows, params) {
        const p = Object.assign({}, DEFAULT_PARAMS, params || {});
        const labelSize = normalizeLabelSize(String(p.label_size != null ? p.label_size : 'tiny'));

        if (!Array.isArray(rows) || rows.length < 2) {
            return {
                lines: [],
                areas: [],
                labels: [],
                label_size: labelSize,
                modules: [],
            };
        }

        let allLines = [];
        let allAreas = [];
        let allLabels = [];
        const modules = [];

        if (p.ut_enabled !== false) {
            const ut = computeUtBot(rows, p);
            allLines = allLines.concat(ut.lines);
            allLabels = allLabels.concat(ut.labels);
            if (ut.lines.length || ut.labels.length) modules.push('ut_bot');
        }

        if (p.hull_enabled !== false) {
            const hull = computeHullSuite(rows, p);
            allLines = allLines.concat(hull.lines);
            allAreas = allAreas.concat(hull.areas || []);
            if (hull.lines.length || (hull.areas && hull.areas.length)) modules.push('hull_suite');
        }

        if (p.three_br_enabled !== false) {
            const threeBr = computeThreeBarReversal(rows, p);
            allLines = allLines.concat(threeBr.lines);
            allAreas = allAreas.concat(threeBr.areas);
            allLabels = allLabels.concat(threeBr.labels);
            if (threeBr.lines.length || threeBr.areas.length || threeBr.labels.length) {
                modules.push('three_bar_reversal');
            }
        }

        if (p.lux_fvg_enabled !== false) {
            const fvg = computeLuxFvg(rows, p);
            allLines = allLines.concat(fvg.lines);
            allAreas = allAreas.concat(fvg.areas);
            if (fvg.lines.length || fvg.areas.length) modules.push('lux_fvg');
        }

        if (p.lux_ob_enabled !== false) {
            const ob = computeLuxOrderBlocks(rows, p);
            allLines = allLines.concat(ob.lines);
            allAreas = allAreas.concat(ob.areas);
            if (ob.lines.length || ob.areas.length) modules.push('lux_ob');
        }

        if (p.rs_enabled !== false) {
            const rs = computeReversalSignals(rows, p);
            allLines = allLines.concat(rs.lines);
            allLabels = allLabels.concat(rs.labels);
            if (rs.lines.length || rs.labels.length) modules.push('reversal_signals');
        }

        if (p.mxwll_enabled !== false) {
            const mxwll = computeMxwllSuite(rows, p);
            allLines = allLines.concat(mxwll.lines);
            allLabels = allLabels.concat(mxwll.labels);
            if (mxwll.lines.length || mxwll.labels.length) modules.push('mxwll_structure');
        }

        if (p.sessions_enabled !== false) {
            const sessions = computeSessions(rows, p);
            allAreas = allAreas.concat(sessions.areas);
            if (sessions.areas.length) modules.push('sessions');
        }

        allLabels.sort(function (a, b) { return (a.time | 0) - (b.time | 0); });

        return {
            lines: allLines,
            areas: allAreas,
            labels: allLabels,
            label_size: labelSize,
            modules: modules,
        };
    }

    /** UT Bot: ATR-трейлинг и сигналы Buy/Sell по пересечению цены и стопа (как в 3.txt). */
    function computeUtBot(rows, p) {
        const key = Math.max(0.0001, Number(p.ut_key_value));
        const period = Math.max(1, Number(p.ut_atr_period) | 0);
        const showLabels = !!p.show_ut_labels;
        const keepRecent = !!p.keep_recent_only;
        const maxLabels = Math.max(1, Math.min(10, Number(p.max_ut_labels) | 0));
        const buyBg = String(p.color_ut_buy);
        const sellBg = String(p.color_ut_sell);
        const showTrail = !!p.show_ut_trailing_stop;
        const trailBull = String(p.color_ut_trail_bull);
        const trailBear = String(p.color_ut_trail_bear);
        const maxTrailSegments = Math.max(50, Math.min(5000, Number(p.ut_max_trail_segments != null ? p.ut_max_trail_segments : 1200) | 0));

        const n = rows.length;
        const time = colTime(rows);
        const high = colFloat(rows, 'high');
        const low = colFloat(rows, 'low');
        const close = colFloat(rows, 'close');

        const atr = atrWilder(high, low, close, period);

        const stops = new Array(n).fill(0);
        const pos = new Array(n).fill(0);

        for (let i = 0; i < n; i++) {
            const src = close[i];
            const prevSrc = i > 0 ? close[i - 1] : src;
            const prevStop = i > 0 ? stops[i - 1] : 0;
            const nLoss = key * Number(atr[i] != null ? atr[i] : 0);

            // Логика xATRTrailingStop из Pine (3.txt): стоп подтягивается за ценой в сторону тренда.
            if (src > prevStop && prevSrc > prevStop) {
                stops[i] = Math.max(prevStop, src - nLoss);
            } else if (src < prevStop && prevSrc < prevStop) {
                stops[i] = Math.min(prevStop, src + nLoss);
            } else if (src > prevStop) {
                stops[i] = src - nLoss;
            } else {
                stops[i] = src + nLoss;
            }

            if (prevSrc < prevStop && src > prevStop) {
                pos[i] = 1;
            } else if (prevSrc > prevStop && src < prevStop) {
                pos[i] = -1;
            } else {
                pos[i] = i > 0 ? pos[i - 1] : 0;
            }
        }

        const linesOut = [];
        if (showTrail) {
            // Ломаная ATR-трейла: (t[i-1], stop[i-1]) → (t[i], stop[i]), как plot(xATRTrailingStop).
            const start = Math.max(1, n - maxTrailSegments);
            for (let i = start; i < n; i++) {
                linesOut.push({
                    time_start: time[i - 1] | 0,
                    time_end: time[i] | 0,
                    price_start: Number(stops[i - 1]),
                    price_end: Number(stops[i]),
                    color: pos[i] >= 0 ? trailBull : trailBear,
                    style: 'Solid',
                    width: 2,
                });
            }
        }

        let labelsOut = [];
        if (showLabels) {
            let buyLabels = [];
            let sellLabels = [];
            for (let i = 1; i < n; i++) {
                const src = close[i];
                const prevSrc = close[i - 1];
                const stop = stops[i];
                const prevStop = stops[i - 1];

                // EMA(close, 1) в Pine совпадает с close; crossover — как ta.crossover в 3.txt.
                const above = src > stop && prevSrc <= prevStop;
                const below = src < stop && prevSrc >= prevStop;
                const buy = src > stop && above;
                const sell = src < stop && below;

                if (buy) {
                    buyLabels.push({
                        time: time[i] | 0,
                        price: Number(low[i]),
                        text: 'Buy',
                        color: '#ffffff',
                        background: buyBg,
                        position: 'below',
                    });
                }
                if (sell) {
                    sellLabels.push({
                        time: time[i] | 0,
                        price: Number(high[i]),
                        text: 'Sell',
                        color: '#ffffff',
                        background: sellBg,
                        position: 'above',
                    });
                }
            }

            if (keepRecent) {
                buyLabels = buyLabels.slice(-maxLabels);
                sellLabels = sellLabels.slice(-maxLabels);
            }

            labelsOut = buyLabels.concat(sellLabels);
            labelsOut.sort(function (a, b) { return a.time - b.time; });
        }

        return { lines: linesOut, labels: labelsOut };
    }

    /** ATR по Wilder (аналог ta.atr в Pine). */
    function atrWilder(high, low, close, len) {
        const n = high.length;
        const out = new Array(n).fill(null);
        if (len <= 0 || n === 0) return out;

        const tr = [];
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

        let seed = 0;
        for (let i = 0; i < len; i++) seed += tr[i];
        seed /= len;
        out[len - 1] = seed;
        for (let i = len; i < n; i++) {
            const prev = Number(out[i - 1]);
            out[i] = ((prev * (len - 1)) + tr[i]) / len;
        }
        return out;
    }

    /** Hull Suite (Hma / Thma / Ehma): основной контур MHULL и сигнальная линия SHULL=HULL[2]. */
    function computeHullSuite(rows, p) {
        const time = colTime(rows);
        const close = colFloat(rows, 'close');
        const n = close.length;
        const mode = String(p.hull_mode != null ? p.hull_mode : 'Hma');
        const length = Math.max(2, Number(p.hull_length != null ? p.hull_length : 55) | 0);
        const lengthMult = Math.max(0.1, Math.min(5.0, Number(p.hull_length_multiplier != null ? p.hull_length_multiplier : 1.0)));
        const len = Math.max(2, Math.round(length * lengthMult) | 0);
        const showSignal = p.hull_show_signal_line !== false;
        const switchColor = p.hull_switch_color !== false;
        const lineWidth = Math.max(1, Math.min(6, Number(p.hull_line_width != null ? p.hull_line_width : 2) | 0));
        const signalLineWidth = Math.max(1, Math.min(6, Number(p.hull_signal_line_width != null ? p.hull_signal_line_width : 1) | 0));
        const maxSegments = Math.max(50, Math.min(5000, Number(p.hull_max_segments != null ? p.hull_max_segments : 1200) | 0));
        const cUp = String(p.color_hull_up != null ? p.color_hull_up : '#00ff00');
        const cDown = String(p.color_hull_down != null ? p.color_hull_down : '#ff0000');
        const cNeutral = String(p.color_hull_neutral != null ? p.color_hull_neutral : '#ff9800');

        let hull;
        if (mode === 'Ehma') {
            hull = ehmaSeries(close, len);
        } else if (mode === 'Thma') {
            hull = thmaSeries(close, Math.max(2, Math.floor(len / 2) | 0));
        } else {
            hull = hmaSeries(close, len);
        }

        const signal = new Array(n).fill(null);
        for (let i = 2; i < n; i++) {
            signal[i] = hull[i - 2];
        }

        const lines = [];
        const start = Math.max(1, n - maxSegments);
        for (let i = start; i < n; i++) {
            const prev = hull[i - 1];
            const cur = hull[i];
            if (prev === null || cur === null) continue;

            const color = switchColor
                ? ((i >= 2 && hull[i - 2] !== null && cur > hull[i - 2]) ? cUp : cDown)
                : cNeutral;

            lines.push({
                time_start: time[i - 1] | 0,
                time_end: time[i] | 0,
                price_start: Number(prev),
                price_end: Number(cur),
                color: color,
                style: 'Solid',
                width: lineWidth,
            });
        }

        if (showSignal) {
            for (let i = start; i < n; i++) {
                const prev = signal[i - 1];
                const cur = signal[i];
                if (prev === null || cur === null) continue;
                lines.push({
                    time_start: time[i - 1] | 0,
                    time_end: time[i] | 0,
                    price_start: Number(prev),
                    price_end: Number(cur),
                    color: switchColor
                        ? ((i >= 2 && hull[i - 2] !== null && hull[i] !== null && hull[i] > hull[i - 2]) ? cUp : cDown)
                        : cNeutral,
                    style: 'Solid',
                    width: signalLineWidth,
                });
            }
        }

        const areas = [];
        if (showSignal && (p.hull_show_band !== false)) {
            const bandTrans = Math.max(0, Math.min(100, Number(p.hull_band_transparency != null ? p.hull_band_transparency : 40) | 0));
            const bandAlpha = Math.round((100 - bandTrans) / 100 * 100) / 100;
            for (let i = start; i < n; i++) {
                const h0 = hull[i - 1];
                const h1 = hull[i];
                const s0 = signal[i - 1];
                const s1 = signal[i];
                if (h0 === null || h1 === null || s0 === null || s1 === null) continue;
                const trendUp = switchColor ? (h1 > (hull[i - 2] != null ? hull[i - 2] : h1)) : true;
                const base = switchColor ? (trendUp ? cUp : cDown) : cNeutral;
                const fill = colorWithAlpha(base, bandAlpha);
                areas.push({
                    time_start: time[i - 1] | 0,
                    time_end: time[i] | 0,
                    price_high: Math.max(h0, h1, s0, s1),
                    price_low: Math.min(h0, h1, s0, s1),
                    fill: fill,
                    border: 'none',
                    kind: 'hull_band',
                });
            }
        }

        return { lines: lines, areas: areas };
    }

    function hmaSeries(src, len) {
        const half = Math.max(1, Math.floor(len / 2) | 0);
        const sqrtLen = Math.max(1, Math.round(Math.sqrt(len)) | 0);
        const w1 = wmaSeries(src, half);
        const w2 = wmaSeries(src, len);
        const diff = [];
        const n = src.length;
        for (let i = 0; i < n; i++) {
            diff.push((w1[i] !== null && w2[i] !== null) ? (2.0 * w1[i] - w2[i]) : null);
        }
        return wmaSeries(diff, sqrtLen);
    }

    function ehmaSeries(src, len) {
        const half = Math.max(1, Math.floor(len / 2) | 0);
        const sqrtLen = Math.max(1, Math.round(Math.sqrt(len)) | 0);
        const e1 = emaSeries(src, half);
        const e2 = emaSeries(src, len);
        const diff = [];
        const n = src.length;
        for (let i = 0; i < n; i++) {
            diff.push((e1[i] !== null && e2[i] !== null) ? (2.0 * e1[i] - e2[i]) : null);
        }
        return emaSeries(diff, sqrtLen);
    }

    function thmaSeries(src, len) {
        const l3 = Math.max(1, Math.floor(len / 3) | 0);
        const l2 = Math.max(1, Math.floor(len / 2) | 0);
        const w3 = wmaSeries(src, l3);
        const w2 = wmaSeries(src, l2);
        const w1 = wmaSeries(src, len);
        const mix = [];
        const n = src.length;
        for (let i = 0; i < n; i++) {
            mix.push((w3[i] !== null && w2[i] !== null && w1[i] !== null)
                ? (3.0 * w3[i] - w2[i] - w1[i])
                : null);
        }
        return wmaSeries(mix, len);
    }

    function wmaSeries(src, len) {
        const n = src.length;
        const out = new Array(n).fill(null);
        if (len <= 0) return out;
        const den = (len * (len + 1)) / 2.0;
        for (let i = len - 1; i < n; i++) {
            let ok = true;
            let num = 0;
            let w = 1;
            for (let j = i - len + 1; j <= i; j++, w++) {
                const v = src[j];
                if (v === null || v === undefined) {
                    ok = false;
                    break;
                }
                num += Number(v) * w;
            }
            if (ok) out[i] = num / den;
        }
        return out;
    }

    function emaSeries(src, len) {
        const n = src.length;
        const out = new Array(n).fill(null);
        if (len <= 0 || n === 0) return out;
        const alpha = 2.0 / (len + 1.0);
        let ema = null;
        for (let i = 0; i < n; i++) {
            const v = src[i];
            if (v === null || v === undefined) continue;
            const fv = Number(v);
            ema = ema === null ? fv : (alpha * fv + (1.0 - alpha) * ema);
            out[i] = ema;
        }
        return out;
    }

    function normalizeLabelSize(size) {
        const s = String(size || '').toLowerCase().trim();
        return (s === 'tiny' || s === 'small' || s === 'normal') ? s : 'tiny';
    }

    /** Преобразует HEX-цвет в rgba для полупрозрачной заливки зон 3BR. */
    function hexToRgba(hex, alpha) {
        let h = String(hex || '').trim().replace(/^#/, '');
        if (h.length === 3) {
            h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2];
        } else if (h.length === 8) {
            h = h.substring(0, 6);
        }
        if (h.length !== 6 || !/^[0-9a-fA-F]+$/.test(h)) {
            return 'rgba(148,163,184,0.22)';
        }
        const r = parseInt(h.substring(0, 2), 16);
        const g = parseInt(h.substring(2, 4), 16);
        const b = parseInt(h.substring(4, 6), 16);
        const a = Math.max(0, Math.min(1, alpha));
        return 'rgba(' + r + ',' + g + ',' + b + ',' + a.toFixed(3) + ')';
    }

    /** Three Bar Reversal из Pine: базовые bullish/bearish паттерны и опциональный MA-тренд фильтр. */
    function computeThreeBarReversal(rows, p) {
        const n = rows.length;
        if (n < 3) return { lines: [], areas: [], labels: [] };

        const time = colTime(rows);
        const open = colFloat(rows, 'open');
        const high = colFloat(rows, 'high');
        const low = colFloat(rows, 'low');
        const close = colFloat(rows, 'close');
        const volume = colVolume(rows);

        const patternType = String(p.three_br_type != null ? p.three_br_type : 'All');
        const trendType = String(p.three_br_trend_type != null ? p.three_br_trend_type : 'None');
        const trendFilter = String(p.three_br_trend_filter != null ? p.three_br_trend_filter : 'Aligned');
        const maType = String(p.three_br_ma_type != null ? p.three_br_ma_type : 'HMA');
        const fastLen = Math.max(2, Math.min(500, Number(p.three_br_ma_fast_length != null ? p.three_br_ma_fast_length : 50) | 0));
        const slowLen = Math.max(3, Math.min(1000, Number(p.three_br_ma_slow_length != null ? p.three_br_ma_slow_length : 200) | 0));
        const stAtr = Math.max(1, Number(p.three_br_st_atr_period != null ? p.three_br_st_atr_period : 10) | 0);
        const stFactor = Math.max(0.1, Number(p.three_br_st_factor != null ? p.three_br_st_factor : 3.0));
        const dcLen = Math.max(1, Number(p.three_br_dc_length != null ? p.three_br_dc_length : 13) | 0);
        const srMode = String(p.three_br_sr_mode != null ? p.three_br_sr_mode : 'Level');
        const showLevels = p.three_br_show_levels !== false;
        const maxPatterns = Math.max(10, Math.min(500, Number(p.three_br_max_patterns != null ? p.three_br_max_patterns : 120) | 0));
        const bullColor = String(p.color_three_br_bull != null ? p.color_three_br_bull : '#2962ff');
        const bearColor = String(p.color_three_br_bear != null ? p.color_three_br_bear : '#ff9800');
        const bullFill = hexToRgba(bullColor, 0.27);
        const bearFill = hexToRgba(bearColor, 0.27);
        const bullSrFill = hexToRgba(bullColor, srMode === 'Zone' ? 0.27 : 0.47);
        const bearSrFill = hexToRgba(bearColor, srMode === 'Zone' ? 0.27 : 0.47);

        const trendPair = threeBarReversalTrendFilters(
            close, high, low, volume, trendType, trendFilter, maType, fastLen, slowLen, stAtr, stFactor, dcLen
        );
        const trendUp = trendPair[0];
        const trendDown = trendPair[1];

        const lines = [];
        const areas = [];
        let labels = [];

        // Состояние конечного автомата Pine: bullProcess / bullProcess2 и зеркало для bear.
        let bullProcess = false;
        let bullProcess2 = false;
        let bearProcess = false;
        let bearProcess2 = false;
        let bullProcessLag1 = false;
        let bearProcessLag1 = false;

        let bullTop = 0;
        let bullBtm = 0;
        let bullSrTop = 0;
        let bearBot = 0;
        let bearTop = 0;
        let bearSrBot = 0;

        let bullLineTopIdx = null;
        let bullLineBtmIdx = null;
        let bullAreaIdx = null;
        let bullSrAreaIdx = null;
        let bearLineBotIdx = null;
        let bearLineTopIdx = null;
        let bearAreaIdx = null;
        let bearSrAreaIdx = null;

        for (let i = 0; i < n; i++) {
            const bullAtBarStart = bullProcess;
            const bearAtBarStart = bearProcess;

            const bullishRev = i >= 2
                && isBullishReversal3Bar(open, high, low, close, i)
                && trendUp[i];
            const bearishRev = i >= 2
                && isBearishReversal3Bar(open, high, low, close, i)
                && trendDown[i];

            if (i >= 2 && bullishRev && threeBarPatternAllowed(patternType, true, close[i], high[i - 2], low[i - 2])) {
                bullProcess = true;
                bullTop = Number(high[i - 2]);
                bullBtm = Number(Math.min(low[i - 1], low[i]));
                bullSrTop = srMode === 'Zone'
                    ? Number(Math.max(low[i - 1], low[i]))
                    : Number(Math.min(low[i - 1], low[i]));

                labels.push({
                    time: time[i] | 0,
                    price: Number(low[i]),
                    text: '^',
                    color: bullColor,
                    background: 'transparent',
                    position: 'below',
                    kind: 'three_br_bull',
                });

                if (showLevels) {
                    lines.push({
                        time_start: time[i - 2] | 0,
                        time_end: time[i] | 0,
                        price_start: bullTop,
                        price_end: bullTop,
                        color: hexToRgba(bullColor, 0.47),
                        style: 'Solid',
                        width: 1,
                        kind: 'three_br_pending',
                    });
                    bullLineTopIdx = lines.length - 1;
                    lines.push({
                        time_start: time[i - 1] | 0,
                        time_end: time[i] | 0,
                        price_start: bullBtm,
                        price_end: bullBtm,
                        color: hexToRgba(bullColor, 0.47),
                        style: 'Solid',
                        width: 1,
                        kind: 'three_br_pending',
                    });
                    bullLineBtmIdx = lines.length - 1;
                    areas.push({
                        time_start: time[i - 2] | 0,
                        time_end: time[i] | 0,
                        price_high: bullTop,
                        price_low: bullBtm,
                        fill: bullFill,
                        border: 'none',
                        kind: 'three_br_pending',
                    });
                    bullAreaIdx = areas.length - 1;
                }
            }

            if (i >= 2 && bearishRev && threeBarPatternAllowed(patternType, false, close[i], high[i - 2], low[i - 2])) {
                bearProcess = true;
                bearBot = Number(low[i - 2]);
                bearTop = Number(Math.max(high[i - 1], high[i]));
                bearSrBot = srMode === 'Zone'
                    ? Number(Math.min(high[i - 1], high[i]))
                    : Number(Math.max(high[i - 1], high[i]));

                labels.push({
                    time: time[i] | 0,
                    price: Number(high[i]),
                    text: '¡',
                    color: bearColor,
                    background: 'transparent',
                    position: 'above',
                    kind: 'three_br_bear',
                });

                if (showLevels) {
                    lines.push({
                        time_start: time[i - 2] | 0,
                        time_end: time[i] | 0,
                        price_start: bearBot,
                        price_end: bearBot,
                        color: hexToRgba(bearColor, 0.47),
                        style: 'Solid',
                        width: 1,
                        kind: 'three_br_pending',
                    });
                    bearLineBotIdx = lines.length - 1;
                    lines.push({
                        time_start: time[i - 1] | 0,
                        time_end: time[i] | 0,
                        price_start: bearTop,
                        price_end: bearTop,
                        color: hexToRgba(bearColor, 0.47),
                        style: 'Solid',
                        width: 1,
                        kind: 'three_br_pending',
                    });
                    bearLineTopIdx = lines.length - 1;
                    areas.push({
                        time_start: time[i - 2] | 0,
                        time_end: time[i] | 0,
                        price_high: bearTop,
                        price_low: bearBot,
                        fill: bearFill,
                        border: 'none',
                        kind: 'three_br_pending',
                    });
                    bearAreaIdx = areas.length - 1;
                }
            }

            if (bullProcess && i >= 1) {
                const confirmed = close[i - 1] > bullTop;
                const failed = close[i - 1] < bullBtm || bearishRev;

                if (confirmed) {
                    // Pine: пропускаем «?», если подтверждение на следующем баре после детекции (enhanced).
                    if (!(bullAtBarStart && !bullProcessLag1)) {
                        labels.push({
                            time: time[i - 1] | 0,
                            price: Number(low[i - 1]),
                            text: '?',
                            color: bullColor,
                            background: 'transparent',
                            position: 'below',
                            kind: 'three_br_confirm',
                        });
                    }
                    bullProcess = false;
                    if (srMode !== 'None') {
                        bullProcess2 = true;
                        areas.push({
                            time_start: time[i] | 0,
                            time_end: time[i] | 0,
                            price_high: bullSrTop,
                            price_low: bullBtm,
                            fill: bullSrFill,
                            border: hexToRgba(bullColor, 0.47),
                            kind: 'three_br_sr_bull',
                        });
                        bullSrAreaIdx = areas.length - 1;
                    }
                } else if (failed) {
                    bullProcess = false;
                    const endT = time[i - 1] | 0;
                    if (bullLineTopIdx !== null) lines[bullLineTopIdx].time_end = endT;
                    if (bullLineBtmIdx !== null) lines[bullLineBtmIdx].time_end = endT;
                    if (bullAreaIdx !== null) areas[bullAreaIdx].time_end = endT;
                    bullLineTopIdx = null;
                    bullLineBtmIdx = null;
                    bullAreaIdx = null;
                } else {
                    const endT = time[i] | 0;
                    if (bullLineTopIdx !== null) lines[bullLineTopIdx].time_end = endT;
                    if (bullLineBtmIdx !== null) lines[bullLineBtmIdx].time_end = endT;
                    if (bullAreaIdx !== null) areas[bullAreaIdx].time_end = endT;
                }
            }

            if (bearProcess && i >= 1) {
                const failed = close[i - 1] > bearTop || bullishRev;
                const confirmed = close[i - 1] < bearBot;

                if (failed) {
                    bearProcess = false;
                    const endT = time[i - 1] | 0;
                    if (bearLineBotIdx !== null) lines[bearLineBotIdx].time_end = endT;
                    if (bearLineTopIdx !== null) lines[bearLineTopIdx].time_end = endT;
                    if (bearAreaIdx !== null) areas[bearAreaIdx].time_end = endT;
                    bearLineBotIdx = null;
                    bearLineTopIdx = null;
                    bearAreaIdx = null;
                } else if (confirmed) {
                    if (!(bearAtBarStart && !bearProcessLag1)) {
                        labels.push({
                            time: time[i - 1] | 0,
                            price: Number(high[i - 1]),
                            text: '?',
                            color: bearColor,
                            background: 'transparent',
                            position: 'above',
                            kind: 'three_br_confirm',
                        });
                    }
                    bearProcess = false;
                    if (srMode !== 'None') {
                        bearProcess2 = true;
                        areas.push({
                            time_start: time[i] | 0,
                            time_end: time[i] | 0,
                            price_high: bearTop,
                            price_low: bearSrBot,
                            fill: bearSrFill,
                            border: hexToRgba(bearColor, 0.47),
                            kind: 'three_br_sr_bear',
                        });
                        bearSrAreaIdx = areas.length - 1;
                    }
                } else {
                    const endT = time[i] | 0;
                    if (bearLineBotIdx !== null) lines[bearLineBotIdx].time_end = endT;
                    if (bearLineTopIdx !== null) lines[bearLineTopIdx].time_end = endT;
                    if (bearAreaIdx !== null) areas[bearAreaIdx].time_end = endT;
                }
            }

            if (bullProcess2 && bullSrAreaIdx !== null && srMode !== 'None') {
                areas[bullSrAreaIdx].time_end = time[i] | 0;
                if (close[i] <= areas[bullSrAreaIdx].price_low) {
                    bullProcess2 = false;
                    bullSrAreaIdx = null;
                }
            }

            if (bearProcess2 && bearSrAreaIdx !== null && srMode !== 'None') {
                areas[bearSrAreaIdx].time_end = time[i] | 0;
                if (close[i] >= areas[bearSrAreaIdx].price_high) {
                    bearProcess2 = false;
                    bearSrAreaIdx = null;
                }
            }

            bullProcessLag1 = bullAtBarStart;
            bearProcessLag1 = bearAtBarStart;
        }

        if (labels.length > maxPatterns) labels = labels.slice(-maxPatterns);
        let linesOut = lines;
        let areasOut = areas;
        if (linesOut.length > maxPatterns * 4) linesOut = linesOut.slice(-maxPatterns * 4);
        if (areasOut.length > maxPatterns * 2) areasOut = areasOut.slice(-maxPatterns * 2);

        return { lines: linesOut, areas: areasOut, labels: labels };
    }

    /** Reversal Signals (упрощённый блок 3.txt): счётчик momentum 9 + step S/R. */
    function computeReversalSignals(rows, p) {
        const n = rows.length;
        if (n < 5) return { lines: [], labels: [] };

        const time = colTime(rows);
        const high = colFloat(rows, 'high');
        const low = colFloat(rows, 'low');
        const close = colFloat(rows, 'close');

        const showSr = p.rs_sr_levels !== false;
        const maxMarkers = Math.max(10, Math.min(500, Number(p.rs_max_momentum_markers != null ? p.rs_max_momentum_markers : 80) | 0));
        const maxSegments = Math.max(20, Math.min(2000, Number(p.rs_max_sr_segments != null ? p.rs_max_sr_segments : 200) | 0));
        const bullColor = String(p.color_rs_bull != null ? p.color_rs_bull : '#089981');
        const bearColor = String(p.color_rs_bear != null ? p.color_rs_bear : '#f23645');

        let bScore = 0;
        let sScore = 0;
        let bResistance = null;
        let sSupport = null;
        let labels = [];
        let lines = [];

        let resActive = false;
        let resStart = 0;
        let resLevel = null;
        let supActive = false;
        let supStart = 0;
        let supLevel = null;

        for (let i = 0; i < n; i++) {
            if (i >= 4) {
                const consecutiveDown = close[i] < close[i - 4];
                if (consecutiveDown) {
                    bScore = bScore === 9 ? 1 : bScore + 1;
                    sScore = 0;
                } else {
                    sScore = sScore === 9 ? 1 : sScore + 1;
                    bScore = 0;
                }

                const from = Math.max(0, i - 8);
                const hSlice = high.slice(from, i + 1);
                const lSlice = low.slice(from, i + 1);
                const h9 = Math.max.apply(null, hSlice);
                const l9 = Math.min.apply(null, lSlice);

                if (bScore === 9) {
                    bResistance = h9;
                } else if (bResistance !== null && close[i] > bResistance) {
                    bResistance = null;
                }

                if (sScore === 9) {
                    sSupport = l9;
                } else if (sSupport !== null && close[i] < sSupport) {
                    sSupport = null;
                }

                if (bScore === 9) {
                    labels.push({
                        time: time[i] | 0,
                        price: Number(low[i]),
                        text: '●',
                        color: '#ffffff',
                        background: hexToRgba(bullColor, 0.75),
                        position: 'below',
                        kind: 'rs_momentum_bull',
                    });
                }
                if (sScore === 9) {
                    labels.push({
                        time: time[i] | 0,
                        price: Number(high[i]),
                        text: '●',
                        color: '#ffffff',
                        background: hexToRgba(bearColor, 0.75),
                        position: 'above',
                        kind: 'rs_momentum_bear',
                    });
                }
            }

            if (showSr) {
                if (bResistance === null) {
                    if (resActive) {
                        lines.push({
                            time_start: resStart,
                            time_end: time[i] | 0,
                            price_start: Number(resLevel),
                            price_end: Number(resLevel),
                            color: hexToRgba(bearColor, 0.5),
                            style: 'Solid',
                            width: 2,
                            kind: 'rs_resistance',
                        });
                        resActive = false;
                    }
                } else if (!resActive || bResistance !== resLevel) {
                    if (resActive) {
                        lines.push({
                            time_start: resStart,
                            time_end: time[i] | 0,
                            price_start: Number(resLevel),
                            price_end: Number(resLevel),
                            color: hexToRgba(bearColor, 0.5),
                            style: 'Solid',
                            width: 2,
                            kind: 'rs_resistance',
                        });
                    }
                    resActive = true;
                    resStart = time[i] | 0;
                    resLevel = bResistance;
                }

                if (sSupport === null) {
                    if (supActive) {
                        lines.push({
                            time_start: supStart,
                            time_end: time[i] | 0,
                            price_start: Number(supLevel),
                            price_end: Number(supLevel),
                            color: hexToRgba(bullColor, 0.5),
                            style: 'Solid',
                            width: 2,
                            kind: 'rs_support',
                        });
                        supActive = false;
                    }
                } else if (!supActive || sSupport !== supLevel) {
                    if (supActive) {
                        lines.push({
                            time_start: supStart,
                            time_end: time[i] | 0,
                            price_start: Number(supLevel),
                            price_end: Number(supLevel),
                            color: hexToRgba(bullColor, 0.5),
                            style: 'Solid',
                            width: 2,
                            kind: 'rs_support',
                        });
                    }
                    supActive = true;
                    supStart = time[i] | 0;
                    supLevel = sSupport;
                }
            }
        }

        if (showSr) {
            const lastT = time[n - 1] | 0;
            if (resActive && resLevel !== null) {
                lines.push({
                    time_start: resStart,
                    time_end: lastT,
                    price_start: Number(resLevel),
                    price_end: Number(resLevel),
                    color: hexToRgba(bearColor, 0.5),
                    style: 'Solid',
                    width: 2,
                    kind: 'rs_resistance',
                });
            }
            if (supActive && supLevel !== null) {
                lines.push({
                    time_start: supStart,
                    time_end: lastT,
                    price_start: Number(supLevel),
                    price_end: Number(supLevel),
                    color: hexToRgba(bullColor, 0.5),
                    style: 'Solid',
                    width: 2,
                    kind: 'rs_support',
                });
            }
        }

        if (labels.length > maxMarkers) labels = labels.slice(-maxMarkers);
        if (lines.length > maxSegments) lines = lines.slice(-maxSegments);

        return { lines: lines, labels: labels };
    }

    /** Фильтры тренда Three Bar Reversal: MA cloud / Supertrend / Donchian. */
    function threeBarReversalTrendFilters(
        close, high, low, volume, trendType, trendFilter, maType, fastLen, slowLen, stAtr, stFactor, dcLen
    ) {
        const n = close.length;
        const trendUp = new Array(n).fill(true);
        const trendDown = new Array(n).fill(true);

        if (trendType === 'Moving Average Cloud') {
            const maFast = movingAverageByType(close, volume, maType, fastLen);
            const maSlow = movingAverageByType(close, volume, maType, slowLen);
            for (let i = 0; i < n; i++) {
                if (maFast[i] === null || maSlow[i] === null) {
                    trendUp[i] = false;
                    trendDown[i] = false;
                    continue;
                }
                const alignedDown = close[i] < maFast[i] && maFast[i] < maSlow[i];
                const alignedUp = close[i] > maFast[i] && maFast[i] > maSlow[i];
                if (trendFilter === 'Opposite') {
                    trendDown[i] = alignedUp;
                    trendUp[i] = alignedDown;
                } else {
                    trendDown[i] = alignedDown;
                    trendUp[i] = alignedUp;
                }
            }
        } else if (trendType === 'Supertrend') {
            const st = superTrendSeries(high, low, close, stAtr, stFactor);
            for (let i = 0; i < n; i++) {
                const dir = st.direction[i];
                if (trendFilter === 'Opposite') {
                    trendDown[i] = dir < 0;
                    trendUp[i] = dir > 0;
                } else {
                    trendDown[i] = dir > 0;
                    trendUp[i] = dir < 0;
                }
            }
        } else if (trendType === 'Donchian Channels') {
            const upper = rollingHighest(close, dcLen);
            const lower = rollingLowest(close, dcLen);
            let os = 0;
            for (let i = 0; i < n; i++) {
                if (upper[i] !== null && i > 0 && upper[i - 1] !== null && upper[i] > upper[i - 1]) {
                    os = 1;
                } else if (lower[i] !== null && i > 0 && lower[i - 1] !== null && lower[i] < lower[i - 1]) {
                    os = 0;
                }
                if (trendFilter === 'Opposite') {
                    trendDown[i] = os === 1;
                    trendUp[i] = os === 0;
                } else {
                    trendDown[i] = os === 0;
                    trendUp[i] = os === 1;
                }
            }
        }

        return [trendUp, trendDown];
    }

    function threeBarPatternAllowed(patternType, bull, close, refHigh, refLow) {
        if (patternType === 'All') return true;
        if (bull) {
            const enhanced = close > refHigh;
            return patternType === 'Enhanced' ? enhanced : !enhanced;
        }
        const enhanced = close < refLow;
        return patternType === 'Enhanced' ? enhanced : !enhanced;
    }

    function isBullishReversal3Bar(open, high, low, close, i) {
        return (close[i - 2] < open[i - 2])
            && (low[i - 1] < low[i - 2])
            && (high[i - 1] < high[i - 2])
            && (close[i - 1] < open[i - 1])
            && (close[i] > open[i])
            && (high[i] > high[i - 2]);
    }

    function isBearishReversal3Bar(open, high, low, close, i) {
        return (close[i - 2] > open[i - 2])
            && (high[i - 1] > high[i - 2])
            && (low[i - 1] > low[i - 2])
            && (close[i - 1] > open[i - 1])
            && (close[i] < open[i])
            && (low[i] < low[i - 2]);
    }

    /** SuperTrend (ta.supertrend в Pine): direction < 0 — uptrend, > 0 — downtrend. */
    function superTrendSeries(high, low, close, atrPeriod, factor) {
        const n = close.length;
        const atr = atrWilder(high, low, close, atrPeriod);
        const upperBand = new Array(n).fill(null);
        const lowerBand = new Array(n).fill(null);
        const direction = new Array(n).fill(1);
        const supertrend = new Array(n).fill(null);

        for (let i = 0; i < n; i++) {
            if (atr[i] === null) continue;
            const hl2 = (high[i] + low[i]) / 2.0;
            const basicUpper = hl2 + factor * atr[i];
            const basicLower = hl2 - factor * atr[i];

            if (i === 0 || lowerBand[i - 1] === null) {
                lowerBand[i] = basicLower;
                upperBand[i] = basicUpper;
            } else {
                const prevLower = Number(lowerBand[i - 1]);
                const prevUpper = Number(upperBand[i - 1]);
                lowerBand[i] = (basicLower > prevLower || close[i - 1] < prevLower) ? basicLower : prevLower;
                upperBand[i] = (basicUpper < prevUpper || close[i - 1] > prevUpper) ? basicUpper : prevUpper;
            }

            if (i === 0 || supertrend[i - 1] === null) {
                direction[i] = 1;
            } else if (Math.abs(Number(supertrend[i - 1]) - Number(upperBand[i - 1])) < 1e-9) {
                direction[i] = close[i] > Number(upperBand[i]) ? -1 : 1;
            } else {
                direction[i] = close[i] < Number(lowerBand[i]) ? 1 : -1;
            }

            supertrend[i] = direction[i] === -1 ? Number(lowerBand[i]) : Number(upperBand[i]);
        }

        return { direction: direction, value: supertrend };
    }

    function rollingHighest(values, len) {
        const n = values.length;
        const out = new Array(n).fill(null);
        if (len <= 0) return out;
        for (let i = len - 1; i < n; i++) {
            out[i] = Math.max.apply(null, values.slice(i - len + 1, i + 1));
        }
        return out;
    }

    function rollingLowest(values, len) {
        const n = values.length;
        const out = new Array(n).fill(null);
        if (len <= 0) return out;
        for (let i = len - 1; i < n; i++) {
            out[i] = Math.min.apply(null, values.slice(i - len + 1, i + 1));
        }
        return out;
    }

    function movingAverageByType(close, volume, type, len) {
        if (type === 'SMA') return smaSeries(close, len);
        if (type === 'EMA') return emaSeries(close, len);
        if (type === 'RMA') return rmaSeries(close, len);
        if (type === 'WMA') return wmaSeries(close, len);
        if (type === 'VWMA') return vwmaSeries(close, volume, len);
        return hmaSeries(close, len);
    }

    function smaSeries(src, len) {
        const n = src.length;
        const out = new Array(n).fill(null);
        if (len <= 0 || n === 0) return out;
        let sum = 0;
        for (let i = 0; i < n; i++) {
            sum += src[i];
            if (i >= len) sum -= src[i - len];
            if (i >= len - 1) out[i] = sum / len;
        }
        return out;
    }

    function rmaSeries(src, len) {
        const n = src.length;
        const out = new Array(n).fill(null);
        if (len <= 0 || n === 0) return out;
        if (n < len) return out;
        let seed = 0;
        for (let i = 0; i < len; i++) seed += src[i];
        seed /= len;
        out[len - 1] = seed;
        for (let i = len; i < n; i++) {
            const prev = Number(out[i - 1]);
            out[i] = ((prev * (len - 1)) + src[i]) / len;
        }
        return out;
    }

    function vwmaSeries(close, volume, len) {
        const n = close.length;
        const out = new Array(n).fill(null);
        if (len <= 0 || n === 0) return out;
        let sumPv = 0;
        let sumV = 0;
        for (let i = 0; i < n; i++) {
            sumPv += close[i] * volume[i];
            sumV += volume[i];
            if (i >= len) {
                sumPv -= close[i - len] * volume[i - len];
                sumV -= volume[i - len];
            }
            if (i >= len - 1 && sumV > 0) out[i] = sumPv / sumV;
        }
        return out;
    }

    /** Mxwll Suite: structure + rolling 4H/1D levels + Auto Fibs/main leg. */
    function computeMxwllSuite(rows, p) {
        const extState = {};
        const structure = computeMxwllStructure(rows, p, extState);
        const levels = computeMxwllRollingLevels(rows, p);
        const fibs = computeMxwllAutoFibs(rows, p, extState);
        return {
            lines: structure.lines.concat(levels.lines, fibs.lines),
            labels: structure.labels.concat(levels.labels, fibs.labels),
        };
    }

    /**
     * Mxwll Suite: swing labels (HH/LH/HL/LL) и события структуры BoS/CHoCH.
     * @param {object|null} externalStateOut — мутируемый объект для Auto Fibs.
     */
    function computeMxwllStructure(rows, p, externalStateOut) {
        const n = rows.length;
        if (n < 30) return { lines: [], labels: [] };

        const time = colTime(rows);
        const high = colFloat(rows, 'high');
        const low = colFloat(rows, 'low');
        const close = colFloat(rows, 'close');

        const bullColor = String(p.color_mxwll_bull != null ? p.color_mxwll_bull : '#14D990');
        const bearColor = String(p.color_mxwll_bear != null ? p.color_mxwll_bear : '#F24968');
        const maxSwingLabels = Math.max(10, Math.min(300, Number(p.mxwll_max_swing_labels != null ? p.mxwll_max_swing_labels : 80) | 0));
        const maxStructureEvents = Math.max(
            20,
            Math.min(300, Number(
                p.mxwll_max_structure_events != null
                    ? p.mxwll_max_structure_events
                    : (p.mxwll_max_events != null ? p.mxwll_max_events : 80)
            ) | 0)
        );

        let swingLabels = [];
        let structureEvents = [];

        if (p.mxwll_show_externals !== false) {
            const extSens = normalizeMxwllSensitivity(Number(p.mxwll_ext_sensitivity != null ? p.mxwll_ext_sensitivity : 25) | 0, [10, 25, 50], 25);
            runMxwllStructurePass(
                time, high, low, close,
                extSens,
                String(p.mxwll_ext_structure != null ? p.mxwll_ext_structure : 'All'),
                '',
                false,
                p.mxwll_show_hh_lh !== false,
                p.mxwll_show_hl_ll !== false,
                bullColor, bearColor,
                swingLabels, structureEvents,
                externalStateOut
            );
        }

        if (p.mxwll_show_internals !== false) {
            const intSens = normalizeMxwllSensitivity(Number(p.mxwll_int_sensitivity != null ? p.mxwll_int_sensitivity : 5) | 0, [3, 5, 8], 5);
            runMxwllStructurePass(
                time, high, low, close,
                intSens,
                String(p.mxwll_int_structure != null ? p.mxwll_int_structure : 'All'),
                'I-',
                true,
                false, false,
                bullColor, bearColor,
                swingLabels, structureEvents,
                null
            );
        }

        if (swingLabels.length > maxSwingLabels) swingLabels = swingLabels.slice(-maxSwingLabels);
        if (structureEvents.length > maxStructureEvents) structureEvents = structureEvents.slice(-maxStructureEvents);

        const lines = [];
        const labels = swingLabels.slice();
        for (let i = 0; i < structureEvents.length; i++) {
            lines.push(structureEvents[i].line);
            labels.push(structureEvents[i].label);
        }
        return { lines: lines, labels: labels };
    }

    /** Один проход структуры (external или internal) по всем барам. */
    function runMxwllStructurePass(
        time, high, low, close,
        sensitivity, structureFilter, labelPrefix, internalPass,
        showSwingHighLabels, showSwingLowLabels,
        bullColor, bearColor,
        swingLabelsOut, structureEventsOut,
        externalStateOut
    ) {
        const n = time.length;
        const intraRef = { value: 0 };
        let moving = 0;
        let upAxis = 0;
        let upAxisTime = 0;
        let dnAxis = 0;
        let dnAxisTime = 0;
        let upside = 1;
        let downside = 1;

        for (let i = 0; i < n; i++) {
            const piv = mxwllPivotsAt(high, low, i, sensitivity, intraRef);
            const topSwing = piv[0];
            const botSwing = piv[1];

            if (topSwing > 0) {
                upside = 1;
                const swingTime = time[Math.max(0, i - sensitivity)] | 0;
                if (showSwingHighLabels && labelPrefix === '') {
                    const swingText = topSwing > upAxis ? 'HH' : 'LH';
                    swingLabelsOut.push({
                        time: swingTime,
                        price: Number(topSwing),
                        text: swingText,
                        color: bearColor,
                        background: 'transparent',
                        position: 'above',
                        kind: 'mxwll_swing',
                    });
                }
                upAxis = Number(topSwing);
                upAxisTime = swingTime;
            }

            if (botSwing > 0) {
                downside = 1;
                const swingTime = time[Math.max(0, i - sensitivity)] | 0;
                if (showSwingLowLabels && labelPrefix === '') {
                    const swingText = (dnAxis > 0 && botSwing < dnAxis) ? 'LL' : 'HL';
                    swingLabelsOut.push({
                        time: swingTime,
                        price: Number(botSwing),
                        text: swingText,
                        color: bullColor,
                        background: 'transparent',
                        position: 'below',
                        kind: 'mxwll_swing',
                    });
                }
                dnAxis = Number(botSwing);
                dnAxisTime = swingTime;
            }

            if (i === 0) continue;

            // crossover(close, upAxis) — пробой swing high.
            if (upAxis > 0 && close[i - 1] <= upAxis && close[i] > upAxis && upside !== 0) {
                const event = moving < 0
                    ? (structureFilter !== 'BoS' ? labelPrefix + 'CHoCH' : '')
                    : (structureFilter !== 'CHoCH' ? labelPrefix + 'BoS' : '');

                if (mxwllStructureEventAllowed(structureFilter, event, internalPass)) {
                    mxwllAppendStructureEvent(
                        structureEventsOut,
                        upAxisTime,
                        time[i] | 0,
                        upAxis,
                        event,
                        bullColor,
                        true
                    );
                }
                upside = 0;
                moving = 1;
            }

            // crossunder(close, dnAxis) — пробой swing low.
            if (dnAxis > 0 && close[i - 1] >= dnAxis && close[i] < dnAxis && downside !== 0) {
                const event = moving > 0
                    ? (structureFilter !== 'BoS' ? labelPrefix + 'CHoCH' : '')
                    : (structureFilter !== 'CHoCH' ? labelPrefix + 'BoS' : '');

                if (mxwllStructureEventAllowed(structureFilter, event, internalPass)) {
                    mxwllAppendStructureEvent(
                        structureEventsOut,
                        dnAxisTime,
                        time[i] | 0,
                        dnAxis,
                        event,
                        bearColor,
                        false
                    );
                }
                downside = 0;
                moving = -1;
            }

            // Состояние external bigData для Auto Fibs (updateMain в Pine).
            if (labelPrefix === '' && externalStateOut) {
                externalStateOut.up_axis = upAxis;
                externalStateOut.up_axis_time = upAxisTime;
                externalStateOut.dn_axis = dnAxis;
                externalStateOut.dn_axis_time = dnAxisTime;
                externalStateOut.moving = moving;
            }
        }
    }

    /**
     * Pivot-логика calculatePivots() из Pine.
     * @param {{value:number}} intraRef — мутабельный intraCalc.
     */
    function mxwllPivotsAt(high, low, i, lengthCalc, intraRef) {
        if (i < lengthCalc + 1) return [0, 0];

        const from = i - lengthCalc + 1;
        const segmentHigh = high.slice(from, from + lengthCalc);
        const segmentLow = low.slice(from, from + lengthCalc);
        const up = Math.max.apply(null, segmentHigh);
        const dn = Math.min.apply(null, segmentLow);
        const cHi = high[i - lengthCalc];
        const cLo = low[i - lengthCalc];
        const prevIntra = intraRef.value;

        if (cHi > up) {
            intraRef.value = 0;
        } else if (cLo < dn) {
            intraRef.value = 1;
        }

        const topSwing = (intraRef.value === 0 && prevIntra !== 0) ? Number(cHi) : 0;
        const botSwing = (intraRef.value === 1 && prevIntra !== 1) ? Number(cLo) : 0;
        return [topSwing, botSwing];
    }

    /** Фильтр extStru/intStru — в Pine порядок аргументов str.contains разный для external/internal. */
    function mxwllStructureEventAllowed(filter, eventText, internalPass) {
        if (eventText === '') return false;
        if (filter === 'All') return true;
        // External: str.contains(extStru, str). Internal: str.contains(str, intStru).
        return internalPass
            ? (String(eventText).indexOf(filter) !== -1)
            : (String(filter).indexOf(eventText) !== -1);
    }

    /** drawChar() из Pine: горизонтальная пунктирная линия + подпись на свече пробоя. */
    function mxwllAppendStructureEvent(structureEventsOut, axisTime, breakTime, price, text, color, bullishBreak) {
        const line = {
            time_start: axisTime,
            time_end: breakTime,
            price_start: price,
            price_end: price,
            color: color,
            style: 'Dashed',
            width: 1,
            kind: 'mxwll_structure',
        };
        // Подпись на свече пробоя (bar_index в Pine), а не в mid — иначе при зуме вправо
        // метка уходит за левый край viewport, а пунктир остаётся видимым.
        const label = {
            time: breakTime,
            time_end: breakTime,
            price: price,
            text: text,
            color: color,
            background: 'transparent',
            position: bullishBreak ? 'below' : 'above',
            kind: 'mxwll_structure',
        };
        structureEventsOut.push({ line: line, label: label });
    }

    /** Rolling High/Low уровни 240/1440 баров (tfDraw на M1 в Pine). */
    function computeMxwllRollingLevels(rows, p) {
        const n = rows.length;
        if (n < 2) return { lines: [], labels: [] };

        const time = colTime(rows);
        const high = colFloat(rows, 'high');
        const low = colFloat(rows, 'low');
        const lastTime = time[n - 1] | 0;
        const levelColor = String(p.color_mxwll_level != null ? p.color_mxwll_level : '#00ffff');
        const lines = [];
        const labels = [];

        const windows = [];
        if (p.mxwll_show_4h_levels !== false) {
            windows.push({ bars: 240, tf: '240', show_labels: p.mxwll_show_4h_labels !== false });
        }
        if (p.mxwll_show_1d_levels !== false) {
            windows.push({ bars: 1440, tf: '1D', show_labels: p.mxwll_show_1d_labels !== false });
        }

        for (let w = 0; w < windows.length; w++) {
            const window = windows[w];
            const startIdx = Math.max(0, n - (window.bars | 0));
            let hi = -Infinity;
            let lo = Infinity;
            let hiTime = lastTime;
            let loTime = lastTime;
            for (let i = startIdx; i < n; i++) {
                if (high[i] >= hi) {
                    hi = high[i];
                    hiTime = time[i] | 0;
                }
                if (low[i] <= lo) {
                    lo = low[i];
                    loTime = time[i] | 0;
                }
            }
            if (!Number.isFinite(hi) || !Number.isFinite(lo)) continue;

            const tf = String(window.tf);
            lines.push({
                time_start: hiTime,
                time_end: lastTime,
                price_start: hi,
                price_end: hi,
                color: levelColor,
                style: 'Solid',
                width: 1,
                kind: 'mxwll_level',
            });
            lines.push({
                time_start: loTime,
                time_end: lastTime,
                price_start: lo,
                price_end: lo,
                color: levelColor,
                style: 'Solid',
                width: 1,
                kind: 'mxwll_level',
            });

            if (window.show_labels) {
                labels.push({
                    time: lastTime,
                    price: hi,
                    text: tf + 'H',
                    color: levelColor,
                    background: 'transparent',
                    position: 'left',
                    kind: 'mxwll_level',
                });
                labels.push({
                    time: lastTime,
                    price: lo,
                    text: tf + 'L',
                    color: levelColor,
                    background: 'transparent',
                    position: 'left',
                    kind: 'mxwll_level',
                });
            }
        }

        return { lines: lines, labels: labels };
    }

    /** Auto Fibs + main zigzag leg (updateMain/drawFibs в Pine, только на последнем баре). */
    function computeMxwllAutoFibs(rows, p, extState) {
        if (!((p.mxwll_show_fibs !== false) || (p.mxwll_show_main_leg !== false))) {
            return { lines: [], labels: [] };
        }

        const n = rows.length;
        if (n < 30 || !extState || Object.keys(extState).length === 0) {
            return { lines: [], labels: [] };
        }

        const time = colTime(rows);
        const high = colFloat(rows, 'high');
        const low = colFloat(rows, 'low');
        const lastTime = time[n - 1] | 0;
        const periodSec = Math.max(60, (time[n - 1] | 0) - (time[n - 2] | 0));
        const extendSec = Math.max(0, Number(p.mxwll_fib_extend_bars != null ? p.mxwll_fib_extend_bars : 5) | 0) * periodSec;
        const fibEndTime = lastTime + extendSec;
        const bullColor = String(p.color_mxwll_bull != null ? p.color_mxwll_bull : '#14D990');
        const bearColor = String(p.color_mxwll_bear != null ? p.color_mxwll_bear : '#F24968');

        const upAxis = Number(extState.up_axis != null ? extState.up_axis : 0);
        const upAxisTime = Number(extState.up_axis_time != null ? extState.up_axis_time : 0) | 0;
        const dnAxis = Number(extState.dn_axis != null ? extState.dn_axis : 0);
        const dnAxisTime = Number(extState.dn_axis_time != null ? extState.dn_axis_time : 0) | 0;
        if (upAxis <= 0 || dnAxis <= 0) return { lines: [], labels: [] };

        const fibSens = 25;
        let counterFibs = 0;
        const intraFib = { value: 0 };
        for (let i = Math.max(fibSens + 1, n - 120); i < n; i++) {
            const piv = mxwllPivotsAt(high, low, i, fibSens, intraFib);
            if (piv[0] > 0) counterFibs = 1;
            if (piv[1] > 0) counterFibs = -1;
        }

        let x1 = 0;
        let y1 = 0;
        let x2 = 0;
        let y2 = 0;

        if (counterFibs === 1) {
            x1 = upAxisTime;
            y1 = upAxis;
            const dnIdx = timeIndexAtOrBefore(time, dnAxisTime);
            y2 = Infinity;
            for (let i = dnIdx; i < n; i++) {
                if (low[i] <= y2) {
                    y2 = low[i];
                    x2 = time[i] | 0;
                }
            }
        } else if (counterFibs === -1) {
            x1 = dnAxisTime;
            y1 = dnAxis;
            const upIdx = timeIndexAtOrBefore(time, upAxisTime);
            y2 = -Infinity;
            for (let i = upIdx; i < n; i++) {
                if (high[i] >= y2) {
                    y2 = high[i];
                    x2 = time[i] | 0;
                }
            }
        } else {
            return { lines: [], labels: [] };
        }

        if (!Number.isFinite(y2) || x2 <= 0) return { lines: [], labels: [] };

        if (x2 < x1) {
            const tx = x1; x1 = x2; x2 = tx;
            const ty = y1; y1 = y2; y2 = ty;
        }

        const legColor = y2 < y1 ? bearColor : bullColor;
        const lines = [];
        const labels = [];

        if (p.mxwll_show_main_leg !== false) {
            lines.push({
                time_start: x1,
                time_end: x2,
                price_start: y1,
                price_end: y2,
                color: legColor,
                style: 'Dashed',
                width: 2,
                kind: 'mxwll_main_leg',
            });
        }

        if (p.mxwll_show_fibs !== false) {
            const sub = y1 - y2;
            const fibLevels = defaultMxwllFibLevels();
            for (let fi = 0; fi < fibLevels.length; fi++) {
                const fib = fibLevels[fi];
                if (fib.show === false) continue;
                const ratio = Number(fib.ratio != null ? fib.ratio : 0);
                const price = sub * ratio + y2;
                const col = String(fib.color != null ? fib.color : '#a0a5b9');
                lines.push({
                    time_start: x2,
                    time_end: fibEndTime,
                    price_start: price,
                    price_end: price,
                    color: col,
                    style: 'Solid',
                    width: 1,
                    kind: 'mxwll_fib',
                });
                // rtrim(rtrim(sprintf('%.3f', ratio), '0'), '.') — как в PHP.
                const ratioText = String(Number(ratio).toFixed(3)).replace(/0+$/, '').replace(/\.$/, '');
                labels.push({
                    time: fibEndTime,
                    price: price,
                    text: ratioText,
                    color: col,
                    background: 'transparent',
                    position: 'left',
                    kind: 'mxwll_fib',
                });
            }
        }

        return { lines: lines, labels: labels };
    }

    /** Фон торговых сессий (NY / Asia / London) — America/New_York, как bgcolor в Pine. */
    function computeSessions(rows, p) {
        const n = rows.length;
        if (n < 2) return { areas: [] };

        const time = colTime(rows);
        const high = colFloat(rows, 'high');
        const low = colFloat(rows, 'low');
        const chartHigh = Math.max.apply(null, high);
        const chartLow = Math.min.apply(null, low);
        const trans = Math.max(0, Math.min(100, Number(p.sessions_transparency != null ? p.sessions_transparency : 98) | 0));
        const alpha = Math.round((100 - trans) / 100 * 1000) / 1000;
        const colors = {
            ny: String(p.color_session_ny != null ? p.color_session_ny : '#f24968'),
            asia: String(p.color_session_asia != null ? p.color_session_asia : '#14D990'),
            london: String(p.color_session_london != null ? p.color_session_london : '#F2B807'),
        };

        const areas = [];
        let segStart = 0;
        let segSession = nySessionAt(time[0] | 0);

        for (let i = 1; i < n; i++) {
            const session = nySessionAt(time[i] | 0);
            if (session !== segSession) {
                if (segSession !== null) {
                    areas.push({
                        time_start: time[segStart] | 0,
                        time_end: time[i - 1] | 0,
                        price_high: chartHigh,
                        price_low: chartLow,
                        fill: colorWithAlpha(colors[segSession], alpha),
                        border: 'none',
                        kind: 'session_' + segSession,
                    });
                }
                segStart = i;
                segSession = session;
            }
        }

        if (segSession !== null) {
            areas.push({
                time_start: time[segStart] | 0,
                time_end: time[n - 1] | 0,
                price_high: chartHigh,
                price_low: chartLow,
                fill: colorWithAlpha(colors[segSession], alpha),
                border: 'none',
                kind: 'session_' + segSession,
            });
        }

        return { areas: areas };
    }

    /** Индекс последней свечи с time <= target. */
    function timeIndexAtOrBefore(time, target) {
        let idx = 0;
        for (let i = 0; i < time.length; i++) {
            if ((time[i] | 0) <= target) idx = i;
        }
        return idx;
    }

    /** NY / Asia / London / null (dead zone). America/New_York wall-clock. */
    function nySessionAt(unixTime) {
        const dtf = new Intl.DateTimeFormat('en-US', {
            timeZone: 'America/New_York',
            hour: 'numeric',
            minute: 'numeric',
            hourCycle: 'h23',
        });
        const parts = dtf.formatToParts(new Date(unixTime * 1000));
        let hour = 0;
        let minute = 0;
        for (let i = 0; i < parts.length; i++) {
            if (parts[i].type === 'hour') hour = parseInt(parts[i].value, 10) % 24;
            if (parts[i].type === 'minute') minute = parseInt(parts[i].value, 10);
        }
        const mins = hour * 60 + minute;

        if (mins >= (9 * 60 + 30) && mins < (16 * 60)) return 'ny';
        if (mins >= (20 * 60) || mins < (2 * 60)) return 'asia';
        if (mins >= (3 * 60) && mins < (11 * 60 + 30)) return 'london';
        return null;
    }

    function colorWithAlpha(hex, alpha) {
        let h = String(hex || '').trim().replace(/^#/, '');
        if (h.length === 3) {
            h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2];
        }
        if (h.length !== 6) {
            return 'rgba(148,163,184,' + alpha + ')';
        }
        const r = parseInt(h.substring(0, 2), 16);
        const g = parseInt(h.substring(2, 4), 16);
        const b = parseInt(h.substring(4, 6), 16);
        const a = Math.max(0, Math.min(1, alpha));
        return 'rgba(' + r + ',' + g + ',' + b + ',' + a.toFixed(3) + ')';
    }

    /** LuxAlgo FVG: detectFVG(), зоны, mitigation и unmitigated levels (3.txt). */
    function computeLuxFvg(rows, p) {
        const n = rows.length;
        if (n < 3) return { lines: [], areas: [] };

        const time = colTime(rows);
        const high = colFloat(rows, 'high');
        const low = colFloat(rows, 'low');
        const close = colFloat(rows, 'close');
        const lastTime = time[n - 1] | 0;
        const periodSec = Math.max(60, (time[n - 1] | 0) - (time[n - 2] | 0));
        const extendBars = Math.max(0, Number(p.lux_fvg_extend_bars != null ? p.lux_fvg_extend_bars : 20) | 0);
        const dynamic = !!p.lux_fvg_dynamic;
        const mitigationLevels = !!p.lux_fvg_mitigation_levels;
        const showLast = Math.max(0, Number(p.lux_fvg_show_last != null ? p.lux_fvg_show_last : 0) | 0);
        const thresholdAuto = !!p.lux_fvg_threshold_auto;
        const thresholdPct = Math.max(0, Math.min(100, Number(p.lux_fvg_threshold_pct != null ? p.lux_fvg_threshold_pct : 0))) / 100.0;
        const bullFill = colorWithAlpha(
            String(p.color_lux_fvg_bull != null ? p.color_lux_fvg_bull : '#089981'),
            (100 - Math.max(0, Math.min(100, Number(p.color_lux_fvg_bull_alpha != null ? p.color_lux_fvg_bull_alpha : 70) | 0))) / 100
        );
        const bearFill = colorWithAlpha(
            String(p.color_lux_fvg_bear != null ? p.color_lux_fvg_bear : '#f23645'),
            (100 - Math.max(0, Math.min(100, Number(p.color_lux_fvg_bear_alpha != null ? p.color_lux_fvg_bear_alpha : 70) | 0))) / 100
        );
        const bullSolid = String(p.color_lux_fvg_bull != null ? p.color_lux_fvg_bull : '#089981');
        const bearSolid = String(p.color_lux_fvg_bear != null ? p.color_lux_fvg_bear : '#f23645');

        const records = [];
        const areas = [];
        const lines = [];
        let lastFvgTime = 0;
        let cumRangeRatio = 0;
        let maxBullFvg = null;
        let minBullFvg = null;
        let maxBearFvg = null;
        let minBearFvg = null;
        let dynamicBullStart = 0;
        let dynamicBearStart = 0;

        for (let i = 2; i < n; i++) {
            if (low[i] > 0) {
                cumRangeRatio += (high[i] - low[i]) / low[i];
            }
            const threshold = thresholdAuto && i > 0 ? (cumRangeRatio / i) : thresholdPct;

            const bullFvg = low[i] > high[i - 2]
                && close[i - 1] > high[i - 2]
                && high[i - 2] > 0
                && ((low[i] - high[i - 2]) / high[i - 2]) > threshold;
            const bearFvg = high[i] < low[i - 2]
                && close[i - 1] < low[i - 2]
                && high[i] > 0
                && ((low[i - 2] - high[i]) / high[i]) > threshold;

            const barTime = time[i] | 0;

            if (bullFvg && barTime !== lastFvgTime) {
                const neu = { max: low[i], min: high[i - 2], isbull: true, t: barTime };
                if (dynamic) {
                    maxBullFvg = neu.max;
                    minBullFvg = neu.min;
                    dynamicBullStart = time[i - 2] | 0;
                } else {
                    areas.push({
                        time_start: time[i - 2] | 0,
                        time_end: barTime + extendBars * periodSec,
                        price_high: neu.max,
                        price_low: neu.min,
                        fill: bullFill,
                        border: 'none',
                        kind: 'lux_fvg_bull',
                    });
                }
                records.unshift(neu);
                lastFvgTime = barTime;
            } else if (dynamic && maxBullFvg !== null && minBullFvg !== null) {
                maxBullFvg = Math.max(Math.min(close[i], maxBullFvg), minBullFvg);
            }

            if (bearFvg && barTime !== lastFvgTime) {
                const neu = { max: low[i - 2], min: high[i], isbull: false, t: barTime };
                if (dynamic) {
                    maxBearFvg = neu.max;
                    minBearFvg = neu.min;
                    dynamicBearStart = time[i - 2] | 0;
                } else {
                    areas.push({
                        time_start: time[i - 2] | 0,
                        time_end: barTime + extendBars * periodSec,
                        price_high: neu.max,
                        price_low: neu.min,
                        fill: bearFill,
                        border: 'none',
                        kind: 'lux_fvg_bear',
                    });
                }
                records.unshift(neu);
                lastFvgTime = barTime;
            } else if (dynamic && maxBearFvg !== null && minBearFvg !== null) {
                minBearFvg = Math.min(Math.max(close[i], minBearFvg), maxBearFvg);
            }

            for (let ri = records.length - 1; ri >= 0; ri--) {
                const rec = records[ri];
                const mitigated = (rec.isbull && close[i] < rec.min)
                    || (!rec.isbull && close[i] > rec.max);
                if (!mitigated) continue;
                if (mitigationLevels) {
                    const level = rec.isbull ? rec.min : rec.max;
                    lines.push({
                        time_start: rec.t | 0,
                        time_end: barTime,
                        price_start: level,
                        price_end: level,
                        color: rec.isbull ? bullSolid : bearSolid,
                        style: 'Dashed',
                        width: 1,
                        kind: 'lux_fvg_mitigation',
                    });
                }
                if (!dynamic) {
                    luxFvgRemoveAreaAt(areas, ri);
                }
                records.splice(ri, 1);
            }
        }

        if (dynamic) {
            if (maxBullFvg !== null && minBullFvg !== null && dynamicBullStart > 0) {
                areas.push({
                    time_start: dynamicBullStart,
                    time_end: lastTime,
                    price_high: Number(maxBullFvg),
                    price_low: Number(minBullFvg),
                    fill: bullFill,
                    border: 'none',
                    kind: 'lux_fvg_bull_dynamic',
                });
            }
            if (maxBearFvg !== null && minBearFvg !== null && dynamicBearStart > 0) {
                areas.push({
                    time_start: dynamicBearStart,
                    time_end: lastTime,
                    price_high: Number(maxBearFvg),
                    price_low: Number(minBearFvg),
                    fill: bearFill,
                    border: 'none',
                    kind: 'lux_fvg_bear_dynamic',
                });
            }
        }

        if (showLast > 0 && records.length) {
            const limit = Math.min(showLast, records.length);
            for (let i = 0; i < limit; i++) {
                const rec = records[i];
                const level = rec.isbull ? rec.min : rec.max;
                lines.push({
                    time_start: rec.t | 0,
                    time_end: lastTime,
                    price_start: level,
                    price_end: level,
                    color: rec.isbull ? bullSolid : bearSolid,
                    style: 'Solid',
                    width: 1,
                    kind: 'lux_fvg_unmitigated',
                });
            }
        }

        return { lines: lines, areas: areas };
    }

    /** Удаляет i-ю FVG-зону (records и areas синхронны через unshift). */
    function luxFvgRemoveAreaAt(areas, recordIndex) {
        if (recordIndex >= 0 && recordIndex < areas.length) {
            areas.splice(recordIndex, 1);
        }
    }

    /** LuxAlgo Order Blocks: volume pivot + OS + mitigation (3.txt). */
    function computeLuxOrderBlocks(rows, p) {
        const n = rows.length;
        const len = Math.max(1, Number(p.lux_ob_length != null ? p.lux_ob_length : 5) | 0);
        if (n < (len * 2) + 1) return { lines: [], areas: [] };

        const time = colTime(rows);
        const high = colFloat(rows, 'high');
        const low = colFloat(rows, 'low');
        const close = colFloat(rows, 'close');
        const volume = colVolume(rows);
        const lastTime = time[n - 1] | 0;
        const mitigation = String(p.lux_ob_mitigation != null ? p.lux_ob_mitigation : 'Wick');
        const bullCount = Math.max(1, Number(p.lux_ob_bull_count != null ? p.lux_ob_bull_count : 3) | 0);
        const bearCount = Math.max(1, Number(p.lux_ob_bear_count != null ? p.lux_ob_bear_count : 3) | 0);
        const lineStyle = luxObLineStyle(String(p.lux_ob_avg_line_style != null ? p.lux_ob_avg_line_style : 'dotted'));
        const lineWidth = Math.max(1, Number(p.lux_ob_avg_line_width != null ? p.lux_ob_avg_line_width : 1) | 0);

        const bullBg = colorWithAlpha(
            String(p.color_lux_ob_bull_bg != null ? p.color_lux_ob_bull_bg : '#089981'),
            (100 - Math.max(0, Math.min(100, Number(p.color_lux_ob_bull_bg_alpha != null ? p.color_lux_ob_bull_bg_alpha : 80) | 0))) / 100
        );
        const bearBg = colorWithAlpha(
            String(p.color_lux_ob_bear_bg != null ? p.color_lux_ob_bear_bg : '#f23645'),
            (100 - Math.max(0, Math.min(100, Number(p.color_lux_ob_bear_bg_alpha != null ? p.color_lux_ob_bear_bg_alpha : 80) | 0))) / 100
        );
        const bullBorder = colorWithAlpha(String(p.color_lux_ob_bull_border != null ? p.color_lux_ob_bull_border : '#089981'), 0.3);
        const bearBorder = colorWithAlpha(String(p.color_lux_ob_bear_border != null ? p.color_lux_ob_bear_border : '#f23645'), 0.3);
        const bullAvgCol = colorWithAlpha(
            String(p.color_lux_ob_bull_avg != null ? p.color_lux_ob_bull_avg : '#9598a1'),
            (100 - Math.max(0, Math.min(100, Number(p.color_lux_ob_bull_avg_alpha != null ? p.color_lux_ob_bull_avg_alpha : 37) | 0))) / 100
        );
        const bearAvgCol = colorWithAlpha(
            String(p.color_lux_ob_bear_avg != null ? p.color_lux_ob_bear_avg : '#9598a1'),
            (100 - Math.max(0, Math.min(100, Number(p.color_lux_ob_bear_avg_alpha != null ? p.color_lux_ob_bear_avg_alpha : 37) | 0))) / 100
        );

        const bullTop = [];
        const bullBtm = [];
        const bullAvg = [];
        const bullLeft = [];
        const bearTop = [];
        const bearBtm = [];
        const bearAvg = [];
        const bearLeft = [];
        let os = 0;

        for (let i = 0; i < n; i++) {
            const upper = seriesHighest(high, i, len);
            const lower = seriesLowest(low, i, len);
            const targetBull = mitigation === 'Close' ? seriesLowest(close, i, len) : lower;
            const targetBear = mitigation === 'Close' ? seriesHighest(close, i, len) : upper;

            if (i >= len) {
                if (high[i - len] > upper) {
                    os = 0;
                } else if (low[i - len] < lower) {
                    os = 1;
                }
            }

            const phvDetected = i >= (2 * len) && luxPivotHighVolume(volume, i, len);

            if (phvDetected && os === 1 && i >= len) {
                const idx = i - len;
                const top = (high[idx] + low[idx]) / 2.0;
                const btm = low[idx];
                bullTop.unshift(top);
                bullBtm.unshift(btm);
                bullAvg.unshift((top + btm) / 2.0);
                bullLeft.unshift(time[idx] | 0);
            }

            if (phvDetected && os === 0 && i >= len) {
                const idx = i - len;
                const top = high[idx];
                const btm = (high[idx] + low[idx]) / 2.0;
                bearTop.unshift(top);
                bearBtm.unshift(btm);
                bearAvg.unshift((top + btm) / 2.0);
                bearLeft.unshift(time[idx] | 0);
            }

            luxObRemoveMitigated(bullTop, bullBtm, bullLeft, bullAvg, targetBull, true);
            luxObRemoveMitigated(bearTop, bearBtm, bearLeft, bearAvg, targetBear, false);
        }

        const areas = [];
        const lines = [];

        luxObEmitBlocks(areas, lines, bullTop, bullBtm, bullLeft, bullAvg, bullCount, lastTime, bullBg, bullBorder, bullAvgCol, lineStyle, lineWidth, 'lux_ob_bull');
        luxObEmitBlocks(areas, lines, bearTop, bearBtm, bearLeft, bearAvg, bearCount, lastTime, bearBg, bearBorder, bearAvgCol, lineStyle, lineWidth, 'lux_ob_bear');

        return { lines: lines, areas: areas };
    }

    function seriesHighest(series, i, len) {
        const start = Math.max(0, i - len + 1);
        return Math.max.apply(null, series.slice(start, i + 1));
    }

    function seriesLowest(series, i, len) {
        const start = Math.max(0, i - len + 1);
        return Math.min.apply(null, series.slice(start, i + 1));
    }

    /** ta.pivothigh(volume, len, len) подтверждён на баре i. */
    function luxPivotHighVolume(volume, i, len) {
        const pivotIdx = i - len;
        if (pivotIdx < len || pivotIdx + len >= volume.length) return false;
        const pivotVal = volume[pivotIdx];
        for (let j = pivotIdx - len; j <= pivotIdx + len; j++) {
            if (volume[j] > pivotVal) return false;
        }
        return true;
    }

    /** remove_mitigated() из Pine. */
    function luxObRemoveMitigated(tops, btms, lefts, avgs, target, bull) {
        for (let idx = tops.length - 1; idx >= 0; idx--) {
            const level = bull ? btms[idx] : tops[idx];
            if (bull ? target < level : target > level) {
                tops.splice(idx, 1);
                btms.splice(idx, 1);
                lefts.splice(idx, 1);
                avgs.splice(idx, 1);
            }
        }
    }

    /** set_order_blocks() на последнем баре — зоны + средняя линия вправо. */
    function luxObEmitBlocks(areas, lines, tops, btms, lefts, avgs, extLast, lastTime, bgFill, borderColor, avgColor, lineStyle, lineWidth, kind) {
        const count = Math.min(extLast, tops.length);
        for (let i = 0; i < count; i++) {
            areas.push({
                time_start: lefts[i] | 0,
                time_end: lastTime,
                price_high: Number(tops[i]),
                price_low: Number(btms[i]),
                fill: bgFill,
                border: '1px solid ' + borderColor,
                kind: kind,
            });
            lines.push({
                time_start: lefts[i] | 0,
                time_end: lastTime,
                price_start: Number(avgs[i]),
                price_end: Number(avgs[i]),
                color: avgColor,
                style: lineStyle,
                width: lineWidth,
                kind: kind + '_avg',
            });
        }
    }

    function luxObLineStyle(style) {
        const st = String(style || '').toLowerCase();
        if (st === 'dashed' || st === '----') return 'Dashed';
        if (st === 'dotted' || st === '···' || st === '...') return 'Dotted';
        return 'Solid';
    }

    function normalizeMxwllSensitivity(value, allowed, defaultVal) {
        return allowed.indexOf(value) !== -1 ? value : defaultVal;
    }

    global.PlatformSmcFlowCompute = {
        ID: ID,
        DEFAULT_PARAMS: DEFAULT_PARAMS,
        defaultMxwllFibLevels: defaultMxwllFibLevels,
        defaultParams: defaultParams,
        compute: compute,
    };
})(typeof window !== 'undefined' ? window : globalThis);
